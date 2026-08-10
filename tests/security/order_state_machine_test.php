<?php
// tests/security/order_state_machine_test.php - Order Lifecycle State Machine Audit
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/OrderService.php';

echo "=================================================================\n";
echo "       RMS SaaS SECURITY TEST: ORDER STATE MACHINE VALIDATION     \n";
echo "=================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $testName) {
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ [PASS] {$testName}\n";
        $passed++;
    } else {
        echo "  ❌ [FAIL] {$testName}\n";
        $failed++;
    }
}

echo "--- TEST SUITE 1: Legal Transitions Are Allowed ---\n";
assertTest(OrderService::isValidTransition('new', 'preparing'), "new -> preparing is a legal transition");
assertTest(OrderService::isValidTransition('preparing', 'ready'), "preparing -> ready is a legal transition");
assertTest(OrderService::isValidTransition('ready', 'completed'), "ready -> completed is a legal transition");
assertTest(OrderService::isValidTransition('new', 'cancelled'), "new -> cancelled is a legal transition");
assertTest(OrderService::isValidTransition('preparing', 'cancelled'), "preparing -> cancelled is a legal transition");
assertTest(OrderService::isValidTransition('completed', 'refund_requested'), "completed -> refund_requested is a legal transition");
assertTest(OrderService::isValidTransition('refund_requested', 'refunded'), "refund_requested -> refunded is a legal transition");
assertTest(OrderService::isValidTransition('new', 'new'), "No-op same-state transition is permitted");
echo "\n";

echo "--- TEST SUITE 2: Illegal Transitions Are Blocked ---\n";
assertTest(!OrderService::isValidTransition('new', 'ready'), "new -> ready is BLOCKED (skips preparation)");
assertTest(!OrderService::isValidTransition('new', 'completed'), "new -> completed is BLOCKED (skips prep/ready)");
assertTest(!OrderService::isValidTransition('preparing', 'completed'), "preparing -> completed is BLOCKED (skips ready)");
assertTest(!OrderService::isValidTransition('completed', 'preparing'), "completed -> preparing is BLOCKED (state regression)");
assertTest(!OrderService::isValidTransition('completed', 'ready'), "completed -> ready is BLOCKED (state regression)");
assertTest(!OrderService::isValidTransition('cancelled', 'new'), "cancelled -> new is BLOCKED (terminal state)");
assertTest(!OrderService::isValidTransition('cancelled', 'ready'), "cancelled -> ready is BLOCKED (terminal state)");
assertTest(!OrderService::isValidTransition('refunded', 'completed'), "refunded -> completed is BLOCKED (terminal state)");
assertTest(!OrderService::isValidTransition('new', 'refunded'), "new -> refunded is BLOCKED (refund only after completed)");
assertTest(!OrderService::isValidTransition('invalid_state', 'completed'), "Unknown source state fails closed");
echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 3: Atomic Transition Enforcement With Real DB
// -----------------------------------------------------------------
echo "--- TEST SUITE 3: Atomic State Transition (DB Level) ---\n";
$conn = getDBConnection();
if (!$conn) {
    echo "  ⚠️ [SKIP] Database not available - skipping DB-level assertions.\n";
} else {
    $testTenant = 9301;
    $conn->query("DELETE FROM orders WHERE restaurant_id = $testTenant AND table_number = 'STATE-TEST'");
    $conn->query("INSERT INTO orders (restaurant_id, table_number, customer_name, status, total_amount, payment_status) VALUES ($testTenant, 'STATE-TEST', 'State Machine Tester', 'new', 100.00, 'pending')");
    $orderId = $conn->insert_id;

    // Set tenant context to test tenant
    Auth::startSession();
    $_SESSION['restaurant_id'] = $testTenant;

    // Legal transition: new -> preparing
    $r1 = OrderService::transitionStatus($conn, $orderId, 'preparing', 'admin');
    assertTest($r1['success'] === true, "DB: new -> preparing transition succeeds");

    // Illegal transition: preparing -> completed (skips ready)
    $r2 = OrderService::transitionStatus($conn, $orderId, 'completed', 'admin');
    assertTest($r2['success'] === false, "DB: preparing -> completed is rejected by server");

    // Verify state unchanged after rejected transition
    $stateCheck = $conn->query("SELECT status FROM orders WHERE id = $orderId");
    $currentState = $stateCheck ? $stateCheck->fetch_assoc()['status'] : 'unknown';
    assertTest($currentState === 'preparing', "DB: Order state remains 'preparing' after rejected transition");

    // Legal completion path
    OrderService::transitionStatus($conn, $orderId, 'ready', 'admin');
    $r3 = OrderService::transitionStatus($conn, $orderId, 'completed', 'admin');
    assertTest($r3['success'] === true, "DB: ready -> completed (full legal path) succeeds");

    // Terminal state: completed -> ready regression
    $r4 = OrderService::transitionStatus($conn, $orderId, 'ready', 'admin');
    assertTest($r4['success'] === false, "DB: completed -> ready regression is rejected");

    $conn->query("DELETE FROM orders WHERE id = $orderId");
}

echo "\n=================================================================\n";
echo "                  TEST SUITE EXECUTION SUMMARY                   \n";
echo "=================================================================\n";
echo "Total Tests Passed : {$passed}\n";
echo "Total Tests Failed : {$failed}\n";
echo "Overall Status     : " . ($failed === 0 ? "✅ ALL TESTS PASSED SUCCESSFULLY!" : "❌ SOME TESTS FAILED") . "\n";
echo "=================================================================\n";

exit($failed > 0 ? 1 : 0);
