<?php
// API - Get Menu Items
header('Content-Type: application/json');

require_once '../config.php';

$conn = getDBConnection();

$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

if ($category_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM menu_items WHERE category_id = ? AND status = 'active' ORDER BY name");
    $stmt->bind_param("i", $category_id);
} else {
    $stmt = $conn->prepare("SELECT * FROM menu_items WHERE status = 'active' ORDER BY category_id, name");
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
?>
