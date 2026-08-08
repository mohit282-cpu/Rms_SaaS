<?php
// api/orders-stream.php - Realtime POS Order Management Stream API
require_once __DIR__ . '/../config.php';

if (!Auth::isAdminLoggedIn() && !Auth::isKitchenLoggedIn()) {
    Response::error('Unauthorized access. Staff authentication required.', 401);
}
// Release session lock so multiple browser tabs can poll concurrently.
session_write_close();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

$today = date('Y-m-d');

// Handle POST Status Updates with SQL Transaction & State Lock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $new_status = Security::sanitize($_POST['status'] ?? '');
    
    if ($order_id > 0 && in_array($new_status, ['new', 'preparing', 'ready', 'completed', 'cancelled'])) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $new_status, $order_id);
                $stmt->execute();
                $stmt->close();
            }

            // If order completed, update table status if no other active orders remain on table
            if ($new_status === 'completed') {
                $t_res = $conn->query("SELECT table_number FROM orders WHERE id = $order_id LIMIT 1");
                if ($t_res && $t_row = $t_res->fetch_assoc()) {
                    $tbl_num = $conn->real_escape_string($t_row['table_number']);
                    $active_check = $conn->query("SELECT id FROM orders WHERE table_number = '$tbl_num' AND status IN ('new', 'preparing', 'ready') AND id != $order_id LIMIT 1");
                    if (!$active_check || $active_check->num_rows == 0) {
                        $conn->query("UPDATE tables SET status = 'vacant' WHERE table_number = '$tbl_num'");
                    }
                }
            }

            // Audit log event
            $user_role = $_SESSION['admin_user'] ?? 'kitchen';
            $audit = $conn->prepare("INSERT INTO audit_logs (username, event_type, description) VALUES (?, 'ORDER_STATUS_UPDATE', ?)");
            if ($audit) {
                $desc = "Order #$order_id status changed to " . strtoupper($new_status);
                $audit->bind_param("ss", $user_role, $desc);
                $audit->execute();
                $audit->close();
            }

            $conn->commit();
            Response::success('Order status updated successfully');
        } catch (Throwable $e) {
            $conn->rollback();
            Response::error('Failed to update order status: ' . $e->getMessage(), 500);
        }
    } else {
        Response::error('Invalid order ID or status', 400);
    }
}

// Handle POST Table Status Updates (Vacant, Cleaning, Reserved, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_table_status') {
    $table_id = intval($_POST['id'] ?? 0);
    $table_number = Security::sanitize($_POST['table_number'] ?? '');
    $status = Security::sanitize($_POST['status'] ?? 'vacant');

    if (in_array($status, ['vacant', 'occupied', 'reserved', 'cleaning', 'disabled'])) {
        $conn->begin_transaction();
        try {
            if ($table_id > 0) {
                $stmt = $conn->prepare("UPDATE tables SET status = ? WHERE id = ?");
                $stmt->bind_param("si", $status, $table_id);
                $stmt->execute();
                $stmt->close();
            } elseif (!empty($table_number)) {
                $stmt = $conn->prepare("UPDATE tables SET status = ? WHERE table_number = ?");
                $stmt->bind_param("ss", $status, $table_number);
                $stmt->execute();
                $stmt->close();
            }

            // If marked vacant or cleaning, close active dining session
            if ($status === 'vacant' || $status === 'cleaning') {
                $tbl_safe = $conn->real_escape_string($table_number);
                $conn->query("UPDATE orders SET payment_status = 'paid', status = 'completed' WHERE table_number = '$tbl_safe' AND payment_status = 'pending'");
                $conn->query("UPDATE dining_sessions SET status = 'closed' WHERE table_number = '$tbl_safe' AND status = 'active'");
            }

            $conn->commit();
            Response::success("Table status updated to " . ucfirst($status));
        } catch (Throwable $e) {
            $conn->rollback();
            Response::error("Failed to update table status: " . $e->getMessage(), 500);
        }
    } else {
        Response::error("Invalid status value", 400);
    }
}

// Handle POST Settle & Bill Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_table_payment') {
    $table_number = Security::sanitize($_POST['table_number'] ?? '');
    if (!empty($table_number)) {
        $conn->begin_transaction();
        try {
            $tbl_safe = $conn->real_escape_string($table_number);
            // Settle all pending orders for this table
            $conn->query("UPDATE orders SET payment_status = 'paid', status = 'completed', updated_at = NOW() WHERE table_number = '$tbl_safe' AND payment_status = 'pending'");
            // Close active dining session
            $conn->query("UPDATE dining_sessions SET status = 'closed', ended_at = NOW() WHERE table_number = '$tbl_safe' AND status = 'active'");
            // Set table status to vacant
            $conn->query("UPDATE tables SET status = 'vacant' WHERE table_number = '$tbl_safe'");

            $conn->commit();
            Response::success("Table $table_number bill settled & marked vacant successfully!");
        } catch (Throwable $e) {
            $conn->rollback();
            Response::error("Failed to settle bill: " . $e->getMessage(), 500);
        }
    } else {
        Response::error("Missing table number", 400);
    }
}

