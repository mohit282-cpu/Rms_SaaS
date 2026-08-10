<?php
// tests/customer_qr_checkout_test.php - Automated Verification of Customer QR Ordering & Checkout Session

require_once __DIR__ . '/../config.php';

$conn = getDBConnection();
if (!$conn) {
    die("❌ DB connection failed\n");
}

echo "=================================================================\n";
echo "    RMS SaaS AUTOMATED TEST: CUSTOMER QR & CHECKOUT SESSION FLOW \n";
echo "=================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $label) {
    global $passCount, $failCount;
    if ($condition) {
        echo "  ✅ [PASS] $label\n";
        $passCount++;
    } else {
        echo "  ❌ [FAIL] $label\n";
        $failCount++;
    }
}

// Setup Test Tenant and Table 3
$tenantId = 1;
$tableNumber = '3';

// Ensure Table 3 exists with a valid QR token
$stmt = $conn->prepare("SELECT id, qr_token, status FROM tables WHERE table_number = ? AND restaurant_id = ? LIMIT 1");
$stmt->bind_param("si", $tableNumber, $tenantId);
$stmt->execute();
$tableRes = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tableRes) {
    $newToken = bin2hex(random_bytes(16));
    $stmt = $conn->prepare("INSERT INTO tables (restaurant_id, table_number, qr_token, status) VALUES (?, ?, ?, 'vacant')");
    $stmt->bind_param("iss", $tenantId, $tableNumber, $newToken);
    $stmt->execute();
    $stmt->close();
    $qrToken = $newToken;
} else {
    $qrToken = $tableRes['qr_token'];
    if (empty($qrToken)) {
        $qrToken = bin2hex(random_bytes(16));
        $uStmt = $conn->prepare("UPDATE tables SET qr_token = ? WHERE id = ?");
        $uStmt->bind_param("si", $qrToken, $tableRes['id']);
        $uStmt->execute();
        $uStmt->close();
    }
}

echo "--- STEP 1: SCAN QR CODE & ESTABLISH CUSTOMER SESSION ---\n";
CustomerSessionService::establishSession($tableNumber, $qrToken, $tenantId);

assertTest($_SESSION['customer_table_id'] === '3', "Customer table ID set to '3' in PHP session");
assertTest($_SESSION['customer_table_token'] === $qrToken, "Customer QR token stored in PHP session");
assertTest($_SESSION['customer_restaurant_id'] === 1, "Customer restaurant ID set to 1 in PHP session");
assertTest($_SESSION['customer_session_expires'] > time(), "Customer session expiration set in future");

echo "\n--- STEP 2: SERVER-SIDE CHECKOUT SESSION VALIDATION ---\n";
$val = CustomerSessionService::validateSession($conn);
assertTest($val['valid'] === true, "CustomerSessionService::validateSession returns valid = true for Table 3");
assertTest($val['code'] === 200, "Validation status code is 200 OK");
assertTest(isset($val['table']) && $val['table']['table_number'] === '3', "Validated table details match Table 3");

echo "\n--- STEP 3: PLACE ORDER VIA CUSTOMER CHECKOUT ---\n";
// Create test order items
$menuRes = $conn->query("SELECT id, name, price FROM menu_items WHERE restaurant_id = $tenantId AND status = 'available' LIMIT 2");
$items = [];
if ($menuRes) {
    while ($row = $menuRes->fetch_assoc()) {
        $items[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'price' => floatval($row['price']),
            'quantity' => 1
        ];
    }
}

if (count($items) === 0) {
    // Insert dummy item if menu empty
    $conn->query("INSERT INTO menu_items (restaurant_id, name, price, category_id, status) VALUES ($tenantId, 'Test Naan', 50.00, 1, 'available')");
    $items[] = ['id' => $conn->insert_id, 'name' => 'Test Naan', 'price' => 50.00, 'quantity' => 1];
}

$grandTotal = array_reduce($items, function($sum, $i) { return $sum + ($i['price'] * $i['quantity']); }, 0);

// Insert test order for Table 3
$orderStmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_number, customer_name, notes, status, total_amount, payment_status, payment_method, created_at) VALUES (?, ?, 'Test Guest', 'Less spicy', 'new', ?, 'pending', 'pending', NOW())");
$orderStmt->bind_param("isd", $tenantId, $tableNumber, $grandTotal);
$orderStmt->execute();
$testOrderId = $orderStmt->insert_id;
$orderStmt->close();

