<?php
// admin/settings.php - Centralized Restaurant Settings Configuration
// Every value is read/persisted through RestaurantSettingsService (the single
// source of truth), which enforces tenant scoping, validation, provisioning and
// audit logging server-side. The UI never writes to the database directly.
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
$conn = getDBConnection();

$currentPage = 'settings';
$success = '';
$errors = [];

// Single save handler: all settings are persisted atomically by the service.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();
    $result = RestaurantSettingsService::saveSettings($conn, $tenantId, $_POST, $_FILES);
    if ($result['success']) {
        $success = $result['message'];
    } else {
        $errors = $result['errors'];
    }
}

$sett = $conn ? RestaurantSettingsService::getPaymentSettings($conn, $tenantId) : [];
$loyalty = $conn ? RestaurantSettingsService::getLoyaltySettings($conn, $tenantId) : [];

$logoUrl = '';
if (!empty($sett['logo'])) {
    $logoUrl = '../' . ltrim((string)$sett['logo'], '/');
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
                <p class="text-xs text-zinc-400">Configure Restaurant Info, Tax (VAT), Service Charge, Receipts, Loyalty & POS Behavior</p>
            </div>
            <button type="button" id="stickySaveBtn" onclick="saveSettings()"
                    class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20 disabled:opacity-50 disabled:cursor-not-allowed">
                💾 Save Settings
            </button>
        </header>

        <main class="max-w-4xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <?php if ($success): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">✅ <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="p-3.5 rounded-2xl bg-red-500/10 border border-red-500/30 text-red-400 text-xs font-bold space-y-1">
                    <div>❌ Settings could not be saved:</div>
                    <ul class="list-disc list-inside font-semibold">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form id="settingsForm" method="POST" enctype="multipart/form-data" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-6"
                  onsubmit="event.preventDefault(); saveSettings();">
                <?= CSRF::getField() ?>

                <div class="space-y-4" data-section="1">
                    <h2 class="text-sm font-black text-white border-b border-zinc-800 pb-2">1. Restaurant Info</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div>
                            <label class="block font-bold text-zinc-300 mb-1">Restaurant Name</label>
                            <input type="text" name="restaurant_name" value="<?= htmlspecialchars($sett['restaurant_name']) ?>" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block font-bold text-zinc-300 mb-1">Logo</label>
                            <input type="file" name="logo" id="logoInput" accept="image/jpeg,image/png,image/webp"
                                   class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-2 text-[11px] text-zinc-400 file:mr-2 file:rounded-lg file:border-0 file:bg-amber-500 file:px-3 file:py-2 file:text-zinc-950 file:font-bold file:text-[11px] file:cursor-pointer">
                            <?php if ($logoUrl): ?>
                                <div class="mt-2 flex items-center gap-2">
                                    <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="w-10 h-10 rounded-lg object-cover border border-zinc-700" id="logoPreview">
                                    <span class="text-[10px] text-zinc-500">Current logo (JPG/PNG/WEBP, max 5MB)</span>
                                </div>
                            <?php else: ?>
                                <div class="mt-2 hidden" id="logoPreviewWrap">
                                    <img id="logoPreview" alt="Logo preview" class="w-10 h-10 rounded-lg object-cover border border-zinc-700">
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block font-bold text-zinc-300 mb-1">Address</label>
                                <input type="text" name="address" value="<?= htmlspecialchars($sett['address']) ?>" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                            </div>
                            <div>
                                <label class="block font-bold text-zinc-300 mb-1">Phone</label>
                                <input type="text" name="phone" value="<?= htmlspecialchars($sett['phone']) ?>" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                            </div>
                            <div>
                                <label class="block font-bold text-zinc-300 mb-1">Email</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($sett['email']) ?>" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                            </div>
                            <div>
                                <label class="block font-bold text-zinc-300 mb-1">PAN / VAT No.</label>
                                <input type="text" name="pan_vat" value="<?= htmlspecialchars($sett['pan_vat']) ?>" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                            </div>
                        </div>
                        <div>
                            <label class="block font-bold text-zinc-300 mb-1">Currency Code</label>
                            <input type="text" name="currency" maxlength="10" value="<?= htmlspecialchars($sett['currency']) ?>" placeholder="NPR" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block font-bold text-zinc-300 mb-1">Currency Symbol</label>
                            <input type="text" name="currency_symbol" maxlength="10" value="<?= htmlspecialchars($sett['currency_symbol']) ?>" placeholder="Rs." class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block font-bold text-zinc-300 mb-1">Currency Symbol Position</label>
                            <div class="flex items-center gap-4 h-10">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="currency_position" value="left" <?= $sett['currency_position'] === 'right' ? '' : 'checked' ?> class="w-4 h-4 accent-amber-500">
                                    <span class="font-bold text-white">Left (Rs. 100)</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="currency_position" value="right" <?= $sett['currency_position'] === 'right' ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                                    <span class="font-bold text-white">Right (100 Rs.)</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block font-bold text-zinc-300 mb-1">Timezone</label>
                            <input type="text" name="timezone" list="tzList" maxlength="64" value="<?= htmlspecialchars($sett['timezone']) ?>" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                            <datalist id="tzList">
                                <option value="Asia/Kathmandu"></option>
                                <option value="Asia/Kolkata"></option>
                                <option value="Asia/Dhaka"></option>
                                <option value="Asia/Karachi"></option>
                                <option value="Asia/Dubai"></option>
                                <option value="Asia/Singapore"></option>
                                <option value="UTC"></option>
                                <option value="America/New_York"></option>
                                <option value="Europe/London"></option>
                            </datalist>
                        </div>
                    </div>
                </div>

                <div class="space-y-4" data-section="2">
                    <h2 class="text-sm font-black text-white border-b border-zinc-800 pb-2">2. Tax (VAT) & Service Charge Configuration</h2>

                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-white">VAT Calculation Mode</span>
                        </div>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="vat_mode" value="exclusive" <?= $sett['vat_mode'] === 'inclusive' ? '' : 'checked' ?> class="w-4 h-4 accent-amber-500">
                                <span class="text-xs font-bold text-white">Exclusive — Menu prices exclude VAT</span>
                                <span class="text-[10px] text-zinc-500">VAT is added on top of the bill.</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="vat_mode" value="inclusive" <?= $sett['vat_mode'] === 'inclusive' ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                                <span class="text-xs font-bold text-white">Inclusive — Menu prices already include VAT</span>
                                <span class="text-[10px] text-zinc-500">The embedded VAT is shown separately; customers pay the menu price.</span>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-white">Value Added Tax (VAT)</span>
                                <input type="checkbox" name="tax_enabled" value="1" <?= $sett['tax_enabled'] ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-zinc-400 mb-1">Tax Percentage (%)</label>
                                <input type="number" step="0.1" min="0" max="100" name="tax_percentage" value="<?= htmlspecialchars($sett['tax_percentage']) ?>" class="w-full h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500">
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-white">Service Charge</span>
                                <input type="checkbox" name="service_charge_enabled" value="1" <?= $sett['service_charge_enabled'] ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500">
                            </div>
                            <div class="flex items-center gap-3">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="service_charge_type" value="percent" <?= $sett['service_charge_type'] === 'fixed' ? '' : 'checked' ?> class="w-3.5 h-3.5 accent-amber-500">
                                    <span class="font-bold text-white">Percent</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="service_charge_type" value="fixed" <?= $sett['service_charge_type'] === 'fixed' ? 'checked' : '' ?> class="w-3.5 h-3.5 accent-amber-500">
                                    <span class="font-bold text-white">Fixed</span>
                                </label>
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-zinc-400 mb-1">Service Charge Amount</label>
                                <input type="number" step="0.1" min="0" max="100" name="service_charge_amount" value="<?= htmlspecialchars($sett['service_charge_amount']) ?>" class="w-full h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500">
                            </div>
                            <p class="text-[10px] text-zinc-500">Applied to the net bill after item, order & loyalty discounts.</p>
                        </div>
                    </div>
                </div>

                <div class="space-y-4" data-section="3">
                    <h2 class="text-sm font-black text-white border-b border-zinc-800 pb-2">3. Payment & Receipt Note</h2>
                    <div class="text-xs">
                        <label class="block font-bold text-zinc-300 mb-1">Footer Payment Instructions on Receipt</label>
                        <textarea name="payment_note" rows="3" class="w-full bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-white outline-none focus:border-amber-500"><?= htmlspecialchars($sett['payment_note']) ?></textarea>
                    </div>
                </div>

                <div class="space-y-4" data-section="4">
                    <h2 class="text-sm font-black text-white border-b border-zinc-800 pb-2">4. 🎁 Loyalty Program Settings</h2>

                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="font-bold text-white">Enable Loyalty Program</span>
                                <p class="text-[11px] text-zinc-500">Allow customers to earn and redeem loyalty points</p>
                            </div>
                            <input type="checkbox" name="loyalty_enabled" value="1" <?= $loyalty['is_enabled'] ? 'checked' : '' ?> class="w-5 h-5 accent-amber-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Point Earning Rate</h3>
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-white">Customers earn</span>
                                <input type="number" step="1" min="1" name="earning_points" id="earningPoints" value="<?= htmlspecialchars($loyalty['earning_points']) ?>" class="w-16 h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-2 text-white font-bold outline-none focus:border-amber-500 text-center">
                                <span class="font-bold text-white">point(s) for every</span>
                                <input type="number" step="0.01" min="0.01" name="earn_spend_amount" id="earnSpendAmount" value="<?= htmlspecialchars($loyalty['earn_spend_amount']) ?>" class="w-24 h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-2 text-white font-bold outline-none focus:border-amber-500 text-center">
                                <span class="font-bold text-white">spent</span>
                            </div>
                            <p class="text-[10px] text-zinc-500">Example: <span id="earnExample"><?= htmlspecialchars($loyalty['earning_points']) ?> point per <?= htmlspecialchars($loyalty['earn_spend_amount']) ?> spent</span></p>
                        </div>

                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Point Value</h3>
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-white">1 point =</span>
                                <input type="number" step="0.01" min="0.01" name="point_value" id="pointValue" value="<?= htmlspecialchars($loyalty['point_value']) ?>" class="w-24 h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500 text-center">
                                <span class="font-bold text-white">discount</span>
                            </div>
                            <p class="text-[10px] text-zinc-500">Monetary value of 1 loyalty point</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Minimum Redemption</h3>
                            <label class="block text-[11px] font-semibold text-zinc-400">Min points required</label>
                            <input type="number" step="1" min="0" name="min_redemption_points" value="<?= htmlspecialchars($loyalty['min_redemption_points']) ?>" class="w-full h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500">
                            <p class="text-[10px] text-zinc-500">Customer must have at least this many points</p>
                        </div>

                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Max Points Per Bill</h3>
                            <label class="block text-[11px] font-semibold text-zinc-400">Max points redeemable</label>
                            <input type="number" step="1" min="0" name="max_redemption_points" value="<?= htmlspecialchars($loyalty['max_redemption_points']) ?>" class="w-full h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500">
                            <p class="text-[10px] text-zinc-500">0 = Unlimited</p>
                        </div>

                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Max Bill Discount %</h3>
                            <label class="block text-[11px] font-semibold text-zinc-400">Maximum discount</label>
                            <div class="flex items-center gap-1">
                                <input type="number" step="1" min="0" max="100" name="max_discount_percent" value="<?= htmlspecialchars($loyalty['max_discount_percent']) ?>" class="w-20 h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500 text-center">
                                <span class="font-bold text-white">%</span>
                            </div>
                            <p class="text-[10px] text-zinc-500">Max loyalty discount as % of bill</p>
                        </div>

                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
                            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider">Minimum Bill for Redemption</h3>
                            <label class="block text-[11px] font-semibold text-zinc-400">Bill must reach this amount</label>
                            <input type="number" step="0.01" min="0" name="min_bill_amount" value="<?= htmlspecialchars($loyalty['min_bill_amount']) ?>" class="w-full h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500">
                            <p class="text-[10px] text-zinc-500">Customers cannot redeem points below this bill value</p>
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
                    <button type="submit" id="bottomSaveBtn"
                            class="w-full py-3 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20 disabled:opacity-50 disabled:cursor-not-allowed">
                        💾 Save Restaurant Settings
                    </button>
                </div>
            </form>
        </main>
    </div>

    <script>
        (function () {
            const form = document.getElementById('settingsForm');
            const stickyBtn = document.getElementById('stickySaveBtn');
            const bottomBtn = document.getElementById('bottomSaveBtn');

            // ---- Single save handler (shared by header + bottom buttons) ----
            window.saveSettings = function () {
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }
                stickyBtn.disabled = true;
                bottomBtn.disabled = true;
                stickyBtn.textContent = '💾 Saving…';
                bottomBtn.textContent = '💾 Saving…';
                form.submit();
            };

            // ---- Dirty-state tracking (no silent data loss when navigating away) ----
            let dirty = false;
            form.querySelectorAll('input, textarea, select').forEach(function (el) {
                el.addEventListener('input', markDirty);
                el.addEventListener('change', markDirty);
            });
            function markDirty() { dirty = true; }
            window.addEventListener('beforeunload', function (e) {
                if (dirty) {
                    e.preventDefault();
                    e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                }
            });
            // Clear dirty flag once a save actually submits
            form.addEventListener('submit', function () { dirty = false; });

            // ---- Live example: earning rate ----
            const earnPoints = document.getElementById('earningPoints');
            const earnSpend = document.getElementById('earnSpendAmount');
            const earnExample = document.getElementById('earnExample');
            function updateEarnExample() {
                const p = earnPoints.value || '1';
                const s = earnSpend.value || '100';
                earnExample.textContent = p + ' point' + (parseInt(p, 10) === 1 ? '' : 's') + ' per ' + s + ' spent';
            }
            if (earnPoints) earnPoints.addEventListener('input', updateEarnExample);
            if (earnSpend) earnSpend.addEventListener('input', updateEarnExample);

            // ---- Logo preview ----
            const logoInput = document.getElementById('logoInput');
            const logoPreview = document.getElementById('logoPreview');
            if (logoInput) {
                logoInput.addEventListener('change', function () {
                    const file = this.files && this.files[0];
                    if (!file) return;
                    if (file.size > 5 * 1024 * 1024) {
                        alert('Logo exceeds the 5MB limit.');
                        this.value = '';
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        logoPreview.src = e.target.result;
                        const wrap = document.getElementById('logoPreviewWrap');
                        if (wrap) wrap.classList.remove('hidden');
                    };
                    reader.readAsDataURL(file);
                });
            }
        })();
    </script>
</body>
</html>
