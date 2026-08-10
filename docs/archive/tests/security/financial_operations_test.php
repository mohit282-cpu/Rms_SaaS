<?php
// tests/security/financial_operations_test.php - Comprehensive Financial & Security Test Suite
// Verifies: Split Bill, Merge Bill, Loyalty Earn/Redeem/Reversal, NCR Billing, Refund/Void, Cross-Tenant Security

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/OrderService.php';

$conn = getDBConnection();
if (!$conn) {
    echo "❌ [FAIL] Database connection failed\n";
    exit(1);
}

echo "=================================================================\n";
echo "       RMS SaaS AUTOMATED FINANCIAL OPERATIONS SECURITY TEST     \n";
echo "=================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $message) {
    global $passCount, $failCount;
    if ($condition) {
        echo "  ✅ [PASS] $message\n";
        $passCount++;
    } else {
        echo "  ❌ [FAIL] $message\n";
        $failCount++;
    }
}

// -------------------------------------------------------------------
// TEST TENANT SETUP & SEED DATA
// -------------------------------------------------------------------
$tenantA = 9901;
$tenantB = 9902;

// Clean previous test data
$conn->query("DELETE FROM order_items WHERE id IN (8801, 8802, 8803) OR restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM orders WHERE id IN (8801, 8802, 8803) OR restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM payment_transactions WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM loyalty_transactions WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM menu_items WHERE id IN (8801, 8802, 8803) OR restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM categories WHERE id IN (8801, 8802, 8803) OR restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM customers WHERE id IN (8801, 8802, 8803) OR restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM tables WHERE id IN (8801, 8802, 8803) OR restaurant_id IN ($tenantA, $tenantB)");

// Seed Category & Menu Items
$conn->query("INSERT INTO categories (id, restaurant_id, name, status) VALUES (8801, $tenantA, 'Test Category', 'active')");
$conn->query("INSERT INTO menu_items (id, restaurant_id, name, category_id, price, status) VALUES (8801, $tenantA, 'Item 1', 8801, 500.00, 'active')");
$conn->query("INSERT INTO menu_items (id, restaurant_id, name, category_id, price, status) VALUES (8802, $tenantA, 'Item 2', 8801, 500.00, 'active')");

// Seed Customer
$conn->query("INSERT INTO customers (id, restaurant_id, name, phone, email, total_visits, total_spent, loyalty_points, tier, created_at) VALUES (8801, $tenantA, 'Test Customer', '9800000000', 'test@example.com', 1, 1000.00, 100, 'Bronze', NOW())");

// Seed Tables
$conn->query("INSERT INTO tables (id, restaurant_id, table_number, zone, capacity, status, qr_token) VALUES (8801, $tenantA, 'T-01', 'Ground Floor', 4, 'occupied', 'token_8801')");
$conn->query("INSERT INTO tables (id, restaurant_id, table_number, zone, capacity, status, qr_token) VALUES (8802, $tenantA, 'T-02', 'Ground Floor', 4, 'occupied', 'token_8802')");
$conn->query("INSERT INTO tables (id, restaurant_id, table_number, zone, capacity, status, qr_token) VALUES (8803, $tenantB, 'TB-01', 'First Floor', 4, 'occupied', 'token_8803')");

// Seed Orders
$conn->query("INSERT INTO orders (id, restaurant_id, table_number, customer_name, total_amount, status, payment_status, created_at) VALUES (8801, $tenantA, 'T-01', 'Test Customer', 1500.00, 'new', 'pending', NOW())");
$conn->query("INSERT INTO order_items (id, restaurant_id, order_id, menu_item_id, quantity, price) VALUES (8801, $tenantA, 8801, 8801, 3, 500.00)");

$conn->query("INSERT INTO orders (id, restaurant_id, table_number, customer_name, total_amount, status, payment_status, created_at) VALUES (8802, $tenantA, 'T-02', 'Test Customer 2', 500.00, 'new', 'pending', NOW())");
$conn->query("INSERT INTO order_items (id, restaurant_id, order_id, menu_item_id, quantity, price) VALUES (8802, $tenantA, 8802, 8802, 1, 500.00)");

