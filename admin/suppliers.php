<?php
// admin/suppliers.php - Supplier Directory & Purchase History
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Suppliers';
$currentPage = 'suppliers';
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">🏭 Supplier Directory</h1>
                    <p class="text-xs text-zinc-400">Vendor profiles, balances, purchase histories, and performance ratings</p>
                </div>
                <button onclick="openModal()" class="h-10 px-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">
                    + Add Supplier
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 pb-12">
            <div id="supplierList" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="col-span-full text-center py-12 text-zinc-500 text-sm">Loading supplier directory...</div>
            </div>
        </main>
    </div>

    <!-- SUPPLIER MODAL -->
    <div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-lg max-h-[90vh] overflow-y-auto no-scrollbar shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="mTitle" class="text-sm font-black text-white">Add Supplier</h2>
                <button onclick="closeModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="sForm" class="p-5 space-y-3" onsubmit="save(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="sId" value="0">
                <input type="hidden" name="action" value="save_supplier">
                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Company Name *</label>
                    <input type="text" name="company_name" id="sCompany" required placeholder="e.g. Fresh Produce Co." class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Contact Person</label>
                        <input type="text" name="contact_person" id="sContact" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Phone</label>
                        <input type="text" name="phone" id="sPhone" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Email</label>
                        <input type="email" name="email" id="sEmail" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">VAT/PAN</label>
                        <input type="text" name="vat_pan" id="sVat" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                    </div>
                </div>
                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Address</label>
                    <textarea name="address" id="sAddress" rows="2" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none focus:border-amber-500/50"></textarea>
                </div>
                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Notes</label>
                    <textarea name="notes" id="sNotes" rows="2" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none focus:border-amber-500/50"></textarea>
                </div>
                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Save Supplier</button>
            </form>
        </div>
    </div>

    <script>
        const API = '../api/inventory.php';
        const CSRF = '<?php echo $csrfToken; ?>';

        async function load() {
            const r = await fetch(API+'?action=list_suppliers'); 
            const j = await r.json();
            const list = document.getElementById('supplierList');
            const items = j.suppliers || [];
            if (items.length === 0) {
                list.innerHTML = '<div class="col-span-full text-center py-12"><div class="text-4xl mb-3">🏭</div><div class="text-zinc-500 text-sm">No suppliers registered yet</div></div>';
                return;
            }
            list.innerHTML = items.map(s => `
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-3 hover:border-amber-500/30 transition-all shadow-lg">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-sm font-black text-white">${s.company_name}</div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">${s.contact_person || 'No contact person'} ${s.vat_pan ? '· VAT/PAN: '+s.vat_pan : ''}</div>
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black">${(s.status||'active').toUpperCase()}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-xs text-zinc-400 bg-zinc-950 p-3 rounded-2xl border border-zinc-800/80">
                        <div>📞 ${s.phone || '—'}</div>
                        <div>📧 ${s.email || '—'}</div>
                        <div class="col-span-2 text-[11px] text-zinc-400">📍 ${s.address || 'No address specified'}</div>
                    </div>
                    <div class="flex items-center justify-between text-[11px] text-zinc-500 pt-1">
                        <span>Balance: <strong class="text-rose-400">Rs.${parseFloat(s.outstanding_balance||0).toFixed(2)}</strong></span>
                        <span class="text-amber-400 font-bold">★ ${parseFloat(s.performance_rating||5.0).toFixed(1)} / 5.0</span>
                    </div>
                    <div class="flex gap-2 pt-1">
                        <button onclick="edit(${s.id})" class="flex-1 h-9 rounded-xl bg-zinc-950 border border-zinc-800 text-xs font-bold text-zinc-300 hover:border-amber-500/40">✏️ Edit Profile</button>
                        <button onclick="del(${s.id})" class="h-9 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs font-bold text-rose-400 hover:border-rose-500/40">🗑️</button>
                    </div>
                </div>
            `).join('');
        }

        function openModal(id=0) { 
            document.getElementById('mTitle').textContent = id ? 'Edit Supplier' : 'Add Supplier'; 
            document.getElementById('sId').value = id; 
            document.getElementById('sForm').reset(); 
            document.getElementById('sId').value = id; 
            document.getElementById('modal').classList.remove('hidden'); 
            document.getElementById('modal').classList.add('flex'); 
        }

        function closeModal() { 
            document.getElementById('modal').classList.add('hidden'); 
            document.getElementById('modal').classList.remove('flex'); 
        }

        async function edit(id) {
            const r = await fetch(API+'?action=list_suppliers'); 
            const j = await r.json();
            const s = (j.suppliers||[]).find(x => x.id == id);
            if (!s) return;
            openModal(id);
            document.getElementById('sCompany').value = s.company_name;
            document.getElementById('sContact').value = s.contact_person || '';
            document.getElementById('sPhone').value = s.phone || '';
            document.getElementById('sEmail').value = s.email || '';
            document.getElementById('sVat').value = s.vat_pan || '';
            document.getElementById('sAddress').value = s.address || '';
            document.getElementById('sNotes').value = s.notes || '';
        }

        async function save(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('sForm'));
            const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            const j = await r.json();
            if (j.success) { 
                closeModal(); 
                load(); 
            } else { 
                alert(j.message); 
            }
        }

        async function del(id) {
            if (!confirm('Deactivate this supplier?')) return;
            const fd = new FormData(); 
            fd.append('action','delete_supplier'); 
            fd.append('id',id); 
            fd.append('csrf_token',CSRF);
            await fetch(API, {method:'POST', body:fd, credentials:'same-origin'}); 
            load();
        }

        document.addEventListener('DOMContentLoaded', load);
    </script>
</body>
</html>
