<?php
// tests/phase4_loyalty_analytics_audit_test.php - Automated Verification Test Suite for Phase 4
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/LoyaltyService.php';

echo "=================================================================\n";
echo "   PHASE 4: LOYALTY, ANALYTICS & AUDIT LOGGING TEST              \n";
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
// TEST SUITE 1: Loyalty Point Earning & Tier Promotion
// -----------------------------------------------------------------
echo "--- TEST SUITE 1: Loyalty Point Earning & Tier Upgrade ---\n";

$phone = "9877" . rand(10000, 99999);
$conn->query("INSERT INTO customers (restaurant_id, name, phone, loyalty_points, tier) VALUES ($tenantId, 'Loyalty Tester', '$phone', 0, 'BRONZE')");
$cid = $conn->insert_id;

// Award points for NPR 60,000 order (should earn 600 points -> Upgrade to SILVER tier)
$awardRes = LoyaltyService::awardPointsForOrder($conn, 9999, $cid, 60000.00, $tenantId);
assertTest($awardRes['success'] === true, "Loyalty points awarded for order #9999 (" . ($awardRes['message'] ?? '') . ")");
assertTest($awardRes['points_earned'] === 600, "600 points earned for NPR 60,000 order (1 pt per NPR 100)");

$cCheck = $conn->query("SELECT loyalty_points, tier FROM customers WHERE id = $cid")->fetch_assoc();
assertTest(intval($cCheck['loyalty_points']) === 600, "Customer loyalty_points balance updated to 600");
assertTest($cCheck['tier'] === 'Silver', "Customer tier automatically promoted to Silver (>= 500 pts)");

echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 2: Loyalty Point Redemption
// -----------------------------------------------------------------
echo "--- TEST SUITE 2: Loyalty Point Redemption & Ledger Entry ---\n";

$redeemRes = LoyaltyService::redeemPoints($conn, $cid, 200, 9999, $tenantId);
assertTest($redeemRes['success'] === true, "200 points redeemed for NPR 200 discount");
assertTest($redeemRes['remaining_points'] === 400, "Remaining points balance correctly reduced to 400");

$ledgerCheck = $conn->query("SELECT * FROM loyalty_transactions WHERE customer_id = $cid AND type = 'redeem'")->fetch_assoc();
assertTest($ledgerCheck !== null, "Loyalty transaction ledger entry created for redemption");

echo "\n";

// -----------------------------------------------------------------
// TEST SUITE 3: Audit Security Logging
// -----------------------------------------------------------------
echo "--- TEST SUITE 3: System-Wide Audit Logging ---\n";

Security::logAudit("PHASE4_TEST_ACTION", "Testing audit trail logging system");
$auditCheck = $conn->query("SELECT * FROM audit_logs WHERE restaurant_id = $tenantId ORDER BY id DESC LIMIT 1")->fetch_assoc();
assertTest($auditCheck !== null, "System audit log entry successfully recorded in database");

// Clean up
$conn->query("DELETE FROM loyalty_transactions WHERE customer_id = $cid");
$conn->query("DELETE FROM customers WHERE id = $cid");
$conn->query("DELETE FROM audit_logs WHERE event_type = 'PHASE4_TEST_ACTION'");

echo "\n=================================================================\n";
echo "  ✅ SUCCESS: PHASE 4 ALL VERIFICATION TESTS PASSED 100%!        \n";
echo "=================================================================\n";
