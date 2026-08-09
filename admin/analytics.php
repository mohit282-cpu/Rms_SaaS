<?php
// admin/analytics.php - Advanced Analytics & Business Intelligence Dashboard (Phase 4)
require_once __DIR__ . '/../config.php';
requireAdminLogin();
RBAC::requirePermission('view_analytics');

$currentPage = 'analytics';
$tenantId = TenantContext::getTenantId();

$conn = getDBConnection();
$days = 30;
$startDate = date('Y-m-d', strtotime("-{$days} days"));

$kpiStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(total_amount), 0.00) as total_gross_sales,
        COALESCE(AVG(total_amount), 0.00) as aov
    FROM orders 
    WHERE restaurant_id = ? AND status = 'completed' AND DATE(created_at) >= ?
");
$kpiStmt->bind_param("is", $tenantId, $startDate);
$kpiStmt->execute();
$kpi = $kpiStmt->get_result()->fetch_assoc();
$kpiStmt->close();

$topStmt = $conn->prepare("
    SELECT mi.name, SUM(oi.quantity) as total_qty, SUM(oi.quantity * oi.price) as total_sales 
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    WHERE o.restaurant_id = ? AND o.status = 'completed' AND DATE(o.created_at) >= ?
    GROUP BY oi.menu_item_id
    ORDER BY total_qty DESC LIMIT 5
");
$topStmt->bind_param("is", $tenantId, $startDate);
$topStmt->execute();
$topItems = $topStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$topStmt->close();

$catStmt = $conn->prepare("
    SELECT c.name as category_name, SUM(oi.quantity * oi.price) as total_sales 
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    JOIN categories c ON mi.category_id = c.id
    WHERE o.restaurant_id = ? AND o.status = 'completed' AND DATE(o.created_at) >= ?
    GROUP BY c.id
    ORDER BY total_sales DESC
");
$catStmt->bind_param("is", $tenantId, $startDate);
$catStmt->execute();
$categories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$catStmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Advanced Analytics & BI - QR Cafe</title>
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
                        <span>📈</span> Executive Analytics & BI Dashboard
                    </h1>
                    <p class="text-xs text-zinc-400">Sales performance, top menu sellers, category contributions, and average order value</p>
                </div>
            </header>

            <div class="p-4 md:p-8 max-w-6xl mx-auto space-y-6">

                <!-- EXECUTIVE KPI CARDS -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-1 shadow-xl">
                        <span class="text-xs text-zinc-400 font-bold">💳 30-Day Gross Revenue</span>
                        <div class="text-2xl font-black text-amber-400">NPR <?= number_format($kpi['total_gross_sales'] ?? 0, 2) ?></div>
                    </div>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-1 shadow-xl">
                        <span class="text-xs text-zinc-400 font-bold">📋 Completed Orders</span>
                        <div class="text-2xl font-black text-white"><?= number_format($kpi['total_orders'] ?? 0) ?></div>
                    </div>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-1 shadow-xl">
                        <span class="text-xs text-zinc-400 font-bold">📊 Average Order Value (AOV)</span>
                        <div class="text-2xl font-black text-emerald-400">NPR <?= number_format($kpi['aov'] ?? 0, 2) ?></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- TOP SELLING DISHES -->
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2 border-b border-zinc-800 pb-3">
                            <span>🔥</span> Top 5 Best-Selling Dishes
                        </h3>

                        <div class="space-y-3">
                            <?php if (empty($topItems)): ?>
                                <p class="text-xs text-zinc-500 italic py-4 text-center">No sales records available yet.</p>
                            <?php else: ?>
                                <?php foreach ($topItems as $item): ?>
                                    <div class="bg-zinc-950 border border-zinc-800 p-3 rounded-2xl flex items-center justify-between">
                                        <div>
                                            <h4 class="font-bold text-white text-xs"><?= htmlspecialchars($item['name']) ?></h4>
                                            <p class="text-[10px] text-zinc-400"><?= $item['total_qty'] ?> units sold</p>
                                        </div>
                                        <span class="font-black text-amber-400 text-xs">NPR <?= number_format($item['total_sales'], 2) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- CATEGORY SALES CONTRIBUTION -->
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2 border-b border-zinc-800 pb-3">
                            <span>🏷️</span> Revenue by Category
                        </h3>

                        <div class="space-y-3">
                            <?php if (empty($categories)): ?>
                                <p class="text-xs text-zinc-500 italic py-4 text-center">No category revenue breakdown available.</p>
                            <?php else: ?>
                                <?php foreach ($categories as $cat): ?>
                                    <div class="space-y-1">
                                        <div class="flex justify-between text-xs font-bold">
                                            <span class="text-white"><?= htmlspecialchars($cat['category_name']) ?></span>
                                            <span class="text-amber-400">NPR <?= number_format($cat['total_sales'], 2) ?></span>
                                        </div>
                                        <div class="w-full h-2 rounded-full bg-zinc-950 overflow-hidden">
                                            <div class="h-full bg-amber-500 rounded-full" style="width: 75%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>
        </main>
    </div>
</body>
</html>
