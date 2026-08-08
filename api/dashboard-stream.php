<?php
// api/dashboard-stream.php - Realtime Restaurant Operations Center Stream & Search API
// Transport: AJAX polling (project-native realtime transport). Returns a single
// consolidated JSON payload so the dashboard never issues 20+ queries per poll.
require_once __DIR__ . '/../config.php';

// Ensure output buffering is clean before emitting JSON
while (ob_get_level() > 0) {
    @ob_end_clean();
}

try {
    // Staff authentication guard (admin OR kitchen). Kitchen check accepts an authenticated admin session.
    if (!Auth::isAdminLoggedIn() && !Auth::isKitchenLoggedIn()) {
        Response::error('Unauthorized access. Staff authentication required.', 401, 'UNAUTHORIZED');
    }

    // Release PHP session file lock for polling
    session_write_close();

    $conn = getDBConnection();
    if (!$conn) {
        Response::error('Live order service is temporarily unavailable. Database connection failed.', 500, 'SERVICE_UNAVAILABLE');
    }

    // Resolve Authenticated Tenant Context (NEVER trusts raw GET/POST params)
    $tenantId = TenantContext::getTenantId();

    // ----------------------------------------------------
    // GLOBAL SEARCH ACTION (IF REQUESTED)
    // ----------------------------------------------------
    $action = $_GET['action'] ?? 'stream';
    if ($action === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            Response::json(['success' => true, 'query' => $q, 'results' => []]);
        }
        $escaped = str_replace(['%', '_'], ['\%', '\_'], $conn->real_escape_string($q));
        $like = '%' . $escaped . '%';
        $results = [];

        // Search Orders
        $stmt = $conn->prepare("SELECT id, table_number, customer_name, total_amount, status FROM orders WHERE restaurant_id = ? AND (id LIKE ? OR customer_name LIKE ? OR table_number LIKE ?) LIMIT 5");
        if ($stmt) {
            $stmt->bind_param('isss', $tenantId, $like, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $results[] = [
                    'type' => 'order',
                    'title' => 'Order #' . $r['id'] . ' (Table ' . $r['table_number'] . ')',
                    'subtitle' => 'Rs. ' . $r['total_amount'] . ' · ' . strtoupper($r['status']),
                    'link' => 'orders.php?order_id=' . $r['id']
                ];
            }
            $stmt->close();
        }

        // Search Inventory Items
        $stmt = $conn->prepare("SELECT id, name, barcode, current_stock FROM inventory_items WHERE restaurant_id = ? AND (name LIKE ? OR barcode LIKE ?) LIMIT 5");
        if ($stmt) {
            $stmt->bind_param('iss', $tenantId, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $results[] = [
                    'type' => 'inventory',
                    'title' => $r['name'],
                    'subtitle' => 'Stock: ' . floatval($r['current_stock']) . ($r['barcode'] ? ' · Code: ' . $r['barcode'] : ''),
                    'link' => 'inventory-items.php?search=' . urlencode($r['name'])
                ];
            }
            $stmt->close();
        }

        // Search Assets
        $stmt = $conn->prepare("SELECT id, name, asset_code, serial_number FROM assets WHERE restaurant_id = ? AND (name LIKE ? OR asset_code LIKE ? OR serial_number LIKE ?) LIMIT 5");
        if ($stmt) {
            $stmt->bind_param('isss', $tenantId, $like, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $results[] = [
                    'type' => 'asset',
                    'title' => $r['name'],
                    'subtitle' => 'Code: ' . $r['asset_code'],
                    'link' => 'assets.php?search=' . urlencode($r['asset_code'])
                ];
            }
            $stmt->close();
        }

        // Search Suppliers
        $stmt = $conn->prepare("SELECT id, company_name, contact_person, phone FROM suppliers WHERE restaurant_id = ? AND (company_name LIKE ? OR contact_person LIKE ? OR phone LIKE ?) LIMIT 5");
        if ($stmt) {
            $stmt->bind_param('isss', $tenantId, $like, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $results[] = [
                    'type' => 'supplier',
                    'title' => $r['company_name'],
                    'subtitle' => 'Contact: ' . ($r['contact_person'] ?: '-') . ' (' . $r['phone'] . ')',
                    'link' => 'suppliers.php'
                ];
            }
            $stmt->close();
        }

        Response::json(['success' => true, 'query' => $q, 'results' => $results]);
    }

    // ----------------------------------------------------
    // MAIN STREAM DATA COMPUTATION (TENANT SCOPED)
    // ----------------------------------------------------
    $today = date('Y-m-d');
    $todayStart = $today . ' 00:00:00';
    $tomorrowStart = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';
    $yesterdayStart = date('Y-m-d', strtotime('-1 day')) . ' 00:00:00';
    $weekStart = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
    $monthStart = date('Y-m-d', strtotime('first day of this month')) . ' 00:00:00';

    // 1. TODAY'S ORDER COUNTERS + REVENUE (tenant scoped)
    $stats = ['total_orders' => 0, 'cnt_new' => 0, 'cnt_preparing' => 0, 'cnt_ready' => 0, 'cnt_completed' => 0, 'cnt_cancelled' => 0, 'rev_today' => 0.0, 'avg_prep' => null];
    $statsStmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS cnt_new,
            SUM(CASE WHEN status = 'preparing' THEN 1 ELSE 0 END) AS cnt_preparing,
            SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) AS cnt_ready,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS cnt_completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cnt_cancelled,
            SUM(CASE WHEN status = 'completed' AND payment_status = 'paid' THEN total_amount ELSE 0 END) AS rev_today,
            AVG(CASE WHEN status IN ('ready', 'completed') THEN TIMESTAMPDIFF(MINUTE, created_at, updated_at) ELSE NULL END) AS avg_prep
        FROM orders
        WHERE restaurant_id = ? AND created_at >= ? AND created_at < ?
    ");
    if ($statsStmt) {
        $statsStmt->bind_param("iss", $tenantId, $todayStart, $tomorrowStart);
        $statsStmt->execute();
        $res = $statsStmt->get_result();
        if ($row = $res->fetch_assoc()) {
            foreach ($row as $k => $v) {
                if ($v !== null && array_key_exists($k, $stats)) $stats[$k] = $v;
            }
        }
        $statsStmt->close();
    }

    $today_total_orders = intval($stats['total_orders'] ?? 0);
    $status_counts = [
        'new' => intval($stats['cnt_new'] ?? 0),
        'preparing' => intval($stats['cnt_preparing'] ?? 0),
        'ready' => intval($stats['cnt_ready'] ?? 0),
        'completed' => intval($stats['cnt_completed'] ?? 0),
        'cancelled' => intval($stats['cnt_cancelled'] ?? 0),
    ];
    $today_revenue = floatval($stats['rev_today'] ?? 0);
    $active_orders_count = $status_counts['new'] + $status_counts['preparing'] + $status_counts['ready'];
    $avg_prep_mins = ($stats['avg_prep'] !== null && $stats['avg_prep'] !== '') ? max(0, intval(round(floatval($stats['avg_prep'])))) : 0;

    // 2. YESTERDAY / WEEK / MONTH SETTLED REVENUE (tenant scoped)
    $rev_hist = ['rev_yesterday' => 0.0, 'rev_week' => 0.0, 'rev_month' => 0.0];
    $revStmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN created_at >= ? AND created_at < ? THEN total_amount ELSE 0 END) AS rev_yesterday,
            SUM(CASE WHEN created_at >= ? THEN total_amount ELSE 0 END) AS rev_week,
            SUM(CASE WHEN created_at >= ? THEN total_amount ELSE 0 END) AS rev_month
        FROM orders
        WHERE restaurant_id = ? AND created_at >= ? AND status = 'completed' AND payment_status = 'paid'
    ");
    if ($revStmt) {
        $revStmt->bind_param("ssssis", $yesterdayStart, $todayStart, $weekStart, $monthStart, $tenantId, $weekStart);
        $revStmt->execute();
        $res = $revStmt->get_result();
        if ($row = $res->fetch_assoc()) {
            foreach ($row as $k => $v) {
                if ($v !== null && array_key_exists($k, $rev_hist)) $rev_hist[$k] = floatval($v);
            }
        }
        $revStmt->close();
    }
    $yesterday_revenue = $rev_hist['rev_yesterday'];
    $this_week_revenue = $rev_hist['rev_week'];
    $this_month_revenue = $rev_hist['rev_month'];
    $rev_diff = $today_revenue - $yesterday_revenue;
    $rev_change_pct = ($yesterday_revenue > 0) ? round(($rev_diff / $yesterday_revenue) * 100, 1) : ($today_revenue > 0 ? 100.0 : 0.0);

    // 3. ACTIVE ORDERS & LONGEST WAIT (tenant scoped)
    $active_row = ['cnt' => 0, 'longest' => 0];
    $actStmt = $conn->prepare("
        SELECT COUNT(*) AS cnt, COALESCE(MAX(TIMESTAMPDIFF(MINUTE, created_at, NOW())), 0) AS longest
        FROM orders WHERE restaurant_id = ? AND status IN ('new', 'preparing', 'ready')
    ");
    if ($actStmt) {
        $actStmt->bind_param("i", $tenantId);
        $actStmt->execute();
        $res = $actStmt->get_result();
        if ($row = $res->fetch_assoc()) { $active_row = $row; }
        $actStmt->close();
    }
    $active_count_all = intval($active_row['cnt'] ?? 0);
    $longest_wait_mins = intval($active_row['longest'] ?? 0);

    // 4. TABLE & FLOOR METRICS (tenant scoped)
    $total_tables = 0; $occupied_tables = 0; $vacant_tables = 0; $reserved_tables = 0; $cleaning_tables = 0; $disabled_tables = 0; $active_guests = 0;
    $tblStmt = $conn->prepare("SELECT status, guest_count FROM tables WHERE restaurant_id = ?");
    if ($tblStmt) {
        $tblStmt->bind_param("i", $tenantId);
        $tblStmt->execute();
        $res = $tblStmt->get_result();
        while ($t = $res->fetch_assoc()) {
            $total_tables++;
            $st = strtolower($t['status'] ?: 'vacant');
            if ($st === 'occupied') $occupied_tables++;
            else if ($st === 'reserved') $reserved_tables++;
            else if ($st === 'cleaning') $cleaning_tables++;
            else if ($st === 'disabled') $disabled_tables++;
            else $vacant_tables++;
            $active_guests += intval($t['guest_count'] ?: 0);
        }
        $tblStmt->close();
    }
    $occupancy_rate = ($total_tables > 0) ? round(($occupied_tables / $total_tables) * 100) . '%' : '0%';
    $kitchen_capacity_pct = min(100, round(($active_count_all / 15) * 100));

    // 5. PAYMENT DUE (tenant scoped)
    $payment_pending_total = 0.0;
    $pendStmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) AS tot FROM orders WHERE restaurant_id = ? AND payment_status = 'pending' AND status != 'cancelled'");
    if ($pendStmt) {
        $pendStmt->bind_param("i", $tenantId);
        $pendStmt->execute();
        $res = $pendStmt->get_result();
        if ($row = $res->fetch_assoc()) { $payment_pending_total = floatval($row['tot']); }
        $pendStmt->close();
    }

    // 6. PAYMENT BREAKDOWN (tenant scoped)
    $payment_methods = ['cash' => 0.0, 'esewa' => 0.0, 'khalti' => 0.0, 'fonepay' => 0.0, 'connectips' => 0.0, 'ime_pay' => 0.0, 'card' => 0.0, 'other' => 0.0];
    $payStmt = $conn->prepare("
        SELECT payment_method, COALESCE(SUM(total_amount), 0) AS total
        FROM orders
        WHERE restaurant_id = ? AND created_at >= ? AND created_at < ?
          AND status = 'completed' AND payment_status = 'paid'
        GROUP BY payment_method
    ");
    if ($payStmt) {
        $payStmt->bind_param("iss", $tenantId, $todayStart, $tomorrowStart);
        $payStmt->execute();
        $res = $payStmt->get_result();
        while ($pm = $res->fetch_assoc()) {
            $m = strtolower(str_replace([' ', '-'], '_', $pm['payment_method'] ?: 'cash'));
            if (isset($payment_methods[$m])) {
                $payment_methods[$m] = floatval($pm['total']);
            } else {
                $payment_methods['other'] += floatval($pm['total']);
            }
        }
        $payStmt->close();
    }

    // 7. LIVE ACTIVE ORDERS STREAM (tenant scoped)
    $live_orders = [];
    $liveStmt = $conn->prepare("
        SELECT o.id, o.table_number, o.customer_name, o.status, o.total_amount,
               o.payment_status, o.payment_method, o.created_at, o.updated_at,
               TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS elapsed_mins
        FROM orders o
        WHERE o.restaurant_id = ? AND o.status IN ('new', 'preparing', 'ready')
        ORDER BY CASE o.status WHEN 'new' THEN 1 WHEN 'preparing' THEN 2 ELSE 3 END, o.created_at DESC
        LIMIT 12
    ");
    $order_map = [];
    if ($liveStmt) {
        $liveStmt->bind_param("i", $tenantId);
        $liveStmt->execute();
        $res = $liveStmt->get_result();
        while ($ord = $res->fetch_assoc()) {
            $ord['items'] = [];
            $order_map[intval($ord['id'])] = count($live_orders);
            $live_orders[] = $ord;
        }
        $liveStmt->close();
    }

    if (!empty($order_map)) {
        $ids_in = implode(',', array_keys($order_map));
        $itemsRes = $conn->query("
            SELECT oi.*, mi.name AS item_name
            FROM order_items oi
            JOIN menu_items mi ON oi.menu_item_id = mi.id
            WHERE oi.restaurant_id = {$tenantId} AND oi.order_id IN ({$ids_in})
        ");
        if ($itemsRes) {
            while ($itm = $itemsRes->fetch_assoc()) {
                $o_id = intval($itm['order_id']);
                if (isset($order_map[$o_id])) {
                    $live_orders[$order_map[$o_id]]['items'][] = $itm;
                }
            }
        }
    }

    // 8. INVENTORY VALUATION + CRITICAL ALERTS (tenant scoped)
    $inv_val = 0.0; $low_stock_cnt = 0; $out_stock_cnt = 0; $inv_alerts = [];
    $valStmt = $conn->prepare("SELECT COALESCE(SUM(current_stock * COALESCE(average_cost, purchase_cost, 0)), 0) AS total_val FROM inventory_items WHERE restaurant_id = ? AND status = 'active'");
    if ($valStmt) {
        $valStmt->bind_param("i", $tenantId);
        $valStmt->execute();
        $res = $valStmt->get_result();
        if ($row = $res->fetch_assoc()) { $inv_val = floatval($row['total_val']); }
        $valStmt->close();
    }

    $alertsStmt = $conn->prepare("
        SELECT id, name, current_stock, minimum_stock, expiry_date, unit_id,
        CASE
            WHEN current_stock <= 0 THEN 'out_of_stock'
            WHEN current_stock <= minimum_stock THEN 'low_stock'
            WHEN expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN 'expired'
            WHEN expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'expiring'
            ELSE NULL
        END AS alert_type
        FROM inventory_items
        WHERE restaurant_id = ? AND ((current_stock <= minimum_stock) OR (expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)))
        ORDER BY current_stock ASC, name ASC
        LIMIT 8
    ");
    if ($alertsStmt) {
        $alertsStmt->bind_param("i", $tenantId);
        $alertsStmt->execute();
        $res = $alertsStmt->get_result();
        while ($al = $res->fetch_assoc()) {
            if ($al['alert_type']) {
                $inv_alerts[] = $al;
                if ($al['alert_type'] === 'low_stock') $low_stock_cnt++;
                else if ($al['alert_type'] === 'out_of_stock') $out_stock_cnt++;
            }
        }
        $alertsStmt->close();
    }

    // 9. PURCHASES & SUPPLIER SUMMARY (tenant scoped)
    $today_purchases = 0.0;
    $poStmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS total
        FROM purchase_orders
        WHERE restaurant_id = ? AND ((order_date >= ? AND order_date < ?) OR (created_at >= ? AND created_at < ? AND status != 'cancelled'))
    ");
    if ($poStmt) {
        $poStmt->bind_param("issss", $tenantId, $today, $tomorrowStart, $todayStart, $tomorrowStart);
        $poStmt->execute();
        $res = $poStmt->get_result();
        if ($row = $res->fetch_assoc()) { $today_purchases = floatval($row['total']); }
        $poStmt->close();
    }

    $pending_pos = 0; $awaiting_receiving = 0;
    $poStatsStmt = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM purchase_orders WHERE restaurant_id = ? AND status IN ('draft', 'approved', 'ordered', 'payment_pending', 'partial') GROUP BY status");
    if ($poStatsStmt) {
        $poStatsStmt->bind_param("i", $tenantId);
        $poStatsStmt->execute();
        $res = $poStatsStmt->get_result();
        while ($ps = $res->fetch_assoc()) {
            $st = $ps['status'];
            if (in_array($st, ['ordered', 'draft', 'payment_pending'], true)) $pending_pos += intval($ps['cnt']);
            if (in_array($st, ['ordered', 'partial'], true)) $awaiting_receiving += intval($ps['cnt']);
        }
        $poStatsStmt->close();
    }

    $supplier_payables = 0.0;
    $payablesStmt = $conn->prepare("SELECT COALESCE(SUM(outstanding_balance), 0) AS total FROM suppliers WHERE restaurant_id = ?");
    if ($payablesStmt) {
        $payablesStmt->bind_param("i", $tenantId);
        $payablesStmt->execute();
        $res = $payablesStmt->get_result();
        if ($row = $res->fetch_assoc()) { $supplier_payables = floatval($row['total']); }
        $payablesStmt->close();
    }

    $recent_pos = [];
    $rpoStmt = $conn->prepare("
        SELECT po.id, po.po_number, po.status, po.total_amount, po.created_at, s.company_name AS supplier_name
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.restaurant_id = ?
        ORDER BY po.id DESC LIMIT 4
    ");
    if ($rpoStmt) {
        $rpoStmt->bind_param("i", $tenantId);
        $rpoStmt->execute();
        $res = $rpoStmt->get_result();
        while ($r = $res->fetch_assoc()) { $recent_pos[] = $r; }
        $rpoStmt->close();
    }

    // 10. WASTE METRICS (tenant scoped)
    $today_waste_val = 0.0; $today_waste_qty = 0.0;
    $wasteStmt = $conn->prepare("
        SELECT COALESCE(SUM(total_cost), 0) AS val, COALESCE(SUM(quantity), 0) AS qty
        FROM inventory_waste
        WHERE restaurant_id = ? AND created_at >= ? AND created_at < ?
    ");
    if ($wasteStmt) {
        $wasteStmt->bind_param("iss", $tenantId, $todayStart, $tomorrowStart);
        $wasteStmt->execute();
        $res = $wasteStmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $today_waste_val = floatval($row['val']);
            $today_waste_qty = floatval($row['qty']);
        }
        $wasteStmt->close();
    }

    // 11. ASSETS SUMMARY (tenant scoped)
    $asset_summary = ['total' => 0, 'active_cnt' => 0, 'maint_cnt' => 0, 'exp_warr' => 0, 'total_val' => 0.0];
    $assetStmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('available', 'in_use') THEN 1 ELSE 0 END) AS active_cnt,
            SUM(CASE WHEN status IN ('maintenance', 'repair') THEN 1 ELSE 0 END) AS maint_cnt,
            SUM(CASE WHEN warranty_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS exp_warr,
            SUM(COALESCE(current_value, purchase_cost, 0)) AS total_val
        FROM assets WHERE restaurant_id = ? AND status != 'disposed'
    ");
    if ($assetStmt) {
        $assetStmt->bind_param("ssi", $today, $today, $tenantId);
        $assetStmt->execute();
        $res = $assetStmt->get_result();
        if ($row = $res->fetch_assoc()) {
            foreach ($row as $k => $v) { if ($v !== null && array_key_exists($k, $asset_summary)) $asset_summary[$k] = $v; }
        }
        $assetStmt->close();
    }

    // 12. LIVE ACTIVITY STREAM FEED (tenant scoped)
    $activity_feed = [];
    $feedStmt = $conn->prepare("
        (SELECT 'order' AS type, CONCAT('🍽 Order #', o.id, ' placed for Table ', o.table_number, ' (Rs. ', o.total_amount, ')') AS event_text, o.created_at, 'Customer' AS actor, CONCAT('order:', o.id) AS reference_id FROM orders o WHERE o.restaurant_id = ? AND o.created_at >= ?)
        UNION ALL
        (SELECT 'payment' AS type, CONCAT('💳 Payment received for Table ', o.table_number, ' (Rs. ', o.total_amount, ')'), o.updated_at, 'Staff', CONCAT('order:', o.id) FROM orders o WHERE o.restaurant_id = ? AND o.payment_status = 'paid' AND o.updated_at >= ?)
        UNION ALL
        (SELECT 'waiter' AS type, CONCAT('🔔 Table ', wc.table_number, ' requested waiter assistance'), wc.created_at, 'Customer', CONCAT('waiter:', wc.id) FROM waiter_calls wc WHERE wc.restaurant_id = ? AND wc.status = 'pending')
        UNION ALL
        (SELECT 'inventory' AS type, CONCAT('🗑 Waste logged: ', w.quantity, ' units (Rs. ', w.total_cost, ')'), w.created_at, 'Staff', CONCAT('waste:', w.id) FROM inventory_waste w WHERE w.restaurant_id = ? AND w.created_at >= ?)
        UNION ALL
        (SELECT 'asset' AS type, CONCAT('🔧 Maintenance scheduled for ', COALESCE(am.technician, 'Staff'), ' (Cost: Rs. ', am.cost, ')'), am.created_at, 'System', CONCAT('maintenance:', am.id) FROM asset_maintenance am WHERE am.restaurant_id = ? AND am.created_at >= ?)
        ORDER BY created_at DESC LIMIT 12
    ");
    if ($feedStmt) {
        $feedStmt->bind_param("isissisi", $tenantId, $todayStart, $tenantId, $todayStart, $tenantId, $tenantId, $todayStart, $tenantId, $todayStart);
        $feedStmt->execute();
        $res = $feedStmt->get_result();
        while ($act = $res->fetch_assoc()) { $activity_feed[] = $act; }
        $feedStmt->close();
    }

    // 13. STAFF METRICS (tenant scoped)
    $active_staff_count = 0;
    $staffStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM admin_users WHERE restaurant_id = ?");
    if ($staffStmt) {
        $staffStmt->bind_param("i", $tenantId);
        $staffStmt->execute();
        $res = $staffStmt->get_result();
        if ($row = $res->fetch_assoc()) { $active_staff_count = max(intval($row['cnt']), 1); }
        $staffStmt->close();
    }

    Response::json([
        'success' => true,
        'timestamp' => date('c'),
        'tenant_id' => $tenantId,
        'data' => [
            'metrics' => [
                'today_revenue' => $today_revenue,
                'yesterday_revenue' => $yesterday_revenue,
                'rev_change_pct' => $rev_change_pct,
                'this_week_revenue' => $this_week_revenue,
                'this_month_revenue' => $this_month_revenue,
                'today_total_orders' => $today_total_orders,
                'active_orders' => $active_orders_count,
                'served_orders' => $status_counts['completed'],
                'cancelled_orders' => $status_counts['cancelled'],
                'occupied_tables' => $occupied_tables,
                'vacant_tables' => $vacant_tables,
                'reserved_tables' => $reserved_tables,
                'cleaning_tables' => $cleaning_tables,
                'disabled_tables' => $disabled_tables,
                'active_guests' => $active_guests,
                'payment_pending' => $payment_pending_total,
                'avg_prep_time' => $avg_prep_mins . 'm',
                'longest_wait_mins' => $longest_wait_mins . 'm',
                'occupancy_rate' => $occupancy_rate,
                'inventory_value' => $inv_val,
                'low_stock_count' => $low_stock_cnt,
                'out_of_stock_count' => $out_stock_cnt,
                'today_purchases' => $today_purchases,
                'pending_pos' => $pending_pos,
                'awaiting_receiving' => $awaiting_receiving,
                'supplier_payables' => $supplier_payables,
                'today_waste_val' => $today_waste_val,
                'today_waste_qty' => $today_waste_qty,
                'total_assets' => intval($asset_summary['total']),
                'active_assets' => intval($asset_summary['active_cnt']),
                'in_maint_assets' => intval($asset_summary['maint_cnt']),
                'expiring_warranties' => intval($asset_summary['exp_warr']),
                'asset_book_value' => floatval($asset_summary['total_val']),
                'active_staff' => $active_staff_count
            ],
            'kitchen_queue' => [
                'new_waiting' => $status_counts['new'],
                'preparing' => $status_counts['preparing'],
                'ready_to_serve' => $status_counts['ready'],
                'avg_cook_time' => $avg_prep_mins . 'm',
                'longest_wait' => $longest_wait_mins . 'm',
                'capacity_pct' => $kitchen_capacity_pct . '%'
            ],
            'payment_breakdown' => $payment_methods,
            'live_orders' => $live_orders,
            'inventory_alerts' => $inv_alerts,
            'recent_pos' => $recent_pos,
            'activity_feed' => $activity_feed
        ]
    ]);

} catch (Throwable $e) {
    Response::error('Live order service is temporarily unavailable.', 500, 'SERVICE_UNAVAILABLE');
}
