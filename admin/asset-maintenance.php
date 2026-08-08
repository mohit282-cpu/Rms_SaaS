<?php
// admin/asset-maintenance.php - Maintenance Management & Work Orders
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Asset Maintenance';
$currentPage = 'asset-maintenance';
$conn = getDBConnection();
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">🔧 Asset Maintenance & Servicing</h1>
                    <p class="text-xs text-zinc-400">Schedule preventive maintenance, track work order costs & technician logs</p>
                </div>
                <button onclick="openMaintModal()" class="h-10 px-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">
                    + Schedule Maintenance
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 pb-12">
            <div id="maintList" class="space-y-3">
                <div class="text-center py-12 text-zinc-500 text-sm">Loading maintenance records...</div>
            </div>
        </main>
    </div>

    <!-- MAINTENANCE WORK ORDER MODAL -->
    <div id="maintModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-lg shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="modalTitle" class="text-sm font-black text-white">Schedule Maintenance</h2>
                <button onclick="closeMaintModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="maintForm" class="p-5 space-y-4" onsubmit="saveMaintenance(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="maintId" value="0">
                <input type="hidden" name="action" value="save_maintenance">

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Target Asset *</label>
                    <select name="asset_id" id="fAsset" required class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Maintenance Type</label>
                        <select name="type" id="fType" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                            <option value="preventive">Preventive</option>
                            <option value="corrective">Corrective</option>
                            <option value="emergency">Emergency</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Status</label>
                        <select name="maint_status" id="fStatus" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                            <option value="scheduled">Scheduled</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Service Date *</label>
                        <input type="date" name="service_date" id="fServiceDate" required class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Next Service Date</label>
                        <input type="date" name="next_service_date" id="fNextDate" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Technician / Vendor</label>
                        <input type="text" name="technician" id="fTech" placeholder="Technician name or company" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Service Cost (Rs.)</label>
                        <input type="number" step="0.01" name="cost" id="fCost" value="0.00" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Parts Used / Replaced</label>
                    <input type="text" name="parts_used" id="fParts" placeholder="e.g. Heating element, Gaskets" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                </div>

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Description / Work Summary</label>
                    <textarea name="description" id="fDesc" rows="2" placeholder="Details of servicing performed" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none"></textarea>
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Save Work Order</button>
            </form>
        </div>
    </div>

    <script>
        const API = '../api/assets.php';
        const CSRF = '<?php echo $csrfToken; ?>';
        let assetsList = [];

        async function init() {
            const r = await fetch(API + '?action=list_assets');
            const j = await r.json();
            assetsList = j.assets || [];

            document.getElementById('fAsset').innerHTML = '<option value="">— Select Asset —</option>' + assetsList.map(a => `
                <option value="${a.id}">${a.name} (${a.asset_code})</option>
            `).join('');

            loadMaintenance();
        }

        async function loadMaintenance() {
            const r = await fetch(API + '?action=list_maintenance');
            const j = await r.json();
            const list = document.getElementById('maintList');
            const items = j.maintenance || [];

            if (items.length === 0) {
                list.innerHTML = '<div class="text-center py-12 text-zinc-500 text-sm">No maintenance records logged</div>';
                return;
            }

            list.innerHTML = items.map(m => {
                const statusColors = {scheduled:'blue', in_progress:'amber', completed:'emerald', cancelled:'rose'};
                const sc = statusColors[m.status] || 'zinc';
                const typeIcons = {preventive:'🛡️', corrective:'🔧', emergency:'🚨'};

                return `
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 hover:border-amber-500/30 transition-all space-y-2 shadow-lg">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="text-xl">${typeIcons[m.type]||'🔧'}</span>
                                <div>
                                    <h3 class="text-sm font-black text-white">${m.asset_name} <span class="text-amber-400 font-mono text-xs">(${m.asset_code})</span></h3>
                                    <div class="text-[10px] text-zinc-500">Tech: <strong class="text-zinc-300">${m.technician||'Unassigned'}</strong> · Date: ${m.service_date}</div>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="px-2.5 py-0.5 rounded-full bg-${sc}-500/10 border border-${sc}-500/30 text-${sc}-400 text-[10px] font-black uppercase">${m.status.replace('_',' ')}</span>
                                <div class="text-sm font-black text-white mt-1">Rs.${parseFloat(m.cost).toLocaleString()}</div>
                            </div>
                        </div>
                        ${m.parts_used ? `<div class="text-xs text-zinc-400 bg-zinc-950 p-2 rounded-xl border border-zinc-800">Parts Used: ${m.parts_used}</div>` : ''}
                        ${m.description ? `<p class="text-xs text-zinc-400">${m.description}</p>` : ''}
                        <div class="flex justify-between items-center text-[10px] text-zinc-500 pt-1 border-t border-zinc-800/80">
                            <span>Next Service Due: <strong class="text-amber-400">${m.next_service_date||'Not scheduled'}</strong></span>
                            <button onclick="editMaint(${m.id})" class="text-amber-400 font-bold hover:underline">Edit Work Order →</button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function openMaintModal(id=0) {
            document.getElementById('modalTitle').textContent = id ? 'Edit Work Order' : 'Schedule Maintenance';
            document.getElementById('maintId').value = id;
            document.getElementById('maintForm').reset();
            document.getElementById('fServiceDate').valueAsDate = new Date();
            document.getElementById('maintModal').classList.remove('hidden');
            document.getElementById('maintModal').classList.add('flex');
        }

        function closeMaintModal() {
            document.getElementById('maintModal').classList.add('hidden');
            document.getElementById('maintModal').classList.remove('flex');
        }

        async function editMaint(id) {
            const r = await fetch(API + '?action=list_maintenance');
            const j = await r.json();
            const m = (j.maintenance||[]).find(x => x.id == id);
            if (!m) return;
            openMaintModal(id);

            document.getElementById('fAsset').value = m.asset_id;
            document.getElementById('fType').value = m.type || 'preventive';
            document.getElementById('fStatus').value = m.status || 'scheduled';
            document.getElementById('fServiceDate').value = m.service_date || '';
            document.getElementById('fNextDate').value = m.next_service_date || '';
            document.getElementById('fTech').value = m.technician || '';
            document.getElementById('fCost').value = m.cost || 0;
            document.getElementById('fParts').value = m.parts_used || '';
            document.getElementById('fDesc').value = m.description || '';
        }

        async function saveMaintenance(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('maintForm'));
            const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            const j = await r.json();
            if (j.success) {
                closeMaintModal();
                loadMaintenance();
            } else {
                alert(j.message);
            }
        }

        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>
