<?php
// kitchen-dashboard.php - Enterprise Kitchen Operations Center & KDS Command Center
require_once 'config.php';
requireKitchenLogin();

// Logout Handler
if (isset($_GET['kds_logout'])) {
    unset($_SESSION['kitchen_user']);
    header('Location: kitchen-login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>Kitchen Display System (KDS) - QR Cafe</title>
    <link rel="manifest" href="manifest.json">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              amber: { 500: '#f59e0b', 600: '#d97706' }
            }
          }
        }
      }
    </script>
    <style>
        body { overscroll-behavior-y: contain; -webkit-tap-highlight-color: transparent; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        @keyframes alertPulse {
            0% { box-shadow: 0 0 10px rgba(244, 63, 94, 0.4); transform: scale(1); }
            100% { box-shadow: 0 0 20px rgba(244, 63, 94, 0.8); transform: scale(1.02); }
        }
        .red-flash-badge { animation: alertPulse 1s infinite alternate; }
    </style>
</head>
<body class="min-h-full pb-20 md:pb-8 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <!-- HEADER BAR -->
    <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-xl">👨‍🍳</div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base md:text-lg font-black text-white leading-tight">Kitchen Operations Command Center (KDS)</h1>
                        <span class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black uppercase tracking-wider">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span> 2s Realtime Stream
                        </span>
                    </div>
                    <p class="text-xs text-zinc-400 hidden sm:block">Toast / Oracle KDS Style Live Line Monitor & Itemized Cooking Station Queue</p>
                </div>
            </div>

            <!-- Action Controls -->
            <div class="flex items-center gap-2 shrink-0">
                <button id="soundToggleBtn" onclick="toggleSoundAlerts()" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-extrabold text-xs flex items-center gap-1.5 hover:border-amber-500/40">
                    <span id="soundIcon">🔔</span>
                    <span id="soundLabel">Sound On</span>
                </button>
                <a href="kitchen-menu.php" class="hidden md:inline-flex items-center gap-1.5 h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-xs font-bold text-amber-400 hover:border-amber-500/40">
                    📋 Today's Menu
                </a>
                <a href="kitchen-dashboard.php?kds_logout=1" class="h-10 px-3 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 font-bold text-xs hover:bg-rose-500 hover:text-white flex items-center gap-1">
                    Lock KDS 🔒
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 space-y-6">

        <!-- 1. TOP KITCHEN COMMAND KPI METRICS (8 METRICS) -->
        <section class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
            <div class="bg-zinc-900/90 border border-rose-500/30 rounded-2xl p-3 text-center space-y-1">
                <span class="text-xs text-rose-400 font-bold">🆕 New Orders</span>
                <div id="kpiNewOrders" class="text-xl font-black text-rose-400">0</div>
            </div>
            <div class="bg-zinc-900/90 border border-amber-500/30 rounded-2xl p-3 text-center space-y-1">
                <span class="text-xs text-amber-400 font-bold">🔥 Preparing</span>
                <div id="kpiPreparing" class="text-xl font-black text-amber-400">0</div>
            </div>
            <div class="bg-zinc-900/90 border border-emerald-500/30 rounded-2xl p-3 text-center space-y-1">
                <span class="text-xs text-emerald-400 font-bold">✅ Ready Pickup</span>
                <div id="kpiReady" class="text-xl font-black text-emerald-400">0</div>
            </div>
            <div class="bg-zinc-900/90 border border-rose-500/30 rounded-2xl p-3 text-center space-y-1">
                <span class="text-xs text-rose-400 font-bold">🚨 Delayed (>15m)</span>
                <div id="kpiDelayed" class="text-xl font-black text-rose-400">0</div>
            </div>
            <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                <span class="text-xs text-zinc-400 font-bold">⏱ Avg Prep Time</span>
                <div id="kpiAvgPrep" class="text-sm font-black text-amber-400 truncate">12m</div>
            </div>
            <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                <span class="text-xs text-zinc-400 font-bold">🍽 Completed Today</span>
                <div id="kpiCompletedToday" class="text-lg font-black text-white">0</div>
            </div>
            <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                <span class="text-xs text-zinc-400 font-bold">👨‍🍳 Active Chefs</span>
                <div id="kpiActiveChefs" class="text-lg font-black text-blue-400">3</div>
            </div>
            <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                <span class="text-xs text-zinc-400 font-bold">⚡ Kitchen Load</span>
                <div id="kpiKitchenLoad" class="text-sm font-black text-emerald-400 truncate">35%</div>
            </div>
        </section>

        <!-- 2. SEARCH & KITCHEN LINE FILTERS -->
        <section class="flex flex-col sm:flex-row items-center justify-between gap-3 bg-zinc-900/90 border border-zinc-800/80 p-3.5 rounded-3xl">
            <div class="relative w-full sm:w-72">
                <input type="text" id="kdsSearchInput" onkeyup="filterKdsTickets()" placeholder="Search Order #, Table, Dish..." class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl pl-9 pr-3 text-xs text-white placeholder-zinc-500 font-medium outline-none focus:border-amber-500">
                <span class="absolute left-3 top-2.5 text-xs text-zinc-500">🔍</span>
            </div>

            <!-- Filter Status Pills -->
            <div class="flex items-center gap-1.5 overflow-x-auto w-full sm:w-auto no-scrollbar">
                <button onclick="setKdsFilter('all')" id="filterBtnAll" class="px-3.5 py-1.5 rounded-2xl font-black text-xs bg-amber-500 text-zinc-950 shadow-md">All Tickets</button>
                <button onclick="setKdsFilter('new')" id="filterBtnNew" class="px-3.5 py-1.5 rounded-2xl font-bold text-xs bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">🆕 New</button>
                <button onclick="setKdsFilter('preparing')" id="filterBtnPrep" class="px-3.5 py-1.5 rounded-2xl font-bold text-xs bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">🔥 Preparing</button>
                <button onclick="setKdsFilter('ready')" id="filterBtnReady" class="px-3.5 py-1.5 rounded-2xl font-bold text-xs bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">✅ Ready</button>
                <button onclick="setKdsFilter('delayed')" id="filterBtnDelayed" class="px-3.5 py-1.5 rounded-2xl font-bold text-xs bg-zinc-950 border border-zinc-800 text-rose-400 hover:text-rose-300">🚨 Overdue</button>
            </div>
        </section>

        <!-- 3. WAITER CALL ALERTS CAROUSEL -->
        <section class="space-y-2">
            <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider flex items-center gap-2">
                <span>🔔</span> Live Waiter Assistance Requests
            </h4>
            <div id="waiterCallsGrid" class="flex gap-3 overflow-x-auto no-scrollbar py-1">
                <div class="text-xs text-zinc-500 italic py-2">No pending waiter calls</div>
            </div>
        </section>

        <!-- 4. KITCHEN TICKETS GRID BOARD -->
        <section id="kdsTicketsGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div class="col-span-full text-center py-16 text-zinc-500 font-bold text-sm">
                ⏳ Connecting to Kitchen Realtime Line...
            </div>
        </section>

    </main>

    <!-- REALTIME KITCHEN CONTROLLER SCRIPT -->
    <script src="js/modern.js"></script>
    <script>
        let currentFilter = 'all';
        let soundEnabled = true;

        function toggleSoundAlerts() {
            soundEnabled = !soundEnabled;
            document.getElementById('soundIcon').textContent = soundEnabled ? '🔔' : '🔕';
            document.getElementById('soundLabel').textContent = soundEnabled ? 'Sound On' : 'Muted';
            showToast(soundEnabled ? 'Kitchen Sound Notifications Enabled' : 'Kitchen Sounds Muted', 'info');
        }

        function setKdsFilter(filter) {
            currentFilter = filter;
            ['All', 'New', 'Prep', 'Ready', 'Delayed'].forEach(f => {
                const btn = document.getElementById('filterBtn' + f);
                if (f.toLowerCase() === filter) {
                    btn.className = 'px-3.5 py-1.5 rounded-2xl font-black text-xs bg-amber-500 text-zinc-950 shadow-md';
                } else {
                    btn.className = 'px-3.5 py-1.5 rounded-2xl font-bold text-xs bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white';
                }
            });
            refreshKitchenStream();
        }

        function refreshKitchenStream() {
            fetch('api/kitchen-stream.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        updateKPICards(data.kpi);
                        renderWaiterCalls(data.waiter_calls || []);
                        renderKitchenTickets(data.orders || []);
                    }
                })
                .catch(err => console.error('Kitchen stream error:', err));
        }

        function updateKPICards(kpi) {
            if (!kpi) return;
            document.getElementById('kpiNewOrders').textContent = kpi.new_orders || 0;
            document.getElementById('kpiPreparing').textContent = kpi.preparing || 0;
            document.getElementById('kpiReady').textContent = kpi.ready || 0;
            document.getElementById('kpiDelayed').textContent = kpi.delayed || 0;
            document.getElementById('kpiAvgPrep').textContent = kpi.avg_prep_time || '12m';
            document.getElementById('kpiCompletedToday').textContent = kpi.completed_today || 0;
            document.getElementById('kpiActiveChefs').textContent = kpi.active_chefs || 3;
            document.getElementById('kpiKitchenLoad').textContent = kpi.kitchen_load || '35%';
        }

        function renderWaiterCalls(calls) {
            const grid = document.getElementById('waiterCallsGrid');
            if (calls.length === 0) {
                grid.innerHTML = `<div class="text-xs text-zinc-500 italic py-2">No pending waiter calls</div>`;
                return;
            }

            grid.innerHTML = calls.map(c => `
                <div onclick="resolveWaiterCall(${c.id}, this)" class="px-4 py-2.5 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-400 shrink-0 flex items-center justify-between gap-3 cursor-pointer hover:bg-amber-500/20 active:scale-95 transition-all group">
                    <div class="flex items-center gap-3">
                        <span class="text-lg animate-bounce">🔔</span>
                        <div>
                            <span class="font-black text-xs text-white">Table ${c.table_number} Call</span>
                            <p class="text-[10px] text-zinc-400">${c.request_type || 'Waiter Requested'}</p>
                        </div>
                    </div>
                    <button class="px-2 py-1 rounded-xl bg-amber-500 text-zinc-950 font-black text-[10px] hover:brightness-110 shadow-md">
                        ✅ Attend
                    </button>
                </div>
            `).join('');
        }

        function resolveWaiterCall(callId, el) {
            if (el) {
                el.style.opacity = '0.5';
                el.style.pointerEvents = 'none';
            }
            const form = new FormData();
            form.append('action', 'serve');
            form.append('id', callId);

            fetch('api/call-waiter.php', { method: 'POST', body: form })
                .then(r => r.json())
                .then(data => {
                    showToast('Waiter call attended & cleared!', 'success');
                    refreshKitchenStream();
                })
                .catch(err => {
                    fetch('api/call-waiter.php?action=serve&id=' + callId)
                        .then(r => r.json())
                        .then(d => {
                            showToast('Waiter call attended & cleared!', 'success');
                            refreshKitchenStream();
                        });
                });
        }

        function renderKitchenTickets(orders) {
            const grid = document.getElementById('kdsTicketsGrid');
            const searchVal = document.getElementById('kdsSearchInput').value.toLowerCase();

            let filtered = orders.filter(o => {
                if (currentFilter === 'new' && o.status !== 'new') return false;
                if (currentFilter === 'preparing' && o.status !== 'preparing') return false;
                if (currentFilter === 'ready' && o.status !== 'ready') return false;
                if (currentFilter === 'delayed' && !o.is_delayed) return false;

                if (searchVal) {
                    const matchId = String(o.id).includes(searchVal);
                    const matchTable = String(o.table_number).includes(searchVal);
                    const matchCust = (o.customer_name || '').toLowerCase().includes(searchVal);
                    return matchId || matchTable || matchCust;
                }
                return true;
            });

            if (filtered.length === 0) {
                grid.innerHTML = `<div class="col-span-full text-center py-16 text-zinc-500 font-bold text-sm">No active kitchen tickets for this view</div>`;
                return;
            }

            grid.innerHTML = filtered.map(o => {
                const elapsed = o.elapsed_mins || 0;
                let timerColor = 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30';
                if (elapsed > 10) timerColor = 'text-amber-400 bg-amber-500/10 border-amber-500/30';
                if (elapsed > 15) timerColor = 'text-rose-400 bg-rose-500/10 border-rose-500/30 red-flash-badge';

                let statusBadge = '<span class="px-2.5 py-0.5 rounded-full bg-rose-500/10 border border-rose-500/30 text-rose-400 font-black text-[10px]">NEW</span>';
                if (o.status === 'preparing') statusBadge = '<span class="px-2.5 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 font-black text-[10px]">PREPARING</span>';
                if (o.status === 'ready') statusBadge = '<span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-black text-[10px]">READY</span>';

                const itemsHtml = (o.items || []).map(i => `
                    <div class="flex items-start justify-between gap-2 border-b border-zinc-800/60 pb-2">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-amber-500 text-zinc-950 font-black text-xs flex items-center justify-center">${i.quantity}x</span>
                            <div>
                                <span class="font-black text-sm text-white">${i.item_name}</span>
                                ${i.allergens ? `<span class="block text-[10px] text-rose-400 font-bold">⚠️ ${i.allergens}</span>` : ''}
                            </div>
                        </div>
                    </div>
                `).join('');

                let actionButtons = '';
                if (o.status === 'new') {
                    actionButtons = `
                        <button onclick="updateOrderStatus(this, ${o.id}, 'preparing')" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-md hover:brightness-110">🔥 Start Cooking</button>
                    `;
                } else if (o.status === 'preparing') {
                    actionButtons = `
                        <button onclick="updateOrderStatus(this, ${o.id}, 'ready')" class="w-full h-11 rounded-2xl bg-emerald-500 text-zinc-950 font-black text-xs active:scale-95 shadow-md hover:brightness-110">✅ Mark Ready</button>
                    `;
                } else if (o.status === 'ready') {
                    actionButtons = `
                        <button onclick="updateOrderStatus(this, ${o.id}, 'completed')" class="w-full h-11 rounded-2xl bg-zinc-800 border border-zinc-700 text-zinc-200 font-bold text-xs hover:border-amber-500">🏁 Complete Order</button>
                    `;
                }

                return `
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4 flex flex-col justify-between">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-black text-white text-base">#${o.id}</span>
                                        <span class="px-2 py-0.5 rounded-lg bg-zinc-800 text-zinc-300 font-extrabold text-[10px]">Table ${o.table_number}</span>
                                        <span class="px-2 py-0.5 rounded-lg bg-amber-500/20 border border-amber-500/30 text-amber-400 font-extrabold text-[10px]">Batch #${o.batch_number || 1}</span>
                                    </div>
                                    <p class="text-[11px] text-zinc-400 font-medium">${o.customer_name || 'Guest'}</p>
                                </div>
                                <div class="text-right">
                                    <div data-mins="${elapsed}" class="elapsed-timer-badge px-3 py-1 rounded-xl border text-xs font-black ${timerColor}">⏱ ${elapsed}m</div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between">
                                ${statusBadge}
                                <span class="text-[10px] text-zinc-500 font-bold">Station #${(o.id % 4) + 1}</span>
                            </div>

                            ${o.notes ? `
                                <div class="p-2.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">
                                    📝 Note: ${o.notes}
                                </div>
                            ` : ''}

                            <div class="space-y-2 pt-1">
                                ${itemsHtml}
                            </div>
                        </div>

                        <div class="pt-3 border-t border-zinc-800">
                            ${actionButtons}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function filterKdsTickets() {
            refreshKitchenStream();
        }

        function updateOrderStatus(btn, orderId, status) {
            if (!btn || btn.disabled) return;

            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳ Saving...';

            const form = new FormData();
            form.append('action', 'update_status');
            form.append('order_id', orderId);
            form.append('status', status);

            fetch('api/orders-stream.php', { method: 'POST', body: form })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(`Order #${orderId} set to ${status.toUpperCase()}`, 'success');
                        refreshKitchenStream();
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                        showToast(data.message || data.error || 'Failed to update status', 'error');
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                    console.error('Status update error:', err);
                    showToast('Network error updating order status', 'error');
                });
        }

        // Initialize Realtime Polling Stream (Every 2 seconds)
        document.addEventListener('DOMContentLoaded', () => {
            refreshKitchenStream();
            setInterval(refreshKitchenStream, 2000);
        });
    </script>
</body>
</html>
