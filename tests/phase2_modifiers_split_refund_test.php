<?php
// tests/phase2_modifiers_split_refund_test.php - Automated Verification Test Suite for Phase 2
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/ModifierService.php';
require_once __DIR__ . '/../helpers/BillService.php';
require_once __DIR__ . '/../helpers/RefundService.php';

echo "=================================================================\n";
echo "   PHASE 2: MODIFIERS, BILL SPLITS, MERGES, VOIDS & REFUNDS TEST  \n";
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
// TEST SUITE 1: Modifiers Group Constraint Validation
// -----------------------------------------------------------------
echo "--- TEST SUITE 1: Product Modifiers & Selection Constraints ---\n";

$conn->query("DELETE FROM modifier_groups WHERE restaurant_id = $tenantId AND name = 'Test Spice'");
$conn->query("INSERT INTO modifier_groups (restaurant_id, name, selection_type, is_required, min_selections, max_selections) VALUES ($tenantId, 'Test Spice', 'single', 1, 1, 1)");
$gid = $conn->insert_id;

$conn->query("INSERT INTO modifiers (restaurant_id, group_id, name, price) VALUES ($tenantId, $gid, 'Medium', 0.00)");
$mid = $conn->insert_id;

$valEmpty = ModifierService::validateSelections($gid, [], $tenantId);
assertTest($valEmpty['valid'] === false, "Required modifier group correctly blocks empty selection");

$valSuccess = ModifierService::validateSelections($gid, [$mid], $tenantId);
assertTest($valSuccess['valid'] === true, "Valid selection passes modifier validation");

echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 2: Bill Equal Splitting
// -----------------------------------------------------------------
echo "--- TEST SUITE 2: Bill Equal Splitting ---\n";

$conn->query("DELETE FROM orders WHERE restaurant_id = $tenantId AND table_number = 'SPLIT-TEST'");
$conn->query("INSERT INTO orders (restaurant_id, table_number, total_amount, status) VALUES ($tenantId, 'SPLIT-TEST', 1500.00, 'ready')");
$orderId = $conn->insert_id;

$splitRes = BillService::splitEqual($conn, $orderId, 3, $tenantId);
assertTest($splitRes['success'] === true, "Order #{$orderId} bill split into 3 equal parts");
assertTest(count($splitRes['splits']) === 3, "3 split records generated");
assertTest($splitRes['splits'][0]['amount'] === 500.00, "Split 1 amount correctly calculated as 500.00");
assertTest($splitRes['splits'][2]['amount'] === 500.00, "Split 3 amount correctly calculated as 500.00");

echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 3: Table Transfer
// -----------------------------------------------------------------
echo "--- TEST SUITE 3: Table Transfer Workflow ---\n";

$conn->query("INSERT INTO tables (restaurant_id, table_number, status) VALUES ($tenantId, 'T-03', 'occupied') ON DUPLICATE KEY UPDATE status='occupied'");
$conn->query("INSERT INTO tables (restaurant_id, table_number, status) VALUES ($tenantId, 'T-08', 'vacant') ON DUPLICATE KEY UPDATE status='vacant'");

$trRes = BillService::transferTable($conn, $orderId, 'T-08', 'tester', $tenantId);
assertTest($trRes['success'] === true, "Order transferred from T-03 to T-08 successfully");

$ordCheck = $conn->query("SELECT table_number FROM orders WHERE id = $orderId")->fetch_assoc();
assertTest($ordCheck['table_number'] === 'T-08', "Order table updated to T-08 in database");

echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 4: Voids & Refunds
// -----------------------------------------------------------------
echo "--- TEST SUITE 4: Order Voiding & Partial/Full Refund Controls ---\n";

$conn->query("INSERT INTO order_items (order_id, menu_item_id, quantity, price) VALUES ($orderId, 1, 2, 250.00)");
$itemId = $conn->insert_id;

$voidRes = RefundService::voidItem($conn, $orderId, $itemId, 'Customer cancelled dish', 'tester', $tenantId);
assertTest($voidRes['success'] === true, "Item #{$itemId} voided with mandatory reason log");

$refRes = RefundService::processRefund($conn, $orderId, 'full', 1500.00, 'cash', 'Customer complaint refund', 'tester', $tenantId);
assertTest($refRes['success'] === true, "Full refund of NPR 1500.00 processed with audit record");

$stCheck = $conn->query("SELECT status FROM orders WHERE id = $orderId")->fetch_assoc();
assertTest($stCheck['status'] === 'cancelled', "Order status updated to 'cancelled' after full refund");

// Clean up
$conn->query("DELETE FROM order_refunds WHERE order_id = $orderId");
$conn->query("DELETE FROM order_voids WHERE order_id = $orderId");
$conn->query("DELETE FROM orders WHERE id = $orderId");
$conn->query("DELETE FROM modifiers WHERE id = $mid");
$conn->query("DELETE FROM modifier_groups WHERE id = $gid");

echo "\n=================================================================\n";
echo "  ✅ SUCCESS: PHASE 2 ALL VERIFICATION TESTS PASSED 100%!        \n";
echo "=================================================================\n";
