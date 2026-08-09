<?php
// helpers/BillService.php - Bill Splitting, Order Merging & Table Transfer Service for RMS SaaS
require_once __DIR__ . '/CalculationEngine.php';

class BillService {

    /**
     * Split bill equally among N customers
     */
    public static function splitEqual($conn, int $orderId, int $numSplits, int $tenantId): array {
        $numSplits = max(2, $numSplits);

        $stmt = $conn->prepare("SELECT total_amount FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
        $stmt->bind_param("ii", $orderId, $tenantId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order) {
            return ['success' => false, 'message' => 'Order not found'];
        }

        $total = floatval($order['total_amount']);
        $splitAmount = round($total / $numSplits, 2);

        // Delete previous pending splits
        $conn->query("DELETE FROM order_splits WHERE order_id = $orderId AND restaurant_id = $tenantId AND payment_status = 'pending'");

        $splits = [];
        for ($i = 1; $i <= $numSplits; $i++) {
            $label = "Customer {$i}";
            // Adjust last split for rounding difference
            $amount = ($i === $numSplits) ? round($total - ($splitAmount * ($numSplits - 1)), 2) : $splitAmount;

            $ins = $conn->prepare("INSERT INTO order_splits (restaurant_id, order_id, split_type, customer_label, amount) VALUES (?, ?, 'equal', ?, ?)");
            $ins->bind_param("iisd", $tenantId, $orderId, $label, $amount);
            $ins->execute();
            $splitId = $ins->insert_id;
            $ins->close();

            $splits[] = ['id' => $splitId, 'label' => $label, 'amount' => $amount];
        }

        Security::logAudit("BILL_SPLIT", "Split Order #{$orderId} equally into {$numSplits} parts of NPR {$splitAmount}");
        return ['success' => true, 'splits' => $splits, 'total' => $total];
    }

    /**
     * Merge source order into target order
     */
    public static function mergeOrders($conn, int $sourceOrderId, int $targetOrderId, string $user, int $tenantId): array {
        if ($sourceOrderId === $targetOrderId) {
            return ['success' => false, 'message' => 'Cannot merge an order into itself'];
        }

        $conn->begin_transaction();
        try {
            // Assert tenant ownership for both orders
            TenantContext::assertOwnership($conn, 'orders', $sourceOrderId);
            TenantContext::assertOwnership($conn, 'orders', $targetOrderId);

            // Fetch items from source order
            $sStmt = $conn->prepare("SELECT menu_item_id, quantity, price FROM order_items WHERE order_id = ?");
            $sStmt->bind_param("i", $sourceOrderId);
            $sStmt->execute();
            $sourceItems = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $sStmt->close();

            foreach ($sourceItems as $sItem) {
                $mId = intval($sItem['menu_item_id']);
                $qty = intval($sItem['quantity']);
                $price = floatval($sItem['price']);

                // Check if target order already has this menu item (prevent duplicate rows, merge quantity)
                $tCheck = $conn->prepare("SELECT id, quantity FROM order_items WHERE order_id = ? AND menu_item_id = ? LIMIT 1");
                $tCheck->bind_param("ii", $targetOrderId, $mId);
                $tCheck->execute();
                $existing = $tCheck->get_result()->fetch_assoc();
                $tCheck->close();

                if ($existing) {
                    $upd = $conn->prepare("UPDATE order_items SET quantity = quantity + ? WHERE id = ?");
                    $upd->bind_param("ii", $qty, $existing['id']);
                    $upd->execute();
                    $upd->close();
                } else {
                    $ins = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, price) VALUES (?, ?, ?, ?)");
                    $ins->bind_param("iiid", $targetOrderId, $mId, $qty, $price);
                    $ins->execute();
                    $ins->close();
                }
            }

            // Recalculate target order total
            $sumRes = $conn->query("SELECT SUM(quantity * price) as new_subtotal FROM order_items WHERE order_id = $targetOrderId");
            $newSubtotal = floatval($sumRes->fetch_assoc()['new_subtotal'] ?? 0.00);

            $calc = CalculationEngine::calculate($newSubtotal, 0.00, 'percent', $tenantId);
            $uStmt = $conn->prepare("UPDATE orders SET total_amount = ?, tax_amount = ?, service_charge_amount = ? WHERE id = ? AND restaurant_id = ?");
            $uStmt->bind_param("dddii", $calc['grand_total'], $calc['tax_amount'], $calc['service_charge_amount'], $targetOrderId, $tenantId);
            $uStmt->execute();
            $uStmt->close();

            // Mark source order as cancelled / merged
            $cStmt = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND restaurant_id = ?");
            $cStmt->bind_param("ii", $sourceOrderId, $tenantId);
            $cStmt->execute();
            $cStmt->close();

            // Record merge log
            $mLog = $conn->prepare("INSERT INTO order_merges (restaurant_id, source_order_id, target_order_id, merged_by) VALUES (?, ?, ?, ?)");
            $mLog->bind_param("iiis", $tenantId, $sourceOrderId, $targetOrderId, $user);
            $mLog->execute();
            $mLog->close();

            $conn->commit();
            Security::logAudit("ORDER_MERGE", "Merged Order #{$sourceOrderId} into Order #{$targetOrderId} by {$user}");
            return ['success' => true, 'message' => "Order #{$sourceOrderId} merged into Order #{$targetOrderId} successfully", 'new_total' => $calc['grand_total']];

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Merge failed: ' . $e->getMessage()];
        }
    }

