<?php
// admin/settings.php - Centralized Restaurant Settings Configuration
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
$conn = getDBConnection();

// Ensure loyalty settings table exists
if ($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS restaurant_loyalty_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id INT NOT NULL UNIQUE,
        is_enabled TINYINT(1) DEFAULT 1,
        earn_spend_amount DECIMAL(10,2) DEFAULT 100.00,
        point_value DECIMAL(10,2) DEFAULT 1.00,
        min_redemption_points INT DEFAULT 100,
        max_redemption_points INT DEFAULT 500,
        max_discount_percent DECIMAL(5,2) DEFAULT 20.00,
        expiration_enabled TINYINT(1) DEFAULT 0,
        expiration_days INT DEFAULT 365,
        earning_basis VARCHAR(50) DEFAULT 'subtotal_after_discounts',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_loyalty_tenant (restaurant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$currentPage = 'settings';
$message = '';

// Handle POST Settings Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();

    $name = Security::sanitize($_POST['restaurant_name'] ?? 'RMS Restaurant');
    $note = Security::sanitize($_POST['payment_note'] ?? '');
    $taxEnabled = isset($_POST['tax_enabled']) ? 1 : 0;
    $taxPercent = (float)($_POST['tax_percentage'] ?? 13.0);
    $scEnabled = isset($_POST['service_charge_enabled']) ? 1 : 0;
    $scPercent = (float)($_POST['service_charge_amount'] ?? 10.0);

    // Loyalty settings
    $loyaltyEnabled = isset($_POST['loyalty_enabled']) ? 1 : 0;
    $earnSpendAmount = max(0.01, (float)($_POST['earn_spend_amount'] ?? 100.0));
    $pointValue = max(0.01, (float)($_POST['point_value'] ?? 1.0));
    $minRedemptionPoints = max(0, (int)($_POST['min_redemption_points'] ?? 100));
    $maxRedemptionPoints = max(0, (int)($_POST['max_redemption_points'] ?? 500));
    $maxDiscountPercent = max(0.0, min(100.0, (float)($_POST['max_discount_percent'] ?? 20.0)));
    $expirationEnabled = isset($_POST['expiration_enabled']) ? 1 : 0;
    $expirationDays = max(1, (int)($_POST['expiration_days'] ?? 365));
    $earningBasis = Security::sanitize($_POST['earning_basis'] ?? 'subtotal_after_discounts');

    $stmt = $conn->prepare("UPDATE payment_settings SET restaurant_name = ?, payment_note = ?, tax_enabled = ?, tax_percentage = ?, service_charge_enabled = ?, service_charge_amount = ? WHERE id = 1");
    $stmt->bind_param("ssiddi", $name, $note, $taxEnabled, $taxPercent, $scEnabled, $scPercent);
    if ($stmt->execute()) {
        $message = "Restaurant settings updated successfully!";
    }
    $stmt->close();

    // Save loyalty settings
    $stmt = $conn->prepare("
        INSERT INTO restaurant_loyalty_settings 
        (restaurant_id, is_enabled, earn_spend_amount, point_value, min_redemption_points, max_redemption_points, max_discount_percent, expiration_enabled, expiration_days, earning_basis)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        is_enabled = VALUES(is_enabled),
        earn_spend_amount = VALUES(earn_spend_amount),
        point_value = VALUES(point_value),
        min_redemption_points = VALUES(min_redemption_points),
        max_redemption_points = VALUES(max_redemption_points),
        max_discount_percent = VALUES(max_discount_percent),
        expiration_enabled = VALUES(expiration_enabled),
        expiration_days = VALUES(expiration_days),
        earning_basis = VALUES(earning_basis),
        updated_at = NOW()
    ");
    $stmt->bind_param("iidddiidds", $tenantId, $loyaltyEnabled, $earnSpendAmount, $pointValue, $minRedemptionPoints, $maxRedemptionPoints, $maxDiscountPercent, $expirationEnabled, $expirationDays, $earningBasis);
    $stmt->execute();
    $stmt->close();

    // Log audit
    Security::logAudit('LOYALTY_SETTINGS_UPDATED', "Loyalty settings updated by admin. Enabled: $loyaltyEnabled, Earn rate: $earnSpendAmount, Point value: $pointValue");
}

// Fetch Settings
$sett = [
    'restaurant_name' => 'RMS Restaurant',
    'payment_note' => 'Scan QR to pay via Esewa / Khalti',
    'tax_enabled' => 1,
    'tax_percentage' => 13.0,
    'service_charge_enabled' => 1,
    'service_charge_amount' => 10.0
];
$res = $conn->query("SELECT * FROM payment_settings WHERE id = 1 LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $sett = array_merge($sett, $row);
}

// Fetch Loyalty Settings
$loyalty = [
    'is_enabled' => 1,
    'earn_spend_amount' => 100.00,
    'point_value' => 1.00,
    'min_redemption_points' => 100,
    'max_redemption_points' => 500,
    'max_discount_percent' => 20.00,
    'expiration_enabled' => 0,
    'expiration_days' => 365,
    'earning_basis' => 'subtotal_after_discounts'
];
$res = $conn->query("SELECT * FROM restaurant_loyalty_settings WHERE restaurant_id = $tenantId LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $loyalty = array_merge($loyalty, $row);
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 font-sans antialiased text-white selection:bg-amber-500 selection:text-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Settings — RMS SaaS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { amber: { 500: '#f59e0b', 600: '#d97706' } } } } }
    </script>
