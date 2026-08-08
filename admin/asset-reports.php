<?php
// admin/asset-reports.php - Enterprise Asset Reports & Financial Schedule
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Asset Reports';
$currentPage = 'asset-reports';
$conn = getDBConnection();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen pb-12">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">📈 Asset Reports & Financial Schedules</h1>
                    <p class="text-xs text-zinc-400">Capital Asset Register, Depreciation Schedules, Maintenance Expenditures, and Warranty Expiry Reports</p>
                </div>
                <button onclick="window.print()" class="h-10 px-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
                    🖨️ Print / Export PDF
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <!-- REPORT TABS -->
            <div class="flex items-center gap-2 overflow-x-auto pb-2 no-scrollbar border-b border-zinc-800">
                <button onclick="switchReport('register')" id="tab-register" class="px-4 py-2.5 rounded-2xl font-bold text-xs bg-amber-500 text-zinc-950 shadow-lg shadow-amber-500/20 whitespace-nowrap">
                    🏗️ Asset Register Report
                </button>
                <button onclick="switchReport('depreciation')" id="tab-depreciation" class="px-4 py-2.5 rounded-2xl font-bold text-xs text-zinc-400 hover:text-white hover:bg-zinc-900 whitespace-nowrap">
                    📉 Depreciation Schedule
                </button>
                <button onclick="switchReport('maintenance')" id="tab-maintenance" class="px-4 py-2.5 rounded-2xl font-bold text-xs text-zinc-400 hover:text-white hover:bg-zinc-900 whitespace-nowrap">
                    🔧 Maintenance Expenditure
                </button>
                <button onclick="switchReport('warranty')" id="tab-warranty" class="px-4 py-2.5 rounded-2xl font-bold text-xs text-zinc-400 hover:text-white hover:bg-zinc-900 whitespace-nowrap">
                    🛡️ Warranty Expiry Report
                </button>
            </div>

            <!-- DISPLAY TABLE CONTAINER -->
            <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-800 pb-4">
                    <div>
                        <h2 id="reportTitle" class="text-base font-black text-white">Asset Register Report</h2>
                        <p id="reportDesc" class="text-xs text-zinc-500">Summary of all capital equipment, purchase costs, and book values</p>
                    </div>
                    <div id="reportSummaryBadge" class="text-right">
                        <div class="text-xs text-zinc-500 font-semibold">Total Net Book Value</div>
                        <div id="reportTotalVal" class="text-lg font-black text-amber-400">Rs.0</div>
                    </div>
                </div>

                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left text-xs">
                        <thead id="reportTableHead" class="text-[11px] font-black text-zinc-400 uppercase tracking-wider bg-zinc-950/80 border-b border-zinc-800">
                        </thead>
                        <tbody id="reportTableBody" class="divide-y divide-zinc-800/60 text-zinc-300">
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script>
        const API = '../api/assets.php';

        function fmt(n) { return 'Rs.' + parseFloat(n||0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); }

        let currentTab = 'register';

        function switchReport(tab) {
            currentTab = tab;
            document.querySelectorAll('[id^="tab-"]').forEach(btn => {
                btn.className = 'px-4 py-2.5 rounded-2xl font-bold text-xs text-zinc-400 hover:text-white hover:bg-zinc-900 whitespace-nowrap';
            });
            const activeBtn = document.getElementById('tab-' + tab);
            if (activeBtn) activeBtn.className = 'px-4 py-2.5 rounded-2xl font-bold text-xs bg-amber-500 text-zinc-950 shadow-lg shadow-amber-500/20 whitespace-nowrap';

            loadReport();
        }

        async function loadReport() {
            const head = document.getElementById('reportTableHead');
            const body = document.getElementById('reportTableBody');
            const title = document.getElementById('reportTitle');
            const desc = document.getElementById('reportDesc');
            const totalVal = document.getElementById('reportTotalVal');

            body.innerHTML = '<tr><td colspan="6" class="text-center py-12 text-zinc-500">Loading asset report...</td></tr>';

            if (currentTab === 'register') {
                title.textContent = '🏗️ Capital Asset Register Report';
                desc.textContent = 'Full catalog of registered capital equipment, location, custodian & valuation';
                const r = await fetch(API + '?action=list_assets');
                const j = await r.json();
                const items = j.assets || [];

                let grandTotal = 0;
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">Asset Code</th>
                        <th class="py-3 px-4">Asset Name</th>
                        <th class="py-3 px-4">Category</th>
                        <th class="py-3 px-4">Location / Custodian</th>
                        <th class="py-3 px-4">Purchase Cost</th>
                        <th class="py-3 px-4">Net Book Value</th>
                        <th class="py-3 px-4">Status</th>
                    </tr>
                `;
                body.innerHTML = items.map(a => {
                    const cost = parseFloat(a.purchase_cost||0);
                    const bookVal = parseFloat(a.current_value||cost);
                    grandTotal += bookVal;
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-mono font-bold text-amber-400">${a.asset_code}</td>
                            <td class="py-3 px-4 font-bold text-white">${a.name}</td>
                            <td class="py-3 px-4 text-zinc-400">${a.category_icon||'🏗️'} ${a.category_name||'—'}</td>
                            <td class="py-3 px-4 text-zinc-300">${a.assigned_location||'—'} ${a.assigned_employee?'('+a.assigned_employee+')':''}</td>
                            <td class="py-3 px-4 text-zinc-400">${fmt(cost)}</td>
                            <td class="py-3 px-4 font-black text-amber-400">${fmt(bookVal)}</td>
                            <td class="py-3 px-4 font-bold text-emerald-400">${(a.status||'available').toUpperCase()}</td>
                        </tr>
                    `;
                }).join('');
                totalVal.textContent = fmt(grandTotal);

            } else if (currentTab === 'depreciation') {
                title.textContent = '📉 Asset Depreciation Schedule Report';
                desc.textContent = 'Written off depreciation amounts and ending book values per asset';
                const r = await fetch(API + '?action=list_depreciation');
                const j = await r.json();
                const items = j.depreciation || [];

                let grandTotal = 0;
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">Period Date</th>
                        <th class="py-3 px-4">Asset Name</th>
                        <th class="py-3 px-4">Asset Code</th>
                        <th class="py-3 px-4">Period Write-Off</th>
                        <th class="py-3 px-4">Accumulated Dep.</th>
                        <th class="py-3 px-4">Ending Book Value</th>
                    </tr>
                `;
                body.innerHTML = items.map(d => {
                    const depAmt = parseFloat(d.depreciation_amount);
                    grandTotal += depAmt;
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-bold text-amber-400">${d.period_date}</td>
                            <td class="py-3 px-4 font-bold text-white">${d.asset_name}</td>
                            <td class="py-3 px-4 font-mono text-zinc-400">${d.asset_code}</td>
                            <td class="py-3 px-4 font-bold text-rose-400">${fmt(depAmt)}</td>
                            <td class="py-3 px-4 font-bold text-zinc-400">${fmt(d.accumulated_depreciation)}</td>
                            <td class="py-3 px-4 font-black text-emerald-400">${fmt(d.book_value)}</td>
                        </tr>
                    `;
                }).join('');
                totalVal.textContent = fmt(grandTotal) + ' (Total Write-Off)';

            } else if (currentTab === 'maintenance') {
                title.textContent = '🔧 Maintenance Expenditure Report';
                desc.textContent = 'Servicing costs, work orders, technicians, and parts used';
                const r = await fetch(API + '?action=list_maintenance');
                const j = await r.json();
                const items = j.maintenance || [];

                let grandTotal = 0;
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">Service Date</th>
                        <th class="py-3 px-4">Asset Name</th>
                        <th class="py-3 px-4">Type</th>
                        <th class="py-3 px-4">Technician / Vendor</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Service Cost</th>
                    </tr>
                `;
                body.innerHTML = items.map(m => {
                    const cost = parseFloat(m.cost||0);
                    grandTotal += cost;
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-bold text-amber-400">${m.service_date}</td>
                            <td class="py-3 px-4 font-bold text-white">${m.asset_name}</td>
                            <td class="py-3 px-4 font-semibold text-zinc-300">${(m.type||'').toUpperCase()}</td>
                            <td class="py-3 px-4 text-zinc-400">${m.technician||'—'}</td>
                            <td class="py-3 px-4 font-bold text-emerald-400">${(m.status||'scheduled').toUpperCase()}</td>
                            <td class="py-3 px-4 font-black text-amber-400">${fmt(cost)}</td>
                        </tr>
                    `;
                }).join('');
                totalVal.textContent = fmt(grandTotal);

            } else if (currentTab === 'warranty') {
                title.textContent = '🛡️ Warranty Expiry & Policy Report';
                desc.textContent = 'Manufacturer warranties, policy numbers, and expiration dates';
                const r = await fetch(API + '?action=list_warranties');
                const j = await r.json();
                const items = j.warranties || [];

                totalVal.textContent = items.length + ' Warranties';
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">Asset Name</th>
                        <th class="py-3 px-4">Provider Name</th>
                        <th class="py-3 px-4">Policy #</th>
                        <th class="py-3 px-4">Expiry Date</th>
                        <th class="py-3 px-4">Claim Status</th>
                    </tr>
                `;
                body.innerHTML = items.map(w => {
                    const isExp = new Date(w.expiry_date) < new Date();
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-bold text-white">${w.asset_name} <span class="text-zinc-500 font-mono text-[10px]">(${w.asset_code})</span></td>
                            <td class="py-3 px-4 text-zinc-300">${w.provider_name||'—'}</td>
                            <td class="py-3 px-4 font-mono text-zinc-400">${w.policy_number||'—'}</td>
                            <td class="py-3 px-4 font-black text-${isExp?'rose':'emerald'}-400">${w.expiry_date}</td>
                            <td class="py-3 px-4 font-bold text-amber-400">${(w.claim_status||'active').toUpperCase()}</td>
                        </tr>
                    `;
                }).join('');
            }
        }

        document.addEventListener('DOMContentLoaded', loadReport);
    </script>
</body>
</html>
