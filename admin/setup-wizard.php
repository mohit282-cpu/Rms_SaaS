<?php
// admin/setup-wizard.php - Interactive 8-Step Restaurant Setup Wizard (0–100% Progress)
require_once __DIR__ . '/../config.php';

Auth::requireRestaurant();
$tenantId = TenantContext::getTenantId();
$conn = getDBConnection();

$message = null;
$error = null;

// Fetch current setup status
$rest = null;
$tableCount = 0;
$catCount = 0;
$menuCount = 0;
$userCount = 0;

if ($conn) {
    $res = $conn->query("SELECT * FROM restaurants WHERE id = {$tenantId} LIMIT 1");
    if ($res) $rest = $res->fetch_assoc();

    $tRes = $conn->query("SELECT COUNT(*) as cnt FROM tables WHERE restaurant_id = {$tenantId}");
    if ($tRes && $tr = $tRes->fetch_assoc()) $tableCount = (int)$tr['cnt'];

    $cRes = $conn->query("SELECT COUNT(*) as cnt FROM categories WHERE restaurant_id = {$tenantId}");
    if ($cRes && $cr = $cRes->fetch_assoc()) $catCount = (int)$cr['cnt'];

    $mRes = $conn->query("SELECT COUNT(*) as cnt FROM menu_items WHERE restaurant_id = {$tenantId}");
    if ($mRes && $mr = $mRes->fetch_assoc()) $menuCount = (int)$mr['cnt'];

    $uRes = $conn->query("SELECT COUNT(*) as cnt FROM admin_users WHERE restaurant_id = {$tenantId}");
    if ($uRes && $ur = $uRes->fetch_assoc()) $userCount = (int)$ur['cnt'];
}

// Calculate setup completion percentage (0 - 100%)
$completedSteps = 0;
$totalSteps = 6;

if ($rest && !empty($rest['restaurant_name'])) $completedSteps++;
if ($rest && !empty($rest['logo'])) $completedSteps++;
if ($tableCount > 0) $completedSteps++;
if ($catCount > 0 || $menuCount > 0) $completedSteps++;
if ($userCount > 1) $completedSteps++;
if ($rest && $rest['status'] === 'ACTIVE') $completedSteps++;

$progressPercent = min(100, (int)round(($completedSteps / $totalSteps) * 100));

// Handle Quick Step Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $error = "CSRF security check failed.";
    } else {
        $stepAction = $_POST['step_action'] ?? '';
        if ($stepAction === 'save_info') {
            $name = Security::sanitize($_POST['restaurant_name'] ?? '');
            $type = Security::sanitize($_POST['restaurant_type'] ?? '');
            $phone = Security::sanitize($_POST['phone'] ?? '');

            if ($conn) {
                $stmt = $conn->prepare("UPDATE restaurants SET restaurant_name = ?, restaurant_type = ?, phone = ? WHERE id = ?");
                $stmt->bind_param("sssi", $name, $type, $phone, $tenantId);
                $stmt->execute();
                $stmt->close();
                $message = "Restaurant information saved!";
            }
        } elseif ($stepAction === 'add_table') {
            $tableNum = Security::sanitize($_POST['table_number'] ?? '1');
            $zone = Security::sanitize($_POST['zone'] ?? 'Ground Floor');
            $cap = (int)($_POST['capacity'] ?? 4);
            $token = bin2hex(random_bytes(16));

            if ($conn) {
                $stmt = $conn->prepare("INSERT INTO tables (restaurant_id, table_number, zone, capacity, qr_token) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issis", $tenantId, $tableNum, $zone, $cap, $token);
                if ($stmt->execute()) {
                    $message = "Table #{$tableNum} added successfully!";
                } else {
                    $error = "Table number #{$tableNum} already exists.";
                }
                $stmt->close();
            }
        } elseif ($stepAction === 'add_category') {
            $catName = Security::sanitize($_POST['category_name'] ?? '');
            if (!empty($catName) && $conn) {
                $stmt = $conn->prepare("INSERT INTO categories (restaurant_id, name) VALUES (?, ?)");
                $stmt->bind_param("is", $tenantId, $catName);
                if ($stmt->execute()) {
                    $message = "Category '{$catName}' added!";
                }
                $stmt->close();
            }
        } elseif ($stepAction === 'complete_wizard') {
            $message = "Setup complete! Your restaurant is now live and fully operational.";
            header('Location: index.php');
            exit;
        }

        // Refresh counts
        header('Location: setup-wizard.php');
        exit;
    }
}

