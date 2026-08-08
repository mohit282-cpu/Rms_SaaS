<?php
// helpers/OrderService.php - Centralized Order Lifecycle & State Machine Service

class OrderService {

    // Valid state transitions for orders (Fixes RMS-008, RMS-009, RMS-028)
    private static $allowedTransitions = [
        'new' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['completed', 'cancelled'],
        'completed' => ['refund_requested'],
        'refund_requested' => ['refunded'],
        'refunded' => [],
        'cancelled' => []
    ];

    /**
     * Validate if state transition is allowed
     */
    public static function isValidTransition($currentState, $nextState) {
        $currentState = strtolower(trim($currentState));
        $nextState = strtolower(trim($nextState));

        if ($currentState === $nextState) {
            return true;
        }

        $allowed = self::$allowedTransitions[$currentState] ?? [];
        return in_array($nextState, $allowed, true);
    }

    /**
     * Atomically transition order status with row locking & inventory transaction protection
     * Fixes RMS-008, RMS-009, RMS-010, RMS-011, RMS-027, RMS-028, RMS-038
     */
    public static function transitionStatus($conn, $orderId, $nextState, $userRole = 'admin', $notes = '') {
        $orderId = intval($orderId);
        $nextState = strtolower(trim($nextState));

        if ($orderId <= 0) {
            return ['success' => false, 'message' => 'Invalid order ID'];
        }

        $conn->begin_transaction();

        try {
            // Lock order row for update
            $stmt = $conn->prepare("SELECT id, status, table_number, total_amount, dining_session_id, payment_status FROM orders WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$order) {
                $conn->rollback();
                return ['success' => false, 'message' => 'Order not found'];
            }

            $currentState = strtolower($order['status']);

            if ($currentState === $nextState) {
                $conn->commit();
                return ['success' => true, 'message' => 'Order status is already ' . $nextState, 'status' => $nextState];
            }

            if (!self::isValidTransition($currentState, $nextState)) {
                $conn->rollback();
                return ['success' => false, 'message' => "Invalid status transition from '$currentState' to '$nextState'"];
            }

            // Role-specific restrictions (Fixes RMS-012)
            if (in_array($userRole, ['kitchen', 'kds'], true) && in_array($nextState, ['completed', 'refund_requested', 'refunded'], true)) {
                $conn->rollback();
                return ['success' => false, 'message' => 'Kitchen role is not authorized to complete or refund orders'];
            }

            // Perform atomic state update (RMS-010 fix)
            $updateStmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?");
            $updateStmt->bind_param("sis", $nextState, $orderId, $currentState);
            $updateStmt->execute();

            if ($updateStmt->affected_rows === 0) {
                $updateStmt->close();
                $conn->rollback();
                return ['success' => false, 'message' => 'Concurrent modification detected. Please refresh and try again.'];
            }
            $updateStmt->close();

            // Handle Inventory Deductions & Restocks atomically on completion/cancellation
            if ($nextState === 'completed' && $currentState !== 'completed') {
                self::processOrderInventoryDeduction($conn, $orderId);
            } elseif ($nextState === 'refunded' && $currentState === 'refund_requested') {
                self::processOrderInventoryRestock($conn, $orderId);
            }

            $conn->commit();
            return ['success' => true, 'message' => "Order #$orderId updated to $nextState", 'status' => $nextState];

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()];
        }
    }

    /**
     * Atomic Inventory Deduction for Order Items (RMS-011, RMS-027, RMS-038)
     */
    private static function processOrderInventoryDeduction($conn, $orderId) {
        // Fetch order items
        $stmt = $conn->prepare("SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($items as $item) {
            $menuItemId = intval($item['menu_item_id']);
            $qtyOrdered = intval($item['quantity']);

            // Deduct menu item stock quantity if menu_items tracking enabled
            $conn->query("UPDATE menu_items SET stock_quantity = GREATEST(0, stock_quantity - $qtyOrdered) WHERE id = $menuItemId");

            // Deduct raw ingredients if recipe exists
            $recipeRes = $conn->query("SELECT inventory_item_id, quantity FROM recipes WHERE menu_item_id = $menuItemId");
            if ($recipeRes && $recipeRes->num_rows > 0) {
                while ($rec = $recipeRes->fetch_assoc()) {
                    $invItemId = intval($rec['inventory_item_id']);
                    $neededQty = floatval($rec['quantity']) * $qtyOrdered;

                    // Idempotent constraint check using transaction_type (Fixes RMS-027)
                    $chkStmt = $conn->prepare("SELECT id FROM inventory_transactions WHERE order_id = ? AND inventory_item_id = ? AND transaction_type = 'sale' LIMIT 1");
                    $chkStmt->bind_param("ii", $orderId, $invItemId);
                    $chkStmt->execute();
                    if ($chkStmt->get_result()->num_rows === 0) {
                        $conn->query("UPDATE inventory_items SET current_stock = GREATEST(0, current_stock - $neededQty) WHERE id = $invItemId");
                        $logStmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_item_id, order_id, transaction_type, quantity, notes) VALUES (?, ?, 'sale', ?, ?)");
                        $note = "POS Order #$orderId fulfillment";
                        $logStmt->bind_param("iids", $invItemId, $orderId, $neededQty, $note);
                        $logStmt->execute();
                        $logStmt->close();
                    }
                    $chkStmt->close();
                }
            }
        }
    }

    /**
     * Process Inventory Restock on Order Refund (RMS-029)
     */
    private static function processOrderInventoryRestock($conn, $orderId) {
        $stmt = $conn->prepare("SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($items as $item) {
            $menuItemId = intval($item['menu_item_id']);
            $qtyOrdered = intval($item['quantity']);

            $conn->query("UPDATE menu_items SET stock_quantity = stock_quantity + $qtyOrdered WHERE id = $menuItemId");

            $recipeRes = $conn->query("SELECT inventory_item_id, quantity FROM recipes WHERE menu_item_id = $menuItemId");
            if ($recipeRes && $recipeRes->num_rows > 0) {
                while ($rec = $recipeRes->fetch_assoc()) {
                    $invItemId = intval($rec['inventory_item_id']);
                    $restockQty = floatval($rec['quantity']) * $qtyOrdered;

                    $conn->query("UPDATE inventory_items SET current_stock = current_stock + $restockQty WHERE id = $invItemId");

                    $logStmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_item_id, order_id, transaction_type, quantity, notes) VALUES (?, ?, 'restock', ?, ?)");
                    $note = "Refund Restock for Order #$orderId";
                    $logStmt->bind_param("iids", $invItemId, $orderId, $restockQty, $note);
                    $logStmt->execute();
                    $logStmt->close();
                }
            }
        }
    }
}
