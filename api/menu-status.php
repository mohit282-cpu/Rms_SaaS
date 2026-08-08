<?php
// API Endpoint: Live Menu Status (for real-time stock sync on customer menu)
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'items' => []]);
    exit;
}

$res = $conn->query("SELECT id, status FROM menu_items");
$items = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => intval($row['id']),
            'status' => $row['status']
        ];
    }
}

$conn->close();
echo json_encode(['success' => true, 'items' => $items]);
?>