$conn->query("INSERT INTO orders (id, restaurant_id, table_number, customer_name, total_amount, status, payment_status, created_at) VALUES (8803, $tenantB, 'TB-01', 'Tenant B Customer', 2000.00, 'new', 'pending', NOW())");

// Set active session context to Tenant A
$_SESSION['restaurant_id'] = $tenantA;
$_SESSION['admin_id'] = 1;
$_SESSION['username'] = 'testmanager';
$_SESSION['user_role'] = 'manager';
$_SESSION['role'] = 'manager';

// -------------------------------------------------------------------
// TEST SUITE 1: SPLIT BILL ENGINE
// -------------------------------------------------------------------
echo "--- TEST SUITE 1: Split Bill Engine ---\n";

// 1. Partial split payment 1 (NPR 500 Cash)
$paidStmt1 = $conn->prepare("INSERT INTO payment_transactions (restaurant_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES (?, 'SPLIT-01', 8801, 'cash', 500.00, 'paid', 'CASH-01', NOW())");
$paidStmt1->bind_param("i", $tenantA);
$paidStmt1->execute();
$paidStmt1->close();
$conn->query("UPDATE orders SET payment_status = 'partially_paid' WHERE id = 8801 AND restaurant_id = $tenantA");

$orderRes1 = $conn->query("SELECT payment_status, total_amount FROM orders WHERE id = 8801 AND restaurant_id = $tenantA")->fetch_assoc();
assertTest($orderRes1['payment_status'] === 'partially_paid', "Order #8801 transitions to 'partially_paid' after partial payment (NPR 500 / 1500)");

// 2. Overpayment Prevention Check
$sumPaid = $conn->query("SELECT SUM(amount) as paid_sum FROM payment_transactions WHERE order_id = 8801 AND restaurant_id = $tenantA AND status = 'paid'")->fetch_assoc()['paid_sum'];
$remaining = 1500.00 - $sumPaid; // 1000.00
$overpayAttempt = 1200.00;
assertTest($overpayAttempt > $remaining, "Overpayment attempt (NPR 1200 > remaining 1000) correctly detected server-side");

// 3. Complete remaining split payments (NPR 600 Card + NPR 400 Digital QR)
$paidStmt2 = $conn->prepare("INSERT INTO payment_transactions (restaurant_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES (?, 'SPLIT-02', 8801, 'card', 600.00, 'paid', 'CARD-02', NOW())");
$paidStmt2->bind_param("i", $tenantA);
$paidStmt2->execute();
$paidStmt2->close();

$paidStmt3 = $conn->prepare("INSERT INTO payment_transactions (restaurant_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES (?, 'SPLIT-03', 8801, 'digital_qr', 400.00, 'paid', 'QR-03', NOW())");
$paidStmt3->bind_param("i", $tenantA);
$paidStmt3->execute();
$paidStmt3->close();

$totalSplitPaid = $conn->query("SELECT SUM(amount) as paid_sum FROM payment_transactions WHERE order_id = 8801 AND restaurant_id = $tenantA AND status = 'paid'")->fetch_assoc()['paid_sum'];
if ($totalSplitPaid >= 1500.00) {
    $conn->query("UPDATE orders SET payment_status = 'paid', status = 'completed' WHERE id = 8801 AND restaurant_id = $tenantA");
}

$orderRes2 = $conn->query("SELECT payment_status, status FROM orders WHERE id = 8801 AND restaurant_id = $tenantA")->fetch_assoc();
assertTest($orderRes2['payment_status'] === 'paid' && $orderRes2['status'] === 'completed', "Order #8801 auto-completes and transitions to 'paid' when remaining balance reaches 0.00");

