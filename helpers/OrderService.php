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
            // Resolve the active tenant (fail closed: no tenant, no transition).
            $tenantId = (int)TenantContext::getTenantId();
            if ($tenantId <= 0) {
                $conn->rollback();
                return ['success' => false, 'message' => 'No tenant context available'];
            }

            // Lock order row for update (tenant-scoped)
            $stmt = $conn->prepare("SELECT id, status, table_number, total_amount, dining_session_id, payment_status, restaurant_id FROM orders WHERE id = ? AND restaurant_id = ? FOR UPDATE");
            $stmt->bind_param("ii", $orderId, $tenantId);
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

            // Role-specific restrictions (Fixes RMS-012): Kitchen/KDS must never
            // initiate refunds or payment-state changes, but may complete (served)
            // an order, which correctly triggers inventory consumption.
            if (in_array($userRole, ['kitchen', 'kds'], true) && in_array($nextState, ['refund_requested', 'refunded'], true)) {
                $conn->rollback();
                return ['success' => false, 'message' => 'Kitchen role is not authorized to refund or modify payment state'];
            }

            // Perform atomic state update (RMS-010 fix)
            $updateStmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ? AND status = ?");
            $updateStmt->bind_param("siis", $nextState, $orderId, $tenantId, $currentState);
            $updateStmt->execute();

            if ($updateStmt->affected_rows === 0) {
                $updateStmt->close();
                $conn->rollback();
                return ['success' => false, 'message' => 'Concurrent modification detected. Please refresh and try again.'];
            }
            $updateStmt->close();

            // Handle Inventory Deductions & Restocks atomically on completion/cancellation
            if ($nextState === 'completed' && $currentState !== 'completed') {
                self::processOrderInventoryDeduction($conn, $orderId, $tenantId);
            } elseif ($nextState === 'refunded' && $currentState === 'refund_requested') {
                self::processOrderInventoryRestock($conn, $orderId, $tenantId);
                self::processOrderLoyaltyReversal($conn, $orderId, $tenantId);
            } elseif ($nextState === 'cancelled' && in_array($currentState, ['new', 'preparing', 'ready'], true)) {
                self::processOrderLoyaltyReversal($conn, $orderId, $tenantId);
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
     * Uses the production schema for inventory_transactions (type/direction/reference_*).
     */
    private static function processOrderInventoryDeduction($conn, $orderId, $tenantId) {
        $stmt = $conn->prepare("SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($items as $item) {
            $menuItemId = intval($item['menu_item_id']);
            $qtyOrdered = intval($item['quantity']);

            // Deduct menu item stock quantity if menu_items tracking enabled (tenant-scoped)
            $uStmt = $conn->prepare("UPDATE menu_items SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ? AND restaurant_id = ?");
            $uStmt->bind_param("iii", $qtyOrdered, $menuItemId, $tenantId);
            $uStmt->execute();
            $uStmt->close();

            // Deduct raw ingredients if recipe exists (tenant-scoped)
            $recipeRes = $conn->prepare("SELECT ri.inventory_item_id, ri.quantity FROM recipe_items ri JOIN recipes r ON ri.recipe_id = r.id WHERE r.menu_item_id = ? AND r.restaurant_id = ?");
            $recipeRes->bind_param("ii", $menuItemId, $tenantId);
            $recipeRes->execute();
            $recipes = $recipeRes->get_result()->fetch_all(MYSQLI_ASSOC);
            $recipeRes->close();

            foreach ($recipes as $rec) {
                $invItemId = intval($rec['inventory_item_id']);
                $neededQty = floatval($rec['quantity']) * $qtyOrdered;

                // Idempotent constraint check (Fixes RMS-027): skip if already deducted
                $chkStmt = $conn->prepare("SELECT id FROM inventory_transactions WHERE reference_type = 'order' AND reference_id = ? AND inventory_item_id = ? AND type = 'consumption' LIMIT 1");
                $chkStmt->bind_param("ii", $orderId, $invItemId);
                $chkStmt->execute();
                $alreadyConsumed = $chkStmt->get_result()->fetch_row();
                $chkStmt->close();

                if (!$alreadyConsumed) {
                    $updStmt = $conn->prepare("UPDATE inventory_items SET current_stock = GREATEST(0, current_stock - ?) WHERE id = ? AND restaurant_id = ?");
                    $updStmt->bind_param("dii", $neededQty, $invItemId, $tenantId);
                    $updStmt->execute();
                    $updStmt->close();

                    $logStmt = $conn->prepare("INSERT INTO inventory_transactions (restaurant_id, inventory_item_id, type, quantity, direction, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'consumption', ?, 'out', 'order', ?, ?, ?)");
                    $note = "POS Order #$orderId fulfillment";
                    $creator = 'system';
                    $logStmt->bind_param("iidiss", $tenantId, $invItemId, $neededQty, $orderId, $note, $creator);
                    $logStmt->execute();
                    $logStmt->close();
                }
            }
        }
    }

    /**
     * Process Inventory Restock on Order Refund (RMS-029)
     */
    private static function processOrderInventoryRestock($conn, $orderId, $tenantId) {
        $stmt = $conn->prepare("SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($items as $item) {
            $menuItemId = intval($item['menu_item_id']);
            $qtyOrdered = intval($item['quantity']);

            $uStmt = $conn->prepare("UPDATE menu_items SET stock_quantity = stock_quantity + ? WHERE id = ? AND restaurant_id = ?");
            $uStmt->bind_param("iii", $qtyOrdered, $menuItemId, $tenantId);
            $uStmt->execute();
            $uStmt->close();

            $recipeRes = $conn->prepare("SELECT ri.inventory_item_id, ri.quantity FROM recipe_items ri JOIN recipes r ON ri.recipe_id = r.id WHERE r.menu_item_id = ? AND r.restaurant_id = ?");
            $recipeRes->bind_param("ii", $menuItemId, $tenantId);
            $recipeRes->execute();
            $recipes = $recipeRes->get_result()->fetch_all(MYSQLI_ASSOC);
            $recipeRes->close();

            foreach ($recipes as $rec) {
                $invItemId = intval($rec['inventory_item_id']);
                $restockQty = floatval($rec['quantity']) * $qtyOrdered;

                $updStmt = $conn->prepare("UPDATE inventory_items SET current_stock = current_stock + ? WHERE id = ? AND restaurant_id = ?");
                $updStmt->bind_param("dii", $restockQty, $invItemId, $tenantId);
                $updStmt->execute();
                $updStmt->close();

                $logStmt = $conn->prepare("INSERT INTO inventory_transactions (restaurant_id, inventory_item_id, type, quantity, direction, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'return', ?, 'in', 'order', ?, ?, ?)");
                $note = "Refund Restock for Order #$orderId";
                $creator = 'system';
                $logStmt->bind_param("iidiss", $tenantId, $invItemId, $restockQty, $orderId, $note, $creator);
                $logStmt->execute();
                $logStmt->close();
            }
        }
    }

    /**
     * Process Loyalty Point Reversal on Order Refund or Cancellation
     */
    public static function processOrderLoyaltyReversal($conn, $orderId, $tenantId) {
        $stmt = $conn->prepare("SELECT id, customer_id, type, points, amount_equivalent FROM loyalty_transactions WHERE order_id = ? AND restaurant_id = ?");
        if (!$stmt) return;
        $stmt->bind_param("ii", $orderId, $tenantId);
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($transactions as $tx) {
            $customerId = (int)$tx['customer_id'];
            $type = $tx['type'];
            $points = (int)$tx['points'];

            if ($type === 'earn' && $points > 0) {
                // Reverse earned points (deduct from customer)
                $uStmt = $conn->prepare("UPDATE customers SET loyalty_points = GREATEST(0, loyalty_points - ?) WHERE id = ? AND restaurant_id = ?");
                if ($uStmt) {
                    $uStmt->bind_param("iii", $points, $customerId, $tenantId);
                    $uStmt->execute();
                    $uStmt->close();
                }

                $logStmt = $conn->prepare("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, notes, created_at) VALUES (?, ?, ?, 'refund_reversal', ?, ?, ?, NOW())");
                if ($logStmt) {
                    $revPoints = -intval($points);
                    $revEq = -floatval($tx['amount_equivalent']);
                    $note = "Earned points reversed due to order #$orderId refund";
                    $logStmt->bind_param("iiiids", $tenantId, $customerId, $orderId, $revPoints, $revEq, $note);
                    $logStmt->execute();
                    $logStmt->close();
                }

            } elseif ($type === 'redeem' && $points < 0) {
                // Restore redeemed points (add back to customer)
                $restorePoints = abs(intval($points));
                $uStmt = $conn->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ? AND restaurant_id = ?");
                if ($uStmt) {
                    $uStmt->bind_param("iii", $restorePoints, $customerId, $tenantId);
                    $uStmt->execute();
                    $uStmt->close();
                }

                $logStmt = $conn->prepare("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, notes, created_at) VALUES (?, ?, ?, 'refund_reversal', ?, ?, ?, NOW())");
                if ($logStmt) {
                    $revEq = abs(floatval($tx['amount_equivalent']));
                    $note = "Redeemed points restored due to order #$orderId refund";
                    $logStmt->bind_param("iiiids", $tenantId, $customerId, $orderId, $restorePoints, $revEq, $note);
                    $logStmt->execute();
                    $logStmt->close();
                }
            }
        }
    }
}
