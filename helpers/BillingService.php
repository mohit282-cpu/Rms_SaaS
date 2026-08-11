<?php
// helpers/BillingService.php - Centralized Authoritative Billing & Calculation Engine

class BillingService {

    // Default tenant loyalty settings used only as a safety fallback. The real
    // configuration always comes from the restaurant_loyalty_settings table via
    // RestaurantSettingsService (the single source of truth).
    private const DEFAULT_LOYALTY = [
        'is_enabled' => 1,
        'earning_points' => 1,
        'earn_spend_amount' => 100.00,
        'point_value' => 1.00,
        'min_redemption_points' => 10,
        'max_redemption_points' => 500,
        'max_discount_percent' => 20.00,
        'min_bill_amount' => 0.00,
        'expiration_enabled' => 0,
        'expiration_days' => 365,
        'earning_basis' => 'subtotal_after_discounts'
    ];

    /**
     * Get tenant-specific loyalty settings (single authoritative source used by
     * the billing engine, the table-payment API and the loyalty service).
     */
    public static function getLoyaltySettings($conn, int $tenantId): array {
        if (!$conn || $tenantId <= 0) {
            return array_merge(self::DEFAULT_LOYALTY, ['restaurant_id' => max(1, $tenantId)]);
        }
        return RestaurantSettingsService::getLoyaltySettings($conn, $tenantId);
    }

    public static function formatMoney(float $amount, string $symbol = 'Rs.', string $position = 'left'): string {
        $value = number_format($amount, 2);
        return $position === 'right' ? $value . ' ' . $symbol : $symbol . ' ' . $value;
    }

    public static function formatMoneyBackend(float $amount): string {
        return number_format($amount, 2);
    }

    public static function formatItemTotal($quantity, $price) {
        return number_format($quantity * $price, 2);
    }

