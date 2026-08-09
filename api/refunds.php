<?php
// api/refunds.php - Order Voids & Refunds API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// GET Request: Fetch refund register & void logs
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    RBAC::requirePermission('view_revenue');

    $rStmt = $conn->prepare("SELECT * FROM order_refunds WHERE restaurant_id = ? ORDER BY id DESC LIMIT 100");
    $rStmt->bind_param("i", $tenantId);
    $rStmt->execute();
    $refunds = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rStmt->close();

    $vStmt = $conn->prepare("SELECT * FROM order_voids WHERE restaurant_id = ? ORDER BY id DESC LIMIT 100");
    $vStmt->bind_param("i", $tenantId);
    $vStmt->execute();
    $voids = $vStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $vStmt->close();

    Response::json(['success' => true, 'refunds' => $refunds, 'voids' => $voids]);
}

// POST Request: Process Void or Refund
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();

    $action = Security::sanitize($_POST['action'] ?? '');
    $user = $_SESSION['admin_username'] ?? 'staff';

    if ($action === 'void_item') {
        RBAC::requirePermission('void_orders');
        $orderId = intval($_POST['order_id'] ?? 0);
        $orderItemId = intval($_POST['order_item_id'] ?? 0);
        $reason = Security::sanitize($_POST['reason'] ?? '');

        $res = RefundService::voidItem($conn, $orderId, $orderItemId, $reason, $user, $tenantId);
        if ($res['success']) Response::success($res['message'], $res);
        else Response::error($res['message'], 400);
    }

    elseif ($action === 'process_refund') {
        RBAC::requirePermission('refund_payment');
        $orderId = intval($_POST['order_id'] ?? 0);
        $refundType = in_array($_POST['refund_type'] ?? '', ['full', 'partial'], true) ? $_POST['refund_type'] : 'full';
        $amount = floatval($_POST['amount'] ?? 0.00);
        $method = Security::sanitize($_POST['payment_method'] ?? 'cash');
        $reason = Security::sanitize($_POST['reason'] ?? '');

        $res = RefundService::processRefund($conn, $orderId, $refundType, $amount, $method, $reason, $user, $tenantId);
        if ($res['success']) Response::success($res['message'], $res);
        else Response::error($res['message'], 400);
    }

    else {
        Response::error('Invalid action specified', 400);
    }
}
