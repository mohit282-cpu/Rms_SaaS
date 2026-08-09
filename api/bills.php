<?php
// api/bills.php - Bill Splitting, Merging & Table Transfer API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();

    $action = Security::sanitize($_POST['action'] ?? '');
    $user = $_SESSION['admin_username'] ?? 'staff';

    if ($action === 'split_equal') {
        RBAC::requirePermission('process_payment');
        $orderId = intval($_POST['order_id'] ?? 0);
        $splits = intval($_POST['splits'] ?? 2);

        $res = BillService::splitEqual($conn, $orderId, $splits, $tenantId);
        if ($res['success']) Response::success('Bill split equally', $res);
        else Response::error($res['message'], 400);
    }

    elseif ($action === 'merge_orders') {
        RBAC::requirePermission('edit_orders');
        $sourceId = intval($_POST['source_order_id'] ?? 0);
        $targetId = intval($_POST['target_order_id'] ?? 0);

        $res = BillService::mergeOrders($conn, $sourceId, $targetId, $user, $tenantId);
        if ($res['success']) Response::success($res['message'], $res);
        else Response::error($res['message'], 400);
    }

    elseif ($action === 'transfer_table') {
        RBAC::requirePermission('edit_orders');
        $orderId = intval($_POST['order_id'] ?? 0);
        $newTable = Security::sanitize($_POST['new_table'] ?? '');

        if ($orderId <= 0 || empty($newTable)) Response::error('Order ID and Target Table are required', 400);

        $res = BillService::transferTable($conn, $orderId, $newTable, $user, $tenantId);
        if ($res['success']) Response::success($res['message'], $res);
        else Response::error($res['message'], 400);
    }

    else {
        Response::error('Invalid action specified', 400);
    }
}
