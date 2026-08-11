<?php
// admin/expenses.php - Operational Expense Management & Net Operating Income
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
$conn = getDBConnection();

$currentPage = 'expenses';
$message = '';
$error = '';

// Provision expenses table
@$conn->query("CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL DEFAULT 1,
    category VARCHAR(100) NOT NULL DEFAULT 'General',
    category_name VARCHAR(100) NOT NULL DEFAULT 'General',
    title VARCHAR(200) NOT NULL DEFAULT 'Expense',
    description TEXT,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    expense_date DATE NOT NULL,
    vendor VARCHAR(150) DEFAULT '',
    reference_no VARCHAR(100) DEFAULT '',
    payment_method VARCHAR(50) DEFAULT 'cash',
    notes TEXT,
    created_by VARCHAR(100) DEFAULT 'Admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exp_tenant_date (restaurant_id, expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Check and align table schema for legacy vs current columns
$colsRes = $conn->query("SHOW COLUMNS FROM expenses");
$cols = [];
if ($colsRes) {
    while ($col = $colsRes->fetch_assoc()) {
        $cols[strtolower($col['Field'])] = true;
    }
}

if (!isset($cols['category'])) {
    @$conn->query("ALTER TABLE expenses ADD COLUMN category VARCHAR(100) DEFAULT 'General'");
    if (isset($cols['category_name'])) {
        @$conn->query("UPDATE expenses SET category = category_name WHERE category IS NULL OR category = '' OR category = 'General'");
    }
}
if (!isset($cols['category_name'])) {
    @$conn->query("ALTER TABLE expenses ADD COLUMN category_name VARCHAR(100) DEFAULT 'General'");
    @$conn->query("UPDATE expenses SET category_name = category WHERE category_name IS NULL OR category_name = ''");
}

if (!isset($cols['title'])) {
    @$conn->query("ALTER TABLE expenses ADD COLUMN title VARCHAR(200) DEFAULT 'Expense'");
    if (isset($cols['description'])) {
        @$conn->query("UPDATE expenses SET title = description WHERE title IS NULL OR title = '' OR title = 'Expense'");
    }
}
if (!isset($cols['description'])) {
    @$conn->query("ALTER TABLE expenses ADD COLUMN description TEXT DEFAULT NULL");
    @$conn->query("UPDATE expenses SET description = title WHERE description IS NULL OR description = ''");
}

if (!isset($cols['vendor'])) {
    @$conn->query("ALTER TABLE expenses ADD COLUMN vendor VARCHAR(150) DEFAULT ''");
    if (isset($cols['reference_no'])) {
        @$conn->query("UPDATE expenses SET vendor = reference_no WHERE vendor IS NULL OR vendor = ''");
    }
}
if (!isset($cols['reference_no'])) {
    @$conn->query("ALTER TABLE expenses ADD COLUMN reference_no VARCHAR(100) DEFAULT ''");
    @$conn->query("UPDATE expenses SET reference_no = vendor WHERE reference_no IS NULL OR reference_no = ''");
}

// Handle POST Add Expense
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValidToken();

    $title = Security::sanitize($_POST['title'] ?? '');
    $category = Security::sanitize($_POST['category'] ?? 'General');
    $amount = (float)($_POST['amount'] ?? 0.0);
    $date = Security::sanitize($_POST['expense_date'] ?? date('Y-m-d'));
    $vendor = Security::sanitize($_POST['vendor'] ?? '');
    $method = Security::sanitize($_POST['payment_method'] ?? 'cash');
    $notes = Security::sanitize($_POST['notes'] ?? '');
    $user = $_SESSION['email'] ?? $_SESSION['admin_email'] ?? 'Admin';

    if (empty($title) || $amount <= 0) {
        $error = "Valid expense title and amount are required.";
    } else {
        $stmt = $conn->prepare("INSERT INTO expenses (restaurant_id, category, category_name, title, description, amount, expense_date, vendor, reference_no, payment_method, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssdssssss", $tenantId, $category, $category, $title, $title, $amount, $date, $vendor, $vendor, $method, $notes, $user);
        if ($stmt->execute()) {
            $message = "Expense '$title' of Rs. " . number_format($amount, 2) . " recorded!";
        } else {
            $error = "Failed to record expense: " . $conn->error;
        }
        $stmt->close();
    }
}

// Calculate Total Revenue & Expenses for Net Result Calculation
$totalRevenue = 0.0;
$rev_res = $conn->query("SELECT SUM(total_amount) as total FROM orders WHERE restaurant_id = $tenantId AND payment_status = 'paid'");
if ($rev_res && $row = $rev_res->fetch_assoc()) {
    $totalRevenue = (float)($row['total'] ?? 0.0);
}

