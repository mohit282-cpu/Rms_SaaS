<?php
// admin/shifts.php - Register Shift Management, Cash Drawer Float & Variance Auditing
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
$conn = getDBConnection();

$currentPage = 'shifts';
$message = '';
$error = '';

// Ensure database schema is provisioned
RegisterShiftService::ensureRegisterShiftSchema($conn);

$staffId = (int)($_SESSION['user_id'] ?? 1);
$staffName = $_SESSION['admin_username'] ?? 'Cashier';

// Fetch active open register shift
$activeShift = RegisterShiftService::getActiveShift($conn, $tenantId);

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValidToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'open_shift') {
        $res = RegisterShiftService::openShift($conn, $tenantId, $_POST, $staffId, $staffName);
        if ($res['success']) {
            $message = "Register Shift #{$res['shift_id']} opened successfully!";
            $activeShift = RegisterShiftService::getActiveShift($conn, $tenantId);
        } else {
            $error = $res['error'];
        }
    } elseif ($action === 'cash_in' || $action === 'cash_out') {
        if (!$activeShift) {
            $error = "No active shift open. Please open a shift first.";
        } else {
            $amount = (float)($_POST['amount'] ?? 0.0);
            $reason = Security::sanitize($_POST['reason'] ?? '');
            $isExpense = isset($_POST['link_expense']) && $_POST['link_expense'] === '1';
            $category = Security::sanitize($_POST['expense_category'] ?? 'General');

            $res = RegisterShiftService::recordCashMovement(
                $conn, $tenantId, $activeShift['id'], $action, $amount, $reason, 
                $staffId, $staffName, $isExpense, $category
            );
            if ($res['success']) {
                $message = strtoupper($action) . " of Rs. " . number_format($amount, 2) . " recorded successfully!";
                $activeShift = RegisterShiftService::getActiveShift($conn, $tenantId);
            } else {
                $error = $res['error'];
            }
        }
    } elseif ($action === 'close_shift') {
        if (!$activeShift) {
            $error = "No active shift open to close.";
        } else {
            $actualCash = (float)($_POST['closing_cash'] ?? 0.0);
            $notes = Security::sanitize($_POST['notes'] ?? '');

            // Parse Denomination counts if provided
            $denominations = [];
            $denomKeys = ['1000', '500', '100', '50', '20', '10', '5', '2', '1'];
            foreach ($denomKeys as $k) {
                if (isset($_POST["denom_$k"])) {
                    $denominations[$k] = max(0, (int)$_POST["denom_$k"]);
                }
            }

            $res = RegisterShiftService::closeShift($conn, $tenantId, $activeShift['id'], $actualCash, $denominations, $notes, $staffName);
            if ($res['success']) {
                $message = "Register Shift CLOSED! Variance Status: " . $res['variance_text'];
                $activeShift = null;
            } else {
                $error = $res['error'];
            }
        }
    }
}

// Fetch historical shift details if requested via Modal query param
$viewShiftDetail = null;
if (!empty($_GET['view_shift_id'])) {
    $viewShiftDetail = RegisterShiftService::getShiftById($conn, $tenantId, (int)$_GET['view_shift_id']);
}

// Fetch Shift Audit History
$shiftsHistory = RegisterShiftService::getShiftHistory($conn, $tenantId, 50);

