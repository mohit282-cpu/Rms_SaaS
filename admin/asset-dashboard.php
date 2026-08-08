<?php
// admin/asset-dashboard.php - Enterprise Asset Dashboard & Analytics
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Asset Dashboard';
$currentPage = 'asset-dashboard';
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen pb-12">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg md:text-xl font-black text-white">🏗️ Asset Dashboard</h1>
                        <span class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black uppercase tracking-wider">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span> Live Assets
                        </span>
                    </div>
                    <p class="text-xs text-zinc-400 hidden sm:block">Realtime asset register, maintenance alerts, warranty tracking, and net book valuations</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="assets.php" class="h-10 px-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs flex items-center gap-1.5 shadow-lg shadow-amber-500/20 transition-all">
                        <span>+</span> Register New Asset
                    </a>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <!-- REALTIME KPI CARDS -->
            <section id="kpiGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                <div class="bg-zinc-900/90 border border-amber-500/30 rounded-3xl p-4 space-y-1.5 col-span-2 shadow-xl">
                    <span class="text-xs text-zinc-400 font-bold block">💰 Net Book Value</span>
                    <div id="kpiBookValue" class="text-2xl md:text-3xl font-black text-amber-400">Rs.0</div>
                    <p class="text-[10px] text-zinc-500">Current depreciated value of active assets</p>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">🏗️ Total Assets</span>
                    <div id="kpiTotalAssets" class="text-xl font-black text-white">0</div>
                    <span class="text-[10px] text-zinc-500">Registered equipment</span>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">💵 Total Purchase Cost</span>
                    <div id="kpiTotalCost" class="text-xl font-black text-emerald-400">Rs.0</div>
                    <span class="text-[10px] text-zinc-500">Original acquisition cost</span>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">📉 Accum. Depreciation</span>
                    <div id="kpiAccumDep" class="text-xl font-black text-rose-400">Rs.0</div>
                    <span class="text-[10px] text-zinc-500">Total written off</span>
                </div>
                <div class="bg-zinc-900/90 border border-emerald-500/30 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">✅ Active / In Use</span>
                    <div id="kpiActiveAssets" class="text-xl font-black text-emerald-400">0</div>
                    <span class="text-[10px] text-zinc-500">Operational status</span>
                </div>
                <div class="bg-zinc-900/90 border border-amber-500/30 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">🔧 In Maintenance</span>
                    <div id="kpiInMaintenance" class="text-xl font-black text-amber-400">0</div>
                    <span class="text-[10px] text-zinc-500">Under service/repair</span>
                </div>
                <div class="bg-zinc-900/90 border border-orange-500/30 rounded-3xl p-4 space-y-1 shadow-lg">
                    <span class="text-xs text-zinc-400 font-bold block">🛡️ Expiring Warranty</span>
                    <div id="kpiExpiringWarranty" class="text-xl font-black text-orange-400">0</div>
                    <span class="text-[10px] text-zinc-500">Within 30 days</span>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-1 shadow-lg col-span-2 sm:col-span-1">
                    <span class="text-xs text-zinc-400 font-bold block">🛠️ Month Maint. Cost</span>
                    <div id="kpiMaintCost" class="text-xl font-black text-blue-400">Rs.0</div>
                    <span class="text-[10px] text-zinc-500">Servicing expense</span>
                </div>
            </section>

            <!-- CHARTS SECTION -->
            <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Status Breakdown Chart -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>📊</span> Asset Status Breakdown
                        </h2>
                        <span class="text-[10px] text-zinc-500">Current Operational State</span>
                    </div>
                    <div class="h-64 relative flex justify-center">
                        <canvas id="chartAssetStatus"></canvas>
                    </div>
                </div>

                <!-- Category Valuation Chart -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>🏢</span> Category Net Book Value
                        </h2>
                        <span class="text-[10px] text-zinc-500">Asset Distribution by Category</span>
                    </div>
                    <div class="h-64 relative">
                        <canvas id="chartAssetCategories"></canvas>
                    </div>
                </div>
            </section>

            <!-- UPCOMING MAINTENANCE & TRANSFERS -->
            <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Upcoming Maintenance -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-3 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>🔧</span> Upcoming Scheduled Maintenance
                        </h2>
                        <a href="asset-maintenance.php" class="text-[10px] text-amber-400 font-bold hover:underline">View All →</a>
                    </div>
                    <div id="maintList" class="space-y-2 max-h-80 overflow-y-auto no-scrollbar">
                        <div class="text-xs text-zinc-500 text-center py-4">Loading maintenance schedule...</div>
                    </div>
                </div>

                <!-- Recent Asset Transfers -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-3 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-white flex items-center gap-2">
                            <span>🚚</span> Recent Asset Transfers
                        </h2>
                        <a href="asset-transfers.php" class="text-[10px] text-amber-400 font-bold hover:underline">View All →</a>
                    </div>
                    <div id="transferList" class="space-y-2 max-h-80 overflow-y-auto no-scrollbar">
                        <div class="text-xs text-zinc-500 text-center py-4">Loading transfer history...</div>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        const API = '../api/asset-stream.php';

        function fmt(n) { 
            return 'Rs.' + parseFloat(n||0).toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0}); 
        }

        let chartStatus, chartCategories;

        async function refreshDashboard() {
            try {
                const r = await fetch(API, {credentials:'same-origin'});
                const j = await r.json();
                if (!j.success) return;
                const d = j.data;

                // Update KPIs
                document.getElementById('kpiBookValue').textContent = fmt(d.net_book_value);
                document.getElementById('kpiTotalAssets').textContent = d.total_assets;
                document.getElementById('kpiTotalCost').textContent = fmt(d.total_cost);
                document.getElementById('kpiAccumDep').textContent = fmt(d.accumulated_depreciation);
                document.getElementById('kpiActiveAssets').textContent = d.active_assets;
                document.getElementById('kpiInMaintenance').textContent = d.in_maintenance;
                document.getElementById('kpiExpiringWarranty').textContent = d.expiring_warranties;
                document.getElementById('kpiMaintCost').textContent = fmt(d.month_maintenance_cost);

                // Upcoming Maintenance List
                const ml = document.getElementById('maintList');
                if (!d.upcoming_maintenance || d.upcoming_maintenance.length === 0) {
                    ml.innerHTML = '<div class="text-xs text-zinc-500 text-center py-6 bg-zinc-950 rounded-2xl border border-zinc-800">✅ No upcoming maintenance work orders</div>';
                } else {
                    ml.innerHTML = d.upcoming_maintenance.map(m => `
                        <div class="flex items-center justify-between bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800 text-xs">
                            <div>
                                <span class="font-bold text-white">${m.asset_name}</span>
                                <div class="text-[10px] text-zinc-500">${m.type.toUpperCase()} · Tech: ${m.technician||'Unassigned'}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs font-black text-amber-400">📅 ${m.service_date}</div>
                                <div class="text-[10px] text-zinc-500">Cost: ${fmt(m.cost)}</div>
                            </div>
                        </div>
                    `).join('');
                }

                // Recent Transfers List
                const tl = document.getElementById('transferList');
                if (!d.recent_transfers || d.recent_transfers.length === 0) {
                    tl.innerHTML = '<div class="text-xs text-zinc-500 text-center py-6 bg-zinc-950 rounded-2xl border border-zinc-800">No asset location transfers logged</div>';
                } else {
                    tl.innerHTML = d.recent_transfers.map(t => `
                        <div class="flex items-center justify-between bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800 text-xs">
                            <div>
                                <span class="font-bold text-white">${t.asset_name} (${t.asset_code})</span>
                                <div class="text-[10px] text-zinc-400">${t.from_location||'?'} → <strong class="text-amber-400">${t.to_location||'?'}</strong></div>
                            </div>
                            <span class="text-[10px] text-zinc-500">📅 ${t.transfer_date}</span>
                        </div>
                    `).join('');
                }

                renderCharts(d.status_breakdown || {}, d.category_breakdown || []);

            } catch(e) { console.warn('Asset dashboard error:', e); }
        }

        function renderCharts(statusObj, catArr) {
            // Chart 1: Status Breakdown
            if (chartStatus) chartStatus.destroy();
            const ctxSt = document.getElementById('chartAssetStatus').getContext('2d');
            const labels = Object.keys(statusObj).map(s => s.replace('_',' ').toUpperCase());
            const values = Object.values(statusObj);

            chartStatus = new Chart(ctxSt, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#64748b', '#dc2626', '#94a3b8']
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'right', labels: { color: '#a1a1aa', font: { size: 11 } } } }
                }
            });

            // Chart 2: Category Net Book Value
            if (chartCategories) chartCategories.destroy();
            const ctxCat = document.getElementById('chartAssetCategories').getContext('2d');
            chartCategories = new Chart(ctxCat, {
                type: 'bar',
                data: {
                    labels: catArr.map(c => c.name),
                    datasets: [{ label: 'Book Value (Rs)', data: catArr.map(c => parseFloat(c.val)), backgroundColor: '#3b82f6', borderRadius: 8 }]
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
        }

        document.addEventListener('DOMContentLoaded', () => {
            refreshDashboard();
            setInterval(refreshDashboard, 7000);
        });
    </script>
</body>
</html>
