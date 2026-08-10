<?php
// tests/security/plan_limit_test.php - Server-Side Plan Limit Enforcement Test
require_once __DIR__ . '/../../config.php';

echo "=================================================================\n";
echo "    RMS SaaS AUTOMATED SECURITY TEST: PLAN LIMIT ENFORCEMENT     \n";
echo "=================================================================\n";

$conn = getDBConnection();
if (!$conn) {
    echo "❌ [FATAL] Database connection failed.\n";
    exit(1);
}

$passed = 0;
$failed = 0;

function assertPlanLimit(bool $condition, string $testName) {
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ [PASS] $testName\n";
        $passed++;
    } else {
        echo "  ❌ [FAIL] $testName\n";
        $failed++;
    }
}

// -----------------------------------------------------------------
// SETUP TEST TENANT ON STARTER PLAN (Plan ID: 1, Max Tables: 10, Max Staff: 3)
// -----------------------------------------------------------------
$testTenant = 9301;
$conn->query("DELETE FROM restaurants WHERE id = $testTenant");
$conn->query("DELETE FROM tables WHERE restaurant_id = $testTenant");
$conn->query("DELETE FROM admin_users WHERE restaurant_id = $testTenant");

$conn->query("INSERT INTO restaurants (id, uuid, restaurant_code, restaurant_name, owner_name, email, phone, status, subscription_plan_id, subscription_status) 
    VALUES ($testTenant, 'uuid-9301', 'RMS-9301', 'Limit Test Restaurant', 'Owner Test', 'limit@test.com', '9800000000', 'ACTIVE', 1, 'ACTIVE')");

echo "\n--- TEST SUITE 1: Table Creation Limit Checks ---\n";

$limits = SubscriptionService::getTenantPlanLimits($testTenant);
$maxTables = (int)($limits['max_tables'] ?? 15);

// Seed maxTables - 1 tables (under limit)
for ($i = 1; $i <= ($maxTables - 1); $i++) {
    $conn->query("INSERT INTO tables (restaurant_id, table_number, status) VALUES ($testTenant, 'T-$i', 'vacant')");
}

$canAddUnder = SubscriptionService::canAddTable($testTenant);
assertPlanLimit($canAddUnder === true, "Tenant with " . ($maxTables - 1) . " tables CAN add table #" . $maxTables . " (Plan Limit: {$maxTables})");

// Seed maxTables-th table (at limit)
$conn->query("INSERT INTO tables (restaurant_id, table_number, status) VALUES ($testTenant, 'T-{$maxTables}', 'vacant')");

$canAddOver = SubscriptionService::canAddTable($testTenant);
assertPlanLimit($canAddOver === false, "Tenant with {$maxTables} tables CANNOT add table #" . ($maxTables + 1) . " (Plan Limit: {$maxTables} enforced server-side)");

echo "\n--- TEST SUITE 2: Staff Account Creation Limit Checks ---\n";

// Seed 5 staff accounts (at limit of 5 for Starter Plan)
for ($i = 1; $i <= 5; $i++) {
    $conn->query("INSERT INTO admin_users (restaurant_id, username, password, full_name, role) VALUES ($testTenant, 'staff_test_$i', 'hash', 'Staff $i', 'CASHIER')");
}

$canAddStaff6 = SubscriptionService::canAddStaff($testTenant);
assertPlanLimit($canAddStaff6 === false, "Tenant with 5 staff accounts CANNOT add 6th staff (Starter Plan Limit: 5 enforced server-side)");

// Clean test data
$conn->query("DELETE FROM restaurants WHERE id = $testTenant");
$conn->query("DELETE FROM tables WHERE restaurant_id = $testTenant");
$conn->query("DELETE FROM admin_users WHERE restaurant_id = $testTenant");

echo "\n=================================================================\n";
echo "        PLAN LIMIT ENFORCEMENT SECURITY TEST SUMMARY             \n";
echo "=================================================================\n";
echo "Total Tests Passed : $passed\n";
echo "Total Tests Failed : $failed\n";
echo "Overall Status     : " . ($failed === 0 ? "✅ ALL TESTS PASSED SUCCESSFULLY!" : "❌ SOME TESTS FAILED") . "\n";
echo "=================================================================\n";

if ($failed > 0) exit(1);