    /**
     * Calculate authoritative bill totals for an order within tenant context.
     *
     * Calculation sequence (required business flow):
     * 1. Subtotal = SUM(quantity * price) for order items (NCR items excluded)
     * 2. Order-level manual discount = orders.discount_amount
     * 3. Loyalty discount applied BEFORE service charge & VAT
     * 4. Service Charge = netBase * SC% (or fixed amount)
     * 5. VAT: exclusive -> (netBase + SC) * VAT%; inclusive -> embedded tax recovered
     * 6. Grand Total = netBase + Service Charge + VAT (never negative)
     * 7. NCR (whole order waiver) -> grand total = 0, ncr_amount = pre-loyalty total
     * 8. earning_eligible = the configured earning basis after all discounts
     *
     * @param mysqli $conn Database connection
     * @param int $tenantId Authenticated restaurant ID
     * @param int $orderId Order ID
     * @param int $loyaltyPointsRedeemed Points to redeem
     * @param bool $isNCR Whether complimentary NCR waiver applies
     * @return array Authoritative bill calculation breakdown
     */
    public static function calculateOrderBill($conn, int $tenantId, int $orderId, int $loyaltyPointsRedeemed = 0, bool $isNCR = false): array {
        if (!$conn || $tenantId <= 0 || $orderId <= 0) {
            return self::emptyBill();
        }

        $loyalty = RestaurantSettingsService::getLoyaltySettings($conn, $tenantId);
        $pay = RestaurantSettingsService::getPaymentSettings($conn, $tenantId);

        $pointValue = max(0.01, (float)$loyalty['point_value']);
        $loyaltyEnabled = (int)$loyalty['is_enabled'] === 1;

        // 1. Fetch order items (tenant-scoped). Items flagged NCR (ncr_amount > 0)
        //    are excluded from the payable subtotal entirely.
        $itemsStmt = $conn->prepare("
            SELECT oi.quantity, oi.price, oi.ncr_amount, mi.name
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN menu_items mi ON oi.menu_item_id = mi.id
            WHERE oi.order_id = ? AND o.restaurant_id = ?
        ");
        $itemsStmt->bind_param("ii", $orderId, $tenantId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();

        $subtotal = 0.0;
        foreach ($items as $item) {
            if ((float)($item['ncr_amount'] ?? 0) > 0) {
                continue;
            }
            $subtotal += round((float)$item['price'] * (int)$item['quantity'], 2);
        }
        $subtotal = round($subtotal, 2);

        // 2. Order-level manual discount + whole-order NCR flag
        $discount = 0.0;
        $ordStmt = $conn->prepare("SELECT discount_amount, ncr_amount FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
        if ($ordStmt) {
            $ordStmt->bind_param("ii", $orderId, $tenantId);
            $ordStmt->execute();
            $ordRow = $ordStmt->get_result()->fetch_assoc();
            $ordStmt->close();
            if ($ordRow) {
                $discount = round(max(0, (float)($ordRow['discount_amount'] ?? 0)), 2);
                if ((float)($ordRow['ncr_amount'] ?? 0) > 0) {
                    $isNCR = true;
                }
            }
        }

        $scEnabled = (int)$pay['service_charge_enabled'] === 1;
        $scType = $pay['service_charge_type'] ?? 'percent';
        $scAmount = (float)$pay['service_charge_amount'];
        $taxEnabled = (int)$pay['tax_enabled'] === 1;
        $vatPercent = (float)$pay['tax_percentage'];
        $vatMode = $pay['vat_mode'] ?? 'exclusive';

        // Service charge + VAT computed on a given net base (after all discounts).
        // In inclusive mode, item prices already include VAT: the tax returned is the
        // embedded portion recovered for display only and must NOT be added to the
        // payable total (returns $taxAddsToTotal=false). Service charge is charged on
        // top in both modes (standard restaurant practice).
        $computeAddOns = function (float $netBase) use ($scEnabled, $scType, $scAmount, $taxEnabled, $vatPercent, $vatMode): array {
            $serviceCharge = 0.0;
            $tax = 0.0;
            if ($netBase > 0) {
                if ($scEnabled) {
                    $serviceCharge = $scType === 'fixed'
                        ? round($scAmount, 2)
                        : round(($netBase * $scAmount) / 100.0, 2);
                }
                if ($taxEnabled) {
                    if ($vatMode === 'inclusive') {
                        // Recover the embedded VAT portion of the whole charge (incl. SC)
                        $tax = round((($netBase + $serviceCharge) * $vatPercent) / (100.0 + $vatPercent), 2);
                        return [$serviceCharge, $tax, false];
                    }
                    $tax = round((($netBase + $serviceCharge) * $vatPercent) / 100.0, 2);
                }
            }
            return [$serviceCharge, $tax, true];
        };

        // 3. Pre-loyalty payable (before loyalty discount) -> the cap base
        $base0 = max(0.0, $subtotal - $discount);
        list($sc0, $vat0, $vatAdds0) = $computeAddOns($base0);
        $preLoyaltyTotal = round($base0 + $sc0 + ($vatAdds0 ? $vat0 : 0.0), 2);

        // 4. Loyalty discount (points * value), capped by the configured bill
        //    percentage and by the bill total itself.
        $pointsUsed = (int)max(0, $loyaltyPointsRedeemed);
        $loyaltyDiscount = 0.0;
        if ($loyaltyEnabled && $pointsUsed > 0 && $preLoyaltyTotal > 0) {
            $maxDiscountPercent = min(100.0, (float)$loyalty['max_discount_percent']);
            $maxByPercent = ($maxDiscountPercent >= 100.0)
                ? $preLoyaltyTotal
                : round($preLoyaltyTotal * $maxDiscountPercent / 100.0, 2);
            $loyaltyDiscount = min(round($pointsUsed * $pointValue, 2), $maxByPercent, $preLoyaltyTotal);
            if ($loyaltyDiscount <= 0) {
                $pointsUsed = 0;
            }
        } elseif (!$loyaltyEnabled) {
            $pointsUsed = 0;
        }

        // 5. Final service charge & VAT on the net base AFTER loyalty discount
        $base1 = max(0.0, $base0 - $loyaltyDiscount);
        list($serviceCharge, $tax, $taxAddsToTotal) = $computeAddOns($base1);
        $grandTotal = round($base1 + $serviceCharge + ($taxAddsToTotal ? $tax : 0.0), 2);

        // 6. Eligible spend for earning new points per the configured basis
        //    (inclusive mode: tax is embedded in the base, so contribute 0)
        $eligible = self::earningEligible($loyalty, $base1, $serviceCharge, $taxAddsToTotal ? $tax : 0.0);

        // 7. NCR waiver (whole order complimentary)
        $ncrAmount = 0.0;
        if ($isNCR) {
            $ncrAmount = round($preLoyaltyTotal, 2);
            $grandTotal = 0.0;
        }

        $symbol = $pay['currency_symbol'] ?? 'Rs.';
        $position = $pay['currency_position'] ?? 'left';

        return [
            'subtotal' => $subtotal,
            'service_charge' => $serviceCharge,
            'vat' => $tax,
            'discount' => $discount,
            'loyalty_discount' => $loyaltyDiscount,
            'ncr_amount' => $ncrAmount,
            'grand_total' => $grandTotal,
            'pre_loyalty_total' => $preLoyaltyTotal,
            'earning_eligible' => $eligible,
            'vat_mode' => $vatMode,
            'loyalty_points_redeemed' => $pointsUsed,
            'currency' => $pay['currency'] ?? 'NPR',
            'currency_symbol' => $symbol,
            'currency_position' => $position,
            'sc_percent' => $scAmount,
            'sc_type' => $scType,
            'vat_percent' => $vatPercent,
            'loyalty_settings' => [
                'is_enabled' => (int)$loyalty['is_enabled'],
                'point_value' => $pointValue,
                'earning_points' => (int)$loyalty['earning_points'],
                'earn_spend_amount' => (float)$loyalty['earn_spend_amount'],
                'max_discount_percent' => (float)$loyalty['max_discount_percent'],
                'max_redemption_points' => (int)$loyalty['max_redemption_points'],
                'min_redemption_points' => (int)$loyalty['min_redemption_points'],
                'min_bill_amount' => (float)$loyalty['min_bill_amount'],
                'expiration_enabled' => (int)$loyalty['expiration_enabled'],
                'expiration_days' => (int)$loyalty['expiration_days'],
                'earning_basis' => $loyalty['earning_basis']
            ],
            'formatted' => [
                'subtotal' => self::formatMoney($subtotal, $symbol, $position),
                'service_charge' => self::formatMoney($serviceCharge, $symbol, $position),
                'vat' => self::formatMoney($tax, $symbol, $position),
                'discount' => self::formatMoney($discount, $symbol, $position),
                'loyalty_discount' => self::formatMoney($loyaltyDiscount, $symbol, $position),
                'ncr_amount' => self::formatMoney($ncrAmount, $symbol, $position),
                'grand_total' => self::formatMoney($grandTotal, $symbol, $position),
            ]
        ];
    }

    /**
     * Calculate authoritative bill totals for ALL active unsettled orders belonging to a table.
     */
    public static function calculateTableBill($conn, int $tenantId, string $tableNumber, int $loyaltyPointsRedeemed = 0, bool $isNCR = false): array {
        if (!$conn || $tenantId <= 0 || empty($tableNumber)) {
            return self::emptyBill();
        }

        // Fetch active order IDs for this table
        $tEsc = $conn->real_escape_string($tableNumber);
        $res = $conn->query("SELECT id FROM orders WHERE restaurant_id = $tenantId AND table_number = '$tEsc' AND payment_status = 'pending' AND status != 'cancelled' ORDER BY id ASC");
        if (!$res || $res->num_rows === 0) {
            return self::emptyBill();
        }

        $orderIds = [];
        while ($r = $res->fetch_assoc()) {
            $orderIds[] = (int)$r['id'];
        }

        if (count($orderIds) === 1) {
            return self::calculateOrderBill($conn, $tenantId, $orderIds[0], $loyaltyPointsRedeemed, $isNCR);
        }

        // Aggregate across multiple active orders for the table
        $loyalty = RestaurantSettingsService::getLoyaltySettings($conn, $tenantId);
        $pay = RestaurantSettingsService::getPaymentSettings($conn, $tenantId);
        $pointValue = max(0.01, (float)$loyalty['point_value']);
        $loyaltyEnabled = (int)$loyalty['is_enabled'] === 1;

        $oList = implode(',', $orderIds);
        $itemsStmt = $conn->query("
            SELECT oi.quantity, oi.price, oi.ncr_amount
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE oi.order_id IN ($oList) AND o.restaurant_id = $tenantId
        ");
        $subtotal = 0.0;
        if ($itemsStmt) {
            while ($itm = $itemsStmt->fetch_assoc()) {
                if ((float)($itm['ncr_amount'] ?? 0) > 0) continue;
                $subtotal += round((float)$itm['price'] * (int)$itm['quantity'], 2);
            }
        }
        $subtotal = round($subtotal, 2);

        $discount = 0.0;
        $ordStmt = $conn->query("SELECT discount_amount, ncr_amount FROM orders WHERE id IN ($oList) AND restaurant_id = $tenantId");
        if ($ordStmt) {
            while ($orow = $ordStmt->fetch_assoc()) {
                $discount += round(max(0, (float)($orow['discount_amount'] ?? 0)), 2);
                if ((float)($orow['ncr_amount'] ?? 0) > 0) $isNCR = true;
            }
        }

        $scEnabled = (int)$pay['service_charge_enabled'] === 1;
        $scType = $pay['service_charge_type'] ?? 'percent';
        $scAmount = (float)$pay['service_charge_amount'];
        $taxEnabled = (int)$pay['tax_enabled'] === 1;
        $vatPercent = (float)$pay['tax_percentage'];
        $vatMode = $pay['vat_mode'] ?? 'exclusive';

        $computeAddOns = function (float $netBase) use ($scEnabled, $scType, $scAmount, $taxEnabled, $vatPercent, $vatMode): array {
            $serviceCharge = 0.0;
            $tax = 0.0;
            if ($netBase > 0) {
                if ($scEnabled) {
                    $serviceCharge = $scType === 'fixed' ? round($scAmount, 2) : round(($netBase * $scAmount) / 100.0, 2);
                }
                if ($taxEnabled) {
                    if ($vatMode === 'inclusive') {
                        $tax = round((($netBase + $serviceCharge) * $vatPercent) / (100.0 + $vatPercent), 2);
                        return [$serviceCharge, $tax, false];
                    }
                    $tax = round((($netBase + $serviceCharge) * $vatPercent) / 100.0, 2);
                }
            }
            return [$serviceCharge, $tax, true];
        };

        $base0 = max(0.0, $subtotal - $discount);
        list($sc0, $vat0, $vatAdds0) = $computeAddOns($base0);
        $preLoyaltyTotal = round($base0 + $sc0 + ($vatAdds0 ? $vat0 : 0.0), 2);

        $pointsUsed = (int)max(0, $loyaltyPointsRedeemed);
        $loyaltyDiscount = 0.0;
        if ($loyaltyEnabled && $pointsUsed > 0 && $preLoyaltyTotal > 0) {
            $maxDiscountPercent = min(100.0, (float)$loyalty['max_discount_percent']);
            $maxByPercent = ($maxDiscountPercent >= 100.0) ? $preLoyaltyTotal : round($preLoyaltyTotal * $maxDiscountPercent / 100.0, 2);
            $loyaltyDiscount = min(round($pointsUsed * $pointValue, 2), $maxByPercent, $preLoyaltyTotal);
            if ($loyaltyDiscount <= 0) $pointsUsed = 0;
        } elseif (!$loyaltyEnabled) {
            $pointsUsed = 0;
        }

        $base1 = max(0.0, $base0 - $loyaltyDiscount);
        list($serviceCharge, $tax, $taxAddsToTotal) = $computeAddOns($base1);
        $grandTotal = round($base1 + $serviceCharge + ($taxAddsToTotal ? $tax : 0.0), 2);
        $eligible = self::earningEligible($loyalty, $base1, $serviceCharge, $taxAddsToTotal ? $tax : 0.0);

        $ncrAmount = 0.0;
        if ($isNCR) {
            $ncrAmount = round($preLoyaltyTotal, 2);
            $grandTotal = 0.0;
        }

        $symbol = $pay['currency_symbol'] ?? 'Rs.';
        $position = $pay['currency_position'] ?? 'left';

        return [
            'subtotal' => $subtotal,
            'service_charge' => $serviceCharge,
            'vat' => $tax,
            'discount' => $discount,
            'loyalty_discount' => $loyaltyDiscount,
            'ncr_amount' => $ncrAmount,
            'grand_total' => $grandTotal,
            'pre_loyalty_total' => $preLoyaltyTotal,
            'earning_eligible' => $eligible,
            'vat_mode' => $vatMode,
            'loyalty_points_redeemed' => $pointsUsed,
            'currency' => $pay['currency'] ?? 'NPR',
            'currency_symbol' => $symbol,
            'currency_position' => $position,
            'sc_percent' => $scAmount,
            'sc_type' => $scType,
            'vat_percent' => $vatPercent,
            'loyalty_settings' => [
                'is_enabled' => (int)$loyalty['is_enabled'],
                'point_value' => $pointValue,
                'earning_points' => (int)$loyalty['earning_points'],
                'earn_spend_amount' => (float)$loyalty['earn_spend_amount'],
                'max_discount_percent' => (float)$loyalty['max_discount_percent'],
                'max_redemption_points' => (int)$loyalty['max_redemption_points'],
                'min_redemption_points' => (int)$loyalty['min_redemption_points'],
                'min_bill_amount' => (float)$loyalty['min_bill_amount'],
                'expiration_enabled' => (int)$loyalty['expiration_enabled'],
                'expiration_days' => (int)$loyalty['expiration_days'],
                'earning_basis' => $loyalty['earning_basis']
            ],
            'formatted' => [
                'subtotal' => self::formatMoney($subtotal, $symbol, $position),
                'service_charge' => self::formatMoney($serviceCharge, $symbol, $position),
                'vat' => self::formatMoney($tax, $symbol, $position),
                'discount' => self::formatMoney($discount, $symbol, $position),
                'loyalty_discount' => self::formatMoney($loyaltyDiscount, $symbol, $position),
                'ncr_amount' => self::formatMoney($ncrAmount, $symbol, $position),
                'grand_total' => self::formatMoney($grandTotal, $symbol, $position),
            ]
        ];
    }

    /**
     * Eligible spend amount that generates new loyalty points, per the configured
     * earning basis and after every applicable discount.
     */
    private static function earningEligible(array $loyalty, float $netBase, float $serviceCharge, float $tax): float {
        $basis = $loyalty['earning_basis'] ?? 'subtotal_after_discounts';
        switch ($basis) {
            case 'subtotal_plus_service_charge':
                return round(max(0, $netBase + $serviceCharge), 2);
            case 'grand_total_before_tax':
                return round(max(0, $netBase + $serviceCharge + $tax), 2);
            case 'subtotal_after_discounts':
            default:
                // Net base = subtotal - manual discount - loyalty discount
                return round(max(0, $netBase), 2);
        }
    }

    private static function emptyBill(): array {
        return [
            'subtotal' => 0.00,
            'service_charge' => 0.00,
            'vat' => 0.00,
            'discount' => 0.00,
            'loyalty_discount' => 0.00,
            'ncr_amount' => 0.00,
            'grand_total' => 0.00,
            'pre_loyalty_total' => 0.00,
            'earning_eligible' => 0.00,
            'vat_mode' => 'exclusive',
            'loyalty_points_redeemed' => 0,
            'currency' => 'NPR',
            'currency_symbol' => 'Rs.',
            'currency_position' => 'left',
            'sc_percent' => 10.0,
            'sc_type' => 'percent',
            'vat_percent' => 13.0,
            'loyalty_settings' => [
                'is_enabled' => 1,
                'point_value' => 1.00,
                'earning_points' => 1,
                'earn_spend_amount' => 100.00,
                'max_discount_percent' => 20.00,
                'max_redemption_points' => 500,
                'min_redemption_points' => 10,
                'min_bill_amount' => 0.00,
                'expiration_enabled' => 0,
                'expiration_days' => 365,
                'earning_basis' => 'subtotal_after_discounts'
            ],
            'formatted' => [
                'subtotal' => 'Rs. 0.00',
                'service_charge' => 'Rs. 0.00',
                'vat' => 'Rs. 0.00',
                'discount' => 'Rs. 0.00',
                'loyalty_discount' => 'Rs. 0.00',
                'ncr_amount' => 'Rs. 0.00',
                'grand_total' => 'Rs. 0.00',
            ]
        ];
    }
}
