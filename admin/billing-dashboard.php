<?php
// admin/billing-dashboard.php - Financial Billing & Sales Dashboard
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
$conn = getDBConnection();

$currentPage = 'billing-dashboard';

// Calculate Today's Financial Metrics
$today = date('Y-m-d');
$stats = [
    'today_sales' => 0.00,
    'cash_sales' => 0.00,
    'card_sales' => 0.00,
    'digital_sales' => 0.00,
    'total_discount' => 0.00,
    'total_tax' => 0.00,
    'total_sc' => 0.00,
    'total_ncr' => 0.00,
    'total_refunds' => 0.00,
    'total_voids' => 0.00,
    'outstanding' => 0.00,
    'paid_count' => 0,
    'pending_count' => 0
];

// Query sales metrics from orders
$stmt = $conn->prepare("SELECT payment_status, payment_method, SUM(total_amount) as sum_total, COUNT(id) as cnt FROM orders WHERE restaurant_id = ? AND DATE(created_at) = ? GROUP BY payment_status, payment_method");
$stmt->bind_param("is", $tenantId, $today);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $amt = (float)$row['sum_total'];
    $cnt = (int)$row['cnt'];
    $st = strtolower($row['payment_status']);
    $method = strtolower($row['payment_method']);

    if ($st === 'paid') {
        $stats['today_sales'] += $amt;
        $stats['paid_count'] += $cnt;
        if ($method === 'cash') $stats['cash_sales'] += $amt;
        elseif ($method === 'card') $stats['card_sales'] += $amt;
        else $stats['digital_sales'] += $amt;
    } elseif ($st === 'pending') {
        $stats['outstanding'] += $amt;
        $stats['pending_count'] += $cnt;
    } elseif ($st === 'ncr') {
        $stats['total_ncr'] += $amt;
    }
}
$stmt->close();

// Fetch Recent Payment Transactions
$transactions = [];
$tx_stmt = $conn->prepare("SELECT id, transaction_id, order_id, gateway_name, amount, status, created_at FROM payment_transactions WHERE order_id IN (SELECT id FROM orders WHERE restaurant_id = ?) ORDER BY id DESC LIMIT 15");
$tx_stmt->bind_param("i", $tenantId);
$tx_stmt->execute();
$tx_res = $tx_stmt->get_result();
while ($tx_row = $tx_res->fetch_assoc()) {
    $transactions[] = $tx_row;
}
$tx_stmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 font-sans antialiased text-white selection:bg-amber-500 selection:text-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing &amp; Financial Dashboard — RMS SaaS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: { colors: { amber: { 500: '#f59e0b', 600: '#d97706' } } }
            }
        }
    </script>
</head>
<body class="min-h-full pb-12 font-sans antialiased">
    <?php include 'includes/sidebar.php'; ?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5 flex items-center justify-between">
            <div>
                <h1 class="text-lg md:text-xl font-black text-white">Billing &amp; Sales Dashboard</h1>
                <p class="text-xs text-zinc-400">Authoritative Financial Metrics, Daily Sales, Payment Gateways &amp; Ledger</p>
            </div>
            <a href="tables.php" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                ⚡ Floor &amp; Tables Billing →
            </a>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">
            
            <!-- 1. KPI CARDS GRID -->
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-3.5 space-y-1">
                    <span class="text-xs font-bold text-zinc-400">💰 Today Sales</span>
                    <div class="text-lg font-black text-emerald-400">Rs. <?= number_format($stats['today_sales'], 2) ?></div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-3.5 space-y-1">
                    <span class="text-xs font-bold text-zinc-400">💵 Cash Sales</span>
                    <div class="text-lg font-black text-amber-400">Rs. <?= number_format($stats['cash_sales'], 2) ?></div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-3.5 space-y-1">
                    <span class="text-xs font-bold text-zinc-400">💳 Card Sales</span>
                    <div class="text-lg font-black text-blue-400">Rs. <?= number_format($stats['card_sales'], 2) ?></div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-3.5 space-y-1">
                    <span class="text-xs font-bold text-zinc-400">📱 Digital QR</span>
                    <div class="text-lg font-black text-purple-400">Rs. <?= number_format($stats['digital_sales'], 2) ?></div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-3.5 space-y-1">
                    <span class="text-xs font-bold text-zinc-400">⏳ Outstanding</span>
                    <div class="text-lg font-black text-rose-400">Rs. <?= number_format($stats['outstanding'], 2) ?></div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-3.5 space-y-1">
                    <span class="text-xs font-bold text-zinc-400">🎁 NCR Waivers</span>
                    <div class="text-lg font-black text-amber-400">Rs. <?= number_format($stats['total_ncr'], 2) ?></div>
                </div>
            </div>

            <!-- 2. RECENT PAYMENT TRANSACTIONS TABLE -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-black text-white">Recent Payment Transactions</h2>
                    <span class="text-xs font-bold text-zinc-500">Realtime Payment Gateway Ledger</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <th class="py-2.5 px-3">Transaction Ref</th>
                                <th class="py-2.5 px-3">Order ID</th>
                                <th class="py-2.5 px-3">Gateway / Method</th>
                                <th class="py-2.5 px-3">Amount</th>
                                <th class="py-2.5 px-3">Status</th>
                                <th class="py-2.5 px-3">Timestamp</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-zinc-500">No payment transactions recorded today.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $tx): ?>
                                    <tr class="hover:bg-zinc-800/40">
                                        <td class="py-3 px-3 font-mono text-amber-400 font-bold"><?= htmlspecialchars($tx['transaction_id']) ?></td>
                                        <td class="py-3 px-3">Order #<?= htmlspecialchars($tx['order_id']) ?></td>
                                        <td class="py-3 px-3 uppercase font-bold text-white"><?= htmlspecialchars($tx['gateway_name']) ?></td>
                                        <td class="py-3 px-3 font-black text-emerald-400">Rs. <?= number_format($tx['amount'], 2) ?></td>
                                        <td class="py-3 px-3">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $tx['status'] === 'paid' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' ?>">
                                                <?= ucfirst($tx['status']) ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-3 text-zinc-400 text-[11px]"><?= date('M d, H:i', strtotime($tx['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</body>
</html>