// Available Register Terminals
$registersRes = $conn->query("SELECT register_name FROM registers WHERE restaurant_id = $tenantId AND status = 'active' ORDER BY register_name ASC");
$availableRegisters = [];
if ($registersRes) {
    while ($r = $registersRes->fetch_assoc()) {
        $availableRegisters[] = $r['register_name'];
    }
}
if (empty($availableRegisters)) {
    $availableRegisters = ['Counter 01', 'Counter 02', 'Bar Counter'];
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 font-sans antialiased text-white selection:bg-amber-500 selection:text-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Register &amp; Cash Float — RMS SaaS</title>
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
                <h1 class="text-lg md:text-xl font-black text-white">Shift Register &amp; Cash Float</h1>
                <p class="text-xs text-zinc-400">Open/Close Cashier Shifts, Balance Drawer &amp; Track Cash Variance</p>
            </div>
            <div class="flex items-center gap-2">
                <?php if (!$activeShift): ?>
                    <button onclick="document.getElementById('openShiftModal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                        🔑 Open Register Shift
                    </button>
                <?php else: ?>
                    <button onclick="document.getElementById('cashInModal').classList.remove('hidden')" class="px-3.5 py-2 rounded-xl bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 font-bold text-xs hover:bg-emerald-500/30">
                        ➕ Cash In
                    </button>
                    <button onclick="document.getElementById('cashOutModal').classList.remove('hidden')" class="px-3.5 py-2 rounded-xl bg-rose-500/20 border border-rose-500/30 text-rose-400 font-bold text-xs hover:bg-rose-500/30">
                        ➖ Cash Out
                    </button>
                    <button onclick="document.getElementById('closeShiftModal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-rose-500 text-white font-black text-xs active:scale-95 shadow-lg shadow-rose-500/20">
                        🔒 Close Shift
                    </button>
                <?php endif; ?>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <?php if ($message): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">✅ <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- ACTIVE SHIFT DASHBOARD OR NO ACTIVE SHIFT BANNER -->
            <?php if (!$activeShift): ?>
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-8 text-center space-y-4">
                    <div class="w-16 h-16 rounded-3xl bg-zinc-800 text-zinc-500 flex items-center justify-center font-black text-3xl mx-auto">
                        🔑
                    </div>
                    <div class="space-y-1 max-w-md mx-auto">
                        <h2 class="text-xl font-black text-white">No Active Register Shift Open</h2>
                        <p class="text-xs text-zinc-400">Open a cash register shift to set your opening float, accept table customer payments, and track cash movements.</p>
                    </div>
                    <div>
                        <button onclick="document.getElementById('openShiftModal').classList.remove('hidden')" class="px-6 py-3 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                            🔑 Open Register Shift Now
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <!-- ACTIVE REGISTER SHIFT DASHBOARD -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-6">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-zinc-800/80 pb-5">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black text-xl">
                                🟢
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h2 class="text-lg font-black text-white">Active Register Shift #<?= $activeShift['id'] ?></h2>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">OPEN</span>
                                </div>
                                <p class="text-xs text-zinc-400">
                                    Cashier: <strong class="text-white"><?= htmlspecialchars($activeShift['staff_name']) ?></strong> &middot; 
                                    Terminal: <strong class="text-amber-400"><?= htmlspecialchars($activeShift['register_name']) ?></strong> &middot; 
                                    Opened at <span class="font-mono text-zinc-300"><?= date('h:i A', strtotime($activeShift['open_time'])) ?></span>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="text-right">
                                <span class="text-xs text-zinc-400 font-bold block">Expected Physical Cash</span>
                                <span class="text-2xl font-black text-emerald-400 font-mono">Rs. <?= number_format($activeShift['expected_cash'], 2) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- FINANCIAL METRICS GRID -->
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-9 gap-3">
                        <div class="p-3.5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-0.5">
                            <span class="text-[10px] font-extrabold uppercase text-zinc-500">Opening Float</span>
                            <div class="text-sm font-black text-amber-400 font-mono">Rs. <?= number_format($activeShift['opening_cash'], 2) ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-0.5">
                            <span class="text-[10px] font-extrabold uppercase text-zinc-500">Cash Sales</span>
                            <div class="text-sm font-black text-emerald-400 font-mono">Rs. <?= number_format($activeShift['cash_sales'], 2) ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-0.5">
                            <span class="text-[10px] font-extrabold uppercase text-zinc-500">Cash Refunds</span>
                            <div class="text-sm font-black text-rose-400 font-mono">-Rs. <?= number_format($activeShift['cash_refunds'], 2) ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-0.5">
                            <span class="text-[10px] font-extrabold uppercase text-zinc-500">Cash In</span>
                            <div class="text-sm font-black text-emerald-400 font-mono">+Rs. <?= number_format($activeShift['cash_in'], 2) ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-0.5">
                            <span class="text-[10px] font-extrabold uppercase text-zinc-500">Cash Out</span>
                            <div class="text-sm font-black text-rose-400 font-mono">-Rs. <?= number_format($activeShift['cash_out'], 2) ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-zinc-950 border border-emerald-500/30 space-y-0.5">
                            <span class="text-[10px] font-extrabold uppercase text-emerald-400">Expected Cash</span>
                            <div class="text-sm font-black text-emerald-400 font-mono">Rs. <?= number_format($activeShift['expected_cash'], 2) ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-0.5">
                            <span class="text-[10px] font-extrabold uppercase text-zinc-500">Card Sales</span>
                            <div class="text-sm font-black text-blue-400 font-mono">Rs. <?= number_format($activeShift['card_sales'], 2) ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-0.5">
                            <span class="text-[10px] font-extrabold uppercase text-zinc-500">Digital QR</span>
                            <div class="text-sm font-black text-purple-400 font-mono">Rs. <?= number_format($activeShift['digital_sales'], 2) ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-zinc-950 border border-amber-500/30 space-y-0.5">
                            <span class="text-[10px] font-extrabold uppercase text-amber-400">Total Sales</span>
                            <div class="text-sm font-black text-amber-400 font-mono">Rs. <?= number_format($activeShift['total_sales'], 2) ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- SHIFT AUDIT HISTORY TABLE -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-black text-white">Shift Audit History &amp; Reconciliations</h2>
                    <span class="text-xs text-zinc-500 font-bold">Past 50 Register Shifts</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <th class="py-2.5 px-3">Shift ID</th>
                                <th class="py-2.5 px-3">Staff / Cashier</th>
                                <th class="py-2.5 px-3">Register</th>
                                <th class="py-2.5 px-3">Open Time</th>
                                <th class="py-2.5 px-3">Close Time</th>
                                <th class="py-2.5 px-3">Opening Float</th>
                                <th class="py-2.5 px-3">Cash Sales</th>
                                <th class="py-2.5 px-3">Expected Cash</th>
                                <th class="py-2.5 px-3">Actual Cash</th>
                                <th class="py-2.5 px-3">Variance</th>
                                <th class="py-2.5 px-3">Status</th>
                                <th class="py-2.5 px-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                            <?php if (empty($shiftsHistory)): ?>
                                <tr><td colspan="12" class="py-8 text-center text-zinc-500">No shift history recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($shiftsHistory as $sh):
                                    $var = (float)($sh['variance'] ?? 0);
                                    $varBadge = $var == 0 ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' : ($var > 0 ? 'bg-purple-500/20 text-purple-400 border-purple-500/30' : 'bg-rose-500/20 text-rose-400 border-rose-500/30');
                                    $varLabel = $var == 0 ? 'BALANCED' : ($var > 0 ? '+Rs. ' . number_format($var, 2) . ' OVER' : '-Rs. ' . number_format(abs($var), 2) . ' SHORT');
                                ?>
                                    <tr class="hover:bg-zinc-800/40">
                                        <td class="py-3 px-3 font-mono font-bold text-amber-400">#<?= $sh['id'] ?></td>
                                        <td class="py-3 px-3 text-white font-bold"><?= htmlspecialchars($sh['staff_name']) ?></td>
                                        <td class="py-3 px-3 text-zinc-400 font-semibold"><?= htmlspecialchars($sh['register_name']) ?></td>
                                        <td class="py-3 px-3 text-zinc-400 text-[11px] font-mono"><?= date('M d, H:i', strtotime($sh['open_time'])) ?></td>
                                        <td class="py-3 px-3 text-zinc-400 text-[11px] font-mono"><?= $sh['close_time'] ? date('M d, H:i', strtotime($sh['close_time'])) : 'Active' ?></td>
                                        <td class="py-3 px-3 font-mono">Rs. <?= number_format($sh['opening_cash'], 2) ?></td>
                                        <td class="py-3 px-3 font-mono text-emerald-400 font-bold">Rs. <?= number_format($sh['cash_sales'], 2) ?></td>
                                        <td class="py-3 px-3 font-mono text-white font-bold">Rs. <?= number_format($sh['expected_cash'], 2) ?></td>
                                        <td class="py-3 px-3 font-mono text-white font-bold">Rs. <?= number_format($sh['closing_cash'], 2) ?></td>
                                        <td class="py-3 px-3">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase border <?= $varBadge ?>">
                                                <?= $varLabel ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-3">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase <?= $sh['status'] === 'open' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-zinc-800 text-zinc-400 border border-zinc-700' ?>">
                                                <?= ucfirst($sh['status']) ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-3 text-right">
                                            <a href="shifts.php?view_shift_id=<?= $sh['id'] ?>" class="px-2.5 py-1 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-amber-400 font-bold text-[11px]">👁️ Details</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- MODAL 1: OPEN REGISTER SHIFT -->
    <div id="openShiftModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
            <?= CSRF::getField() ?>
            <input type="hidden" name="action" value="open_shift">
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">🔑 Open Register Shift</h3>
                <button type="button" onclick="document.getElementById('openShiftModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Cashier / Staff</label>
                        <input type="text" value="<?= htmlspecialchars($staffName) ?>" readonly class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-zinc-400 font-bold outline-none cursor-not-allowed">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Terminal / Register</label>
                        <select name="register_name" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                            <?php foreach ($availableRegisters as $reg): ?>
                                <option value="<?= htmlspecialchars($reg) ?>"><?= htmlspecialchars($reg) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Opening Cash Float (Rs.)</label>
                    <input type="number" step="0.01" name="opening_cash" value="1000.00" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white font-black text-base outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Opening Notes / Remarks</label>
                    <textarea name="notes" placeholder="Optional notes regarding float count..." class="w-full h-16 bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-white outline-none focus:border-amber-500"></textarea>
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('openShiftModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">Open Shift</button>
            </div>
        </form>
    </div>

    <!-- MODAL 2: CASH IN -->
    <?php if ($activeShift): ?>
        <div id="cashInModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
            <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
                <?= CSRF::getField() ?>
                <input type="hidden" name="action" value="cash_in">
                <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                    <h3 class="font-black text-white text-base">➕ Record Cash In</h3>
                    <button type="button" onclick="document.getElementById('cashInModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
                </div>
                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Cash In Amount (Rs.)</label>
                        <input type="number" step="0.01" name="amount" required placeholder="500.00" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white font-black text-base outline-none focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Reason / Notes</label>
                        <input type="text" name="reason" required placeholder="e.g. Additional Float Deposit / Cash Transfer" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-emerald-500">
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('cashInModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-emerald-500 text-zinc-950 font-black text-xs">Save Cash In</button>
                </div>
            </form>
        </div>

        <!-- MODAL 3: CASH OUT -->
        <div id="cashOutModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
            <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
                <?= CSRF::getField() ?>
                <input type="hidden" name="action" value="cash_out">
                <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                    <h3 class="font-black text-white text-base">➖ Record Cash Out</h3>
                    <button type="button" onclick="document.getElementById('cashOutModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
                </div>
                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Cash Out Amount (Rs.)</label>
                        <input type="number" step="0.01" name="amount" required placeholder="200.00" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white font-black text-base outline-none focus:border-rose-500">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Reason / Purpose</label>
                        <input type="text" name="reason" required placeholder="e.g. Petty Cash / Vegetable Supplier Payment" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-rose-500">
                    </div>
                    <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer font-bold text-zinc-200">
                            <input type="checkbox" name="link_expense" value="1" checked class="w-4 h-4 rounded bg-zinc-900 border-zinc-700 text-amber-500">
                            <span>Link to Operating Expense P&amp;L</span>
                        </label>
                        <div>
                            <label class="block text-[11px] font-bold text-zinc-400 mb-1">Expense Category</label>
                            <select name="expense_category" class="w-full h-9 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                                <option value="Raw Materials">Raw Materials / Food</option>
                                <option value="Utilities">Utilities</option>
                                <option value="Maintenance">Maintenance &amp; Repairs</option>
                                <option value="General">General / Misc</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('cashOutModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-rose-500 text-white font-black text-xs">Save Cash Out</button>
                </div>
            </form>
        </div>

        <!-- MODAL 4: CLOSE REGISTER SHIFT WITH DENOMINATIONS -->
        <div id="closeShiftModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
            <form method="POST" onsubmit="return confirm('Are you sure you want to close and lock this register shift?')" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-lg w-full space-y-4 max-h-[90vh] overflow-y-auto">
                <?= CSRF::getField() ?>
                <input type="hidden" name="action" value="close_shift">
                <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                    <h3 class="font-black text-white text-base">🔒 Close Register Shift #<?= $activeShift['id'] ?></h3>
                    <button type="button" onclick="document.getElementById('closeShiftModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
                </div>

                <!-- EXPECTED CASH BREAKDOWN SUMMARY -->
                <div class="p-3.5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-1.5 text-xs font-mono">
                    <div class="flex justify-between"><span>Opening Float:</span> <span class="text-amber-400">Rs. <?= number_format($activeShift['opening_cash'], 2) ?></span></div>
                    <div class="flex justify-between"><span>+ Cash Sales:</span> <span class="text-emerald-400">Rs. <?= number_format($activeShift['cash_sales'], 2) ?></span></div>
                    <div class="flex justify-between"><span>+ Cash In:</span> <span class="text-emerald-400">Rs. <?= number_format($activeShift['cash_in'], 2) ?></span></div>
                    <div class="flex justify-between"><span>- Cash Refunds:</span> <span class="text-rose-400">-Rs. <?= number_format($activeShift['cash_refunds'], 2) ?></span></div>
                    <div class="flex justify-between"><span>- Cash Out:</span> <span class="text-rose-400">-Rs. <?= number_format($activeShift['cash_out'], 2) ?></span></div>
                    <div class="border-t border-zinc-800 pt-1.5 flex justify-between font-bold text-sm">
                        <span class="text-white">Expected Cash:</span> 
                        <span class="text-emerald-400" id="modalExpectedCash" data-expected="<?= $activeShift['expected_cash'] ?>">Rs. <?= number_format($activeShift['expected_cash'], 2) ?></span>
                    </div>
                </div>

                <!-- OPTIONAL DENOMINATION COUNTING GRID -->
                <div class="border-t border-zinc-800 pt-3 space-y-2">
                    <h4 class="font-bold text-white text-xs">Cash Denomination Count (Optional Helper)</h4>
                    <div class="grid grid-cols-3 gap-2 text-xs">
                        <?php foreach (['1000', '500', '100', '50', '20', '10', '5', '2', '1'] as $val): ?>
                            <div class="flex items-center gap-1.5 p-1.5 rounded-xl bg-zinc-950 border border-zinc-800">
                                <span class="text-zinc-400 font-bold w-12 text-right">Rs. <?= $val ?>:</span>
                                <input type="number" min="0" name="denom_<?= $val ?>" data-val="<?= $val ?>" oninput="calcDenominations()" class="denom-input w-full h-8 bg-zinc-900 border border-zinc-800 rounded-lg px-2 text-white font-mono text-center outline-none focus:border-amber-500">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ACTUAL CASH INPUT & VARIANCE PREVIEW -->
                <div class="space-y-3 text-xs border-t border-zinc-800 pt-3">
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Actual Cash Counted in Drawer (Rs.)</label>
                        <input type="number" step="0.01" name="closing_cash" id="actualCashInput" required oninput="calcVariance()" placeholder="Enter total cash counted" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white font-black text-base outline-none focus:border-amber-500">
                    </div>
                    <div class="p-3 rounded-2xl bg-zinc-950 flex justify-between items-center">
                        <span class="font-bold text-zinc-400">Calculated Cash Variance:</span>
                        <span id="variancePreview" class="font-black font-mono text-sm text-zinc-400">Enter Actual Cash</span>
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Closing Remarks / Variance Notes</label>
                        <textarea name="notes" placeholder="Optional notes regarding cash variance or shift closing..." class="w-full h-16 bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-white outline-none focus:border-amber-500"></textarea>
                    </div>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('closeShiftModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-rose-500 text-white font-black text-xs">Close &amp; Lock Shift</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- MODAL 5: HISTORICAL SHIFT DETAILS VIEW MODAL -->
    <?php if ($viewShiftDetail): ?>
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4">
            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-2xl w-full space-y-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-black text-white text-lg">Shift Details #<?= $viewShiftDetail['id'] ?></h3>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase <?= $viewShiftDetail['status'] === 'open' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-zinc-800 text-zinc-400' ?>">
                                <?= ucfirst($viewShiftDetail['status']) ?>
                            </span>
                        </div>
                        <p class="text-xs text-zinc-400">Cashier: <?= htmlspecialchars($viewShiftDetail['staff_name']) ?> &middot; Register: <?= htmlspecialchars($viewShiftDetail['register_name']) ?></p>
                    </div>
                    <a href="shifts.php" class="text-zinc-400 hover:text-white font-bold text-sm">✕ Close</a>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-xs">
                    <div class="p-3 rounded-2xl bg-zinc-950 space-y-0.5">
                        <span class="text-zinc-500 font-bold">Open Time</span>
                        <div class="text-white font-mono font-semibold"><?= date('M d, H:i', strtotime($viewShiftDetail['open_time'])) ?></div>
                    </div>
                    <div class="p-3 rounded-2xl bg-zinc-950 space-y-0.5">
                        <span class="text-zinc-500 font-bold">Close Time</span>
                        <div class="text-white font-mono font-semibold"><?= $viewShiftDetail['close_time'] ? date('M d, H:i', strtotime($viewShiftDetail['close_time'])) : 'Active' ?></div>
                    </div>
                    <div class="p-3 rounded-2xl bg-zinc-950 space-y-0.5">
                        <span class="text-zinc-500 font-bold">Opening Float</span>
                        <div class="text-amber-400 font-mono font-bold">Rs. <?= number_format($viewShiftDetail['opening_cash'], 2) ?></div>
                    </div>
                    <div class="p-3 rounded-2xl bg-zinc-950 space-y-0.5">
                        <span class="text-zinc-500 font-bold">Cash Sales</span>
                        <div class="text-emerald-400 font-mono font-bold">Rs. <?= number_format($viewShiftDetail['cash_sales'], 2) ?></div>
                    </div>
                    <div class="p-3 rounded-2xl bg-zinc-950 space-y-0.5">
                        <span class="text-zinc-500 font-bold">Card Sales</span>
                        <div class="text-blue-400 font-mono font-bold">Rs. <?= number_format($viewShiftDetail['card_sales'], 2) ?></div>
                    </div>
                    <div class="p-3 rounded-2xl bg-zinc-950 space-y-0.5">
                        <span class="text-zinc-500 font-bold">Digital QR Sales</span>
                        <div class="text-purple-400 font-mono font-bold">Rs. <?= number_format($viewShiftDetail['digital_sales'], 2) ?></div>
                    </div>
                    <div class="p-3 rounded-2xl bg-zinc-950 space-y-0.5">
                        <span class="text-zinc-500 font-bold">Expected Cash</span>
                        <div class="text-white font-mono font-bold">Rs. <?= number_format($viewShiftDetail['expected_cash'], 2) ?></div>
                    </div>
                    <div class="p-3 rounded-2xl bg-zinc-950 space-y-0.5">
                        <span class="text-zinc-500 font-bold">Actual Cash</span>
                        <div class="text-white font-mono font-bold">Rs. <?= number_format($viewShiftDetail['closing_cash'], 2) ?></div>
                    </div>
                    <div class="p-3 rounded-2xl bg-zinc-950 space-y-0.5">
                        <span class="text-zinc-500 font-bold">Variance</span>
                        <div class="font-mono font-black <?= $viewShiftDetail['variance'] == 0 ? 'text-emerald-400' : ($viewShiftDetail['variance'] > 0 ? 'text-purple-400' : 'text-rose-400') ?>">
                            Rs. <?= number_format($viewShiftDetail['variance'], 2) ?>
                        </div>
                    </div>
                </div>

                <!-- CASH MOVEMENTS LOG -->
                <?php if (!empty($viewShiftDetail['cash_movements'])): ?>
                    <div class="border-t border-zinc-800 pt-3 space-y-2">
                        <h4 class="font-extrabold text-white text-xs uppercase tracking-wider text-zinc-400">Cash Movements (Cash In / Cash Out)</h4>
                        <div class="space-y-1.5 text-xs font-mono">
                            <?php foreach ($viewShiftDetail['cash_movements'] as $mv): ?>
                                <div class="p-2 rounded bg-zinc-950 flex justify-between items-center">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase <?= $mv['type'] === 'cash_in' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' ?>"><?= $mv['type'] ?></span>
                                    <span class="font-bold text-white"><?= htmlspecialchars($mv['reason']) ?></span>
                                    <span class="font-bold <?= $mv['type'] === 'cash_in' ? 'text-emerald-400' : 'text-rose-400' ?>">Rs. <?= number_format($mv['amount'], 2) ?></span>
                                    <span class="text-zinc-500 text-[10px]"><?= date('H:i', strtotime($mv['created_at'])) ?> by <?= htmlspecialchars($mv['user_name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- DENOMINATIONS BREAKDOWN IF PRESENT -->
                <?php if (!empty($viewShiftDetail['denominations_json'])): 
                    $denoms = json_decode($viewShiftDetail['denominations_json'], true);
                ?>
                    <?php if (!empty($denoms)): ?>
                        <div class="border-t border-zinc-800 pt-3 space-y-2">
                            <h4 class="font-extrabold text-white text-xs uppercase tracking-wider text-zinc-400">Closing Denomination Count</h4>
                            <div class="grid grid-cols-3 gap-2 text-xs font-mono">
                                <?php foreach ($denoms as $denomVal => $count): if ($count <= 0) continue; ?>
                                    <div class="p-2 rounded bg-zinc-950 flex justify-between">
                                        <span class="text-zinc-400">Rs. <?= $denomVal ?> &times; <?= $count ?></span>
                                        <span class="text-white font-bold">Rs. <?= number_format($denomVal * $count, 2) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($viewShiftDetail['notes'])): ?>
                    <div class="border-t border-zinc-800 pt-3 space-y-1">
                        <span class="text-zinc-400 text-xs font-bold">Remarks &amp; Notes:</span>
                        <p class="text-xs text-zinc-300 italic"><?= htmlspecialchars($viewShiftDetail['notes']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <script>
    function calcDenominations() {
        let total = 0;
        document.querySelectorAll('.denom-input').forEach(input => {
            const val = parseFloat(input.getAttribute('data-val')) || 0;
            const count = parseInt(input.value) || 0;
            total += (val * count);
        });
        const actualInput = document.getElementById('actualCashInput');
        if (actualInput && total > 0) {
            actualInput.value = total.toFixed(2);
            calcVariance();
        }
    }

    function calcVariance() {
        const expected = parseFloat(document.getElementById('modalExpectedCash').getAttribute('data-expected')) || 0;
        const actual = parseFloat(document.getElementById('actualCashInput').value) || 0;
        const diff = actual - expected;
        const preview = document.getElementById('variancePreview');
        if (!preview) return;

        if (diff === 0) {
            preview.className = 'font-black font-mono text-sm text-emerald-400';
            preview.innerText = 'Rs. 0.00 (BALANCED)';
        } else if (diff > 0) {
            preview.className = 'font-black font-mono text-sm text-purple-400';
            preview.innerText = '+Rs. ' + diff.toFixed(2) + ' (OVER)';
        } else {
            preview.className = 'font-black font-mono text-sm text-rose-400';
            preview.innerText = '-Rs. ' + Math.abs(diff).toFixed(2) + ' (SHORT)';
        }
    }
    </script>
</body>
</html>
