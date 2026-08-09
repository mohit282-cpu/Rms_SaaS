<?php
// admin/refunds.php - Order Refunds & Void Audit Log UI (Phase 2)
require_once __DIR__ . '/../config.php';
requireAdminLogin();
RBAC::requirePermission('view_revenue');

$currentPage = 'refunds';
$tenantId = TenantContext::getTenantId();

$conn = getDBConnection();
$rStmt = $conn->prepare("SELECT * FROM order_refunds WHERE restaurant_id = ? ORDER BY id DESC LIMIT 100");
$rStmt->bind_param("i", $tenantId);
$rStmt->execute();
$refunds = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rStmt->close();

$vStmt = $conn->prepare("SELECT * FROM order_voids WHERE restaurant_id = ? ORDER BY id DESC LIMIT 100");
$vStmt->bind_param("i", $tenantId);
$vStmt->execute();
$voids = $vStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$vStmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Refunds & Void Audit Register - QR Cafe</title>
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
                        <span>🧾</span> Refunds & Void Audit Log
                    </h1>
                    <p class="text-xs text-zinc-400">Financial refund records, item voids, and approval audit trail</p>
                </div>
            </header>

            <div class="p-4 md:p-8 max-w-6xl mx-auto space-y-8">

                <!-- REFUNDS REGISTER -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                            <span>💸</span> Order Refund Register
                        </h3>
                        <span class="text-xs font-bold text-amber-400 bg-amber-500/10 border border-amber-500/30 px-3 py-1 rounded-full">
                            Total Records: <?= count($refunds) ?>
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider font-bold">
                                    <th class="py-3 px-4">Refund ID</th>
                                    <th class="py-3 px-4">Order ID</th>
                                    <th class="py-3 px-4">Type</th>
                                    <th class="py-3 px-4">Amount</th>
                                    <th class="py-3 px-4">Method</th>
                                    <th class="py-3 px-4">Reason</th>
                                    <th class="py-3 px-4">Authorized By</th>
                                    <th class="py-3 px-4 text-right">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-200">
                                <?php if (empty($refunds)): ?>
                                    <tr>
                                        <td colspan="8" class="py-8 text-center text-zinc-500 italic">No refund records recorded.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($refunds as $r): ?>
                                        <tr class="hover:bg-zinc-800/30 transition-colors">
                                            <td class="py-3.5 px-4 font-bold text-white">#REF-<?= $r['id'] ?></td>
                                            <td class="py-3.5 px-4 font-bold text-amber-400">#<?= $r['order_id'] ?></td>
                                            <td class="py-3.5 px-4">
                                                <span class="px-2 py-0.5 rounded-lg text-[10px] font-black uppercase border <?= $r['refund_type'] === 'full' ? 'bg-rose-500/10 border-rose-500/30 text-rose-400' : 'bg-amber-500/10 border-amber-500/30 text-amber-400' ?>">
                                                    <?= $r['refund_type'] ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 font-black text-rose-400">-NPR <?= number_format($r['amount'], 2) ?></td>
                                            <td class="py-3.5 px-4 uppercase text-zinc-300"><?= htmlspecialchars($r['payment_method']) ?></td>
                                            <td class="py-3.5 px-4 text-zinc-300"><?= htmlspecialchars($r['reason']) ?></td>
                                            <td class="py-3.5 px-4 font-bold text-white"><?= htmlspecialchars($r['refunded_by']) ?></td>
                                            <td class="py-3.5 px-4 text-right text-zinc-400"><?= date('M d, Y H:i', strtotime($r['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- VOID AUDIT LOGS -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                            <span>🚫</span> Void Item Audit Register
                        </h3>
                        <span class="text-xs font-bold text-rose-400 bg-rose-500/10 border border-rose-500/30 px-3 py-1 rounded-full">
                            Total Voids: <?= count($voids) ?>
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider font-bold">
                                    <th class="py-3 px-4">Void ID</th>
                                    <th class="py-3 px-4">Order ID</th>
                                    <th class="py-3 px-4">Item ID</th>
                                    <th class="py-3 px-4">Voided Amount</th>
                                    <th class="py-3 px-4">Reason</th>
                                    <th class="py-3 px-4">Voided By</th>
                                    <th class="py-3 px-4 text-right">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-200">
                                <?php if (empty($voids)): ?>
                                    <tr>
                                        <td colspan="7" class="py-8 text-center text-zinc-500 italic">No item voids recorded.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($voids as $v): ?>
                                        <tr class="hover:bg-zinc-800/30 transition-colors">
                                            <td class="py-3.5 px-4 font-bold text-white">#VOID-<?= $v['id'] ?></td>
                                            <td class="py-3.5 px-4 font-bold text-amber-400">#<?= $v['order_id'] ?></td>
                                            <td class="py-3.5 px-4 text-zinc-400">#<?= $v['order_item_id'] ?? 'N/A' ?></td>
                                            <td class="py-3.5 px-4 font-black text-rose-400">NPR <?= number_format($v['amount'], 2) ?></td>
                                            <td class="py-3.5 px-4 text-zinc-300"><?= htmlspecialchars($v['reason']) ?></td>
                                            <td class="py-3.5 px-4 font-bold text-white"><?= htmlspecialchars($v['voided_by']) ?></td>
                                            <td class="py-3.5 px-4 text-right text-zinc-400"><?= date('M d, Y H:i', strtotime($v['created_at'])) ?></td>
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
