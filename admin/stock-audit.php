<?php
// admin/stock-audit.php - Physical Stock Audit & Variance Reconciliation
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Stock Audit';
$currentPage = 'stock-audit';
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">⚖️ Physical Stock Audit</h1>
                    <p class="text-xs text-zinc-400">Reconcile physical inventory counts against system balances & approve variance adjustments</p>
                </div>
                <button onclick="openAuditModal()" class="h-10 px-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">
                    + New Physical Count Audit
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 pb-12 space-y-6">

            <!-- AUDIT HISTORY TABLE -->
            <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-black text-white">📋 Audit History & Variance Log</h2>
                    <span class="text-xs text-zinc-500">Immutable Audit Trail</span>
                </div>
                <div id="auditLogList" class="space-y-2">
                    <div class="text-center py-12 text-zinc-500 text-sm">Loading audit history...</div>
                </div>
            </div>

        </main>
    </div>

    <!-- AUDIT ENTRY MODAL -->
    <div id="auditModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 class="text-sm font-black text-white">⚖️ Record Stock Audit</h2>
                <button onclick="closeAuditModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="auditForm" class="p-5 space-y-4" onsubmit="submitAudit(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_audit">

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Select Stock Item *</label>
                    <select name="inventory_item_id" id="auditItemSelect" required onchange="onAuditItemChange()" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3 bg-zinc-950 p-3 rounded-2xl border border-zinc-800/80">
                    <div>
                        <span class="text-[10px] text-zinc-500 block font-semibold">System Stock</span>
                        <div id="dispSystemQty" class="text-sm font-black text-amber-400">0.00</div>
                    </div>
                    <div>
                        <span class="text-[10px] text-zinc-500 block font-semibold">Expected Variance</span>
                        <div id="dispVariance" class="text-sm font-black text-zinc-400">0.00</div>
                    </div>
                </div>

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Physical Count Quantity *</label>
                    <input type="number" step="0.001" name="physical_qty" id="auditPhysicalQty" required oninput="calcVariance()" placeholder="Actual measured quantity" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" name="auto_adjust" id="auditAutoAdjust" value="1" checked class="w-4 h-4 rounded bg-zinc-950 border-zinc-800 text-amber-500 focus:ring-0">
                    <label for="auditAutoAdjust" class="text-xs text-zinc-300 font-bold">Automatically adjust system stock balance</label>
                </div>

                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Audit Notes / Explanation</label>
                    <textarea name="notes" placeholder="Reason for physical discrepancy (e.g. Month-end count verification)" rows="2" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none focus:border-amber-500/50"></textarea>
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Post Physical Audit</button>
            </form>
        </div>
    </div>

    <script>
        const API = '../api/inventory.php';
        const CSRF = '<?php echo $csrfToken; ?>';
        let stockItems = [];

        async function init() {
            const r = await fetch(API + '?action=list_items&status=active');
            const j = await r.json();
            stockItems = j.items || [];

            const sel = document.getElementById('auditItemSelect');
            sel.innerHTML = '<option value="">— Select Item —</option>' + stockItems.map(i => `
                <option value="${i.id}" data-stock="${i.current_stock}">${i.name} (Current: ${parseFloat(i.current_stock).toFixed(1)} ${i.unit_abbr||'pcs'})</option>
            `).join('');

            loadAuditLog();
        }

        function onAuditItemChange() {
            const sel = document.getElementById('auditItemSelect');
            const stock = parseFloat(sel.selectedOptions[0]?.dataset.stock || 0);
            document.getElementById('dispSystemQty').textContent = stock.toFixed(2);
            calcVariance();
        }

        function calcVariance() {
            const sel = document.getElementById('auditItemSelect');
            const system = parseFloat(sel.selectedOptions[0]?.dataset.stock || 0);
            const physical = parseFloat(document.getElementById('auditPhysicalQty').value || 0);
            const variance = physical - system;
            const disp = document.getElementById('dispVariance');
            disp.textContent = (variance > 0 ? '+' : '') + variance.toFixed(2);
            disp.className = 'text-sm font-black ' + (variance === 0 ? 'text-zinc-400' : variance > 0 ? 'text-emerald-400' : 'text-rose-400');
        }

        async function loadAuditLog() {
            const r = await fetch(API + '?action=list_movements&type=adjustment');
            const j = await r.json();
            const el = document.getElementById('auditLogList');
            const items = j.movements || [];

            if (items.length === 0) {
                el.innerHTML = '<div class="text-center py-12 text-zinc-500 text-sm">No stock audits posted yet</div>';
                return;
            }

            el.innerHTML = items.map(a => {
                const variance = parseFloat(a.quantity) * (a.direction === 'in' ? 1 : -1);
                const varColor = variance >= 0 ? 'emerald' : 'rose';
                const varSign = variance > 0 ? '+' : '';
                return `
                    <div class="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-3 flex items-center justify-between hover:border-amber-500/30 transition-all">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">⚖️</span>
                            <div>
                                <div class="text-xs font-bold text-white">${a.item_name}</div>
                                <div class="text-[10px] text-zinc-500 mt-0.5">
                                    Audited by: <span class="text-zinc-400 font-bold">${a.created_by||'admin'}</span> · ${new Date(a.created_at).toLocaleString()}
                                    ${a.notes ? `· <span class="text-zinc-400">${a.notes}</span>` : ''}
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs font-black text-${varColor}-400">${varSign}${variance.toFixed(2)} ${a.unit_abbr}</div>
                            <div class="text-[10px] text-zinc-500">System: ${parseFloat(a.stock_before).toFixed(1)} → New: ${parseFloat(a.stock_after).toFixed(1)}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function openAuditModal() {
            document.getElementById('auditForm').reset();
            document.getElementById('dispSystemQty').textContent = '0.00';
            document.getElementById('dispVariance').textContent = '0.00';
            document.getElementById('auditModal').classList.remove('hidden');
            document.getElementById('auditModal').classList.add('flex');
        }

        function closeAuditModal() {
            document.getElementById('auditModal').classList.add('hidden');
            document.getElementById('auditModal').classList.remove('flex');
        }

        async function submitAudit(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('auditForm'));
            const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            const j = await r.json();
            if (j.success) {
                closeAuditModal();
                init();
            } else {
                alert(j.message);
            }
        }

        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>
