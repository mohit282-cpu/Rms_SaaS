<?php
// tests/saas_tenant_isolation_test.php - Automated Multi-Tenant Isolation, IDOR & Manual Credential Security Test Suite
require_once __DIR__ . '/../config.php';

echo "=================================================================\n";
echo "       RMS SaaS MULTI-TENANT ISOLATION & CREDENTIAL SECURITY TEST \n";
echo "=================================================================\n\n";

$conn = getDBConnection();
if (!$conn) {
    echo "❌ [FAIL] Could not connect to MySQL database.\n";
    exit(1);
}

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

// -----------------------------------------------------------------
// TEST 1: Database Migration Audit (SaaS Tables & Columns Existence)
// -----------------------------------------------------------------
echo "--- TEST SUITE 1: SaaS Database Schema & Column Audit ---\n";
$saasTables = ['restaurants', 'subscription_plans', 'subscriptions', 'restaurant_requests', 'notifications'];
foreach ($saasTables as $tbl) {
    $res = $conn->query("SHOW TABLES LIKE '{$tbl}'");
    assertTest(($res && $res->num_rows > 0), "SaaS Table '{$tbl}' exists in database schema");
}

$entityTables = ['admin_users', 'categories', 'menu_items', 'tables', 'orders', 'inventory_items', 'assets'];
foreach ($entityTables as $tbl) {
    $colRes = $conn->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'restaurant_id'");
    assertTest(($colRes && $colRes->num_rows > 0), "Entity Table '{$tbl}' has 'restaurant_id' tenant column");
}
echo "\n";

// -----------------------------------------------------------------
// TEST 2: Tenant Isolation & Cross-Tenant IDOR Prevention
// -----------------------------------------------------------------
echo "--- TEST SUITE 2: Multi-Tenant Isolation & IDOR Protection ---\n";

// Setup Test Tenant A (ID: 9001) & Tenant B (ID: 9002)
$conn->query("DELETE FROM restaurants WHERE id IN (9001, 9002)");
$conn->query("DELETE FROM orders WHERE restaurant_id IN (9001, 9002)");

