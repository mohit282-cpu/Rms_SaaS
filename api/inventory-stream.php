<?php
// api/inventory-stream.php - Inventory Dashboard KPI & Charts Stream
require_once '../config.php';
requireAdminLogin();

// Release session lock so multiple browser tabs can poll concurrently.
session_write_close();

header('Content-Type: application/json; charset=UTF-8');
$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

try {
    $data = [];

    // Total inventory value
    $r = $conn->query("SELECT COALESCE(SUM(current_stock * average_cost), 0) as total_value FROM inventory_items WHERE status='active'");
    $data['total_value'] = $r ? (float)$r->fetch_assoc()['total_value'] : 0;

    // Total items count
    $r = $conn->query("SELECT COUNT(*) as cnt FROM inventory_items WHERE status='active'");
    $data['total_items'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Low stock count
    $r = $conn->query("SELECT COUNT(*) as cnt FROM inventory_items WHERE status='active' AND current_stock > 0 AND current_stock <= minimum_stock");
    $data['low_stock'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Out of stock count
    $r = $conn->query("SELECT COUNT(*) as cnt FROM inventory_items WHERE status='active' AND current_stock <= 0");
    $data['out_of_stock'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Near expiry (within 7 days)
    $r = $conn->query("SELECT COUNT(*) as cnt FROM inventory_items WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
    $data['near_expiry'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Expired items
    $r = $conn->query("SELECT COUNT(*) as cnt FROM inventory_items WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date < CURDATE()");
    $data['expired'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Pending POs
    $r = $conn->query("SELECT COUNT(*) as cnt FROM purchase_orders WHERE status IN ('draft','approved','ordered','partial','payment_pending')");
    $data['pending_pos'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Today's consumption value
    $r = $conn->query("SELECT COALESCE(SUM(ABS(quantity) * unit_cost), 0) as val FROM inventory_transactions WHERE type='consumption' AND DATE(created_at) = CURDATE()");
    $data['today_consumption'] = $r ? (float)$r->fetch_assoc()['val'] : 0;

    // Today's purchases value
    $r = $conn->query("SELECT COALESCE(SUM(received_qty * unit_cost), 0) as val FROM goods_receipts WHERE DATE(received_at) = CURDATE()");
    $data['today_purchases'] = $r ? (float)$r->fetch_assoc()['val'] : 0;

    // Today's waste value
    $r = $conn->query("SELECT COALESCE(SUM(total_cost), 0) as val FROM inventory_waste WHERE DATE(created_at) = CURDATE()");
    $data['today_waste'] = $r ? (float)$r->fetch_assoc()['val'] : 0;

    // Monthly consumption value (last 30 days)
    $r = $conn->query("SELECT COALESCE(SUM(ABS(quantity) * unit_cost), 0) as val FROM inventory_transactions WHERE type='consumption' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $monthly_consumption = $r ? (float)$r->fetch_assoc()['val'] : 0;
    $data['monthly_consumption'] = $monthly_consumption;

    // Inventory Turnover Ratio (Monthly Consumption / Average Inventory Value)
    $avg_inv_val = max(1.0, $data['total_value']);
    $data['inventory_turnover'] = round($monthly_consumption / $avg_inv_val, 2);

    // Active suppliers count
    $r = $conn->query("SELECT COUNT(*) as cnt FROM suppliers WHERE status='active'");
    $data['active_suppliers'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Total categories count
    $r = $conn->query("SELECT COUNT(*) as cnt FROM inventory_categories WHERE status='active'");
    $data['total_categories'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Unread alerts
    $r = $conn->query("SELECT COUNT(*) as cnt FROM inventory_alerts WHERE is_read=0");
    $data['unread_alerts'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Low stock items list (top 10)
    $lowStockItems = [];
    $r = $conn->query("SELECT i.id, i.name, i.current_stock, i.minimum_stock, COALESCE(u.abbreviation,'pcs') as unit
        FROM inventory_items i LEFT JOIN inventory_units u ON i.unit_id=u.id
        WHERE i.status='active' AND i.current_stock <= i.minimum_stock ORDER BY (i.current_stock/GREATEST(i.minimum_stock,0.001)) ASC LIMIT 10");
    if ($r) { while ($row = $r->fetch_assoc()) $lowStockItems[] = $row; }
    $data['low_stock_items'] = $lowStockItems;

    // Recent transactions (last 10)
    $recent = [];
    $r = $conn->query("SELECT t.id, t.type, t.direction, t.quantity, t.created_at, i.name as item_name, COALESCE(u.abbreviation,'pcs') as unit
        FROM inventory_transactions t
        JOIN inventory_items i ON t.inventory_item_id=i.id
        LEFT JOIN inventory_units u ON i.unit_id=u.id
        ORDER BY t.created_at DESC LIMIT 10");
    if ($r) { while ($row = $r->fetch_assoc()) $recent[] = $row; }
    $data['recent_transactions'] = $recent;

    // Category breakdown
    $categories = [];
    $r = $conn->query("SELECT c.name, c.icon, COUNT(i.id) as item_count, COALESCE(SUM(i.current_stock * i.average_cost),0) as value
        FROM inventory_categories c LEFT JOIN inventory_items i ON c.id=i.category_id AND i.status='active'
        WHERE c.status='active' GROUP BY c.id ORDER BY c.display_order");
    if ($r) { while ($row = $r->fetch_assoc()) $categories[] = $row; }
    $data['categories'] = $categories;

    // ==========================================
    // CHART DATASETS
    // ==========================================

    // 1. Stock Movement (Last 7 Days)
    $sm_labels = [];
    $sm_in = [];
    $sm_out = [];
    for ($i = 6; $i >= 0; $i--) {
        $dt = date('Y-m-d', strtotime("-$i days"));
        $sm_labels[] = date('D d', strtotime($dt));
        $r_in = $conn->query("SELECT COALESCE(SUM(quantity),0) as total FROM inventory_transactions WHERE direction='in' AND DATE(created_at)='$dt'");
        $sm_in[] = $r_in ? (float)$r_in->fetch_assoc()['total'] : 0;
        $r_out = $conn->query("SELECT COALESCE(SUM(quantity),0) as total FROM inventory_transactions WHERE direction='out' AND DATE(created_at)='$dt'");
        $sm_out[] = $r_out ? (float)$r_out->fetch_assoc()['total'] : 0;
    }
    $data['charts']['stock_movement'] = [
        'labels' => $sm_labels,
        'in' => $sm_in,
        'out' => $sm_out
    ];

    // 2. Monthly Purchases (Last 6 Months)
    $mp_labels = [];
    $mp_totals = [];
    for ($i = 5; $i >= 0; $i--) {
        $m_str = date('Y-m', strtotime("-$i months"));
        $mp_labels[] = date('M Y', strtotime("-$i months"));
        $r_mp = $conn->query("SELECT COALESCE(SUM(total_amount),0) as total FROM purchase_orders WHERE DATE_FORMAT(created_at, '%Y-%m')='$m_str' AND status IN ('ordered','partial','received','payment_pending','completed')");
        $mp_totals[] = $r_mp ? (float)$r_mp->fetch_assoc()['total'] : 0;
    }
    $data['charts']['monthly_purchases'] = [
        'labels' => $mp_labels,
        'totals' => $mp_totals
    ];

    // 3. Top Consumed Ingredients
    $tc_labels = [];
    $tc_qty = [];
    $r_tc = $conn->query("SELECT i.name, SUM(t.quantity) as total_qty FROM inventory_transactions t JOIN inventory_items i ON t.inventory_item_id=i.id WHERE t.type='consumption' GROUP BY t.inventory_item_id ORDER BY total_qty DESC LIMIT 5");
    if ($r_tc) {
        while ($row = $r_tc->fetch_assoc()) {
            $tc_labels[] = $row['name'];
            $tc_qty[] = (float)$row['total_qty'];
        }
    }
    $data['charts']['top_consumed'] = [
        'labels' => $tc_labels,
        'quantities' => $tc_qty
    ];

    // 4. Fast Moving vs Slow Moving
    $fast_moving = [];
    $r_fm = $conn->query("SELECT i.name, COUNT(t.id) as move_count, SUM(t.quantity) as total_qty FROM inventory_items i JOIN inventory_transactions t ON i.id=t.inventory_item_id WHERE i.status='active' GROUP BY i.id ORDER BY move_count DESC LIMIT 5");
    if ($r_fm) { while ($row = $r_fm->fetch_assoc()) $fast_moving[] = $row; }
    $data['fast_moving'] = $fast_moving;

    $slow_moving = [];
    $r_sm = $conn->query("SELECT i.name, i.current_stock, COALESCE(u.abbreviation,'pcs') as unit FROM inventory_items i LEFT JOIN inventory_units u ON i.unit_id=u.id WHERE i.status='active' AND i.id NOT IN (SELECT DISTINCT inventory_item_id FROM inventory_transactions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) LIMIT 5");
    if ($r_sm) { while ($row = $r_sm->fetch_assoc()) $slow_moving[] = $row; }
    $data['slow_moving'] = $slow_moving;

    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
