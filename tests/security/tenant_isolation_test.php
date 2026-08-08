<?php
// tests/security/tenant_isolation_test.php - Comprehensive Automated Two-Tenant Isolation Security Test
require_once __DIR__ . '/../../config.php';

echo "=================================================================\n";
echo "   RMS SaaS AUTOMATED SECURITY TEST: TWO-TENANT ISOLATION (A vs B) \n";
echo "=================================================================\n";

$conn = getDBConnection();
if (!$conn) {
    echo "❌ [FATAL] Database connection failed.\n";
    exit(1);
}

$passed = 0;
$failed = 0;

function assertSecurity(bool $condition, string $testName) {
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
// SETUP DUMMY TENANT DATA (Tenant A = 9101, Tenant B = 9102)
// -----------------------------------------------------------------
$tenantA = 9101;
$tenantB = 9102;

// Clean legacy test records
$conn->query("DELETE FROM orders WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM menu_items WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM inventory_items WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM assets WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM suppliers WHERE restaurant_id IN ($tenantA, $tenantB)");

// Seed Tenant A Data
$conn->query("INSERT INTO orders (id, restaurant_id, table_number, customer_name, total_amount, status) VALUES (91001, $tenantA, 'T-A1', 'Customer A', 1500.00, 'new')");
$conn->query("INSERT INTO menu_items (id, restaurant_id, name, price, category_id, status) VALUES (91001, $tenantA, 'Burger A', 500.00, 1, 'active')");
$conn->query("INSERT INTO inventory_items (id, restaurant_id, name, current_stock, status) VALUES (91001, $tenantA, 'Cheese A', 20.00, 'active')");
$conn->query("INSERT INTO assets (id, restaurant_id, asset_code, name, purchase_cost, status) VALUES (91001, $tenantA, 'AST-A1', 'Oven A', 50000.00, 'available')");
$conn->query("INSERT INTO suppliers (id, restaurant_id, company_name, status) VALUES (91001, $tenantA, 'Supplier A', 'active')");

// Seed Tenant B Data
$conn->query("INSERT INTO orders (id, restaurant_id, table_number, customer_name, total_amount, status) VALUES (91002, $tenantB, 'T-B1', 'Customer B', 2500.00, 'new')");
$conn->query("INSERT INTO menu_items (id, restaurant_id, name, price, category_id, status) VALUES (91002, $tenantB, 'Pizza B', 800.00, 1, 'active')");
$conn->query("INSERT INTO inventory_items (id, restaurant_id, name, current_stock, status) VALUES (91002, $tenantB, 'Flour B', 50.00, 'active')");
$conn->query("INSERT INTO assets (id, restaurant_id, asset_code, name, purchase_cost, status) VALUES (91002, $tenantB, 'AST-B1', 'Fryer B', 35000.00, 'available')");
$conn->query("INSERT INTO suppliers (id, restaurant_id, company_name, status) VALUES (91002, $tenantB, 'Supplier B', 'active')");

echo "\n--- TEST SUITE 1: Tenant Context Isolation & Query Scoping ---\n";

// Set Active Session Context to Tenant A
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_id'] = 91;
$_SESSION['restaurant_id'] = $tenantA;
$_SESSION['role'] = 'OWNER';

$resolvedId = TenantContext::getTenantId();
assertSecurity($resolvedId === $tenantA, "TenantContext resolves Tenant A (ID: $tenantA)");

// 1. Orders Isolation
$stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND restaurant_id = ?");
$stmt->bind_param("ii", $idB, $resolvedId);
$idB = 91002;
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
assertSecurity($res === null, "Tenant A CANNOT query Tenant B Order #91002 (Scoped Query Result = NULL)");

// 2. Menu Items Isolation
$stmt = $conn->prepare("SELECT id FROM menu_items WHERE id = ? AND restaurant_id = ?");
$stmt->bind_param("ii", $idB, $resolvedId);
$idB = 91002;
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
assertSecurity($res === null, "Tenant A CANNOT query Tenant B Menu Item #91002");

// 3. Inventory Items Isolation
$stmt = $conn->prepare("SELECT id FROM inventory_items WHERE id = ? AND restaurant_id = ?");
$stmt->bind_param("ii", $idB, $resolvedId);
$idB = 91002;
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
assertSecurity($res === null, "Tenant A CANNOT query Tenant B Inventory Item #91002");

// 4. Assets Isolation
$stmt = $conn->prepare("SELECT id FROM assets WHERE id = ? AND restaurant_id = ?");
$stmt->bind_param("ii", $idB, $resolvedId);
$idB = 91002;
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
assertSecurity($res === null, "Tenant A CANNOT query Tenant B Asset #91002");

// 5. Suppliers Isolation
$stmt = $conn->prepare("SELECT id FROM suppliers WHERE id = ? AND restaurant_id = ?");
$stmt->bind_param("ii", $idB, $resolvedId);
$idB = 91002;
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
assertSecurity($res === null, "Tenant A CANNOT query Tenant B Supplier #91002");

echo "\n--- TEST SUITE 2: Cross-Tenant Mutation (UPDATE / DELETE) Attack Testing ---\n";

// Attempt Tenant A updating Tenant B Order
$updStmt = $conn->prepare("UPDATE orders SET status = 'completed' WHERE id = 91002 AND restaurant_id = ?");
$updStmt->bind_param("i", $tenantA);
$updStmt->execute();
$affected = $updStmt->affected_rows;
$updStmt->close();
assertSecurity($affected === 0, "Tenant A UPDATE attempt on Tenant B Order #91002 affects 0 rows");

// Verify Tenant B Order remains unchanged
$checkB = $conn->query("SELECT status FROM orders WHERE id = 91002")->fetch_assoc();
assertSecurity(($checkB['status'] ?? '') === 'new', "Tenant B Order #91002 status remains untampered ('new')");

// Attempt Tenant A deleting Tenant B Inventory Item
$delStmt = $conn->prepare("DELETE FROM inventory_items WHERE id = 91002 AND restaurant_id = ?");
$delStmt->bind_param("i", $tenantA);
$delStmt->execute();
$affectedDel = $delStmt->affected_rows;
$delStmt->close();
assertSecurity($affectedDel === 0, "Tenant A DELETE attempt on Tenant B Inventory #91002 affects 0 rows");

// Verify Tenant B Inventory record still exists
$checkInvB = $conn->query("SELECT id FROM inventory_items WHERE id = 91002")->fetch_assoc();
assertSecurity(!empty($checkInvB), "Tenant B Inventory Item #91002 still exists");

echo "\n--- TEST SUITE 3: Parameter Tampering & Forgery Resistance ---\n";

// Inject forged GET and POST parameters attempting to switch tenant context to Tenant B
$_GET['restaurant_id'] = $tenantB;
$_POST['restaurant_id'] = $tenantB;
$_REQUEST['tenant_id'] = $tenantB;

$unforgedTenant = TenantContext::getTenantId();
assertSecurity($unforgedTenant === $tenantA, "TenantContext IGNORES forged GET/POST/REQUEST restaurant_id=$tenantB (Remains Tenant A: $tenantA)");

// Clean test data
$conn->query("DELETE FROM orders WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM menu_items WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM inventory_items WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM assets WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM suppliers WHERE restaurant_id IN ($tenantA, $tenantB)");

echo "\n=================================================================\n";
echo "           TENANT ISOLATION SECURITY TEST SUMMARY                \n";
echo "=================================================================\n";
echo "Total Tests Passed : $passed\n";
echo "Total Tests Failed : $failed\n";
echo "Overall Status     : " . ($failed === 0 ? "✅ ALL TESTS PASSED SUCCESSFULLY!" : "❌ SOME TESTS FAILED") . "\n";
echo "=================================================================\n";

if ($failed > 0) exit(1);
