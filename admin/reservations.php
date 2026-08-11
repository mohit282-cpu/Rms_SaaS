<?php
// admin/reservations.php - Table Reservation Management & Conflict Prevention
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
$conn = getDBConnection();

$currentPage = 'reservations';
$message = '';
$error = '';

// Provision table if missing
@$conn->query("CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL DEFAULT 1,
    customer_name VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(30) NOT NULL,
    customer_email VARCHAR(100) DEFAULT '',
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    guest_count INT DEFAULT 2,
    table_number VARCHAR(20) DEFAULT '',
    status ENUM('pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show') DEFAULT 'confirmed',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_res_date (restaurant_id, reservation_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Handle POST Create Reservation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();

    $name = Security::sanitize($_POST['customer_name'] ?? '');
    $phone = Security::sanitize($_POST['customer_phone'] ?? '');
    $date = Security::sanitize($_POST['reservation_date'] ?? date('Y-m-d'));
    $time = Security::sanitize($_POST['reservation_time'] ?? '18:00');
    $guests = intval($_POST['guest_count'] ?? 2);
    $tableNum = Security::sanitize($_POST['table_number'] ?? '');
    $notes = Security::sanitize($_POST['notes'] ?? '');

    if (empty($name) || empty($phone)) {
        $error = "Customer name and phone number are required.";
    } else {
        // Prevent double booking conflict for same table & time
        if (!empty($tableNum)) {
            $conflict = $conn->prepare("SELECT id FROM reservations WHERE restaurant_id = ? AND table_number = ? AND reservation_date = ? AND reservation_time = ? AND status IN ('confirmed', 'seated') LIMIT 1");
            $conflict->bind_param("isss", $tenantId, $tableNum, $date, $time);
            $conflict->execute();
            $res = $conflict->get_result();
            if ($res && $res->num_rows > 0) {
                $error = "Table #$tableNum is already reserved for $date at $time. Please select another table or time.";
            }
            $conflict->close();
        }

        if (empty($error)) {
            $stmt = $conn->prepare("INSERT INTO reservations (restaurant_id, customer_name, customer_phone, reservation_date, reservation_time, guest_count, table_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssiss", $tenantId, $name, $phone, $date, $time, $guests, $tableNum, $notes);
            if ($stmt->execute()) {
                $message = "Reservation for '$name' created successfully!";
                if (!empty($tableNum)) {
                    $uTbl = $conn->prepare("UPDATE tables SET status = 'reserved', reserved_by = ?, guest_count = ? WHERE restaurant_id = ? AND table_number = ?");
                    $uTbl->bind_param("siis", $name, $guests, $tenantId, $tableNum);
                    $uTbl->execute();
                    $uTbl->close();
                }
            } else {
                $error = "Failed to create reservation: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// Fetch Active Reservations
$reservations = [];
$r_res = $conn->query("SELECT * FROM reservations WHERE restaurant_id = $tenantId ORDER BY reservation_date ASC, reservation_time ASC");
if ($r_res) {
    while ($row = $r_res->fetch_assoc()) {
        $reservations[] = $row;
    }
}

// Fetch tables for dropdown
$tables = [];
$t_res = $conn->query("SELECT table_number, capacity FROM tables WHERE restaurant_id = $tenantId ORDER BY table_number ASC");
if ($t_res) {
    while ($t = $t_res->fetch_assoc()) {
        $tables[] = $t;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 font-sans antialiased text-white selection:bg-amber-500 selection:text-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Table Reservations — RMS SaaS</title>
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
                <h1 class="text-lg md:text-xl font-black text-white">Table Reservation Management</h1>
                <p class="text-xs text-zinc-400">Bookings, Guest Scheduling &amp; Table Conflict Prevention</p>
            </div>
            <button onclick="document.getElementById('addResModal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                📅 New Reservation
            </button>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <?php if ($message): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">✅ <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-black text-white">Upcoming Reservations</h2>
                    <span class="text-xs text-zinc-500 font-bold">Total: <?= count($reservations) ?> Bookings</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <th class="py-2.5 px-3">Date &amp; Time</th>
                                <th class="py-2.5 px-3">Customer</th>
                                <th class="py-2.5 px-3">Phone</th>
                                <th class="py-2.5 px-3">Guests</th>
                                <th class="py-2.5 px-3">Table</th>
                                <th class="py-2.5 px-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                            <?php if (empty($reservations)): ?>
                                <tr><td colspan="6" class="py-8 text-center text-zinc-500">No reservations recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reservations as $r): ?>
                                    <tr class="hover:bg-zinc-800/40">
                                        <td class="py-3 px-3 font-bold text-amber-400">
                                            <?= date('M d, Y', strtotime($r['reservation_date'])) ?> @ <?= date('h:i A', strtotime($r['reservation_time'])) ?>
                                        </td>
                                        <td class="py-3 px-3 text-white font-bold"><?= htmlspecialchars($r['customer_name']) ?></td>
                                        <td class="py-3 px-3 font-mono text-zinc-400"><?= htmlspecialchars($r['customer_phone']) ?></td>
                                        <td class="py-3 px-3">👥 <?= intval($r['guest_count']) ?> Guests</td>
                                        <td class="py-3 px-3 font-bold text-white"><?= $r['table_number'] ? 'Table #' . htmlspecialchars($r['table_number']) : 'Unassigned' ?></td>
                                        <td class="py-3 px-3">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-amber-500/20 text-amber-400 border border-amber-500/30">
                                                <?= htmlspecialchars($r['status']) ?>
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

    <!-- Modal New Reservation -->
    <div id="addResModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
            <?= CSRF::getField() ?>
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">New Table Reservation</h3>
                <button type="button" onclick="document.getElementById('addResModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Customer Name</label>
                    <input type="text" name="customer_name" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Customer Phone</label>
                    <input type="text" name="customer_phone" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Date</label>
                        <input type="date" name="reservation_date" value="<?= date('Y-m-d') ?>" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Time</label>
                        <input type="time" name="reservation_time" value="18:00" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Guest Count</label>
                        <input type="number" name="guest_count" value="2" min="1" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Assign Table</label>
                        <select name="table_number" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                            <option value="">-- Select Table --</option>
                            <?php foreach ($tables as $tb): ?>
                                <option value="<?= htmlspecialchars($tb['table_number']) ?>">Table <?= htmlspecialchars($tb['table_number']) ?> (<?= $tb['capacity'] ?> Seats)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('addResModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">Save Reservation</button>
            </div>
        </form>
    </div>
</body>
</html>
