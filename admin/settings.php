<?php
// admin/settings.php - Multi-Tab Restaurant Settings UI (Phase 1)
require_once __DIR__ . '/../config.php';
requireAdminLogin();
RBAC::requirePermission('manage_settings');

$currentPage = 'settings';
$tenantId = TenantContext::getTenantId();
$settings = CalculationEngine::getSettings($tenantId);

$conn = getDBConnection();
$restName = 'Restaurant Workspace';
$rRes = $conn->query("SELECT restaurant_name FROM restaurants WHERE id = $tenantId LIMIT 1");
if ($rRes && $row = $rRes->fetch_assoc()) {
    $restName = $row['restaurant_name'];
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Restaurant Settings - <?= htmlspecialchars($restName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              amber: { 500: '#f59e0b', 600: '#d97706' }
            }
          }
        }
      }
    </script>
    <style>
        body { overscroll-behavior-y: contain; -webkit-tap-highlight-color: transparent; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="min-h-full pb-20 md:pb-8 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <div class="flex min-h-screen">
        <?php include 'includes/sidebar.php'; ?>

        <main class="flex-1 md:pl-64">
            <!-- HEADER BAR -->
            <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-lg md:text-xl font-black text-white flex items-center gap-2">
                        <span>⚙️</span> Restaurant Settings
                    </h1>
                    <p class="text-xs text-zinc-400">Configure General, POS, Kitchen, and QR Ordering rules for <?= htmlspecialchars($restName) ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="saveRestaurantSettings()" id="saveBtn" class="h-10 px-5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 transition-all flex items-center gap-2 shadow-lg shadow-amber-500/20">
                        <span>💾</span> <span>Save Settings</span>
                    </button>
                </div>
            </header>

            <div class="p-4 md:p-8 max-w-5xl mx-auto space-y-6">

                <!-- TABS HEADER -->
                <div class="flex items-center gap-2 border-b border-zinc-800 pb-2 overflow-x-auto no-scrollbar">
                    <button onclick="switchTab('general')" id="tabBtn-general" class="tab-btn px-4 py-2 rounded-2xl font-black text-xs transition-all bg-amber-500 text-zinc-950 shadow-md">
                        🏛️ General Info
                    </button>
                    <button onclick="switchTab('pos')" id="tabBtn-pos" class="tab-btn px-4 py-2 rounded-2xl font-bold text-xs transition-all text-zinc-400 hover:text-white hover:bg-zinc-900">
                        💳 POS & Tax Rules
                    </button>
                    <button onclick="switchTab('kitchen')" id="tabBtn-kitchen" class="tab-btn px-4 py-2 rounded-2xl font-bold text-xs transition-all text-zinc-400 hover:text-white hover:bg-zinc-900">
                        👨‍🍳 Kitchen & KDS
                    </button>
                    <button onclick="switchTab('qr')" id="tabBtn-qr" class="tab-btn px-4 py-2 rounded-2xl font-bold text-xs transition-all text-zinc-400 hover:text-white hover:bg-zinc-900">
                        📱 QR Ordering
                    </button>
                </div>

                <!-- SETTINGS FORM -->
                <form id="settingsForm" enctype="multipart/form-data" onsubmit="event.preventDefault(); saveRestaurantSettings();">
                    <?php echo CSRF::getField(); ?>

                    <!-- 1. GENERAL TAB -->
                    <div id="tab-general" class="tab-content space-y-6">
                        <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-6 shadow-xl">
                            <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2 border-b border-zinc-800 pb-3">
                                <span>🏛️</span> General Restaurant Details
                            </h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-1.5 md:col-span-2">
                                    <label class="block text-xs font-bold text-zinc-300">Restaurant Name</label>
                                    <input type="text" name="restaurant_name" value="<?= htmlspecialchars($restName) ?>" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-medium outline-none focus:border-amber-500">
                                </div>

                                <div class="space-y-1.5">
                                    <label class="block text-xs font-bold text-zinc-300">Phone Number</label>
                                    <input type="text" name="phone" value="<?= htmlspecialchars($settings['phone'] ?? '') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                </div>

                                <div class="space-y-1.5">
                                    <label class="block text-xs font-bold text-zinc-300">Email Address</label>
                                    <input type="email" name="email" value="<?= htmlspecialchars($settings['email'] ?? '') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                </div>

                                <div class="space-y-1.5 md:col-span-2">
                                    <label class="block text-xs font-bold text-zinc-300">Address / Location</label>
                                    <input type="text" name="address" value="<?= htmlspecialchars($settings['address'] ?? '') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                </div>

                                <div class="space-y-1.5">
                                    <label class="block text-xs font-bold text-zinc-300">PAN / VAT Registration Number</label>
                                    <input type="text" name="pan_vat_number" value="<?= htmlspecialchars($settings['pan_vat_number'] ?? '') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                </div>

                                <div class="space-y-1.5">
                                    <label class="block text-xs font-bold text-zinc-300">Currency Symbol</label>
                                    <input type="text" name="currency" value="<?= htmlspecialchars($settings['currency'] ?? 'NPR') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                                </div>

                                <div class="space-y-1.5 md:col-span-2">
                                    <label class="block text-xs font-bold text-zinc-300">Timezone</label>
                                    <select name="timezone" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                        <option value="Asia/Kathmandu" <?= ($settings['timezone'] ?? '') === 'Asia/Kathmandu' ? 'selected' : '' ?>>Asia/Kathmandu (Nepal GMT+5:45)</option>
                                        <option value="Asia/Kolkata" <?= ($settings['timezone'] ?? '') === 'Asia/Kolkata' ? 'selected' : '' ?>>Asia/Kolkata (India GMT+5:30)</option>
                                        <option value="UTC" <?= ($settings['timezone'] ?? '') === 'UTC' ? 'selected' : '' ?>>UTC (Coordinated Universal Time)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Logo Upload Section -->
                            <div class="pt-4 border-t border-zinc-800 space-y-3">
                                <label class="block text-xs font-bold text-zinc-300">Restaurant Logo</label>
                                <div class="flex items-center gap-4">
                                    <div id="logoPreviewBox" class="w-16 h-16 rounded-2xl bg-zinc-950 border border-zinc-800 overflow-hidden flex items-center justify-center text-2xl shrink-0">
                                        <?php if (!empty($settings['logo_url'])): ?>
                                            <img src="../<?= htmlspecialchars($settings['logo_url']) ?>" alt="Logo" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            ☕
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <input type="file" name="logo" id="logoInput" onchange="previewLogo(this)" accept="image/*" class="text-xs text-zinc-400 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-zinc-800 file:text-amber-400 hover:file:bg-zinc-700 cursor-pointer">
                                        <p class="text-[10px] text-zinc-500 mt-1">Recommended: Square PNG/JPEG/WEBP under 5MB</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. POS SETTINGS TAB -->
                    <div id="tab-pos" class="tab-content hidden space-y-6">
                        <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-6 shadow-xl">
                            <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2 border-b border-zinc-800 pb-3">
                                <span>💳</span> POS Tax & Financial Settings
                            </h3>

                            <!-- Tax Configuration -->
                            <div class="space-y-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4 class="text-xs font-bold text-white">Enable Value Added Tax (VAT)</h4>
                                        <p class="text-[11px] text-zinc-400">Automatically add government tax to customer invoices</p>
                                    </div>
                                    <input type="checkbox" name="tax_enabled" value="1" <?= !empty($settings['tax_enabled']) ? 'checked' : '' ?> class="w-5 h-5 accent-amber-500 cursor-pointer">
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Tax Display Name</label>
                                        <input type="text" name="tax_name" value="<?= htmlspecialchars($settings['tax_name'] ?? 'VAT') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Tax Percentage (%)</label>
                                        <input type="number" step="0.01" name="tax_percentage" value="<?= floatval($settings['tax_percentage'] ?? 13.00) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                                    </div>
                                </div>
                            </div>

                            <!-- Service Charge -->
                            <div class="pt-4 border-t border-zinc-800 space-y-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4 class="text-xs font-bold text-white">Enable Service Charge</h4>
                                        <p class="text-[11px] text-zinc-400">Add service fee to active dine-in orders</p>
                                    </div>
                                    <input type="checkbox" name="service_charge_enabled" value="1" <?= !empty($settings['service_charge_enabled']) ? 'checked' : '' ?> class="w-5 h-5 accent-amber-500 cursor-pointer">
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Service Charge Type</label>
                                        <select name="service_charge_type" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                            <option value="percent" <?= ($settings['service_charge_type'] ?? '') === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
                                            <option value="fixed" <?= ($settings['service_charge_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed Amount (NPR)</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Amount / Rate</label>
                                        <input type="number" step="0.01" name="service_charge_amount" value="<?= floatval($settings['service_charge_amount'] ?? 10.00) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                                    </div>
                                </div>
                            </div>

                            <!-- Discount Limits -->
                            <div class="pt-4 border-t border-zinc-800 space-y-4">
                                <h4 class="text-xs font-bold text-white">Discount Control & Limits</h4>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Maximum Allowed Discount (%)</label>
                                        <input type="number" step="0.01" name="discount_max_percent" value="<?= floatval($settings['discount_max_percent'] ?? 20.00) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                                    </div>
                                    <div class="flex items-center gap-3 pt-6">
                                        <input type="checkbox" name="discount_require_permission" value="1" <?= !empty($settings['discount_require_permission']) ? 'checked' : '' ?> class="w-5 h-5 accent-amber-500 cursor-pointer">
                                        <span class="text-xs font-bold text-zinc-300">Require Manager Approval for Discounts</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Receipt & Prefix -->
                            <div class="pt-4 border-t border-zinc-800 space-y-4">
                                <h4 class="text-xs font-bold text-white">Receipt & Order Formatting</h4>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Order Number Prefix</label>
                                        <input type="text" name="order_prefix" value="<?= htmlspecialchars($settings['order_prefix'] ?? 'ORD-') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Starting Order Number</label>
                                        <input type="number" name="order_starting_number" value="<?= intval($settings['order_starting_number'] ?? 1001) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Thermal Paper Width</label>
                                        <select name="receipt_paper_size" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                            <option value="80mm" <?= ($settings['receipt_paper_size'] ?? '') === '80mm' ? 'selected' : '' ?>>80mm Standard</option>
                                            <option value="58mm" <?= ($settings['receipt_paper_size'] ?? '') === '58mm' ? 'selected' : '' ?>>58mm Compact</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1.5 sm:col-span-3">
                                        <label class="block text-xs font-bold text-zinc-300">Receipt Footer Note</label>
                                        <input type="text" name="receipt_footer_msg" value="<?= htmlspecialchars($settings['receipt_footer_msg'] ?? 'Thank you for dining with us!') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 3. KITCHEN SETTINGS TAB -->
                    <div id="tab-kitchen" class="tab-content hidden space-y-6">
                        <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-6 shadow-xl">
                            <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2 border-b border-zinc-800 pb-3">
                                <span>👨‍🍳</span> Kitchen Display System (KDS) & Routing
                            </h3>

                            <div class="space-y-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4 class="text-xs font-bold text-white">Enable KDS Screen Mode</h4>
                                        <p class="text-[11px] text-zinc-400">Stream orders in real-time to kitchen line monitors</p>
                                    </div>
                                    <input type="checkbox" name="kds_enabled" value="1" <?= !empty($settings['kds_enabled']) ? 'checked' : '' ?> class="w-5 h-5 accent-amber-500 cursor-pointer">
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Auto Refresh Rate (Sec)</label>
                                        <input type="number" name="kds_auto_refresh_sec" value="<?= intval($settings['kds_auto_refresh_sec'] ?? 2) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Target Prep Time (Mins)</label>
                                        <input type="number" name="kds_prep_time_mins" value="<?= intval($settings['kds_prep_time_mins'] ?? 15) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Overdue Warning Threshold (Mins)</label>
                                        <input type="number" name="kds_delayed_threshold_mins" value="<?= intval($settings['kds_delayed_threshold_mins'] ?? 15) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. QR ORDERING TAB -->
                    <div id="tab-qr" class="tab-content hidden space-y-6">
                        <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-6 shadow-xl">
                            <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2 border-b border-zinc-800 pb-3">
                                <span>📱</span> Contactless QR Table Ordering Rules
                            </h3>

                            <div class="space-y-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4 class="text-xs font-bold text-white">Enable Customer QR Table Ordering</h4>
                                        <p class="text-[11px] text-zinc-400">Allow customers to scan QR codes on tables and submit orders</p>
                                    </div>
                                    <input type="checkbox" name="qr_ordering_enabled" value="1" <?= !empty($settings['qr_ordering_enabled']) ? 'checked' : '' ?> class="w-5 h-5 accent-amber-500 cursor-pointer">
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Minimum Order Amount (NPR)</label>
                                        <input type="number" step="0.01" name="qr_min_order_amount" value="<?= floatval($settings['qr_min_order_amount'] ?? 0.00) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Opening Time</label>
                                        <input type="time" name="qr_opening_time" value="<?= htmlspecialchars($settings['qr_opening_time'] ?? '08:00') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-xs font-bold text-zinc-300">Closing Time</label>
                                        <input type="time" name="qr_closing_time" value="<?= htmlspecialchars($settings['qr_closing_time'] ?? '22:00') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                    </div>
                                    <div class="space-y-1.5 sm:col-span-3">
                                        <label class="block text-xs font-bold text-zinc-300">Customer Welcome Banner Instructions</label>
                                        <input type="text" name="qr_instructions" value="<?= htmlspecialchars($settings['qr_instructions'] ?? 'Select dishes, add notes, and submit order directly to kitchen.') ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </main>
    </div>

    <script src="../js/modern.js"></script>
    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.className = 'tab-btn px-4 py-2 rounded-2xl font-bold text-xs transition-all text-zinc-400 hover:text-white hover:bg-zinc-900';
            });

            document.getElementById('tab-' + tabName).classList.remove('hidden');
            const activeBtn = document.getElementById('tabBtn-' + tabName);
            activeBtn.className = 'tab-btn px-4 py-2 rounded-2xl font-black text-xs transition-all bg-amber-500 text-zinc-950 shadow-md';
        }

        function previewLogo(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('logoPreviewBox').innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function saveRestaurantSettings() {
            const btn = document.getElementById('saveBtn');
            btn.disabled = true;
            btn.innerHTML = '<span>⏳</span> <span>Saving...</span>';

            const form = document.getElementById('settingsForm');
            const formData = new FormData(form);

            fetch('../api/settings.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<span>💾</span> <span>Save Settings</span>';
                if (data.success) {
                    showToast('Restaurant settings saved successfully!', 'success');
                } else {
                    showToast(data.message || 'Failed to save settings', 'error');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<span>💾</span> <span>Save Settings</span>';
                showToast('Network error saving settings', 'error');
            });
        }
    </script>
</body>
</html>
