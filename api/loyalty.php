<?php
// api/loyalty.php - Multi-Tenant Customer Loyalty API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// GET Request: Fetch loyalty ledger & top customer balances
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    RBAC::requirePermission('manage_customers');

    $lStmt = $conn->prepare("
        SELECT lt.*, c.name as customer_name, c.phone as customer_phone 
        FROM loyalty_transactions lt
        JOIN customers c ON lt.customer_id = c.id
        WHERE lt.restaurant_id = ? 
        ORDER BY lt.id DESC LIMIT 100
    ");
    $lStmt->bind_param("i", $tenantId);
    $lStmt->execute();
    $transactions = $lStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lStmt->close();

    Response::json(['success' => true, 'transactions' => $transactions]);
}

// POST Request: Manual Point Adjustments & Point Redemptions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RBAC::requirePermission('manage_customers');
    CSRF::requireValidToken();

    $action = Security::sanitize($_POST['action'] ?? '');

    if ($action === 'redeem') {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $points = intval($_POST['points'] ?? 0);
        $orderId = intval($_POST['order_id'] ?? 0);

        $res = LoyaltyService::redeemPoints($conn, $customerId, $points, $orderId, $tenantId);
        if ($res['success']) Response::success('Loyalty points redeemed successfully', $res);
        else Response::error($res['message'], 400);
    }

    else {
        Response::error('Invalid action specified', 400);
    }
}
