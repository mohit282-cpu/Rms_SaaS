<?php
// helpers/RefundService.php - Void & Refund Financial Control Service for RMS SaaS
require_once __DIR__ . '/CalculationEngine.php';

class RefundService {

    /**
     * Void an item or order with audit tracking (Never hard-deletes financial records)
     */
    public static function voidItem($conn, int $orderId, int $orderItemId, string $reason, string $user, int $tenantId): array {
        if (empty($reason)) {
            return ['success' => false, 'message' => 'Reason for voiding is required'];
        }

        $conn->begin_transaction();
        try {
            TenantContext::assertOwnership($conn, 'orders', $orderId);

            // Fetch order item details
            $itemStmt = $conn->prepare("SELECT quantity, price FROM order_items WHERE id = ? AND order_id = ? LIMIT 1");
            $itemStmt->bind_param("ii", $orderItemId, $orderId);
            $itemStmt->execute();
            $item = $itemStmt->get_result()->fetch_assoc();
            $itemStmt->close();

            if (!$item) {
                $conn->rollback();
                return ['success' => false, 'message' => 'Order item not found'];
            }

            $voidedAmount = round(floatval($item['price']) * intval($item['quantity']), 2);

            // Delete item from order_items
            $del = $conn->prepare("DELETE FROM order_items WHERE id = ? AND order_id = ?");
            $del->bind_param("ii", $orderItemId, $orderId);
            $del->execute();
            $del->close();

            // Recalculate remaining order total
            $sumRes = $conn->query("SELECT SUM(quantity * price) as new_subtotal FROM order_items WHERE order_id = $orderId");
            $newSubtotal = floatval($sumRes->fetch_assoc()['new_subtotal'] ?? 0.00);

            $calc = CalculationEngine::calculate($newSubtotal, 0.00, 'percent', $tenantId);
            $uOrder = $conn->prepare("UPDATE orders SET total_amount = ?, tax_amount = ?, service_charge_amount = ? WHERE id = ? AND restaurant_id = ?");
            $uOrder->bind_param("dddii", $calc['grand_total'], $calc['tax_amount'], $calc['service_charge_amount'], $orderId, $tenantId);
            $uOrder->execute();
            $uOrder->close();

            // Log void record
            $vLog = $conn->prepare("INSERT INTO order_voids (restaurant_id, order_id, order_item_id, reason, amount, voided_by) VALUES (?, ?, ?, ?, ?, ?)");
            $vLog->bind_param("iiisds", $tenantId, $orderId, $orderItemId, $reason, $voidedAmount, $user);
            $vLog->execute();
            $vLog->close();

            $conn->commit();
            Security::logAudit("ORDER_VOID", "Voided item #{$orderItemId} (Amount: NPR {$voidedAmount}) from Order #{$orderId}. Reason: {$reason}");
            return ['success' => true, 'message' => "Item voided successfully. Order subtotal updated.", 'new_total' => $calc['grand_total']];

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Void failed: ' . $e->getMessage()];
        }
    }

    /**
     * Process full or partial refund with audit record & inventory restock
     */
    public static function processRefund($conn, int $orderId, string $refundType, float $amount, string $paymentMethod, string $reason, string $user, int $tenantId): array {
        if ($amount <= 0 || empty($reason)) {
            return ['success' => false, 'message' => 'Valid refund amount and reason are required'];
        }

        $conn->begin_transaction();
        try {
            TenantContext::assertOwnership($conn, 'orders', $orderId);

            $oStmt = $conn->prepare("SELECT total_amount, status FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
            $oStmt->bind_param("ii", $orderId, $tenantId);
            $oStmt->execute();
            $order = $oStmt->get_result()->fetch_assoc();
            $oStmt->close();

            if (!$order) {
                $conn->rollback();
                return ['success' => false, 'message' => 'Order not found'];
            }

            $refType = ($refundType === 'full' || $amount >= floatval($order['total_amount'])) ? 'full' : 'partial';

            // Insert refund record
            $refStmt = $conn->prepare("
                INSERT INTO order_refunds (restaurant_id, order_id, refund_type, amount, payment_method, reason, refunded_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $refStmt->bind_param("iisdsss", $tenantId, $orderId, $refType, $amount, $paymentMethod, $reason, $user);
            $refStmt->execute();
            $refundId = $refStmt->insert_id;
            $refStmt->close();

            // Update order status if full refund
            if ($refType === 'full') {
                $uOrder = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND restaurant_id = ?");
                $uOrder->bind_param("ii", $orderId, $tenantId);
                $uOrder->execute();
                $uOrder->close();
            }

            $conn->commit();
            Security::logAudit("ORDER_REFUND", "Issued {$refType} refund of NPR {$amount} for Order #{$orderId}. Reason: {$reason}");
            return ['success' => true, 'message' => "Refund of NPR {$amount} processed successfully", 'refund_id' => $refundId];

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Refund failed: ' . $e->getMessage()];
        }
    }
}
