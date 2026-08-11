<?php
// admin/tables.php - Enterprise POS Restaurant Floor & Table Operations Dashboard
require_once '../config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) {
    die("Database connection error");
}

$tenantId = (int)($_SESSION['restaurant_id'] ?? 0);

// Get tax settings for bill calculation (single source of truth)
$settings = RestaurantSettingsService::getPaymentSettings($conn, $tenantId);
$vatPercent = floatval($settings['tax_percentage'] ?? 13.00);
$scPercent = !empty($settings['service_charge_enabled']) ? floatval($settings['service_charge_amount'] ?? 10.00) : 0.00;
$scType = $settings['service_charge_type'] ?? 'percent';
$taxEnabled = !empty($settings['tax_enabled']);
$scEnabled = !empty($settings['service_charge_enabled']);
$vatMode = $settings['vat_mode'] ?? 'exclusive';
$currencySymbol = $settings['currency_symbol'] ?? 'Rs.';
$currencyPosition = $settings['currency_position'] ?? 'left';

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

            <!-- CUSTOMER SECTION -->
            <div class="space-y-2">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Customer</h4>
                <div id="customerSection" class="space-y-3">
                    <div class="bg-zinc-950 border border-zinc-800 rounded-2xl p-4 space-y-3">
                        <div class="flex items-center gap-2">
                            <span class="w-8 h-8 rounded-xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-xs">👤</span>
                            <span class="text-xs font-bold text-zinc-400">Walk-in Guest</span>
                        </div>
                        <div class="flex gap-2">
                            <input type="tel" id="customerPhoneInput" placeholder="Enter phone to find/create customer" class="flex-1 h-10 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500" maxlength="15">
                            <button onclick="searchCustomerByPhone()" class="h-10 px-4 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-md">Search</button>
                        </div>
                        <div id="customerCreateForm" class="hidden space-y-2 pt-2 border-t border-zinc-800">
                            <input type="text" id="customerNameInput" placeholder="Customer Name" class="w-full h-10 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                            <input type="email" id="customerEmailInput" placeholder="Email (optional)" class="w-full h-10 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                            <button onclick="createCustomer()" class="w-full h-10 rounded-xl bg-emerald-500 text-zinc-950 font-black text-xs active:scale-95 shadow-md">Create Customer</button>
                        </div>
                    </div>
                    <div id="customerDetailsBox" class="hidden bg-zinc-950 border border-zinc-800 rounded-2xl p-4 space-y-2">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="w-8 h-8 rounded-xl bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center text-sm">✓</span>
                                <span class="text-xs font-bold text-emerald-400">Customer Linked</span>
                            </div>
                            <button onclick="unlinkCustomer()" class="text-xs text-rose-400 hover:text-rose-300 font-bold">Unlink</button>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="bg-zinc-900/50 rounded-xl p-2 border border-zinc-800/40">
                                <span class="text-[10px] font-bold text-zinc-500 block">Name</span>
                                <span id="customerDisplayName" class="font-bold text-white"></span>
                            </div>
                            <div class="bg-zinc-900/50 rounded-xl p-2 border border-zinc-800/40">
                                <span class="text-[10px] font-bold text-zinc-500 block">Phone</span>
                                <span id="customerDisplayPhone" class="font-bold text-white"></span>
                            </div>
                            <div class="bg-zinc-900/50 rounded-xl p-2 border border-zinc-800/40">
                                <span class="text-[10px] font-bold text-zinc-500 block">Total Visits</span>
                                <span id="customerDisplayVisits" class="font-bold text-amber-400"></span>
                            </div>
                            <div class="bg-zinc-900/50 rounded-xl p-2 border border-zinc-800/40">
                                <span class="text-[10px] font-bold text-zinc-500 block">Total Spent</span>
                                <span id="customerDisplaySpent" class="font-bold text-emerald-400"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- LOYALTY SECTION -->
            <div class="space-y-2" id="loyaltySection" style="display: none;">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Loyalty</h4>
                <div id="loyaltyBox" class="bg-zinc-950 border border-emerald-500/20 rounded-2xl p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-emerald-400">🏆 Available Points</span>
                        <span id="loyaltyPointsDisplay" class="text-lg font-black text-emerald-400">0</span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-zinc-400">
                        <span>Points Value</span>
                        <span id="loyaltyValueDisplay" class="font-bold text-white">Rs.0</span>
                    </div>
                    <div class="flex gap-2">
                        <input type="number" id="loyaltyPointsToRedeem" placeholder="Points to redeem" min="1" oninput="updateLoyaltyHint()" class="flex-1 h-10 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                        <button onclick="applyLoyaltyPoints()" class="h-10 px-4 rounded-xl bg-emerald-500 text-zinc-950 font-black text-xs active:scale-95 shadow-md">Apply</button>
                    </div>
                    <div id="loyaltyMaxHint" class="hidden text-[10px] text-amber-400/80"></div>
                    <div id="loyaltyDiscountRow" class="hidden flex justify-between text-xs text-zinc-400 bg-zinc-900/50 rounded-xl p-2 border border-zinc-800/40">
                        <span>Loyalty Discount</span>
                        <span id="loyaltyDiscountAmount" class="font-bold text-emerald-400">Rs.0</span>
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
                <div class="flex justify-between text-xs text-zinc-400" id="drawerNCRRow" style="display: none;">
                    <span>NCR / Complimentary</span>
                    <span id="drawerNCR">Rs.0</span>
                </div>
                <div class="flex justify-between text-xs text-zinc-400" id="drawerLoyaltyRow" style="display: none;">
                    <span>Loyalty Discount</span>
                    <span id="drawerLoyaltyDiscount">Rs.0</span>
                </div>
                <div class="flex justify-between text-sm font-black text-white pt-2 border-t border-zinc-800">
                    <span>Total Amount Due</span>
                    <span id="drawerTotalAmount" class="text-amber-400">Rs.0</span>
                </div>
            </div>

            <!-- PAYMENT METHOD SELECTION -->
            <div class="space-y-3" id="paymentSection" style="display: none;">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Payment</h4>
                <div class="grid grid-cols-3 gap-2" id="paymentMethodButtons">
                    <button type="button" onclick="selectPaymentMethod('cash')" class="h-14 rounded-2xl bg-zinc-950 border border-zinc-800 text-zinc-300 font-bold text-xs flex flex-col items-center justify-center gap-1.5 hover:border-amber-500/40 transition-all" data-method="cash">
                        <span class="text-2xl">💵</span>
                        <span>Cash</span>
                    </button>
                    <button type="button" onclick="selectPaymentMethod('card')" class="h-14 rounded-2xl bg-zinc-950 border border-zinc-800 text-zinc-300 font-bold text-xs flex flex-col items-center justify-center gap-1.5 hover:border-blue-500/40 transition-all" data-method="card">
                        <span class="text-2xl">💳</span>
                        <span>Card</span>
                    </button>
                    <button type="button" onclick="selectPaymentMethod('digital')" class="h-14 rounded-2xl bg-zinc-950 border border-zinc-800 text-zinc-300 font-bold text-xs flex flex-col items-center justify-center gap-1.5 hover:border-purple-500/40 transition-all" data-method="digital">
                        <span class="text-2xl">📱</span>
                        <span>Digital QR</span>
                    </button>
                </div>
            </div>

            <!-- CASH PAYMENT INPUT -->
            <div class="hidden bg-zinc-950 border border-zinc-800 rounded-2xl p-4 space-y-3" id="cashPaymentSection">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Cash Payment</h4>
                <div class="space-y-2">
                    <div class="flex justify-between text-xs text-zinc-400">
                        <span>Amount Due</span>
                        <span id="cashAmountDue" class="font-black text-amber-400">Rs.0</span>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-300 mb-1">Cash Received</label>
                        <input type="number" step="0.01" id="cashReceivedInput" placeholder="Enter amount received" class="w-full h-11 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-base text-white font-black outline-none focus:border-amber-500 text-right" oninput="validateCashPayment()">
                    </div>
                    <div class="flex justify-between text-sm font-bold">
                        <span class="text-zinc-400">Change Due</span>
                        <span id="cashChangeDue" class="text-emerald-400">Rs.0</span>
                    </div>
                    <div id="cashValidationError" class="hidden text-xs text-rose-400 font-bold text-center py-1">
                        Insufficient cash received. Amount received must be greater than or equal to amount due.
                    </div>
                    <button type="button" onclick="showPaymentConfirmation('cash')" id="cashPayButton" class="w-full h-12 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                        ✓ PAY
                    </button>
                </div>
            </div>

            <!-- CARD PAYMENT INPUT -->
            <div class="hidden bg-zinc-950 border border-zinc-800 rounded-2xl p-4 space-y-3" id="cardPaymentSection">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Card Payment</h4>
                <div class="space-y-2">
                    <div class="flex justify-between text-xs text-zinc-400">
                        <span>Amount</span>
                        <span id="cardAmountDue" class="font-black text-blue-400">Rs.0</span>
                    </div>
                    <div class="text-center py-2">
                        <span class="text-xs text-zinc-500">Tap/Insert card on terminal</span>
                    </div>
                    <button type="button" onclick="showPaymentConfirmation('card')" id="cardPayButton" class="w-full h-12 rounded-2xl bg-blue-500 text-white font-black text-xs active:scale-95 shadow-lg shadow-blue-500/20">
                        ✓ PAY
                    </button>
                </div>
            </div>

            <!-- DIGITAL QR PAYMENT INPUT -->
            <div class="hidden bg-zinc-950 border border-zinc-800 rounded-2xl p-4 space-y-3" id="digitalPaymentSection">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Digital QR Payment</h4>
                <div class="space-y-2">
                    <div class="flex justify-between text-xs text-zinc-400">
                        <span>Amount</span>
                        <span id="digitalAmountDue" class="font-black text-purple-400">Rs.0</span>
                    </div>
                    <div class="text-center py-2">
                        <span class="text-xs text-zinc-500">Customer scans QR to pay</span>
                    </div>
                    <div class="p-3 bg-white rounded-xl inline-block">
                        <img id="digitalQRImage" src="" alt="Payment QR" class="w-32 h-32 mx-auto">
                    </div>
                    <button type="button" onclick="showPaymentConfirmation('digital')" id="digitalPayButton" class="w-full h-12 rounded-2xl bg-purple-500 text-white font-black text-xs active:scale-95 shadow-lg shadow-purple-500/20">
                        ✓ PAYMENT RECEIVED
                    </button>
                </div>
            </div>

            <!-- PAYMENT CONFIRMATION -->
            <div class="hidden bg-zinc-950 border border-amber-500/20 rounded-2xl p-4 space-y-3" id="paymentConfirmationSection">
                <h4 class="text-xs font-black text-amber-400 uppercase tracking-wider flex items-center gap-1.5">⚠️ Confirm Payment</h4>
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between text-zinc-400"><span>Table:</span><span id="confirmTable" class="font-bold text-white"></span></div>
                    <div class="flex justify-between text-zinc-400"><span>Order:</span><span id="confirmOrder" class="font-bold text-white"></span></div>
                    <div class="flex justify-between text-zinc-400"><span>Customer:</span><span id="confirmCustomer" class="font-bold text-white"></span></div>
                    <div class="flex justify-between text-zinc-400"><span>Payment Method:</span><span id="confirmMethod" class="font-bold text-amber-400"></span></div>
                    <div class="flex justify-between text-zinc-400 pt-2 border-t border-zinc-800"><span class="font-black">Grand Total:</span><span id="confirmTotal" class="font-black text-amber-400 text-lg"></span></div>
                    <div id="confirmCashDetails" class="hidden space-y-1 text-[10px] text-zinc-500">
                        <div class="flex justify-between"><span>Received:</span><span id="confirmCashReceived" class="font-bold"></span></div>
                        <div class="flex justify-between"><span>Change:</span><span id="confirmCashChange" class="font-bold text-emerald-400"></span></div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 pt-2">
                    <button onclick="hidePaymentConfirmation()" class="h-11 rounded-2xl bg-zinc-800 font-bold text-xs text-zinc-300">Cancel</button>
                    <button onclick="processPayment()" class="h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">✓ Confirm & Settle</button>
                </div>
            </div>

            <!-- PAYMENT SUCCESS STATE -->
            <div class="hidden bg-zinc-950 border border-emerald-500/30 rounded-2xl p-6 text-center space-y-4" id="paymentSuccessSection">
                <div class="w-16 h-16 rounded-full bg-emerald-500/20 border-2 border-emerald-500 flex items-center justify-center text-emerald-400 font-black text-3xl mx-auto">✓</div>
                <div>
                    <h4 class="font-black text-white text-base">✓ PAYMENT SUCCESSFUL</h4>
                    <p class="text-xs text-zinc-400 mt-1">Table <span id="successTableNum" class="font-bold text-amber-400"></span> settled</p>
                </div>
                <div class="bg-zinc-900/50 rounded-xl p-3 border border-zinc-800/40 space-y-1 text-xs text-left">
                    <div class="flex justify-between text-zinc-400"><span>Bill #</span><span id="successBillNum" class="font-bold text-amber-400"></span></div>
                    <div class="flex justify-between text-zinc-400"><span>Order:</span><span id="successOrderId" class="font-bold text-white"></span></div>
                    <div class="flex justify-between text-zinc-400"><span>Amount Paid:</span><span id="successAmount" class="font-bold text-emerald-400"></span></div>
                    <div class="flex justify-between text-zinc-400"><span>Payment Method:</span><span id="successMethod" class="font-bold text-amber-400"></span></div>
                    <div id="successCashDetails" class="hidden space-y-1 text-zinc-500 border-t border-zinc-800 pt-2">
                        <div class="flex justify-between"><span>Cash Received:</span><span id="successCashReceived" class="font-bold text-white"></span></div>
                        <div class="flex justify-between"><span>Change Due:</span><span id="successChangeDue" class="font-bold text-emerald-400"></span></div>
                    </div>
                    <div class="flex justify-between text-zinc-400 border-t border-zinc-800 pt-2">
                        <span class="font-black">Status:</span>
                        <span class="font-bold text-emerald-400">PAID</span>
                    </div>
                    <div class="flex justify-between text-[10px] text-zinc-500 border-t border-zinc-800 pt-2">
                        <span>Date/Time:</span>
                        <span id="successDateTime" class="font-bold"></span>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 pt-2 border-t border-zinc-800">
                    <button onclick="printReceipt()" class="h-11 rounded-2xl bg-zinc-800 text-zinc-300 font-bold text-xs hover:text-white flex items-center justify-center gap-1.5 active:scale-95 transition-all">
                        🧾 Print Receipt
                    </button>
                    <button onclick="viewReceipt()" class="h-11 rounded-2xl bg-blue-500 text-white font-bold text-xs active:scale-95 shadow-lg flex items-center justify-center gap-1.5 hover:bg-blue-600 transition-all">
                        👁️ View Receipt
                    </button>
                    <button onclick="closeTableDrawer(); refreshDashboardStream()" class="col-span-2 h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                        Close Panel
                    </button>
                </div>
            </div>

            <!-- QUICK ACTIONS -->
            <div class="grid grid-cols-2 gap-2" id="quickActionsSection">
                <button onclick="openTableQRModalFromDrawer()" class="h-11 rounded-2xl bg-zinc-800 text-white font-bold text-xs flex items-center justify-center gap-1.5 active:scale-95 hover:border-amber-500/40">
                    📱 View Table QR
                </button>
                <button onclick="promptTransferTable()" class="h-11 rounded-2xl bg-zinc-900 border border-zinc-800 text-blue-400 font-bold text-xs flex items-center justify-center gap-1.5 hover:border-blue-500/40">
                    ➡️ Transfer Table
                </button>
                <button onclick="promptMergeTables()" class="h-11 rounded-2xl bg-zinc-900 border border-zinc-800 text-purple-400 font-bold text-xs flex items-center justify-center gap-1.5 hover:border-purple-500/40">
                    🔀 Merge Tables
                </button>
                <button onclick="updateSelectedTableStatus('cleaning')" class="h-11 rounded-2xl bg-zinc-950 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
                    🧹 Mark Cleaning
                </button>
                <button onclick="updateSelectedTableStatus('vacant')" class="h-11 rounded-2xl bg-zinc-950 border border-zinc-800 text-emerald-400 font-bold text-xs hover:border-emerald-500/40">
                    🟢 Mark Vacant
                </button>
                <button onclick="updateSelectedTableStatus('reserved')" class="h-11 rounded-2xl bg-zinc-950 border border-zinc-800 text-amber-400 font-bold text-xs hover:border-amber-500/40">
                    🟡 Reserve
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
                <div id="qrModalCustomerUrl" class="text-xs font-mono font-bold text-amber-400 truncate selection:bg-amber-500 selection:text-zinc-950"><?php echo htmlspecialchars((empty($_SERVER['HTTPS']) ? 'http://' : 'https://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/menu.php?token=...'); ?></div>
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
        window.csrfToken = '<?php echo CSRF::generateToken(); ?>';
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
            const opts = { signal, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
            return fetch('../api/tables-stream.php', opts);
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

        let currentCustomerId = 0;
        let currentLoyaltyPoints = 0;
        let currentLoyaltyPointValue = 1.0;
        let currentLoyaltyDiscount = 0;
        let currentMaxAllowedPoints = 0;
        let isProcessingPayment = false;
        let selectedPaymentMethod = null;
        let currentBill = null;

        function openTableDrawer(tableNum) {
            selectedTableNumber = tableNum;
            currentCustomerId = 0;
            currentLoyaltyPoints = 0;
            currentLoyaltyPointValue = 1.0;
            currentLoyaltyDiscount = 0;
            selectedPaymentMethod = null;
            currentBill = null;

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

            // Reset customer/loyalty sections
            document.getElementById('customerSection').querySelector('.bg-zinc-950').classList.remove('hidden');
            document.getElementById('customerDetailsBox').classList.add('hidden');
            document.getElementById('customerCreateForm').classList.add('hidden');
            document.getElementById('customerPhoneInput').value = '';
            document.getElementById('customerNameInput').value = '';
            document.getElementById('customerEmailInput').value = '';
            document.getElementById('loyaltySection').style.display = 'none';
            document.getElementById('loyaltyDiscountRow').classList.add('hidden');
            document.getElementById('drawerLoyaltyRow').style.display = 'none';
            document.getElementById('drawerLoyaltyDiscount').textContent = formatPrice(0);
            document.getElementById('loyaltyPointsToRedeem').value = '';
            document.getElementById('loyaltyMaxHint').classList.add('hidden');
            currentCustomerId = 0;
            currentLoyaltyPoints = 0;
            currentLoyaltyPointValue = 1.0;
            currentLoyaltyDiscount = 0;
            currentMaxAllowedPoints = 0;

            // Fetch authoritative bill calculations from backend if active order exists
            const orderId = t.active_order ? t.active_order.id : null;
            if (orderId) {
                fetch('../api/table-payment.php?action=calculate_bill&order_id=' + orderId, { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.bill) {
                            currentBill = data.bill;
                            renderBillSummary();
                        } else {
                            fallbackCalculateBill(t);
                        }
                    })
                    .catch(() => fallbackCalculateBill(t));
            } else {
                fallbackCalculateBill(t);
            }

            // Show payment section if table is waiting for bill
            const isWaitingBill = (st === 'payment_pending');
            document.getElementById('paymentSection').style.display = isWaitingBill ? 'block' : 'none';
            document.getElementById('cashPaymentSection').classList.add('hidden');
            document.getElementById('cardPaymentSection').classList.add('hidden');
            document.getElementById('digitalPaymentSection').classList.add('hidden');
            document.getElementById('paymentConfirmationSection').classList.add('hidden');
            document.getElementById('paymentSuccessSection').classList.add('hidden');
            document.getElementById('quickActionsSection').style.display = isWaitingBill ? 'none' : 'grid';

            // Reset payment method buttons
            document.querySelectorAll('#paymentMethodButtons button').forEach(btn => {
                btn.className = 'h-14 rounded-2xl bg-zinc-950 border border-zinc-800 text-zinc-300 font-bold text-xs flex flex-col items-center justify-center gap-1.5 hover:border-amber-500/40 transition-all';
            });

            document.getElementById('tableDrawer').classList.remove('translate-x-full');
        }

        function fallbackCalculateBill(t) {
            // Degraded fallback ONLY used when the authoritative calculate_bill
            // API is unreachable. Mirrors the server engine (vat_mode + discounts).
            const items = t.items || [];
            let subtotal = 0;
            items.forEach(i => { subtotal += parseFloat(i.price) * parseInt(i.quantity); });

            const manualDiscount = Math.max(0, parseFloat(t.discount_amount || t.discount || 0));
            const loyaltyDisc = parseFloat(currentLoyaltyDiscount || 0) || 0;
            const netBase = Math.max(0, subtotal - manualDiscount - loyaltyDisc);

            let sc = 0, vat = 0;
            if (<?= $scEnabled ? 'true' : 'false' ?>) {
                sc = <?= $scType === 'fixed' ? 'Math.round(' . $scPercent . ' * 100) / 100' : 'Math.round((netBase * ' . $scPercent . ') / 100 * 100) / 100' ?>;
            }
            if (<?= $taxEnabled ? 'true' : 'false' ?>) {
                <?php if ($vatMode === 'inclusive'): ?>
                vat = Math.round((netBase * <?= $vatPercent ?>) / (100 + <?= $vatPercent ?>) * 100) / 100;
                <?php else: ?>
                vat = Math.round(((netBase + sc) * <?= $vatPercent ?>) / 100 * 100) / 100;
                <?php endif; ?>
            }
            let gt = Math.max(0, netBase + sc + vat);

            currentBill = {
                subtotal: subtotal,
                service_charge: sc,
                vat: vat,
                discount: manualDiscount,
                loyalty_discount: loyaltyDisc,
                ncr_amount: 0,
                grand_total: gt,
                formatted: {
                    subtotal: formatPrice(subtotal),
                    service_charge: formatPrice(sc),
                    vat: formatPrice(vat),
                    discount: formatPrice(manualDiscount),
                    loyalty_discount: formatPrice(loyaltyDisc),
                    ncr_amount: formatPrice(0),
                    grand_total: formatPrice(gt)
                }
            };
            renderBillSummary();
        }

        function renderBillSummary() {
            if (!currentBill) return;
            document.getElementById('drawerSubtotal').textContent = currentBill.formatted.subtotal;
            document.getElementById('drawerServiceCharge').textContent = currentBill.formatted.service_charge;
            document.getElementById('drawerTax').textContent = currentBill.formatted.vat;
            document.getElementById('drawerDiscount').textContent = currentBill.formatted.discount;
            document.getElementById('drawerNCR').textContent = currentBill.formatted.ncr_amount;
            document.getElementById('drawerLoyaltyDiscount').textContent = currentBill.formatted.loyalty_discount;
            document.getElementById('drawerTotalAmount').textContent = currentBill.formatted.grand_total;

            document.getElementById('drawerServiceChargeRow').style.display = currentBill.service_charge > 0 ? 'flex' : 'none';
            document.getElementById('drawerTaxRow').style.display = currentBill.vat > 0 ? 'flex' : 'none';
            document.getElementById('drawerLoyaltyRow').style.display = currentBill.loyalty_discount > 0 ? 'flex' : 'none';

            const loyaltyRow = document.getElementById('loyaltyDiscountRow');
            if (loyaltyRow) {
                if (currentBill.loyalty_discount > 0) {
                    document.getElementById('loyaltyDiscountAmount').textContent = formatPrice(currentBill.loyalty_discount);
                    loyaltyRow.classList.remove('hidden');
                } else {
                    loyaltyRow.classList.add('hidden');
                }
            }

            if (selectedPaymentMethod) {
                updatePaymentAmountDisplays();
            }
        }

        function closeTableDrawer() {
            document.getElementById('tableDrawer').classList.add('translate-x-full');
        }

        let currentCustomerUrl = '';
        let currentQRImageUrl = '';

        function generateQRCodeDataURL(text, size = 300) {
            return 'https://api.qrserver.com/v1/create-qr-code/?size=' + size + 'x' + size + '&data=' + encodeURIComponent(text);
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
            openTableDrawer(selectedTableNumber);
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

        // ================================================================
        // TABLE BILLING & PAYMENT FUNCTIONS
        // ================================================================
        
        function searchCustomerByPhone() {
            const phone = document.getElementById('customerPhoneInput').value.trim();
            if (!phone) {
                showToast('Please enter a phone number', 'warning');
                return;
            }

            showToast('Searching customer...', 'info');

            fetch('../api/table-payment.php?action=search_customer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'phone=' + encodeURIComponent(phone),
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (data.customer && data.exists) {
                        linkCustomer(data.customer);
                    } else {
                        // Customer not found - show create form
                        document.getElementById('customerPhoneInput').value = phone;
                        document.getElementById('customerCreateForm').classList.remove('hidden');
                        showToast('Customer not found. Enter details to create.', 'info');
                    }
                } else {
                    showToast(data.message || 'Search failed', 'error');
                }
            })
            .catch(err => showToast('Connection error', 'error'));
        }

        function createCustomer() {
            const phone = document.getElementById('customerPhoneInput').value.trim();
            const name = document.getElementById('customerNameInput').value.trim();
            const email = document.getElementById('customerEmailInput').value.trim();

            if (!name) {
                showToast('Customer name is required', 'warning');
                return;
            }

            fetch('../api/table-payment.php?action=create_customer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'phone=' + encodeURIComponent(phone) + '&name=' + encodeURIComponent(name) + '&email=' + encodeURIComponent(email),
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.customer) {
                    linkCustomer(data.customer);
                    document.getElementById('customerCreateForm').classList.add('hidden');
                    document.getElementById('customerNameInput').value = '';
                    document.getElementById('customerEmailInput').value = '';
                } else {
                    showToast(data.message || 'Failed to create customer', 'error');
                }
            })
            .catch(err => showToast('Connection error', 'error'));
        }

        function linkCustomer(customer) {
            currentCustomerId = customer.id;
            currentLoyaltyPoints = customer.loyalty_points || 0;
            currentMaxAllowedPoints = 0;
            
            document.getElementById('customerSection').querySelector('.bg-zinc-950').classList.add('hidden');
            document.getElementById('customerDetailsBox').classList.remove('hidden');
            document.getElementById('customerDisplayName').textContent = customer.name;
            document.getElementById('customerDisplayPhone').textContent = customer.phone;
            document.getElementById('customerDisplayVisits').textContent = customer.total_visits || 0;
            document.getElementById('customerDisplaySpent').textContent = formatPrice(customer.total_spent || 0);

            // Show loyalty section
            document.getElementById('loyaltySection').style.display = 'block';
            updateLoyaltyDisplay();

            // Fetch loyalty details
            fetchLoyaltyInfo(customer.id);

            showToast('Customer linked: ' + customer.name, 'success');
        }

        function unlinkCustomer() {
            currentCustomerId = 0;
            currentLoyaltyPoints = 0;
            currentLoyaltyPointValue = 1.0;
            currentLoyaltyDiscount = 0;
            currentMaxAllowedPoints = 0;
            
            document.getElementById('customerDetailsBox').classList.add('hidden');
            document.getElementById('customerSection').querySelector('.bg-zinc-950').classList.remove('hidden');
            document.getElementById('customerCreateForm').classList.add('hidden');
            document.getElementById('loyaltySection').style.display = 'none';
            document.getElementById('loyaltyDiscountRow').classList.add('hidden');
            document.getElementById('drawerLoyaltyRow').style.display = 'none';
            document.getElementById('loyaltyPointsToRedeem').value = '';
            document.getElementById('loyaltyMaxHint').classList.add('hidden');
            
            const t = allTablesData.find(x => x.table_number.toString() === selectedTableNumber.toString());
            const orderId = t && t.active_order ? t.active_order.id : null;
            if (orderId) {
                fetch('../api/table-payment.php?action=calculate_bill&order_id=' + orderId + '&loyalty_points=0&customer_id=0', { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.bill) {
                            currentBill = data.bill;
                            renderBillSummary();
                        }
                    });
            }
        }

        function fetchLoyaltyInfo(customerId) {
            fetch('../api/table-payment.php?action=get_loyalty', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'customer_id=' + customerId,
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    currentLoyaltyPoints = data.loyalty_points || 0;
                    currentLoyaltyPointValue = data.point_value || 1.0;
                    updateLoyaltyDisplay();
                }
            })
            .catch(err => console.error('Loyalty fetch error:', err));
        }

        function updateLoyaltyDisplay() {
            document.getElementById('loyaltyPointsDisplay').textContent = currentLoyaltyPoints.toLocaleString();
            document.getElementById('loyaltyValueDisplay').textContent = formatPrice(currentLoyaltyPoints * currentLoyaltyPointValue);
            document.getElementById('loyaltyPointsToRedeem').max = currentLoyaltyPoints;
            document.getElementById('loyaltyPointsToRedeem').placeholder = 'Max: ' + currentLoyaltyPoints + ' points';
            updateLoyaltyHint();
        }

        function updateLoyaltyHint() {
            const input = document.getElementById('loyaltyPointsToRedeem');
            const hint = document.getElementById('loyaltyMaxHint');
            const entered = parseInt(input.value) || 0;
            if (!hint) return;
            const maxPoints = currentMaxAllowedPoints > 0 ? currentMaxAllowedPoints : (currentLoyaltyPoints || 0);
            if (entered > 0 && currentMaxAllowedPoints > 0 && entered > currentMaxAllowedPoints) {
                hint.textContent = 'Maximum allowed: ' + currentMaxAllowedPoints + ' points (loyalty rules)';
                hint.classList.remove('hidden');
            } else if (entered > 0 && currentMaxAllowedPoints > 0 && entered < currentMaxAllowedPoints && entered > 0) {
                hint.textContent = 'Max allowed: ' + currentMaxAllowedPoints + ' points';
                hint.classList.remove('hidden');
            } else {
                hint.classList.add('hidden');
            }
        }

        function applyLoyaltyPoints() {
            const points = parseInt(document.getElementById('loyaltyPointsToRedeem').value) || 0;
            if (!points || points <= 0) {
                showToast('Enter points to redeem', 'warning');
                return;
            }

            if (!currentCustomerId) {
                showToast('No customer linked', 'warning');
                return;
            }

            const t = allTablesData.find(x => x.table_number.toString() === selectedTableNumber.toString());
            const orderId = t && t.active_order ? t.active_order.id : null;
            if (!orderId) {
                showToast('No active order to apply loyalty', 'warning');
                return;
            }

            showToast('Validating loyalty points...', 'info');

            // 1. Ask the server to authoritatively validate + cap the redemption
            fetch('../api/table-payment.php?action=apply_loyalty', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'customer_id=' + currentCustomerId + '&points=' + points + '&order_id=' + orderId,
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.valid) {
                    currentMaxAllowedPoints = data.max_allowed_points || 0;
                    document.getElementById('loyaltyPointsToRedeem').value = data.points_redeemed;
                    document.getElementById('loyaltyPointsToRedeem').max = data.max_allowed_points || data.available_points || currentLoyaltyPoints;
                    document.getElementById('loyaltyPointsToRedeem').placeholder = 'Max: ' + (data.max_allowed_points || currentLoyaltyPoints) + ' points';
                    if (data.points_redeemed < points) {
                        showToast('Redemption capped to ' + data.points_redeemed + ' points by loyalty rules', 'info');
                    }
                    // 2. Recalculate the authoritative bill with the validated points
                    return fetch('../api/table-payment.php?action=calculate_bill&order_id=' + orderId + '&loyalty_points=' + data.points_redeemed + '&customer_id=' + currentCustomerId, { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(billData => {
                            if (billData.success && billData.bill) {
                                currentBill = billData.bill;
                                currentLoyaltyDiscount = billData.bill.loyalty_discount;
                                renderBillSummary();
                                showToast('Loyalty discount applied: ' + billData.bill.formatted.loyalty_discount, 'success');
                            } else {
                                showToast(billData.message || 'Failed to apply loyalty', 'error');
                            }
                        });
                } else {
                    currentLoyaltyDiscount = 0;
                    currentMaxAllowedPoints = data.max_allowed_points || 0;
                    document.getElementById('loyaltyDiscountRow').classList.add('hidden');
                    document.getElementById('drawerLoyaltyRow').style.display = 'none';
                    document.getElementById('drawerLoyaltyDiscount').textContent = formatPrice(0);
                    showToast(data.message || 'Redemption rejected by loyalty rules', 'error');
                    // Reset the bill to no-loyalty so the shown total stays authoritative
                    return fetch('../api/table-payment.php?action=calculate_bill&order_id=' + orderId + '&loyalty_points=0&customer_id=' + currentCustomerId, { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(billData => {
                            if (billData.success && billData.bill) {
                                currentBill = billData.bill;
                                renderBillSummary();
                            }
                        });
                }
            })
            .catch(() => showToast('Connection error', 'error'));
        }

        function selectPaymentMethod(method) {
            selectedPaymentMethod = method;
            
            // Update button styles
            document.querySelectorAll('#paymentMethodButtons button').forEach(btn => {
                if (btn.dataset.method === method) {
                    btn.className = 'h-14 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs flex flex-col items-center justify-center gap-1.5 shadow-lg shadow-amber-500/20 active:scale-95';
                } else {
                    btn.className = 'h-14 rounded-2xl bg-zinc-950 border border-zinc-800 text-zinc-300 font-bold text-xs flex flex-col items-center justify-center gap-1.5 hover:border-amber-500/40 transition-all';
                }
            });

            // Show payment section
            document.getElementById('paymentSection').style.display = 'block';
            
            // Hide all payment input sections
            document.getElementById('cashPaymentSection').classList.add('hidden');
            document.getElementById('cardPaymentSection').classList.add('hidden');
            document.getElementById('digitalPaymentSection').classList.add('hidden');
            document.getElementById('paymentConfirmationSection').classList.add('hidden');
            document.getElementById('paymentSuccessSection').classList.add('hidden');

            if (method === 'cash') {
                document.getElementById('cashPaymentSection').classList.remove('hidden');
                document.getElementById('cashReceivedInput').value = '';
                document.getElementById('cashChangeDue').textContent = formatPrice(0);
            } else if (method === 'card') {
                document.getElementById('cardPaymentSection').classList.remove('hidden');
            } else if (method === 'digital') {
                document.getElementById('digitalPaymentSection').classList.remove('hidden');
                generateDigitalQR(currentBill ? currentBill.grand_total : 0);
            }

            updatePaymentAmountDisplays();
            document.getElementById('quickActionsSection').style.display = 'none';
        }

        function updatePaymentAmountDisplays() {
            const dueFormatted = currentBill ? currentBill.formatted.grand_total : formatPrice(0);
            document.getElementById('cashAmountDue').textContent = dueFormatted;
            document.getElementById('cardAmountDue').textContent = dueFormatted;
            document.getElementById('digitalAmountDue').textContent = dueFormatted;
            validateCashPayment();
        }

        function validateCashPayment() {
            const due = currentBill ? currentBill.grand_total : 0;
            const received = parseFloat(document.getElementById('cashReceivedInput').value) || 0;
            const change = Math.max(0, received - due);
            document.getElementById('cashChangeDue').textContent = formatPrice(change);
            
            const payButton = document.getElementById('cashPayButton');
            const errorDiv = document.getElementById('cashValidationError');
            
            if (received > 0 && received + 0.001 < due) {
                // Insufficient cash
                payButton.disabled = true;
                errorDiv.classList.remove('hidden');
            } else if (received > 0 && received >= due) {
                // Sufficient cash
                payButton.disabled = false;
                errorDiv.classList.add('hidden');
            } else {
                // No amount entered yet
                payButton.disabled = true;
                errorDiv.classList.add('hidden');
            }
        }

        function generateDigitalQR(amount) {
            const paymentUrl = window.location.origin + '/payment.php?amount=' + amount + '&table=' + selectedTableNumber;
            const qrDataUrl = generateQRCodeDataURL(paymentUrl, 200);
            document.getElementById('digitalQRImage').src = qrDataUrl;
        }

        function showPaymentConfirmation(method) {
            selectedPaymentMethod = method;
            
            const tableNum = selectedTableNumber;
            const t = allTablesData.find(x => x.table_number.toString() === tableNum.toString());
            const orderId = t && t.active_order ? t.active_order.id : null;
            const grandTotalFormatted = currentBill ? currentBill.formatted.grand_total : formatPrice(0);
            const customerName = currentCustomerId ? document.getElementById('customerDisplayName').textContent : 'Walk-in Guest';

            document.getElementById('confirmTable').textContent = 'T-' + tableNum;
            document.getElementById('confirmOrder').textContent = orderId ? '#' + orderId : 'N/A';
            document.getElementById('confirmCustomer').textContent = customerName;
            
            const methodLabels = {
                'cash': '💵 Cash',
                'card': '💳 Card',
                'digital': '📱 Digital QR'
            };
            document.getElementById('confirmMethod').textContent = methodLabels[method] || method;
            document.getElementById('confirmTotal').textContent = grandTotalFormatted;

            if (method === 'cash') {
                const received = parseFloat(document.getElementById('cashReceivedInput').value) || 0;
                const due = currentBill ? currentBill.grand_total : 0;
                if (received + 0.001 < due) {
                    showToast('Cash received (Rs. ' + received.toFixed(2) + ') is less than amount due (' + grandTotalFormatted + ')', 'error');
                    return;
                }
                const change = Math.max(0, received - due);
                document.getElementById('confirmCashReceived').textContent = formatPrice(received);
                document.getElementById('confirmCashChange').textContent = formatPrice(change);
                document.getElementById('confirmCashDetails').classList.remove('hidden');
            } else {
                document.getElementById('confirmCashDetails').classList.add('hidden');
            }

            // Hide other sections
            document.getElementById('cashPaymentSection').classList.add('hidden');
            document.getElementById('cardPaymentSection').classList.add('hidden');
            document.getElementById('digitalPaymentSection').classList.add('hidden');
            document.getElementById('paymentSection').style.display = 'none';
            document.getElementById('paymentConfirmationSection').classList.remove('hidden');
            document.getElementById('paymentSuccessSection').classList.add('hidden');
        }

        function hidePaymentConfirmation() {
            document.getElementById('paymentConfirmationSection').classList.add('hidden');
            document.getElementById('paymentSection').style.display = 'block';
            
            if (selectedPaymentMethod === 'cash') document.getElementById('cashPaymentSection').classList.remove('hidden');
            else if (selectedPaymentMethod === 'card') document.getElementById('cardPaymentSection').classList.remove('hidden');
            else if (selectedPaymentMethod === 'digital') document.getElementById('digitalPaymentSection').classList.remove('hidden');
        }

        function processPayment() {
            if (isProcessingPayment) {
                showToast('Payment already processing...', 'warning');
                return;
            }
            if (!selectedTableNumber || !selectedPaymentMethod) {
                showToast('No payment method selected', 'warning');
                return;
            }

            const tableNum = selectedTableNumber;
            const t = allTablesData.find(x => x.table_number.toString() === tableNum.toString());
            const orderId = t && t.active_order ? t.active_order.id : null;
            
            if (!orderId) {
                showToast('No active order found', 'error');
                return;
            }

            const grandTotal = currentBill ? currentBill.grand_total : 0;

            let cashReceived = 0;
            if (selectedPaymentMethod === 'cash') {
                cashReceived = parseFloat(document.getElementById('cashReceivedInput').value) || 0;
                if (cashReceived + 0.001 < grandTotal) {
                    showToast('Cash received is less than amount due', 'warning');
                    return;
                }
            }

            hidePaymentConfirmation();
            showToast('Processing payment...', 'info');
            isProcessingPayment = true;

            const formData = new FormData();
            formData.append('action', 'process_payment');
            formData.append('table_number', tableNum);
            formData.append('order_id', orderId);
            formData.append('payment_method', selectedPaymentMethod);
            formData.append('customer_id', currentCustomerId);
            formData.append('loyalty_points_redeemed', currentBill ? currentBill.loyalty_points_redeemed : 0);
            formData.append('cash_received', cashReceived);
            formData.append('csrf_token', window.csrfToken || '');

            fetch('../api/table-payment.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    isProcessingPayment = false;
                    displayPaymentSuccess(data, tableNum, orderId, grandTotal, cashReceived);
                    
                    currentLoyaltyDiscount = 0;
                    currentLoyaltyPoints = 0;
                    currentMaxAllowedPoints = 0;
                    document.getElementById('loyaltyPointsToRedeem').value = '';
                    document.getElementById('loyaltyMaxHint').classList.add('hidden');
                    document.getElementById('loyaltyDiscountRow').classList.add('hidden');
                    document.getElementById('drawerLoyaltyRow').style.display = 'none';
                    document.getElementById('drawerLoyaltyDiscount').textContent = formatPrice(0);
                    
                    showToast('Payment successful!', 'success');
                    refreshDashboardStream();
                } else {
                    isProcessingPayment = false;
                    showToast(data.message || 'Payment failed', 'error');
                    document.getElementById('paymentConfirmationSection').classList.add('hidden');
                    document.getElementById('paymentSection').style.display = 'block';
                    if (selectedPaymentMethod === 'cash') {
                        document.getElementById('cashPaymentSection').classList.remove('hidden');
                    } else if (selectedPaymentMethod === 'card') {
                        document.getElementById('cardPaymentSection').classList.remove('hidden');
                    } else if (selectedPaymentMethod === 'digital') {
                        document.getElementById('digitalPaymentSection').classList.remove('hidden');
                    }
                }
            })
            .catch(err => {
                isProcessingPayment = false;
                showToast('Connection error during payment', 'error');
                document.getElementById('paymentConfirmationSection').classList.add('hidden');
                document.getElementById('paymentSection').style.display = 'block';
            });
        }

        function promptTransferTable() {
            const srcTable = selectedTableNumber;
            if (!srcTable) {
                showToast('Please select a table first', 'warning');
                return;
            }
            const tgtTable = prompt('Enter target table number to transfer Table ' + srcTable + ' orders to:');
            if (!tgtTable || !tgtTable.trim() || tgtTable.trim() === srcTable.toString()) {
                if (tgtTable) showToast('Target table must be different', 'warning');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'transfer_table');
            formData.append('source_table', srcTable);
            formData.append('target_table', tgtTable.trim());
            formData.append('csrf_token', window.csrfToken || '');

            fetch('../api/table-payment.php', { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message || 'Table transferred!', 'success');
                        closeTableDrawer();
                        refreshDashboardStream();
                    } else {
                        showToast(data.message || 'Transfer failed', 'error');
                    }
                })
                .catch(() => showToast('Transfer request error', 'error'));
        }

        function promptMergeTables() {
            const t = allTablesData.find(x => x.table_number.toString() === selectedTableNumber.toString());
            const srcOrderId = t && t.active_order ? t.active_order.id : null;
            if (!srcOrderId) {
                showToast('No active order on selected table to merge', 'warning');
                return;
            }
            const tgtOrderIdStr = prompt('Enter target Order ID to merge Order #' + srcOrderId + ' into:');
            const tgtOrderId = parseInt(tgtOrderIdStr);
            if (!tgtOrderId || tgtOrderId === srcOrderId) {
                if (tgtOrderIdStr) showToast('Target Order ID must be different', 'warning');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'merge_bills');
            formData.append('source_order_id', srcOrderId);
            formData.append('target_order_id', tgtOrderId);
            formData.append('csrf_token', window.csrfToken || '');

            fetch('../api/table-payment.php', { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast('Orders merged successfully!', 'success');
                        closeTableDrawer();
                        refreshDashboardStream();
                    } else {
                        showToast(data.message || 'Merge failed', 'error');
                    }
                })
                .catch(() => showToast('Merge request error', 'error'));
        }

        function displayPaymentSuccess(data, tableNum, orderId, grandTotal, cashReceived) {
            const change = selectedPaymentMethod === 'cash' ? Math.max(0, cashReceived - grandTotal) : 0;
            
            document.getElementById('successTableNum').textContent = 'T-' + tableNum;
            document.getElementById('successBillNum').textContent = data.transaction_id || '#' + orderId;
            document.getElementById('successOrderId').textContent = '#' + orderId;
            document.getElementById('successAmount').textContent = formatPrice(grandTotal);
            
            const methodLabels = {
                'cash': '💵 Cash',
                'card': '💳 Card',
                'digital': '📱 Digital QR'
            };
            document.getElementById('successMethod').textContent = methodLabels[selectedPaymentMethod] || selectedPaymentMethod;
            
            if (selectedPaymentMethod === 'cash') {
                document.getElementById('successCashReceived').textContent = formatPrice(cashReceived);
                document.getElementById('successChangeDue').textContent = formatPrice(change);
                document.getElementById('successCashDetails').classList.remove('hidden');
            } else {
                document.getElementById('successCashDetails').classList.add('hidden');
            }
            
            document.getElementById('successDateTime').textContent = new Date().toLocaleString();
            
            // Store receipt data for printing
            window.lastPaymentData = {
                order_id: orderId,
                table_number: tableNum,
                transaction_id: data.transaction_id,
                grand_total: grandTotal,
                payment_method: selectedPaymentMethod,
                cash_received: cashReceived,
                change: change,
                customer_id: currentCustomerId,
                customer_name: currentCustomerId ? document.getElementById('customerDisplayName').textContent : 'Walk-in Guest',
                items: data.items || [],
                subtotal: currentBill ? currentBill.subtotal : 0,
                service_charge: currentBill ? currentBill.service_charge : 0,
                vat: currentBill ? currentBill.vat : 0,
                loyalty_discount: currentBill ? currentBill.loyalty_discount : 0
            };

            // Hide all sections
            document.getElementById('paymentSection').style.display = 'none';
            document.getElementById('paymentConfirmationSection').classList.add('hidden');
            document.getElementById('cashPaymentSection').classList.add('hidden');
            document.getElementById('cardPaymentSection').classList.add('hidden');
            document.getElementById('digitalPaymentSection').classList.add('hidden');
            document.getElementById('quickActionsSection').style.display = 'none';
            document.getElementById('paymentSuccessSection').classList.remove('hidden');
        }

        function printReceipt() {
            const orderId = (window.lastPaymentData && window.lastPaymentData.order_id)
                || (activeReceipt && activeReceipt.order_no ? parseInt(activeReceipt.order_no.replace(/[^0-9]/g, '')) : null)
                || (allTablesData.find(x => x.table_number.toString() === selectedTableNumber.toString())?.active_order?.id);

            if (!orderId) {
                showToast('No active order found to print receipt', 'warning');
                return;
            }
            window.open('../receipt.php?order_id=' + orderId + '&print=1', '_blank');
        }

        function viewReceipt() {
            const orderId = (window.lastPaymentData && window.lastPaymentData.order_id)
                || (activeReceipt && activeReceipt.order_no ? parseInt(activeReceipt.order_no.replace(/[^0-9]/g, '')) : null)
                || (allTablesData.find(x => x.table_number.toString() === selectedTableNumber.toString())?.active_order?.id);

            if (!orderId) {
                showToast('No active order found to view receipt', 'warning');
                return;
            }
            window.open('../receipt.php?order_id=' + orderId, '_blank');
        }

        // Initialize: auto-load floor stream on page load and poll every 5s
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('paymentSection').style.display = 'none';
            document.getElementById('loyaltySection').style.display = 'none';
            
            // Auto-load floor layout immediately on page load
            refreshDashboardStream();
            
            // Start automatic 5-second live floor polling
            setInterval(refreshDashboardStream, 5000);
        });
    </script>
</body>
</html>
