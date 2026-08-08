<?php
// api/asset-stream.php - Asset Dashboard Realtime Stream & Analytics API
require_once '../config.php';
$tenantId = (int)AuthorizationService::requireStaffApi();

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

    // 1. Total Assets
    $r = $conn->query("SELECT COUNT(*) as cnt FROM assets WHERE restaurant_id = $tenantId AND status != 'disposed'");
    $data['total_assets'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // 2. Total Purchase Valuation
    $r = $conn->query("SELECT COALESCE(SUM(purchase_cost),0) as val FROM assets WHERE restaurant_id = $tenantId AND status != 'disposed'");
    $data['total_cost'] = $r ? (float)$r->fetch_assoc()['val'] : 0;

    // 3. Net Book Value
    $r = $conn->query("SELECT COALESCE(SUM(current_value),0) as val FROM assets WHERE restaurant_id = $tenantId AND status != 'disposed'");
    $data['net_book_value'] = $r ? (float)$r->fetch_assoc()['val'] : 0;

    // 4. Accumulated Depreciation
    $data['accumulated_depreciation'] = max(0, $data['total_cost'] - $data['net_book_value']);

    // 5. In Maintenance / Repair
    $r = $conn->query("SELECT COUNT(*) as cnt FROM assets WHERE restaurant_id = $tenantId AND status IN ('maintenance','repair')");
    $data['in_maintenance'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // 6. Active / In Use
    $r = $conn->query("SELECT COUNT(*) as cnt FROM assets WHERE restaurant_id = $tenantId AND status IN ('available','in_use')");
    $data['active_assets'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // 7. Expiring Warranties (Within 30 Days)
    $r = $conn->query("SELECT COUNT(*) as cnt FROM assets WHERE restaurant_id = $tenantId AND status != 'disposed' AND warranty_expiry IS NOT NULL AND warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $data['expiring_warranties'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // 8. Maintenance Expense (This Month)
    $r = $conn->query("SELECT COALESCE(SUM(m.cost),0) as total FROM asset_maintenance m JOIN assets a ON m.asset_id = a.id WHERE a.restaurant_id = $tenantId AND DATE_FORMAT(m.service_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
    $data['month_maintenance_cost'] = $r ? (float)$r->fetch_assoc()['total'] : 0;

    // ==========================================
    // CHARTS & BREAKDOWNS
    // ==========================================

    // Status Breakdown
    $status_counts = [];
    $r = $conn->query("SELECT status, COUNT(*) as cnt FROM assets WHERE restaurant_id = $tenantId GROUP BY status");
    if ($r) { while ($row = $r->fetch_assoc()) $status_counts[$row['status']] = (int)$row['cnt']; }
    $data['status_breakdown'] = $status_counts;

    // Category Valuation Breakdown
    $cat_breakdown = [];
    $r = $conn->query("SELECT ac.name, COUNT(a.id) as cnt, COALESCE(SUM(a.current_value),0) as val
        FROM asset_categories ac LEFT JOIN assets a ON ac.id=a.category_id AND a.restaurant_id = $tenantId AND a.status != 'disposed'
        WHERE ac.restaurant_id = $tenantId
        GROUP BY ac.id ORDER BY val DESC");
    if ($r) { while ($row = $r->fetch_assoc()) $cat_breakdown[] = $row; }
    $data['category_breakdown'] = $cat_breakdown;

    // Upcoming Maintenance
    $upcoming_maint = [];
    $r = $conn->query("SELECT m.*, a.name as asset_name, a.asset_code FROM asset_maintenance m JOIN assets a ON m.asset_id=a.id WHERE a.restaurant_id = $tenantId AND m.status IN ('scheduled','in_progress') ORDER BY m.service_date ASC LIMIT 10");
    if ($r) { while ($row = $r->fetch_assoc()) $upcoming_maint[] = $row; }
    $data['upcoming_maintenance'] = $upcoming_maint;

    // Recent Asset Transfers
    $recent_transfers = [];
    $r = $conn->query("SELECT t.*, a.name as asset_name, a.asset_code FROM asset_transfers t JOIN assets a ON t.asset_id=a.id WHERE a.restaurant_id = $tenantId ORDER BY t.created_at DESC LIMIT 5");
    if ($r) { while ($row = $r->fetch_assoc()) $recent_transfers[] = $row; }
    $data['recent_transfers'] = $recent_transfers;

    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
