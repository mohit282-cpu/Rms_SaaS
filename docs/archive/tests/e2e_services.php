<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require 'Z:/Xampp/htdocs/Rms_SaaS/config.php';
require 'Z:/Xampp/htdocs/Rms_SaaS/helpers/LoyaltyService.php';

$conn = getDBConnection();
if (!$conn) { fwrite(STDERR, "FATAL: no DB\n"); exit(2); }

// ---- scratch tenant + helpers -------------------------------------------------
$T = 987654; // scratch tenant id
$pass = 0; $fail = 0;
$results = [];

function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $name\n"; }
    else     { $fail++; echo "FAIL  $name  -- $detail\n"; }
}
function approx(float $a, float $b, float $eps = 0.011): bool { return abs($a - $b) <= $eps; }

// wipe any leftovers from prior runs
$conn->query("DELETE FROM payment_transactions WHERE restaurant_id = $T");
$conn->query("DELETE FROM loyalty_transactions WHERE restaurant_id = $T");
$conn->query("DELETE FROM order_items WHERE restaurant_id = $T");
$conn->query("DELETE FROM orders WHERE restaurant_id = $T");
$conn->query("DELETE FROM menu_items WHERE restaurant_id = $T");
$conn->query("DELETE FROM categories WHERE restaurant_id = $T");
$conn->query("DELETE FROM customers WHERE restaurant_id = $T");
$conn->query("DELETE FROM payment_settings WHERE restaurant_id = $T");
$conn->query("DELETE FROM restaurant_loyalty_settings WHERE restaurant_id = $T");
$conn->query("DELETE FROM restaurants WHERE id = $T");

// create a restaurants row so provisioning has something to copy from
$uuid = "E2E-$T"; $code = "E2E-$T"; $rname = 'E2E TEST'; $owner = 'Tester';
$stmt = $conn->prepare("INSERT INTO restaurants (id, uuid, restaurant_code, restaurant_name, owner_name, status, subscription_status) VALUES (?, ?, ?, ?, ?, 'ACTIVE', 'ACTIVE')");
$stmt->bind_param("issss", $T, $uuid, $code, $rname, $owner);
$stmt->execute(); $stmt->close();
$conn->query("UPDATE restaurants SET restaurant_name = 'E2E TEST', email = 'e2e@test.local', phone = '1111', pan_number = 'PN123', address = 'Test St' WHERE id = $T");

// ---------------------------------------------------------------------------
echo "=== 1. Settings validation & persistence ===\n";

// bad email must fail
$bad = RestaurantSettingsService::saveSettings($conn, $T, [
    'restaurant_name' => 'E2E', 'payment_note' => '', 'tax_enabled' => 0, 'tax_percentage' => 0,
    'vat_mode' => 'exclusive', 'service_charge_enabled' => 0, 'service_charge_type' => 'percent',
    'service_charge_amount' => 0, 'address' => '', 'phone' => '', 'email' => 'not-an-email',
    'pan_vat' => '', 'currency' => 'NPR', 'currency_symbol' => 'Rs.', 'currency_position' => 'left',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 1, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 0, 'max_redemption_points' => 0, 'max_discount_percent' => 0,
    'min_bill_amount' => 0, 'expiration_enabled' => 0, 'expiration_days' => 365, 'earning_basis' => 'subtotal_after_discounts',
]);
check('invalid email rejected', $bad['success'] === false, json_encode($bad));

$bad2 = RestaurantSettingsService::saveSettings($conn, $T, [
    'restaurant_name' => 'E2E', 'payment_note' => '', 'tax_enabled' => 1, 'tax_percentage' => 999,
    'vat_mode' => 'exclusive', 'service_charge_enabled' => 0, 'service_charge_type' => 'percent',
    'service_charge_amount' => 0, 'address' => '', 'phone' => '', 'email' => '',
    'pan_vat' => '', 'currency' => 'NPR', 'currency_symbol' => 'Rs.', 'currency_position' => 'left',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 1, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 0, 'max_redemption_points' => 0, 'max_discount_percent' => 0,
    'min_bill_amount' => 0, 'expiration_enabled' => 0, 'expiration_days' => 365, 'earning_basis' => 'subtotal_after_discounts',
]);
check('tax 999 rejected', $bad2['success'] === false, json_encode($bad2));

