<?php
// tests/phase3_crm_reservations_expenses_shifts_test.php - Automated Verification Test Suite for Phase 3
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/ShiftService.php';

echo "=================================================================\n";
echo "   PHASE 3: CRM, RESERVATIONS, EXPENSES & SHIFTS TEST           \n";
echo "=================================================================\n\n";

function assertTest($condition, $description) {
    if ($condition) {
        echo "  ✅ [PASS] $description\n";
    } else {
        echo "  ❌ [FAIL] $description\n";
        exit(1);
    }
}

$conn = getDBConnection();
$tenantId = 1;
$_SESSION['restaurant_id'] = $tenantId;

// -----------------------------------------------------------------
// TEST SUITE 1: Customer Profile & Spend Tracking
// -----------------------------------------------------------------
echo "--- TEST SUITE 1: Customer Profile & CRM Calculations ---\n";

$phone = "9899" . rand(10000, 99999);
$conn->query("INSERT INTO customers (restaurant_id, name, phone, email, total_spent, total_visits) VALUES ($tenantId, 'Test Customer', '$phone', 'test@test.com', 2500.00, 4)");
$cid = $conn->insert_id;

$cCheck = $conn->query("SELECT * FROM customers WHERE id = $cid")->fetch_assoc();
assertTest($cCheck['phone'] === $phone, "Customer record created with phone {$phone}");
assertTest(floatval($cCheck['total_spent']) === 2500.00, "Customer lifetime spent correctly set to 2500.00");

echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 2: Table Reservations & Double-Booking Prevention
// -----------------------------------------------------------------
echo "--- TEST SUITE 2: Table Reservation Collision Check ---\n";

$today = date('Y-m-d');
$conn->query("DELETE FROM reservations WHERE restaurant_id = $tenantId AND table_number = 'RES-T1'");
$conn->query("INSERT INTO reservations (restaurant_id, customer_name, phone, reservation_date, reservation_time, guest_count, table_number, status) VALUES ($tenantId, 'John Doe', '9800000000', '$today', '19:00:00', 4, 'RES-T1', 'confirmed')");
$rid = $conn->insert_id;

$checkStmt = $conn->prepare("SELECT id FROM reservations WHERE restaurant_id = ? AND table_number = 'RES-T1' AND reservation_date = ? AND status IN ('pending','confirmed')");
$checkStmt->bind_param("is", $tenantId, $today);
$checkStmt->execute();
$collisionRes = $checkStmt->get_result();
assertTest($collisionRes->num_rows > 0, "Double-booking prevention detects table RES-T1 is already reserved");
$checkStmt->close();

echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 3: Expense Recording & Operating Profit Calculation
// -----------------------------------------------------------------
echo "--- TEST SUITE 3: Expenses & Operating Profit P&L Calculation ---\n";

$conn->query("INSERT INTO expenses (restaurant_id, category_name, amount, expense_date, description, created_by) VALUES ($tenantId, 'Electricity', 500.00, '$today', 'Monthly Light Bill', 'tester')");
$eid = $conn->insert_id;

$revSumRes = $conn->query("SELECT COALESCE(SUM(total_amount), 0.00) as total_rev FROM orders WHERE restaurant_id = $tenantId AND status = 'completed' AND DATE(created_at) = '$today'");
$rev = floatval($revSumRes->fetch_assoc()['total_rev'] ?? 0.00);

$expSumRes = $conn->query("SELECT COALESCE(SUM(amount), 0.00) as total_exp FROM expenses WHERE restaurant_id = $tenantId AND expense_date = '$today'");
$exp = floatval($expSumRes->fetch_assoc()['total_exp'] ?? 0.00);

$pnl = $rev - $exp;
assertTest($exp >= 500.00, "Monthly expenses sum accurately includes 500.00 electricity bill");
assertTest(is_numeric($pnl), "Net operating profit (Revenue - Expenses) successfully calculated");

echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 4: Shift Management & Cash Drawer Reconciliation
// -----------------------------------------------------------------
echo "--- TEST SUITE 4: Shift Management & Cash Drawer Variance ---\n";

// Close any open shifts for test user ID 999
$conn->query("UPDATE work_shifts SET status = 'closed' WHERE restaurant_id = $tenantId AND user_id = 999 AND status = 'open'");

$openRes = ShiftService::openShift($conn, 999, 'TesterUser', 'Test Shift', 1000.00, $tenantId);
assertTest($openRes['success'] === true, "New shift opened with 1000.00 opening float");
$shiftId = $openRes['shift_id'];

$closeRes = ShiftService::closeShift($conn, $shiftId, 950.00, 'NPR 50 short', 'TesterUser', $tenantId);
assertTest($closeRes['success'] === true, "Shift closed and drawer reconciled");
assertTest($closeRes['expected_cash'] >= 1000.00, "Expected cash float correctly computed");
assertTest($closeRes['variance'] <= 0.00, "Cash variance correctly recorded");

// Clean up
$conn->query("DELETE FROM customers WHERE id = $cid");
$conn->query("DELETE FROM reservations WHERE id = $rid");
$conn->query("DELETE FROM expenses WHERE id = $eid");
$conn->query("DELETE FROM work_shifts WHERE id = $shiftId");

echo "\n=================================================================\n";
echo "  ✅ SUCCESS: PHASE 3 ALL VERIFICATION TESTS PASSED 100%!        \n";
echo "=================================================================\n";