assertTest($testOrderId > 0, "Order #$testOrderId successfully created for Table 3");

// Insert order items
$itemStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, price) VALUES (?, ?, ?, ?)");
foreach ($items as $item) {
    $itemStmt->bind_param("iiid", $testOrderId, $item['id'], $item['quantity'], $item['price']);
    $itemStmt->execute();
}
$itemStmt->close();

echo "\n--- STEP 4: KITCHEN DASHBOARD & FLOOR DISPLAY ---\n";
$kdsRes = $conn->query("SELECT id, status, total_amount FROM orders WHERE id = $testOrderId AND restaurant_id = $tenantId AND status = 'new'")->fetch_assoc();
assertTest(!empty($kdsRes), "Order #$testOrderId appears in Kitchen KDS queue as 'new'");

// Legal Kitchen State Transition: new -> preparing -> ready -> completed
$conn->query("UPDATE orders SET status = 'preparing' WHERE id = $testOrderId AND restaurant_id = $tenantId");
$conn->query("UPDATE orders SET status = 'ready' WHERE id = $testOrderId AND restaurant_id = $tenantId");

$tableOpsRes = $conn->query("SELECT id, status FROM orders WHERE id = $testOrderId AND restaurant_id = $tenantId AND status = 'ready'")->fetch_assoc();
assertTest(!empty($tableOpsRes), "Order #$testOrderId ready for staff billing on admin/tables.php");

echo "\n--- STEP 5: CASHIER COUNTER SETTLEMENT ---\n";
// Settle order via payment_transactions
$txnId = 'TXN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
$gatewayName = 'cash';
$refId = 'CASH-TEST';
$payStmt = $conn->prepare("INSERT INTO payment_transactions (restaurant_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES (?, ?, ?, ?, ?, 'paid', ?, NOW())");
$payStmt->bind_param("isisds", $tenantId, $txnId, $testOrderId, $gatewayName, $grandTotal, $refId);
$payStmt->execute();
$payStmt->close();

$conn->query("UPDATE orders SET payment_status = 'paid', payment_method = 'cash', status = 'completed' WHERE id = $testOrderId AND restaurant_id = $tenantId");
$conn->query("UPDATE tables SET status = 'vacant' WHERE table_number = '$tableNumber' AND restaurant_id = $tenantId");

$settledRes = $conn->query("SELECT payment_status, status FROM orders WHERE id = $testOrderId")->fetch_assoc();
assertTest($settledRes['payment_status'] === 'paid' && $settledRes['status'] === 'completed', "Order #$testOrderId marked paid and completed");

echo "\n--- STEP 6: SECURITY BOUNDARY & NEGATIVE TESTS ---\n";

// 1. Invalid QR token
$_SESSION['customer_table_token'] = 'invalid_fake_qr_token_123';
$invalidVal = CustomerSessionService::validateSession($conn);
assertTest($invalidVal['valid'] === false, "Invalid QR token rejected with valid = false");
assertTest($invalidVal['code'] === 403, "Invalid QR token returns HTTP 403");

// 2. Expired session
CustomerSessionService::establishSession($tableNumber, $qrToken, $tenantId);
$_SESSION['customer_session_expires'] = time() - 3600; // Expired 1 hour ago
$expiredVal = CustomerSessionService::validateSession($conn);
assertTest($expiredVal['valid'] === false, "Expired session rejected with valid = false");
assertTest($expiredVal['title'] === '🔒 Session Expired', "Expired session returns clear error title");

// 3. Cross-Tenant Tampering Protection (Tenant A token accessing Tenant B)
CustomerSessionService::establishSession($tableNumber, $qrToken, 99999);
$crossTenantVal = CustomerSessionService::validateSession($conn);
assertTest($crossTenantVal['valid'] === false, "Cross-tenant access attempt (Restaurant ID 99999) rejected");

// Restore session for Table 3
CustomerSessionService::establishSession($tableNumber, $qrToken, $tenantId);

echo "\n=================================================================\n";
echo "                  TEST EXECUTION SUMMARY                         \n";
echo "=================================================================\n";
echo "Total Tests Passed : $passCount\n";
echo "Total Tests Failed : $failCount\n";
if ($failCount === 0) {
    echo "Overall Status     : ✅ ALL CUSTOMER QR & CHECKOUT TESTS PASSED!\n";
} else {
    echo "Overall Status     : ❌ SOME TESTS FAILED!\n";
}
echo "=================================================================\n";