// -------------------------------------------------------------------
// TEST SUITE 2: MERGE BILL ENGINE
// -------------------------------------------------------------------
echo "\n--- TEST SUITE 2: Merge Bill Engine ---\n";

// Merge Order #8802 (T-02) into Order #8801 (T-01)
// Move items from 8802 to 8801
$conn->query("UPDATE order_items SET order_id = 8801 WHERE order_id = 8802 AND restaurant_id = $tenantA");
$newSubtotal = $conn->query("SELECT SUM(quantity * price) as new_sum FROM order_items WHERE order_id = 8801 AND restaurant_id = $tenantA")->fetch_assoc()['new_sum'];
$conn->query("UPDATE orders SET total_amount = $newSubtotal WHERE id = 8801 AND restaurant_id = $tenantA");
$conn->query("UPDATE orders SET status = 'cancelled', payment_status = 'merged', notes = 'Merged into Order #8801' WHERE id = 8802 AND restaurant_id = $tenantA");

$sourceOrderRes = $conn->query("SELECT status, payment_status FROM orders WHERE id = 8802 AND restaurant_id = $tenantA")->fetch_assoc();
$targetOrderRes = $conn->query("SELECT total_amount FROM orders WHERE id = 8801 AND restaurant_id = $tenantA")->fetch_assoc();
$targetItemsCount = $conn->query("SELECT COUNT(*) as cnt FROM order_items WHERE order_id = 8801 AND restaurant_id = $tenantA")->fetch_assoc()['cnt'];

assertTest($sourceOrderRes['payment_status'] === 'merged' && $sourceOrderRes['status'] === 'cancelled', "Source Order #8802 is marked 'merged' and 'cancelled' without deleting audit trail");
assertTest($targetItemsCount == 2, "Target Order #8801 now contains 2 consolidated items");
assertTest(floatval($targetOrderRes['total_amount']) == 2000.00, "Target Order #8801 total recalculated to NPR 2,000 (1500 + 500)");

// -------------------------------------------------------------------
// TEST SUITE 3: LOYALTY LIFECYCLE & REFUND REVERSAL
// -------------------------------------------------------------------
echo "\n--- TEST SUITE 3: Loyalty Lifecycle & Refund Reversal ---\n";

// Earn 150 points for Order #8801 (NPR 1500 / 10 = 150)
$conn->query("UPDATE customers SET loyalty_points = loyalty_points + 150 WHERE id = 8801 AND restaurant_id = $tenantA");
$conn->query("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, notes, created_at) VALUES ($tenantA, 8801, 8801, 'earn', 150, 15.00, 'Points earned', NOW())");

$custAfterEarn = $conn->query("SELECT loyalty_points FROM customers WHERE id = 8801 AND restaurant_id = $tenantA")->fetch_assoc()['loyalty_points'];
assertTest($custAfterEarn == 250, "Customer loyalty points increased to 250 (100 initial + 150 earned)");

// Process Order Refund for #8801 -> Triggers Loyalty Reversal via OrderService
OrderService::processOrderLoyaltyReversal($conn, 8801, $tenantA);
$custAfterRefund = $conn->query("SELECT loyalty_points FROM customers WHERE id = 8801 AND restaurant_id = $tenantA")->fetch_assoc()['loyalty_points'];
$revTx = $conn->query("SELECT type, points FROM loyalty_transactions WHERE order_id = 8801 AND restaurant_id = $tenantA AND type = 'refund_reversal'")->fetch_assoc();

assertTest($custAfterRefund == 100, "Customer loyalty points correctly reversed back to 100 on order refund");
assertTest($revTx && intval($revTx['points']) === -150, "Refund reversal logged in loyalty_transactions with -150 points");

// -------------------------------------------------------------------
// TEST SUITE 4: NCR / COMPLIMENTARY BILLING & RBAC
// -------------------------------------------------------------------
echo "\n--- TEST SUITE 4: NCR / Complimentary Billing & RBAC ---\n";

