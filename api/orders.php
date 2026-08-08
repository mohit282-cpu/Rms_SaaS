<?php
// api/orders.php - Optimized & Secured Kitchen Orders Fetch API
require_once __DIR__ . '/../config.php';

$conn = getDBConnection();
if ($conn === null) {
    Response::error('Database connection failed', 500);
}

// 1. Single Order Status Request (Public / Customer Tracker)
if (isset($_GET['id'])) {
    $order_id = intval($_GET['id']);
    
    $stmt = $conn->prepare("SELECT id, table_number, customer_name, notes, status, total_amount, payment_status, payment_method, created_at, updated_at FROM orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($order = $result->fetch_assoc()) {
        $items_stmt = $conn->prepare("
            SELECT oi.id, oi.order_id, oi.menu_item_id, oi.quantity, oi.price, mi.name 
            FROM order_items oi 
            JOIN menu_items mi ON oi.menu_item_id = mi.id 
            WHERE oi.order_id = ?
        ");
        $items_stmt->bind_param("i", $order_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        
        $items = [];
        while ($item = $items_result->fetch_assoc()) {
            $items[] = $item;
        }
        $items_stmt->close();
        
        $order['items'] = $items;
        $stmt->close();
        Response::json(['order' => $order]);
    } else {
        $stmt->close();
        Response::error('Order not found', 404);
    }
}

// 2. Bulk Orders Stream Request (Requires Kitchen / Admin Authentication)
if (!Auth::isKitchenLoggedIn() && !Auth::isAdminLoggedIn()) {
    Response::error('Unauthorized access. Staff authentication required to view bulk order streams.', 401);
}

$status = Security::sanitize($_GET['status'] ?? 'active');
$include_all = isset($_GET['include_all']) && $_GET['include_all'] == '1';

try {
    if ($status === 'cancelled') {
        $query = "SELECT * FROM orders WHERE status = 'cancelled' ORDER BY updated_at DESC LIMIT 100";
    } else if ($status === 'completed') {
        $query = "SELECT * FROM orders WHERE status = 'completed' ORDER BY updated_at DESC LIMIT 100";
    } else if ($status === 'all_history' || $include_all) {
        $query = "SELECT * FROM orders ORDER BY created_at DESC LIMIT 200";
    } else {
        // Default 'active' status: ONLY show 'new', 'preparing', 'ready' orders
        $query = "SELECT * FROM orders WHERE status IN ('new', 'preparing', 'ready') ORDER BY 
                CASE status 
                    WHEN 'new' THEN 1 
                    WHEN 'preparing' THEN 2 
                    WHEN 'ready' THEN 3 
                END, created_at DESC";
    }

    $orders_res = $conn->query($query);
    $orders_map = [];
    $order_ids = [];

    if ($orders_res) {
        while ($row = $orders_res->fetch_assoc()) {
            $row['items'] = [];
            $orders_map[$row['id']] = $row;
            $order_ids[] = $row['id'];
        }
    }

    // Single JOIN query to fetch order items in bulk (Eliminates N+1 Query Overhead)
    if (!empty($order_ids)) {
        $ids_in = implode(',', array_map('intval', $order_ids));
        $items_query = "
            SELECT oi.order_id, oi.menu_item_id, oi.quantity, oi.price, mi.name 
            FROM order_items oi 
            JOIN menu_items mi ON oi.menu_item_id = mi.id 
            WHERE oi.order_id IN ($ids_in)
        ";
        $items_res = $conn->query($items_query);
        if ($items_res) {
            while ($item = $items_res->fetch_assoc()) {
                $o_id = $item['order_id'];
                if (isset($orders_map[$o_id])) {
                    $orders_map[$o_id]['items'][] = $item;
                }
            }
        }
    }

    Response::json(['orders' => array_values($orders_map)]);

} catch (Exception $e) {
    Response::error('Failed to fetch orders: ' . $e->getMessage(), 500);
}
