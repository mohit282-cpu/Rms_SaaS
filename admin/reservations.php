<?php
// admin/reservations.php - Table Reservation Management UI (Phase 3)
require_once __DIR__ . '/../config.php';
requireAdminLogin();
RBAC::requirePermission('manage_reservations');

$currentPage = 'reservations';
$tenantId = TenantContext::getTenantId();

$conn = getDBConnection();
$today = date('Y-m-d');
$rStmt = $conn->prepare("SELECT * FROM reservations WHERE restaurant_id = ? AND reservation_date >= ? ORDER BY reservation_date ASC, reservation_time ASC LIMIT 100");
$rStmt->bind_param("is", $tenantId, $today);
$rStmt->execute();
$reservations = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rStmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Table Reservations - QR Cafe</title>
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
                        <span>📅</span> Table Reservations Manager
                    </h1>
                    <p class="text-xs text-zinc-400">Schedule table bookings, manage guest arrivals, and prevent double-booking</p>
                </div>
                <button onclick="openModal()" class="h-10 px-5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 transition-all flex items-center gap-2 shadow-lg shadow-amber-500/20">
                    <span>➕</span> <span>New Reservation</span>
                </button>
            </header>

            <div class="p-4 md:p-8 max-w-6xl mx-auto space-y-6">

                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                            <span>📅</span> Upcoming Table Bookings
                        </h3>
                        <span class="text-xs font-bold text-amber-400 bg-amber-500/10 border border-amber-500/30 px-3 py-1 rounded-full">
                            Total Bookings: <?= count($reservations) ?>
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider font-bold">
                                    <th class="py-3 px-4">Date & Time</th>
                                    <th class="py-3 px-4">Customer Name</th>
                                    <th class="py-3 px-4">Phone</th>
                                    <th class="py-3 px-4">Guests</th>
                                    <th class="py-3 px-4">Table</th>
                                    <th class="py-3 px-4">Status</th>
                                    <th class="py-3 px-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-200">
                                <?php if (empty($reservations)): ?>
                                    <tr><td colspan="7" class="py-8 text-center text-zinc-500 italic">No upcoming reservations scheduled.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($reservations as $r): ?>
                                        <tr class="hover:bg-zinc-800/30 transition-colors">
                                            <td class="py-3.5 px-4 font-bold text-amber-400">
                                                <?= date('M d, Y', strtotime($r['reservation_date'])) ?> at <?= date('h:i A', strtotime($r['reservation_time'])) ?>
                                            </td>
                                            <td class="py-3.5 px-4 font-bold text-white"><?= htmlspecialchars($r['customer_name']) ?></td>
                                            <td class="py-3.5 px-4 font-mono text-zinc-300"><?= htmlspecialchars($r['phone']) ?></td>
                                            <td class="py-3.5 px-4 font-bold"><?= $r['guest_count'] ?> Guests</td>
                                            <td class="py-3.5 px-4 font-bold text-amber-400"><?= htmlspecialchars($r['table_number'] ?: 'Unassigned') ?></td>
                                            <td class="py-3.5 px-4">
                                                <span class="px-2.5 py-0.5 rounded-lg text-[10px] font-black uppercase border border-amber-500/30 bg-amber-500/10 text-amber-400">
                                                    <?= $r['status'] ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 text-right space-x-1">
                                                <?php if ($r['status'] === 'confirmed'): ?>
                                                    <button onclick="updateStatus(<?= $r['id'] ?>, 'arrived')" class="px-2 py-1 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 rounded-lg text-[10px] font-bold">Arrived</button>
                                                <?php endif; ?>
                                                <button onclick="updateStatus(<?= $r['id'] ?>, 'cancelled')" class="px-2 py-1 bg-rose-500/10 border border-rose-500/30 text-rose-400 rounded-lg text-[10px] font-bold">Cancel</button>
                                            </td>
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

    <!-- NEW RESERVATION MODAL -->
    <div id="resModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 w-full max-w-md space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 class="text-sm font-black text-white">Book Table Reservation</h3>
                <button onclick="closeModal()" class="text-zinc-500 hover:text-white font-bold text-sm">✕</button>
            </div>

            <form id="resForm" onsubmit="event.preventDefault(); submitReservation();" class="space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="create">

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Customer Name</label>
                    <input type="text" name="customer_name" required placeholder="e.g. Suman Thapa" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Phone Number</label>
                    <input type="text" name="phone" required placeholder="e.g. 9801234567" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-mono outline-none focus:border-amber-500">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-zinc-300">Date</label>
                        <input type="date" name="reservation_date" value="<?= date('Y-m-d') ?>" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-zinc-300">Time</label>
                        <input type="time" name="reservation_time" value="19:00" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-zinc-300">Guest Count</label>
                        <input type="number" name="guest_count" value="2" min="1" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-zinc-300">Table Number</label>
                        <input type="text" name="table_number" placeholder="e.g. T-04" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                    </div>
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110">Confirm Reservation</button>
            </form>
        </div>
    </div>

    <script src="../js/modern.js"></script>
    <script>
        function openModal() { document.getElementById('resModal').classList.remove('hidden'); }
        function closeModal() { document.getElementById('resModal').classList.add('hidden'); }

        function submitReservation() {
            const formData = new FormData(document.getElementById('resForm'));
            fetch('../api/reservations.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Reservation booked!', 'success'); setTimeout(() => location.reload(), 800); }
                    else showToast(data.message || 'Error booking reservation', 'error');
                });
        }

        function updateStatus(id, status) {
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('reservation_id', id);
            formData.append('status', status);
            formData.append('csrf_token', '<?= CSRF::generateToken() ?>');

            fetch('../api/reservations.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Status updated!', 'success'); setTimeout(() => location.reload(), 800); }
                });
        }
    </script>
</body>
</html>
