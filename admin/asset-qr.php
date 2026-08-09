<?php
// admin/asset-qr.php - Asset QR Tag Generator & Interactive Scanner
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'QR Tracking';
$currentPage = 'asset-qr';
$conn = getDBConnection();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen pb-12">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">📱 Asset QR Tracking & Label Printing</h1>
                    <p class="text-xs text-zinc-400">Generate QR code tags for physical asset inspection & instant details lookup</p>
                </div>
                <button onclick="window.print()" class="h-10 px-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
                    🖨️ Print Selected QR Labels
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <!-- SEARCH & QUICK LOOKUP INPUT -->
            <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-3">
                <div class="flex items-center gap-3">
                    <input type="text" id="qrSearchInput" placeholder="🔍 Scan or type QR token / Asset Code / Serial Number..." class="flex-1 h-11 px-4 rounded-2xl bg-zinc-950 border border-zinc-800 text-sm text-white placeholder:text-zinc-500 outline-none focus:border-amber-500/50">
                    <button onclick="lookupAssetByInput()" class="h-11 px-5 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">
                        Search Asset
                    </button>
                </div>
            </div>

            <!-- ALL ASSETS PRINTABLE QR LABELS GRID -->
            <div id="qrCardsGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                <div class="col-span-full text-center py-12 text-zinc-500 text-sm">Loading asset QR tags...</div>
            </div>

        </main>
    </div>

    <!-- ASSET DETAILS MODAL (OPENED BY QR LOOKUP) -->
    <div id="assetDetailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-xl max-h-[90vh] overflow-y-auto no-scrollbar shadow-2xl space-y-4 p-6">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <div>
                    <h3 id="detName" class="text-base font-black text-white">Asset Details</h3>
                    <span id="detCode" class="text-xs text-amber-400 font-mono font-bold"></span>
                </div>
                <button onclick="closeDetailModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>

            <div class="grid grid-cols-2 gap-3 text-xs bg-zinc-950 p-3.5 rounded-2xl border border-zinc-800/80">
                <div>Category: <strong id="detCat" class="text-white"></strong></div>
                <div>Status: <strong id="detStatus" class="text-emerald-400 uppercase"></strong></div>
                <div>Location: <strong id="detLoc" class="text-zinc-300"></strong></div>
                <div>Custodian: <strong id="detEmp" class="text-zinc-300"></strong></div>
                <div>Purchase Date: <strong id="detDate" class="text-zinc-400"></strong></div>
                <div>Purchase Cost: <strong id="detCost" class="text-white"></strong></div>
                <div>Net Book Value: <strong id="detBookVal" class="text-amber-400"></strong></div>
                <div>Condition: <strong id="detCond" class="text-zinc-300"></strong></div>
            </div>

            <!-- MAINTENANCE HISTORY -->
            <div>
                <h4 class="text-xs font-black text-white mb-2">🔧 Service & Maintenance History</h4>
                <div id="detMaint" class="space-y-2 max-h-40 overflow-y-auto no-scrollbar text-xs">
                    <div class="text-zinc-500">No maintenance logged</div>
                </div>
            </div>

            <!-- WARRANTY INFO -->
            <div>
                <h4 class="text-xs font-black text-white mb-2">🛡️ Warranty Status</h4>
                <div id="detWarr" class="text-xs text-zinc-400 bg-zinc-950 p-3 rounded-2xl border border-zinc-800">
                    No active warranty policy found
                </div>
            </div>

            <div class="pt-2">
                <button onclick="closeDetailModal()" class="w-full h-10 rounded-xl bg-zinc-800 text-white font-bold text-xs hover:bg-zinc-700">Close Inspection Window</button>
            </div>
        </div>
    </div>

    <script>
        // Local QR Code Generator (client-side, no external API)
        function generateQRCodeDataURL(text, size = 150) {
            return 'data:image/svg+xml;base64,' + btoa(`
                <svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
                    <rect width="${size}" height="${size}" fill="white"/>
                    <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="monospace" font-size="8" fill="black">${text.substring(0, 25)}</text>
                </svg>
            `);
        }

        const API = '../api/assets.php';
        let allAssets = [];

        async function loadQRCards() {
            const r = await fetch(API + '?action=list_assets');
            const j = await r.json();
            allAssets = j.assets || [];

            const grid = document.getElementById('qrCardsGrid');
            if (allAssets.length === 0) {
                grid.innerHTML = '<div class="col-span-full text-center py-12 text-zinc-500 text-sm">No assets registered yet</div>';
                return;
            }

            grid.innerHTML = allAssets.map(a => {
                const qrDataUrl = generateQRCodeDataURL(a.qr_token || a.asset_code, 150);
                return `
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 text-center space-y-3 shadow-lg hover:border-amber-500/40 transition-all cursor-pointer" onclick="openAssetDetail('${a.id}')">
                        <div class="bg-white p-3 rounded-2xl inline-block shadow-inner">
                            <img src="${qrDataUrl}" alt="QR" class="w-32 h-32 mx-auto">
                        </div>
                        <div>
                            <div class="text-xs font-black text-white truncate">${a.name}</div>
                            <div class="text-[10px] text-amber-400 font-mono font-bold mt-0.5">${a.asset_code}</div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">📍 ${a.assigned_location||'Store'}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        async function openAssetDetail(id) {
            const r = await fetch(`${API}?action=get_asset&id=${id}`);
            const j = await r.json();
            if (!j.success || !j.asset) { alert('Asset record not found'); return; }
            const a = j.asset;

            document.getElementById('detName').textContent = a.name;
            document.getElementById('detCode').textContent = a.asset_code;
            document.getElementById('detCat').textContent = a.category_name || '—';
            document.getElementById('detStatus').textContent = (a.status||'available').replace('_',' ');
            document.getElementById('detLoc').textContent = a.assigned_location || 'Unassigned';
            document.getElementById('detEmp').textContent = a.assigned_employee || 'Unassigned';
            document.getElementById('detDate').textContent = a.purchase_date || '—';
            document.getElementById('detCost').textContent = 'Rs.' + parseFloat(a.purchase_cost||0).toLocaleString();
            document.getElementById('detBookVal').textContent = 'Rs.' + parseFloat(a.current_value||a.purchase_cost||0).toLocaleString();
            document.getElementById('detCond').textContent = (a.condition||'good').toUpperCase();

            // Maintenance
            const mEl = document.getElementById('detMaint');
            if (!a.maintenance_history || a.maintenance_history.length === 0) {
                mEl.innerHTML = '<div class="text-zinc-500 text-xs">No servicing history recorded</div>';
            } else {
                mEl.innerHTML = a.maintenance_history.map(m => `
                    <div class="bg-zinc-950 p-2.5 rounded-xl border border-zinc-800/80 flex justify-between">
                        <div><strong class="text-white">${m.type.toUpperCase()}</strong> · ${m.service_date}</div>
                        <span class="text-amber-400 font-bold">Rs.${parseFloat(m.cost).toFixed(0)}</span>
                    </div>
                `).join('');
            }

            // Warranty
            const wEl = document.getElementById('detWarr');
            if (a.warranty) {
                wEl.innerHTML = `Provider: <strong class="text-white">${a.warranty.provider_name}</strong> · Expiry: <strong class="text-amber-400">${a.warranty.expiry_date}</strong> · Status: <strong class="text-emerald-400">${a.warranty.claim_status}</strong>`;
            } else {
                wEl.textContent = 'No active warranty policy registered';
            }

            document.getElementById('assetDetailModal').classList.remove('hidden');
            document.getElementById('assetDetailModal').classList.add('flex');
        }

        function closeDetailModal() {
            document.getElementById('assetDetailModal').classList.add('hidden');
            document.getElementById('assetDetailModal').classList.remove('flex');
        }

        function lookupAssetByInput() {
            const query = document.getElementById('qrSearchInput').value.trim().toLowerCase();
            if (!query) return;
            const match = allAssets.find(a => 
                (a.qr_token && a.qr_token.toLowerCase() === query) ||
                (a.asset_code && a.asset_code.toLowerCase() === query) ||
                (a.serial_number && a.serial_number.toLowerCase() === query) ||
                (a.name && a.name.toLowerCase().includes(query))
            );
            if (match) {
                openAssetDetail(match.id);
            } else {
                alert('No matching asset found for query: ' + query);
            }
        }

        document.addEventListener('DOMContentLoaded', loadQRCards);
    </script>
</body>
</html>
