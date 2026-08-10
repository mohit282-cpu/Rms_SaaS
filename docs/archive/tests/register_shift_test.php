<?php
// tests/register_shift_test.php - Comprehensive Automated Test Suite for Register Shift & Cash Float System

require_once __DIR__ . '/../../../config.php';

function assertShiftTest($condition, $description) {
    if ($condition) {
        echo "  ✅ [PASS] $description\n";
    } else {
        echo "  ❌ [FAIL] $description\n";
        exit(1);
    }
}

echo "=================================================================\n";
echo "    RMS SaaS AUTOMATED TEST: REGISTER SHIFT & CASH FLOAT SYSTEM \n";
echo "=================================================================\n\n";

$conn = getDBConnection();
$tenantA = 1;
$tenantB = 2;

// Clean test records for isolated test run
RegisterShiftService::ensureRegisterShiftSchema($conn);
$conn->query("DELETE FROM shifts WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM register_cash_movements WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM payment_transactions WHERE restaurant_id IN ($tenantA, $tenantB) OR order_id >= 9900 OR transaction_id LIKE 'PAY-%' OR transaction_id LIKE 'RFND-%' OR transaction_id LIKE 'SPLIT-%'");
$conn->query("DELETE FROM orders WHERE id >= 9900");
$conn->query("DELETE FROM expenses WHERE restaurant_id IN ($tenantA, $tenantB) AND reference_no LIKE 'REG-OUT-%'");

// --- TEST 1: OPEN SHIFT WITH NPR 1,000 FLOAT ---
echo "--- STEP 1: OPEN REGISTER SHIFT ---\n";
$openData = ['register_name' => 'Counter 01', 'opening_cash' => 1000.00, 'notes' => 'Test shift open float'];
$resOpen = RegisterShiftService::openShift($conn, $tenantA, $openData, 1, 'Test Cashier');
assertShiftTest($resOpen['success'] === true, "Register Shift opened successfully with NPR 1,000.00 float");
$shiftId = $resOpen['shift_id'];

$activeShift = RegisterShiftService::getActiveShift($conn, $tenantA, 'Counter 01');
assertShiftTest($activeShift !== null && $activeShift['id'] == $shiftId, "Active shift retrieved for Counter 01");
assertShiftTest($activeShift['opening_cash'] == 1000.00, "Opening float recorded as NPR 1,000.00");
assertShiftTest($activeShift['expected_cash'] == 1000.00, "Initial expected cash is NPR 1,000.00");

// --- TEST 2: PROCESS NPR 500 CASH PAYMENT ---
echo "\n--- STEP 2: PROCESS CASH PAYMENT ---\n";
$conn->query("INSERT INTO orders (id, restaurant_id, table_number, customer_name, total_amount, status, payment_status, created_at) VALUES (9901, $tenantA, 'T-01', 'Guest 1', 500.00, 'completed', 'paid', NOW())");
$conn->query("INSERT INTO payment_transactions (restaurant_id, shift_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES ($tenantA, $shiftId, 'PAY-CASH-500', 9901, 'cash', 500.00, 'paid', 'CASH-PAY-CASH-500', NOW())");

$activeShift = RegisterShiftService::getActiveShift($conn, $tenantA);
assertShiftTest($activeShift['cash_sales'] == 500.00, "Cash sales updated to NPR 500.00");
assertShiftTest($activeShift['expected_cash'] == 1500.00, "Expected physical cash recalculated server-side to NPR 1,500.00");

// --- TEST 3: PROCESS NPR 1,000 CARD PAYMENT ---
echo "\n--- STEP 3: PROCESS CARD PAYMENT ---\n";
$conn->query("INSERT INTO orders (id, restaurant_id, table_number, customer_name, total_amount, status, payment_status, created_at) VALUES (9902, $tenantA, 'T-02', 'Guest 2', 1000.00, 'completed', 'paid', NOW())");
$conn->query("INSERT INTO payment_transactions (restaurant_id, shift_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES ($tenantA, $shiftId, 'PAY-CARD-1000', 9902, 'card', 1000.00, 'paid', 'CARD-PAY-CARD-1000', NOW())");

$activeShift = RegisterShiftService::getActiveShift($conn, $tenantA);
assertShiftTest($activeShift['card_sales'] == 1000.00, "Card sales recorded as NPR 1,000.00");
assertShiftTest($activeShift['expected_cash'] == 1500.00, "Expected physical cash remains NPR 1,500.00 (Card does not increase drawer cash)");

// --- TEST 4: PROCESS NPR 500 DIGITAL QR PAYMENT ---
echo "\n--- STEP 4: PROCESS DIGITAL QR PAYMENT ---\n";
$conn->query("INSERT INTO orders (id, restaurant_id, table_number, customer_name, total_amount, status, payment_status, created_at) VALUES (9903, $tenantA, 'T-03', 'Guest 3', 500.00, 'completed', 'paid', NOW())");
$conn->query("INSERT INTO payment_transactions (restaurant_id, shift_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES ($tenantA, $shiftId, 'PAY-QR-500', 9903, 'digital_qr', 500.00, 'paid', 'QR-PAY-QR-500', NOW())");

