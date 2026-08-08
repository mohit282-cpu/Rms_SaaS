<?php
// admin/inventory.php - Inventory Dashboard with Realtime KPIs & Chart Analytics
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Inventory Dashboard';
$currentPage = 'inventory';
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen pb-12">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg md:text-xl font-black text-white">📦 Inventory Dashboard</h1>
                        <span class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black uppercase tracking-wider">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span> Realtime
                        </span>
                    </div>
                    <p class="text-xs text-zinc-400 hidden sm:block">Live stock levels, alerts, valuation, consumption & analytics</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="inventory-items.php" class="h-10 px-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs flex items-center gap-1.5 shadow-lg shadow-amber-500/20 transition-all">
                        <span>+</span> Add Stock Item
                    </a>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <!-- REALTIME KPI GRID -->
            <section id="kpiGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <div class="bg-zinc-900/90 border border-amber-500/30 rounded-3xl p-4 space-y-1.5 col-span-2 shadow-xl">
                    <span class="text-xs text-zinc-400 font-bold block">💰 Total Inventory Value</span>
                    <div id="kpiTotalValue" class="text-2xl md:text-3xl font-black text-amber-400">Rs.0</div>
                    <p class="text-[10px] text-zinc-500">Active stock valuation</p>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">📦 Total SKUs</span>
                    <div id="kpiTotalItems" class="text-xl font-black text-white">0</div>
                    <span class="text-[10px] text-zinc-500">Active stock items</span>
                </div>
                <div class="bg-zinc-900/90 border border-rose-500/30 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">⚠️ Low Stock</span>
                    <div id="kpiLowStock" class="text-xl font-black text-rose-400">0</div>
                    <span class="text-[10px] text-zinc-500">Below minimum</span>
                </div>
                <div class="bg-zinc-900/90 border border-red-500/30 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">🚫 Out of Stock</span>
                    <div id="kpiOutOfStock" class="text-xl font-black text-red-500">0</div>
                    <span class="text-[10px] text-zinc-500">Zero quantity</span>
                </div>
                <div class="bg-zinc-900/90 border border-orange-500/30 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">⏰ Near Expiry</span>
                    <div id="kpiNearExpiry" class="text-xl font-black text-orange-400">0</div>
                    <span class="text-[10px] text-zinc-500">Within 7 days</span>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">☠️ Expired</span>
                    <div id="kpiExpired" class="text-xl font-black text-red-400">0</div>
                    <span class="text-[10px] text-zinc-500">Past expiry date</span>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">🛒 Pending POs</span>
                    <div id="kpiPendingPOs" class="text-xl font-black text-blue-400">0</div>
                    <span class="text-[10px] text-zinc-500">Open purchase orders</span>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">🔥 Today Consumed</span>
                    <div id="kpiConsumed" class="text-xl font-black text-purple-400">Rs.0</div>
                    <span class="text-[10px] text-zinc-500">Kitchen order usage</span>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">📥 Today Purchases</span>
                    <div id="kpiPurchases" class="text-xl font-black text-emerald-400">Rs.0</div>
                    <span class="text-[10px] text-zinc-500">Goods received</span>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">🗑️ Today Waste</span>
                    <div id="kpiWaste" class="text-xl font-black text-rose-400">Rs.0</div>
                    <span class="text-[10px] text-zinc-500">Spoilage loss value</span>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">🔄 Stock Turnover</span>
                    <div id="kpiTurnover" class="text-xl font-black text-amber-300">0x</div>
                    <span class="text-[10px] text-zinc-500">Monthly usage ratio</span>
                </div>
            </section>

            <!-- ANALYTICS CHARTS GRID -->
            <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Stock Movement Chart -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>📈</span> Stock Movements (Last 7 Days)
                        </h2>
                        <span class="text-[10px] text-zinc-500">Inflow vs Outflow</span>
                    </div>
                    <div class="h-64 relative">
                        <canvas id="chartStockMovement"></canvas>
                    </div>
                </div>

                <!-- Monthly Purchases Chart -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>🛒</span> Monthly Purchases Trend
                        </h2>
                        <span class="text-[10px] text-zinc-500">Last 6 Months</span>
                    </div>
                    <div class="h-64 relative">
                        <canvas id="chartMonthlyPurchases"></canvas>
                    </div>
                </div>

                <!-- Category Inventory Valuation Chart -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>📊</span> Inventory Valuation by Category
                        </h2>
                        <span class="text-[10px] text-zinc-500">Stock Value Distribution</span>
                    </div>
                    <div class="h-64 relative flex justify-center">
                        <canvas id="chartInventoryValuation"></canvas>
                    </div>
                </div>

                <!-- Top Consumed Ingredients Chart -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>🍳</span> Top Consumed Ingredients
                        </h2>
                        <span class="text-[10px] text-zinc-500">Highest Volume Usage</span>
                    </div>
                    <div class="h-64 relative">
                        <canvas id="chartTopConsumed"></canvas>
                    </div>
                </div>
            </section>

            <!-- FAST vs SLOW MOVING & LOW STOCK ALERTS -->
            <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Low Stock Alerts -->
                <div class="lg:col-span-1 bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-3 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>⚠️</span> Low Stock Alerts
                        </h2>
                        <a href="inventory-items.php" class="text-[10px] text-amber-400 font-bold hover:underline">View All →</a>
                    </div>
                    <div id="lowStockList" class="space-y-2 max-h-80 overflow-y-auto no-scrollbar">
                        <div class="text-xs text-zinc-500 text-center py-4">Loading alerts...</div>
                    </div>
                </div>

                <!-- Fast Moving Items -->
                <div class="lg:col-span-1 bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-3 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>⚡</span> Fast Moving Stock
                        </h2>
                        <span class="text-[10px] text-zinc-500">High Transaction Frequency</span>
                    </div>
                    <div id="fastMovingList" class="space-y-2 max-h-80 overflow-y-auto no-scrollbar">
                        <div class="text-xs text-zinc-500 text-center py-4">Loading fast moving items...</div>
                    </div>
                </div>

                <!-- Slow Moving Items -->
                <div class="lg:col-span-1 bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-3 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>🐢</span> Slow Moving Stock
                        </h2>
                        <span class="text-[10px] text-zinc-500">No usage in 30+ days</span>
                    </div>
                    <div id="slowMovingList" class="space-y-2 max-h-80 overflow-y-auto no-scrollbar">
                        <div class="text-xs text-zinc-500 text-center py-4">Loading slow moving items...</div>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        const API = '../api/inventory-stream.php';

        function fmt(n) { 
            return 'Rs.' + parseFloat(n||0).toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0}); 
        }

        let chartMovements, chartPurchases, chartValuation, chartConsumed;

        async function refreshDashboard() {
            try {
                const r = await fetch(API, {credentials:'same-origin'});
                const j = await r.json();
                if (!j.success) return;
                const d = j.data;

                // Update KPIs
                document.getElementById('kpiTotalValue').textContent = fmt(d.total_value);
                document.getElementById('kpiTotalItems').textContent = d.total_items;
                document.getElementById('kpiLowStock').textContent = d.low_stock;
                document.getElementById('kpiOutOfStock').textContent = d.out_of_stock;
                document.getElementById('kpiNearExpiry').textContent = d.near_expiry;
                document.getElementById('kpiExpired').textContent = d.expired;
                document.getElementById('kpiPendingPOs').textContent = d.pending_pos;
                document.getElementById('kpiConsumed').textContent = fmt(d.today_consumption);
                document.getElementById('kpiPurchases').textContent = fmt(d.today_purchases);
                document.getElementById('kpiWaste').textContent = fmt(d.today_waste);
                document.getElementById('kpiTurnover').textContent = (d.inventory_turnover || 0) + 'x';

                // Render Low Stock List
                const lsl = document.getElementById('lowStockList');
                if (!d.low_stock_items || d.low_stock_items.length === 0) {
                    lsl.innerHTML = '<div class="text-xs text-zinc-500 text-center py-6 bg-zinc-950 rounded-2xl border border-zinc-800">✅ All stock levels healthy</div>';
                } else {
                    lsl.innerHTML = d.low_stock_items.map(i => {
                        const pct = i.minimum_stock > 0 ? Math.round((i.current_stock / i.minimum_stock) * 100) : 0;
                        const color = pct <= 0 ? 'red' : pct <= 50 ? 'rose' : 'amber';
                        return `<div class="flex items-center justify-between bg-zinc-950 p-2.5 rounded-2xl border border-${color}-500/20 text-xs">
                            <div><span class="font-bold text-white">${i.name}</span>
                            <div class="text-[10px] text-${color}-400 font-semibold">${parseFloat(i.current_stock).toFixed(1)} / ${parseFloat(i.minimum_stock).toFixed(1)} ${i.unit}</div></div>
                            <div class="px-2 py-1 rounded-xl bg-${color}-500/10 text-${color}-400 font-black text-[10px]">${pct}%</div>
                        </div>`;
                    }).join('');
                }

                // Fast Moving Items
                const fml = document.getElementById('fastMovingList');
                if (!d.fast_moving || d.fast_moving.length === 0) {
                    fml.innerHTML = '<div class="text-xs text-zinc-500 text-center py-6 bg-zinc-950 rounded-2xl border border-zinc-800">No movement history</div>';
                } else {
                    fml.innerHTML = d.fast_moving.map(i => `
                        <div class="flex items-center justify-between bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800 text-xs">
                            <div><span class="font-bold text-white">${i.name}</span>
                            <div class="text-[10px] text-zinc-500">${i.move_count} transactions</div></div>
                            <span class="font-black text-amber-400 text-xs">${parseFloat(i.total_qty).toFixed(1)}</span>
                        </div>
                    `).join('');
                }

                // Slow Moving Items
                const sml = document.getElementById('slowMovingList');
                if (!d.slow_moving || d.slow_moving.length === 0) {
                    sml.innerHTML = '<div class="text-xs text-zinc-500 text-center py-6 bg-zinc-950 rounded-2xl border border-zinc-800">No idle stock detected</div>';
                } else {
                    sml.innerHTML = d.slow_moving.map(i => `
                        <div class="flex items-center justify-between bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800 text-xs">
                            <div><span class="font-bold text-white">${i.name}</span>
                            <div class="text-[10px] text-rose-400">Idle for 30+ days</div></div>
                            <span class="font-bold text-zinc-400">${parseFloat(i.current_stock).toFixed(1)} ${i.unit}</span>
                        </div>
                    `).join('');
                }

                // Render Charts
                if (d.charts) renderCharts(d.charts, d.categories);

            } catch(e) { console.warn('Dashboard error:', e); }
        }

        function renderCharts(c, categories) {
            // Chart 1: Stock Movements
            if (chartMovements) chartMovements.destroy();
            const ctxSm = document.getElementById('chartStockMovement').getContext('2d');
            chartMovements = new Chart(ctxSm, {
                type: 'line',
                data: {
                    labels: c.stock_movement.labels,
                    datasets: [
                        { label: 'Stock In', data: c.stock_movement.in, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true, tension: 0.3 },
                        { label: 'Stock Out', data: c.stock_movement.out, borderColor: '#f43f5e', backgroundColor: 'rgba(244, 63, 94, 0.1)', fill: true, tension: 0.3 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: '#a1a1aa' } } },
                    scales: {
                        x: { ticks: { color: '#71717a' }, grid: { color: '#27272a' } },
                        y: { ticks: { color: '#71717a' }, grid: { color: '#27272a' } }
                    }
                }
            });

            // Chart 2: Monthly Purchases
            if (chartPurchases) chartPurchases.destroy();
            const ctxMp = document.getElementById('chartMonthlyPurchases').getContext('2d');
            chartPurchases = new Chart(ctxMp, {
                type: 'bar',
                data: {
                    labels: c.monthly_purchases.labels,
                    datasets: [{ label: 'Purchases (Rs)', data: c.monthly_purchases.totals, backgroundColor: '#f59e0b', borderRadius: 8 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#71717a' }, grid: { display: false } },
                        y: { ticks: { color: '#71717a' }, grid: { color: '#27272a' } }
                    }
                }
            });

            // Chart 3: Category Valuation
            if (chartValuation) chartValuation.destroy();
            const ctxVal = document.getElementById('chartInventoryValuation').getContext('2d');
            const catLabels = (categories || []).map(cat => cat.name);
            const catVals = (categories || []).map(cat => parseFloat(cat.value));
            chartValuation = new Chart(ctxVal, {
                type: 'doughnut',
                data: {
                    labels: catLabels,
                    datasets: [{
                        data: catVals,
                        backgroundColor: ['#f59e0b', '#10b981', '#3b82f6', '#ec4899', '#8b5cf6', '#06b6d4', '#f97316', '#6366f1', '#14b8a6', '#64748b']
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'right', labels: { color: '#a1a1aa', font: { size: 11 } } } }
                }
            });

            // Chart 4: Top Consumed Ingredients
            if (chartConsumed) chartConsumed.destroy();
            const ctxTc = document.getElementById('chartTopConsumed').getContext('2d');
            chartConsumed = new Chart(ctxTc, {
                type: 'bar',
                data: {
                    labels: c.top_consumed.labels,
                    datasets: [{ label: 'Quantity Consumed', data: c.top_consumed.quantities, backgroundColor: '#a855f7', borderRadius: 8 }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#71717a' }, grid: { color: '#27272a' } },
                        y: { ticks: { color: '#71717a' }, grid: { display: false } }
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            refreshDashboard();
            setInterval(refreshDashboard, 6000);
        });
    </script>
</body>
</html>
