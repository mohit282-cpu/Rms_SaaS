<?php
// admin/settings.php - Centralized Restaurant Settings Configuration
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
$conn = getDBConnection();

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

    $stmt = $conn->prepare("UPDATE payment_settings SET restaurant_name = ?, payment_note = ?, tax_enabled = ?, tax_percentage = ?, service_charge_enabled = ?, service_charge_amount = ? WHERE id = 1");
    $stmt->bind_param("ssiddi", $name, $note, $taxEnabled, $taxPercent, $scEnabled, $scPercent);
    if ($stmt->execute()) {
        $message = "Restaurant settings updated successfully!";
    }
    $stmt->close();
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
                <p class="text-xs text-zinc-400">Configure Tenant Details, Tax (VAT), Service Charge, Receipts &amp; POS Behavior</p>
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
                            <label class="block font-bold text-zinc-300 mb-1">Currency &amp; Region</label>
                            <input type="text" value="NPR (Nepali Rupee - Rs.)" disabled class="w-full h-10 bg-zinc-950/60 border border-zinc-800/60 rounded-xl px-3 text-zinc-400 font-bold outline-none cursor-not-allowed">
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <h2 class="text-sm font-black text-white border-b border-zinc-800 pb-2">2. Tax &amp; Service Charge Configuration</h2>
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
                    <h2 class="text-sm font-black text-white border-b border-zinc-800 pb-2">3. Payment &amp; Receipt Note</h2>
                    <div class="text-xs">
                        <label class="block font-bold text-zinc-300 mb-1">Footer Payment Instructions on Receipt</label>
                        <textarea name="payment_note" rows="3" class="w-full bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-white outline-none focus:border-amber-500"><?= htmlspecialchars($sett['payment_note']) ?></textarea>
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
</body>
</html>