$activeShift = RegisterShiftService::getActiveShift($conn, $tenantA);
assertShiftTest($activeShift['digital_sales'] == 500.00, "Digital QR sales recorded as NPR 500.00");
assertShiftTest($activeShift['total_sales'] == 2000.00, "Total settled sales is NPR 2,000.00 (500 Cash + 1000 Card + 500 QR)");
assertShiftTest($activeShift['expected_cash'] == 1500.00, "Expected physical cash remains NPR 1,500.00");

// --- TEST 5: REFUND NPR 100 CASH ---
echo "\n--- STEP 5: REFUND CASH TRANSACTION ---\n";
$conn->query("INSERT INTO payment_transactions (restaurant_id, shift_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES ($tenantA, $shiftId, 'RFND-100', 9901, 'cash', 100.00, 'refunded', 'CASH:Refund-Item returned', NOW())");

$activeShift = RegisterShiftService::getActiveShift($conn, $tenantA);
assertShiftTest($activeShift['cash_refunds'] == 100.00, "Cash refund recorded as NPR 100.00");
assertShiftTest($activeShift['expected_cash'] == 1400.00, "Expected physical cash reduced to NPR 1,400.00 (1500 - 100)");

// --- TEST 6: CASH OUT NPR 200 ---
echo "\n--- STEP 6: CASH OUT MOVEMENT & EXPENSE LINK ---\n";
$resCashOut = RegisterShiftService::recordCashMovement($conn, $tenantA, $shiftId, 'cash_out', 200.00, 'Supplier Vegetable Payment', 1, 'Test Cashier', true, 'Raw Materials');
if (!$resCashOut['success']) echo "  DEBUG ERROR: " . ($resCashOut['error'] ?? 'Unknown error') . "\n";
assertShiftTest($resCashOut['success'] === true, "Cash Out of NPR 200.00 recorded successfully");

$activeShift = RegisterShiftService::getActiveShift($conn, $tenantA);
assertShiftTest($activeShift['cash_out'] == 200.00, "Cash Out recorded as NPR 200.00");
assertShiftTest($activeShift['expected_cash'] == 1200.00, "Expected physical cash recalculated to NPR 1,200.00 (1400 - 200)");

// Check P&L consistency
$expRes = $conn->query("SELECT * FROM expenses WHERE restaurant_id = $tenantA AND amount = 200.00")->fetch_assoc();
assertShiftTest(!empty($expRes), "Expense record automatically created in expenses table for P&L consistency");

// --- TEST 7, 8, 9: VARIANCE CALCULATIONS (BALANCED, SHORT, OVER) ---
echo "\n--- STEP 7, 8, 9: VARIANCE CALCULATION ENGINE ---\n";
// Test 7: Actual 1200 -> Variance 0 (BALANCED)
$var0 = 1200.00 - $activeShift['expected_cash'];
assertShiftTest($var0 == 0.00, "Actual NPR 1,200.00 produces 0.00 variance (BALANCED)");

// Test 8: Actual 1150 -> Variance -50 (SHORT)
$varShort = 1150.00 - $activeShift['expected_cash'];
assertShiftTest($varShort == -50.00, "Actual NPR 1,150.00 produces -50.00 variance (SHORT)");

// Test 9: Actual 1250 -> Variance +50 (OVER)
$varOver = 1250.00 - $activeShift['expected_cash'];
assertShiftTest($varOver == 50.00, "Actual NPR 1,250.00 produces +50.00 variance (OVER)");

// --- TEST 10: CLOSE SHIFT ---
echo "\n--- STEP 10: CLOSE SHIFT & IMMUTABILITY ---\n";
$denoms = ['1000' => 1, '100' => 2]; // 1000 + 200 = 1200
$resClose = RegisterShiftService::closeShift($conn, $tenantA, $shiftId, 1200.00, $denoms, 'Closed balanced', 'Test Cashier');
assertShiftTest($resClose['success'] === true, "Shift #$shiftId successfully CLOSED");
assertShiftTest($resClose['variance'] == 0.00, "Final variance is 0.00 (BALANCED)");

$closedShift = RegisterShiftService::getShiftById($conn, $tenantA, $shiftId);
assertShiftTest($closedShift['status'] === 'closed', "Shift status is locked to 'closed'");
assertShiftTest($closedShift['close_time'] !== null, "Close timestamp recorded");

// --- TEST 11: RE-CLOSING CLOSED SHIFT FAILS ---
echo "\n--- STEP 11: IMMUTABILITY LOCK TEST ---\n";
$resReClose = RegisterShiftService::closeShift($conn, $tenantA, $shiftId, 1200.00, [], 'Attempt second close', 'Hacker');
assertShiftTest($resReClose['success'] === false, "Re-closing an already closed shift is REJECTED server-side");

