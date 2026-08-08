<?php
// API Endpoint: Live Menu Status (for real-time stock sync on customer menu)
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'items' => []]);
    exit;
}

// Tenant-scoped: returns nothing when no dining/staff session context exists.
$tenantId = (int)TenantContext::getTenantId();
if ($tenantId <= 0) {
    echo json_encode(['success' => false, 'items' => []]);
    exit;
}

$stmt = $conn->prepare("SELECT id, status FROM menu_items WHERE restaurant_id = ?");
$stmt->bind_param("i", $tenantId);
$stmt->execute();
$res = $stmt->get_result();
$items = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => intval($row['id']),
            'status' => $row['status']
        ];
    }
}
$stmt->close();

$conn->close();
echo json_encode(['success' => true, 'items' => $items]);
