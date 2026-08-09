<?php
require_once 'config.php';
$conn = getDBConnection();
if ($conn) {
    $res = $conn->query('SHOW COLUMNS FROM orders');
    while ($row = $res->fetch_assoc()) {
        echo $row['Field'] . ' - ' . $row['Type'] . PHP_EOL;
    }
}