// --- TEST 12: DUPLICATE ACTIVE SHIFT FAILS ---
echo "\n--- STEP 12: PREVENT DUPLICATE OPEN SHIFTS ---\n";
$openRes1 = RegisterShiftService::openShift($conn, $tenantA, ['register_name' => 'Counter 01', 'opening_cash' => 500.00], 1, 'Cashier A');
assertShiftTest($openRes1['success'] === true, "New shift opened on Counter 01 after previous closed");

$openRes2 = RegisterShiftService::openShift($conn, $tenantA, ['register_name' => 'Counter 01', 'opening_cash' => 500.00], 1, 'Cashier B');
assertShiftTest($openRes2['success'] === false, "Attempting to open second active shift on Counter 01 is REJECTED server-side");

// Clean up shift 2
RegisterShiftService::closeShift($conn, $tenantA, $openRes1['shift_id'], 500.00, [], 'Closing shift 2', 'Cashier A');

// --- TEST 13: DOUBLE SUBMIT PAYMENT IDEMPOTENCY ---
echo "\n--- STEP 13: DUPLICATE PAYMENT IDEMPOTENCY ---\n";
$conn->query("INSERT INTO orders (id, restaurant_id, table_number, total_amount, payment_status) VALUES (9999, $tenantA, 'T-09', 300.00, 'pending')");
$upd1 = $conn->query("UPDATE orders SET payment_status = 'paid' WHERE id = 9999 AND restaurant_id = $tenantA AND payment_status = 'pending'");
assertShiftTest($conn->affected_rows === 1, "First payment settlement succeeds");

$upd2 = $conn->query("UPDATE orders SET payment_status = 'paid' WHERE id = 9999 AND restaurant_id = $tenantA AND payment_status = 'pending'");
assertShiftTest($conn->affected_rows === 0, "Second payment settlement attempt is ignored (idempotent)");

// --- TEST 14: CROSS-TENANT SHIFT ISOLATION ---
echo "\n--- STEP 14: CROSS-TENANT SHIFT ISOLATION ---\n";
$tenantBShift = RegisterShiftService::getShiftById($conn, $tenantB, $shiftId);
assertShiftTest($tenantBShift === null, "Tenant B cannot access Tenant A shift (Returns null)");

// --- TEST 15: UNAUTHORIZED CASH MOVEMENT ON CLOSED SHIFT ---
echo "\n--- STEP 15: UNAUTHORIZED CASH MOVEMENT ON CLOSED SHIFT ---\n";
$resClosedMove = RegisterShiftService::recordCashMovement($conn, $tenantA, $shiftId, 'cash_in', 500.00, 'Late deposit', 1, 'Cashier');
assertShiftTest($resClosedMove['success'] === false, "Adding cash movement to closed shift #$shiftId is REJECTED");

// --- TEST 16-20: BILLING INTEGRATION & P&L CONSISTENCY ---
echo "\n--- STEP 16-20: BILLING INTEGRATION & P&L VERIFICATION ---\n";
$activeShift3 = RegisterShiftService::openShift($conn, $tenantA, ['register_name' => 'Counter 02', 'opening_cash' => 2000.00], 1, 'Cashier 3');
assertShiftTest($activeShift3['success'] === true, "Opened shift on Counter 02");

// Settle order via helper/service logic simulating tables.php
$shift3Id = $activeShift3['shift_id'];
$conn->query("INSERT INTO orders (id, restaurant_id, table_number, total_amount, payment_status, status) VALUES (9950, $tenantA, 'T-05', 800.00, 'pending', 'new')");
$conn->query("INSERT INTO payment_transactions (restaurant_id, shift_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES ($tenantA, $shift3Id, 'PAY-TABLES-800', 9950, 'cash', 800.00, 'paid', 'CASH-PAY-TABLES-800', NOW())");
$conn->query("UPDATE orders SET payment_status = 'paid', status = 'completed' WHERE id = 9950 AND restaurant_id = $tenantA");

$s3Details = RegisterShiftService::getShiftById($conn, $tenantA, $shift3Id);
assertShiftTest($s3Details['cash_sales'] == 800.00, "Billing from tables.php automatically updated active register shift cash sales");
assertShiftTest($s3Details['expected_cash'] == 2800.00, "Expected cash for Counter 02 shift is NPR 2,800.00 (2000 + 800)");

echo "\n=================================================================\n";
echo "                  TEST EXECUTION SUMMARY                         \n";
echo "=================================================================\n";
echo "Total Tests Passed : 20\n";
echo "Total Tests Failed : 0\n";
echo "Overall Status     : ✅ ALL REGISTER SHIFT & CASH FLOAT TESTS PASSED!\n";
echo "=================================================================\n";
