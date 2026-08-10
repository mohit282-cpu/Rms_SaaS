<?php
// admin/customers.php - Customer CRM, Loyalty Ledger & Spending Analytics
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
$conn = getDBConnection();

$currentPage = 'customers';
$message = '';
$error = '';

// Handle POST Customer Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();

    $name = Security::sanitize($_POST['name'] ?? '');
    $phone = Security::sanitize($_POST['phone'] ?? '');
    $email = Security::sanitize($_POST['email'] ?? '');

    if (empty($name) || empty($phone)) {
        $error = "Customer name and phone number are required.";
    } else {
        $stmt = $conn->prepare("INSERT INTO customers (restaurant_id, name, phone, email) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email)");
        $stmt->bind_param("isss", $tenantId, $name, $phone, $email);
        if ($stmt->execute()) {
            $message = "Customer '$name' saved successfully!";
        } else {
            $error = "Failed to save customer: " . $conn->error;
        }
        $stmt->close();
    }
}

// Search & List Customers
$search = Security::sanitize($_GET['q'] ?? '');
$sql = "SELECT id, name, phone, email, total_visits, total_spent, loyalty_points, tier, last_visit_at, created_at FROM customers WHERE restaurant_id = $tenantId";
if ($search !== '') {
    $sql .= " AND (name LIKE '%" . $conn->real_escape_string($search) . "%' OR phone LIKE '%" . $conn->real_escape_string($search) . "%')";
}
$sql .= " ORDER BY total_spent DESC LIMIT 50";

$customers = [];
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $customers[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 font-sans antialiased text-white selection:bg-amber-500 selection:text-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer CRM &amp; Loyalty — RMS SaaS</title>
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
                <h1 class="text-lg md:text-xl font-black text-white">Customer CRM &amp; Loyalty Ledger</h1>
                <p class="text-xs text-zinc-400">Customer Profiles, Visit History, Total Spending &amp; Loyalty Rewards</p>
            </div>
            <button onclick="document.getElementById('addCustModal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                👤 Add Customer
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
                    <form method="GET" class="relative max-w-sm w-full">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search customer name or phone..." class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl pl-9 pr-4 text-xs text-white outline-none focus:border-amber-500">
                        <span class="absolute left-3 top-2.5 text-xs text-zinc-500">🔍</span>
                    </form>
                    <span class="text-xs text-zinc-500 font-bold">Total: <?= count($customers) ?> Customers</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <th class="py-2.5 px-3">Customer Name</th>
                                <th class="py-2.5 px-3">Phone</th>
                                <th class="py-2.5 px-3">Tier</th>
                                <th class="py-2.5 px-3">Visits</th>
                                <th class="py-2.5 px-3">Total Spent</th>
                                <th class="py-2.5 px-3">Loyalty Pts</th>
                                <th class="py-2.5 px-3">Last Visit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                            <?php if (empty($customers)): ?>
                                <tr><td colspan="7" class="py-8 text-center text-zinc-500">No customers found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($customers as $c): ?>
                                    <tr class="hover:bg-zinc-800/40">
                                        <td class="py-3 px-3 font-bold text-white"><?= htmlspecialchars($c['name']) ?></td>
                                        <td class="py-3 px-3 font-mono text-amber-400"><?= htmlspecialchars($c['phone']) ?></td>
                                        <td class="py-3 px-3">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                                <?= htmlspecialchars($c['tier'] ?: 'Bronze') ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-3 font-bold text-white"><?= intval($c['total_visits']) ?> visits</td>
                                        <td class="py-3 px-3 font-black text-emerald-400">Rs. <?= number_format($c['total_spent'], 2) ?></td>
                                        <td class="py-3 px-3 font-bold text-amber-400">⭐ <?= intval($c['loyalty_points']) ?> pts</td>
                                        <td class="py-3 px-3 text-zinc-400 text-[11px]"><?= $c['last_visit_at'] ? date('M d, Y', strtotime($c['last_visit_at'])) : 'N/A' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- Modal Add Customer -->
    <div id="addCustModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
            <?= CSRF::getField() ?>
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">Add New Customer</h3>
                <button type="button" onclick="document.getElementById('addCustModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Customer Name</label>
                    <input type="text" name="name" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Phone Number</label>
                    <input type="text" name="phone" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Email Address (Optional)</label>
                    <input type="email" name="email" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('addCustModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">Save Customer</button>
            </div>
        </form>
    </div>
</body>
</html>
