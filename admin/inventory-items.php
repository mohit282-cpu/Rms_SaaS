<?php
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Stock Items';
$currentPage = 'inventory-items';
$conn = getDBConnection();
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>
    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">📋 Stock Items</h1>
                    <p class="text-xs text-zinc-400">Manage inventory items, stock levels, and adjustments</p>
                </div>
                <div class="flex items-center gap-2">
                    <input type="text" id="searchBox" placeholder="🔍 Search items..." class="h-10 px-4 w-48 rounded-2xl bg-zinc-900 border border-zinc-800 text-xs text-white placeholder:text-zinc-500 focus:border-amber-500/50 outline-none">
                    <select id="filterCategory" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-xs text-white outline-none">
                        <option value="0">All Categories</option>
                    </select>
                    <button onclick="openItemModal()" class="h-10 px-4 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">+ Add Item</button>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 pb-8">
            <div id="itemsGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                <div class="col-span-full text-center py-12 text-zinc-500 text-sm">Loading stock items...</div>
            </div>
        </main>
    </div>

    <!-- ITEM MODAL -->
    <div id="itemModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-lg max-h-[90vh] overflow-y-auto no-scrollbar shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="modalTitle" class="text-sm font-black text-white">Add Stock Item</h2>
                <button onclick="closeItemModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="itemForm" class="p-5 space-y-4" onsubmit="saveItem(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="itemId" value="0">
                <input type="hidden" name="action" value="save_item">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2"><label class="text-[11px] text-zinc-400 font-bold block mb-1">Item Name *</label>
                        <input type="text" name="name" id="fName" required class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Barcode</label>
                        <input type="text" name="barcode" id="fBarcode" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Brand</label>
                        <input type="text" name="brand" id="fBrand" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Category</label>
                        <select name="category_id" id="fCategory" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Unit</label>
                        <select name="unit_id" id="fUnit" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Supplier</label>
                        <select name="supplier_id" id="fSupplier" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Purchase Cost (Rs.)</label>
                        <input type="number" step="0.01" name="purchase_cost" id="fCost" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Min Stock</label>
                        <input type="number" step="0.001" name="minimum_stock" id="fMinStock" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Max Stock</label>
                        <input type="number" step="0.001" name="maximum_stock" id="fMaxStock" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Storage Location</label>
                        <input type="text" name="storage_location" id="fLocation" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Expiry Date</label>
                        <input type="date" name="expiry_date" id="fExpiry" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                    <div class="col-span-2"><label class="text-[11px] text-zinc-400 font-bold block mb-1">Notes</label>
                        <textarea name="notes" id="fNotes" rows="2" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50 resize-none"></textarea></div>
                </div>
                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Save Item</button>
            </form>
        </div>
    </div>

    <!-- ADJUST STOCK MODAL -->
    <div id="adjustModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-sm shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 class="text-sm font-black text-white">📦 Adjust Stock</h2>
                <button onclick="closeAdjustModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="adjustForm" class="p-5 space-y-4" onsubmit="submitAdjust(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="id" id="adjId">
                <input type="hidden" name="type" value="adjustment">
                <div id="adjItemName" class="text-xs font-bold text-amber-400"></div>
                <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Direction</label>
                    <select name="direction" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                        <option value="in">📥 Stock In (+)</option>
                        <option value="out">📤 Stock Out (-)</option>
                    </select></div>
                <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Quantity</label>
                    <input type="number" step="0.001" name="quantity" required min="0.001" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Reason</label>
                    <input type="text" name="notes" placeholder="e.g. Physical count correction" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                <button type="submit" class="w-full h-11 rounded-2xl bg-emerald-500 text-zinc-950 font-black text-xs">✅ Apply Adjustment</button>
            </form>
        </div>
    </div>

    <!-- QR & BARCODE VIEW MODAL -->
    <div id="qrModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-sm p-6 text-center space-y-4 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 id="qrModalTitle" class="text-sm font-black text-white">Item Identification</h3>
                <button onclick="closeQRModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <div class="bg-white p-4 rounded-2xl inline-block shadow-inner">
                <img id="qrModalImg" src="" alt="QR Code" class="w-44 h-44 mx-auto">
            </div>
            <div class="space-y-1">
                <div id="qrTokenText" class="text-[10px] text-amber-400 font-mono font-bold"></div>
                <div id="barcodeText" class="text-xs text-zinc-400 font-bold"></div>
            </div>
            <button onclick="window.print()" class="w-full h-10 rounded-xl bg-zinc-800 text-white font-bold text-xs hover:bg-zinc-700">🖨️ Print Label</button>
        </div>
    </div>

    <script>
        function showQRModal(qrToken, barcode, title) {
            document.getElementById('qrModalTitle').textContent = title;
            document.getElementById('qrTokenText').textContent = qrToken ? 'QR: ' + qrToken : '';
            document.getElementById('barcodeText').textContent = barcode ? 'Barcode: ' + barcode : 'No Barcode';
            const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(qrToken || title);
            document.getElementById('qrModalImg').src = qrUrl;
            document.getElementById('qrModal').classList.remove('hidden');
            document.getElementById('qrModal').classList.add('flex');
        }
        function closeQRModal() {
            document.getElementById('qrModal').classList.add('hidden');
            document.getElementById('qrModal').classList.remove('flex');
        }
        const API = '../api/inventory.php';
        const CSRF = '<?php echo $csrfToken; ?>';
        let categories = [], units = [], suppliers = [];

        async function loadDropdowns() {
            const [cRes, uRes, sRes] = await Promise.all([
                fetch(API + '?action=list_categories').then(r=>r.json()),
                fetch(API + '?action=list_units').then(r=>r.json()),
                fetch(API + '?action=list_suppliers').then(r=>r.json())
            ]);
            categories = cRes.categories || [];
            units = uRes.units || [];
            suppliers = sRes.suppliers || [];

            const filterCat = document.getElementById('filterCategory');
            const fCat = document.getElementById('fCategory');
            const fUnit = document.getElementById('fUnit');
            const fSup = document.getElementById('fSupplier');
            fCat.innerHTML = '<option value="0">— Select —</option>' + categories.map(c=>`<option value="${c.id}">${c.icon} ${c.name}</option>`).join('');
            fUnit.innerHTML = '<option value="0">— Select —</option>' + units.map(u=>`<option value="${u.id}">${u.name} (${u.abbreviation})</option>`).join('');
            fSup.innerHTML = '<option value="0">— None —</option>' + suppliers.map(s=>`<option value="${s.id}">${s.company_name}</option>`).join('');
            filterCat.innerHTML = '<option value="0">All Categories</option>' + categories.map(c=>`<option value="${c.id}">${c.icon} ${c.name}</option>`).join('');
        }

        async function loadItems() {
            const search = document.getElementById('searchBox').value;
            const cat = document.getElementById('filterCategory').value;
            const r = await fetch(`${API}?action=list_items&search=${encodeURIComponent(search)}&category_id=${cat}`);
            const j = await r.json();
            renderItems(j.items || []);
        }

        function renderItems(items) {
            const grid = document.getElementById('itemsGrid');
            if (items.length === 0) {
                grid.innerHTML = '<div class="col-span-full text-center py-12"><div class="text-4xl mb-3">📦</div><div class="text-zinc-500 text-sm">No stock items found</div><button onclick="openItemModal()" class="mt-3 px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-bold text-xs">+ Add First Item</button></div>';
                return;
            }
            grid.innerHTML = items.map(i => {
                const stock = parseFloat(i.current_stock);
                const min = parseFloat(i.minimum_stock);
                const pct = min > 0 ? Math.min(100, Math.round((stock/min)*100)) : (stock > 0 ? 100 : 0);
                const isLow = stock <= min && stock > 0;
                const isOut = stock <= 0;
                const borderColor = isOut ? 'border-red-500/40' : isLow ? 'border-amber-500/40' : 'border-zinc-800';
                const barColor = isOut ? 'bg-red-500' : isLow ? 'bg-amber-500' : 'bg-emerald-500';
                const unit = i.unit_abbr || 'pcs';
                return `<div class="bg-zinc-900/90 border ${borderColor} rounded-2xl p-4 space-y-3 hover:border-amber-500/30 transition-all cursor-pointer" onclick="editItem(${i.id})">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-xs font-black text-white leading-tight">${i.name}</div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">${i.category_icon||'📦'} ${i.category_name||'Uncategorized'} ${i.barcode ? '· '+i.barcode : ''}</div>
                        </div>
                        ${isOut ? '<span class="px-2 py-0.5 rounded-full bg-red-500/10 border border-red-500/30 text-red-400 text-[9px] font-black">OUT</span>' :
                          isLow ? '<span class="px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[9px] font-black">LOW</span>' :
                          '<span class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[9px] font-black">OK</span>'}
                    </div>
                    <div>
                        <div class="flex items-end justify-between mb-1">
                            <span class="text-lg font-black text-white">${stock.toFixed(1)}<span class="text-[10px] text-zinc-500 ml-1">${unit}</span></span>
                            <span class="text-[10px] text-zinc-500">Min: ${min.toFixed(1)}</span>
                        </div>
                        <div class="h-1.5 rounded-full bg-zinc-800 overflow-hidden"><div class="h-full rounded-full ${barColor} transition-all" style="width:${Math.min(pct,100)}%"></div></div>
                    </div>
                    <div class="flex items-center justify-between text-[10px] text-zinc-500">
                        <span>Cost: Rs.${parseFloat(i.purchase_cost).toFixed(0)}</span>
                        <span>${i.supplier_name||'No supplier'}</span>
                    </div>
                    <div class="flex gap-1.5 pt-1">
                        <button onclick="event.stopPropagation();showQRModal('${i.qr_token||''}','${i.barcode||''}','${i.name.replace(/'/g,"\\'")}')" class="h-8 px-2.5 rounded-xl bg-zinc-950 border border-zinc-800 text-[10px] font-bold text-amber-400 hover:border-amber-500/40" title="View QR / Barcode">📱 QR</button>
                        <button onclick="event.stopPropagation();openAdjust(${i.id},'${i.name.replace(/'/g,"\\'")}')" class="flex-1 h-8 rounded-xl bg-zinc-950 border border-zinc-800 text-[10px] font-bold text-zinc-300 hover:border-amber-500/40">📦 Adjust</button>
                        <button onclick="event.stopPropagation();deleteItem(${i.id})" class="h-8 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-[10px] font-bold text-rose-400 hover:border-rose-500/40">🗑️</button>
                    </div>
                </div>`;
            }).join('');
        }

        function openItemModal(id=0) {
            document.getElementById('modalTitle').textContent = id ? 'Edit Stock Item' : 'Add Stock Item';
            document.getElementById('itemId').value = id;
            document.getElementById('itemForm').reset();
            document.getElementById('itemModal').classList.remove('hidden');
            document.getElementById('itemModal').classList.add('flex');
        }

        function closeItemModal() {
            document.getElementById('itemModal').classList.add('hidden');
            document.getElementById('itemModal').classList.remove('flex');
        }

        async function editItem(id) {
            const r = await fetch(`${API}?action=get_item&id=${id}`);
            const j = await r.json();
            if (!j.success || !j.item) return;
            const i = j.item;
            openItemModal(id);
            document.getElementById('fName').value = i.name;
            document.getElementById('fBarcode').value = i.barcode||'';
            document.getElementById('fBrand').value = i.brand||'';
            document.getElementById('fCategory').value = i.category_id||0;
            document.getElementById('fUnit').value = i.unit_id||0;
            document.getElementById('fSupplier').value = i.supplier_id||0;
            document.getElementById('fCost').value = i.purchase_cost||0;
            document.getElementById('fMinStock').value = i.minimum_stock||0;
            document.getElementById('fMaxStock').value = i.maximum_stock||0;
            document.getElementById('fLocation').value = i.storage_location||'';
            document.getElementById('fExpiry').value = i.expiry_date||'';
            document.getElementById('fNotes').value = i.notes||'';
        }

        async function saveItem(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('itemForm'));
            const r = await fetch(API, {method:'POST',body:fd,credentials:'same-origin'});
            const j = await r.json();
            if (j.success) { closeItemModal(); loadItems(); } else { alert(j.message); }
        }

        async function deleteItem(id) {
            if (!confirm('Deactivate this item?')) return;
            const fd = new FormData();
            fd.append('action','delete_item');
            fd.append('id',id);
            fd.append('csrf_token',CSRF);
            await fetch(API,{method:'POST',body:fd,credentials:'same-origin'});
            loadItems();
        }

        function openAdjust(id, name) {
            document.getElementById('adjId').value = id;
            document.getElementById('adjItemName').textContent = name;
            document.getElementById('adjustForm').reset();
            document.getElementById('adjId').value = id;
            document.getElementById('adjustModal').classList.remove('hidden');
            document.getElementById('adjustModal').classList.add('flex');
        }
        function closeAdjustModal() {
            document.getElementById('adjustModal').classList.add('hidden');
            document.getElementById('adjustModal').classList.remove('flex');
        }
        async function submitAdjust(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('adjustForm'));
            const r = await fetch(API,{method:'POST',body:fd,credentials:'same-origin'});
            const j = await r.json();
            if (j.success) { closeAdjustModal(); loadItems(); } else { alert(j.message); }
        }

        document.getElementById('searchBox').addEventListener('input', debounce(loadItems, 300));
        document.getElementById('filterCategory').addEventListener('change', loadItems);

        function debounce(fn, ms) { let t; return (...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);} }

        document.addEventListener('DOMContentLoaded', async ()=>{
            await loadDropdowns();
            loadItems();
        });
    </script>
</body>
</html>