// valid save: tax 13% exclusive, SC 10% percent, loyalty 1pt/100, point value 1, max disc 20%
$okSave = RestaurantSettingsService::saveSettings($conn, $T, [
    'restaurant_name' => 'E2E TEST', 'payment_note' => 'Thanks!', 'tax_enabled' => 1, 'tax_percentage' => 13,
    'vat_mode' => 'exclusive', 'service_charge_enabled' => 1, 'service_charge_type' => 'percent',
    'service_charge_amount' => 10, 'address' => 'Test St', 'phone' => '1111', 'email' => 'e2e@test.local',
    'pan_vat' => 'PN123', 'currency' => 'NPR', 'currency_symbol' => 'Rs.', 'currency_position' => 'left',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 1, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 0, 'max_redemption_points' => 0, 'max_discount_percent' => 20,
    'min_bill_amount' => 0, 'expiration_enabled' => 1, 'expiration_days' => 30, 'earning_basis' => 'grand_total_before_tax',
]);
check('valid save succeeds', $okSave['success'] === true, json_encode($okSave));
$pay = RestaurantSettingsService::getPaymentSettings($conn, $T);
check('restaurant name persisted', $pay['restaurant_name'] === 'E2E TEST', $pay['restaurant_name']);
check('vat_mode persisted', $pay['vat_mode'] === 'exclusive', $pay['vat_mode']);
check('SC percent persisted', $pay['service_charge_type'] === 'percent' && approx((float)$pay['service_charge_amount'], 10));
$loy = RestaurantSettingsService::getLoyaltySettings($conn, $T);
check('earning_points persisted', (int)$loy['earning_points'] === 1, (string)$loy['earning_points']);
check('min_bill_amount persisted', (float)$loy['min_bill_amount'] === 0.0);
check('expiration persisted', (int)$loy['expiration_days'] === 30, (string)$loy['expiration_days']);
check('restaurants row synced', $conn->query("SELECT restaurant_name FROM restaurants WHERE id = $T")->fetch_assoc()['restaurant_name'] === 'E2E TEST');

// ---------------------------------------------------------------------------
echo "\n=== 2. Fixtures: menu + order ===\n";
$conn->query("INSERT INTO categories (restaurant_id, name) VALUES ($T, 'E2E CAT')");
$catId = (int)$conn->query("SELECT id FROM categories WHERE restaurant_id = $T AND name = 'E2E CAT'")->fetch_assoc()['id'];
$stmt = $conn->prepare("INSERT INTO menu_items (restaurant_id, name, price, status, category_id) VALUES ($T, 'MO:MO', 500.00, 'active', ?), ($T, 'THUKPA', 500.00, 'active', ?)");
$stmt->bind_param("ii", $catId, $catId);
$stmt->execute(); $stmt->close();
$m1 = (int)$conn->query("SELECT id FROM menu_items WHERE restaurant_id = $T AND name = 'MO:MO'")->fetch_assoc()['id'];
$m2 = (int)$conn->query("SELECT id FROM menu_items WHERE restaurant_id = $T AND name = 'THUKPA'")->fetch_assoc()['id'];
$conn->query("INSERT INTO orders (restaurant_id, table_number, status, payment_status, total_amount, discount_amount, ncr_amount, order_type) VALUES ($T, 'E2E1', 'new', 'pending', 1000.00, 0, 0, 'DINE_IN')");
$oid = (int)$conn->query("SELECT id FROM orders WHERE restaurant_id = $T ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
$stmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, price, restaurant_id, ncr_amount) VALUES (?, ?, 1, 500.00, $T, 0), (?, ?, 1, 500.00, $T, 0)");
$stmt->bind_param("iiii", $oid, $m1, $oid, $m2);
$stmt->execute(); $stmt->close();
$conn->query("UPDATE orders SET total_amount = 1000 WHERE id = $oid");

function bill(int $oid, int $pts = 0, bool $ncr = false): array {
    global $conn, $T;
    return BillingService::calculateOrderBill($conn, $T, $oid, $pts, $ncr);
}

echo "\n=== 3. VAT exclusive math (13% + SC 10% on 1000) ===\n";
$b = bill($oid);
check('exclusive: subtotal 1000', approx($b['subtotal'], 1000), (string)$b['subtotal']);
check('exclusive: SC 100', approx($b['service_charge'], 100), (string)$b['service_charge']);
check('exclusive: vat 143', approx($b['vat'], 143), (string)$b['vat']);
check('exclusive: grand 1243', approx($b['grand_total'], 1243), (string)$b['grand_total']);
check('exclusive: pre-loyalty 1243', approx($b['pre_loyalty_total'], 1243));