$csrfField = CSRF::getField();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Onboarding Setup Wizard - RMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Plus Jakarta Sans', sans-serif; } </style>
</head>
<body class="min-h-full bg-zinc-950 text-zinc-100 font-sans antialiased py-8 px-4 sm:px-6 lg:px-8 selection:bg-amber-500 selection:text-zinc-950">
    <div class="max-w-4xl mx-auto space-y-8">
        <!-- Top Navigation -->
        <div class="flex items-center justify-between border-b border-zinc-800 pb-6">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-amber-500 text-zinc-950 font-black flex items-center justify-center text-xl">🚀</div>
                <div>
                    <h1 class="text-xl font-black text-white">RMS Setup Wizard</h1>
                    <p class="text-xs text-zinc-400 font-medium">Onboarding setup for <?= htmlspecialchars($rest['restaurant_name'] ?? 'Your Restaurant') ?></p>
                </div>
            </div>
            <a href="index.php" class="px-4 py-2 rounded-xl bg-zinc-900 border border-zinc-800 text-xs font-bold text-zinc-400 hover:text-white transition-colors">
                Skip to Dashboard →
            </a>
        </div>

        <!-- Progress Tracker Bar -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold text-zinc-400 uppercase tracking-wider">Setup Progress</span>
                    <h2 class="text-2xl font-black text-amber-400"><?= $progressPercent ?>% Completed</h2>
                </div>
                <div class="text-right">
                    <span class="text-xs text-zinc-400 font-semibold"><?= $completedSteps ?> of <?= $totalSteps ?> core steps completed</span>
                </div>
            </div>

            <div class="w-full bg-zinc-950 h-3 rounded-full overflow-hidden border border-zinc-800">
                <div class="bg-gradient-to-r from-amber-500 to-amber-400 h-full rounded-full transition-all duration-500" style="width: <?= $progressPercent ?>%;"></div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">
                ✅ <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Restaurant Info -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <span class="w-8 h-8 rounded-xl bg-amber-500/10 text-amber-400 font-mono font-black flex items-center justify-center text-xs">1</span>
                    <h3 class="text-base font-black text-white">Restaurant Information</h3>
                </div>
                <span class="text-xs font-bold text-emerald-400">✅ Configured</span>
            </div>

            <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
                <?= $csrfField ?>
                <input type="hidden" name="step_action" value="save_info">
                <div>
                    <label class="block text-xs font-bold text-zinc-400 mb-1">Restaurant Name</label>
                    <input type="text" name="restaurant_name" value="<?= htmlspecialchars($rest['restaurant_name'] ?? '') ?>" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white">
                </div>
                <div>
                    <label class="block text-xs font-bold text-zinc-400 mb-1">Restaurant Type</label>
                    <input type="text" name="restaurant_type" value="<?= htmlspecialchars($rest['restaurant_type'] ?? 'Fine Dining') ?>" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white">
                </div>
                <div>
                    <label class="block text-xs font-bold text-zinc-400 mb-1">Phone</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($rest['phone'] ?? '') ?>" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white">
                </div>
            </form>
        </div>

        <!-- Step 2: Dining Tables -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <span class="w-8 h-8 rounded-xl bg-amber-500/10 text-amber-400 font-mono font-black flex items-center justify-center text-xs">2</span>
                    <h3 class="text-base font-black text-white">Dining Tables Setup</h3>
                </div>
                <span class="text-xs font-bold text-zinc-400"><?= $tableCount ?> Tables Added</span>
            </div>

            <form method="POST" class="flex flex-col sm:flex-row items-center gap-3 pt-2">
                <?= $csrfField ?>
                <input type="hidden" name="step_action" value="add_table">
                <input type="text" name="table_number" placeholder="Table # (e.g. 1)" required class="h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white w-full sm:w-32">
                <input type="text" name="zone" placeholder="Zone (e.g. Main Hall)" value="Main Dining" class="h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white w-full sm:w-48">
                <button type="submit" class="h-10 px-5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all w-full sm:w-auto">
                    + Add Table
                </button>
                <a href="tables.php" class="h-10 px-4 rounded-xl border border-zinc-800 text-xs font-bold text-zinc-400 hover:text-white flex items-center justify-center">
                    Manage Tables →
                </a>
            </form>
        </div>

        <!-- Step 3: Menu Categories -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <span class="w-8 h-8 rounded-xl bg-amber-500/10 text-amber-400 font-mono font-black flex items-center justify-center text-xs">3</span>
                    <h3 class="text-base font-black text-white">Menu Categories & Items</h3>
                </div>
                <span class="text-xs font-bold text-zinc-400"><?= $catCount ?> Categories / <?= $menuCount ?> Items</span>
            </div>

            <form method="POST" class="flex items-center gap-3 pt-2">
                <?= $csrfField ?>
                <input type="hidden" name="step_action" value="add_category">
                <input type="text" name="category_name" placeholder="Category Name (e.g. Starters)" required class="h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white flex-1">
                <button type="submit" class="h-10 px-5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all">
                    + Add Category
                </button>
                <a href="menu-items.php" class="h-10 px-4 rounded-xl border border-zinc-800 text-xs font-bold text-zinc-400 hover:text-white flex items-center justify-center">
                    Add Items →
                </a>
            </form>
        </div>

        <!-- Finish Button -->
        <div class="pt-6 border-t border-zinc-800 flex items-center justify-between">
            <a href="index.php" class="text-xs font-bold text-zinc-500 hover:text-zinc-300">Exit Wizard</a>
            <form method="POST">
                <?= $csrfField ?>
                <input type="hidden" name="step_action" value="complete_wizard">
                <button type="submit" class="px-8 py-3.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-sm shadow-xl shadow-amber-500/20 active:scale-95">
                    Finish Setup & Go Live 🚀
                </button>
            </form>
        </div>
    </div>
</body>
</html>
