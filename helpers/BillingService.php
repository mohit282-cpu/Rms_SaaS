<?php
// helpers/BillingService.php - Centralized Authoritative Billing & Calculation Engine

class BillingService {

    // Default tenant loyalty settings used only as a safety fallback. The real
    // configuration always comes from the restaurant_loyalty_settings table.
    private const DEFAULT_LOYALTY = [
        'is_enabled' => 1,
        'earn_spend_amount' => 100.00,
        'point_value' => 1.00,
        'min_redemption_points' => 10,
        'max_redemption_points' => 500,
        'max_discount_percent' => 20.00,
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

        $stmt = $conn->prepare("SELECT * FROM restaurant_loyalty_settings WHERE restaurant_id = ? LIMIT 1");
        if (!$stmt) {
            return array_merge(self::DEFAULT_LOYALTY, ['restaurant_id' => max(1, $tenantId)]);
        }
        $stmt->bind_param("i", $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return [
                'restaurant_id' => (int)$tenantId,
                'is_enabled' => (int)$row['is_enabled'],
                'earn_spend_amount' => max(0.01, floatval($row['earn_spend_amount'])),
                'point_value' => max(0.01, floatval($row['point_value'])),
                'min_redemption_points' => max(0, (int)$row['min_redemption_points']),
                'max_redemption_points' => max(0, (int)$row['max_redemption_points']),
                'max_discount_percent' => max(0.0, min(100.0, floatval($row['max_discount_percent']))),
                'expiration_enabled' => (int)$row['expiration_enabled'],
                'expiration_days' => max(1, (int)$row['expiration_days']),
                'earning_basis' => $row['earning_basis'] ?: 'subtotal_after_discounts'
            ];
        }

        // Provision a default row for the tenant so settings persist going forward
        try {
            $iStmt = $conn->prepare("INSERT IGNORE INTO restaurant_loyalty_settings (restaurant_id, is_enabled, earn_spend_amount, point_value, min_redemption_points, max_redemption_points, max_discount_percent, expiration_enabled, expiration_days, earning_basis) VALUES (?, 1, 100.00, 1.00, 10, 500, 20.00, 0, 365, 'subtotal_after_discounts')");
            if ($iStmt) {
                $iStmt->bind_param("i", $tenantId);
                $iStmt->execute();
                $iStmt->close();
            }
        } catch (Throwable $e) {}

        return array_merge(self::DEFAULT_LOYALTY, ['restaurant_id' => (int)$tenantId]);
    }
    
