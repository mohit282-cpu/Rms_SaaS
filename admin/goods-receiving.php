<?php
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Goods Receiving';
$currentPage = 'goods-receiving';
$csrfToken = CSRF::generateToken();
$poId = intval($_GET['po_id'] ?? 0);
include 'includes/header.php';
include 'includes/sidebar.php';
?>
    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div><h1 class="text-lg font-black text-white">📥 Goods Receiving</h1><p class="text-xs text-zinc-400">Receive stock against purchase orders</p></div>
                <a href="purchase-orders.php" class="h-10 px-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs flex items-center gap-1.5 hover:border-amber-500/40">← Back to POs</a>
            </div>
        </header>
        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 pb-8">
            <?php if ($poId > 0): ?>
                <div id="poDetails" class="bg-zinc-900/90 border border-amber-500/30 rounded-3xl p-5 mb-6 shadow-xl">
                    <div class="text-sm font-black text-amber-400 mb-2">Loading PO details...</div>
                </div>
                <div id="receiveForm" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                    <h2 class="text-sm font-black text-white">📦 Receive Items</h2>
                    <div id="receiveLines" class="space-y-3"></div>
                    <button onclick="submitReceive()" class="w-full h-11 rounded-2xl bg-emerald-500 text-zinc-950 font-black text-xs shadow-lg shadow-emerald-500/20">✅ Confirm Goods Receipt</button>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <div class="text-4xl mb-3">📥</div>
                    <div class="text-zinc-500 text-sm mb-3">Select a Purchase Order to receive goods</div>
                    <a href="purchase-orders.php" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-bold text-xs">View Purchase Orders</a>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($poId > 0): ?>
    <script>
        const API = '../api/inventory.php';
        const CSRF = '<?php echo $csrfToken; ?>';
        const PO_ID = <?php echo $poId; ?>;

        async function loadPO() {
            const r = await fetch(`${API}?action=get_po&id=${PO_ID}`);
            const j = await r.json();
            if (!j.success) { document.getElementById('poDetails').innerHTML = '<div class="text-rose-400 text-sm font-bold">PO not found</div>'; return; }
            const o = j.order;
            document.getElementById('poDetails').innerHTML = `
                <div class="flex items-center justify-between mb-3">
                    <div class="text-sm font-black text-amber-400">${o.po_number}</div>
                    <span class="text-lg font-black text-white">Rs.${parseFloat(o.total_amount).toLocaleString()}</span>
                </div>
                <div class="grid grid-cols-3 gap-3 text-[10px] text-zinc-400">
                    <div>🏭 ${o.supplier_name}</div>
                    <div>📅 Ordered: ${o.order_date||'—'}</div>
                    <div>⏰ Expected: ${o.expected_date||'—'}</div>
                </div>`;

            const lines = document.getElementById('receiveLines');
            lines.innerHTML = (j.items||[]).map(i => {
                const remaining = Math.max(0, parseFloat(i.quantity) - parseFloat(i.received_qty||0));
                return `<div class="bg-zinc-950 rounded-2xl border border-zinc-800 p-3 space-y-2 receive-line" data-item-id="${i.inventory_item_id}">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-white">${i.item_name}</span>
                        <span class="text-[10px] text-zinc-500">Ordered: ${parseFloat(i.quantity).toFixed(1)} ${i.unit_abbr||'pcs'} · Received: ${parseFloat(i.received_qty||0).toFixed(1)}</span>
                    </div>
                    <div class="grid grid-cols-4 gap-2">
                        <div><label class="text-[9px] text-zinc-500 block">Receive Qty</label>
                            <input type="number" step="0.001" value="${remaining}" max="${remaining}" class="recv-qty w-full h-8 px-2 rounded-lg bg-zinc-900 border border-zinc-800 text-[10px] text-white outline-none"></div>
                        <div><label class="text-[9px] text-zinc-500 block">Rejected</label>
                            <input type="number" step="0.001" value="0" class="recv-rejected w-full h-8 px-2 rounded-lg bg-zinc-900 border border-zinc-800 text-[10px] text-white outline-none"></div>
                        <div><label class="text-[9px] text-zinc-500 block">Damaged</label>
                            <input type="number" step="0.001" value="0" class="recv-damaged w-full h-8 px-2 rounded-lg bg-zinc-900 border border-zinc-800 text-[10px] text-white outline-none"></div>
                        <div><label class="text-[9px] text-zinc-500 block">Unit Cost</label>
                            <input type="number" step="0.01" value="${i.unit_cost}" class="recv-cost w-full h-8 px-2 rounded-lg bg-zinc-900 border border-zinc-800 text-[10px] text-white outline-none"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="text-[9px] text-zinc-500 block">Batch #</label>
                            <input type="text" class="recv-batch w-full h-8 px-2 rounded-lg bg-zinc-900 border border-zinc-800 text-[10px] text-white outline-none"></div>
                        <div><label class="text-[9px] text-zinc-500 block">Expiry</label>
                            <input type="date" class="recv-expiry w-full h-8 px-2 rounded-lg bg-zinc-900 border border-zinc-800 text-[10px] text-white outline-none"></div>
                    </div>
                </div>`;
            }).join('');
        }

        async function submitReceive() {
            const items = [];
            document.querySelectorAll('.receive-line').forEach(el => {
                const qty = parseFloat(el.querySelector('.recv-qty').value||0);
                if (qty <= 0) return;
                items.push({
                    inventory_item_id: el.dataset.itemId,
                    received_qty: qty,
                    rejected_qty: parseFloat(el.querySelector('.recv-rejected').value||0),
                    damaged_qty: parseFloat(el.querySelector('.recv-damaged').value||0),
                    unit_cost: parseFloat(el.querySelector('.recv-cost').value||0),
                    batch_number: el.querySelector('.recv-batch').value,
                    expiry_date: el.querySelector('.recv-expiry').value
                });
            });
            if (items.length===0) { alert('No items to receive'); return; }
            const fd = new FormData();
            fd.append('action','receive_goods'); fd.append('po_id',PO_ID); fd.append('items',JSON.stringify(items)); fd.append('csrf_token',CSRF);
            const r = await fetch(API,{method:'POST',body:fd,credentials:'same-origin'});
            const j = await r.json();
            if (j.success) { alert('Goods received!'); window.location.href='purchase-orders.php'; } else { alert(j.message); }
        }

        document.addEventListener('DOMContentLoaded', loadPO);
    </script>
    <?php endif; ?>
</body>
</html>
