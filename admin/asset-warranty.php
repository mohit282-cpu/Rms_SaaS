<?php
// admin/asset-warranty.php - Asset Warranty Tracking & Claim Center
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Asset Warranty';
$currentPage = 'asset-warranty';
$conn = getDBConnection();
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">🛡️ Warranty Tracking & Claims</h1>
                    <p class="text-xs text-zinc-400">Monitor manufacturer warranties, claim statuses, policies & expiry alerts</p>
                </div>
                <button onclick="openWarrantyModal()" class="h-10 px-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">
                    + Add Warranty Record
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 pb-12">
            <div id="warrantyList" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="col-span-full text-center py-12 text-zinc-500 text-sm">Loading warranty records...</div>
            </div>
        </main>
    </div>

    <!-- WARRANTY MODAL -->
    <div id="warrantyModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="modalTitle" class="text-sm font-black text-white">Add Warranty Policy</h2>
                <button onclick="closeWarrantyModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="warrantyForm" class="p-5 space-y-4" onsubmit="saveWarranty(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="wId" value="0">
                <input type="hidden" name="action" value="save_warranty">

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Target Asset *</label>
                    <select name="asset_id" id="fAsset" required class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Provider Name</label>
                        <input type="text" name="provider_name" id="fProvider" placeholder="e.g. Samsung Support" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Policy / Contract #</label>
                        <input type="text" name="policy_number" id="fPolicy" placeholder="e.g. WARR-99482" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Start Date</label>
                        <input type="date" name="start_date" id="fStartDate" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Expiry Date *</label>
                        <input type="date" name="expiry_date" id="fExpiryDate" required class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Claim Status</label>
                    <select name="claim_status" id="fStatus" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                        <option value="active">Active Policy</option>
                        <option value="claim_pending">Claim Pending</option>
                        <option value="repaired">Repaired under Warranty</option>
                        <option value="replaced">Unit Replaced</option>
                        <option value="expired">Expired</option>
                    </select>
                </div>

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Coverage Details</label>
                    <textarea name="coverage_details" id="fDetails" rows="2" placeholder="Parts covered, labor inclusions..." class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none"></textarea>
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Save Warranty Policy</button>
            </form>
        </div>
    </div>

    <script>
        const API = '../api/assets.php';
        const CSRF = '<?php echo $csrfToken; ?>';

        async function init() {
            const r = await fetch(API + '?action=list_assets');
            const j = await r.json();
            const assets = j.assets || [];

            document.getElementById('fAsset').innerHTML = '<option value="">— Select Asset —</option>' + assets.map(a => `
                <option value="${a.id}">${a.name} (${a.asset_code})</option>
            `).join('');

            loadWarranties();
        }

        async function loadWarranties() {
            const r = await fetch(API + '?action=list_warranties');
            const j = await r.json();
            const grid = document.getElementById('warrantyList');
            const items = j.warranties || [];

            if (items.length === 0) {
                grid.innerHTML = '<div class="col-span-full text-center py-12 text-zinc-500 text-sm">No warranty policies registered</div>';
                return;
            }

            grid.innerHTML = items.map(w => {
                const isExp = new Date(w.expiry_date) < new Date();
                const statusColor = isExp ? 'rose' : w.claim_status === 'claim_pending' ? 'amber' : 'emerald';

                return `
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-3 shadow-lg hover:border-amber-500/30 transition-all">
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-sm font-black text-white">${w.asset_name}</h3>
                                <div class="text-[10px] text-amber-400 font-mono font-bold">${w.asset_code}</div>
                            </div>
                            <span class="px-2.5 py-0.5 rounded-full bg-${statusColor}-500/10 border border-${statusColor}-500/30 text-${statusColor}-400 text-[10px] font-black uppercase">
                                ${isExp ? 'EXPIRED' : (w.claim_status||'active').replace('_',' ')}
                            </span>
                        </div>

                        <div class="bg-zinc-950 p-3 rounded-2xl border border-zinc-800/80 space-y-1 text-xs">
                            <div class="flex justify-between text-zinc-400">
                                <span>Provider:</span>
                                <span class="font-bold text-white">${w.provider_name||'Vendor'}</span>
                            </div>
                            <div class="flex justify-between text-zinc-400">
                                <span>Policy #:</span>
                                <span class="font-mono text-zinc-300">${w.policy_number||'—'}</span>
                            </div>
                            <div class="flex justify-between text-zinc-400 pt-1 border-t border-zinc-800">
                                <span>Expiry Date:</span>
                                <span class="font-black text-${isExp?'rose':'emerald'}-400">${w.expiry_date}</span>
                            </div>
                        </div>

                        ${w.coverage_details ? `<p class="text-xs text-zinc-400 bg-zinc-950/60 p-2.5 rounded-xl border border-zinc-800/60">${w.coverage_details}</p>` : ''}

                        <button onclick="editWarranty(${w.id})" class="w-full h-8 rounded-xl bg-zinc-950 border border-zinc-800 text-xs font-bold text-zinc-300 hover:border-amber-500/40">✏️ Edit Policy</button>
                    </div>
                `;
            }).join('');
        }

        function openWarrantyModal(id=0) {
            document.getElementById('modalTitle').textContent = id ? 'Edit Warranty Policy' : 'Add Warranty Policy';
            document.getElementById('wId').value = id;
            document.getElementById('warrantyForm').reset();
            document.getElementById('warrantyModal').classList.remove('hidden');
            document.getElementById('warrantyModal').classList.add('flex');
        }

        function closeWarrantyModal() {
            document.getElementById('warrantyModal').classList.add('hidden');
            document.getElementById('warrantyModal').classList.remove('flex');
        }

        async function editWarranty(id) {
            const r = await fetch(API + '?action=list_warranties');
            const j = await r.json();
            const w = (j.warranties||[]).find(x => x.id == id);
            if (!w) return;
            openWarrantyModal(id);

            document.getElementById('fAsset').value = w.asset_id;
            document.getElementById('fProvider').value = w.provider_name || '';
            document.getElementById('fPolicy').value = w.policy_number || '';
            document.getElementById('fStartDate').value = w.start_date || '';
            document.getElementById('fExpiryDate').value = w.expiry_date || '';
            document.getElementById('fStatus').value = w.claim_status || 'active';
            document.getElementById('fDetails').value = w.coverage_details || '';
        }

        async function saveWarranty(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('warrantyForm'));
            const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            const j = await r.json();
            if (j.success) {
                closeWarrantyModal();
                loadWarranties();
            } else {
                alert(j.message);
            }
        }

        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>
