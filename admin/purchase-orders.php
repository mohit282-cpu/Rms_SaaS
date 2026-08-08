<?php
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Purchase Orders';
$currentPage = 'purchase-orders';
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>
    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div><h1 class="text-lg font-black text-white">🛒 Purchase Orders</h1><p class="text-xs text-zinc-400">Manage procurement & vendor orders</p></div>
                <div class="flex items-center gap-2">
                    <select id="filterStatus" onchange="loadPOs()" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-xs text-white outline-none">
                        <option value="">All Status</option>
                        <option value="draft">Draft</option><option value="approved">Approved</option><option value="ordered">Ordered</option>
                        <option value="partial">Partial</option><option value="received">Received</option>
                        <option value="payment_pending">Payment Pending</option><option value="completed">Completed</option>
                    </select>
                    <button onclick="openPOModal()" class="h-10 px-4 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">+ New PO</button>
                </div>
            </div>
        </header>
        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 pb-8">
            <div id="poList" class="space-y-3">
                <div class="text-center py-12 text-zinc-500 text-sm">Loading purchase orders...</div>
            </div>
        </main>
    </div>

    <!-- PO MODAL -->
    <div id="poModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-2xl max-h-[90vh] overflow-y-auto no-scrollbar shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="poTitle" class="text-sm font-black text-white">New Purchase Order</h2>
                <button onclick="closePOModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="poForm" class="p-5 space-y-4" onsubmit="savePO(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="poId" value="0">
                <input type="hidden" name="action" value="save_po">
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Supplier *</label>
                        <select name="supplier_id" id="poSupplier" required class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Expected Date</label>
                        <input type="date" name="expected_date" id="poExpDate" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                </div>
                <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Notes</label>
                    <textarea name="notes" id="poNotes" rows="2" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none focus:border-amber-500/50"></textarea></div>

                <!-- LINE ITEMS -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[11px] text-zinc-400 font-bold">Line Items</label>
                        <button type="button" onclick="addPOLine()" class="text-[10px] text-amber-400 font-bold">+ Add Item</button>
                    </div>
                    <div id="poLines" class="space-y-2"></div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Tax</label>
                        <input type="number" step="0.01" name="tax_amount" id="poTax" value="0" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Discount</label>
                        <input type="number" step="0.01" name="discount_amount" id="poDiscount" value="0" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Total</label>
                        <div id="poTotal" class="h-10 px-3 rounded-xl bg-zinc-950 border border-amber-500/30 flex items-center text-sm font-black text-amber-400">Rs.0</div></div>
                </div>
                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Save Purchase Order</button>
            </form>
        </div>
    </div>

    <script>
        const API = '../api/inventory.php';
        const CSRF = '<?php echo $csrfToken; ?>';
        let suppliers=[], invItems=[];

        async function init() {
            const [sR, iR] = await Promise.all([
                fetch(API+'?action=list_suppliers').then(r=>r.json()),
                fetch(API+'?action=list_items&status=active').then(r=>r.json())
            ]);
            suppliers = sR.suppliers||[];
            invItems = iR.items||[];
            document.getElementById('poSupplier').innerHTML = '<option value="">— Select Supplier —</option>' + suppliers.map(s=>`<option value="${s.id}">${s.company_name}</option>`).join('');
            loadPOs();
        }

        async function loadPOs() {
            const status = document.getElementById('filterStatus').value;
            const r = await fetch(`${API}?action=list_pos&status=${status}`);
            const j = await r.json();
            renderPOs(j.orders||[]);
        }

        function statusBadge(s) {
            const colors = {draft:'zinc',approved:'blue',ordered:'purple',partial:'amber',received:'emerald',payment_pending:'orange',completed:'emerald',cancelled:'rose'};
            const c = colors[s]||'zinc';
            return `<span class="px-2 py-0.5 rounded-full bg-${c}-500/10 border border-${c}-500/30 text-${c}-400 text-[9px] font-black uppercase">${s.replace('_',' ')}</span>`;
        }

        function renderPOs(orders) {
            const el = document.getElementById('poList');
            if (orders.length===0) { el.innerHTML='<div class="text-center py-12"><div class="text-4xl mb-3">🛒</div><div class="text-zinc-500 text-sm">No purchase orders</div></div>'; return; }
            el.innerHTML = orders.map(o => `
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-2xl p-4 hover:border-amber-500/30 transition-all">
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-black text-amber-400">${o.po_number}</span>
                            ${statusBadge(o.status)}
                        </div>
                        <span class="text-lg font-black text-white">Rs.${parseFloat(o.total_amount).toLocaleString()}</span>
                    </div>
                    <div class="grid grid-cols-4 gap-4 text-[10px] text-zinc-400 mb-3">
                        <div>🏭 ${o.supplier_name||'—'}</div>
                        <div>📦 ${o.item_count} items</div>
                        <div>📅 ${o.order_date||'—'}</div>
                        <div>⏰ ETA: ${o.expected_date||'—'}</div>
                    </div>
                    <div class="flex gap-1.5">
                        ${o.status==='draft' ? `<button onclick="updatePOStatus(${o.id},'approved')" class="h-7 px-3 rounded-lg bg-blue-500/10 border border-blue-500/30 text-blue-400 text-[10px] font-bold">✅ Approve</button>` : ''}
                        ${o.status==='approved' ? `<button onclick="updatePOStatus(${o.id},'ordered')" class="h-7 px-3 rounded-lg bg-purple-500/10 border border-purple-500/30 text-purple-400 text-[10px] font-bold">📤 Mark Ordered</button>` : ''}
                        ${['ordered','partial'].includes(o.status) ? `<a href="goods-receiving.php?po_id=${o.id}" class="h-7 px-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-bold flex items-center">📥 Receive</a>` : ''}
                        ${o.status==='received' ? `<button onclick="updatePOStatus(${o.id},'payment_pending')" class="h-7 px-3 rounded-lg bg-orange-500/10 border border-orange-500/30 text-orange-400 text-[10px] font-bold">💳 Payment Pending</button>` : ''}
                        ${['received','payment_pending'].includes(o.status) ? `<button onclick="updatePOStatus(${o.id},'completed')" class="h-7 px-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-bold">🏁 Complete</button>` : ''}
                        ${o.status!=='cancelled'&&o.status!=='completed' ? `<button onclick="updatePOStatus(${o.id},'cancelled')" class="h-7 px-3 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-400 text-[10px] font-bold">❌ Cancel</button>` : ''}
                    </div>
                </div>
            `).join('');
        }

        async function updatePOStatus(id, status) {
            if (!confirm(`Set status to "${status}"?`)) return;
            const fd = new FormData(); fd.append('action','update_po_status'); fd.append('id',id); fd.append('status',status); fd.append('csrf_token',CSRF);
            await fetch(API,{method:'POST',body:fd,credentials:'same-origin'}); loadPOs();
        }

        function openPOModal() {
            document.getElementById('poForm').reset(); document.getElementById('poId').value=0;
            document.getElementById('poLines').innerHTML='';
            addPOLine();
            document.getElementById('poModal').classList.remove('hidden'); document.getElementById('poModal').classList.add('flex');
        }
        function closePOModal() { document.getElementById('poModal').classList.add('hidden'); document.getElementById('poModal').classList.remove('flex'); }

        function addPOLine() {
            const div = document.createElement('div');
            div.className = 'grid grid-cols-12 gap-2 items-end';
            div.innerHTML = `
                <select class="col-span-5 h-9 px-2 rounded-lg bg-zinc-950 border border-zinc-800 text-[10px] text-white outline-none po-item-select">
                    <option value="">Select item</option>
                    ${invItems.map(i=>`<option value="${i.id}" data-cost="${i.purchase_cost}">${i.name}</option>`).join('')}
                </select>
                <input type="number" step="0.001" min="0.001" placeholder="Qty" class="col-span-2 h-9 px-2 rounded-lg bg-zinc-950 border border-zinc-800 text-[10px] text-white outline-none po-qty">
                <input type="number" step="0.01" placeholder="Cost" class="col-span-2 h-9 px-2 rounded-lg bg-zinc-950 border border-zinc-800 text-[10px] text-white outline-none po-cost">
                <div class="col-span-2 h-9 flex items-center text-[10px] font-bold text-zinc-300 po-line-total">Rs.0</div>
                <button type="button" onclick="this.closest('.grid').remove();calcTotal()" class="col-span-1 h-9 rounded-lg bg-zinc-950 border border-zinc-800 text-rose-400 text-xs">✕</button>
            `;
            div.querySelector('.po-item-select').addEventListener('change', function() {
                const cost = this.selectedOptions[0]?.dataset.cost || 0;
                div.querySelector('.po-cost').value = cost;
                calcTotal();
            });
            div.querySelector('.po-qty').addEventListener('input', calcTotal);
            div.querySelector('.po-cost').addEventListener('input', calcTotal);
            document.getElementById('poLines').appendChild(div);
        }

        function calcTotal() {
            let sub = 0;
            document.querySelectorAll('#poLines > div').forEach(row => {
                const qty = parseFloat(row.querySelector('.po-qty')?.value||0);
                const cost = parseFloat(row.querySelector('.po-cost')?.value||0);
                const t = qty * cost;
                sub += t;
                row.querySelector('.po-line-total').textContent = 'Rs.' + t.toFixed(0);
            });
            const tax = parseFloat(document.getElementById('poTax').value||0);
            const disc = parseFloat(document.getElementById('poDiscount').value||0);
            document.getElementById('poTotal').textContent = 'Rs.' + (sub+tax-disc).toLocaleString();
        }
        document.getElementById('poTax').addEventListener('input', calcTotal);
        document.getElementById('poDiscount').addEventListener('input', calcTotal);

        async function savePO(e) {
            e.preventDefault();
            const lines = [];
            document.querySelectorAll('#poLines > div').forEach(row => {
                const item_id = row.querySelector('.po-item-select').value;
                const qty = row.querySelector('.po-qty').value;
                const cost = row.querySelector('.po-cost').value;
                if (item_id && qty > 0) lines.push({inventory_item_id:item_id, quantity:qty, unit_cost:cost});
            });
            if (lines.length===0) { alert('Add at least one item'); return; }
            const fd = new FormData(document.getElementById('poForm'));
            fd.append('items', JSON.stringify(lines));
            const r = await fetch(API,{method:'POST',body:fd,credentials:'same-origin'});
            const j = await r.json();
            if (j.success) { closePOModal(); loadPOs(); } else { alert(j.message); }
        }

        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>