$totalExpenses = 0.0;
$exp_res = $conn->query("SELECT SUM(amount) as total FROM expenses WHERE restaurant_id = $tenantId");
if ($exp_res && $erow = $exp_res->fetch_assoc()) {
    $totalExpenses = (float)($erow['total'] ?? 0.0);
}

$netIncome = $totalRevenue - $totalExpenses;

// Fetch Expenses List
$expenses = [];
$e_res = $conn->query("SELECT * FROM expenses WHERE restaurant_id = $tenantId ORDER BY expense_date DESC, id DESC");
if ($e_res) {
    while ($row = $e_res->fetch_assoc()) {
        $expenses[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 font-sans antialiased text-white selection:bg-amber-500 selection:text-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Management — RMS SaaS</title>
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
                <h1 class="text-lg md:text-xl font-black text-white">Expense Management &amp; P&amp;L</h1>
                <p class="text-xs text-zinc-400">Track Restaurant Operating Expenses, Supplier Payments &amp; Net Result</p>
            </div>
            <button onclick="document.getElementById('addExpModal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                💸 Add Expense
            </button>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <?php if ($message): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">✅ <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Financial Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-1">
                    <span class="text-xs font-bold text-zinc-400">Total Settled Revenue</span>
                    <div class="text-2xl font-black text-emerald-400">Rs. <?= number_format($totalRevenue, 2) ?></div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-1">
                    <span class="text-xs font-bold text-zinc-400">Total Operating Expenses</span>
                    <div class="text-2xl font-black text-rose-400">Rs. <?= number_format($totalExpenses, 2) ?></div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-1">
                    <span class="text-xs font-bold text-zinc-400">Net Operating Income</span>
                    <div class="text-2xl font-black <?= $netIncome >= 0 ? 'text-amber-400' : 'text-rose-500' ?>">Rs. <?= number_format($netIncome, 2) ?></div>
                </div>
            </div>

            <!-- Expense Ledger Table -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-black text-white">Expense Ledger</h2>
                    <span class="text-xs text-zinc-500 font-bold">Total Entries: <?= count($expenses) ?></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <th class="py-2.5 px-3">Date</th>
                                <th class="py-2.5 px-3">Category</th>
                                <th class="py-2.5 px-3">Title / Description</th>
                                <th class="py-2.5 px-3">Vendor</th>
                                <th class="py-2.5 px-3">Method</th>
                                <th class="py-2.5 px-3">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                            <?php if (empty($expenses)): ?>
                                <tr><td colspan="6" class="py-8 text-center text-zinc-500">No expenses recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($expenses as $ex):
                                    $catVal = $ex['category'] ?? $ex['category_name'] ?? 'General';
                                    $titleVal = $ex['title'] ?? $ex['description'] ?? 'Expense';
                                    $vendorVal = $ex['vendor'] ?? $ex['reference_no'] ?? '';
                                ?>
                                    <tr class="hover:bg-zinc-800/40">
                                        <td class="py-3 px-3 font-mono text-zinc-400"><?= date('Y-m-d', strtotime($ex['expense_date'])) ?></td>
                                        <td class="py-3 px-3">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-zinc-800 text-amber-400">
                                                <?= htmlspecialchars($catVal) ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-3 font-bold text-white"><?= htmlspecialchars($titleVal) ?></td>
                                        <td class="py-3 px-3 text-zinc-400"><?= htmlspecialchars($vendorVal ?: 'N/A') ?></td>
                                        <td class="py-3 px-3 uppercase text-[10px] font-bold text-zinc-400"><?= htmlspecialchars($ex['payment_method'] ?? 'cash') ?></td>
                                        <td class="py-3 px-3 font-black text-rose-400">Rs. <?= number_format((float)($ex['amount'] ?? 0), 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- Modal Add Expense -->
    <div id="addExpModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
            <?= CSRF::getField() ?>
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">Record New Expense</h3>
                <button type="button" onclick="document.getElementById('addExpModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Expense Title</label>
                    <input type="text" name="title" required placeholder="e.g. Fresh Vegetable Stock" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Category</label>
                        <select name="category" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                            <option value="Raw Materials">Raw Materials / Food</option>
                            <option value="Utilities">Utilities (Electricity/Water)</option>
                            <option value="Rent">Rent &amp; Lease</option>
                            <option value="Salaries">Staff Salaries</option>
                            <option value="Maintenance">Maintenance &amp; Repairs</option>
                            <option value="General">General / Misc</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Amount (Rs.)</label>
                        <input type="number" step="0.01" name="amount" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white font-bold outline-none focus:border-amber-500">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Expense Date</label>
                        <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Vendor / Payee</label>
                        <input type="text" name="vendor" placeholder="Supplier name" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    </div>
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('addExpModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">Save Expense</button>
            </div>
        </form>
    </div>
</body>
</html>
