<?php
// api/orders-stream.php - Realtime POS Order Management Stream API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();
// Release session lock so multiple browser tabs can poll concurrently.
session_write_close();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

$today = date('Y-m-d');

// Handle POST Status Updates with SQL Transaction & State Lock (tenant-scoped)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $new_status = Security::sanitize($_POST['status'] ?? '');

    if ($order_id > 0 && in_array($new_status, ['new', 'preparing', 'ready', 'completed', 'cancelled'])) {
        require_once __DIR__ . '/../helpers/OrderService.php';

        // Resolve requester role for state-machine authorization (RMS-012)
        $userRole = 'admin';
        if (Auth::isKitchenLoggedIn() && !Auth::isAdminLoggedIn()) {
            $userRole = 'kitchen';
        }

        // Route through the centralized state machine: validates transitions,
        // locks the row (FOR UPDATE), detects concurrent modifications, and
        // atomically handles inventory deduction/restock (RMS-008..011, RMS-027, RMS-028).
        $result = OrderService::transitionStatus($conn, $order_id, $new_status, $userRole);
        if (!$result['success']) {
            Response::error($result['message'], 400);
        }

        $conn->begin_transaction();
        try {
            // If order completed, update table status to waiting_bill if no other active kitchen orders remain on table
            if ($new_status === 'completed') {
                $t_stmt = $conn->prepare("SELECT table_number FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
                $t_stmt->bind_param("ii", $order_id, $tenantId);
                $t_stmt->execute();
                $t_res = $t_stmt->get_result();
                if ($t_row = $t_res->fetch_assoc()) {
                    $t_stmt->close();
                    $tbl_num = $t_row['table_number'];
                    $active_stmt = $conn->prepare("SELECT id FROM orders WHERE table_number = ? AND restaurant_id = ? AND status IN ('new', 'preparing', 'ready') AND id != ? LIMIT 1");
                    $active_stmt->bind_param("sii", $tbl_num, $tenantId, $order_id);
                    $active_stmt->execute();
                    $active_check = $active_stmt->get_result();
                    if (!$active_check || $active_check->num_rows == 0) {
                        $active_stmt->close();
                        $upd_stmt = $conn->prepare("UPDATE tables SET status = 'waiting_bill' WHERE table_number = ? AND restaurant_id = ? AND status != 'disabled'");
                        $upd_stmt->bind_param("si", $tbl_num, $tenantId);
                        $upd_stmt->execute();
                        $upd_stmt->close();
                    } else {
                        $active_stmt->close();
                    }
                } else {
                    $t_stmt->close();
                }
            }

            // Audit log event (tenant-scoped)
            $user_role = $_SESSION['admin_username'] ?? 'kitchen';
            $audit = $conn->prepare("INSERT INTO audit_logs (restaurant_id, username, event_type, description) VALUES (?, ?, 'ORDER_STATUS_UPDATE', ?)");
            if ($audit) {
                $desc = "Order #$order_id status changed to " . strtoupper($new_status);
                $audit->bind_param("iss", $tenantId, $user_role, $desc);
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
    if (!Auth::checkPermission('tables.manage') && !Auth::checkPermission('tables.view')) {
        Response::error('Access Denied: Role permission required to update table status', 403);
    }
    $table_id = intval($_POST['id'] ?? 0);
    $table_number = Security::sanitize($_POST['table_number'] ?? '');
    $status = Security::sanitize($_POST['status'] ?? 'vacant');

    if (in_array($status, ['vacant', 'occupied', 'reserved', 'cleaning', 'disabled'])) {
        $conn->begin_transaction();
        try {
            if ($table_id > 0) {
                $stmt = $conn->prepare("UPDATE tables SET status = ? WHERE id = ? AND restaurant_id = ?");
                $stmt->bind_param("sii", $status, $table_id, $tenantId);
                $stmt->execute();
                $stmt->close();
            } elseif (!empty($table_number)) {
                $stmt = $conn->prepare("UPDATE tables SET status = ? WHERE table_number = ? AND restaurant_id = ?");
                $stmt->bind_param("ssi", $status, $table_number, $tenantId);
                $stmt->execute();
                $stmt->close();
            }

            // If trying to mark vacant or cleaning, verify there are NO unpaid active orders (Fixes RMS Table Payment Bug)
            if ($status === 'vacant' || $status === 'cleaning') {
                $unpaid_stmt = $conn->prepare("SELECT id, total_amount FROM orders WHERE table_number = ? AND restaurant_id = ? AND payment_status = 'pending' AND status != 'cancelled' LIMIT 1");
                $unpaid_stmt->bind_param("si", $table_number, $tenantId);
                $unpaid_stmt->execute();
                $unpaid_res = $unpaid_stmt->get_result();
                if ($unpaid_res && $unpaid_res->num_rows > 0) {
                    $unpaid_order = $unpaid_res->fetch_assoc();
                    $unpaid_stmt->close();
                    $conn->rollback();
                    Response::error("Cannot mark table " . htmlspecialchars($table_number) . " as " . $status . ". There is an unpaid order (#" . $unpaid_order['id'] . "). Please complete payment in RPOS first.", 400);
                    exit;
                }
                $unpaid_stmt->close();

                // Close dining session safely
                $upd2 = $conn->prepare("UPDATE dining_sessions SET status = 'closed' WHERE table_number = ? AND restaurant_id = ? AND status = 'active'");
                $upd2->bind_param("si", $table_number, $tenantId);
                $upd2->execute();
                $upd2->close();
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

// Legacy payment bypass (settle_table_payment) is permanently REMOVED for financial security (RMS Rule 7).
// All bill settlements must be processed authoritatively through api/table-payment.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_table_payment') {
    Response::error("Legacy payment bypass disabled. All bill settlements must be processed via api/table-payment.php.", 400);
}

$status_filter = Security::sanitize($_GET['status'] ?? 'all');

// 1. Fetch KPI Counters (tenant-scoped)
$today_orders_stmt = $conn->prepare("
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
    WHERE restaurant_id = ? AND DATE(created_at) = ?
");
$today_orders_stmt->bind_param("is", $tenantId, $today);
$today_orders_stmt->execute();
$today_orders_res = $today_orders_stmt->get_result();
$kpi = $today_orders_res ? $today_orders_res->fetch_assoc() : [];
$today_orders_stmt->close();

$active_stmt = $conn->prepare("SELECT COUNT(DISTINCT table_number) as cnt FROM orders WHERE restaurant_id = ? AND status IN ('new', 'preparing', 'ready')");
$active_stmt->bind_param("i", $tenantId);
$active_stmt->execute();
$active_tables_res = $active_stmt->get_result();
$active_tables_cnt = $active_tables_res ? intval($active_tables_res->fetch_assoc()['cnt']) : 0;
$active_stmt->close();

// 2. Build SQL Query for Orders Stream (tenant-scoped)
$sql = "
    SELECT o.*,
           t.capacity, t.assigned_waiter, t.zone,
           TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) as elapsed_mins,
           CASE WHEN o.status IN ('new', 'preparing') AND TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) > 20 THEN 1 ELSE 0 END as is_delayed
    FROM orders o
    LEFT JOIN tables t ON o.table_number = t.table_number AND t.restaurant_id = o.restaurant_id
";

$where_clauses = ["o.restaurant_id = " . (int)$tenantId];
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
