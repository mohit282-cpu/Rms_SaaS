<?php
// admin/asset-transfers.php - Asset Transfers & Custody Log
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Asset Transfers';
$currentPage = 'asset-transfers';
$conn = getDBConnection();
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">🚚 Asset Transfers & Custody</h1>
                    <p class="text-xs text-zinc-400">Track equipment location movements & employee assignment changes</p>
                </div>
                <button onclick="openTransferModal()" class="h-10 px-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">
                    + Record Asset Transfer
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 pb-12">
            <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                    <h2 class="text-sm font-black text-white">📜 Transfer Audit History</h2>
                    <span class="text-xs text-zinc-500">Location & Employee Assignment Logs</span>
                </div>
                <div id="transferList" class="space-y-2">
                    <div class="text-center py-12 text-zinc-500 text-sm">Loading transfer history...</div>
                </div>
            </div>
        </main>
    </div>

    <!-- TRANSFER MODAL -->
    <div id="transferModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 class="text-sm font-black text-white">🚚 Record Asset Transfer</h2>
                <button onclick="closeTransferModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="transferForm" class="p-5 space-y-4" onsubmit="saveTransfer(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_transfer">

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Select Asset *</label>
                    <select name="asset_id" id="fAsset" required onchange="onAssetSelect()" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select>
                </div>

                <div class="grid grid-cols-2 gap-3 bg-zinc-950 p-3 rounded-2xl border border-zinc-800/80">
                    <div>
                        <label class="text-[10px] text-zinc-500 font-semibold block mb-1">Current Location</label>
                        <input type="text" name="from_location" id="fFromLoc" readonly class="w-full h-8 px-2.5 rounded-lg bg-zinc-900 border border-zinc-800 text-xs text-zinc-400 outline-none">
                    </div>
                    <div>
                        <label class="text-[10px] text-zinc-500 font-semibold block mb-1">Current Employee</label>
                        <input type="text" name="from_employee" id="fFromEmp" readonly class="w-full h-8 px-2.5 rounded-lg bg-zinc-900 border border-zinc-800 text-xs text-zinc-400 outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">New Location *</label>
                        <input type="text" name="to_location" id="fToLoc" required placeholder="e.g. 2nd Floor Dining / Bar" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">New Employee Custodian</label>
                        <input type="text" name="to_employee" id="fToEmp" placeholder="e.g. Chef / Manager" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                    </div>
                </div>

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Transfer Date</label>
                    <input type="date" name="transfer_date" id="fTransferDate" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                </div>

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Reason for Transfer</label>
                    <textarea name="reason" placeholder="e.g. Relocated for kitchen expansion" rows="2" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none focus:border-amber-500/50"></textarea>
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Post Asset Transfer</button>
            </form>
        </div>
    </div>

    <script>
        const API = '../api/assets.php';
        const CSRF = '<?php echo $csrfToken; ?>';
        let assetList = [];

        async function init() {
            const r = await fetch(API + '?action=list_assets');
            const j = await r.json();
            assetList = j.assets || [];

            document.getElementById('fAsset').innerHTML = '<option value="">— Select Asset —</option>' + assetList.map(a => `
                <option value="${a.id}" data-loc="${a.assigned_location||''}" data-emp="${a.assigned_employee||''}">${a.name} (${a.asset_code})</option>
            `).join('');

            loadTransfers();
        }

        function onAssetSelect() {
            const sel = document.getElementById('fAsset');
            const opt = sel.selectedOptions[0];
            document.getElementById('fFromLoc').value = opt?.dataset.loc || 'Unassigned';
            document.getElementById('fFromEmp').value = opt?.dataset.emp || 'Unassigned';
        }

        async function loadTransfers() {
            const r = await fetch(API + '?action=list_transfers');
            const j = await r.json();
            const list = document.getElementById('transferList');
            const items = j.transfers || [];

            if (items.length === 0) {
                list.innerHTML = '<div class="text-center py-12 text-zinc-500 text-sm">No asset transfers recorded yet</div>';
                return;
            }

            list.innerHTML = items.map(t => `
                <div class="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-3 flex items-center justify-between hover:border-amber-500/30 transition-all">
                    <div class="flex items-center gap-3">
                        <span class="text-xl">🚚</span>
                        <div>
                            <div class="text-xs font-bold text-white">${t.asset_name} <span class="text-amber-400 font-mono">(${t.asset_code})</span></div>
                            <div class="text-[10px] text-zinc-400 mt-0.5">
                                Location: <span class="text-zinc-500">${t.from_location||'?'}</span> → <strong class="text-amber-400">${t.to_location||'?'}</strong>
                                ${t.to_employee ? `· Custodian: <strong class="text-zinc-300">${t.to_employee}</strong>` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs font-bold text-zinc-300">📅 ${t.transfer_date}</div>
                        <div class="text-[10px] text-zinc-500">By: ${t.transferred_by||'admin'}</div>
                    </div>
                </div>
            `).join('');
        }

        function openTransferModal() {
            document.getElementById('transferForm').reset();
            document.getElementById('fTransferDate').valueAsDate = new Date();
            document.getElementById('fFromLoc').value = '—';
            document.getElementById('fFromEmp').value = '—';
            document.getElementById('transferModal').classList.remove('hidden');
            document.getElementById('transferModal').classList.add('flex');
        }

        function closeTransferModal() {
            document.getElementById('transferModal').classList.add('hidden');
            document.getElementById('transferModal').classList.remove('flex');
        }

        async function saveTransfer(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('transferForm'));
            const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            const j = await r.json();
            if (j.success) {
                closeTransferModal();
                init();
            } else {
                alert(j.message);
            }
        }

        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>