</head>
<body class="min-h-full pb-12 font-sans antialiased">
    <?php include 'includes/sidebar.php'; ?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5 flex items-center justify-between">
            <div>
                <h1 class="text-lg md:text-xl font-black text-white">Centralized Restaurant Settings</h1>
                <p class="text-xs text-zinc-400">Configure Tenant Details, Tax (VAT), Service Charge, Receipts, Loyalty & POS Behavior</p>
            </div>
            <button onclick="document.getElementById('settingsForm').submit()" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                💾 Save Settings
            </button>
        </header>

        <main class="max-w-4xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <?php if ($message): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">✅ <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <form id="settingsForm" method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-6">
                <?= CSRF::getField() ?>

                <div class="space-y-4">
                    <h2 class="text-sm font-black text-white border-b border-zinc-800 pb-2">1. General Restaurant Info</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div>
                            <label class="block font-bold text-zinc-300 mb-1">Restaurant Name</label>
                            <input type="text" name="restaurant_name" value="<?= htmlspecialchars($sett['restaurant_name']) ?>" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block font-bold text-zinc-300 mb-1">Currency & Region</label>
                            <input type="text" value="NPR (Nepali Rupee - Rs.)" disabled class="w-full h-10 bg-zinc-950/60 border border-zinc-800/60 rounded-xl px-3 text-zinc-400 font-bold outline-none cursor-not-allowed">
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <h2 class="text-sm font-black text-white border-b border-zinc-800 pb-2">2. Tax & Service Charge Configuration</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-white">Value Added Tax (VAT)</span>
                                <input type="checkbox" name="tax_enabled" value="1" <?= $sett['tax_enabled'] ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-zinc-400 mb-1">Tax Percentage (%)</label>
                                <input type="number" step="0.1" name="tax_percentage" value="<?= htmlspecialchars($sett['tax_percentage']) ?>" class="w-full h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500">
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-white">Service Charge</span>
                                <input type="checkbox" name="service_charge_enabled" value="1" <?= $sett['service_charge_enabled'] ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-zinc-400 mb-1">Service Charge Percentage (%)</label>
                                <input type="number" step="0.1" name="service_charge_amount" value="<?= htmlspecialchars($sett['service_charge_amount']) ?>" class="w-full h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <h2 class="text-sm font-black text-white border-b border-zinc-800 pb-2">3. Payment & Receipt Note</h2>
                    <div class="text-xs">
                        <label class="block font-bold text-zinc-300 mb-1">Footer Payment Instructions on Receipt</label>
                        <textarea name="payment_note" rows="3" class="w-full bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-white outline-none focus:border-amber-500"><?= htmlspecialchars($sett['payment_note']) ?></textarea>
                    </div>
                </div>

                <div class="space-y-4">
                    <h2 class="text-sm font-black text-white border-b border-zinc-800 pb-2">4. 🎁 Loyalty Program Settings</h2>
                    
                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-4">
                        <!-- Enable/Disable -->
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="font-bold text-white">Enable Loyalty Program</span>
                                <p class="text-[11px] text-zinc-500">Allow customers to earn and redeem loyalty points</p>
                            </div>
                            <input type="checkbox" name="loyalty_enabled" value="1" <?= $loyalty['is_enabled'] ? 'checked' : '' ?> class="w-5 h-5 accent-amber-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <!-- Point Earning -->
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Point Earning</h3>
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-white">Customers earn</span>
                                <input type="number" step="1" min="1" name="earn_spend_amount" value="<?= htmlspecialchars($loyalty['earn_spend_amount']) ?>" class="w-20 h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500 text-center">
                                <span class="font-bold text-white">point for every</span>
                                <input type="number" step="0.01" min="0.01" name="earn_currency_amount" value="<?= htmlspecialchars($loyalty['earn_spend_amount']) ?>" class="w-24 h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500 text-center">
                                <span class="font-bold text-white">spent</span>
                            </div>
                            <p class="text-[10px] text-zinc-500">Example: 1 point per NPR 100 spent</p>
                        </div>

                        <!-- Point Value -->
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Point Value</h3>
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-white">1 point =</span>
                                <input type="number" step="0.01" min="0.01" name="point_value" value="<?= htmlspecialchars($loyalty['point_value']) ?>" class="w-24 h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500 text-center">
                                <span class="font-bold text-white">discount</span>
                            </div>
                            <p class="text-[10px] text-zinc-500">Monetary value of 1 loyalty point</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                        <!-- Min Redemption -->
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Minimum Redemption</h3>
                            <label class="block text-[11px] font-semibold text-zinc-400">Min points required</label>
                            <input type="number" step="1" min="0" name="min_redemption_points" value="<?= htmlspecialchars($loyalty['min_redemption_points']) ?>" class="w-full h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500">
                            <p class="text-[10px] text-zinc-500">Customer must have at least this many points</p>
                        </div>

                        <!-- Max Redemption Per Bill -->
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Max Points Per Bill</h3>
                            <label class="block text-[11px] font-semibold text-zinc-400">Max points redeemable</label>
                            <input type="number" step="1" min="0" name="max_redemption_points" value="<?= htmlspecialchars($loyalty['max_redemption_points']) ?>" class="w-full h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500">
                            <p class="text-[10px] text-zinc-500">0 = Unlimited</p>
                        </div>

                        <!-- Max Discount % -->
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Max Bill Discount %</h3>
                            <label class="block text-[11px] font-semibold text-zinc-400">Maximum discount</label>
                            <div class="flex items-center gap-1">
                                <input type="number" step="1" min="0" max="100" name="max_discount_percent" value="<?= htmlspecialchars($loyalty['max_discount_percent']) ?>" class="w-20 h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500 text-center">
                                <span class="font-bold text-white">%</span>
                            </div>
                            <p class="text-[10px] text-zinc-500">Max loyalty discount as % of bill</p>
                        </div>
                    </div>

                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                        <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Point Expiration</h3>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="expiration_enabled" value="0" <?= !$loyalty['expiration_enabled'] ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                                <span class="text-xs font-bold text-white">Never expire</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="expiration_enabled" value="1" <?= $loyalty['expiration_enabled'] ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                                <span class="text-xs font-bold text-white">Expire after</span>
                                <input type="number" step="1" min="1" name="expiration_days" value="<?= htmlspecialchars($loyalty['expiration_days']) ?>" class="w-16 h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500 text-center">
                                <span class="text-xs font-bold text-white">days</span>
                            </label>
                        </div>
                        <p class="text-[10px] text-zinc-500">Points earned on a specific date expire after the configured period</p>
                    </div>

                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                        <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Point Calculation Basis</h3>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="earning_basis" value="subtotal_after_discounts" <?= $loyalty['earning_basis'] === 'subtotal_after_discounts' ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                                <span class="text-xs font-medium text-white">Subtotal after discounts (Recommended)</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="earning_basis" value="subtotal_plus_service_charge" <?= $loyalty['earning_basis'] === 'subtotal_plus_service_charge' ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                                <span class="text-xs font-medium text-white">Subtotal + Service Charge</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="earning_basis" value="grand_total_before_tax" <?= $loyalty['earning_basis'] === 'grand_total_before_tax' ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                                <span class="text-xs font-medium text-white">Subtotal + Tax + Service Charge</span>
                            </label>
                        </div>
                        <p class="text-[10px] text-zinc-500">Tax should normally not generate loyalty points</p>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full py-3 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                        💾 Save Restaurant Settings
                    </button>
                </div>
            </form>
        </main>
    </div>

    <script>
        // Sync earn_spend_amount and earn_currency_amount
        document.addEventListener('DOMContentLoaded', function() {
            const earnSpendInput = document.querySelector('input[name="earn_spend_amount"]');
            const earnCurrencyInput = document.querySelector('input[name="earn_currency_amount"]');
            
            if (earnSpendInput && earnCurrencyInput) {
                earnSpendInput.addEventListener('input', function() {
                    earnCurrencyInput.value = this.value;
                });
                earnCurrencyInput.addEventListener('input', function() {
                    earnSpendInput.value = this.value;
                });
            }
        });
    </script>
</body>
</html>
