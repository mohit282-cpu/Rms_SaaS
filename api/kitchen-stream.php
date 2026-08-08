<?php
// api/kitchen-stream.php - Realtime Kitchen Display System (KDS) Stream API
require_once __DIR__ . '/../config.php';

if (!Auth::isAdminLoggedIn() && !Auth::isKitchenLoggedIn()) {
    Response::error('Unauthorized access. Kitchen authentication required.', 401);
}
// Release session lock so multiple browser tabs can poll concurrently.
session_write_close();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

$today = date('Y-m-d');

// 1. Kitchen KPI Counters
$stats_res = $conn->query("
    SELECT 
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_cnt,
        SUM(CASE WHEN status = 'preparing' THEN 1 ELSE 0 END) as prep_cnt,
        SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready_cnt,
        SUM(CASE WHEN status = 'completed' AND DATE(created_at) = '$today' THEN 1 ELSE 0 END) as completed_today,
        AVG(CASE WHEN status IN ('preparing', 'ready', 'completed') AND DATE(created_at) = '$today' THEN TIMESTAMPDIFF(MINUTE, created_at, updated_at) ELSE NULL END) as avg_prep_time
    FROM orders
");
$stats = $stats_res ? $stats_res->fetch_assoc() : [];

$new_cnt = intval($stats['new_cnt'] ?? 0);
$prep_cnt = intval($stats['prep_cnt'] ?? 0);
$ready_cnt = intval($stats['ready_cnt'] ?? 0);
$completed_today = intval($stats['completed_today'] ?? 0);
$avg_prep_time = round(floatval($stats['avg_prep_time'] ?? 12), 1) . 'm';

// Calculate delayed orders (>15 mins in new/preparing)
$delayed_res = $conn->query("
    SELECT COUNT(*) as delayed_cnt 
    FROM orders 
    WHERE status IN ('new', 'preparing') 
    AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) > 15
");
$delayed_cnt = $delayed_res ? intval($delayed_res->fetch_assoc()['delayed_cnt'] ?? 0) : 0;

// Kitchen Load Meter Calculation (Base 20 active orders max)
$total_active = $new_cnt + $prep_cnt;
$kitchen_load = min(round(($total_active / 20) * 100), 100) . '%';

// 2. Active Kitchen Tickets with Items and Modifiers
$orders_res = $conn->query("
    SELECT o.*, 
        TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) as elapsed_mins
    FROM orders o
    WHERE o.status IN ('new', 'preparing', 'ready')
    ORDER BY 
        CASE WHEN o.status = 'new' THEN 1 WHEN o.status = 'preparing' THEN 2 ELSE 3 END ASC,
        o.id ASC
");

$orders = [];
if ($orders_res) {
    while ($ord = $orders_res->fetch_assoc()) {
        $order_id = intval($ord['id']);
        
        // Fetch Order Items
        $items_res = $conn->query("
            SELECT oi.*, m.name as item_name, m.preparation_time, m.allergens
            FROM order_items oi
            JOIN menu_items m ON oi.menu_item_id = m.id
            WHERE oi.order_id = $order_id
        ");
        $items = [];
        if ($items_res) {
            while ($item = $items_res->fetch_assoc()) {
                $items[] = $item;
            }
        }
        $ord['items'] = $items;
        $ord['is_delayed'] = ($ord['elapsed_mins'] > 15);
        $orders[] = $ord;
    }
}

// 3. Pending Waiter Call Alerts
$waiter_res = $conn->query("
    SELECT * FROM waiter_calls 
    WHERE status = 'pending' 
    ORDER BY created_at DESC LIMIT 10
");
$waiter_calls = [];
if ($waiter_res) {
    while ($wc = $waiter_res->fetch_assoc()) {
        $waiter_calls[] = $wc;
    }
}

Response::json([
    'success' => true,
    'timestamp' => date('c'),
    'kpi' => [
        'new_orders' => $new_cnt,
        'preparing' => $prep_cnt,
        'ready' => $ready_cnt,
        'delayed' => $delayed_cnt,
        'avg_prep_time' => $avg_prep_time,
        'completed_today' => $completed_today,
        'active_chefs' => 3,
        'kitchen_load' => $kitchen_load
    ],
    'orders' => $orders,
    'waiter_calls' => $waiter_calls
]);
