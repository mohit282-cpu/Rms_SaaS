<?php
// admin/index.php - Enterprise Restaurant Operations Center Dashboard
require_once '../config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) {
    die("Database connection error");
}
$csrfToken = CSRF::generateToken();

// Resolve authenticated tenant context for header badge
$tenantId = TenantContext::getTenantId();
$tenantName = 'Restaurant Workspace';
$tStmt = $conn->prepare("SELECT restaurant_name FROM restaurants WHERE id = ? LIMIT 1");
if ($tStmt) {
    $tStmt->bind_param("i", $tenantId);
    $tStmt->execute();
    $tRes = $tStmt->get_result();
    if ($tRow = $tRes->fetch_assoc()) {
        $tenantName = $tRow['restaurant_name'];
    }
    $tStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>Operations Center - <?= htmlspecialchars($tenantName) ?></title>
    <link rel="manifest" href="../manifest.json">
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
        @keyframes pulseGlow {
            0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(245, 158, 11, 0); }
            100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        }
        .pulse-alert { animation: pulseGlow 1.5s infinite; }
    </style>
</head>
<body class="min-h-full pb-20 md:pb-8 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <!-- DESKTOP SIDEBAR NAVIGATION -->
    <?php $currentPage = 'dashboard'; include 'includes/sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="md:pl-64 min-h-screen">

        <!-- LIVE RESTAURANT STATUS HEADER BAR -->
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3">
            <div class="max-w-7xl mx-auto flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="text-lg md:text-xl font-black text-white">Restaurant Operations Center</h1>
                            <span class="px-2.5 py-0.5 rounded-full bg-zinc-900 border border-zinc-800 text-amber-400 text-[10px] font-mono font-bold">
                                Tenant ID: #<?= $tenantId ?> (<?= htmlspecialchars($tenantName) ?>)
                            </span>
                            <span id="connStatusBadge" class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black uppercase tracking-wider">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span> Live Stream
                            </span>
                        </div>
                        <p class="text-xs text-zinc-400 hidden sm:block">Central command dashboard aggregating live DB metrics from POS, Floor, Kitchen & Inventory</p>
                    </div>
                </div>

                <!-- Global Search & Control Bar -->
                <div class="flex items-center gap-2 flex-wrap sm:flex-nowrap">
                    <!-- Global Search Bar -->
                    <div class="relative flex-1 sm:w-64">
                        <input type="text" id="globalSearchInput" placeholder="🔍 Search Order, Table, Item, Asset..." oninput="handleGlobalSearch(this.value)" class="w-full h-9 px-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 text-xs text-white placeholder:text-zinc-500 outline-none focus:border-amber-500/50">
                        <div id="searchResultsDropdown" class="absolute top-11 left-0 right-0 z-50 hidden bg-zinc-900 border border-zinc-800 rounded-2xl p-2 shadow-2xl space-y-1 max-h-72 overflow-y-auto no-scrollbar">
                        </div>
                    </div>

                    <!-- Shift & Staff Banner -->
                    <div class="hidden lg:flex items-center gap-2 px-3 py-1.5 rounded-2xl bg-zinc-900 border border-zinc-800 text-xs font-bold text-zinc-300 shrink-0">
                        <span>🕒 Shift: Main Day</span>
                        <span class="text-zinc-600">•</span>
                        <span id="shiftStaffCount">Staff: 6 Active</span>
                    </div>

                    <button onclick="refreshDashboardStream()" class="h-9 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40 shrink-0">
                        🔄 Refresh
                    </button>
                    <a href="../kitchen-dashboard.php" class="h-9 px-3.5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs flex items-center gap-1.5 shadow-lg shadow-amber-500/20 shrink-0">
                        <span>👨‍🍳</span> KDS
                    </a>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 space-y-6">

            <!-- QUICK ACTIONS TOOLBAR -->
            <section class="flex items-center gap-2 overflow-x-auto pb-1 no-scrollbar text-xs font-bold">
                <a href="tables.php" class="h-9 px-3.5 rounded-2xl bg-amber-500 text-zinc-950 font-black flex items-center gap-1.5 shadow-lg shadow-amber-500/20 shrink-0">
                    <span>+</span> New Order
                </a>
                <a href="tables.php" class="h-9 px-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 hover:border-amber-500/40 flex items-center gap-1.5 shrink-0">
                    <span>📍</span> Open Floor
                </a>
                <a href="purchase-orders.php" class="h-9 px-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 hover:border-amber-500/40 flex items-center gap-1.5 shrink-0">
                    <span>🛒</span> + Purchase Order
                </a>
                <a href="goods-receiving.php" class="h-9 px-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 hover:border-amber-500/40 flex items-center gap-1.5 shrink-0">
                    <span>📥</span> Receive Stock
                </a>
                <a href="inventory-items.php" class="h-9 px-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 hover:border-amber-500/40 flex items-center gap-1.5 shrink-0">
                    <span>📦</span> + Stock Item
                </a>
                <a href="assets.php" class="h-9 px-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 hover:border-amber-500/40 flex items-center gap-1.5 shrink-0">
                    <span>🏗️</span> + Add Asset
                </a>
                <a href="waste.php" class="h-9 px-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 hover:border-amber-500/40 flex items-center gap-1.5 shrink-0">
                    <span>🗑️</span> Record Waste
                </a>
            </section>

            <!-- 1. TOP REALTIME KPI CARDS GRID (15 METRICS) -->
            <section class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                
                <!-- Revenue Card (Settled Payments Only) -->
                <div class="bg-zinc-900/90 border border-amber-500/30 rounded-3xl p-4 space-y-2 col-span-2 sm:col-span-1 md:col-span-2 shadow-xl">
                    <div class="flex items-center justify-between text-xs text-zinc-400 font-bold">
                        <span>💰 Today's Revenue (Settled)</span>
                        <span id="revTrendBadge" class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black">+0% vs Yesterday</span>
                    </div>
                    <div id="kpiRevenue" class="text-2xl md:text-3xl font-black text-amber-400">Rs.0.00</div>
                    <div class="flex items-center justify-between text-[10px] text-zinc-500">
                        <span>This Week: <strong id="kpiWeekRev" class="text-zinc-300">Rs.0</strong></span>
                        <span>This Month: <strong id="kpiMonthRev" class="text-zinc-300">Rs.0</strong></span>
                    </div>
                </div>

                <!-- Total Orders -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">📦 Today's Orders</span>
                    <div id="kpiTotalOrders" class="text-xl font-black text-white">0</div>
                    <span class="text-[10px] text-zinc-500">All customer tickets</span>
                </div>

                <!-- Active Orders -->
                <div class="bg-zinc-900/90 border border-amber-500/30 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">🔥 Active Stream</span>
                    <div id="kpiActiveOrders" class="text-xl font-black text-amber-400">0</div>
                    <span class="text-[10px] text-zinc-500">In prep / ready</span>
                </div>

                <!-- Completed / Served Orders -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">🍽 Served Orders</span>
                    <div id="kpiServedOrders" class="text-xl font-black text-emerald-400">0</div>
                    <span class="text-[10px] text-zinc-500">Fulfilled & done</span>
                </div>

                <!-- Cancelled Orders -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">❌ Cancelled</span>
                    <div id="kpiCancelledOrders" class="text-xl font-black text-rose-400">0</div>
                    <span class="text-[10px] text-zinc-500">Voided / returned</span>
                </div>

                <!-- Occupied Tables (Filterable) -->
                <a href="tables.php?status=occupied" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg hover:border-rose-500/40 transition-all block">
                    <span class="text-xs text-zinc-400 font-bold block">🔴 Occupied Tables</span>
                    <div id="kpiOccupiedTables" class="text-xl font-black text-rose-400">0</div>
                    <span class="text-[10px] text-zinc-500">Filter Occupied →</span>
                </a>

                <!-- Vacant Tables (Filterable) -->
                <a href="tables.php?status=vacant" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg hover:border-emerald-500/40 transition-all block">
                    <span class="text-xs text-zinc-400 font-bold block">🟢 Vacant Tables</span>
                    <div id="kpiVacantTables" class="text-xl font-black text-emerald-400">0</div>
                    <span class="text-[10px] text-zinc-500">Filter Vacant →</span>
                </a>

                <!-- Reserved Tables (Filterable) -->
                <a href="tables.php?status=reserved" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg hover:border-amber-500/40 transition-all block">
                    <span class="text-xs text-zinc-400 font-bold block">🟡 Reserved</span>
                    <div id="kpiReservedTables" class="text-xl font-black text-amber-400">0</div>
                    <span class="text-[10px] text-zinc-500">Filter Reserved →</span>
                </a>

                <!-- Active Dining Guests -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">👥 Dining Guests</span>
                    <div id="kpiActiveGuests" class="text-xl font-black text-white">0</div>
                    <span class="text-[10px] text-zinc-500">Headcount seated</span>
                </div>

                <!-- Payment Pending / Due -->
                <div class="bg-zinc-900/90 border border-purple-500/30 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">💳 Payment Due</span>
                    <div id="kpiPaymentPending" class="text-xl font-black text-purple-400">Rs.0</div>
                    <span class="text-[10px] text-zinc-500">Unsettled bills</span>
                </div>

                <!-- Avg Prep Time -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">⏱ Avg Cook Speed</span>
                    <div id="kpiAvgPrepTime" class="text-xl font-black text-blue-400">14m</div>
                    <span class="text-[10px] text-zinc-500">Order to Ready</span>
                </div>

                <!-- Total Inventory Value -->
                <a href="inventory-reports.php" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg hover:border-amber-500/40 transition-all block">
                    <span class="text-xs text-zinc-400 font-bold block">📦 Inventory Value</span>
                    <div id="kpiInventoryValue" class="text-xl font-black text-amber-400">Rs.0</div>
                    <span class="text-[10px] text-zinc-500">Cost valuation →</span>
                </a>

                <!-- Low & Out Stock Items -->
                <a href="inventory-items.php" class="bg-zinc-900/90 border border-rose-500/30 rounded-3xl p-4 space-y-1 shadow-lg hover:border-rose-500/50 transition-all block">
                    <span class="text-xs text-zinc-400 font-bold block">⚠️ Critical Stock</span>
                    <div id="kpiLowStockCount" class="text-xl font-black text-rose-400">0 Items</div>
                    <span class="text-[10px] text-zinc-500">Low or Out of stock</span>
                </a>

                <!-- Today's Purchases -->
                <a href="purchase-orders.php" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg hover:border-amber-500/40 transition-all block">
                    <span class="text-xs text-zinc-400 font-bold block">🛒 Today's Purchases</span>
                    <div id="kpiPurchases" class="text-xl font-black text-emerald-400">Rs.0</div>
                    <span class="text-[10px] text-zinc-500">POs & Receipts →</span>
                </a>

                <!-- Today's Waste -->
                <a href="waste.php" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg hover:border-rose-500/40 transition-all block">
                    <span class="text-xs text-zinc-400 font-bold block">🗑️ Today's Waste</span>
                    <div id="kpiWaste" class="text-xl font-black text-rose-400">Rs.0</div>
                    <span class="text-[10px] text-zinc-500">Loss log →</span>
                </a>

            </section>

            <!-- 2. MAIN OPERATIONS GRID -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- LEFT COLUMN: LIVE ORDER STREAM QUEUE (2 COLS) -->
                <section class="lg:col-span-2 space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-black text-white uppercase tracking-wider">🔥 Live Active Orders Stream</h3>
                            <span id="streamCountBadge" class="px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 font-extrabold text-[10px]">0 Active</span>
                        </div>
                        <a href="orders.php" class="text-xs text-amber-400 font-bold hover:underline">View All Orders Queue →</a>
                    </div>

                    <!-- Stream Cards Container -->
                    <div id="liveOrderCardsContainer" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="col-span-full bg-zinc-900/80 border border-zinc-800 rounded-3xl p-12 text-center text-zinc-500">
                            <div class="text-4xl mb-2 animate-bounce">⏳</div>
                            <p class="font-bold text-xs">Loading Live Orders Stream...</p>
                        </div>
                    </div>
                </section>

                <!-- RIGHT COLUMN: KITCHEN WORKLOAD, PAYMENTS, ALERTS & ACTIVITY FEED (1 COL) -->
                <section class="space-y-6">

                    <!-- A. KITCHEN WORKLOAD MONITOR -->
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                            <h3 class="text-xs font-black text-white flex items-center gap-2 uppercase tracking-wider">
                                <span>👨‍🍳</span> Kitchen Workload Queue
                            </h3>
                            <span id="kitchenCapacityMeter" class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-extrabold text-[10px]">0% Capacity</span>
                        </div>

                        <div class="space-y-3 text-xs">
                            <div>
                                <div class="flex justify-between font-bold text-zinc-300 mb-1">
                                    <span>New Waiting Orders</span>
                                    <span id="kitchNewCount" class="text-amber-400 font-black">0</span>
                                </div>
                                <div class="w-full bg-zinc-950 rounded-full h-2 overflow-hidden">
                                    <div id="kitchNewBar" class="bg-amber-500 h-full w-0 transition-all"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between font-bold text-zinc-300 mb-1">
                                    <span>Preparing On Stove</span>
                                    <span id="kitchPrepCount" class="text-blue-400 font-black">0</span>
                                </div>
                                <div class="w-full bg-zinc-950 rounded-full h-2 overflow-hidden">
                                    <div id="kitchPrepBar" class="bg-blue-500 h-full w-0 transition-all"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between font-bold text-zinc-300 mb-1">
                                    <span>Ready for Pass-Through</span>
                                    <span id="kitchReadyCount" class="text-emerald-400 font-black">0</span>
                                </div>
                                <div class="w-full bg-zinc-950 rounded-full h-2 overflow-hidden">
                                    <div id="kitchReadyBar" class="bg-emerald-500 h-full w-0 transition-all"></div>
                                </div>
                            </div>
                            <div class="pt-2 border-t border-zinc-800/80 flex justify-between text-[11px] text-zinc-400">
                                <span>Longest Waiting Order:</span>
                                <strong id="kitchLongestWait" class="text-rose-400 font-black">0m</strong>
                            </div>
                        </div>
                    </div>

                    <!-- B. PAYMENT METHODS & CASH FLOW MONITOR -->
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                        <h3 class="text-xs font-black text-white flex items-center gap-2 border-b border-zinc-800 pb-3 uppercase tracking-wider">
                            <span>💳</span> Settlement & Cash Flow Today
                        </h3>

                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="bg-zinc-950 p-3 rounded-2xl border border-zinc-800">
                                <span class="text-[10px] font-bold text-zinc-500 block">💵 Cash Today</span>
                                <span id="payCash" class="font-black text-white">Rs.0</span>
                            </div>
                            <div class="bg-zinc-950 p-3 rounded-2xl border border-zinc-800">
                                <span class="text-[10px] font-bold text-zinc-500 block">📱 eSewa / QR</span>
                                <span id="payEsewa" class="font-black text-emerald-400">Rs.0</span>
                            </div>
                            <div class="bg-zinc-950 p-3 rounded-2xl border border-zinc-800">
                                <span class="text-[10px] font-bold text-zinc-500 block">🟣 Khalti / Fonepay</span>
                                <span id="payKhalti" class="font-black text-purple-400">Rs.0</span>
                            </div>
                            <div class="bg-zinc-950 p-3 rounded-2xl border border-zinc-800">
                                <span class="text-[10px] font-bold text-zinc-500 block">💳 Card Total</span>
                                <span id="payCard" class="font-black text-blue-400">Rs.0</span>
                            </div>
                        </div>
                    </div>

                    <!-- C. INVENTORY ALERTS & COMMAND CENTER -->
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                            <h3 class="text-xs font-black text-white flex items-center gap-2 uppercase tracking-wider">
                                <span>⚠️</span> Critical Inventory Alerts
                            </h3>
                            <a href="inventory-items.php" class="text-[11px] text-amber-400 font-bold hover:underline">View Inventory →</a>
                        </div>

                        <div id="lowStockContainer" class="space-y-2">
                            <div class="text-center py-4 text-xs text-zinc-500 bg-zinc-950 rounded-2xl border border-zinc-800">
                                All inventory levels are healthy
                            </div>
                        </div>
                    </div>

                    <!-- D. ASSET REGISTER HEALTH WIDGET -->
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-3">
                        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                            <h3 class="text-xs font-black text-white flex items-center gap-2 uppercase tracking-wider">
                                <span>🏗️</span> Capital Asset Health
                            </h3>
                            <a href="assets.php" class="text-[11px] text-amber-400 font-bold hover:underline">Asset Register →</a>
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800">
                                <span class="text-[10px] text-zinc-500 block">Total Assets</span>
                                <span id="assetTotalCnt" class="font-black text-white">0</span>
                            </div>
                            <div class="bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800">
                                <span class="text-[10px] text-zinc-500 block">In Maintenance</span>
                                <span id="assetMaintCnt" class="font-black text-amber-400">0</span>
                            </div>
                            <div class="bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800">
                                <span class="text-[10px] text-zinc-500 block">Expiring Warranty</span>
                                <span id="assetWarrCnt" class="font-black text-rose-400">0</span>
                            </div>
                            <div class="bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800">
                                <span class="text-[10px] text-zinc-500 block">Net Book Value</span>
                                <span id="assetBookVal" class="font-black text-emerald-400">Rs.0</span>
                            </div>
                        </div>
                    </div>

                    <!-- E. LIVE RECENT ACTIVITY FEED -->
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                        <h3 class="text-xs font-black text-white flex items-center gap-2 border-b border-zinc-800 pb-3 uppercase tracking-wider">
                            <span>⚡</span> Live Activity Stream
                        </h3>

                        <div id="activityFeedContainer" class="space-y-2">
                            <div class="text-center py-4 text-xs text-zinc-500 bg-zinc-950 rounded-2xl border border-zinc-800">
                                Listening for events...
                            </div>
                        </div>
                    </div>

                </section>
            </div>

        </main>
    </div>

    <!-- REALTIME OPERATIONS STREAM CONTROLLER -->
    <script src="../js/modern.js"></script>
    <script>
        function fmt(n) { return 'Rs.' + parseFloat(n||0).toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0}); }

        // =================================================================
        // REALTIME CONNECTION ENGINE
        // Transport: AJAX polling (the project-native realtime channel).
        // One async loop only — never duplicated. Exponential backoff on
        // failures, 5s per-request timeout, heartbeat via last success.
        // =================================================================
        const STREAM_API = '../api/dashboard-stream.php';
        const POLL_INTERVAL_MS = 3000;
        const API_TIMEOUT_MS = 5000;
        const BACKOFF_MS = [1000, 2000, 5000, 10000, 30000];
        const UNAVAILABLE = '--';

        let schedulerTimer = null;
        let isPolling = false;
        let connState = 'connecting';
        let consecutiveFailures = 0;
        let firstLoadDone = false;

        // XSS-safe output encoder for server strings
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
        }

        function setText(id, value) {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        }

        // ---- Connection state badge (🟢 LIVE / 🟡 CONNECTING / 🟠 RECONNECTING / 🔴 OFFLINE)
        function updateConnectionStatus(state) {
            connState = state;
            const badge = document.getElementById('connStatusBadge');
            if (!badge) return;
            const map = {
                live:         { cls: 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400', dot: 'bg-emerald-400', ping: true,  label: '🟢 LIVE' },
                connecting:   { cls: 'bg-amber-500/10 border-amber-500/30 text-amber-400',       dot: 'bg-amber-400', ping: true,  label: '🟡 CONNECTING' },
                reconnecting: { cls: 'bg-orange-500/10 border-orange-500/30 text-orange-400',    dot: 'bg-orange-400', ping: true,  label: '🟠 RECONNECTING' },
                offline:      { cls: 'bg-rose-500/10 border-rose-500/30 text-rose-400',          dot: 'bg-rose-400', ping: false, label: '🔴 OFFLINE' }
            };
            const m = map[state] || map.offline;
            badge.className = 'flex items-center gap-1.5 px-2.5 py-0.5 rounded-full ' + m.cls + ' text-[10px] font-black uppercase tracking-wider';
            badge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full ' + m.dot + (m.ping ? ' animate-ping' : '') + '"></span> ' + m.label;
        }

        function backoffDelay() {
            const idx = Math.min(consecutiveFailures, BACKOFF_MS.length - 1);
            return BACKOFF_MS[idx] || 30000;
        }

        function scheduleNextPoll() {
            if (schedulerTimer) clearTimeout(schedulerTimer);
            const delay = (connState === 'live') ? POLL_INTERVAL_MS : backoffDelay();
            schedulerTimer = setTimeout(pollDashboard, delay);
        }

        async function pollDashboard() {
            if (isPolling) return; // never start a duplicate polling loop
            isPolling = true;

            const controller = new AbortController();
            const timeoutHandle = setTimeout(() => controller.abort(), API_TIMEOUT_MS);

            try {
                const res = await fetch(STREAM_API, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal
                });

                let data = null;
                try { data = await res.json(); }
                catch (e) { throw new Error('Invalid response from live service'); }

                if (!res.ok || !data || data.success !== true) {
                    const errInfo = (data && data.error) ? data.error : {};
                    if (res.status === 401 || errInfo.code === 'UNAUTHORIZED') {
                        updateConnectionStatus('offline');
                        renderStreamError('Your session has expired. Please refresh the page to log in again.');
                        return;
                    }
                    throw new Error(errInfo.message || (data && data.message) || 'Live order service is temporarily unavailable.');
                }

                onStreamSuccess(data);
            } catch (err) {
                onStreamFailure(err);
            } finally {
                clearTimeout(timeoutHandle);
                isPolling = false;
            }
        }

        function onStreamSuccess(data) {
            consecutiveFailures = 0;
            firstLoadDone = true;
            updateConnectionStatus('live');

            const d = (data && data.data) ? data.data : data;
            applyMetrics(d.metrics || {});
            updateKitchenQueue(d.kitchen_queue || {});
            updatePaymentBreakdown(d.payment_breakdown || {});
            renderLiveOrdersStream(d.live_orders || []);
            renderLowStockAlerts(d.inventory_alerts || []);
            renderActivityFeed(d.activity_feed || []);

            scheduleNextPoll();
        }

        function onStreamFailure(err) {
            consecutiveFailures++;
            if (firstLoadDone) {
                // Was live → keep showing last data but flag reconnect
                updateConnectionStatus('reconnecting');
                markStreamStale();
            } else {
                if (consecutiveFailures >= 3) updateConnectionStatus('offline');
                else updateConnectionStatus('reconnecting');
                renderStreamError((err && err.message) ? err.message : 'Unable to connect to live order service.');
            }
            scheduleNextPoll();
        }

        // Manual refresh (header button / Retry) — resets backoff and polls now.
        async function refreshDashboardStream() {
            if (isPolling) return;
            if (schedulerTimer) clearTimeout(schedulerTimer);
            consecutiveFailures = 0;
            if (!firstLoadDone) updateConnectionStatus('connecting');
            await pollDashboard();
        }

        // ---- Live Orders Stream state rendering (LOADING / SUCCESS / EMPTY / ERROR / RECONNECTING)
        function loadingHtml() {
            return `<div class="col-span-full bg-zinc-900/80 border border-zinc-800 rounded-3xl p-12 text-center text-zinc-500">
                        <div class="text-4xl mb-2 animate-bounce">⏳</div>
                        <p class="font-bold text-xs">Loading Live Orders Stream...</p>
                    </div>`;
        }

        function errorHtml(message) {
            return `<div class="col-span-full bg-zinc-900/80 border border-rose-500/30 rounded-3xl p-12 text-center">
                        <div class="text-4xl mb-2">📡</div>
                        <h4 class="font-bold text-sm text-zinc-200">Unable to connect to live order service.</h4>
                        <p class="text-xs text-zinc-500 mt-1">${esc(message || 'The live service is temporarily unavailable.')}</p>
                        <button onclick="refreshDashboardStream()" class="mt-4 px-4 py-2 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">↻ Retry Live Stream</button>
                    </div>`;
        }

        function renderStreamError(message) {
            setText('streamCountBadge', 'Unavailable');
            const container = document.getElementById('liveOrderCardsContainer');
            if (container) container.innerHTML = errorHtml(message);
        }

        function markStreamStale() {
            const container = document.getElementById('liveOrderCardsContainer');
            if (!container) return;
            if (container.querySelector('.stream-stale-banner')) return;
            const banner = document.createElement('div');
            banner.className = 'stream-stale-banner col-span-full flex items-center justify-between px-4 py-2 rounded-2xl bg-orange-500/10 border border-orange-500/30 text-orange-400 text-[11px] font-bold';
            banner.innerHTML = '<span>🟠 Reconnecting to live order service — showing latest known data...</span><button onclick="refreshDashboardStream()" class="px-2.5 py-1 rounded-xl bg-zinc-800 text-amber-400 font-black text-[10px]">Retry</button>';
            container.prepend(banner);
        }

        // ---- KPI integrity: real values on SUCCESS, '--' on ERROR (never fake zeros)
        function applyMetrics(m) {
            if (!m) { markMetricsUnavailable(); return; }
            setText('kpiRevenue', fmt(m.today_revenue));
            setText('kpiWeekRev', fmt(m.this_week_revenue));
            setText('kpiMonthRev', fmt(m.this_month_revenue));

            const badge = document.getElementById('revTrendBadge');
            if (badge) {
                const pct = parseFloat(m.rev_change_pct) || 0;
                badge.textContent = (pct >= 0 ? '↑ ' : '↓ ') + Math.abs(pct) + '% vs Yesterday';
                badge.className = pct >= 0
                    ? 'px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black'
                    : 'px-2 py-0.5 rounded-full bg-rose-500/10 border border-rose-500/30 text-rose-400 text-[10px] font-black';
            }

            setText('kpiTotalOrders', m.today_total_orders || 0);
            setText('kpiActiveOrders', m.active_orders || 0);
            setText('kpiServedOrders', m.served_orders || 0);
            setText('kpiCancelledOrders', m.cancelled_orders || 0);
            setText('kpiOccupiedTables', m.occupied_tables || 0);
            setText('kpiVacantTables', m.vacant_tables || 0);
            setText('kpiReservedTables', m.reserved_tables || 0);
            setText('kpiActiveGuests', m.active_guests || 0);
            setText('kpiPaymentPending', fmt(m.payment_pending));
            setText('kpiAvgPrepTime', m.avg_prep_time || '0m');
            setText('kpiInventoryValue', fmt(m.inventory_value));
            setText('kpiLowStockCount', ((m.low_stock_count||0) + (m.out_of_stock_count||0)) + ' Items');
            setText('kpiPurchases', fmt(m.today_purchases));
            setText('kpiWaste', fmt(m.today_waste_val));

            // Asset Widget
            setText('assetTotalCnt', m.total_assets || 0);
            setText('assetMaintCnt', m.in_maint_assets || 0);
            setText('assetWarrCnt', m.expiring_warranties || 0);
            setText('assetBookVal', fmt(m.asset_book_value));
        }

        function markMetricsUnavailable() {
            ['kpiRevenue','kpiWeekRev','kpiMonthRev','kpiTotalOrders','kpiActiveOrders','kpiServedOrders','kpiCancelledOrders',
             'kpiOccupiedTables','kpiVacantTables','kpiReservedTables','kpiActiveGuests','kpiPaymentPending','kpiAvgPrepTime',
             'kpiInventoryValue','kpiLowStockCount','kpiPurchases','kpiWaste',
             'assetTotalCnt','assetMaintCnt','assetWarrCnt','assetBookVal',
             'payCash','payEsewa','payKhalti','payCard',
             'kitchNewCount','kitchPrepCount','kitchReadyCount','kitchLongestWait','kitchenCapacityMeter'
            ].forEach(id => setText(id, UNAVAILABLE));
            setText('revTrendBadge', 'Data unavailable');
        }

        function updateKitchenQueue(kq) {
            if (!kq) return;
            setText('kitchNewCount', kq.new_waiting || 0);
            setText('kitchPrepCount', kq.preparing || 0);
            setText('kitchReadyCount', kq.ready_to_serve || 0);
            setText('kitchLongestWait', kq.longest_wait || '0m');

            const capMeter = document.getElementById('kitchenCapacityMeter');
            if (capMeter) capMeter.textContent = (kq.capacity_pct || '0%') + ' Capacity';

            const maxVal = Math.max((kq.new_waiting||0) + (kq.preparing||0) + (kq.ready_to_serve||0), 1);
            const bar = v => Math.min((v / maxVal) * 100, 100) + '%';
            document.getElementById('kitchNewBar').style.width = bar(kq.new_waiting || 0);
            document.getElementById('kitchPrepBar').style.width = bar(kq.preparing || 0);
            document.getElementById('kitchReadyBar').style.width = bar(kq.ready_to_serve || 0);
        }

        function updatePaymentBreakdown(pm) {
            if (!pm) return;
            setText('payCash', fmt(pm.cash));
            setText('payEsewa', fmt(pm.esewa));
            setText('payKhalti', fmt((pm.khalti || 0) + (pm.fonepay || 0)));
            setText('payCard', fmt(pm.card));
        }

        function renderLiveOrdersStream(orders) {
            const container = document.getElementById('liveOrderCardsContainer');
            if (!container) return;
            container.querySelectorAll('.stream-stale-banner').forEach(b => b.remove());

            const list = Array.isArray(orders) ? orders : [];

            if (list.length === 0) {
                setText('streamCountBadge', '0 Active');
                container.innerHTML = `
                    <div class="col-span-full bg-zinc-900/80 border border-zinc-800 rounded-3xl p-12 text-center text-zinc-500">
                        <div class="text-4xl mb-2">🍽️</div>
                        <h4 class="font-bold text-sm text-zinc-300">No Active Kitchen Orders</h4>
                        <p class="text-xs text-zinc-500 mt-1">All kitchen orders served and fulfilled</p>
                    </div>
                `;
                return;
            }

            setText('streamCountBadge', list.length + ' Active');

            container.innerHTML = list.map(o => {
                const items = o.items || [];
                const mins = o.elapsed_mins || 0;
                const table = esc(o.table_number);
                const customer = esc(o.customer_name || '');
                const status = o.status || 'new';

                let statusBadge = '<span class="px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 font-extrabold text-[10px]">NEW</span>';
                if (status === 'preparing') statusBadge = '<span class="px-2 py-0.5 rounded-full bg-blue-500/10 border border-blue-500/30 text-blue-400 font-extrabold text-[10px]">PREPARING</span>';
                if (status === 'ready') statusBadge = '<span class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-extrabold text-[10px]">READY</span>';

                return `
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-3 shadow-lg hover:border-amber-500/40 transition-all">
                        <div class="flex items-center justify-between border-b border-zinc-800 pb-2.5">
                            <div>
                                <div class="font-black text-sm text-white">Order #${esc(o.id)} • Table ${table}</div>
                                <div class="text-[10px] text-zinc-400 mt-0.5">⏱ ${mins} mins ago${customer ? ' • ' + customer : ''}</div>
                            </div>
                            ${statusBadge}
                        </div>

                        <div class="space-y-1 text-xs">
                            ${items.slice(0, 3).map(i => `
                                <div class="flex justify-between items-center text-zinc-300">
                                    <span><strong class="text-amber-400">${esc(i.quantity)}x</strong> ${esc(i.item_name)}</span>
                                    <span class="font-bold text-zinc-400">${fmt(i.price * i.quantity)}</span>
                                </div>
                            `).join('')}
                            ${items.length > 3 ? `<div class="text-[10px] text-zinc-500 italic">+${items.length - 3} more items...</div>` : ''}
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-zinc-800">
                            <span class="font-black text-sm text-amber-400">${fmt(o.total_amount)}</span>
                            <div class="flex gap-1.5">
                                <a href="orders.php?order_id=${esc(o.id)}" class="px-2.5 py-1 rounded-xl bg-zinc-800 text-zinc-200 font-bold text-xs hover:bg-amber-500 hover:text-zinc-950">View</a>
                                <a href="../kitchen-dashboard.php" class="px-2.5 py-1 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">KDS</a>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderLowStockAlerts(alerts) {
            const container = document.getElementById('lowStockContainer');
            if (!container) return;
            const list = Array.isArray(alerts) ? alerts : [];
            if (list.length === 0) {
                container.innerHTML = `<div class="text-center py-4 text-xs text-zinc-500 bg-zinc-950 rounded-2xl border border-zinc-800">All inventory levels are healthy</div>`;
                return;
            }

            container.innerHTML = list.map(a => `
                <div class="flex items-center justify-between bg-zinc-950 p-2.5 rounded-2xl border border-rose-500/20 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="text-base">${a.alert_type === 'out_of_stock' ? '🚫' : '⚠️'}</span>
                        <div>
                            <span class="font-bold text-white">${esc(a.name)}</span>
                            <div class="text-[10px] text-rose-400 font-semibold">${String(a.alert_type || 'low').replace(/_/g, ' ').toUpperCase()} (${parseFloat(a.current_stock).toFixed(1)} remaining)</div>
                        </div>
                    </div>
                    <a href="inventory-items.php" class="px-2.5 py-1 rounded-xl bg-zinc-800 text-amber-400 font-bold text-[11px]">Restock →</a>
                </div>
            `).join('');
        }

        function renderActivityFeed(feed) {
            const container = document.getElementById('activityFeedContainer');
            if (!container) return;
            const list = Array.isArray(feed) ? feed : [];
            if (list.length === 0) {
                container.innerHTML = `<div class="text-center py-4 text-xs text-zinc-500 bg-zinc-950 rounded-2xl border border-zinc-800">No recent activity</div>`;
                return;
            }

            container.innerHTML = list.map(a => `
                <div class="bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800 text-xs space-y-0.5">
                    <div class="font-medium text-zinc-200">${esc(a.event_text)}</div>
                    <div class="text-[10px] text-zinc-500 flex items-center justify-between">
                        <span>${a.actor ? esc(a.actor) : ''}</span>
                        <span>${new Date(a.created_at).toLocaleTimeString()}</span>
                    </div>
                </div>
            `).join('');
        }

        // Global Search Handler
        let searchTimer;
        function handleGlobalSearch(q) {
            clearTimeout(searchTimer);
            const drop = document.getElementById('searchResultsDropdown');
            if (q.length < 2) { if (drop) drop.classList.add('hidden'); return; }

            searchTimer = setTimeout(async () => {
                const controller = new AbortController();
                const timeoutHandle = setTimeout(() => controller.abort(), API_TIMEOUT_MS);
                try {
                    const r = await fetch(`../api/dashboard-stream.php?action=search&q=${encodeURIComponent(q)}`, {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        signal: controller.signal
                    });
                    const j = await r.json();
                    const res = (j && j.results) || [];

                    if (drop) {
                        if (res.length === 0) {
                            drop.innerHTML = '<div class="p-3 text-xs text-zinc-500 text-center">No results found</div>';
                        } else {
                            drop.innerHTML = res.map(item => `
                                <a href="${esc(item.link)}" class="block p-2 rounded-xl hover:bg-zinc-800 text-xs">
                                    <div class="font-bold text-white">${esc(item.title)}</div>
                                    <div class="text-[10px] text-zinc-400">${esc(item.subtitle)}</div>
                                </a>
                            `).join('');
                        }
                        drop.classList.remove('hidden');
                    }
                } catch (err) {
                    if (drop) {
                        drop.innerHTML = '<div class="p-3 text-xs text-rose-400 text-center">Search service unavailable. Please retry.</div>';
                        drop.classList.remove('hidden');
                    }
                } finally {
                    clearTimeout(timeoutHandle);
                }
            }, 300);
        }

        // Close dropdown on outside click
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#globalSearchInput') && !e.target.closest('#searchResultsDropdown')) {
                document.getElementById('searchResultsDropdown').classList.add('hidden');
            }
        });

        // Initialize Realtime Stream Engine (single 3s polling loop with backoff)
        document.addEventListener('DOMContentLoaded', () => {
            // Never show fake "0" values while the backend is unreachable.
            markMetricsUnavailable();
            setText('streamCountBadge', '...');
            const container = document.getElementById('liveOrderCardsContainer');
            if (container) container.innerHTML = loadingHtml();
            updateConnectionStatus('connecting');
            scheduleNextPoll();
        });
    </script>
</body>
</html>
