<?php
// API Endpoint to check live status of a single order
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$conn = getDBConnection();

if ($conn === null) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$session_table = strval($_SESSION['customer_table_id'] ?? '');

if ($order_id === 0) {
    Response::error('Invalid order ID', 400);
}

$tenantId = (int)TenantContext::getTenantId();
if ($tenantId <= 0) {
    Response::error('Forbidden. No tenant context.', 403);
}

// Require staff auth or session table match (Fixes RMS-017 & RMS-018)
if (!Auth::isAdminLoggedIn() && !Auth::isKitchenLoggedIn() && empty($session_table)) {
    Response::error('Unauthorized access. Active table session required.', 403);
}

if (!Auth::isAdminLoggedIn() && !Auth::isKitchenLoggedIn()) {
    $stmt = $conn->prepare("SELECT id, table_number, status, notes, total_amount, created_at FROM orders WHERE id = ? AND restaurant_id = ? AND table_number = ? LIMIT 1");
    $stmt->bind_param("iis", $order_id, $tenantId, $session_table);
} else {
    $stmt = $conn->prepare("SELECT id, table_number, status, notes, total_amount, created_at FROM orders WHERE id = ? AND restaurant_id = ? LIMIT 1");
    $stmt->bind_param("ii", $order_id, $tenantId);
}

$stmt->execute();
$res = $stmt->get_result();

if ($res && $row = $res->fetch_assoc()) {
    Response::json([
        'success' => true,
        'order' => $row
    ]);
} else {
    Response::error('Order not found or unauthorized', 404);
}

$stmt->close();
