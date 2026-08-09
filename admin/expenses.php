<?php
// admin/expenses.php - Operating Expense Management & P&L Dashboard UI (Phase 3)
require_once __DIR__ . '/../config.php';
requireAdminLogin();
RBAC::requirePermission('manage_expenses');

$currentPage = 'expenses';
$tenantId = TenantContext::getTenantId();

$conn = getDBConnection();
$month = date('Y-m');
$startDate = "{$month}-01";
$endDate = date('Y-m-t', strtotime($startDate));

$eStmt = $conn->prepare("SELECT * FROM expenses WHERE restaurant_id = ? AND expense_date BETWEEN ? AND ? ORDER BY expense_date DESC LIMIT 100");
$eStmt->bind_param("iss", $tenantId, $startDate, $endDate);
$eStmt->execute();
$expenses = $eStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$eStmt->close();

$expSumRes = $conn->query("SELECT COALESCE(SUM(amount), 0.00) as total_exp FROM expenses WHERE restaurant_id = $tenantId AND expense_date BETWEEN '$startDate' AND '$endDate'");
$totalExpenses = floatval($expSumRes->fetch_assoc()['total_exp'] ?? 0.00);

$revSumRes = $conn->query("SELECT COALESCE(SUM(total_amount), 0.00) as total_rev FROM orders WHERE restaurant_id = $tenantId AND status = 'completed' AND DATE(created_at) BETWEEN '$startDate' AND '$endDate'");
$totalRevenue = floatval($revSumRes->fetch_assoc()['total_rev'] ?? 0.00);

$operatingProfit = round($totalRevenue - $totalExpenses, 2);
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Expense Management & P&L - QR Cafe</title>
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
            <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-lg md:text-xl font-black text-white flex items-center gap-2">
                        <span>💵</span> Expense Management & P&L Dashboard
                    </h1>
                    <p class="text-xs text-zinc-400">Record operating expenses and track net operating profit (Revenue - Expenses)</p>
                </div>
                <button onclick="openModal()" class="h-10 px-5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 transition-all flex items-center gap-2 shadow-lg shadow-amber-500/20">
                    <span>➕</span> <span>Record Expense</span>
                </button>
            </header>

            <div class="p-4 md:p-8 max-w-6xl mx-auto space-y-6">

                <!-- P&L SUMMARY CARDS -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-1 shadow-xl">
                        <span class="text-xs text-zinc-400 font-bold">📈 Total Monthly Revenue</span>
                        <div class="text-2xl font-black text-emerald-400">NPR <?= number_format($totalRevenue, 2) ?></div>
                    </div>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-1 shadow-xl">
                        <span class="text-xs text-zinc-400 font-bold">💸 Total Operating Expenses</span>
                        <div class="text-2xl font-black text-rose-400">NPR <?= number_format($totalExpenses, 2) ?></div>
                    </div>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-1 shadow-xl">
                        <span class="text-xs text-zinc-400 font-bold">📊 Net Operating Profit</span>
                        <div class="text-2xl font-black <?= $operatingProfit >= 0 ? 'text-amber-400' : 'text-rose-500' ?>">
                            NPR <?= number_format($operatingProfit, 2) ?>
                        </div>
                    </div>
                </div>

                <!-- EXPENSES TABLE -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                            <span>🧾</span> Expense Log (Current Month)
                        </h3>
                        <span class="text-xs font-bold text-amber-400 bg-amber-500/10 border border-amber-500/30 px-3 py-1 rounded-full">
                            Total Records: <?= count($expenses) ?>
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider font-bold">
                                    <th class="py-3 px-4">Date</th>
                                    <th class="py-3 px-4">Category</th>
                                    <th class="py-3 px-4">Amount</th>
                                    <th class="py-3 px-4">Description</th>
                                    <th class="py-3 px-4">Payment Method</th>
                                    <th class="py-3 px-4 text-right">Recorded By</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-200">
                                <?php if (empty($expenses)): ?>
                                    <tr><td colspan="6" class="py-8 text-center text-zinc-500 italic">No expenses recorded for this month.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($expenses as $e): ?>
                                        <tr class="hover:bg-zinc-800/30 transition-colors">
                                            <td class="py-3.5 px-4 font-bold text-white"><?= date('M d, Y', strtotime($e['expense_date'])) ?></td>
                                            <td class="py-3.5 px-4">
                                                <span class="px-2.5 py-0.5 rounded-lg text-[10px] font-black uppercase border border-amber-500/30 bg-amber-500/10 text-amber-400">
                                                    <?= htmlspecialchars($e['category_name']) ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 font-black text-rose-400">-NPR <?= number_format($e['amount'], 2) ?></td>
                                            <td class="py-3.5 px-4 text-zinc-300"><?= htmlspecialchars($e['description']) ?></td>
                                            <td class="py-3.5 px-4 uppercase text-zinc-400"><?= htmlspecialchars($e['payment_method']) ?></td>
                                            <td class="py-3.5 px-4 text-right text-zinc-400"><?= htmlspecialchars($e['created_by']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- NEW EXPENSE MODAL -->
    <div id="expModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 w-full max-w-md space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 class="text-sm font-black text-white">Record Operating Expense</h3>
                <button onclick="closeModal()" class="text-zinc-500 hover:text-white font-bold text-sm">✕</button>
            </div>

            <form id="expForm" onsubmit="event.preventDefault(); submitExpense();" class="space-y-4">
                <?php echo CSRF::getField(); ?>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Category</label>
                    <select name="category_name" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                        <option value="Rent">Rent</option>
                        <option value="Electricity">Electricity</option>
                        <option value="Water">Water</option>
                        <option value="Salary">Staff Salary</option>
                        <option value="Gas">Gas / Cooking Fuel</option>
                        <option value="Maintenance">Maintenance & Repairs</option>
                        <option value="Marketing">Marketing & Ads</option>
                        <option value="Supplies">General Supplies</option>
                        <option value="Other">Other Expense</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-zinc-300">Amount (NPR)</label>
                        <input type="number" step="0.01" name="amount" required placeholder="0.00" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-zinc-300">Expense Date</label>
                        <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Description / Note</label>
                    <input type="text" name="description" placeholder="e.g. Monthly Electricity Bill" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110">Save Expense Entry</button>
            </form>
        </div>
    </div>

    <script src="../js/modern.js"></script>
    <script>
        function openModal() { document.getElementById('expModal').classList.remove('hidden'); }
        function closeModal() { document.getElementById('expModal').classList.add('hidden'); }

        function submitExpense() {
            const formData = new FormData(document.getElementById('expForm'));
            fetch('../api/expenses.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Expense recorded!', 'success'); setTimeout(() => location.reload(), 800); }
                    else showToast(data.message || 'Error saving expense', 'error');
                });
        }
    </script>
</body>
</html>