$status_filter = Security::sanitize($_GET['status'] ?? 'all');

// 1. Fetch KPI Counters
$today_orders_res = $conn->query("
    SELECT 
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as cnt_new,
        SUM(CASE WHEN status = 'preparing' THEN 1 ELSE 0 END) as cnt_preparing,
        SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as cnt_ready,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as cnt_completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cnt_cancelled,
        SUM(CASE WHEN payment_status = 'pending' AND status IN ('new', 'preparing', 'ready') THEN 1 ELSE 0 END) as cnt_pending_pay,
        SUM(CASE WHEN status IN ('new', 'preparing') AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) > 20 THEN 1 ELSE 0 END) as cnt_delayed,
        SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as rev_today
    FROM orders 
    WHERE DATE(created_at) = '$today'
");

$kpi = $today_orders_res ? $today_orders_res->fetch_assoc() : [];

$active_tables_res = $conn->query("SELECT COUNT(DISTINCT table_number) as cnt FROM orders WHERE status IN ('new', 'preparing', 'ready')");
$active_tables_cnt = $active_tables_res ? intval($active_tables_res->fetch_assoc()['cnt']) : 0;

// 2. Build SQL Query for Orders Stream
$sql = "
    SELECT o.*, 
           t.capacity, t.assigned_waiter, t.zone,
           TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) as elapsed_mins,
           CASE WHEN o.status IN ('new', 'preparing') AND TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) > 20 THEN 1 ELSE 0 END as is_delayed
    FROM orders o
    LEFT JOIN tables t ON o.table_number = t.table_number
";

$where_clauses = [];
if ($status_filter === 'new') $where_clauses[] = "o.status = 'new'";
else if ($status_filter === 'preparing') $where_clauses[] = "o.status = 'preparing'";
else if ($status_filter === 'ready') $where_clauses[] = "o.status = 'ready'";
else if ($status_filter === 'completed') $where_clauses[] = "o.status = 'completed'";
else if ($status_filter === 'cancelled') $where_clauses[] = "o.status = 'cancelled'";
else if ($status_filter === 'payment_pending') $where_clauses[] = "o.payment_status = 'pending' AND o.status IN ('new', 'preparing', 'ready')";
else if ($status_filter === 'delayed') $where_clauses[] = "o.status IN ('new', 'preparing') AND TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) > 20";

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY 
    CASE o.status 
        WHEN 'new' THEN 1 
        WHEN 'preparing' THEN 2 
        WHEN 'ready' THEN 3 
        WHEN 'completed' THEN 4 
        ELSE 5 
    END, o.created_at DESC LIMIT 150";

$orders_res = $conn->query($sql);
$orders_list = [];
$order_ids = [];
$order_index_map = [];

if ($orders_res) {
    while ($ord = $orders_res->fetch_assoc()) {
        $ord['items'] = [];
        $orders_list[] = $ord;
        $order_ids[] = intval($ord['id']);
        $order_index_map[intval($ord['id'])] = count($orders_list) - 1;
    }
}

// 3. Fetch Itemized Lines in Single Bulk JOIN
if (!empty($order_ids)) {
    $ids_str = implode(',', $order_ids);
    $items_res = $conn->query("
        SELECT oi.*, mi.name as item_name, mi.image as item_image 
        FROM order_items oi 
        JOIN menu_items mi ON oi.menu_item_id = mi.id 
        WHERE oi.order_id IN ($ids_str)
    ");
    if ($items_res) {
        while ($itm = $items_res->fetch_assoc()) {
            $o_id = intval($itm['order_id']);
            if (isset($order_index_map[$o_id])) {
                $idx = $order_index_map[$o_id];
                $orders_list[$idx]['items'][] = $itm;
            }
        }
    }
}

Response::json([
    'success' => true,
    'timestamp' => date('c'),
    'kpi' => [
        'new_orders' => intval($kpi['cnt_new'] ?? 0),
        'preparing' => intval($kpi['cnt_preparing'] ?? 0),
        'ready' => intval($kpi['cnt_ready'] ?? 0),
        'served' => intval($kpi['cnt_completed'] ?? 0),
        'cancelled' => intval($kpi['cnt_cancelled'] ?? 0),
        'payment_pending' => intval($kpi['cnt_pending_pay'] ?? 0),
        'active_tables' => $active_tables_cnt,
        'today_revenue' => floatval($kpi['rev_today'] ?? 0),
        'avg_prep_time' => '14m',
        'delayed_orders' => intval($kpi['cnt_delayed'] ?? 0)
    ],
    'orders' => $orders_list
]);
