<?php
// admin/assets.php - Asset Register & Lifecycle Management
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Asset Register';
$currentPage = 'assets';
$conn = getDBConnection();
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">🏗️ Asset Register</h1>
                    <p class="text-xs text-zinc-400">Manage capital assets, condition tracking, equipment lifecycle & locations</p>
                </div>
                <div class="flex items-center gap-2">
                    <input type="text" id="searchBox" placeholder="🔍 Search assets..." class="h-10 px-4 w-48 rounded-2xl bg-zinc-900 border border-zinc-800 text-xs text-white placeholder:text-zinc-500 focus:border-amber-500/50 outline-none">
                    <select id="filterCategory" onchange="loadAssets()" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-xs text-white outline-none">
                        <option value="0">All Categories</option>
                    </select>
                    <select id="filterStatus" onchange="loadAssets()" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-xs text-white outline-none">
                        <option value="">All Statuses</option>
                        <option value="available">Available</option>
                        <option value="in_use">In Use</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="repair">Repair</option>
                        <option value="retired">Retired</option>
                        <option value="disposed">Disposed</option>
                        <option value="lost">Lost</option>
                    </select>
                    <button onclick="openAssetModal()" class="h-10 px-4 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">+ Add Asset</button>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 pb-12">
            <div id="assetsGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <div class="col-span-full text-center py-12 text-zinc-500 text-sm">Loading asset register...</div>
            </div>
        </main>
    </div>

    <!-- ASSET MODAL -->
    <div id="assetModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-2xl max-h-[90vh] overflow-y-auto no-scrollbar shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="modalTitle" class="text-sm font-black text-white">Register New Asset</h2>
                <button onclick="closeAssetModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="assetForm" class="p-5 space-y-4" onsubmit="saveAsset(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="assetId" value="0">
                <input type="hidden" name="action" value="save_asset">

                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2 sm:col-span-1">
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Asset Name *</label>
                        <input type="text" name="name" id="fName" required placeholder="e.g. Espresso Machine 2-Group" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                    </div>
                    <div class="col-span-2 sm:col-span-1">
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Asset Code / Tag ID</label>
                        <input type="text" name="asset_code" id="fCode" placeholder="Auto-generated if empty" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Category</label>
                        <select name="category_id" id="fCategory" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select>
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Brand</label>
                        <input type="text" name="brand" id="fBrand" placeholder="e.g. La Marzocco" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Model</label>
                        <input type="text" name="model" id="fModel" placeholder="e.g. Linea PB" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Serial Number</label>
                        <input type="text" name="serial_number" id="fSerial" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Purchase Date</label>
                        <input type="date" name="purchase_date" id="fPurchaseDate" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Purchase Cost (Rs.)</label>
                        <input type="number" step="0.01" name="purchase_cost" id="fCost" placeholder="0.00" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Supplier</label>
                        <select name="supplier_id" id="fSupplier" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select>
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Warranty Expiry</label>
                        <input type="date" name="warranty_expiry" id="fWarranty" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Assigned Branch</label>
                        <input type="text" name="assigned_branch" id="fBranch" value="Main Branch" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Assigned Location</label>
                        <input type="text" name="assigned_location" id="fLocation" placeholder="e.g. Main Kitchen / Bar Area" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Assigned Employee</label>
                        <input type="text" name="assigned_employee" id="fEmployee" placeholder="e.g. Head Barista" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-4 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Condition</label>
                        <select name="condition" id="fCondition" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                            <option value="excellent">Excellent</option>
                            <option value="good" selected>Good</option>
                            <option value="fair">Fair</option>
                            <option value="poor">Poor</option>
                            <option value="damaged">Damaged</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Status</label>
                        <select name="asset_status" id="fStatus" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                            <option value="available">Available</option>
                            <option value="in_use">In Use</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="repair">Repair</option>
                            <option value="retired">Retired</option>
                            <option value="disposed">Disposed</option>
                            <option value="lost">Lost</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Useful Life (Months)</label>
                        <input type="number" name="useful_life_months" id="fUsefulLife" value="60" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Residual Value (Rs.)</label>
                        <input type="number" step="0.01" name="residual_value" id="fResidual" value="0.00" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Notes / Description</label>
                    <textarea name="notes" id="fNotes" rows="2" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none"></textarea>
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Save Asset Record</button>
            </form>
        </div>
    </div>

    <!-- QR MODAL -->
    <div id="qrModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-sm p-6 text-center space-y-4 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 id="qrModalTitle" class="text-sm font-black text-white">Asset Label & QR Tag</h3>
                <button onclick="closeQRModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <div class="bg-white p-4 rounded-2xl inline-block shadow-inner">
                <img id="qrModalImg" src="" alt="Asset QR Code" class="w-44 h-44 mx-auto">
            </div>
            <div class="space-y-1">
                <div id="qrTokenText" class="text-[11px] text-amber-400 font-mono font-bold"></div>
                <div id="assetCodeText" class="text-xs text-zinc-300 font-bold"></div>
            </div>
            <button onclick="window.print()" class="w-full h-10 rounded-xl bg-zinc-800 text-white font-bold text-xs hover:bg-zinc-700">🖨️ Print Asset Tag</button>
        </div>
    </div>

    <script>
        const API = '../api/assets.php';
        const INV_API = '../api/inventory.php';
        const CSRF = '<?php echo $csrfToken; ?>';
        let assetCategories = [], suppliers = [];

        async function loadDropdowns() {
            const [cRes, sRes] = await Promise.all([
                fetch(API + '?action=list_asset_categories').then(r=>r.json()),
                fetch(INV_API + '?action=list_suppliers').then(r=>r.json())
            ]);
            assetCategories = cRes.categories || [];
            suppliers = sRes.suppliers || [];

            const fCat = document.getElementById('fCategory');
            const fSup = document.getElementById('fSupplier');
            const filterCat = document.getElementById('filterCategory');

            fCat.innerHTML = '<option value="0">— Select —</option>' + assetCategories.map(c=>`<option value="${c.id}">${c.icon} ${c.name}</option>`).join('');
            fSup.innerHTML = '<option value="0">— None —</option>' + suppliers.map(s=>`<option value="${s.id}">${s.company_name}</option>`).join('');
            filterCat.innerHTML = '<option value="0">All Categories</option>' + assetCategories.map(c=>`<option value="${c.id}">${c.icon} ${c.name}</option>`).join('');
        }

        async function loadAssets() {
            const search = document.getElementById('searchBox').value;
            const cat = document.getElementById('filterCategory').value;
            const status = document.getElementById('filterStatus').value;

            const r = await fetch(`${API}?action=list_assets&search=${encodeURIComponent(search)}&category_id=${cat}&status=${status}`);
            const j = await r.json();
            renderAssets(j.assets || []);
        }

        function renderAssets(assets) {
            const grid = document.getElementById('assetsGrid');
            if (assets.length === 0) {
                grid.innerHTML = '<div class="col-span-full text-center py-12"><div class="text-4xl mb-3">🏗️</div><div class="text-zinc-500 text-sm">No assets found</div><button onclick="openAssetModal()" class="mt-3 px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-bold text-xs">+ Register First Asset</button></div>';
                return;
            }

            grid.innerHTML = assets.map(a => {
                const cost = parseFloat(a.purchase_cost||0);
                const bookVal = parseFloat(a.current_value||cost);
                const status = a.status || 'available';
                const statusColors = {available:'emerald', in_use:'blue', maintenance:'amber', repair:'orange', retired:'zinc', disposed:'rose', lost:'red'};
                const sc = statusColors[status] || 'zinc';

                return `
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-3 hover:border-amber-500/30 transition-all cursor-pointer shadow-lg" onclick="editAsset(${a.id})">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-[10px] text-amber-400 font-mono font-bold">${a.asset_code}</span>
                                <div class="text-sm font-black text-white leading-tight">${a.name}</div>
                                <div class="text-[10px] text-zinc-500 mt-0.5">${a.category_icon||'🏗️'} ${a.category_name||'Uncategorized'} ${a.serial_number ? '· S/N: '+a.serial_number : ''}</div>
                            </div>
                            <span class="px-2 py-0.5 rounded-full bg-${sc}-500/10 border border-${sc}-500/30 text-${sc}-400 text-[9px] font-black uppercase">${status.replace('_',' ')}</span>
                        </div>

                        <div class="bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800/80 space-y-1 text-xs">
                            <div class="flex justify-between text-zinc-400">
                                <span>Purchase Cost:</span>
                                <span class="font-bold text-white">Rs.${cost.toLocaleString()}</span>
                            </div>
                            <div class="flex justify-between text-zinc-400">
                                <span>Net Book Value:</span>
                                <span class="font-black text-amber-400">Rs.${bookVal.toLocaleString()}</span>
                            </div>
                            <div class="flex justify-between text-[10px] text-zinc-500 pt-1 border-t border-zinc-800">
                                <span>📍 ${a.assigned_location||'Unassigned'}</span>
                                <span>Condition: <strong class="text-zinc-300">${a.condition||'good'}</strong></span>
                            </div>
                        </div>

                        <div class="flex gap-1.5 pt-1">
                            <button onclick="event.stopPropagation();showQRModal('${a.qr_token||''}','${a.asset_code}','${a.name.replace(/'/g,"\\'")}')" class="h-8 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-[10px] font-bold text-amber-400 hover:border-amber-500/40">📱 Tag QR</button>
                            <button onclick="event.stopPropagation();deleteAsset(${a.id})" class="h-8 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-[10px] font-bold text-rose-400 hover:border-rose-500/40">🗑️ Dispose</button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function openAssetModal(id=0) {
            document.getElementById('modalTitle').textContent = id ? 'Edit Asset Record' : 'Register New Asset';
            document.getElementById('assetId').value = id;
            document.getElementById('assetForm').reset();
            document.getElementById('assetModal').classList.remove('hidden');
            document.getElementById('assetModal').classList.add('flex');
        }

        function closeAssetModal() {
            document.getElementById('assetModal').classList.add('hidden');
            document.getElementById('assetModal').classList.remove('flex');
        }

        async function editAsset(id) {
            const r = await fetch(`${API}?action=get_asset&id=${id}`);
            const j = await r.json();
            if (!j.success || !j.asset) return;
            const a = j.asset;
            openAssetModal(id);

            document.getElementById('fName').value = a.name;
            document.getElementById('fCode').value = a.asset_code || '';
            document.getElementById('fCategory').value = a.category_id || 0;
            document.getElementById('fBrand').value = a.brand || '';
            document.getElementById('fModel').value = a.model || '';
            document.getElementById('fSerial').value = a.serial_number || '';
            document.getElementById('fPurchaseDate').value = a.purchase_date || '';
            document.getElementById('fCost').value = a.purchase_cost || 0;
            document.getElementById('fSupplier').value = a.supplier_id || 0;
            document.getElementById('fWarranty').value = a.warranty_expiry || '';
            document.getElementById('fBranch').value = a.assigned_branch || 'Main Branch';
            document.getElementById('fLocation').value = a.assigned_location || '';
            document.getElementById('fEmployee').value = a.assigned_employee || '';
            document.getElementById('fCondition').value = a.condition || 'good';
            document.getElementById('fStatus').value = a.status || 'available';
            document.getElementById('fUsefulLife').value = a.useful_life_months || 60;
            document.getElementById('fResidual').value = a.residual_value || 0;
            document.getElementById('fNotes').value = a.notes || '';
        }

        async function saveAsset(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('assetForm'));
            const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            const j = await r.json();
            if (j.success) {
                closeAssetModal();
                loadAssets();
            } else {
                alert(j.message);
            }
        }

        async function deleteAsset(id) {
            if (!confirm('Mark this capital asset as disposed?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_asset');
            fd.append('id', id);
            fd.append('csrf_token', CSRF);
            await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            loadAssets();
        }

        function showQRModal(qrToken, code, name) {
            document.getElementById('qrModalTitle').textContent = name;
            document.getElementById('qrTokenText').textContent = 'QR Token: ' + qrToken;
            document.getElementById('assetCodeText').textContent = 'Asset Code: ' + code;
            const qrDataUrl = 'data:image/svg+xml;base64,' + btoa(`
                <svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
                    <rect width="200" height="200" fill="white"/>
                    <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="monospace" font-size="10" fill="black">${(qrToken || code).substring(0, 30)}</text>
                </svg>
            `);
            document.getElementById('qrModalImg').src = qrDataUrl;
            document.getElementById('qrModal').classList.remove('hidden');
            document.getElementById('qrModal').classList.add('flex');
        }

        function closeQRModal() {
            document.getElementById('qrModal').classList.add('hidden');
            document.getElementById('qrModal').classList.remove('flex');
        }

        document.getElementById('searchBox').addEventListener('input', debounce(loadAssets, 300));

        function debounce(fn, ms) { let t; return (...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);} }

        document.addEventListener('DOMContentLoaded', async () => {
            await loadDropdowns();
            loadAssets();
        });
    </script>
</body>
</html>
