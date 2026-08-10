<?php
// admin/shifts.php - Register Shift Management, Cash Drawer Float & Variance Auditing
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
$conn = getDBConnection();

$currentPage = 'shifts';
$message = '';
$error = '';

// Provision shifts table
@$conn->query("CREATE TABLE IF NOT EXISTS shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL DEFAULT 1,
    staff_id INT DEFAULT 1,
    staff_name VARCHAR(100) NOT NULL,
    open_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    close_time TIMESTAMP NULL DEFAULT NULL,
    opening_cash DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    closing_cash DECIMAL(10, 2) DEFAULT 0.00,
    expected_cash DECIMAL(10, 2) DEFAULT 0.00,
    cash_sales DECIMAL(10, 2) DEFAULT 0.00,
    card_sales DECIMAL(10, 2) DEFAULT 0.00,
    digital_sales DECIMAL(10, 2) DEFAULT 0.00,
    total_refunds DECIMAL(10, 2) DEFAULT 0.00,
    total_ncr DECIMAL(10, 2) DEFAULT 0.00,
    variance DECIMAL(10, 2) DEFAULT 0.00,
    status ENUM('open', 'closed') DEFAULT 'open',
    notes TEXT,
    INDEX idx_shift_status (restaurant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$staffName = $_SESSION['admin_username'] ?? 'Cashier';

// Check if active open shift exists
$activeShift = null;
$s_res = $conn->query("SELECT * FROM shifts WHERE restaurant_id = $tenantId AND status = 'open' ORDER BY id DESC LIMIT 1");
if ($s_res && $s_res->num_rows > 0) {
    $activeShift = $s_res->fetch_assoc();
}

// Handle POST Open / Close Shift Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'open_shift') {
        if ($activeShift) {
            $error = "An active register shift is already open. Close current shift first.";
        } else {
            $float = (float)($_POST['opening_cash'] ?? 0.0);
            $stmt = $conn->prepare("INSERT INTO shifts (restaurant_id, staff_name, opening_cash, status) VALUES (?, ?, ?, 'open')");
            $stmt->bind_param("isd", $tenantId, $staffName, $float);
            if ($stmt->execute()) {
                $message = "Register shift opened successfully with Rs. " . number_format($float, 2) . " opening float!";
            } else {
                $error = "Failed to open shift: " . $conn->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'close_shift' && $activeShift) {
        $actualCash = (float)($_POST['closing_cash'] ?? 0.0);
        $notes = Security::sanitize($_POST['notes'] ?? '');

        // Calculate sales during this shift window
        $openTime = $activeShift['open_time'];
        $cashSales = 0.0;
        $cardSales = 0.0;
        $digitalSales = 0.0;

        $sales_stmt = $conn->prepare("SELECT payment_method, SUM(total_amount) as sum_total FROM orders WHERE restaurant_id = ? AND payment_status = 'paid' AND created_at >= ? GROUP BY payment_method");
        $sales_stmt->bind_param("is", $tenantId, $openTime);
        $sales_stmt->execute();
        $sres = $sales_stmt->get_result();
        while ($srow = $sres->fetch_assoc()) {
            $m = strtolower($srow['payment_method']);
            $amt = (float)$srow['sum_total'];
            if ($m === 'cash') $cashSales += $amt;
            elseif ($m === 'card') $cardSales += $amt;
            else $digitalSales += $amt;
        }
        $sales_stmt->close();

        $expectedCash = $activeShift['opening_cash'] + $cashSales;
        $variance = $actualCash - $expectedCash;

        $close_stmt = $conn->prepare("UPDATE shifts SET close_time = NOW(), closing_cash = ?, expected_cash = ?, cash_sales = ?, card_sales = ?, digital_sales = ?, variance = ?, status = 'closed', notes = ? WHERE id = ? AND restaurant_id = ?");
        $close_stmt->bind_param("ddddddsii", $actualCash, $expectedCash, $cashSales, $cardSales, $digitalSales, $variance, $notes, $activeShift['id'], $tenantId);
        if ($close_stmt->execute()) {
            $message = "Shift CLOSED! Expected Cash: Rs. " . number_format($expectedCash, 2) . " | Variance: Rs. " . number_format($variance, 2);
            $activeShift = null;
        } else {
            $error = "Failed to close shift: " . $conn->error;
        }
        $close_stmt->close();
    }
}

// Fetch Past Shifts History
$shiftsHistory = [];
$sh_res = $conn->query("SELECT * FROM shifts WHERE restaurant_id = $tenantId ORDER BY id DESC LIMIT 20");
if ($sh_res) {
    while ($row = $sh_res->fetch_assoc()) {
        $shiftsHistory[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 font-sans antialiased text-white selection:bg-amber-500 selection:text-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Register Management — RMS SaaS</title>
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
            <?php if (!$activeShift): ?>
                <button onclick="document.getElementById('openShiftModal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                    🔑 Open Register Shift
                </button>
            <?php else: ?>
                <button onclick="document.getElementById('closeShiftModal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-rose-500 text-white font-black text-xs active:scale-95 shadow-lg shadow-rose-500/20">
                    🔒 Close Active Shift
                </button>
            <?php endif; ?>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <?php if ($message): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">✅ <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Active Shift Card Status -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl <?= $activeShift ? 'bg-emerald-500/20 text-emerald-400' : 'bg-zinc-800 text-zinc-500' ?> flex items-center justify-center font-black text-xl">
                            <?= $activeShift ? '🟢' : '⚪' ?>
                        </div>
                        <div>
                            <h2 class="text-base font-black text-white"><?= $activeShift ? 'Active Register Shift #' . $activeShift['id'] : 'No Active Shift Open' ?></h2>
                            <p class="text-xs text-zinc-400"><?= $activeShift ? 'Opened by ' . htmlspecialchars($activeShift['staff_name']) . ' at ' . date('h:i A', strtotime($activeShift['open_time'])) : 'Open a shift to start taking cashier payments.' ?></p>
                        </div>
                    </div>
                    <?php if ($activeShift): ?>
                        <div class="text-right">
                            <span class="text-xs text-zinc-400 font-bold block">Opening Float</span>
                            <span class="text-lg font-black text-amber-400">Rs. <?= number_format($activeShift['opening_cash'], 2) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Shifts History Table -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-black text-white">Shift Audit History</h2>
                    <span class="text-xs text-zinc-500 font-bold">Past 20 Register Shifts</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <th class="py-2.5 px-3">Shift ID</th>
                                <th class="py-2.5 px-3">Staff</th>
                                <th class="py-2.5 px-3">Open Time</th>
                                <th class="py-2.5 px-3">Close Time</th>
                                <th class="py-2.5 px-3">Opening Float</th>
                                <th class="py-2.5 px-3">Cash Sales</th>
                                <th class="py-2.5 px-3">Expected Cash</th>
                                <th class="py-2.5 px-3">Actual Cash</th>
                                <th class="py-2.5 px-3">Variance</th>
                                <th class="py-2.5 px-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                            <?php if (empty($shiftsHistory)): ?>
                                <tr><td colspan="10" class="py-8 text-center text-zinc-500">No shift history recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($shiftsHistory as $sh): ?>
                                    <tr class="hover:bg-zinc-800/40">
                                        <td class="py-3 px-3 font-mono font-bold text-amber-400">#<?= $sh['id'] ?></td>
                                        <td class="py-3 px-3 text-white font-bold"><?= htmlspecialchars($sh['staff_name']) ?></td>
                                        <td class="py-3 px-3 text-zinc-400 text-[11px]"><?= date('M d, H:i', strtotime($sh['open_time'])) ?></td>
                                        <td class="py-3 px-3 text-zinc-400 text-[11px]"><?= $sh['close_time'] ? date('M d, H:i', strtotime($sh['close_time'])) : 'Active' ?></td>
                                        <td class="py-3 px-3 font-bold">Rs. <?= number_format($sh['opening_cash'], 2) ?></td>
                                        <td class="py-3 px-3 font-bold text-emerald-400">Rs. <?= number_format($sh['cash_sales'], 2) ?></td>
                                        <td class="py-3 px-3 font-bold text-white">Rs. <?= number_format($sh['expected_cash'], 2) ?></td>
                                        <td class="py-3 px-3 font-bold text-white">Rs. <?= number_format($sh['closing_cash'], 2) ?></td>
                                        <td class="py-3 px-3 font-black <?= $sh['variance'] >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">Rs. <?= number_format($sh['variance'], 2) ?></td>
                                        <td class="py-3 px-3">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase <?= $sh['status'] === 'open' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-zinc-800 text-zinc-400' ?>">
                                                <?= ucfirst($sh['status']) ?>
                                            </span>
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

    <!-- Modal Open Shift -->
    <div id="openShiftModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-sm w-full space-y-4">
            <?= CSRF::getField() ?>
            <input type="hidden" name="action" value="open_shift">
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">Open Cashier Shift</h3>
                <button type="button" onclick="document.getElementById('openShiftModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Opening Cash Float (Rs.)</label>
                    <input type="number" step="0.01" name="opening_cash" value="1000.00" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white font-black text-base outline-none focus:border-amber-500">
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('openShiftModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">Open Shift</button>
            </div>
        </form>
    </div>

    <!-- Modal Close Shift -->
    <div id="closeShiftModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-sm w-full space-y-4">
            <?= CSRF::getField() ?>
            <input type="hidden" name="action" value="close_shift">
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">Close Cashier Shift</h3>
                <button type="button" onclick="document.getElementById('closeShiftModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Actual Cash Counted in Drawer (Rs.)</label>
                    <input type="number" step="0.01" name="closing_cash" required placeholder="Enter total cash counted" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white font-black text-base outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Shift Notes / Remarks</label>
                    <textarea name="notes" placeholder="Optional notes about drawer variance..." class="w-full h-20 bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-white outline-none focus:border-amber-500"></textarea>
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('closeShiftModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-rose-500 text-white font-black text-xs">Close &amp; Balance Shift</button>
            </div>
        </form>
    </div>
</body>
</html>
