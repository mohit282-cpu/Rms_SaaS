<?php
// tests/security/rbac_test.php - RBAC & Staff Role Permissions Security Test
require_once __DIR__ . '/../../config.php';

echo "=================================================================\n";
echo "       RMS SaaS AUTOMATED SECURITY TEST: RBAC & PERMISSIONS       \n";
echo "=================================================================\n";

$passed = 0;
$failed = 0;

function assertRBAC(bool $condition, string $testName) {
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ [PASS] $testName\n";
        $passed++;
    } else {
        echo "  ❌ [FAIL] $testName\n";
        $failed++;
    }
}

Auth::startSession();

echo "\n--- TEST SUITE 1: Staff Role Permission Enforcement ---\n";

// 1. OWNER role should have all permissions
$_SESSION['role'] = 'OWNER';
assertRBAC(PermissionService::hasPermission('OWNER', 'orders.view') === true, "OWNER has 'orders.view' permission");
assertRBAC(PermissionService::hasPermission('OWNER', 'inventory.manage') === true, "OWNER has 'inventory.manage' permission");
assertRBAC(PermissionService::hasPermission('OWNER', 'staff.manage') === true, "OWNER has 'staff.manage' permission");

// 2. CASHIER role permissions
$_SESSION['role'] = 'CASHIER';
assertRBAC(PermissionService::hasPermission('CASHIER', 'orders.create') === true, "CASHIER has 'orders.create' permission");
assertRBAC(PermissionService::hasPermission('CASHIER', 'payments.settle') === true, "CASHIER has 'payments.settle' permission");
assertRBAC(PermissionService::hasPermission('CASHIER', 'inventory.manage') === false, "CASHIER CANNOT access 'inventory.manage'");
assertRBAC(PermissionService::hasPermission('CASHIER', 'staff.manage') === false, "CASHIER CANNOT access 'staff.manage'");

// 3. WAITER role permissions
$_SESSION['role'] = 'WAITER';
assertRBAC(PermissionService::hasPermission('WAITER', 'orders.create') === true, "WAITER has 'orders.create' permission");
assertRBAC(PermissionService::hasPermission('WAITER', 'waiter_calls.manage') === true, "WAITER has 'waiter_calls.manage' permission");
assertRBAC(PermissionService::hasPermission('WAITER', 'payments.settle') === false, "WAITER CANNOT access 'payments.settle'");

// 4. KITCHEN role permissions
$_SESSION['role'] = 'KITCHEN';
assertRBAC(PermissionService::hasPermission('KITCHEN', 'orders.update') === true, "KITCHEN has 'orders.update' permission");
assertRBAC(PermissionService::hasPermission('KITCHEN', 'reports.view') === false, "KITCHEN CANNOT access 'reports.view'");

// 5. UNKNOWN / UNSET role => FAIL CLOSED
$_SESSION['role'] = 'INVALID_HACKER_ROLE';
assertRBAC(PermissionService::hasPermission('INVALID_HACKER_ROLE', 'orders.view') === false, "Invalid role is denied all permissions (Fails closed)");

echo "\n=================================================================\n";
echo "            RBAC & PERMISSIONS SECURITY TEST SUMMARY             \n";
echo "=================================================================\n";
echo "Total Tests Passed : $passed\n";
echo "Total Tests Failed : $failed\n";
echo "Overall Status     : " . ($failed === 0 ? "✅ ALL TESTS PASSED SUCCESSFULLY!" : "❌ SOME TESTS FAILED") . "\n";
echo "=================================================================\n";

if ($failed > 0) exit(1);
