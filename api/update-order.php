<?php
// api/update-order.php - Order Status & Payment Method Update (Authenticated & Hardened)
require_once __DIR__ . '/../config.php';

// Require staff (admin OR kitchen) authentication with tenant context
$tenantId = (int)AuthorizationService::requireStaffApi();

// CSRF Verification for POST requests
CSRF::requireValidToken();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// Parse JSON input payload
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true) ?: [];

$order_id = intval($input['order_id'] ?? $_POST['order_id'] ?? 0);
$status = Security::sanitize($input['status'] ?? $_POST['status'] ?? '');
$reason = Security::sanitize($input['reason'] ?? $_POST['reason'] ?? '');
$payment_method = Security::sanitize($input['payment_method'] ?? $_POST['payment_method'] ?? '');

if ($order_id <= 0) {
    Response::error('Invalid order ID', 400);
}

require_once __DIR__ . '/../helpers/OrderService.php';

// Determine user role for authorization checks
$userRole = 'admin';
if (Auth::isKitchenLoggedIn() && !Auth::isAdminLoggedIn()) {
    $userRole = 'kitchen';
}

// Role-based permission guard: KDS/kitchen is a separate authentication boundary
// (its state machine already blocks refunds/payment changes), but every admin/staff
// session must hold the orders.update permission to transition order status.
if ($userRole !== 'kitchen' && !AuthorizationService::hasPermission('orders.update')) {
    Response::error('Access Denied: Your role does not have permission to update orders.', 403);
}

// Assert order ownership (IDOR protection) before any write
TenantContext::assertOwnership($conn, 'orders', $order_id);

// Handle payment method update (Fixes RMS-012 & RMS-030)
if (!empty($payment_method)) {
    if ($userRole === 'kitchen') {
        Response::error('Kitchen staff is not authorized to modify payment settings.', 403);
    }

    $allowed_payment_methods = ['cash', 'card', 'esewa', 'khalti', 'fonepay', 'connectips', 'imepay'];
    if (!in_array(strtolower($payment_method), $allowed_payment_methods, true)) {
        Response::error('Invalid payment method specified.', 400);
    }

    $stmt = $conn->prepare("UPDATE orders SET payment_method = ? WHERE id = ? AND restaurant_id = ?");
    $stmt->bind_param("sii", $payment_method, $order_id, $tenantId);
    $stmt->execute();
    $stmt->close();

    if ($payment_method === 'cash') {
        $tbl_stmt = $conn->prepare("SELECT table_number FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
        $tbl_stmt->bind_param("ii", $order_id, $tenantId);
        $tbl_stmt->execute();
        $tbl_res = $tbl_stmt->get_result();

        if ($tbl_row = $tbl_res->fetch_assoc()) {
            $t_num = $tbl_row['table_number'];
            $w_stmt = $conn->prepare("INSERT INTO waiter_calls (restaurant_id, table_number) VALUES (?, ?)");
            $w_stmt->bind_param("is", $tenantId, $t_num);
            $w_stmt->execute();
            $w_stmt->close();
        }
        $tbl_stmt->close();
    }

    Response::success('Payment method updated successfully');
}

if (empty($status)) {
    Response::error('Order status is required', 400);
}

// Route order status transition through centralized OrderService (Fixes RMS-008, RMS-009, RMS-028)
$result = OrderService::transitionStatus($conn, $order_id, $status, $userRole, $reason);

if ($result['success']) {
    Inventory::generateAlerts();
    Response::success($result['message']);
} else {
    Response::error($result['message'], 400);
}