    /**
     * Calculate authoritative bill totals for an order or table within tenant context.
     * 
     * Calculation sequence:
     * 1. Subtotal = SUM(quantity * price) for order items
     * 2. Service Charge = (Subtotal * ServiceChargePercent) / 100
     * 3. Tax Base = Subtotal + Service Charge
     * 4. VAT = (Tax Base * VATPercent) / 100
     * 5. Loyalty Discount = LoyaltyPoints * point_value (from settings)
     * 6. NCR Waiver = If NCR, set grand total to 0.00
     * 7. Grand Total = Subtotal + Service Charge + VAT - Discounts - Loyalty
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

        // Get loyalty settings
        $loyaltySettings = self::getLoyaltySettings($conn, $tenantId);
        
        // 1. Fetch order items by joining orders table to enforce tenant isolation
        $itemsStmt = $conn->prepare("
            SELECT oi.quantity, oi.price, mi.name 
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
            $subtotal += (float)$item['price'] * (int)$item['quantity'];
        }
        $subtotal = round($subtotal, 2);

        // 2. Fetch tenant payment & tax settings
        $settingsStmt = $conn->prepare("SELECT tax_enabled, tax_percentage, service_charge_enabled, service_charge_type, service_charge_amount FROM payment_settings WHERE restaurant_id = ? LIMIT 1");
        $settingsStmt->bind_param("i", $tenantId);
        $settingsStmt->execute();
        $settingsRes = $settingsStmt->get_result();
        $settings = $settingsRes ? $settingsRes->fetch_assoc() : [];
        $settingsStmt->close();

        $scPercent = !empty($settings['service_charge_enabled']) ? (float)($settings['service_charge_amount'] ?? 10.00) : 0.00;
        $scType = $settings['service_charge_type'] ?? 'percent';
        $vatPercent = !empty($settings['tax_enabled']) ? (float)($settings['tax_percentage'] ?? 13.00) : 0.00;

        // 3. Service charge calculation
        $serviceCharge = 0.0;
        if (!empty($settings['service_charge_enabled']) && $subtotal > 0) {
            if ($scType === 'percent') {
                $serviceCharge = round(($subtotal * $scPercent) / 100.0, 2);
            } else {
                $serviceCharge = round($scPercent, 2);
            }
        }

        // 4. VAT calculation on (subtotal + service charge)
        $tax = 0.0;
        if (!empty($settings['tax_enabled']) && $subtotal > 0) {
            $taxableBase = $subtotal + $serviceCharge;
            $tax = round(($taxableBase * $vatPercent) / 100.0, 2);
        }

        // 5. Loyalty discount (using configured point_value)
        $loyaltyDiscount = 0.0;
        $pointsValue = $loyaltySettings['point_value'];
        if ($loyaltyPointsRedeemed > 0) {
            $maxDiscount = $subtotal + $serviceCharge + $tax;
            $loyaltyDiscount = round($loyaltyPointsRedeemed * $pointsValue, 2);
            if ($loyaltyDiscount > $maxDiscount) {
                $loyaltyDiscount = $maxDiscount;
                $loyaltyPointsRedeemed = (int)ceil($maxDiscount / $pointsValue);
            }
        }

        // 6. Pre-discount total
        $preDiscountTotal = max(0.0, round($subtotal + $serviceCharge + $tax - $loyaltyDiscount, 2));

        // 7. NCR Waiver
        $ncrAmount = 0.0;
        if ($isNCR) {
            $ncrAmount = $preDiscountTotal;
            $grandTotal = 0.0;
        } else {
            $grandTotal = $preDiscountTotal;
        }

        return [
            'subtotal' => $subtotal,
            'service_charge' => $serviceCharge,
            'vat' => $tax,
            'discount' => 0.0,
            'loyalty_discount' => $loyaltyDiscount,
            'ncr_amount' => $ncrAmount,
            'grand_total' => $grandTotal,
            'loyalty_points_redeemed' => $loyaltyPointsRedeemed,
            'currency' => 'NPR',
            'sc_percent' => $scPercent,
            'vat_percent' => $vatPercent,
            'loyalty_settings' => [
                'is_enabled' => $loyaltySettings['is_enabled'],
                'point_value' => $pointsValue,
                'earn_spend_amount' => $loyaltySettings['earn_spend_amount'],
                'max_discount_percent' => $loyaltySettings['max_discount_percent'],
                'max_redemption_points' => $loyaltySettings['max_redemption_points'],
                'min_redemption_points' => $loyaltySettings['min_redemption_points'],
                'expiration_enabled' => $loyaltySettings['expiration_enabled'],
                'expiration_days' => $loyaltySettings['expiration_days'],
                'earning_basis' => $loyaltySettings['earning_basis']
            ],
            'formatted' => [
                'subtotal' => self::formatMoney($subtotal),
                'service_charge' => self::formatMoney($serviceCharge),
                'vat' => self::formatMoney($tax),
                'discount' => self::formatMoney(0.0),
                'loyalty_discount' => self::formatMoney($loyaltyDiscount),
                'ncr_amount' => self::formatMoney($ncrAmount),
                'grand_total' => self::formatMoney($grandTotal),
            ]
        ];
    }

    public static function formatMoney(float $amount): string {
        return 'Rs. ' . number_format($amount, 2);
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
            'loyalty_points_redeemed' => 0,
            'currency' => 'NPR',
            'sc_percent' => 10.0,
            'vat_percent' => 13.0,
            'loyalty_settings' => [
                'is_enabled' => 1,
                'point_value' => 1.00,
                'earn_spend_amount' => 100.00,
                'max_discount_percent' => 20.00,
                'max_redemption_points' => 500,
                'min_redemption_points' => 10,
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
