<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/TenantContext.php';
require_once __DIR__ . '/../helpers/Inventory.php';

$conn = getDBConnection();
if (!$conn) {
    echo "DB Connection failed\n";
    exit(1);
}

$res = Inventory::recordTransaction(1, 'adjustment', 5.0, 'in', 10.0, 15.0, 100.0, 'test', 0, 'Verification test', 1);
echo "Result: ";
var_dump($res);
echo "DB Error: " . $conn->error . "\n";
