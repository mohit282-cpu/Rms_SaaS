<?php
// admin/shifts.php - Work Shift Management & Cash Drawer Reconciliation UI (Phase 3)
require_once __DIR__ . '/../config.php';
requireAdminLogin();
RBAC::requirePermission('manage_shifts');

$currentPage = 'shifts';
$tenantId = TenantContext::getTenantId();
$userId = (int)$_SESSION['admin_id'];

$conn = getDBConnection();
$activeShift = ShiftService::getActiveShift($conn, $userId, $tenantId);

$sStmt = $conn->prepare("SELECT * FROM work_shifts WHERE restaurant_id = ? ORDER BY id DESC LIMIT 50");
$sStmt->bind_param("i", $tenantId);
$sStmt->execute();
$history = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sStmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Work Shift Management & Cash Reconciliation - QR Cafe</title>
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
                        <span>⏱️</span> Work Shift & Cash Drawer Reconciliation
                    </h1>
                    <p class="text-xs text-zinc-400">Open/close cashier shifts, track drawer floats, and audit cash variances</p>
                </div>
            </header>

            <div class="p-4 md:p-8 max-w-6xl mx-auto space-y-6">

                <!-- ACTIVE SHIFT BANNER -->
                <?php if ($activeShift): ?>
                    <div class="bg-amber-500/10 border border-amber-500/30 rounded-3xl p-6 space-y-4 shadow-xl">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <span class="px-3 py-1 rounded-full bg-amber-500 text-zinc-950 font-black text-[10px]">🟢 ACTIVE OPEN SHIFT</span>
                                <h3 class="text-lg font-black text-white mt-2"><?= htmlspecialchars($activeShift['shift_name']) ?></h3>
                                <p class="text-xs text-amber-300">Opened at <?= date('M d, Y h:i A', strtotime($activeShift['opened_at'])) ?> with Opening Float NPR <?= number_format($activeShift['opening_cash'], 2) ?></p>
                            </div>
                            <button onclick="openCloseModal(<?= $activeShift['id'] ?>)" class="h-11 px-6 rounded-2xl bg-rose-500 text-white font-black text-xs hover:brightness-110 active:scale-95 transition-all shadow-lg shadow-rose-500/20">
                                🛑 Close Shift & Balance Cash Drawer
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-8 text-center space-y-4 shadow-xl">
                        <div class="text-4xl">⏱️</div>
                        <h3 class="text-base font-bold text-white">No Active Cashier Shift</h3>
                        <p class="text-xs text-zinc-400 max-w-sm mx-auto">Open a cash register shift to start processing sales and recording cash drawer floats.</p>
                        <button onclick="openOpenModal()" class="h-11 px-6 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 transition-all shadow-lg shadow-amber-500/20">
                            🔑 Open New Register Shift
                        </button>
                    </div>
                <?php endif; ?>

                <!-- SHIFT HISTORY & VARIANCE LOG -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                            <span>🧾</span> Shift Audit History
                        </h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider font-bold">
                                    <th class="py-3 px-4">Shift Name</th>
                                    <th class="py-3 px-4">Status</th>
                                    <th class="py-3 px-4">Opening Float</th>
                                    <th class="py-3 px-4">Cash Sales</th>
                                    <th class="py-3 px-4">Expected Cash</th>
                                    <th class="py-3 px-4">Actual Cash</th>
                                    <th class="py-3 px-4">Variance</th>
                                    <th class="py-3 px-4 text-right">Closed At</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-200">
                                <?php if (empty($history)): ?>
                                    <tr><td colspan="8" class="py-8 text-center text-zinc-500 italic">No shift history found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($history as $s): ?>
                                        <tr class="hover:bg-zinc-800/30 transition-colors">
                                            <td class="py-3.5 px-4 font-bold text-white"><?= htmlspecialchars($s['shift_name']) ?></td>
                                            <td class="py-3.5 px-4">
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-black uppercase border <?= $s['status'] === 'open' ? 'bg-amber-500/10 border-amber-500/30 text-amber-400' : 'bg-zinc-800 text-zinc-300' ?>">
                                                    <?= $s['status'] ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4">NPR <?= number_format($s['opening_cash'], 2) ?></td>
                                            <td class="py-3.5 px-4 font-bold text-emerald-400">NPR <?= number_format($s['cash_sales'], 2) ?></td>
                                            <td class="py-3.5 px-4 font-bold">NPR <?= number_format($s['closing_cash_expected'] ?? 0, 2) ?></td>
                                            <td class="py-3.5 px-4 font-bold text-white">NPR <?= number_format($s['closing_cash_actual'] ?? 0, 2) ?></td>
                                            <td class="py-3.5 px-4">
                                                <?php $v = floatval($s['variance']); ?>
                                                <span class="font-black <?= $v == 0 ? 'text-emerald-400' : ($v < 0 ? 'text-rose-400' : 'text-amber-400') ?>">
                                                    <?= $v >= 0 ? '+' : '' ?>NPR <?= number_format($v, 2) ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 text-right text-zinc-400"><?= $s['closed_at'] ? date('M d, H:i', strtotime($s['closed_at'])) : 'Active' ?></td>
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

    <!-- OPEN SHIFT MODAL -->
    <div id="openModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 w-full max-w-md space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 class="text-sm font-black text-white">Open Cash Register Shift</h3>
                <button onclick="closeOpenModal()" class="text-zinc-500 hover:text-white font-bold text-sm">✕</button>
            </div>

            <form id="openForm" onsubmit="event.preventDefault(); submitOpenShift();" class="space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="open">

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Shift Name / Description</label>
                    <input type="text" name="shift_name" required value="Morning Shift" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Opening Cash Float (NPR)</label>
                    <input type="number" step="0.01" name="opening_cash" required value="1000.00" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110">Open Shift</button>
            </form>
        </div>
    </div>

    <!-- CLOSE SHIFT MODAL -->
    <div id="closeModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 w-full max-w-md space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 class="text-sm font-black text-white">Close & Reconcile Shift</h3>
                <button onclick="closeCloseModal()" class="text-zinc-500 hover:text-white font-bold text-sm">✕</button>
            </div>

            <form id="closeForm" onsubmit="event.preventDefault(); submitCloseShift();" class="space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="shift_id" id="targetShiftId">

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Actual Counted Cash in Drawer (NPR)</label>
                    <input type="number" step="0.01" name="actual_cash" required placeholder="0.00" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Reconciliation Notes (Optional)</label>
                    <input type="text" name="notes" placeholder="e.g. NPR 50 short due to change discrepancy" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-rose-500 text-white font-black text-xs hover:brightness-110">Close Shift & Submit Count</button>
            </form>
        </div>
    </div>

    <script src="../js/modern.js"></script>
    <script>
        function openOpenModal() { document.getElementById('openModal').classList.remove('hidden'); }
        function closeOpenModal() { document.getElementById('openModal').classList.add('hidden'); }
        function openCloseModal(id) { document.getElementById('targetShiftId').value = id; document.getElementById('closeModal').classList.remove('hidden'); }
        function closeCloseModal() { document.getElementById('closeModal').classList.add('hidden'); }

        function submitOpenShift() {
            const formData = new FormData(document.getElementById('openForm'));
            fetch('../api/shifts.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Shift opened!', 'success'); setTimeout(() => location.reload(), 800); }
                    else showToast(data.message || 'Error opening shift', 'error');
                });
        }

        function submitCloseShift() {
            const formData = new FormData(document.getElementById('closeForm'));
            fetch('../api/shifts.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Shift closed and reconciled!', 'success'); setTimeout(() => location.reload(), 800); }
                    else showToast(data.message || 'Error closing shift', 'error');
                });
        }
    </script>
</body>
</html>