echo "\n=== 4. VAT inclusive math ===\n";
RestaurantSettingsService::saveSettings($conn, $T, array_merge([], [
    'restaurant_name' => 'E2E TEST', 'payment_note' => '', 'tax_enabled' => 1, 'tax_percentage' => 13,
    'vat_mode' => 'inclusive', 'service_charge_enabled' => 0, 'service_charge_type' => 'percent',
    'service_charge_amount' => 0, 'address' => '', 'phone' => '', 'email' => 'e2e@test.local',
    'pan_vat' => '', 'currency' => 'NPR', 'currency_symbol' => 'Rs.', 'currency_position' => 'left',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 1, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 0, 'max_redemption_points' => 0, 'max_discount_percent' => 20,
    'min_bill_amount' => 0, 'expiration_enabled' => 0, 'expiration_days' => 30, 'earning_basis' => 'grand_total_before_tax',
]));
$b = bill($oid);
check('inclusive: no double-count, grand stays 1000', approx($b['grand_total'], 1000), (string)$b['grand_total']);
check('inclusive: embedded vat 115.04', approx($b['vat'], 115.04), (string)$b['vat']);
check('inclusive: SC 0', approx($b['service_charge'], 0));

echo "\n=== 5. Manual discount (exclusive) ===\n";
RestaurantSettingsService::saveSettings($conn, $T, [
    'restaurant_name' => 'E2E TEST', 'payment_note' => '', 'tax_enabled' => 1, 'tax_percentage' => 13,
    'vat_mode' => 'exclusive', 'service_charge_enabled' => 0, 'service_charge_type' => 'percent',
    'service_charge_amount' => 0, 'address' => '', 'phone' => '', 'email' => 'e2e@test.local',
    'pan_vat' => '', 'currency' => 'NPR', 'currency_symbol' => 'Rs.', 'currency_position' => 'left',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 1, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 0, 'max_redemption_points' => 0, 'max_discount_percent' => 20,
    'min_bill_amount' => 0, 'expiration_enabled' => 0, 'expiration_days' => 30, 'earning_basis' => 'subtotal_after_discounts',
]);
$conn->query("UPDATE orders SET discount_amount = 100 WHERE id = $oid");
$b = bill($oid);
check('discount 100 applied', approx($b['discount'], 100), (string)$b['discount']);
check('discount: base 900', approx($b['subtotal'] - $b['discount'], 900));
check('discount: vat 117', approx($b['vat'], 117), (string)$b['vat']);
check('discount: grand 1017', approx($b['grand_total'], 1017), (string)$b['grand_total']);
$conn->query("UPDATE orders SET discount_amount = 0 WHERE id = $oid");

echo "\n=== 6. Loyalty earning multiplier & basis ===\n";
$b = bill($oid); // exclusive, no SC, no disc, tax 13% -> grand 1130, base 1000
check('earning grand_total_before_tax basis: eligible 1130', approx($b['earning_eligible'], 1130), (string)$b['earning_eligible']);
check('points for 1130 @1pt/100 = 11', LoyaltyService::pointsForEligibleAmount($loy, 1130) === 11, (string)LoyaltyService::pointsForEligibleAmount($loy, 1130));
RestaurantSettingsService::saveSettings($conn, $T, [
    'restaurant_name' => 'E2E TEST', 'payment_note' => '', 'tax_enabled' => 1, 'tax_percentage' => 13,
    'vat_mode' => 'exclusive', 'service_charge_enabled' => 0, 'service_charge_type' => 'percent',
    'service_charge_amount' => 0, 'address' => '', 'phone' => '', 'email' => 'e2e@test.local',
    'pan_vat' => '', 'currency' => 'NPR', 'currency_symbol' => 'Rs.', 'currency_position' => 'left',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 2, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 0, 'max_redemption_points' => 0, 'max_discount_percent' => 20,
    'min_bill_amount' => 0, 'expiration_enabled' => 0, 'expiration_days' => 30, 'earning_basis' => 'subtotal_after_discounts',
]);
$b = bill($oid);
check('basis subtotal_after_discounts: eligible 1000', approx($b['earning_eligible'], 1000), (string)$b['earning_eligible']);
check('2pt/100 on 1000 = 20', LoyaltyService::pointsForEligibleAmount($b['loyalty_settings'], 1000) === 20);
$loy = RestaurantSettingsService::getLoyaltySettings($conn, $T);

