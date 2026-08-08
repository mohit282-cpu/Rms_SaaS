<?php
// api/toggle-stock.php - Menu Item Stock Availability Endpoint (Authenticated)
require_once __DIR__ . '/../config.php';

// Strict Role Guard: Only Staff / Admin can change inventory stock status
if (!Auth::isKitchenLoggedIn() && !Auth::isAdminLoggedIn()) {
    Response::error('Unauthorized access. Kitchen staff or Admin authentication required.', 401);
}

// CSRF Protection
CSRF::requireValidToken();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// Parse Payload
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true) ?? [];

$id = intval($input['id'] ?? $_POST['id'] ?? 0);
$status = Security::sanitize($input['status'] ?? $_POST['status'] ?? '');

if ($id > 0 && in_array($status, ['active', 'sold_out', 'inactive'])) {
    $stmt = $conn->prepare("UPDATE menu_items SET status = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $status, $id);
        if ($stmt->execute()) {
            $stmt->close();
            Response::success($status === 'active' ? 'Item marked In Stock' : 'Item marked Out of Stock', [
                'id' => $id,
                'status' => $status
            ]);
        } else {
            $stmt->close();
            Response::error('Database update failed', 500);
        }
    } else {
        Response::error('Query preparation failed', 500);
    }
} else {
    Response::error('Invalid item ID or status parameter', 400);
}
