<?php
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Waste Management';
$currentPage = 'waste';
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>
    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div><h1 class="text-lg font-black text-white">🗑️ Waste Management</h1><p class="text-xs text-zinc-400">Track kitchen waste, spoilage, and expired items</p></div>
                <button onclick="openModal()" class="h-10 px-4 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">+ Log Waste</button>
            </div>
        </header>
        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 pb-8">
            <div id="wasteList" class="space-y-2">
                <div class="text-center py-12 text-zinc-500 text-sm">Loading waste log...</div>
            </div>
        </main>
    </div>

    <div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 class="text-sm font-black text-white">🗑️ Log Waste</h2>
                <button onclick="closeModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="wForm" class="p-5 space-y-3" onsubmit="save(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_waste">
                <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Inventory Item *</label>
                    <select name="inventory_item_id" id="wItem" required class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Quantity *</label>
                        <input type="number" step="0.001" name="quantity" required min="0.001" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Reason</label>
                        <select name="reason" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                            <option value="kitchen_waste">Kitchen Waste</option><option value="expired">Expired</option>
                            <option value="customer_return">Customer Return</option><option value="damaged">Damaged</option>
                            <option value="spoilage">Spoilage</option><option value="other">Other</option>
                        </select></div>
                </div>
                <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none focus:border-amber-500/50"></textarea></div>
                <button type="submit" class="w-full h-11 rounded-2xl bg-rose-500 text-white font-black text-xs">🗑️ Record Waste</button>
            </form>
        </div>
    </div>

    <script>
        const API = '../api/inventory.php';
        const CSRF = '<?php echo $csrfToken; ?>';

        async function init() {
            const r = await fetch(API+'?action=list_items&status=active');
            const j = await r.json();
            document.getElementById('wItem').innerHTML = '<option value="">— Select Item —</option>' + (j.items||[]).map(i=>`<option value="${i.id}">${i.name} (${parseFloat(i.current_stock).toFixed(1)} ${i.unit_abbr||'pcs'})</option>`).join('');
            loadWaste();
        }

        async function loadWaste() {
            const r = await fetch(API+'?action=list_waste');
            const j = await r.json();
            const el = document.getElementById('wasteList');
            const items = j.waste||[];
            if (items.length===0) { el.innerHTML='<div class="text-center py-12"><div class="text-4xl mb-3">✅</div><div class="text-zinc-500 text-sm">No waste recorded</div></div>'; return; }
            el.innerHTML = items.map(w => {
                const reasonLabels = {kitchen_waste:'🔥 Kitchen',expired:'☠️ Expired',customer_return:'↩️ Return',damaged:'💔 Damaged',spoilage:'🦠 Spoilage',other:'📋 Other'};
                const appStatus = w.approval_status || 'pending';
                const appColor = appStatus === 'approved' ? 'emerald' : appStatus === 'rejected' ? 'rose' : 'amber';
                return `<div class="bg-zinc-900/90 border border-zinc-800 rounded-2xl p-3 flex items-center justify-between hover:border-zinc-700 transition-all">
                    <div class="flex items-center gap-3">
                        <span class="text-xl">🗑️</span>
                        <div><div class="text-xs font-bold text-white">${w.item_name}</div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="px-1.5 py-0.5 rounded-md bg-rose-500/10 text-rose-400 text-[9px] font-bold">${reasonLabels[w.reason]||w.reason}</span>
                            <span class="px-1.5 py-0.5 rounded-md bg-${appColor}-500/10 text-${appColor}-400 text-[9px] font-bold uppercase">${appStatus}</span>
                            <span class="text-[10px] text-zinc-500">${new Date(w.created_at).toLocaleString()}</span>
                            ${w.notes ? `<span class="text-[10px] text-zinc-600">· ${w.notes}</span>` : ''}
                        </div></div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <div class="text-sm font-black text-rose-400">-${parseFloat(w.quantity).toFixed(2)} ${w.unit_abbr}</div>
                            <div class="text-[10px] text-zinc-500">Rs.${parseFloat(w.total_cost).toFixed(0)} lost</div>
                        </div>
                        ${appStatus === 'pending' ? `
                            <div class="flex gap-1">
                                <button onclick="approveWaste(${w.id}, 1)" class="px-2 py-1 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-bold rounded-lg hover:bg-emerald-500/20">Approve</button>
                                <button onclick="approveWaste(${w.id}, 0)" class="px-2 py-1 bg-rose-500/10 border border-rose-500/30 text-rose-400 text-[10px] font-bold rounded-lg hover:bg-rose-500/20">Reject</button>
                            </div>
                        ` : ''}
                    </div>
                </div>`;
            }).join('');
        }

        async function approveWaste(id, approve) {
            const fd = new FormData();
            fd.append('action', 'approve_waste');
            fd.append('id', id);
            fd.append('approve', approve ? '1' : '0');
            fd.append('csrf_token', CSRF);
            await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            loadWaste();
        }

        function openModal() { document.getElementById('wForm').reset(); document.getElementById('modal').classList.remove('hidden'); document.getElementById('modal').classList.add('flex'); }
        function closeModal() { document.getElementById('modal').classList.add('hidden'); document.getElementById('modal').classList.remove('flex'); }

        async function save(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('wForm'));
            const r = await fetch(API,{method:'POST',body:fd,credentials:'same-origin'});
            const j = await r.json();
            if (j.success) { closeModal(); loadWaste(); init(); } else { alert(j.message); }
        }

        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>