echo "\n=== 7. Redemption rules ===\n";
$conn->query("INSERT INTO customers (restaurant_id, name, phone, loyalty_points) VALUES ($T, 'Cust', '9800000000', 100)");
$cid = (int)$conn->query("SELECT id FROM customers WHERE restaurant_id = $T ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];

// point_value now 1 (settings save above used point_value=1)
$r = LoyaltyService::calculateRedemption($conn, $T, $cid, 50, 1130);
check('redeem 50pts on 1130 bill ok', $r['ok'] === true, json_encode($r));
check('redeem capped at 20% = 226 -> 50pts fine', $r['points'] === 50);
$r2 = LoyaltyService::calculateRedemption($conn, $T, $cid, 500, 1130);
check('over-available rejected', $r2['ok'] === false && strpos($r2['message'], 'Insufficient') !== false, $r2['message']);

// max_discount_percent 20% -> max discount 226 on 1130, so 226 points (value 1)
$r3 = LoyaltyService::calculateRedemption($conn, $T, $cid, 100, 1130);
check('100pts ok', $r3['ok'] === true && $r3['points'] === 100);

// min_bill_amount gate
RestaurantSettingsService::saveSettings($conn, $T, [
    'restaurant_name' => 'E2E TEST', 'payment_note' => '', 'tax_enabled' => 1, 'tax_percentage' => 13,
    'vat_mode' => 'exclusive', 'service_charge_enabled' => 0, 'service_charge_type' => 'percent',
    'service_charge_amount' => 0, 'address' => '', 'phone' => '', 'email' => 'e2e@test.local',
    'pan_vat' => '', 'currency' => 'NPR', 'currency_symbol' => 'Rs.', 'currency_position' => 'left',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 2, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 0, 'max_redemption_points' => 0, 'max_discount_percent' => 20,
    'min_bill_amount' => 5000, 'expiration_enabled' => 0, 'expiration_days' => 30, 'earning_basis' => 'subtotal_after_discounts',
]);
$r4 = LoyaltyService::calculateRedemption($conn, $T, $cid, 10, 1130);
check('min-bill 5000 rejects 1130 bill', $r4['ok'] === false && strpos($r4['message'], 'minimum bill') !== false, $r4['message']);
$r5 = LoyaltyService::calculateRedemption($conn, $T, $cid, 10, 6000);
check('min-bill satisfied at 6000', $r5['ok'] === true);

// min_redemption_points gate
RestaurantSettingsService::saveSettings($conn, $T, [
    'restaurant_name' => 'E2E TEST', 'payment_note' => '', 'tax_enabled' => 1, 'tax_percentage' => 13,
    'vat_mode' => 'exclusive', 'service_charge_enabled' => 0, 'service_charge_type' => 'percent',
    'service_charge_amount' => 0, 'address' => '', 'phone' => '', 'email' => 'e2e@test.local',
    'pan_vat' => '', 'currency' => 'NPR', 'currency_symbol' => 'Rs.', 'currency_position' => 'left',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 2, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 50, 'max_redemption_points' => 0, 'max_discount_percent' => 20,
    'min_bill_amount' => 0, 'expiration_enabled' => 0, 'expiration_days' => 30, 'earning_basis' => 'subtotal_after_discounts',
]);
$r6 = LoyaltyService::calculateRedemption($conn, $T, $cid, 10, 1130);
check('min redemption 50 rejects 10', $r6['ok'] === false, $r6['message']);

echo "\n=== 8. Ledger earn/redeem + balance ===\n";
// reset loyalty on/off
RestaurantSettingsService::saveSettings($conn, $T, [
    'restaurant_name' => 'E2E TEST', 'payment_note' => '', 'tax_enabled' => 1, 'tax_percentage' => 13,
    'vat_mode' => 'exclusive', 'service_charge_enabled' => 0, 'service_charge_type' => 'percent',
    'service_charge_amount' => 0, 'address' => '', 'phone' => '', 'email' => 'e2e@test.local',
    'pan_vat' => '', 'currency' => 'NPR', 'currency_symbol' => 'Rs.', 'currency_position' => 'left',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 2, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 0, 'max_redemption_points' => 0, 'max_discount_percent' => 20,
    'min_bill_amount' => 0, 'expiration_enabled' => 1, 'expiration_days' => 30, 'earning_basis' => 'subtotal_after_discounts',
]);
$conn->query("UPDATE customers SET loyalty_points = 100, lifetime_points_earned = 0, lifetime_points_redeemed = 0 WHERE id = $cid");
$earn = LoyaltyService::recordEarning($conn, $T, $cid, $oid, 20, 'earn test');
check('recordEarning ok', $earn['success'] === true, json_encode($earn));
$bal = (int)$conn->query("SELECT loyalty_points FROM customers WHERE id = $cid")->fetch_assoc()['loyalty_points'];
check('balance 120 after +20', $bal === 120, (string)$bal);
$red = LoyaltyService::recordRedemption($conn, $T, $cid, $oid, 30, 30, 'redeem test');
check('recordRedemption ok', $red['success'] === true, json_encode($red));
$bal = (int)$conn->query("SELECT loyalty_points FROM customers WHERE id = $cid")->fetch_assoc()['loyalty_points'];
check('balance 90 after -30', $bal === 90, (string)$bal);
$cnt = (int)$conn->query("SELECT COUNT(*) c FROM loyalty_transactions WHERE restaurant_id = $T AND type IN ('earn','redeem')")->fetch_assoc()['c'];
check('2 ledger rows', $cnt === 2, (string)$cnt);

echo "\n=== 9. Expiry sweep ===\n";
// create an expired earn lot directly (expiration_date yesterday, unprocessed)
$conn->query("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, expiration_date, notes, idempotency_key, created_at)
              VALUES ($T, $cid, NULL, 'earn', 50, 50.00, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'expired lot test', 'EXP-LOT-1', NOW())");
$conn->query("UPDATE customers SET loyalty_points = loyalty_points + 50 WHERE id = $cid");
$bal = (int)$conn->query("SELECT loyalty_points FROM customers WHERE id = $cid")->fetch_assoc()['loyalty_points'];
check('balance 140 with stale 50', $bal === 140, (string)$bal);
$sweep = LoyaltyService::sweepExpiredPoints($conn, $T, $cid);
check('sweep found 1 lot', $sweep['expired_lots'] === 1, json_encode($sweep));
check('sweep expired 50', $sweep['points_expired'] === 50);
$bal = (int)$conn->query("SELECT loyalty_points FROM customers WHERE id = $cid")->fetch_assoc()['loyalty_points'];
check('balance back to 90 after sweep', $bal === 90, (string)$bal);
$expRow = $conn->query("SELECT * FROM loyalty_transactions WHERE restaurant_id = $T AND type = 'expired'")->fetch_assoc();
check('expired ledger row written', $expRow !== false && (int)$expRow['points'] === -50);
check('order_id NULL on expired row', $expRow['order_id'] === null);
check('idempotency key set', $expRow['idempotency_key'] === 'expire_' . $T . '_' . (int)$expRow['id'], $expRow['idempotency_key']);
$processed = (int)$conn->query("SELECT COUNT(*) c FROM loyalty_transactions WHERE restaurant_id = $T AND id = " . (int)$expRow['id'] . " AND expiry_processed_at IS NULL")->fetch_assoc()['c'];
check('original lot marked processed', $processed === 0);
$sweep2 = LoyaltyService::sweepExpiredPoints($conn, $T, $cid);
check('second sweep idempotent (0 lots)', $sweep2['expired_lots'] === 0, json_encode($sweep2));

echo "\n=== 10. Currency & formatting ===\n";
$f = RestaurantSettingsService::formatMoneyFor($conn, $T, 1234.5);
check('formatMoneyFor uses Rs. left', $f === 'Rs. 1,234.50', $f);
RestaurantSettingsService::saveSettings($conn, $T, [
    'restaurant_name' => 'E2E TEST', 'payment_note' => '', 'tax_enabled' => 1, 'tax_percentage' => 13,
    'vat_mode' => 'exclusive', 'service_charge_enabled' => 0, 'service_charge_type' => 'percent',
    'service_charge_amount' => 0, 'address' => '', 'phone' => '', 'email' => 'e2e@test.local',
    'pan_vat' => '', 'currency' => 'USD', 'currency_symbol' => '$', 'currency_position' => 'right',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 2, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 0, 'max_redemption_points' => 0, 'max_discount_percent' => 20,
    'min_bill_amount' => 0, 'expiration_enabled' => 0, 'expiration_days' => 30, 'earning_basis' => 'subtotal_after_discounts',
]);
$f2 = RestaurantSettingsService::formatMoneyFor($conn, $T, 99.9);
check('symbol right + $', $f2 === '99.90 $', $f2);
$b = bill($oid);
check('bill carries currency fields', $b['currency'] === 'USD' && $b['currency_symbol'] === '$' && $b['currency_position'] === 'right');
check('bill formatted uses tenant symbol', $b['formatted']['grand_total'] === '1,130.00 $', $b['formatted']['grand_total']);
// restore NPR for remaining
RestaurantSettingsService::saveSettings($conn, $T, [
    'restaurant_name' => 'E2E TEST', 'payment_note' => '', 'tax_enabled' => 1, 'tax_percentage' => 13,
    'vat_mode' => 'exclusive', 'service_charge_enabled' => 0, 'service_charge_type' => 'percent',
    'service_charge_amount' => 0, 'address' => '', 'phone' => '', 'email' => 'e2e@test.local',
    'pan_vat' => '', 'currency' => 'NPR', 'currency_symbol' => 'Rs.', 'currency_position' => 'left',
    'timezone' => 'Asia/Kathmandu', 'loyalty_enabled' => 1, 'earning_points' => 2, 'earn_spend_amount' => 100,
    'point_value' => 1, 'min_redemption_points' => 0, 'max_redemption_points' => 0, 'max_discount_percent' => 20,
    'min_bill_amount' => 0, 'expiration_enabled' => 0, 'expiration_days' => 30, 'earning_basis' => 'subtotal_after_discounts',
]);

echo "\n=== 11. NCR ===\n";
$b = bill($oid, 0, true);
check('whole-order NCR: grand 0', approx($b['grand_total'], 0), (string)$b['grand_total']);
check('whole-order NCR: ncr_amount = preLoyalty 1130', approx($b['ncr_amount'], 1130), (string)$b['ncr_amount']);
// item-level NCR: flag one item
$conn->query("UPDATE order_items SET ncr_amount = 500 WHERE order_id = $oid AND menu_item_id = $m1");
$b = bill($oid);
check('item NCR: subtotal 500 (one item excluded)', approx($b['subtotal'], 500), (string)$b['subtotal']);
check('item NCR: grand 565', approx($b['grand_total'], 565), (string)$b['grand_total']);
$conn->query("UPDATE order_items SET ncr_amount = 0 WHERE order_id = $oid AND menu_item_id = $m1");

echo "\n=== 12. Tenant isolation ===\n";
$p1 = RestaurantSettingsService::getPaymentSettings($conn, 1);
check('tenant 1 untouched by scratch save', $p1['restaurant_name'] !== 'E2E TEST', $p1['restaurant_name']);
$conn->query("DELETE FROM payment_settings WHERE restaurant_id = 1");
RestaurantSettingsService::getPaymentSettings($conn, 1);
$p1 = RestaurantSettingsService::getPaymentSettings($conn, 1);
check('tenant 1 re-provisions cleanly', $p1['restaurant_name'] !== 'E2E TEST', $p1['restaurant_name']);
check('scratch tenant still intact', RestaurantSettingsService::getPaymentSettings($conn, $T)['currency_symbol'] === 'Rs.');

echo "\n=== 13. formatMoney backward compat ===\n";
check('formatMoney default Rs. left', BillingService::formatMoney(1234.5) === 'Rs. 1,234.50', BillingService::formatMoney(1234.5));
check('formatMoney right', BillingService::formatMoney(99.9, 'USD', 'right') === '99.90 USD', BillingService::formatMoney(99.9, 'USD', 'right'));

// ---------------------------------------------------------------------------
echo "\n=====================================================\n";
echo "RESULT: $pass passed, $fail failed\n";

// cleanup scratch tenant
$conn->query("DELETE FROM payment_transactions WHERE restaurant_id = $T");
$conn->query("DELETE FROM loyalty_transactions WHERE restaurant_id = $T");
$conn->query("DELETE FROM order_items WHERE restaurant_id = $T");
$conn->query("DELETE FROM orders WHERE restaurant_id = $T");
$conn->query("DELETE FROM menu_items WHERE restaurant_id = $T");
$conn->query("DELETE FROM categories WHERE restaurant_id = $T");
$conn->query("DELETE FROM customers WHERE restaurant_id = $T");
$conn->query("DELETE FROM payment_settings WHERE restaurant_id = $T");
$conn->query("DELETE FROM restaurant_loyalty_settings WHERE restaurant_id = $T");
$conn->query("DELETE FROM restaurants WHERE id = $T");

exit($fail === 0 ? 0 : 1);
