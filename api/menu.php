<?php
// API - Get Menu Items (tenant-scoped; requires an active customer/dining session)
header('Content-Type: application/json');

require_once '../config.php';

$conn = getDBConnection();

$tenantId = (int)TenantContext::getTenantId();
if ($tenantId <= 0 || !$conn) {
    echo json_encode([]);
    exit;
}

$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

$public_cols = "id, name, description, price, image, category_id, status, is_popular, preparation_time, dietary_type, addons";

if ($category_id > 0) {
    $stmt = $conn->prepare("SELECT $public_cols FROM menu_items WHERE restaurant_id = ? AND category_id = ? AND status = 'active' ORDER BY name");
    $stmt->bind_param("ii", $tenantId, $category_id);
} else {
    $stmt = $conn->prepare("SELECT $public_cols FROM menu_items WHERE restaurant_id = ? AND status = 'active' ORDER BY category_id, name");
    $stmt->bind_param("i", $tenantId);
}

$stmt->execute();
$result = $stmt->get_result();

$menu_items = [];
while ($row = $result->fetch_assoc()) {
    $menu_items[] = $row;
}

echo json_encode($menu_items);

$stmt->close();
$conn->close();
