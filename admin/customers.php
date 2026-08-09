<?php
// admin/customers.php - Customer Relationship Management (CRM) UI (Phase 3)
require_once __DIR__ . '/../config.php';
requireAdminLogin();
RBAC::requirePermission('manage_customers');

$currentPage = 'customers';
$tenantId = TenantContext::getTenantId();

$conn = getDBConnection();
$cStmt = $conn->prepare("SELECT * FROM customers WHERE restaurant_id = ? ORDER BY id DESC LIMIT 100");
$cStmt->bind_param("i", $tenantId);
$cStmt->execute();
$customers = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$cStmt->close();

$mRes = $conn->query("
    SELECT 
        COUNT(*) as total_customers,
        COALESCE(SUM(total_spent), 0.00) as total_revenue,
        COALESCE(AVG(total_spent), 0.00) as avg_clv
    FROM customers WHERE restaurant_id = $tenantId
");
$metrics = $mRes ? $mRes->fetch_assoc() : [];
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Customer Directory & CRM - QR Cafe</title>
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
                        <span>🎴</span> Customer Relationship Management (CRM)
                    </h1>
                    <p class="text-xs text-zinc-400">Track customer spending, order history, visit frequencies, and loyalty tiers</p>
                </div>
                <button onclick="openCreateModal()" class="h-10 px-5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 transition-all flex items-center gap-2 shadow-lg shadow-amber-500/20">
                    <span>➕</span> <span>New Customer</span>
                </button>
            </header>

            <div class="p-4 md:p-8 max-w-6xl mx-auto space-y-6">

                <!-- KPI METRICS CARDS -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-1 shadow-xl">
                        <span class="text-xs text-zinc-400 font-bold">🎴 Total Registered Customers</span>
                        <div class="text-2xl font-black text-white"><?= number_format($metrics['total_customers'] ?? 0) ?></div>
                    </div>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-1 shadow-xl">
                        <span class="text-xs text-zinc-400 font-bold">💵 Total Lifetime Spend</span>
                        <div class="text-2xl font-black text-amber-400">NPR <?= number_format($metrics['total_revenue'] ?? 0, 2) ?></div>
                    </div>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-1 shadow-xl">
                        <span class="text-xs text-zinc-400 font-bold">📈 Average Customer Value (CLV)</span>
                        <div class="text-2xl font-black text-emerald-400">NPR <?= number_format($metrics['avg_clv'] ?? 0, 2) ?></div>
                    </div>
                </div>

                <!-- CUSTOMER DIRECTORY -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-zinc-800 pb-3">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                            <span>👥</span> Customer Profiles Directory
                        </h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider font-bold">
                                    <th class="py-3 px-4">Customer</th>
                                    <th class="py-3 px-4">Phone</th>
                                    <th class="py-3 px-4">Loyalty Tier</th>
                                    <th class="py-3 px-4">Points</th>
                                    <th class="py-3 px-4">Visits</th>
                                    <th class="py-3 px-4">Total Spent</th>
                                    <th class="py-3 px-4 text-right">Joined</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-200">
                                <?php if (empty($customers)): ?>
                                    <tr><td colspan="7" class="py-8 text-center text-zinc-500 italic">No customer profiles added yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($customers as $c): ?>
                                        <tr class="hover:bg-zinc-800/30 transition-colors">
                                            <td class="py-3.5 px-4 font-bold text-white"><?= htmlspecialchars($c['name']) ?></td>
                                            <td class="py-3.5 px-4 font-mono text-amber-400"><?= htmlspecialchars($c['phone']) ?></td>
                                            <td class="py-3.5 px-4">
                                                <span class="px-2.5 py-0.5 rounded-lg text-[10px] font-black uppercase border border-amber-500/30 bg-amber-500/10 text-amber-400">
                                                    <?= $c['tier'] ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 font-bold text-emerald-400"><?= $c['loyalty_points'] ?> pts</td>
                                            <td class="py-3.5 px-4"><?= $c['total_visits'] ?></td>
                                            <td class="py-3.5 px-4 font-black text-white">NPR <?= number_format($c['total_spent'], 2) ?></td>
                                            <td class="py-3.5 px-4 text-right text-zinc-400"><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
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

    <!-- CREATE CUSTOMER MODAL -->
    <div id="createModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 w-full max-w-md space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 class="text-sm font-black text-white">New Customer Profile</h3>
                <button onclick="closeCreateModal()" class="text-zinc-500 hover:text-white font-bold text-sm">✕</button>
            </div>

            <form id="createCustomerForm" onsubmit="event.preventDefault(); submitCustomer();" class="space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="create">

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Customer Name</label>
                    <input type="text" name="name" required placeholder="e.g. Anish Sharma" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Phone Number</label>
                    <input type="text" name="phone" required placeholder="e.g. 9841234567" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-mono outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Email Address (Optional)</label>
                    <input type="email" name="email" placeholder="e.g. anish@example.com" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110">Save Customer Profile</button>
            </form>
        </div>
    </div>

    <script src="../js/modern.js"></script>
    <script>
        function openCreateModal() { document.getElementById('createModal').classList.remove('hidden'); }
        function closeCreateModal() { document.getElementById('createModal').classList.add('hidden'); }

        function submitCustomer() {
            const formData = new FormData(document.getElementById('createCustomerForm'));
            fetch('../api/customers.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Customer created!', 'success'); setTimeout(() => location.reload(), 800); }
                    else showToast(data.message || 'Error creating customer', 'error');
                });
        }
    </script>
</body>
</html>