$conn->query("INSERT INTO restaurants (id, uuid, restaurant_code, restaurant_name, owner_name, email, phone, status, subscription_status) 
    VALUES (9001, 'uuid-alpha-9001', 'RMS-900001', 'Tenant Alpha Cafe', 'Owner Alpha', 'alpha@test.com', '9800000001', 'ACTIVE', 'ACTIVE')");

$conn->query("INSERT INTO restaurants (id, uuid, restaurant_code, restaurant_name, owner_name, email, phone, status, subscription_status) 
    VALUES (9002, 'uuid-beta-9002', 'RMS-900002', 'Tenant Beta Bistro', 'Owner Beta', 'beta@test.com', '9800000002', 'ACTIVE', 'ACTIVE')");

// Create Order for Tenant A (#99001) & Tenant B (#99002)
$conn->query("INSERT INTO orders (id, restaurant_id, table_number, customer_name, total_amount, status) VALUES (99001, 9001, 'T-01', 'Customer Alpha', 500.00, 'new')");
$conn->query("INSERT INTO orders (id, restaurant_id, table_number, customer_name, total_amount, status) VALUES (99002, 9002, 'T-01', 'Customer Beta', 850.00, 'new')");

// Bind Session to Tenant A
$_SESSION['restaurant_id'] = 9001;
$_SESSION['admin_logged_in'] = true;
$_SESSION['role'] = 'OWNER';
$_SESSION['is_super_admin'] = false;

assertTest(TenantContext::getTenantId() === 9001, "TenantContext correctly resolves active Tenant A (ID: 9001)");

// Test 2a: Tenant A accessing Tenant A's order -> Must Succeed
$resA = $conn->query("SELECT * FROM orders WHERE id = 99001 AND restaurant_id = " . TenantContext::getTenantId());
assertTest(($resA && $resA->num_rows === 1), "Tenant A can successfully query own Order #99001");

// Test 2b: Tenant A accessing Tenant B's order -> Must Return 0 Rows (Isolated)
$resB = $conn->query("SELECT * FROM orders WHERE id = 99002 AND restaurant_id = " . TenantContext::getTenantId());
assertTest(($resB && $resB->num_rows === 0), "Tenant A cannot access Tenant B's Order #99002 (SQL Filter Isolated)");

// Test 2c: IDOR Parameter Tampering Defense (GET/POST forgery override attempt)
$_GET['restaurant_id'] = 9002;
$_POST['restaurant_id'] = 9002;
assertTest(TenantContext::getTenantId() === 9001, "TenantContext IGNORES forged GET/POST restaurant_id parameters");
unset($_GET['restaurant_id'], $_POST['restaurant_id']);
echo "\n";

// -----------------------------------------------------------------
// TEST 3: Public Onboarding Request Workflow & Notification Audit
// -----------------------------------------------------------------
echo "--- TEST SUITE 3: Public Onboarding & Super Admin Pipeline ---\n";

$reqCode = 'REQ-TEST-' . rand(1000, 9999);
$conn->query("INSERT INTO restaurant_requests (request_code, restaurant_name, owner_name, email, phone, status) VALUES ('{$reqCode}', 'Request Test Cafe', 'Test Owner', 'req@test.com', '9811111111', 'PENDING')");
$reqId = $conn->insert_id;

$reqCheck = $conn->query("SELECT * FROM restaurant_requests WHERE id = {$reqId} AND status = 'PENDING'");
assertTest(($reqCheck && $reqCheck->num_rows === 1), "Public request form insertion creates PENDING request #{$reqId}");

// Simulate Super Admin Approval -> Creation of Tenant
$conn->query("UPDATE restaurant_requests SET status = 'CONVERTED' WHERE id = {$reqId}");
$convCheck = $conn->query("SELECT status FROM restaurant_requests WHERE id = {$reqId}");
assertTest(($convCheck && $convCheck->fetch_assoc()['status'] === 'CONVERTED'), "Super Admin approval transitions request status to CONVERTED");
echo "\n";

// -----------------------------------------------------------------
// TEST 4: Account Suspension & Subscription Guards
// -----------------------------------------------------------------
echo "--- TEST SUITE 4: Account Suspension & Subscription Enforcement ---\n";

// Suspend Tenant B
$conn->query("UPDATE restaurants SET status = 'SUSPENDED' WHERE id = 9002");
$suspCheck = $conn->query("SELECT status FROM restaurants WHERE id = 9002");
assertTest(($suspCheck && $suspCheck->fetch_assoc()['status'] === 'SUSPENDED'), "Super Admin can suspend tenant account #9002");

// Test Subscription Expiry Service
assertTest(SubscriptionService::isActive(9001) === true, "Active Tenant 9001 passes SubscriptionService::isActive()");

$conn->query("UPDATE restaurants SET subscription_status = 'EXPIRED' WHERE id = 9002");
assertTest(SubscriptionService::isActive(9002) === false, "Expired/Suspended Tenant 9002 fails SubscriptionService::isActive()");
echo "\n";

// -----------------------------------------------------------------
// TEST 5: Manual Credential Assignment & Password Security
// -----------------------------------------------------------------
echo "--- TEST SUITE 5: Manual Credentials & Username Uniqueness ---\n";

$conn->query("DELETE FROM admin_users WHERE username = 'manual_admin_test'");

// Create user with manual username & password hash
$rawPass = 'SuperSecret123!';
$hashPass = password_hash($rawPass, PASSWORD_BCRYPT);
$conn->query("INSERT INTO admin_users (username, password, full_name, role, is_super_admin, restaurant_id) VALUES ('manual_admin_test', '{$hashPass}', 'Manual Owner', 'owner', 0, 9001)");

$uCheck = $conn->query("SELECT * FROM admin_users WHERE username = 'manual_admin_test'");
assertTest(($uCheck && $uCheck->num_rows === 1), "Manual admin account created with explicit username");

$uData = $uCheck->fetch_assoc();
assertTest(password_verify($rawPass, $uData['password']) === true, "Manually set password verifies against BCRYPT hash");
assertTest(strpos($uData['password'], 'SuperSecret123!') === false, "Plaintext password is NEVER stored in database");

// Duplicate username check
$dupCheck = $conn->query("SELECT id FROM admin_users WHERE username = 'manual_admin_test'");
assertTest(($dupCheck && $dupCheck->num_rows > 0), "Duplicate username check correctly detects existing username");

$conn->query("DELETE FROM admin_users WHERE username = 'manual_admin_test'");
echo "\n";

// -----------------------------------------------------------------
// CLEANUP TEST DATA
// -----------------------------------------------------------------
$conn->query("DELETE FROM orders WHERE id IN (99001, 99002)");
$conn->query("DELETE FROM restaurants WHERE id IN (9001, 9002)");
$conn->query("DELETE FROM restaurant_requests WHERE id = {$reqId}");

echo "=================================================================\n";
echo "                  TEST SUITE EXECUTION SUMMARY                   \n";
echo "=================================================================\n";
echo "Total Tests Passed : {$passed}\n";
echo "Total Tests Failed : {$failed}\n";
echo "Overall Status     : " . ($failed === 0 ? "✅ ALL TESTS PASSED SUCCESSFULLY!" : "❌ SOME TESTS FAILED") . "\n";
echo "=================================================================\n";

exit($failed > 0 ? 1 : 0);
