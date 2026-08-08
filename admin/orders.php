<?php
// admin/orders.php - Enterprise POS Restaurant Order Management Center
require_once '../config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) {
    die("Database connection error");
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>POS Order Management Center - QR Cafe</title>
    <link rel="manifest" href="../manifest.json">
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
        @keyframes pulseAlert {
            0% { box-shadow: 0 0 0 0 rgba(244, 63, 94, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(244, 63, 94, 0); }
            100% { box-shadow: 0 0 0 0 rgba(244, 63, 94, 0); }
        }
        .overdue-flash { animation: pulseAlert 1.2s infinite; }
    </style>
</head>
<body class="min-h-full pb-20 md:pb-8 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <!-- DESKTOP SIDEBAR NAVIGATION -->
    <?php $currentPage = 'orders'; include 'includes/sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="md:pl-64 min-h-screen">

        <!-- HEADER BAR -->
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg md:text-xl font-black text-white">Restaurant Order Management Center</h1>
                        <span class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-blue-500/10 border border-blue-500/30 text-blue-400 text-[10px] font-black uppercase tracking-wider">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-400 animate-ping"></span> Live Stream
                        </span>
                    </div>
                    <p class="text-xs text-zinc-400 hidden sm:block">Full Lifecycle Order Tracking, Kitchen Status Updates & Ticket Processing</p>
                </div>

                <!-- Action Controls -->
                <div class="flex items-center gap-2 shrink-0">
                    <button onclick="refreshOrdersStream()" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
                        🔄 Refresh
                    </button>
                    <a href="../kitchen-dashboard.php" class="h-10 px-4 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs flex items-center gap-1.5 shadow-lg shadow-amber-500/20">
                        <span>👨‍🍳</span> Kitchen Display (KDS)
                    </a>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 space-y-6">

            <!-- 1. TOP STICKY KPI CARDS (10 METRICS) -->
            <section class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 lg:grid-cols-10 gap-3">
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🔵 New</span>
                    <div id="kpiNew" class="text-lg font-black text-blue-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🟠 Preparing</span>
                    <div id="kpiPrep" class="text-lg font-black text-amber-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🟢 Ready</span>
                    <div id="kpiReady" class="text-lg font-black text-emerald-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">✅ Served</span>
                    <div id="kpiServed" class="text-lg font-black text-white">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">❌ Cancelled</span>
                    <div id="kpiCancelled" class="text-lg font-black text-rose-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🟣 Pending Pay</span>
                    <div id="kpiPendingPay" class="text-lg font-black text-purple-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🪑 Active Tables</span>
                    <div id="kpiActiveTables" class="text-lg font-black text-white">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">💰 Revenue</span>
                    <div id="kpiRevenue" class="text-sm font-black text-emerald-400 truncate">Rs.0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">⏱ Avg Cook</span>
                    <div id="kpiAvgPrep" class="text-xs font-black text-blue-400">14m</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🚨 Delayed</span>
                    <div id="kpiDelayed" class="text-lg font-black text-rose-400">0</div>
                </div>
            </section>

            <!-- 2. SEARCH & FILTER CONTROLS BAR -->
            <section class="bg-zinc-900/90 border border-zinc-800/80 rounded-3xl p-4 shadow-xl space-y-3">
                <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                    
                    <!-- Search Input -->
                    <div class="relative flex-1">
                        <span class="absolute left-3.5 top-3 text-zinc-500 text-xs">🔍</span>
                        <input type="text" id="searchInput" oninput="filterOrdersStream()" placeholder="Search Order ID, Customer Name, Table #..." class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl pl-9 pr-4 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 font-medium">
                    </div>

                    <!-- Status Filter Pills -->
                    <div class="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-1">
                        <button onclick="setStatusFilter('all')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md" data-status="all">All Orders</button>
                        <button onclick="setStatusFilter('new')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-blue-400 hover:text-white" data-status="new">🔵 New</button>
                        <button onclick="setStatusFilter('preparing')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-amber-400 hover:text-white" data-status="preparing">🟠 Preparing</button>
                        <button onclick="setStatusFilter('ready')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-emerald-400 hover:text-white" data-status="ready">🟢 Ready</button>
                        <button onclick="setStatusFilter('completed')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white" data-status="completed">✅ Served</button>
                        <button onclick="setStatusFilter('cancelled')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-rose-400 hover:text-white" data-status="cancelled">❌ Cancelled</button>
                        <button onclick="setStatusFilter('delayed')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-rose-400 hover:text-white" data-status="delayed">🚨 Delayed</button>
                    </div>
                </div>
            </section>

            <!-- 3. LIVE ORDER CARDS GRID -->
            <section class="space-y-4">
                <div id="ordersGridContainer" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div class="col-span-full py-12 text-center text-zinc-500">
                        <div class="text-4xl mb-2 animate-bounce">⏳</div>
                        <p class="font-bold text-xs">Loading Live Orders Queue...</p>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- 4. RIGHT SLIDE-OVER DRAWER (ORDER DETAILS & PROGRESSION TIMELINE) -->
    <div id="orderDrawer" class="fixed inset-y-0 right-0 z-50 w-full max-w-md bg-zinc-900 border-l border-zinc-800 shadow-2xl transform translate-x-full transition-transform duration-300 flex flex-col">
        
        <!-- Drawer Header -->
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between bg-zinc-950/80">
            <div class="flex items-center gap-3">
                <div id="drawerOrderBadge" class="w-12 h-12 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center font-black text-amber-400 text-base">#101</div>
                <div>
                    <h3 id="drawerOrderTitle" class="font-black text-white text-base">Order #101</h3>
                    <p id="drawerOrderSubtitle" class="text-xs text-zinc-400">Table 1 • Guest: John Doe</p>
                </div>
            </div>
            <button onclick="closeOrderDrawer()" class="w-9 h-9 rounded-xl bg-zinc-800 text-zinc-400 hover:text-white font-bold flex items-center justify-center">✕</button>
        </div>

        <!-- Drawer Scrollable Body -->
        <div class="flex-1 overflow-y-auto p-5 space-y-6">
            
            <!-- Order Status & Time Details -->
            <div class="flex items-center justify-between bg-zinc-950 p-3.5 rounded-2xl border border-zinc-800/80 text-xs">
                <div>
                    <span class="text-[10px] font-bold text-zinc-500 uppercase block">Ticket Status</span>
                    <div id="drawerStatusText" class="font-black text-amber-400 mt-0.5">NEW</div>
                </div>
                <div>
                    <span class="text-[10px] font-bold text-zinc-500 uppercase block">Elapsed Time</span>
                    <div id="drawerElapsedText" class="font-bold text-white mt-0.5">⏱ 8m ago</div>
                </div>
                <div>
                    <span class="text-[10px] font-bold text-zinc-500 uppercase block">Payment Status</span>
                    <div id="drawerPaymentStatusText" class="font-bold text-purple-400 mt-0.5">Pending</div>
                </div>
            </div>

            <!-- ORDER PROGRESSION TIMELINE -->
            <div class="space-y-2">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Order Lifecycle Timeline</h4>
                <div class="bg-zinc-950 border border-zinc-800 rounded-2xl p-4 space-y-2 text-xs">
                    <div class="flex items-center gap-3">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 font-bold flex items-center justify-center text-[10px]">✓</span>
                        <span class="font-bold text-white">Order Placed</span>
                        <span id="drawerTimePlaced" class="text-[10px] text-zinc-500 ml-auto">12:30 PM</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span id="timelineStepPrepIcon" class="w-5 h-5 rounded-full bg-zinc-800 text-zinc-500 font-bold flex items-center justify-center text-[10px]">2</span>
                        <span id="timelineStepPrepText" class="font-medium text-zinc-400">Kitchen Preparing</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span id="timelineStepReadyIcon" class="w-5 h-5 rounded-full bg-zinc-800 text-zinc-500 font-bold flex items-center justify-center text-[10px]">3</span>
                        <span id="timelineStepReadyText" class="font-medium text-zinc-400">Ready to Serve</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span id="timelineStepCompletedIcon" class="w-5 h-5 rounded-full bg-zinc-800 text-zinc-500 font-bold flex items-center justify-center text-[10px]">4</span>
                        <span id="timelineStepCompletedText" class="font-medium text-zinc-400">Served & Completed</span>
                    </div>
                </div>
            </div>

            <!-- ITEMIZED DISHES BREAKDOWN -->
            <div class="space-y-2">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Itemized Line Items</h4>
                <div id="drawerLinesList" class="space-y-2">
                    <div class="text-center py-6 text-xs text-zinc-500 bg-zinc-950 rounded-2xl border border-zinc-800">
                        No order items loaded
                    </div>
                </div>
            </div>

            <!-- SPECIAL COOKING INSTRUCTIONS -->
            <div id="drawerNotesBox" class="bg-amber-500/10 border border-amber-500/20 p-3.5 rounded-2xl text-xs text-amber-300 hidden space-y-1">
                <span class="font-black flex items-center gap-1">📝 Special Instructions:</span>
                <p id="drawerNotesText" class="text-zinc-300"></p>
            </div>

            <!-- SETTLEMENT SUMMARY -->
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl p-4 space-y-2 text-xs">
                <div class="flex justify-between text-zinc-400">
                    <span>Subtotal</span>
                    <span id="drawerSubtotalAmount">Rs.0</span>
                </div>
                <div class="flex justify-between text-zinc-400">
                    <span>Tax & Service Charge</span>
                    <span>Rs.0</span>
                </div>
                <div class="flex justify-between text-sm font-black text-white pt-2 border-t border-zinc-800">
                    <span>Total Settle Bill</span>
                    <span id="drawerTotalAmount" class="text-amber-400">Rs.0</span>
                </div>
            </div>

            <!-- QUICK STATUS TRANSITION BUTTONS -->
            <div class="space-y-2">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Update Status Action</h4>
                <div class="grid grid-cols-2 gap-2">
                    <button onclick="updateOrderStatusFromDrawer('preparing')" class="h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20 active:scale-95">
                        🟠 Mark Preparing
                    </button>
                    <button onclick="updateOrderStatusFromDrawer('ready')" class="h-11 rounded-2xl bg-emerald-500 text-zinc-950 font-black text-xs shadow-lg shadow-emerald-500/20 active:scale-95">
                        🟢 Mark Ready
                    </button>
                    <button onclick="updateOrderStatusFromDrawer('completed')" class="h-11 rounded-2xl bg-zinc-800 text-white font-bold text-xs hover:border-amber-500/40">
                        ✅ Mark Completed
                    </button>
                    <button onclick="updateOrderStatusFromDrawer('cancelled')" class="h-11 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 font-bold text-xs hover:bg-rose-500 hover:text-white">
                        ❌ Reject Order
                    </button>
                </div>
            </div>

        </div>

        <!-- Drawer Footer -->
        <div class="p-4 border-t border-zinc-800 bg-zinc-950">
            <button onclick="closeOrderDrawer()" class="w-full h-11 rounded-2xl bg-zinc-800 font-bold text-xs text-zinc-300">Close Drawer</button>
        </div>
    </div>

    <!-- MOBILE BOTTOM NAVIGATION -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 max-w-md mx-auto bg-zinc-950/95 backdrop-blur-xl border-t border-zinc-800/80 flex justify-around items-center h-16 rounded-t-2xl px-2">
        <a href="index.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📊</span>
            <span>Dashboard</span>
        </a>
        <a href="orders.php" class="flex flex-col items-center gap-0.5 text-amber-500 font-black text-[10px]">
            <span class="text-lg">📋</span>
            <span>Orders</span>
        </a>
        <a href="tables.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📍</span>
            <span>Tables</span>
        </a>
        <a href="menu-items.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">🍔</span>
            <span>Menu</span>
        </a>
    </nav>

    <!-- REALTIME ORDER MANAGEMENT CONTROLLER -->
    <script src="../js/modern.js"></script>
    <script>
        let allOrdersData = [];
        let selectedStatusFilter = 'all';
        let activeSelectedOrderId = null;

        function setStatusFilter(status) {
            selectedStatusFilter = status;
            document.querySelectorAll('.status-btn').forEach(btn => {
                if (btn.dataset.status === status) {
                    btn.className = 'status-btn px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md';
                } else {
                    btn.className = 'status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white';
                }
            });
            refreshOrdersStream();
        }

        function refreshOrdersStream() {
            fetch('../api/orders-stream.php?status=' + encodeURIComponent(selectedStatusFilter))
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        updateKPICards(data.kpi);
                        allOrdersData = data.orders || [];
                        renderOrdersGrid();
                    }
                })
                .catch(err => console.error('Orders stream error:', err));
        }

        function updateKPICards(kpi) {
            if (!kpi) return;
            document.getElementById('kpiNew').textContent = kpi.new_orders || 0;
            document.getElementById('kpiPrep').textContent = kpi.preparing || 0;
            document.getElementById('kpiReady').textContent = kpi.ready || 0;
            document.getElementById('kpiServed').textContent = kpi.served || 0;
            document.getElementById('kpiCancelled').textContent = kpi.cancelled || 0;
            document.getElementById('kpiPendingPay').textContent = kpi.payment_pending || 0;
            document.getElementById('kpiActiveTables').textContent = kpi.active_tables || 0;
            document.getElementById('kpiRevenue').textContent = formatPrice(kpi.today_revenue || 0);
            document.getElementById('kpiAvgPrep').textContent = kpi.avg_prep_time || '14m';
            document.getElementById('kpiDelayed').textContent = kpi.delayed_orders || 0;
        }

        function filterOrdersStream() {
            renderOrdersGrid();
        }

        function renderOrdersGrid() {
            const container = document.getElementById('ordersGridContainer');
            const search = document.getElementById('searchInput').value.trim().toLowerCase();

            let filtered = allOrdersData.filter(o => {
                const matchSearch = (!search || 
                    o.id.toString().includes(search) ||
                    o.table_number.toString().toLowerCase().includes(search) ||
                    (o.customer_name && o.customer_name.toLowerCase().includes(search))
                );
                return matchSearch;
            });

            if (filtered.length === 0) {
                container.innerHTML = `
                    <div class="col-span-full bg-zinc-900/80 border border-zinc-800 rounded-3xl p-12 text-center text-zinc-500">
                        <div class="text-4xl mb-2">📋</div>
                        <h4 class="font-bold text-sm text-zinc-300">No orders match filter status</h4>
                        <p class="text-xs text-zinc-500 mt-1">Waiting for incoming customer tickets</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = filtered.map(o => {
                const items = o.items || [];
                const mins = o.elapsed_mins || 5;
                const isOverdue = (o.is_delayed == 1);

                let cardBorder = 'border-zinc-800';
                let statusBadge = '<span class="px-2 py-0.5 rounded-full bg-blue-500/10 border border-blue-500/30 text-blue-400 font-extrabold text-[10px]">NEW</span>';

                if (o.status === 'preparing') {
                    cardBorder = 'border-amber-500/50 bg-amber-500/5';
                    statusBadge = '<span class="px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 font-extrabold text-[10px]">PREPARING</span>';
                } else if (o.status === 'ready') {
                    cardBorder = 'border-emerald-500/50 bg-emerald-500/5';
                    statusBadge = '<span class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-extrabold text-[10px]">READY</span>';
                } else if (o.status === 'completed') {
                    cardBorder = 'border-zinc-800 opacity-80';
                    statusBadge = '<span class="px-2 py-0.5 rounded-full bg-zinc-800 text-zinc-400 font-extrabold text-[10px]">SERVED</span>';
                } else if (o.status === 'cancelled') {
                    cardBorder = 'border-rose-500/30 opacity-70';
                    statusBadge = '<span class="px-2 py-0.5 rounded-full bg-rose-500/10 text-rose-400 font-extrabold text-[10px]">CANCELLED</span>';
                }

                if (isOverdue) {
                    cardBorder = 'border-rose-500/80 bg-rose-500/5 overdue-flash';
                }

                return `
                    <div onclick="openOrderDrawer(${o.id})" class="bg-zinc-900/90 border ${cardBorder} rounded-3xl p-5 space-y-3.5 cursor-pointer hover:border-amber-500/80 transition-all shadow-xl relative group">
                        
                        ${isOverdue ? `<div class="absolute -top-2.5 right-4 bg-rose-600 text-white font-black text-[10px] px-2.5 py-0.5 rounded-full shadow-lg">🚨 OVERDUE: Est 15m / Act ${mins}m</div>` : ''}

                        <!-- Ticket Header -->
                        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                            <div>
                                <div class="font-black text-base text-white">Order #${o.id} • Table ${o.table_number}</div>
                                <div class="text-[11px] text-zinc-400 font-medium">⏱ ${mins}m ago ${o.customer_name ? '• ' + o.customer_name : ''}</div>
                            </div>
                            ${statusBadge}
                        </div>

                        <!-- Item Lines Preview -->
                        <div class="space-y-1.5 text-xs">
                            ${items.slice(0, 3).map(i => `
                                <div class="flex justify-between items-center text-zinc-200">
                                    <span><strong class="text-amber-400">${i.quantity}x</strong> ${i.item_name}</span>
                                    <span class="font-bold text-zinc-400">${formatPrice(i.price * i.quantity)}</span>
                                </div>
                            `).join('')}
                            ${items.length > 3 ? `<div class="text-[10px] text-zinc-500 italic">+${items.length - 3} more items...</div>` : ''}
                        </div>

                        ${o.notes ? `<div class="bg-amber-500/10 border border-amber-500/20 p-2.5 rounded-xl text-xs text-amber-300 truncate"><strong>📝 Notes:</strong> ${o.notes}</div>` : ''}

                        <!-- Action Bar Footer -->
                        <div class="flex items-center justify-between pt-2 border-t border-zinc-800">
                            <span class="font-black text-base text-amber-400">${formatPrice(o.total_amount)}</span>
                            <div class="flex items-center gap-1.5">
                                <button onclick="event.stopPropagation(); updateOrderStatus(${o.id}, 'preparing')" class="px-2.5 py-1.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-400 font-bold text-xs hover:bg-amber-500 hover:text-zinc-950">Prep</button>
                                <button onclick="event.stopPropagation(); updateOrderStatus(${o.id}, 'ready')" class="px-2.5 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-bold text-xs hover:bg-emerald-500 hover:text-zinc-950">Ready</button>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function openOrderDrawer(orderId) {
            activeSelectedOrderId = orderId;
            const o = allOrdersData.find(x => x.id == orderId);
            if (!o) return;

            document.getElementById('drawerOrderBadge').textContent = '#' + o.id;
            document.getElementById('drawerOrderTitle').textContent = 'Order #' + o.id;
            document.getElementById('drawerOrderSubtitle').textContent = 'Table ' + o.table_number + (o.customer_name ? ' • Guest: ' + o.customer_name : '');
            document.getElementById('drawerStatusText').textContent = o.status.toUpperCase();
            document.getElementById('drawerElapsedText').textContent = '⏱ ' + (o.elapsed_mins || 5) + 'm ago';
            document.getElementById('drawerPaymentStatusText').textContent = o.payment_status ? o.payment_status.toUpperCase() : 'PENDING';

            const itemsContainer = document.getElementById('drawerLinesList');
            if (o.items && o.items.length > 0) {
                itemsContainer.innerHTML = o.items.map(i => `
                    <div class="flex justify-between items-center bg-zinc-950 p-2.5 rounded-xl border border-zinc-800 text-xs">
                        <div>
                            <span class="font-bold text-white">${i.quantity}x ${i.item_name}</span>
                            <div class="text-[10px] text-zinc-500">Unit: ${formatPrice(i.price)}</div>
                        </div>
                        <span class="font-black text-amber-400">${formatPrice(i.price * i.quantity)}</span>
                    </div>
                `).join('');
            } else {
                itemsContainer.innerHTML = `<div class="text-center py-6 text-xs text-zinc-500 bg-zinc-950 rounded-2xl border border-zinc-800">No items loaded</div>`;
            }

            const notesBox = document.getElementById('drawerNotesBox');
            if (o.notes) {
                document.getElementById('drawerNotesText').textContent = o.notes;
                notesBox.classList.remove('hidden');
            } else {
                notesBox.classList.add('hidden');
            }

            const total = floatval(o.total_amount || 0);
            document.getElementById('drawerSubtotalAmount').textContent = formatPrice(total);
            document.getElementById('drawerTotalAmount').textContent = formatPrice(total);

            document.getElementById('orderDrawer').classList.remove('translate-x-full');
        }

        function closeOrderDrawer() {
            document.getElementById('orderDrawer').classList.add('translate-x-full');
        }

        function updateOrderStatus(orderId, status) {
            fetch('../api/update-order.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?php echo CSRF::generateToken(); ?>'
                },
                body: JSON.stringify({ 
                    csrf_token: '<?php echo CSRF::generateToken(); ?>',
                    order_id: orderId, 
                    status: status 
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(`Order #${orderId} updated to ${status}`, 'success');
                    refreshOrdersStream();
                } else {
                    showToast(data.message || 'Error updating order status', 'warning');
                }
            });
        }

        function updateOrderStatusFromDrawer(status) {
            if (activeSelectedOrderId) {
                updateOrderStatus(activeSelectedOrderId, status);
                closeOrderDrawer();
            }
        }

        function floatval(val) {
            return parseFloat(val) || 0;
        }

        // Initialize Realtime Polling Stream (Every 3 seconds)
        document.addEventListener('DOMContentLoaded', () => {
            refreshOrdersStream();
            setInterval(refreshOrdersStream, 3000);
        });
    </script>
</body>
</html>
