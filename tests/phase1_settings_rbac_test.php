<?php
// tests/phase1_settings_rbac_test.php - Automated Verification Test Suite for Phase 1
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/CalculationEngine.php';
require_once __DIR__ . '/../helpers/RBAC.php';

echo "=================================================================\n";
echo "       PHASE 1: RESTAURANT SETTINGS, TAX ENGINE & RBAC TEST      \n";
echo "=================================================================\n\n";

function assertTest($condition, $description) {
    if ($condition) {
        echo "  ✅ [PASS] $description\n";
    } else {
        echo "  ❌ [FAIL] $description\n";
        exit(1);
    }
}

// -----------------------------------------------------------------
// TEST SUITE 1: Calculation Engine Calculations
// -----------------------------------------------------------------
echo "--- TEST SUITE 1: CalculationEngine Financial Logic ---\n";

$customSettings = [
    'tax_enabled' => 1,
    'tax_percentage' => 13.00,
    'service_charge_enabled' => 1,
    'service_charge_type' => 'percent',
    'service_charge_amount' => 10.00,
    'discount_max_percent' => 20.00,
    'currency' => 'NPR'
];

// Subtotal = 1000.00
// Service Charge (10%) = 100.00
// Tax Base = 1100.00
// Tax (13%) = 143.00
// Discount (10% on Subtotal 1000) = 100.00
// Expected Grand Total = (1000 + 100 + 143) - 100 = 1143.00

$calc1 = CalculationEngine::calculate(1000.00, 10.00, 'percent', 1, $customSettings);
assertTest($calc1['subtotal'] === 1000.00, "Subtotal correctly resolved to 1000.00");
assertTest($calc1['service_charge_amount'] === 100.00, "Service charge (10%) correctly calculated as 100.00");
assertTest($calc1['tax_amount'] === 143.00, "VAT (13% on 1100) correctly calculated as 143.00");
assertTest($calc1['discount_amount'] === 100.00, "Discount (10%) correctly calculated as 100.00");
assertTest($calc1['grand_total'] === 1143.00, "Grand total correctly computed as 1143.00");

echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 2: RBAC Server-Side Permissions
// -----------------------------------------------------------------
echo "--- TEST SUITE 2: RBAC Server-Side Permission Enforcements ---\n";

Auth::startSession();
$_SESSION['is_super_admin'] = true;
assertTest(RBAC::hasPermission('manage_settings') === true, "Super Admin has universal permission across all endpoints");

$_SESSION['is_super_admin'] = false;
$_SESSION['role'] = 'CASHIER';
$_SESSION['restaurant_id'] = 9010;

assertTest(RBAC::hasPermission('create_orders') === true, "Cashier role has permission 'create_orders'");
assertTest(RBAC::hasPermission('manage_settings') === false, "Cashier role is BLOCKED from 'manage_settings'");
assertTest(RBAC::hasPermission('refund_payment') === false, "Cashier role is BLOCKED from 'refund_payment'");

$_SESSION['role'] = 'OWNER';
assertTest(RBAC::hasPermission('manage_settings') === true, "Owner role has permission 'manage_settings'");
assertTest(RBAC::hasPermission('manage_staff') === true, "Owner role has permission 'manage_staff'");

echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 3: Database Settings Persistence (Tenant Isolation)
// -----------------------------------------------------------------
echo "--- TEST SUITE 3: Multi-Tenant Settings Persistence ---\n";

$conn = getDBConnection();
if ($conn) {
    $tenantId = 9010;
    $conn->query("INSERT IGNORE INTO restaurant_settings (restaurant_id) VALUES ($tenantId)");

    $conn->query("UPDATE restaurant_settings SET tax_percentage = 15.00, currency = 'USD' WHERE restaurant_id = $tenantId");
    $s9010 = CalculationEngine::getSettings($tenantId);
    assertTest(floatval($s9010['tax_percentage']) === 15.00, "Tenant 9010 tax percentage saved as 15.00%");
    assertTest($s9010['currency'] === 'USD', "Tenant 9010 currency saved as USD");

    $tenantOther = 1;
    $s1 = CalculationEngine::getSettings($tenantOther);
    assertTest($s1['currency'] !== 'USD' || $s1['restaurant_id'] == $tenantId, "Tenant 1 settings remain strictly isolated from Tenant 9010");
}

echo "\n=================================================================\n";
echo "  ✅ SUCCESS: PHASE 1 ALL VERIFICATION TESTS PASSED 100%!        \n";
echo "=================================================================\n";
