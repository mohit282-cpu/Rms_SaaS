<?php
// admin/loyalty.php - Customer Loyalty Program & Rewards Ledger UI (Phase 4)
require_once __DIR__ . '/../config.php';
requireAdminLogin();
RBAC::requirePermission('manage_customers');

$currentPage = 'loyalty';
$tenantId = TenantContext::getTenantId();

$conn = getDBConnection();
$lStmt = $conn->prepare("
    SELECT lt.*, c.name as customer_name, c.phone as customer_phone 
    FROM loyalty_transactions lt
    JOIN customers c ON lt.customer_id = c.id
    WHERE lt.restaurant_id = ? 
    ORDER BY lt.id DESC LIMIT 100
");
$lStmt->bind_param("i", $tenantId);
$lStmt->execute();
$transactions = $lStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lStmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Loyalty & Rewards Program - QR Cafe</title>
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
                        <span>🎁</span> Customer Loyalty & Rewards Program
                    </h1>
                    <p class="text-xs text-zinc-400">Manage customer loyalty point earning, tier upgrades, and redemption ledger</p>
                </div>
            </header>

            <div class="p-4 md:p-8 max-w-6xl mx-auto space-y-6">

                <!-- LOYALTY TIERS CARDS -->
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-2">
                        <span class="px-2 py-0.5 rounded-full bg-zinc-800 text-zinc-300 font-black text-[10px]">BRONZE TIER</span>
                        <h4 class="text-xs font-bold text-white">0 - 499 Points</h4>
                        <p class="text-[11px] text-zinc-400">1 Point per NPR 100 spent</p>
                    </div>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-2">
                        <span class="px-2 py-0.5 rounded-full bg-blue-500/10 border border-blue-500/30 text-blue-400 font-black text-[10px]">SILVER TIER</span>
                        <h4 class="text-xs font-bold text-white">500 - 1,999 Points</h4>
                        <p class="text-[11px] text-zinc-400">Free Beverage Voucher</p>
                    </div>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-2">
                        <span class="px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 font-black text-[10px]">GOLD TIER</span>
                        <h4 class="text-xs font-bold text-white">2,000 - 4,999 Points</h4>
                        <p class="text-[11px] text-zinc-400">10% Off All Dine-in Orders</p>
                    </div>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-2">
                        <span class="px-2 py-0.5 rounded-full bg-purple-500/10 border border-purple-500/30 text-purple-400 font-black text-[10px]">VIP TIER</span>
                        <h4 class="text-xs font-bold text-white">5,000+ Points</h4>
                        <p class="text-[11px] text-zinc-400">Priority Seating & VIP Discounts</p>
                    </div>
                </div>

                <!-- LOYALTY TRANSACTIONS LEDGER -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                            <span>📜</span> Points Ledger & Audit Trail
                        </h3>
                        <span class="text-xs font-bold text-amber-400 bg-amber-500/10 border border-amber-500/30 px-3 py-1 rounded-full">
                            Total Ledger Entries: <?= count($transactions) ?>
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider font-bold">
                                    <th class="py-3 px-4">Date</th>
                                    <th class="py-3 px-4">Customer</th>
                                    <th class="py-3 px-4">Phone</th>
                                    <th class="py-3 px-4">Type</th>
                                    <th class="py-3 px-4">Points</th>
                                    <th class="py-3 px-4">Description</th>
                                    <th class="py-3 px-4 text-right">Order Ref</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-200">
                                <?php if (empty($transactions)): ?>
                                    <tr><td colspan="7" class="py-8 text-center text-zinc-500 italic">No loyalty transactions recorded yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $t): ?>
                                        <tr class="hover:bg-zinc-800/30 transition-colors">
                                            <td class="py-3.5 px-4 font-bold text-zinc-400"><?= date('M d, Y H:i', strtotime($t['created_at'])) ?></td>
                                            <td class="py-3.5 px-4 font-bold text-white"><?= htmlspecialchars($t['customer_name']) ?></td>
                                            <td class="py-3.5 px-4 font-mono text-amber-400"><?= htmlspecialchars($t['customer_phone']) ?></td>
                                            <td class="py-3.5 px-4">
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-black uppercase border <?= $t['type'] === 'earn' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400' : 'bg-rose-500/10 border-rose-500/30 text-rose-400' ?>">
                                                    <?= $t['type'] ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 font-black <?= $t['points'] >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                                <?= $t['points'] >= 0 ? '+' : '' ?><?= $t['points'] ?> pts
                                            </td>
                                            <td class="py-3.5 px-4 text-zinc-300"><?= htmlspecialchars($t['notes'] ?? '') ?></td>
                                            <td class="py-3.5 px-4 text-right font-bold text-amber-400">#<?= $t['order_id'] ?: 'N/A' ?></td>
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
</body>
</html>
