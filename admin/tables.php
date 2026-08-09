<?php
// admin/tables.php - Enterprise POS Restaurant Floor & Table Operations Dashboard
require_once '../config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) {
    die("Database connection error");
}

$tenantId = (int)($_SESSION['restaurant_id'] ?? 0);

// Get tax settings for bill calculation
$settings_res = $conn->query("SELECT tax_enabled, tax_percentage, service_charge_enabled, service_charge_type, service_charge_amount FROM payment_settings WHERE restaurant_id = $tenantId LIMIT 1");
$settings = $settings_res ? $settings_res->fetch_assoc() : [];
$vatPercent = floatval($settings['tax_percentage'] ?? 13.00);
$scPercent = !empty($settings['service_charge_enabled']) ? floatval($settings['service_charge_amount'] ?? 10.00) : 0.00;
$scType = $settings['service_charge_type'] ?? 'percent';
$taxEnabled = !empty($settings['tax_enabled']);
$scEnabled = !empty($settings['service_charge_enabled']);

// Handle POST Form Submissions (Add, Edit, Reserve, Status Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    CSRF::requireValidToken();

    $action = $_POST['action'];

    if ($action === 'add') {
        $table_number = Security::sanitize($_POST['table_number'] ?? '');
        $zone = Security::sanitize($_POST['zone'] ?? 'Ground Floor');
        $capacity = intval($_POST['capacity'] ?? 4);
        $assigned_waiter = Security::sanitize($_POST['assigned_waiter'] ?? 'Unassigned');
        $qr_token = bin2hex(random_bytes(16));

        if (!empty($table_number)) {
            $stmt = $conn->prepare("INSERT INTO tables (restaurant_id, table_number, zone, capacity, assigned_waiter, status, qr_token) VALUES (?, ?, ?, ?, ?, 'vacant', ?)");
            if ($stmt) {
                $stmt->bind_param("isiss" . "s", $tenantId, $table_number, $zone, $capacity, $assigned_waiter, $qr_token);
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Table '$table_number' added to $zone!";
                } else {
                    $_SESSION['error'] = "Table number '$table_number' already exists.";
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'update_status') {
        $id = intval($_POST['id'] ?? 0);
        $status = Security::sanitize($_POST['status'] ?? 'vacant');
        if ($id > 0 && in_array($status, ['vacant', 'occupied', 'reserved', 'cleaning', 'disabled'])) {
            $stmt = $conn->prepare("UPDATE tables SET status = ? WHERE id = ? AND restaurant_id = ?");
            $stmt->bind_param("sii", $status, $id, $tenantId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['success'] = "Table status updated to " . ucfirst($status);
        }
    } elseif ($action === 'reserve') {
        $id = intval($_POST['id'] ?? 0);
        $reserved_by = Security::sanitize($_POST['reserved_by'] ?? 'Guest');
        $guest_count = intval($_POST['guest_count'] ?? 2);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE tables SET status = 'reserved', reserved_by = ?, guest_count = ? WHERE id = ? AND restaurant_id = ?");
            $stmt->bind_param("siii", $reserved_by, $guest_count, $id, $tenantId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['success'] = "Table reserved for $reserved_by ($guest_count guests)";
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM tables WHERE id = ? AND restaurant_id = ?");
            $stmt->bind_param("ii", $id, $tenantId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['success'] = "Table deleted successfully";
        }
    }

    header('Location: tables.php');
    exit;
}

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uri_dir = dirname($_SERVER['REQUEST_URI'] ?? '');
$base_url = $scheme . $host . str_replace('/admin', '', $uri_dir);
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>POS Table & Seating Operations - QR Cafe</title>
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
        
        @keyframes pulseGlow {
            0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(245, 158, 11, 0); }
            100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        }
        .pulse-alert { animation: pulseGlow 1.5s infinite; }
    </style>
</head>
<body class="min-h-full pb-20 md:pb-8 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <!-- DESKTOP SIDEBAR NAVIGATION -->
    <?php $currentPage = 'tables'; include 'includes/sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="md:pl-64 min-h-screen">

        <!-- HEADER BAR -->
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg md:text-xl font-black text-white">Restaurant Floor Operations</h1>
                        <span class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black uppercase tracking-wider">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span> Live POS
                        </span>
                    </div>
                    <p class="text-xs text-zinc-400 hidden sm:block">Realtime Table Status, Seating Capacity, POS Orders & Waiter Calls</p>
                </div>

                <!-- Action Controls -->
                <div class="flex items-center gap-2 shrink-0">
                    <button onclick="openAddTableModal()" class="h-10 px-4 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs flex items-center gap-1.5 active:scale-95 shadow-lg shadow-amber-500/20">
                        <span>➕</span> Add Table
                    </button>
                    <button onclick="refreshDashboardStream()" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
                        🔄 Refresh
                    </button>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 space-y-6">

            <!-- NOTIFICATION ALERTS & MESSAGES -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold flex items-center justify-between">
                    <span>✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-zinc-400 hover:text-white">✕</button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold flex items-center justify-between">
                    <span>⚠️ <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-zinc-400 hover:text-white">✕</button>
                </div>
            <?php endif; ?>

            <!-- 1. KPI CARDS METRICS BAR (10 METRICS) -->
            <section class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 lg:grid-cols-10 gap-3">
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🟢 Vacant</span>
                    <div id="kpiVacant" class="text-lg font-black text-emerald-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🔴 Occupied</span>
                    <div id="kpiOccupied" class="text-lg font-black text-rose-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🟡 Reserved</span>
                    <div id="kpiReserved" class="text-lg font-black text-amber-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">⚫ Cleaning</span>
                    <div id="kpiCleaning" class="text-lg font-black text-zinc-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🟣 Pending</span>
                    <div id="kpiPending" class="text-lg font-black text-purple-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🍽 Orders</span>
                    <div id="kpiActiveOrders" class="text-lg font-black text-white">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">👥 Guests</span>
                    <div id="kpiGuests" class="text-lg font-black text-white">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">💰 Revenue</span>
                    <div id="kpiRevenue" class="text-sm font-black text-emerald-400 truncate">Rs.0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">📈 Avg Dining</span>
                    <div id="kpiDiningTime" class="text-xs font-black text-amber-400">32m</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">⏱ Avg Prep</span>
                    <div id="kpiPrepTime" class="text-xs font-black text-blue-400">14m</div>
                </div>
            </section>

            <!-- 2. SEARCH & FILTER CONTROLS BAR -->
            <section class="bg-zinc-900/90 border border-zinc-800/80 rounded-3xl p-4 shadow-xl space-y-3">
                <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                    
                    <!-- Search Field -->
                    <div class="relative flex-1">
                        <span class="absolute left-3.5 top-3 text-zinc-500 text-xs">🔍</span>
                        <input type="text" id="searchInput" oninput="filterTableCards()" placeholder="Search Table #, Customer Name, Order ID..." class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl pl-9 pr-4 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 font-medium">
                    </div>

                    <!-- Floor Zone Filter Tabs -->
                    <div class="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-1">
                        <button onclick="setZoneFilter('all')" id="zoneTabAll" class="px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md">All Zones</button>
                        <button onclick="setZoneFilter('Ground Floor')" id="zoneTabGround" class="px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">Ground Floor</button>
                        <button onclick="setZoneFilter('First Floor')" id="zoneTabFirst" class="px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">First Floor</button>
                        <button onclick="setZoneFilter('Outdoor Patio')" id="zoneTabOutdoor" class="px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">Outdoor Patio</button>
                        <button onclick="setZoneFilter('VIP Lounge')" id="zoneTabVIP" class="px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">VIP Lounge</button>
                    </div>
                </div>

                <!-- Status Filter Pills -->
                <div class="flex items-center gap-2 overflow-x-auto no-scrollbar pt-1 border-t border-zinc-800/80">
                    <span class="text-[11px] font-bold text-zinc-500 shrink-0">Filter Status:</span>
                    <button onclick="setStatusFilter('all')" class="status-pill px-3 py-1 rounded-full text-xs font-bold bg-zinc-800 text-white" data-status="all">All</button>
                    <button onclick="setStatusFilter('vacant')" class="status-pill px-3 py-1 rounded-full text-xs font-bold bg-zinc-950 text-emerald-400 border border-emerald-500/30" data-status="vacant">🟢 Vacant</button>
                    <button onclick="setStatusFilter('occupied')" class="status-pill px-3 py-1 rounded-full text-xs font-bold bg-zinc-950 text-rose-400 border border-rose-500/30" data-status="occupied">🔴 Occupied</button>
                    <button onclick="setStatusFilter('reserved')" class="status-pill px-3 py-1 rounded-full text-xs font-bold bg-zinc-950 text-amber-400 border border-amber-500/30" data-status="reserved">🟡 Reserved</button>
                    <button onclick="setStatusFilter('cleaning')" class="status-pill px-3 py-1 rounded-full text-xs font-bold bg-zinc-950 text-zinc-400 border border-zinc-700" data-status="cleaning">⚫ Cleaning</button>
                    <button onclick="setStatusFilter('payment_pending')" class="status-pill px-3 py-1 rounded-full text-xs font-bold bg-zinc-950 text-purple-400 border border-purple-500/30" data-status="payment_pending">🟣 Payment Pending</button>
                </div>
            </section>

            <!-- 3. INTERACTIVE RESTAURANT FLOOR PLAN GRID -->
            <section class="space-y-4">
                
                <!-- Floor Map Layout Demarcation Header -->
                <div class="flex items-center justify-between bg-zinc-900/60 border border-zinc-800/80 rounded-2xl px-4 py-2.5 text-xs text-zinc-400">
                    <div class="flex items-center gap-2">
                        <span>🚪 ENTRANCE</span>
                        <span class="text-zinc-600">─────────────────────</span>
                    </div>
                    <div class="font-extrabold text-amber-400 flex items-center gap-1.5">
                        <span>🍽 FLOOR SEATING MATRIX</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-zinc-600">─────────────────────</span>
                        <span>👨‍🍳 KITCHEN PASS</span>
                    </div>
                </div>

                <!-- Table Grid Container -->
                <div id="tableGridContainer" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <!-- Dynamic Table Cards Injected Here by Realtime Stream -->
                    <div class="col-span-full py-12 text-center text-zinc-500">
                        <div class="text-4xl mb-2 animate-bounce">⏳</div>
                        <p class="font-bold text-xs">Loading Realtime Floor Layout...</p>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- 4. RIGHT SLIDE-OVER DRAWER (TABLE DETAILS & POS WORKFLOW) -->
    <div id="tableDrawer" class="fixed inset-y-0 right-0 z-50 w-full max-w-md bg-zinc-900 border-l border-zinc-800 shadow-2xl transform translate-x-full transition-transform duration-300 flex flex-col">
        
        <!-- Drawer Header -->
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between bg-zinc-950/80">
            <div class="flex items-center gap-3">
                <div id="drawerTableBadge" class="w-12 h-12 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center font-black text-amber-400 text-lg">T1</div>
                <div>
                    <h3 id="drawerTableName" class="font-black text-white text-base">Table 1 Operations</h3>
                    <p id="drawerTableZone" class="text-xs text-zinc-400">Ground Floor • 4 Seats</p>
                </div>
            </div>
            <button onclick="closeTableDrawer()" class="w-9 h-9 rounded-xl bg-zinc-800 text-zinc-400 hover:text-white font-bold flex items-center justify-center">✕</button>
        </div>

        <!-- Drawer Scrollable Body -->
        <div class="flex-1 overflow-y-auto p-5 space-y-6">
            
            <!-- Table Status & Action Pills -->
            <div class="flex items-center justify-between bg-zinc-950 p-3 rounded-2xl border border-zinc-800/80">
                <div>
                    <span class="text-[10px] font-bold text-zinc-500 uppercase">Current Status</span>
                    <div id="drawerStatusBadge" class="text-xs font-black text-emerald-400">🟢 Vacant</div>
                </div>
                <div>
                    <span class="text-[10px] font-bold text-zinc-500 uppercase">Assigned Staff</span>
                    <div id="drawerStaffName" class="text-xs font-bold text-white">Unassigned</div>
                </div>
                <div>
                    <span class="text-[10px] font-bold text-zinc-500 uppercase">Dining Time</span>
                    <div id="drawerDiningTime" class="text-xs font-bold text-amber-400">0m</div>
                </div>
            </div>

            <!-- ORDER TIMELINE STAGE -->
            <div class="space-y-2">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Order Progression Timeline</h4>
                <div class="bg-zinc-950 border border-zinc-800 rounded-2xl p-4 space-y-2">
                    <div class="flex items-center gap-3 text-xs">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 font-bold flex items-center justify-center text-[10px]">✓</span>
                        <span class="font-bold text-white">Customer Seated</span>
                        <span id="timelineSeatedTime" class="text-[10px] text-zinc-500 ml-auto">Just now</span>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <span id="timelineOrderIcon" class="w-5 h-5 rounded-full bg-zinc-800 text-zinc-500 font-bold flex items-center justify-center text-[10px]">2</span>
                        <span id="timelineOrderText" class="font-medium text-zinc-400">Order Placed</span>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <span id="timelinePrepIcon" class="w-5 h-5 rounded-full bg-zinc-800 text-zinc-500 font-bold flex items-center justify-center text-[10px]">3</span>
                        <span id="timelinePrepText" class="font-medium text-zinc-400">Kitchen Preparing</span>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <span id="timelineReadyIcon" class="w-5 h-5 rounded-full bg-zinc-800 text-zinc-500 font-bold flex items-center justify-center text-[10px]">4</span>
                        <span id="timelineReadyText" class="font-medium text-zinc-400">Served & Dining</span>
                    </div>
                </div>
            </div>

            <!-- ACTIVE ORDER ITEMIZED ITEMS -->
            <div class="space-y-2">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Current Itemized Order</h4>
                <div id="drawerItemsList" class="space-y-2">
                    <div class="text-center py-6 text-xs text-zinc-500 bg-zinc-950 rounded-2xl border border-zinc-800">
                        No active order for this table
                    </div>
                </div>
            </div>

            <!-- BILL SUMMARY -->
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl p-4 space-y-2">
                <div class="flex justify-between text-xs text-zinc-400">
                    <span>Subtotal</span>
                    <span id="drawerSubtotal">Rs.0</span>
                </div>
                <div class="flex justify-between text-xs text-zinc-400" id="drawerServiceChargeRow">
                    <span>Service Charge (<?= $scPercent ?>%)</span>
                    <span id="drawerServiceCharge">Rs.0</span>
                </div>
                <div class="flex justify-between text-xs text-zinc-400" id="drawerTaxRow">
                    <span>VAT (<?= $vatPercent ?>%)</span>
                    <span id="drawerTax">Rs.0</span>
                </div>
                <div class="flex justify-between text-xs text-zinc-400" id="drawerDiscountRow" style="display: none;">
                    <span>Discount</span>
                    <span id="drawerDiscount">Rs.0</span>
                </div>
                <div class="flex justify-between text-sm font-black text-white pt-2 border-t border-zinc-800">
                    <span>Total Amount Due</span>
                    <span id="drawerTotalAmount" class="text-amber-400">Rs.0</span>
                </div>
            </div>

            <!-- QUICK WORKFLOW ACTION BUTTONS -->
            <div class="grid grid-cols-2 gap-2">
                <button onclick="triggerQuickPayment()" class="h-11 rounded-2xl bg-emerald-500 text-zinc-950 font-black text-xs flex items-center justify-center gap-1.5 shadow-lg shadow-emerald-500/20 active:scale-95">
                    💳 Settle & Bill
                </button>
                <button onclick="openTableQRModalFromDrawer()" class="h-11 rounded-2xl bg-zinc-800 text-white font-bold text-xs flex items-center justify-center gap-1.5 active:scale-95 hover:border-amber-500/40">
                    📱 View Table QR
                </button>
                <button onclick="updateSelectedTableStatus('cleaning')" class="h-11 rounded-2xl bg-zinc-950 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
                    🧹 Mark Cleaning
                </button>
                <button onclick="updateSelectedTableStatus('vacant')" class="h-11 rounded-2xl bg-zinc-950 border border-zinc-800 text-emerald-400 font-bold text-xs hover:border-emerald-500/40">
                    🟢 Mark Vacant
                </button>
            </div>

        </div>

        <!-- Drawer Footer -->
        <div class="p-4 border-t border-zinc-800 bg-zinc-950">
            <button onclick="closeTableDrawer()" class="w-full h-11 rounded-2xl bg-zinc-800 font-bold text-xs text-zinc-300">Close Panel</button>
        </div>
    </div>

    <!-- 5. TABLE QR CODE MODAL -->
    <div id="qrModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 text-center max-w-sm w-full shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 id="qrModalTitle" class="font-black text-white text-base">Table 1 QR Code</h3>
                <button onclick="closeQRModal()" class="text-zinc-400 hover:text-white font-bold text-lg">✕</button>
            </div>

            <div class="p-4 bg-white rounded-2xl inline-block shadow-inner">
                <img id="qrModalImg" src="" alt="Table QR Code" class="w-48 h-48 mx-auto">
            </div>

            <!-- Customer Destination URL Box -->
            <div class="bg-zinc-950 p-2.5 rounded-2xl border border-zinc-800 text-left">
                <span class="text-[10px] font-bold text-zinc-500 uppercase block mb-0.5">Customer Menu Ordering URL:</span>
                <div id="qrModalCustomerUrl" class="text-xs font-mono font-bold text-amber-400 truncate selection:bg-amber-500 selection:text-zinc-950">http://localhost/RMS_System/menu.php?token=...</div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <button onclick="copyCustomerUrl()" class="h-10 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs flex items-center justify-center gap-1 active:scale-95 shadow-md">
                    📋 Copy Table URL
                </button>
                <button onclick="openCustomerMenu()" class="h-10 rounded-2xl bg-zinc-800 border border-zinc-700 text-white font-bold text-xs flex items-center justify-center gap-1 hover:border-amber-500">
                    🌐 Open Menu
                </button>
            </div>

            <div class="grid grid-cols-3 gap-2 pt-1 border-t border-zinc-800/80">
                <a id="qrDownloadLink" download="table_qr.png" target="_blank" class="h-9 rounded-xl bg-zinc-800 text-zinc-300 font-bold text-[11px] flex items-center justify-center gap-1 hover:text-white">
                    ⬇️ Download
                </a>
                <button onclick="printQrCode()" class="h-9 rounded-xl bg-zinc-800 text-zinc-300 font-bold text-[11px] flex items-center justify-center gap-1 hover:text-white">
                    🖨️ Print
                </button>
                <button onclick="shareCustomerUrl()" class="h-9 rounded-xl bg-zinc-800 text-zinc-300 font-bold text-[11px] flex items-center justify-center gap-1 hover:text-white">
                    🔗 Share
                </button>
            </div>
        </div>
    </div>

    <!-- 6. ADD TABLE MODAL -->
    <div id="addTableModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full shadow-2xl space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="font-black text-white text-base">➕ Add New Dining Table</h3>
                <button onclick="closeAddTableModal()" class="text-zinc-400 hover:text-white font-bold">✕</button>
            </div>

            <form method="POST" action="tables.php" class="space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="add">

                <div>
                    <label class="block text-xs font-bold text-zinc-300 mb-1">Table Number / Identifier</label>
                    <input type="text" name="table_number" required placeholder="e.g. 7 or T-VIP-1" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-zinc-300 mb-1">Floor Zone / Location</label>
                    <select name="zone" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500 font-bold">
                        <option value="Ground Floor">Ground Floor</option>
                        <option value="First Floor">First Floor</option>
                        <option value="Outdoor Patio">Outdoor Patio</option>
                        <option value="VIP Lounge">VIP Lounge</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-zinc-300 mb-1">Seating Capacity</label>
                    <input type="number" name="capacity" value="4" min="1" max="20" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-zinc-300 mb-1">Assigned Waiter / Server</label>
                    <input type="text" name="assigned_waiter" placeholder="e.g. Rahul S. or Unassigned" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="closeAddTableModal()" class="w-1/3 h-11 rounded-2xl bg-zinc-800 font-bold text-xs text-zinc-300">Cancel</button>
                    <button type="submit" class="w-2/3 h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">Save Table</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MOBILE BOTTOM NAVIGATION -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 max-w-md mx-auto bg-zinc-950/95 backdrop-blur-xl border-t border-zinc-800/80 flex justify-around items-center h-16 rounded-t-2xl px-2">
        <a href="index.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📊</span>
            <span>Summary</span>
        </a>
        <a href="tables.php" class="flex flex-col items-center gap-0.5 text-amber-500 font-black text-[10px]">
            <span class="text-lg">📍</span>
            <span>Tables</span>
        </a>
        <a href="orders.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📋</span>
            <span>Orders</span>
        </a>
        <a href="menu-items.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">🍔</span>
            <span>Menu</span>
        </a>
    </nav>

    <!-- REALTIME POS DASHBOARD SCRIPT -->
    <script src="../js/modern.js"></script>
    <script>
        let allTablesData = [];
        let selectedTableNumber = null;
        let selectedZone = 'all';
        let selectedStatus = 'all';
        let currentQRUrl = '';

        function setZoneFilter(zone) {
            selectedZone = zone;
            document.querySelectorAll('#zoneTabAll, #zoneTabGround, #zoneTabFirst, #zoneTabOutdoor, #zoneTabVIP').forEach(btn => {
                btn.className = 'px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white';
            });
            if (zone === 'all') document.getElementById('zoneTabAll').className = 'px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md';
            if (zone === 'Ground Floor') document.getElementById('zoneTabGround').className = 'px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md';
            if (zone === 'First Floor') document.getElementById('zoneTabFirst').className = 'px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md';
            if (zone === 'Outdoor Patio') document.getElementById('zoneTabOutdoor').className = 'px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md';
            if (zone === 'VIP Lounge') document.getElementById('zoneTabVIP').className = 'px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md';
            
            renderTablesGrid();
        }

        function setStatusFilter(status) {
            selectedStatus = status;
            document.querySelectorAll('.status-pill').forEach(btn => {
                if (btn.dataset.status === status) {
                    btn.classList.add('ring-2', 'ring-amber-500');
                } else {
                    btn.classList.remove('ring-2', 'ring-amber-500');
                }
            });
            renderTablesGrid();
        }

        let isStreamLoading = false;

        function fetchFloorStream(signal) {
            const path = window.location.pathname;
            const adminIdx = path.indexOf('/admin');
            const rootPath = adminIdx !== -1 ? path.substring(0, adminIdx) : '/RMS_System';
            const absoluteUrl = window.location.origin + rootPath + '/api/tables-stream.php';
            const opts = { signal, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };

            return fetch(absoluteUrl, opts).then(r => {
                if (!r.ok && r.status !== 401 && r.status !== 403) {
                    return fetch('../api/tables-stream.php', opts);
                }
                return r;
            }).catch(() => fetch('../api/tables-stream.php', opts));
        }

        function refreshDashboardStream() {
            if (isStreamLoading) return;
            isStreamLoading = true;

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 3500);

            fetchFloorStream(controller.signal)
                .then(r => {
                    clearTimeout(timeoutId);
                    if (r.status === 401 || r.status === 403) {
                        throw new Error('AUTH_EXPIRED');
                    }
                    if (!r.ok) {
                        throw new Error('HTTP_' + r.status);
                    }
                    return r.json();
                })
                .then(data => {
                    isStreamLoading = false;
                    if (data.success) {
                        updateKPICards(data.kpi);
                        allTablesData = data.tables || [];
                        try {
                            localStorage.setItem('cached_tables_data', JSON.stringify(allTablesData));
                        } catch(e) {}
                        renderTablesGrid();
                    } else {
                        handleFloorStreamError(data.message || 'Server error loading floor data');
                    }
                })
                .catch(err => {
                    isStreamLoading = false;
                    clearTimeout(timeoutId);
                    if (err.name === 'AbortError') {
                        handleFloorStreamError('Network request timed out (3.5s)');
                    } else if (err.message === 'AUTH_EXPIRED') {
                        handleFloorStreamError('Session expired. Staff authentication required.', true);
                    } else {
                        handleFloorStreamError(err.message || 'Unable to connect to floor stream server');
                    }
                });
        }

        function handleFloorStreamError(msg, isAuthError = false) {
            const container = document.getElementById('tableGridContainer');
            
            // Check if cached data exists for offline/network fallback
            let cached = localStorage.getItem('cached_tables_data');
            if (!isAuthError && cached) {
                try {
                    allTablesData = JSON.parse(cached);
                    if (allTablesData.length > 0) {
                        renderTablesGrid();
                        showToast('Offline Mode: Displaying cached floor layout', 'warning');
                        return;
                    }
                } catch(e) {}
            }

            if (isAuthError) {
                container.innerHTML = `
                    <div class="col-span-full bg-rose-500/10 border border-rose-500/30 rounded-3xl p-8 text-center space-y-3">
                        <div class="text-4xl mb-1">🔒</div>
                        <h4 class="font-black text-base text-rose-400">Session Expired</h4>
                        <p class="text-xs text-zinc-400 max-w-md mx-auto">${msg}</p>
                        <div class="pt-2">
                            <a href="../login.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 shadow-lg">
                                🔑 Login to Staff Portal
                            </a>
                        </div>
                    </div>
                `;
            } else {
                container.innerHTML = `
                    <div class="col-span-full bg-zinc-900 border border-amber-500/30 rounded-3xl p-8 text-center space-y-3">
                        <div class="text-4xl mb-1">⚠️</div>
                        <h4 class="font-black text-base text-white">Unable to Load Floor Layout</h4>
                        <p class="text-xs text-zinc-400 max-w-md mx-auto">${msg}</p>
                        <div class="pt-2">
                            <button onclick="refreshDashboardStream()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 shadow-lg">
                                🔄 Retry Floor Layout
                            </button>
                        </div>
                    </div>
                `;
            }
        }

        function updateKPICards(kpi) {
            if (!kpi) return;
            document.getElementById('kpiVacant').textContent = kpi.vacant || 0;
            document.getElementById('kpiOccupied').textContent = kpi.occupied || 0;
            document.getElementById('kpiReserved').textContent = kpi.reserved || 0;
            document.getElementById('kpiCleaning').textContent = kpi.cleaning || 0;
            document.getElementById('kpiPending').textContent = kpi.payment_pending || 0;
            document.getElementById('kpiActiveOrders').textContent = kpi.active_orders || 0;
            document.getElementById('kpiGuests').textContent = kpi.active_guests || 0;
            document.getElementById('kpiRevenue').textContent = formatPrice(kpi.today_revenue || 0);
            document.getElementById('kpiDiningTime').textContent = kpi.avg_dining_time || '32m';
            document.getElementById('kpiPrepTime').textContent = kpi.avg_prep_time || '14m';
        }

        function filterTableCards() {
            renderTablesGrid();
        }

        function renderTablesGrid() {
            const container = document.getElementById('tableGridContainer');
            const search = document.getElementById('searchInput').value.trim().toLowerCase();

            let filtered = allTablesData.filter(t => {
                const matchZone = (selectedZone === 'all' || t.zone === selectedZone);
                let matchStatus = (selectedStatus === 'all');
                if (!matchStatus) {
                    if (selectedStatus === 'occupied') matchStatus = ['seated','ordering','preparing','dining','payment_pending'].includes(t.computed_status);
                    else matchStatus = (t.computed_status === selectedStatus);
                }
                const matchSearch = (!search || 
                    t.table_number.toString().toLowerCase().includes(search) ||
                    (t.assigned_waiter && t.assigned_waiter.toLowerCase().includes(search)) ||
                    (t.customer_name && t.customer_name.toLowerCase().includes(search)) ||
                    (t.active_order && (t.active_order.customer_name || '').toLowerCase().includes(search)) ||
                    (t.active_order && t.active_order.id.toString().includes(search))
                );
                return matchZone && matchStatus && matchSearch;
            });

            if (filtered.length === 0) {
                container.innerHTML = `
                    <div class="col-span-full bg-zinc-900/80 border border-zinc-800 rounded-3xl p-12 text-center text-zinc-500">
                        <div class="text-4xl mb-2">📍</div>
                        <h4 class="font-bold text-sm text-zinc-300">No tables match active filters</h4>
                        <p class="text-xs text-zinc-500 mt-1">Try resetting zone or status filters</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = filtered.map(t => {
                try {
                    const st = (t && (t.computed_status || t.status)) ? String(t.computed_status || t.status).toLowerCase() : 'vacant';
                    let cardBorder = 'border-zinc-800';
                    let statusBadge = '<span class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-extrabold text-[10px]">🟢 Vacant</span>';

                    if (st === 'payment_pending') {
                        cardBorder = 'border-rose-500/60 bg-rose-500/10 pulse-alert';
                        statusBadge = '<span class="px-2 py-0.5 rounded-full bg-rose-500/20 border border-rose-500/40 text-rose-300 font-extrabold text-[10px]">🔴 Waiting Bill</span>';
                    } else if (st === 'dining') {
                        cardBorder = 'border-purple-500/50 bg-purple-500/5';
                        statusBadge = '<span class="px-2 py-0.5 rounded-full bg-purple-500/10 border border-purple-500/30 text-purple-300 font-extrabold text-[10px]">🟣 Dining</span>';
                    } else if (st === 'preparing') {
                        cardBorder = 'border-amber-500/50 bg-amber-500/5';
                        statusBadge = '<span class="px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 font-extrabold text-[10px]">🟠 Preparing</span>';
                    } else if (st === 'ordering') {
                        cardBorder = 'border-yellow-500/50 bg-yellow-500/5';
                        statusBadge = '<span class="px-2 py-0.5 rounded-full bg-yellow-500/10 border border-yellow-500/30 text-yellow-400 font-extrabold text-[10px]">🟡 Ordering</span>';
                    } else if (st === 'seated') {
                        cardBorder = 'border-blue-500/50 bg-blue-500/5';
                        statusBadge = '<span class="px-2 py-0.5 rounded-full bg-blue-500/10 border border-blue-500/30 text-blue-400 font-extrabold text-[10px]">🔵 Seated</span>';
                    } else if (st === 'reserved') {
                        cardBorder = 'border-zinc-700 bg-zinc-900/40';
                        statusBadge = '<span class="px-2 py-0.5 rounded-full bg-zinc-800 text-zinc-400 font-extrabold text-[10px]">⚫ Reserved</span>';
                    } else if (st === 'cleaning') {
                        cardBorder = 'border-zinc-700 bg-zinc-900/40';
                        statusBadge = '<span class="px-2 py-0.5 rounded-full bg-zinc-800 text-zinc-400 font-extrabold text-[10px]">⚪ Cleaning</span>';
                    }

                    const isOccupied = ['seated', 'ordering', 'preparing', 'dining', 'payment_pending'].includes(st);
                    const isReserved = (st === 'reserved');
                    const tableIcon = isOccupied ? '🍽️' : (isReserved ? '📅' : (st === 'cleaning' ? '🧹' : '🛋️'));

                    const totalBill = t.running_total ? formatPrice(t.running_total) : (t.active_order ? formatPrice(t.active_order.total_amount) : 'Rs.0');
                    const diningMins = t.active_order ? (t.active_order.dining_minutes || 10) + 'm' : '0m';

                    return `
                        <div onclick="openTableDrawer('${t.table_number}')" class="bg-zinc-900/90 border ${cardBorder} rounded-3xl p-4 space-y-3 cursor-pointer hover:border-amber-500/80 transition-all shadow-xl relative group">
                            
                            ${t.waiter_called ? `<div class="absolute -top-2 -right-2 bg-rose-500 text-white text-[10px] font-black px-2 py-1 rounded-full shadow-lg pulse-alert">🔔 WAITER</div>` : ''}

                            <!-- Header -->
                            <div class="flex items-center justify-between border-b border-zinc-800/80 pb-2.5">
                                <div class="flex items-center gap-2">
                                    <div class="text-xl">${tableIcon}</div>
                                    <div>
                                        <div class="font-black text-sm text-white">Table ${t.table_number}</div>
                                        <div class="text-[10px] text-zinc-400 font-medium">${t.zone || 'Ground Floor'}</div>
                                    </div>
                                </div>
                                ${statusBadge}
                            </div>

                            <!-- Info Grid -->
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div class="bg-zinc-950/60 rounded-xl p-2 border border-zinc-800/40">
                                    <span class="text-[10px] font-bold text-zinc-500 block">Capacity</span>
                                    <span class="font-extrabold text-white">👥 ${t.capacity || 4} Seats</span>
                                </div>
                                <div class="bg-zinc-950/60 rounded-xl p-2 border border-zinc-800/40">
                                    <span class="text-[10px] font-bold text-zinc-500 block">Current Bill</span>
                                    <span class="font-black text-amber-400">${totalBill}</span>
                                </div>
                            </div>

                            <!-- Sub-Footer -->
                            <div class="flex items-center justify-between text-[11px] text-zinc-400 pt-1">
                                <span>⏱ ${diningMins} seated</span>
                                <span class="font-bold text-zinc-300">👨‍🍳 ${t.assigned_waiter || 'Staff'}</span>
                            </div>

                            <!-- Hover Action Bar -->
                            <div class="pt-2 border-t border-zinc-800/60 flex items-center justify-between text-[11px]">
                                <span class="text-amber-400 font-bold group-hover:underline">Click for POS Drawer →</span>
                                <button onclick="event.stopPropagation(); showTableQRModal('${t.table_number}', '${t.qr_token || ''}')" class="px-2 py-1 rounded-lg bg-zinc-800 text-white font-bold hover:bg-amber-500 hover:text-zinc-950">📱 QR</button>
                            </div>
                        </div>
                    `;
                } catch(err) {
                    console.warn('Error rendering table card:', err, t);
                    return '';
                }
            }).join('');
        }

        function openTableDrawer(tableNum) {
            selectedTableNumber = tableNum;
            const t = allTablesData.find(x => x.table_number.toString() === tableNum.toString());
            if (!t) return;

            document.getElementById('drawerTableBadge').textContent = 'T' + t.table_number;
            document.getElementById('drawerTableName').textContent = 'Table ' + t.table_number + ' Operations';
            document.getElementById('drawerTableZone').textContent = t.zone + ' • ' + t.capacity + ' Seats';
            
            const st = t.computed_status || t.status || 'vacant';
            let label = '🟢 Vacant';
            if (st === 'payment_pending') label = '🔴 Waiting Bill';
            else if (st === 'dining') label = '🟣 Dining';
            else if (st === 'preparing') label = '🟠 Preparing';
            else if (st === 'ordering') label = '🟡 Ordering';
            else if (st === 'seated') label = '🔵 Seated';
            else if (st === 'reserved') label = '⚫ Reserved';
            else if (st === 'cleaning') label = '⚪ Cleaning';

            document.getElementById('drawerStatusBadge').textContent = label;
            document.getElementById('drawerStaffName').textContent = t.assigned_waiter || 'Unassigned';
            document.getElementById('drawerDiningTime').textContent = t.active_order ? (t.active_order.dining_minutes || 12) + 'm' : '0m';

            const itemsContainer = document.getElementById('drawerItemsList');
            if (t.items && t.items.length > 0) {
                itemsContainer.innerHTML = t.items.map(i => `
                    <div class="flex justify-between items-center bg-zinc-950 p-2.5 rounded-xl border border-zinc-800 text-xs">
                        <div>
                            <span class="font-bold text-white">${i.quantity}x ${i.item_name}</span>
                            <div class="text-[10px] text-zinc-500">Unit: ${formatPrice(i.price)}</div>
                        </div>
                        <span class="font-black text-amber-400">${formatPrice(i.price * i.quantity)}</span>
                    </div>
                `).join('');
            } else {
                itemsContainer.innerHTML = `<div class="text-center py-6 text-xs text-zinc-500 bg-zinc-950 rounded-2xl border border-zinc-800">No active items ordered</div>`;
            }

            const items = t.items || [];
            let subtotal = 0;
            items.forEach(i => {
                subtotal += parseFloat(i.price) * parseInt(i.quantity);
            });

            // Calculate service charge
            let serviceCharge = 0;
            if (<?= $scEnabled ? 'true' : 'false' ?>) {
                if ('<?= $scType ?>' === 'percent') {
                    serviceCharge = Math.round((subtotal * <?= $scPercent ?>) / 100 * 100) / 100;
                } else {
                    serviceCharge = <?= $scPercent ?>;
                }
            }

            // Calculate tax
            let tax = 0;
            if (<?= $taxEnabled ? 'true' : 'false' ?>) {
                const taxableBase = subtotal + serviceCharge;
                tax = Math.round((taxableBase * <?= $vatPercent ?>) / 100 * 100) / 100;
            }

            const grandTotal = Math.max(0, Math.round((subtotal + serviceCharge + tax) * 100) / 100);

            document.getElementById('drawerSubtotal').textContent = formatPrice(subtotal);
            document.getElementById('drawerServiceCharge').textContent = formatPrice(serviceCharge);
            document.getElementById('drawerTax').textContent = formatPrice(tax);
            document.getElementById('drawerTotalAmount').textContent = formatPrice(grandTotal);

            // Show/hide rows based on settings
            document.getElementById('drawerServiceChargeRow').style.display = '<?= $scEnabled ? 'flex' : 'none' ?>';
            document.getElementById('drawerTaxRow').style.display = '<?= $taxEnabled ? 'flex' : 'none' ?>';

            document.getElementById('tableDrawer').classList.remove('translate-x-full');
        }

        function closeTableDrawer() {
            document.getElementById('tableDrawer').classList.add('translate-x-full');
        }

        let currentCustomerUrl = '';
        let currentQRImageUrl = '';

        function generateQRCodeDataURL(text, size = 300) {
            return 'data:image/svg+xml;base64,' + btoa(`
                <svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
                    <rect width="${size}" height="${size}" fill="white"/>
                    <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="monospace" font-size="8" fill="black">${text.substring(0, 50)}</text>
                </svg>
            `);
        }

        function showTableQRModal(tableNum, explicitToken) {
            const t = allTablesData.find(x => x.table_number.toString() === tableNum.toString());
            let token = explicitToken || (t && t.qr_token ? t.qr_token : '');
            if (!token && t && t.qr_token) token = t.qr_token;
            
            const tableCustomerUrl = window.location.origin + window.location.pathname.replace('/admin/tables.php', '') + '/menu.php?token=' + (token || '5fd8a0fdb6e7411fb58d94c6abbe27e2');
            const qrImageUrl = generateQRCodeDataURL(tableCustomerUrl, 300);

            currentCustomerUrl = tableCustomerUrl;
            currentQRImageUrl = qrImageUrl;

            document.getElementById('qrModalTitle').textContent = 'Table ' + tableNum + ' Secure QR Code';
            document.getElementById('qrModalImg').src = qrImageUrl;
            document.getElementById('qrModalCustomerUrl').textContent = tableCustomerUrl;
            document.getElementById('qrDownloadLink').href = qrImageUrl;
            document.getElementById('qrModal').classList.remove('hidden');
        }

        function closeQRModal() {
            document.getElementById('qrModal').classList.add('hidden');
        }

        function openTableQRModalFromDrawer() {
            if (selectedTableNumber) showTableQRModal(selectedTableNumber);
        }

        function copyCustomerUrl() {
            if (currentCustomerUrl) {
                navigator.clipboard.writeText(currentCustomerUrl);
                showToast('Table Menu URL copied to clipboard!', 'success');
            }
        }

        function openCustomerMenu() {
            if (currentCustomerUrl) {
                window.open(currentCustomerUrl, '_blank');
            }
        }

        function shareCustomerUrl() {
            if (navigator.share && currentCustomerUrl) {
                navigator.share({
                    title: 'Customer Table Menu',
                    url: currentCustomerUrl
                }).catch(e => {});
            } else {
                copyCustomerUrl();
            }
        }

        function printQrCode() {
            const win = window.open('', '_blank');
            win.document.write(`
                <html>
                <head><title>Print QR Code - Table</title></head>
                <body style="text-align:center; font-family:sans-serif; padding:40px;">
                    <h2 style="margin-bottom:10px;">Scan Code to Order</h2>
                    <img src="${currentQRImageUrl}" style="width:250px; height:250px; border:1px solid #ccc; padding:10px; border-radius:15px;" />
                    <p style="font-weight:bold; font-size:18px; margin-top:15px;">Customer Table Ordering Portal</p>
                    <p style="font-size:12px; color:#666;">${currentCustomerUrl}</p>
                    <script>window.onload = function() { window.print(); window.close(); }<\/script>
                </body>
                </html>
            `);
        }

        function openAddTableModal() {
            document.getElementById('addTableModal').classList.remove('hidden');
        }

        function closeAddTableModal() {
            document.getElementById('addTableModal').classList.add('hidden');
        }

        function triggerQuickPayment() {
            if (!selectedTableNumber) return;
            const tNum = selectedTableNumber;
            const t = allTablesData.find(x => x.table_number.toString() === tNum.toString());
            
            // Find the active order for this table
            let orderId = null;
            if (t && t.active_order && t.active_order.id) {
                orderId = t.active_order.id;
            }
            
            if (orderId) {
                // Open RPOS with the existing order
                window.open(`pos.php?order_id=${orderId}`, '_blank');
            } else {
                // No active order, just open RPOS for this table
                window.open(`pos.php?table_number=${encodeURIComponent(tNum)}`, '_blank');
            }
        }

        function updateSelectedTableStatus(status) {
            if (!selectedTableNumber) return;
            const tNum = selectedTableNumber;
            const t = allTablesData.find(x => x.table_number.toString() === tNum.toString());

            showToast(`Updating Table ${tNum} status to ${status}...`, 'info');

            const formData = new FormData();
            formData.append('action', 'update_table_status');
            formData.append('table_number', tNum);
            if (t) formData.append('id', t.id);
            formData.append('status', status);

            fetch('../api/orders-stream.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(`Table ${tNum} status updated to ${status.toUpperCase()}`, 'success');
                    refreshDashboardStream();
                    openTableDrawer(tNum);
                } else {
                    showToast(data.message || 'Failed to update table status', 'error');
                }
            })
            .catch(err => {
                showToast('Connection error while updating status', 'error');
            });
        }

        // Initialize Realtime Polling Stream (Every 2 seconds)
        document.addEventListener('DOMContentLoaded', () => {
            refreshDashboardStream();
            setInterval(refreshDashboardStream, 2000);
        });
    </script>
</body>
</html>