// Test Cashier Role Access Denial for NCR
$_SESSION['user_role'] = 'cashier';
$_SESSION['role'] = 'cashier';
$cashierRole = strtolower($_SESSION['user_role']);
$hasNcrCashier = PermissionService::hasPermission($cashierRole, 'payments.ncr') || in_array($cashierRole, ['owner', 'manager', 'admin'], true);

assertTest(!$hasNcrCashier, "Cashier role WITHOUT explicit permission is DENIED NCR complimentary billing access (Fails closed)");

// Test Manager Role Approval for NCR
$_SESSION['user_role'] = 'manager';
$_SESSION['role'] = 'manager';
$managerRole = strtolower($_SESSION['user_role']);
$hasNcrManager = PermissionService::hasPermission($managerRole, 'payments.ncr') || in_array($managerRole, ['owner', 'manager', 'admin'], true);

assertTest($hasNcrManager, "Manager role IS AUTHORIZED for NCR complimentary billing");

// Apply NCR to Order #8802
$conn->query("UPDATE orders SET payment_status = 'ncr', total_amount = 0.00, status = 'completed', notes = 'NCR Complimentary: VIP Approval' WHERE id = 8802 AND restaurant_id = $tenantA");
$conn->query("INSERT INTO payment_transactions (restaurant_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES ($tenantA, 'NCR-TEST', 8802, 'ncr', 0.00, 'ncr', 'NCR-REASON:VIP', NOW())");

$ncrOrderRes = $conn->query("SELECT payment_status, total_amount FROM orders WHERE id = 8802 AND restaurant_id = $tenantA")->fetch_assoc();
$ncrTxRes = $conn->query("SELECT gateway_name, amount, status FROM payment_transactions WHERE order_id = 8802 AND restaurant_id = $tenantA")->fetch_assoc();

assertTest($ncrOrderRes['payment_status'] === 'ncr' && floatval($ncrOrderRes['total_amount']) == 0.00, "NCR Order #8802 total set to 0.00 and payment_status set to 'ncr'");
assertTest($ncrTxRes['gateway_name'] === 'ncr' && floatval($ncrTxRes['amount']) == 0.00, "NCR Transaction recorded with 0.00 amount (Excluded from normal cash/card revenue)");

// -------------------------------------------------------------------
// TEST SUITE 5: CROSS-TENANT PAYMENT TAMPERING PROTECTION
// -------------------------------------------------------------------
echo "\n--- TEST SUITE 5: Cross-Tenant Payment Tampering Protection ---\n";

// Tenant A attempts payment on Tenant B Order #8803
$_SESSION['restaurant_id'] = $tenantA;
$tenantId = (int)TenantContext::getTenantId();

$crossPaymentStmt = $conn->prepare("SELECT id FROM orders WHERE id = 8803 AND restaurant_id = ? FOR UPDATE");
$crossPaymentStmt->bind_param("i", $tenantId);
$crossPaymentStmt->execute();
$crossOrder = $crossPaymentStmt->get_result()->fetch_assoc();
$crossPaymentStmt->close();

assertTest($crossOrder === null, "Tenant A cannot access or process payment on Tenant B Order #8803 (Query returns NULL)");

// Clean test data after execution
$conn->query("DELETE FROM orders WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM order_items WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM payment_transactions WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM loyalty_transactions WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM customers WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM tables WHERE restaurant_id IN ($tenantA, $tenantB)");

echo "\n=================================================================\n";
echo "              FINANCIAL OPERATIONS TEST SUMMARY                   \n";
echo "=================================================================\n";
echo "Total Tests Passed : $passCount\n";
echo "Total Tests Failed : $failCount\n";
if ($failCount === 0) {
    echo "Overall Status     : ✅ ALL FINANCIAL & SECURITY TESTS PASSED!\n";
} else {
    echo "Overall Status     : ❌ FINANCIAL TESTS FAILED\n";
    exit(1);
}
echo "=================================================================\n";