    /**
     * Transfer order from one table to another
     */
    public static function transferTable($conn, int $orderId, string $newTableNumber, string $user, int $tenantId): array {
        TenantContext::assertOwnership($conn, 'orders', $orderId);

        $conn->begin_transaction();
        try {
            // Get original table number
            $oStmt = $conn->prepare("SELECT table_number FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
            $oStmt->bind_param("ii", $orderId, $tenantId);
            $oStmt->execute();
            $oldTableNumber = $oStmt->get_result()->fetch_assoc()['table_number'] ?? '';
            $oStmt->close();

            // Update order table number
            $uOrder = $conn->prepare("UPDATE orders SET table_number = ? WHERE id = ? AND restaurant_id = ?");
            $uOrder->bind_param("sii", $newTableNumber, $orderId, $tenantId);
            $uOrder->execute();
            $uOrder->close();

            // Set new table to occupied
            $uNewTbl = $conn->prepare("UPDATE tables SET status = 'occupied' WHERE table_number = ? AND restaurant_id = ?");
            $uNewTbl->bind_param("si", $newTableNumber, $tenantId);
            $uNewTbl->execute();
            $uNewTbl->close();

            // Check if old table has any remaining active orders
            $checkOld = $conn->prepare("SELECT id FROM orders WHERE table_number = ? AND restaurant_id = ? AND status IN ('new','preparing','ready') LIMIT 1");
            $checkOld->bind_param("si", $oldTableNumber, $tenantId);
            $checkOld->execute();
            $rem = $checkOld->get_result();
            if (!$rem || $rem->num_rows === 0) {
                $uOldTbl = $conn->prepare("UPDATE tables SET status = 'vacant' WHERE table_number = ? AND restaurant_id = ?");
                $uOldTbl->bind_param("si", $oldTableNumber, $tenantId);
                $uOldTbl->execute();
                $uOldTbl->close();
            }
            $checkOld->close();

            // Log table transfer audit record
            $tLog = $conn->prepare("INSERT INTO table_transfers (restaurant_id, order_id, from_table_number, to_table_number, transferred_by) VALUES (?, ?, ?, ?, ?)");
            $tLog->bind_param("iisss", $tenantId, $orderId, $oldTableNumber, $newTableNumber, $user);
            $tLog->execute();
            $tLog->close();

            $conn->commit();
            Security::logAudit("TABLE_TRANSFER", "Transferred Order #{$orderId} from Table {$oldTableNumber} to Table {$newTableNumber} by {$user}");
            return ['success' => true, 'message' => "Order #{$orderId} transferred to Table {$newTableNumber}"];

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Table transfer failed: ' . $e->getMessage()];
        }
    }
}
