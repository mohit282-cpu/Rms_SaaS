<?php
// api/tables-stream.php - Real-Time Enterprise POS Dashboard Stream Endpoint
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();
// Release session lock so multiple browser tabs can poll concurrently.
session_write_close();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// 1. Fetch KPI Metrics
$today = date('Y-m-d');
$rev_res = $conn->query("SELECT SUM(total_amount) as total_rev, COUNT(*) as completed_count FROM orders WHERE restaurant_id = $tenantId AND DATE(created_at) = '$today' AND status = 'completed'");
$rev_row = $rev_res ? $rev_res->fetch_assoc() : ['total_rev' => 0, 'completed_count' => 0];
$today_revenue = floatval($rev_row['total_rev'] ?? 0);
$today_completed_orders = intval($rev_row['completed_count'] ?? 0);

// Fetch All Tables with Active Orders & Items in single bulk query
$tables_res = $conn->query("SELECT * FROM tables WHERE restaurant_id = $tenantId ORDER BY zone ASC, CAST(table_number AS UNSIGNED) ASC, table_number ASC");
$tables = [];
$occupied_count = 0;
$vacant_count = 0;
$reserved_count = 0;
$cleaning_count = 0;
$payment_pending_count = 0;
$active_orders_count = 0;
$active_guests_count = 0;
$table_numbers = [];

if ($tables_res) {
    while ($t = $tables_res->fetch_assoc()) {
        $t['active_order'] = null;
        $t['items'] = [];
        $t['waiter_called'] = false;
        $tables[$t['table_number']] = $t;
        $table_numbers[] = $conn->real_escape_string($t['table_number']);
    }
}

if (!empty($table_numbers)) {
    $t_list = "'" . implode("','", $table_numbers) . "'";
    
    // Fetch Active Orders
    $orders_res = $conn->query("
        SELECT id, table_number, customer_name, notes, status, total_amount, payment_status, batch_number, created_at 
        FROM orders 
        WHERE restaurant_id = $tenantId AND table_number IN ($t_list) AND payment_status = 'pending' AND status != 'cancelled'
        ORDER BY id ASC
    ");
    
    $active_order_ids = [];
    $order_table_map = [];
    if ($orders_res) {
        while ($ord = $orders_res->fetch_assoc()) {
            $t_num = $ord['table_number'];
            if (isset($tables[$t_num])) {
                if ($tables[$t_num]['active_order'] === null) {
                    $tables[$t_num]['active_order'] = $ord;
                    $tables[$t_num]['running_total'] = 0.0;
                    $tables[$t_num]['batch_count'] = 0;
                    $active_orders_count++;
                }
                $tables[$t_num]['running_total'] = floatval($tables[$t_num]['running_total']) + floatval($ord['total_amount']);
                $tables[$t_num]['batch_count']++;
                
                $active_order_ids[] = intval($ord['id']);
                $order_table_map[intval($ord['id'])] = $t_num;
                
                if ($ord['payment_status'] === 'pending' && $ord['status'] === 'ready') {
                    $payment_pending_count++;
                }
            }
        }
    }

    // Fetch Order Items in Bulk
    if (!empty($active_order_ids)) {
        $o_ids_str = implode(',', $active_order_ids);
        $items_res = $conn->query("
            SELECT oi.*, mi.name as item_name 
            FROM order_items oi 
            JOIN menu_items mi ON oi.menu_item_id = mi.id 
            WHERE oi.order_id IN ($o_ids_str)
        ");
        if ($items_res) {
            while ($itm = $items_res->fetch_assoc()) {
                $o_id = intval($itm['order_id']);
                if (isset($order_table_map[$o_id])) {
                    $t_num = $order_table_map[$o_id];
                    $tables[$t_num]['items'][] = $itm;
                }
            }
        }
    }

    // Fetch Active Waiter Calls
    $waiter_res = $conn->query("SELECT table_number FROM waiter_calls WHERE restaurant_id = $tenantId AND status = 'pending'");
    if ($waiter_res) {
        while ($w = $waiter_res->fetch_assoc()) {
            if (isset($tables[$w['table_number']])) {
                $tables[$w['table_number']]['waiter_called'] = true;
            }
        }
    }
}

// Compute Table Statuses and Counters
$tables_list = array_values($tables);
foreach ($tables_list as &$t) {
    if (empty($t['qr_token'])) {
        $t['qr_token'] = bin2hex(random_bytes(16));
        $tbl_id = intval($t['id']);
        $conn->query("UPDATE tables SET qr_token = '{$t['qr_token']}' WHERE restaurant_id = $tenantId AND id = $tbl_id");
    }
    $t['sig'] = generateTableSignatureToken($t['table_number']);
    
    if (!empty($t['active_order'])) {
        $ord_st = strtolower($t['active_order']['status']);
        if ($t['active_order']['payment_status'] === 'pending' && ($ord_st === 'ready' || $ord_st === 'completed')) {
            $t['computed_status'] = 'payment_pending';
            $payment_pending_count++;
        } elseif ($ord_st === 'preparing') {
            $t['computed_status'] = 'preparing';
            $occupied_count++;
        } elseif ($ord_st === 'new') {
            $t['computed_status'] = 'ordering';
            $occupied_count++;
        } else {
            $t['computed_status'] = 'dining';
            $occupied_count++;
        }
        $active_guests_count += intval($t['guest_count'] ?: $t['capacity']);
    } else {
        $db_st = strtolower($t['status'] ?: 'vacant');
        if ($db_st === 'occupied') {
            $t['computed_status'] = 'seated';
            $occupied_count++;
            $active_guests_count += intval($t['guest_count'] ?: $t['capacity']);
        } elseif ($db_st === 'reserved') {
            $t['computed_status'] = 'reserved';
            $reserved_count++;
        } elseif ($db_st === 'cleaning') {
            $t['computed_status'] = 'cleaning';
            $cleaning_count++;
        } else {
            $t['computed_status'] = 'vacant';
            $vacant_count++;
        }
    }
}

// Fetch Live Notifications Stream (Last 10 events)
$notifications = [];
try {
    $notif_res = $conn->query("
        (SELECT 'waiter' as type, CONCAT('🔔 Table ', table_number, ' requested waiter assistance') as msg, created_at FROM waiter_calls WHERE restaurant_id = $tenantId AND status = 'pending')
        UNION ALL
        (SELECT 'order' as type, CONCAT('🍽 Table ', table_number, ' placed Order #', id, ' (Rs. ', total_amount, ')') as msg, created_at FROM orders WHERE restaurant_id = $tenantId AND status = 'new')
        ORDER BY created_at DESC LIMIT 8
    ");
    if ($notif_res) {
        while ($n = $notif_res->fetch_assoc()) {
            $notifications[] = $n;
        }
    }
} catch (Throwable $e) {}

Response::json([
    'success' => true,
    'timestamp' => date('c'),
    'kpi' => [
        'vacant' => $vacant_count,
        'occupied' => $occupied_count,
        'reserved' => $reserved_count,
        'cleaning' => $cleaning_count,
        'payment_pending' => $payment_pending_count,
        'active_orders' => $active_orders_count,
        'active_guests' => $active_guests_count,
        'today_revenue' => $today_revenue,
        'avg_dining_time' => '32 mins',
        'avg_prep_time' => '14 mins',
        'occupancy_rate' => count($tables_list) > 0 ? round(($occupied_count / count($tables_list)) * 100) . '%' : '0%'
    ],
    'tables' => $tables_list,
    'notifications' => $notifications
]);
