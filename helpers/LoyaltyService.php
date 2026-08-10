<?php
// helpers/LoyaltyService.php - Authoritative Loyalty Program Service
// Single source of truth for all loyalty business rules. The UI (tables.php)
// only renders; every calculation, validation and ledger mutation lives here.
// Every method that mutates the ledger MUST be called inside a DB transaction.

class LoyaltyService {

    /**
     * Load the restaurant's loyalty configuration (never hardcoded).
     */
    public static function settings($conn, int $tenantId): array {
        return BillingService::getLoyaltySettings($conn, $tenantId);
    }

    /**
     * Whether the loyalty program is enabled for this tenant.
     */
    public static function isEnabled($conn, int $tenantId): bool {
        $settings = self::settings($conn, $tenantId);
        return (int)$settings['is_enabled'] === 1;
    }

    /**
     * Fetch a tenant-scoped customer (null when missing / cross-tenant).
     */
    public static function customer($conn, int $tenantId, int $customerId): ?array {
        if ($customerId <= 0 || !$conn) {
            return null;
        }
        $stmt = $conn->prepare("SELECT id, name, phone, loyalty_points, lifetime_points_earned, lifetime_points_redeemed, tier FROM customers WHERE id = ? AND restaurant_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("ii", $customerId, $tenantId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $customer ?: null;
    }

    /**
     * Authoritative redemption validation + capping.
     *
     * Guarantees the cashier can never redeem:
     *  - more points than the customer holds
     *  - more than the configured maximum per bill
     *  - more than the configured bill percentage allows
     *  - more than the bill itself
     *  - less than the configured minimum threshold
     *
     * @return array{ok:bool, points:int, discount:float, available_points:int, max_allowed_points:int, point_value:float, message:string}
     */
    public static function calculateRedemption($conn, int $tenantId, int $customerId, int $requestedPoints, float $preDiscountTotal): array {
        $settings = self::settings($conn, $tenantId);

        $base = [
            'ok' => false,
            'points' => 0,
            'discount' => 0.0,
            'available_points' => 0,
            'max_allowed_points' => 0,
            'point_value' => (float)$settings['point_value'],
            'message' => ''
        ];

        if ((int)$settings['is_enabled'] !== 1) {
            $base['message'] = 'Loyalty program is disabled for this restaurant.';
            return $base;
        }

        $customer = self::customer($conn, $tenantId, $customerId);
        if (!$customer) {
            $base['message'] = 'Customer not found for this restaurant.';
            return $base;
        }

        // Expire any earned lots past their expiration date before reading the
        // balance, so the "available points" shown/used never includes stale points.
        self::sweepExpiredPoints($conn, $tenantId, $customerId);
        $customer = self::customer($conn, $tenantId, $customerId);
        if (!$customer) {
            $base['message'] = 'Customer not found for this restaurant.';
            return $base;
        }

        $availablePoints = max(0, (int)$customer['loyalty_points']);
        $pointValue = max(0.01, (float)$settings['point_value']);
        $minRedemption = (int)$settings['min_redemption_points'];
        $maxRedemption = (int)$settings['max_redemption_points'];
        $maxDiscountPercent = (float)$settings['max_discount_percent'];
        $minBill = (float)($settings['min_bill_amount'] ?? 0);
        $requestedPoints = max(0, (int)$requestedPoints);

        $base['available_points'] = $availablePoints;

        if ($requestedPoints <= 0) {
            $base['message'] = 'Enter points to redeem';
            return $base;
        }
        if ($minRedemption > 0 && $requestedPoints < $minRedemption) {
            $base['message'] = "Minimum $minRedemption points required for redemption";
            return $base;
        }
        if ($requestedPoints > $availablePoints) {
            $base['message'] = "Insufficient points. Available: $availablePoints";
            return $base;
        }
        if ($maxRedemption > 0 && $requestedPoints > $maxRedemption) {
            $base['message'] = "Maximum $maxRedemption points can be redeemed per bill";
            return $base;
        }

        // Minimum bill gate: redemption is not allowed below the configured spend floor
        if ($minBill > 0 && ($preDiscountTotal + 0.001) < $minBill) {
            $base['message'] = 'A minimum bill of ' . BillingService::formatMoney($minBill) . ' is required to redeem loyalty points';
            return $base;
        }

        // Points capped by the configured bill percentage
        $maxDiscountAmount = round($preDiscountTotal * $maxDiscountPercent / 100.0, 2);
        $maxPointsByPercent = ($maxDiscountPercent >= 100.0)
            ? PHP_INT_MAX
            : (int)floor(($maxDiscountAmount + 0.0001) / $pointValue);

        // Points capped by the bill total itself (prevent negative totals)
        $maxPointsByTotal = (int)floor(($preDiscountTotal + 0.0001) / $pointValue);

        $maxAllowed = $availablePoints;
        if ($maxRedemption > 0) {
            $maxAllowed = min($maxAllowed, $maxRedemption);
        }
        $maxAllowed = min($maxAllowed, $maxPointsByPercent, $maxPointsByTotal);
        $maxAllowed = max(0, $maxAllowed);

        $base['max_allowed_points'] = $maxAllowed;

        if ($maxAllowed <= 0) {
            $base['message'] = 'Points cannot be redeemed on this bill under the current loyalty rules';
            return $base;
        }

        $points = min($requestedPoints, $maxAllowed);
        $discount = round($points * $pointValue, 2);
        if ($discount > $preDiscountTotal + 0.001) {
            $discount = round($preDiscountTotal, 2);
            $points = (int)floor(($preDiscountTotal + 0.0001) / $pointValue);
        }

        return [
            'ok' => true,
            'points' => $points,
            'discount' => $discount,
            'available_points' => $availablePoints,
            'max_allowed_points' => $maxAllowed,
            'point_value' => $pointValue,
            'message' => ''
        ];
    }

    /**
     * Points earned for a given eligible spend amount using the configured rate.
     * Earning rule: "X points per Y spent" (earning_points per earn_spend_amount).
     */
    public static function pointsForEligibleAmount($settings, float $eligibleAmount): int {
        $earnSpend = max(0.01, (float)($settings['earn_spend_amount'] ?? 100.00));
        $earningPoints = max(1, (int)($settings['earning_points'] ?? 1));
        return (int)floor(max(0, $eligibleAmount) / $earnSpend) * $earningPoints;
    }

    /**
     * Expire earned point lots whose expiration date has passed (lot-based,
     * idempotent via expiry_processed_at). Runs inside its own transaction when
     * one is not already active; safe to call from read-style endpoints too.
     *
     * @return array{expired_lots:int, points_expired:int}
     */
    public static function sweepExpiredPoints($conn, int $tenantId, int $customerId = 0): array {
        if (!$conn) {
            return ['expired_lots' => 0, 'points_expired' => 0];
        }
        $tenantId = max(1, $tenantId);
        $ownTxn = false;
        if (!$conn->in_transaction) {
            $conn->begin_transaction();
            $ownTxn = true;
        }

        try {
            if ($customerId > 0) {
                $stmt = $conn->prepare("SELECT id, customer_id, points FROM loyalty_transactions WHERE restaurant_id = ? AND customer_id = ? AND type = 'earn' AND expiration_date IS NOT NULL AND expiration_date < CURDATE() AND expiry_processed_at IS NULL LIMIT 500");
                $stmt->bind_param("ii", $tenantId, $customerId);
            } else {
                $stmt = $conn->prepare("SELECT id, customer_id, points FROM loyalty_transactions WHERE restaurant_id = ? AND type = 'earn' AND expiration_date IS NOT NULL AND expiration_date < CURDATE() AND expiry_processed_at IS NULL LIMIT 500");
                $stmt->bind_param("i", $tenantId);
            }
            if (!$stmt) {
                return ['expired_lots' => 0, 'points_expired' => 0];
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $expiredLots = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();

            $totalExpired = 0;
            foreach ($expiredLots as $lot) {
                $lotId = (int)$lot['id'];
                $custId = (int)$lot['customer_id'];
                $lotPoints = (int)$lot['points'];
                if ($lotPoints <= 0) {
                    continue;
                }

                // Deduct from the customer balance (never below zero)
                $uStmt = $conn->prepare("UPDATE customers SET loyalty_points = GREATEST(0, loyalty_points - ?) WHERE id = ? AND restaurant_id = ?");
                $uStmt->bind_param("iii", $lotPoints, $custId, $tenantId);
                $uStmt->execute();
                $uStmt->close();

                // Immutable 'expired' ledger entry (idempotent via idempotency_key)
                $key = 'expire_' . $tenantId . '_' . $lotId;
                $note = 'Points expired (lot #' . $lotId . ')';
                $negPoints = -$lotPoints;
                $lStmt = $conn->prepare("INSERT IGNORE INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, expiration_date, notes, idempotency_key, created_at) VALUES (?, ?, NULL, 'expired', ?, 0.00, NULL, ?, ?, NOW())");
                $lStmt->bind_param("iisss", $tenantId, $custId, $negPoints, $note, $key);
                $lStmt->execute();
                $lStmt->close();

                // Mark the lot processed so this sweep is idempotent
                $mStmt = $conn->prepare("UPDATE loyalty_transactions SET expiry_processed_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $mStmt->bind_param("ii", $lotId, $tenantId);
                $mStmt->execute();
                $mStmt->close();

                $totalExpired += $lotPoints;
            }

            if ($ownTxn) {
                $conn->commit();
            }
            return ['expired_lots' => count($expiredLots), 'points_expired' => $totalExpired];
        } catch (Throwable $e) {
            if ($ownTxn) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
            }
            return ['expired_lots' => 0, 'points_expired' => 0];
        }
    }

    /**
     * Expiration date for newly earned points per settings (null = no expiry).
     */
    public static function expirationDate($settings): ?string {
        if ((int)($settings['expiration_enabled'] ?? 0) === 1 && (int)($settings['expiration_days'] ?? 0) > 0) {
            return date('Y-m-d', strtotime('+' . (int)$settings['expiration_days'] . ' days'));
        }
        return null;
    }

    /**
     * Record an earning ledger entry + update the customer balance.
     * MUST run inside a transaction. Idempotency key prevents duplicate earning.
     */
    public static function recordEarning($conn, int $tenantId, int $customerId, int $orderId, int $points, string $notes = ''): array {
        if ($points <= 0) {
            return ['success' => true, 'points' => 0, 'amount_equivalent' => 0.00];
        }

        $key = 'earn_' . $tenantId . '_' . $customerId . '_' . $orderId;

        // Idempotency check: verify this transaction has not already been processed
        $chk = $conn->prepare("SELECT id FROM loyalty_transactions WHERE idempotency_key = ? LIMIT 1");
        $chk->bind_param("s", $key);
        $chk->execute();
        $chkRes = $chk->get_result();
        if ($chkRes && $chkRes->num_rows > 0) {
            $chk->close();
            return ['success' => true, 'already_processed' => true, 'points' => 0, 'amount_equivalent' => 0.00];
        }
        $chk->close();

        // Lock customer row for update to guarantee atomic balance modification
        $cStmt = $conn->prepare("SELECT loyalty_points FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
        $cStmt->bind_param("ii", $customerId, $tenantId);
        $cStmt->execute();
        $cStmt->close();

        $settings = self::settings($conn, $tenantId);
        $pointValue = max(0.01, (float)$settings['point_value']);
        $amountEq = round($points * $pointValue, 2);
        $expDate = self::expirationDate($settings);

        $uStmt = $conn->prepare("UPDATE customers SET loyalty_points = loyalty_points + ?, lifetime_points_earned = lifetime_points_earned + ? WHERE id = ? AND restaurant_id = ?");
        $uStmt->bind_param("iiii", $points, $points, $customerId, $tenantId);
        $uStmt->execute();
        $uStmt->close();

        $note = $notes !== '' ? $notes : "Points earned from order #$orderId";
        $lStmt = $conn->prepare("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, expiration_date, notes, idempotency_key, created_at) VALUES (?, ?, ?, 'earn', ?, ?, ?, ?, ?, NOW())");
        $lStmt->bind_param("iiiddsss", $tenantId, $customerId, $orderId, $points, $amountEq, $expDate, $note, $key);
        $lStmt->execute();
        $lStmt->close();

        return ['success' => true, 'points' => $points, 'amount_equivalent' => $amountEq, 'expiration_date' => $expDate];
    }

    /**
     * Record a redemption ledger entry + update the customer balance.
     * MUST run inside a transaction. Idempotency key prevents duplicate redemption.
     */
    public static function recordRedemption($conn, int $tenantId, int $customerId, int $orderId, int $points, float $discountValue, string $notes = ''): array {
        if ($points <= 0) {
            return ['success' => true, 'points' => 0, 'amount_equivalent' => 0.00];
        }

        $key = 'redeem_' . $tenantId . '_' . $customerId . '_' . $orderId;

        // Idempotency check: verify this redemption has not already been processed
        $chk = $conn->prepare("SELECT id FROM loyalty_transactions WHERE idempotency_key = ? LIMIT 1");
        $chk->bind_param("s", $key);
        $chk->execute();
        $chkRes = $chk->get_result();
        if ($chkRes && $chkRes->num_rows > 0) {
            $chk->close();
            return ['success' => true, 'already_processed' => true, 'points' => 0, 'amount_equivalent' => 0.00];
        }
        $chk->close();

        // Lock customer row FOR UPDATE to prevent concurrent double redemption
        $cStmt = $conn->prepare("SELECT loyalty_points FROM customers WHERE id = ? AND restaurant_id = ? FOR UPDATE");
        $cStmt->bind_param("ii", $customerId, $tenantId);
        $cStmt->execute();
        $cRow = $cStmt->get_result()->fetch_assoc();
        $cStmt->close();

        if (!$cRow || (int)$cRow['loyalty_points'] < $points) {
            throw new Exception("Insufficient loyalty points for redemption");
        }

        $uStmt = $conn->prepare("UPDATE customers SET loyalty_points = GREATEST(0, loyalty_points - ?), lifetime_points_redeemed = lifetime_points_redeemed + ? WHERE id = ? AND restaurant_id = ?");
        $uStmt->bind_param("iiii", $points, $points, $customerId, $tenantId);
        $uStmt->execute();
        $uStmt->close();

        $note = $notes !== '' ? $notes : "Points redeemed for order #$orderId";
        $negPoints = -$points;
        $negAmount = -round($discountValue, 2);
        $lStmt = $conn->prepare("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, notes, idempotency_key, created_at) VALUES (?, ?, ?, 'redeem', ?, ?, ?, ?, NOW())");
        $lStmt->bind_param("iiiddss", $tenantId, $customerId, $orderId, $negPoints, $negAmount, $note, $key);
        $lStmt->execute();
        $lStmt->close();

        return ['success' => true, 'points' => $points, 'amount_equivalent' => round($discountValue, 2)];
    }

    /**
     * Monetary value of a point balance per settings.
     */
    public static function pointsValue($settings, int $points): float {
        return round($points * max(0.01, (float)($settings['point_value'] ?? 1.00)), 2);
    }
}
