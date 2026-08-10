<?php
require_once __DIR__ . '/../config.php';
$conn = getDBConnection();
if ($conn) {
    $res = $conn->query("DELETE FROM payment_transactions WHERE transaction_id = '0'");
    echo "SUCCESS: Cleaned " . $conn->affected_rows . " invalid zero transaction_id records.\n";
} else {
    echo "DB Connection failed\n";
}
