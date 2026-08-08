<?php
// admin/asset-depreciation.php - Asset Depreciation Calculator & Batch Processing Engine
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Asset Depreciation';
$currentPage = 'asset-depreciation';
$conn = getDBConnection();
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen pb-12">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">📉 Asset Depreciation Engine</h1>
                    <p class="text-xs text-zinc-400">Straight Line & Declining Balance automated asset write-offs and book value schedules</p>
                </div>
                <button onclick="runDepreciationBatch()" class="h-10 px-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">
                    ⚡ Execute Monthly Depreciation Batch
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <!-- DEPRECIATION SCHEDULE TABLE -->
            <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                    <div>
                        <h2 class="text-sm font-black text-white">📜 Depreciation Log & Accumulated Write-Offs</h2>
                        <p class="text-xs text-zinc-500">Monthly asset depreciation entries and updated net book values</p>
                    </div>
                    <span class="text-xs text-amber-400 font-bold">Automated Batch Calculations</span>
                </div>

                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left text-xs">
                        <thead class="text-[11px] font-black text-zinc-400 uppercase tracking-wider bg-zinc-950/80 border-b border-zinc-800">
                            <tr>
                                <th class="py-3 px-4">Period Date</th>
                                <th class="py-3 px-4">Asset Name</th>
                                <th class="py-3 px-4">Asset Code</th>
                                <th class="py-3 px-4">Method</th>
                                <th class="py-3 px-4">Period Expense</th>
                                <th class="py-3 px-4">Accumulated Dep.</th>
                                <th class="py-3 px-4">Ending Book Value</th>
                            </tr>
                        </thead>
                        <tbody id="depTableBody" class="divide-y divide-zinc-800/60 text-zinc-300">
                            <tr>
                                <td colspan="7" class="text-center py-12 text-zinc-500">Loading depreciation log...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script>
        const API = '../api/assets.php';
        const CSRF = '<?php echo $csrfToken; ?>';

        function fmt(n) { return 'Rs.' + parseFloat(n||0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); }

        async function loadDepreciationLog() {
            const r = await fetch(API + '?action=list_depreciation');
            const j = await r.json();
            const body = document.getElementById('depTableBody');
            const items = j.depreciation || [];

            if (items.length === 0) {
                body.innerHTML = '<tr><td colspan="7" class="text-center py-12 text-zinc-500">No depreciation runs posted yet. Click "Execute Monthly Depreciation Batch" above.</td></tr>';
                return;
            }

            body.innerHTML = items.map(d => `
                <tr class="hover:bg-zinc-950/50">
                    <td class="py-3 px-4 font-bold text-amber-400">${d.period_date}</td>
                    <td class="py-3 px-4 font-bold text-white">${d.asset_name}</td>
                    <td class="py-3 px-4 font-mono text-zinc-400">${d.asset_code}</td>
                    <td class="py-3 px-4 font-semibold text-zinc-300">${(d.method||'straight_line').replace('_',' ')}</td>
                    <td class="py-3 px-4 font-bold text-rose-400">${fmt(d.depreciation_amount)}</td>
                    <td class="py-3 px-4 font-bold text-zinc-400">${fmt(d.accumulated_depreciation)}</td>
                    <td class="py-3 px-4 font-black text-emerald-400">${fmt(d.book_value)}</td>
                </tr>
            `).join('');
        }

        async function runDepreciationBatch() {
            if (!confirm('Run monthly depreciation batch calculation for all active capital assets?')) return;
            const fd = new FormData();
            fd.append('action', 'run_depreciation');
            fd.append('csrf_token', CSRF);

            const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            const j = await r.json();
            alert(j.message);
            if (j.success) {
                loadDepreciationLog();
            }
        }

        document.addEventListener('DOMContentLoaded', loadDepreciationLog);
    </script>
</body>
</html>
