<?php
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Stock Movements';
$currentPage = 'stock-movements';
include 'includes/header.php';
include 'includes/sidebar.php';
?>
    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div><h1 class="text-lg font-black text-white">🔄 Stock Movements</h1><p class="text-xs text-zinc-400">Immutable audit trail of all inventory transactions</p></div>
                <div class="flex items-center gap-2 flex-wrap">
                    <select id="filterType" onchange="load()" class="h-9 px-3 rounded-xl bg-zinc-900 border border-zinc-800 text-[10px] text-white outline-none">
                        <option value="">All Movement Types</option>
                        <option value="purchase">Purchase</option>
                        <option value="consumption">Kitchen Consumption</option>
                        <option value="waste">Waste</option>
                        <option value="adjustment">Adjustment</option>
                        <option value="transfer">Transfer</option>
                        <option value="return">Return</option>
                        <option value="damage">Damage</option>
                        <option value="manual">Manual Update</option>
                    </select>
                    <input type="date" id="filterFrom" onchange="load()" class="h-9 px-3 rounded-xl bg-zinc-900 border border-zinc-800 text-[10px] text-white outline-none">
                    <input type="date" id="filterTo" onchange="load()" class="h-9 px-3 rounded-xl bg-zinc-900 border border-zinc-800 text-[10px] text-white outline-none">
                </div>
            </div>
        </header>
        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 pb-8">
            <div id="movementsList" class="space-y-2">
                <div class="text-center py-12 text-zinc-500 text-sm">Loading...</div>
            </div>
        </main>
    </div>
    <script>
        const API = '../api/inventory.php';
        async function load() {
            const type = document.getElementById('filterType').value;
            const from = document.getElementById('filterFrom').value;
            const to = document.getElementById('filterTo').value;
            const r = await fetch(`${API}?action=list_movements&type=${type}&from=${from}&to=${to}`);
            const j = await r.json();
            const items = j.movements||[];
            const el = document.getElementById('movementsList');
            if (items.length===0) { el.innerHTML='<div class="text-center py-12 text-zinc-500 text-sm">No movements found</div>'; return; }
            el.innerHTML = items.map(t => {
                const icon = t.direction==='in' ? '📥' : '📤';
                const color = t.direction==='in' ? 'emerald' : 'rose';
                const sign = t.direction==='in' ? '+' : '-';
                const typeColors = {purchase:'blue',consumption:'purple',waste:'rose',adjustment:'amber',transfer:'cyan',return:'emerald',damage:'red',manual:'zinc'};
                const tc = typeColors[t.type]||'zinc';
                return `<div class="bg-zinc-900/90 border border-zinc-800 rounded-2xl p-3 flex items-center justify-between hover:border-zinc-700 transition-all">
                    <div class="flex items-center gap-3">
                        <span class="text-xl">${icon}</span>
                        <div>
                            <div class="text-xs font-bold text-white">${t.item_name}</div>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="px-1.5 py-0.5 rounded-md bg-${tc}-500/10 text-${tc}-400 text-[9px] font-bold uppercase">${t.type}</span>
                                <span class="text-[10px] text-zinc-500">${new Date(t.created_at).toLocaleString()}</span>
                                ${t.notes ? `<span class="text-[10px] text-zinc-600">· ${t.notes}</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-black text-${color}-400">${sign}${parseFloat(t.quantity).toFixed(2)} ${t.unit_abbr}</div>
                        <div class="text-[10px] text-zinc-500">${parseFloat(t.stock_before).toFixed(1)} → ${parseFloat(t.stock_after).toFixed(1)}</div>
                    </div>
                </div>`;
            }).join('');
        }
        document.addEventListener('DOMContentLoaded', load);
    </script>
</body>
</html>
