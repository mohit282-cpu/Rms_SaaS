<?php
// helpers/LoyaltyService.php - Customer Loyalty & Rewards Program Service for RMS SaaS
require_once __DIR__ . '/CalculationEngine.php';

class LoyaltyService {

    /**
     * Award loyalty points to customer on completed order
     */
    public static function awardPointsForOrder($conn, int $orderId, int $customerId, float $orderTotal, int $tenantId): array {
        if ($customerId <= 0 || $orderTotal <= 0) {
            return ['success' => false, 'message' => 'Invalid customer ID or order total'];
        }

        // Fetch tenant loyalty settings (Default: 1 point per NPR 100 spent)
        $settings = CalculationEngine::getSettings($tenantId);
        $rate = floatval($settings['loyalty_earn_rate'] ?? 100.00); // NPR per point
        $pointsEarned = max(0, (int)floor($orderTotal / ($rate > 0 ? $rate : 100.00)));

        if ($pointsEarned <= 0) {
            return ['success' => true, 'points_earned' => 0];
        }

        $conn->begin_transaction();
        try {
            TenantContext::assertOwnership($conn, 'customers', $customerId);

            // Update customer total points & lifetime spend
            $uCust = $conn->prepare("
                UPDATE customers 
                SET loyalty_points = loyalty_points + ?, total_spent = total_spent + ?, total_visits = total_visits + 1 
                WHERE id = ? AND restaurant_id = ?
            ");
            $uCust->bind_param("idii", $pointsEarned, $orderTotal, $customerId, $tenantId);
            $uCust->execute();
            $uCust->close();

            // Record loyalty transaction ledger entry
            $desc = "Earned {$pointsEarned} points for Order #{$orderId}";
            $lLog = $conn->prepare("
                INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, notes) 
                VALUES (?, ?, ?, 'earn', ?, ?)
            ");
            $lLog->bind_param("iiiis", $tenantId, $customerId, $orderId, $pointsEarned, $desc);
            $lLog->execute();
            $lLog->close();

            // Update Loyalty Tier (Bronze < 500, Silver < 2000, Gold < 5000, VIP >= 5000)
            $cRes = $conn->query("SELECT loyalty_points FROM customers WHERE id = $customerId LIMIT 1");
            $totalPts = (int)($cRes->fetch_assoc()['loyalty_points'] ?? 0);
            $tier = 'Bronze';
            if ($totalPts >= 5000) $tier = 'Platinum';
            elseif ($totalPts >= 2000) $tier = 'Gold';
            elseif ($totalPts >= 500) $tier = 'Silver';

            $uTier = $conn->prepare("UPDATE customers SET tier = ? WHERE id = ? AND restaurant_id = ?");
            $uTier->bind_param("sii", $tier, $customerId, $tenantId);
            $uTier->execute();
            $uTier->close();

            $conn->commit();
            Security::logAudit("LOYALTY_AWARD", "Awarded {$pointsEarned} points to Customer #{$customerId} for Order #{$orderId}");
            return ['success' => true, 'points_earned' => $pointsEarned, 'new_total' => $totalPts, 'tier' => $tier];

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Failed to award points: ' . $e->getMessage()];
        }
    }

    /**
     * Redeem customer loyalty points for order discount
     */
    public static function redeemPoints($conn, int $customerId, int $pointsToRedeem, int $orderId, int $tenantId): array {
        if ($customerId <= 0 || $pointsToRedeem <= 0) {
            return ['success' => false, 'message' => 'Invalid customer ID or points amount'];
        }

        $conn->begin_transaction();
        try {
            TenantContext::assertOwnership($conn, 'customers', $customerId);

            $cStmt = $conn->prepare("SELECT loyalty_points FROM customers WHERE id = ? AND restaurant_id = ? LIMIT 1");
            $cStmt->bind_param("ii", $customerId, $tenantId);
            $cStmt->execute();
            $currPts = (int)($cStmt->get_result()->fetch_assoc()['loyalty_points'] ?? 0);
            $cStmt->close();

            if ($currPts < $pointsToRedeem) {
                $conn->rollback();
                return ['success' => false, 'message' => "Insufficient points balance (Available: {$currPts} points)"];
            }

            // 1 point = 1 NPR discount value
            $discountValue = (float)$pointsToRedeem;

            // Deduct points from customer
            $uCust = $conn->prepare("UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id = ? AND restaurant_id = ?");
            $uCust->bind_param("iii", $pointsToRedeem, $customerId, $tenantId);
            $uCust->execute();
            $uCust->close();

            // Record redemption transaction log
            $desc = "Redeemed {$pointsToRedeem} points (NPR {$discountValue} discount) for Order #{$orderId}";
            $negPts = -$pointsToRedeem;
            $lLog = $conn->prepare("
                INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, notes) 
                VALUES (?, ?, ?, 'redeem', ?, ?, ?)
            ");
            $lLog->bind_param("iiiids", $tenantId, $customerId, $orderId, $negPts, $discountValue, $desc);
            $lLog->execute();
            $lLog->close();

            $conn->commit();
            Security::logAudit("LOYALTY_REDEEM", "Customer #{$customerId} redeemed {$pointsToRedeem} points for NPR {$discountValue} discount on Order #{$orderId}");
            return ['success' => true, 'discount_amount' => $discountValue, 'remaining_points' => ($currPts - $pointsToRedeem)];

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Redemption failed: ' . $e->getMessage()];
        }
    }
}
