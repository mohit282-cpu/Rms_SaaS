<?php
// admin/inventory-reports.php - Enterprise Inventory Reports & Analytics
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Inventory Reports';
$currentPage = 'inventory-reports';
$conn = getDBConnection();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen pb-12">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">📊 Inventory Reports & Intelligence</h1>
                    <p class="text-xs text-zinc-400">Valuation, Consumption, Purchases, Suppliers, Waste, Movements, and Expiry Analysis</p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="window.print()" class="h-10 px-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
                        🖨️ Print / Export PDF
                    </button>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <!-- REPORT TYPE SELECTOR TABS -->
            <div class="flex items-center gap-2 overflow-x-auto pb-2 no-scrollbar border-b border-zinc-800">
                <button onclick="switchReport('valuation')" id="tab-valuation" class="px-4 py-2.5 rounded-2xl font-bold text-xs bg-amber-500 text-zinc-950 shadow-lg shadow-amber-500/20 whitespace-nowrap">
                    💰 Inventory Valuation
                </button>
                <button onclick="switchReport('consumption')" id="tab-consumption" class="px-4 py-2.5 rounded-2xl font-bold text-xs text-zinc-400 hover:text-white hover:bg-zinc-900 whitespace-nowrap">
                    🔥 Consumption Report
                </button>
                <button onclick="switchReport('purchase')" id="tab-purchase" class="px-4 py-2.5 rounded-2xl font-bold text-xs text-zinc-400 hover:text-white hover:bg-zinc-900 whitespace-nowrap">
                    🛒 Purchase Report
                </button>
                <button onclick="switchReport('supplier')" id="tab-supplier" class="px-4 py-2.5 rounded-2xl font-bold text-xs text-zinc-400 hover:text-white hover:bg-zinc-900 whitespace-nowrap">
                    🏭 Supplier Report
                </button>
                <button onclick="switchReport('waste')" id="tab-waste" class="px-4 py-2.5 rounded-2xl font-bold text-xs text-zinc-400 hover:text-white hover:bg-zinc-900 whitespace-nowrap">
                    🗑️ Waste Report
                </button>
                <button onclick="switchReport('movement')" id="tab-movement" class="px-4 py-2.5 rounded-2xl font-bold text-xs text-zinc-400 hover:text-white hover:bg-zinc-900 whitespace-nowrap">
                    🔄 Movements Log
                </button>
                <button onclick="switchReport('expiry')" id="tab-expiry" class="px-4 py-2.5 rounded-2xl font-bold text-xs text-zinc-400 hover:text-white hover:bg-zinc-900 whitespace-nowrap">
                    ⏰ Expiry Report
                </button>
            </div>

            <!-- REPORT DISPLAY CONTAINER -->
            <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-800 pb-4">
                    <div>
                        <h2 id="reportTitle" class="text-base font-black text-white">Inventory Valuation Report</h2>
                        <p id="reportDesc" class="text-xs text-zinc-500">Live breakdown of all active stock items and holding asset values</p>
                    </div>
                    <div id="reportSummaryBadge" class="text-right">
                        <div class="text-xs text-zinc-500 font-semibold">Total Report Value</div>
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
        const API = '../api/inventory.php';

        function fmt(n) { return 'Rs.' + parseFloat(n||0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); }

        let currentTab = 'valuation';

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

            body.innerHTML = '<tr><td colspan="6" class="text-center py-12 text-zinc-500">Loading report data...</td></tr>';

            if (currentTab === 'valuation') {
                title.textContent = '💰 Inventory Valuation Report';
                desc.textContent = 'Total value of active ingredients and supplies on hand';
                const r = await fetch(API + '?action=list_items&status=active');
                const j = await r.json();
                const items = j.items || [];

                let grandTotal = 0;
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">Item Name</th>
                        <th class="py-3 px-4">Category</th>
                        <th class="py-3 px-4">Current Stock</th>
                        <th class="py-3 px-4">Avg Cost</th>
                        <th class="py-3 px-4">Total Value</th>
                        <th class="py-3 px-4">Location</th>
                    </tr>
                `;
                body.innerHTML = items.map(i => {
                    const stock = parseFloat(i.current_stock);
                    const cost = parseFloat(i.average_cost || i.purchase_cost || 0);
                    const val = stock * cost;
                    grandTotal += val;
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-bold text-white">${i.name}</td>
                            <td class="py-3 px-4 text-zinc-400">${i.category_icon||'📦'} ${i.category_name||'—'}</td>
                            <td class="py-3 px-4 font-bold text-zinc-200">${stock.toFixed(2)} ${i.unit_abbr||'pcs'}</td>
                            <td class="py-3 px-4">${fmt(cost)}</td>
                            <td class="py-3 px-4 font-black text-amber-400">${fmt(val)}</td>
                            <td class="py-3 px-4 text-zinc-500">${i.storage_location||'Store'}</td>
                        </tr>
                    `;
                }).join('');
                totalVal.textContent = fmt(grandTotal);

            } else if (currentTab === 'consumption') {
                title.textContent = '🔥 Ingredient Consumption Report';
                desc.textContent = 'Kitchen ingredient usage triggered by POS completed orders';
                const r = await fetch(API + '?action=list_movements&type=consumption');
                const j = await r.json();
                const items = j.movements || [];

                let grandTotal = 0;
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">Ingredient Name</th>
                        <th class="py-3 px-4">Date / Time</th>
                        <th class="py-3 px-4">Quantity Consumed</th>
                        <th class="py-3 px-4">Unit Cost</th>
                        <th class="py-3 px-4">Total Loss/Cost</th>
                        <th class="py-3 px-4">Reference</th>
                    </tr>
                `;
                body.innerHTML = items.map(i => {
                    const qty = parseFloat(i.quantity);
                    const cost = parseFloat(i.unit_cost||0);
                    const val = qty * cost;
                    grandTotal += val;
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-bold text-white">${i.item_name}</td>
                            <td class="py-3 px-4 text-zinc-400">${new Date(i.created_at).toLocaleString()}</td>
                            <td class="py-3 px-4 font-bold text-purple-400">${qty.toFixed(2)} ${i.unit_abbr||'pcs'}</td>
                            <td class="py-3 px-4">${fmt(cost)}</td>
                            <td class="py-3 px-4 font-black text-purple-300">${fmt(val)}</td>
                            <td class="py-3 px-4 text-zinc-500">${i.notes||'Order Deduction'}</td>
                        </tr>
                    `;
                }).join('');
                totalVal.textContent = fmt(grandTotal);

            } else if (currentTab === 'purchase') {
                title.textContent = '🛒 Purchase & Procurement Report';
                desc.textContent = 'Summary of purchase orders placed and vendor invoices';
                const r = await fetch(API + '?action=list_pos');
                const j = await r.json();
                const orders = j.orders || [];

                let grandTotal = 0;
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">PO Number</th>
                        <th class="py-3 px-4">Supplier</th>
                        <th class="py-3 px-4">Order Date</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Total Amount</th>
                    </tr>
                `;
                body.innerHTML = orders.map(o => {
                    const val = parseFloat(o.total_amount);
                    grandTotal += val;
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-bold text-amber-400">${o.po_number}</td>
                            <td class="py-3 px-4 text-white">${o.supplier_name||'—'}</td>
                            <td class="py-3 px-4 text-zinc-400">${o.order_date||'—'}</td>
                            <td class="py-3 px-4 font-bold text-emerald-400">${(o.status||'').toUpperCase()}</td>
                            <td class="py-3 px-4 font-black text-amber-400">${fmt(val)}</td>
                        </tr>
                    `;
                }).join('');
                totalVal.textContent = fmt(grandTotal);

            } else if (currentTab === 'supplier') {
                title.textContent = '🏭 Supplier Performance Report';
                desc.textContent = 'Active vendors, outstanding balances, and rating stats';
                const r = await fetch(API + '?action=list_suppliers');
                const j = await r.json();
                const suppliers = j.suppliers || [];

                let grandTotal = 0;
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">Company Name</th>
                        <th class="py-3 px-4">Contact Person</th>
                        <th class="py-3 px-4">Phone / Email</th>
                        <th class="py-3 px-4">VAT/PAN</th>
                        <th class="py-3 px-4">Rating</th>
                        <th class="py-3 px-4">Outstanding Balance</th>
                    </tr>
                `;
                body.innerHTML = suppliers.map(s => {
                    const bal = parseFloat(s.outstanding_balance||0);
                    grandTotal += bal;
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-bold text-white">${s.company_name}</td>
                            <td class="py-3 px-4 text-zinc-300">${s.contact_person||'—'}</td>
                            <td class="py-3 px-4 text-zinc-400">${s.phone||'—'} / ${s.email||'—'}</td>
                            <td class="py-3 px-4 text-zinc-400">${s.vat_pan||'—'}</td>
                            <td class="py-3 px-4 font-bold text-amber-400">★ ${parseFloat(s.performance_rating||5).toFixed(1)}</td>
                            <td class="py-3 px-4 font-black text-rose-400">${fmt(bal)}</td>
                        </tr>
                    `;
                }).join('');
                totalVal.textContent = fmt(grandTotal);

            } else if (currentTab === 'waste') {
                title.textContent = '🗑️ Kitchen Waste & Loss Report';
                desc.textContent = 'Recorded waste entries, spoilage loss, and approval statuses';
                const r = await fetch(API + '?action=list_waste');
                const j = await r.json();
                const items = j.waste || [];

                let grandTotal = 0;
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">Item Name</th>
                        <th class="py-3 px-4">Reason</th>
                        <th class="py-3 px-4">Date</th>
                        <th class="py-3 px-4">Quantity</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Total Loss Value</th>
                    </tr>
                `;
                body.innerHTML = items.map(w => {
                    const loss = parseFloat(w.total_cost||0);
                    grandTotal += loss;
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-bold text-white">${w.item_name}</td>
                            <td class="py-3 px-4 text-rose-400 font-bold">${w.reason}</td>
                            <td class="py-3 px-4 text-zinc-400">${new Date(w.created_at).toLocaleString()}</td>
                            <td class="py-3 px-4 font-bold text-zinc-200">${parseFloat(w.quantity).toFixed(2)} ${w.unit_abbr||'pcs'}</td>
                            <td class="py-3 px-4 font-bold text-amber-400">${(w.approval_status||'pending').toUpperCase()}</td>
                            <td class="py-3 px-4 font-black text-rose-400">${fmt(loss)}</td>
                        </tr>
                    `;
                }).join('');
                totalVal.textContent = fmt(grandTotal);

            } else if (currentTab === 'movement') {
                title.textContent = '🔄 Immutable Stock Movement Audit Log';
                desc.textContent = 'Complete audit history of stock inflows, outflows, and adjustments';
                const r = await fetch(API + '?action=list_movements');
                const j = await r.json();
                const items = j.movements || [];

                totalVal.textContent = items.length + ' Movements';
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">Item Name</th>
                        <th class="py-3 px-4">Type</th>
                        <th class="py-3 px-4">Direction</th>
                        <th class="py-3 px-4">Quantity</th>
                        <th class="py-3 px-4">Stock Before → After</th>
                        <th class="py-3 px-4">Timestamp</th>
                    </tr>
                `;
                body.innerHTML = items.map(t => {
                    const isIn = t.direction === 'in';
                    const color = isIn ? 'emerald' : 'rose';
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-bold text-white">${t.item_name}</td>
                            <td class="py-3 px-4 font-bold text-amber-400 uppercase">${t.type}</td>
                            <td class="py-3 px-4 font-bold text-${color}-400">${(t.direction||'').toUpperCase()}</td>
                            <td class="py-3 px-4 font-black text-${color}-400">${isIn?'+':'-'}${parseFloat(t.quantity).toFixed(2)} ${t.unit_abbr||'pcs'}</td>
                            <td class="py-3 px-4 text-zinc-400">${parseFloat(t.stock_before).toFixed(1)} → ${parseFloat(t.stock_after).toFixed(1)}</td>
                            <td class="py-3 px-4 text-zinc-500">${new Date(t.created_at).toLocaleString()}</td>
                        </tr>
                    `;
                }).join('');

            } else if (currentTab === 'expiry') {
                title.textContent = '⏰ Expiry & Near Expiry Alert Report';
                desc.textContent = 'Perishable stock items nearing or past expiry dates';
                const r = await fetch(API + '?action=list_items&status=active');
                const j = await r.json();
                const allItems = j.items || [];
                const expItems = allItems.filter(i => i.expiry_date);

                totalVal.textContent = expItems.length + ' Tracked Items';
                head.innerHTML = `
                    <tr>
                        <th class="py-3 px-4">Item Name</th>
                        <th class="py-3 px-4">Category</th>
                        <th class="py-3 px-4">Current Stock</th>
                        <th class="py-3 px-4">Expiry Date</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Location</th>
                    </tr>
                `;
                body.innerHTML = expItems.map(i => {
                    const isExpired = new Date(i.expiry_date) < new Date();
                    const statusText = isExpired ? '☠️ EXPIRED' : '⏰ NEAR EXPIRY';
                    const statusColor = isExpired ? 'rose' : 'orange';
                    return `
                        <tr class="hover:bg-zinc-950/50">
                            <td class="py-3 px-4 font-bold text-white">${i.name}</td>
                            <td class="py-3 px-4 text-zinc-400">${i.category_name||'—'}</td>
                            <td class="py-3 px-4 font-bold text-zinc-200">${parseFloat(i.current_stock).toFixed(1)} ${i.unit_abbr||'pcs'}</td>
                            <td class="py-3 px-4 font-black text-${statusColor}-400">${i.expiry_date}</td>
                            <td class="py-3 px-4 font-bold text-${statusColor}-400">${statusText}</td>
                            <td class="py-3 px-4 text-zinc-500">${i.storage_location||'—'}</td>
                        </tr>
                    `;
                }).join('');
            }
        }

        document.addEventListener('DOMContentLoaded', loadReport);
    </script>
</body>
</html>
