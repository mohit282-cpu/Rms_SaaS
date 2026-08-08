<?php
// api/orders.php - Optimized & Secured Kitchen Orders Fetch API
require_once __DIR__ . '/../config.php';

$conn = getDBConnection();
if ($conn === null) {
    Response::error('Database connection failed', 500);
}

$isStaff = Auth::isAdminLoggedIn() || Auth::isKitchenLoggedIn();
$isCustomer = isset($_SESSION['customer_restaurant_id']);

// 1. Single Order Status Request (Customer Tracker / Staff Detail View)
if (isset($_GET['id'])) {
    $order_id = intval($_GET['id']);

    // Access rule: staff (tenant-scoped) OR a customer session whose table owns the order.
    if (!$isStaff && !$isCustomer) {
        Response::error('Unauthorized access.', 401);
    }

    $tenantId = (int)TenantContext::getTenantId();
    if ($tenantId <= 0) {
        Response::error('Forbidden. No tenant context.', 403);
    }

    // Tenant-scoped fetch. For customer sessions, additionally require the order's
    // table to match the customer's active table session.
    if ($isCustomer) {
        $table_num = strval($_SESSION['customer_table_id'] ?? '');
        if ($table_num === '') {
            Response::error('Forbidden. No table session.', 403);
        }
        $stmt = $conn->prepare("SELECT id, table_number, customer_name, notes, status, total_amount, payment_status, payment_method, created_at, updated_at FROM orders WHERE id = ? AND restaurant_id = ? AND table_number = ? LIMIT 1");
        $stmt->bind_param("iis", $order_id, $tenantId, $table_num);
    } else {
        $stmt = $conn->prepare("SELECT id, table_number, customer_name, notes, status, total_amount, payment_status, payment_method, created_at, updated_at FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
        $stmt->bind_param("ii", $order_id, $tenantId);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($order = $result->fetch_assoc()) {
        $stmt->close();
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
        Response::json(['order' => $order]);
    } else {
        $stmt->close();
        Response::error('Order not found', 404);
    }
}

// 2. Bulk Orders Stream Request (Requires Staff Authentication + tenant context)
if (!$isStaff) {
    Response::error('Unauthorized access. Staff authentication required to view bulk order streams.', 401);
}

$tenantId = (int)AuthorizationService::requireTenantApi();
if ($tenantId <= 0) {
    Response::error('Forbidden. No tenant context.', 403);
}
session_write_close();

$status = Security::sanitize($_GET['status'] ?? 'active');
$include_all = isset($_GET['include_all']) && $_GET['include_all'] == '1';

try {
    if ($status === 'cancelled') {
        $query = "SELECT * FROM orders WHERE restaurant_id = ? AND status = 'cancelled' ORDER BY updated_at DESC LIMIT 100";
    } else if ($status === 'completed') {
        $query = "SELECT * FROM orders WHERE restaurant_id = ? AND status = 'completed' ORDER BY updated_at DESC LIMIT 100";
    } else if ($status === 'all_history' || $include_all) {
        $query = "SELECT * FROM orders WHERE restaurant_id = ? ORDER BY created_at DESC LIMIT 200";
    } else {
        // Default 'active' status: ONLY show 'new', 'preparing', 'ready' orders
        $query = "SELECT * FROM orders WHERE restaurant_id = ? AND status IN ('new', 'preparing', 'ready') ORDER BY
                CASE status
                    WHEN 'new' THEN 1
                    WHEN 'preparing' THEN 2
                    WHEN 'ready' THEN 3
                END, created_at DESC";
    }

    $orders_stmt = $conn->prepare($query);
    $orders_stmt->bind_param("i", $tenantId);
    $orders_stmt->execute();
    $orders_res = $orders_stmt->get_result();
    $orders_map = [];
    $order_ids = [];

    if ($orders_res) {
        while ($row = $orders_res->fetch_assoc()) {
            $row['items'] = [];
            $orders_map[$row['id']] = $row;
            $order_ids[] = $row['id'];
        }
    }
    $orders_stmt->close();

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
