<?php
// index.php - RMS Multi-Restaurant SaaS Public Landing Website & Lead Generation Portal
require_once 'config.php';

Auth::startSession();
$conn = getDBConnection();

$requestSuccess = false;
$requestError = null;

// Handle Public Restaurant Onboarding Request Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_restaurant_request') {
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $requestError = "Security verification (CSRF) failed. Please refresh the page and try again.";
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check("restaurant_request_" . $ip, 3, 3600)) {
            $requestError = "You have submitted too many requests recently. Please wait a few minutes or contact our support team.";
        } else {
            $restName = Security::sanitize(trim($_POST['restaurant_name'] ?? ''));
            $ownerName = Security::sanitize(trim($_POST['owner_name'] ?? ''));
            $email = strtolower(Security::sanitize(trim($_POST['email'] ?? '')));
            $phone = Security::sanitize(trim($_POST['phone'] ?? ''));
            $restType = Security::sanitize(trim($_POST['restaurant_type'] ?? 'Casual Dining'));
            $tableCount = max(1, (int)($_POST['table_count'] ?? 10));
            $preferredPlan = Security::sanitize(trim($_POST['preferred_plan'] ?? 'BUSINESS'));
            $message = Security::sanitize(trim($_POST['message'] ?? ''));

            if (empty($restName) || empty($ownerName) || empty($email) || empty($phone)) {
                $requestError = "Please fill in all required fields (Restaurant Name, Owner Name, Email, Phone).";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $requestError = "Please provide a valid email address.";
            } else {
                if ($conn) {
                    // Check duplicate pending request
                    $dupStmt = $conn->prepare("SELECT id FROM restaurant_requests WHERE (email = ? OR phone = ?) AND status = 'PENDING' LIMIT 1");
                    if ($dupStmt) {
                        $dupStmt->bind_param("ss", $email, $phone);
                        $dupStmt->execute();
                        $dupRes = $dupStmt->get_result();
                        if ($dupRes && $dupRes->num_rows > 0) {
                            $requestError = "An onboarding request with this email or phone is already pending review. Our team will contact you shortly!";
                            $dupStmt->close();
                        } else {
                            $dupStmt->close();

                            $requestCode = 'REQ-' . strtoupper(bin2hex(random_bytes(4)));
                            $stmt = $conn->prepare("
                                INSERT INTO restaurant_requests 
                                (request_code, restaurant_name, owner_name, email, phone, restaurant_type, table_count, preferred_plan, message, status, ip_address)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)
                            ");
                            if ($stmt) {
                                $stmt->bind_param("ssssssisss", $requestCode, $restName, $ownerName, $email, $phone, $restType, $tableCount, $preferredPlan, $message, $ip);
                                if ($stmt->execute()) {
                                    $reqId = $stmt->insert_id;
                                    $stmt->close();

                                    // Trigger In-Dashboard Super Admin Notification
                                    $notifTitle = "🔔 New Restaurant Demo Request: {$restName}";
                                    $notifMsg = "Owner: {$ownerName} | Phone: {$phone} | Email: {$email} | Plan: {$preferredPlan}";
                                    $conn->query("INSERT INTO notifications (restaurant_id, type, title, message, link) VALUES (NULL, 'onboarding_request', '" . $conn->real_escape_string($notifTitle) . "', '" . $conn->real_escape_string($notifMsg) . "', 'requests.php?id={$reqId}')");

                                    Security::logAudit("PUBLIC_ONBOARDING_REQUEST", "Submitted restaurant demo request {$requestCode} for {$restName}");
                                    $requestSuccess = true;
                                } else {
                                    $requestError = "Database error while processing your request. Please try again.";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// Fetch Active Subscription Plans from DB
$plans = [];
if ($conn) {
    $pRes = $conn->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price_monthly ASC");
    if ($pRes) {
        while ($p = $pRes->fetch_assoc()) {
            $plans[] = $p;
        }
    }
}

// Fallback Plan Tiers with NPR Pricing if table unpopulated
if (empty($plans)) {
    $plans = [
        ['id' => 1, 'plan_code' => 'STARTER', 'name' => 'Starter Plan', 'price_monthly' => 2999, 'max_tables' => 10, 'max_staff' => 3, 'features' => 'Basic POS, Digital Menu, QR Ordering, Cash Settlements, Standard Reports'],
        ['id' => 2, 'plan_code' => 'BUSINESS', 'name' => 'Business Plan', 'price_monthly' => 5999, 'max_tables' => 25, 'max_staff' => 10, 'features' => 'Full POS, KDS Kitchen Display, Inventory Control, Online Payment Gateways, Multi-Staff RBAC'],
        ['id' => 3, 'plan_code' => 'PRO', 'name' => 'Pro Plan', 'price_monthly' => 9999, 'max_tables' => 50, 'max_staff' => 25, 'features' => 'Enterprise KDS, Advanced Asset Management, Recipe Stock Audits, Real-Time Analytics, Priority SLA'],
        ['id' => 4, 'plan_code' => 'ENTERPRISE', 'name' => 'Enterprise Plan', 'price_monthly' => 19999, 'max_tables' => 100, 'max_staff' => 50, 'features' => 'Multi-Branch Management, Custom Integrations, Dedicated Account Manager, Priority SLA']
    ];
}

$csrfField = CSRF::getField();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100 scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#09090b">
    <title>RMS SaaS | Restaurant POS, QR Ordering & Inventory Management</title>
    <meta name="description" content="RMS SaaS helps restaurants manage POS, QR ordering, kitchen operations, tables, payments, inventory and restaurant operations from one connected platform.">
    <meta property="og:title" content="RMS SaaS — Run Your Restaurant From One Powerful Platform">
    <meta property="og:description" content="POS, QR ordering, kitchen operations, payments, inventory, tables and analytics — all connected in one system.">
    <meta property="og:type" content="website">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="min-h-full font-sans antialiased bg-zinc-950 text-zinc-100 selection:bg-amber-500 selection:text-zinc-950">

    <!-- 1. TOP ANNOUNCEMENT BAR -->
    <div class="bg-gradient-to-r from-amber-500 via-amber-400 to-amber-500 text-zinc-950 font-black text-xs py-2 px-4 text-center tracking-tight flex items-center justify-center space-x-2 shadow-inner">
        <span>⚡ Transform Your Restaurant Into a High-Efficiency Digital Operation.</span>
        <a href="#request-demo" class="underline hover:text-zinc-900 font-extrabold ml-1">Request Your RMS Demo →</a>
    </div>

    <!-- 2. STICKY NAVIGATION BAR -->
    <header class="sticky top-0 z-50 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
        <div class="max-w-7xl mx-auto flex items-center justify-between gap-4">
            <!-- Brand Logo -->
            <a href="index.php" class="flex items-center gap-3 group">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-amber-400 flex items-center justify-center text-zinc-950 text-xl font-black shadow-lg shadow-amber-500/20 group-hover:scale-105 transition-transform">
                    ⚡
                </div>
                <div>
                    <span class="text-lg font-black tracking-tight text-white block leading-tight">RMS SaaS</span>
                    <span class="text-[10px] text-zinc-400 font-bold uppercase tracking-wider block">Restaurant Operating System</span>
                </div>
            </a>
            
            <!-- Desktop Links -->
            <nav class="hidden lg:flex items-center gap-7 text-xs font-bold text-zinc-300">
                <a href="#features" class="hover:text-amber-400 transition-colors">Features</a>
                <a href="#showcase" class="hover:text-amber-400 transition-colors">Modules</a>
                <a href="#workflow" class="hover:text-amber-400 transition-colors">How It Works</a>
                <a href="#pricing" class="hover:text-amber-400 transition-colors">Pricing</a>
                <a href="#faq" class="hover:text-amber-400 transition-colors">FAQ</a>
            </nav>

            <!-- Action Buttons -->
            <div class="hidden sm:flex items-center gap-3">
                <a href="admin/login.php" class="px-4 py-2 rounded-xl border border-zinc-800 bg-zinc-900 text-xs font-bold text-zinc-300 hover:text-white hover:border-zinc-700 transition-all flex items-center space-x-1.5">
                    <span>🗝️</span>
                    <span>Restaurant Login</span>
                </a>
                <a href="super-admin/login.php" class="px-3.5 py-2 rounded-xl border border-amber-500/30 bg-amber-500/10 text-xs font-bold text-amber-400 hover:bg-amber-500/20 transition-all">
                    Super Admin ⚡
                </a>
                <a href="#request-demo" class="px-5 py-2.5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20 hover:bg-amber-400 transition-all">
                    Get Started →
                </a>
            </div>

            <!-- Mobile Hamburger Button -->
            <button id="mobile-menu-btn" aria-label="Toggle Navigation Menu" class="lg:hidden p-2.5 rounded-xl border border-zinc-800 bg-zinc-900 text-zinc-300 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
        </div>

        <!-- Mobile Responsive Dropdown Menu -->
        <div id="mobile-menu" class="hidden lg:hidden px-4 pt-4 pb-6 space-y-3 border-t border-zinc-800/80 mt-3 bg-zinc-950">
            <a href="#features" class="block px-3 py-2 rounded-xl text-sm font-bold text-zinc-300 hover:bg-zinc-900">Features</a>
            <a href="#showcase" class="block px-3 py-2 rounded-xl text-sm font-bold text-zinc-300 hover:bg-zinc-900">Modules</a>
            <a href="#workflow" class="block px-3 py-2 rounded-xl text-sm font-bold text-zinc-300 hover:bg-zinc-900">How It Works</a>
            <a href="#pricing" class="block px-3 py-2 rounded-xl text-sm font-bold text-zinc-300 hover:bg-zinc-900">Pricing</a>
            <a href="#faq" class="block px-3 py-2 rounded-xl text-sm font-bold text-zinc-300 hover:bg-zinc-900">FAQ</a>
            <div class="pt-3 border-t border-zinc-800 flex flex-col gap-2">
                <a href="admin/login.php" class="w-full text-center py-2.5 rounded-xl border border-zinc-800 bg-zinc-900 text-xs font-bold text-white">
                    Restaurant Login 🗝️
                </a>
                <a href="super-admin/login.php" class="w-full text-center py-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 text-xs font-bold text-amber-400">
                    Super Admin ⚡
                </a>
                <a href="#request-demo" class="w-full text-center py-3 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs">
                    Request a Demo →
                </a>
            </div>
        </div>
    </header>

    <!-- 3. HERO SECTION -->
    <section class="relative bg-zinc-950 overflow-hidden pt-12 pb-20 md:pt-20 md:pb-28 border-b border-zinc-800/80">
        <div class="absolute inset-0 bg-gradient-to-b from-amber-500/10 via-transparent to-zinc-950 pointer-events-none"></div>
        
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 space-y-12">
            <div class="max-w-4xl mx-auto text-center space-y-6">
                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold tracking-widest uppercase">
                    ALL-IN-ONE RESTAURANT OPERATING SYSTEM
                </div>
                
                <h1 class="text-4xl sm:text-6xl md:text-7xl font-black text-white tracking-tight leading-none">
                    Run Your Restaurant From One Powerful Platform
                </h1>
                
                <p class="text-base sm:text-lg md:text-xl text-zinc-400 max-w-3xl mx-auto leading-relaxed font-medium">
                    Manage POS, QR ordering, kitchen operations, tables, payments, inventory and analytics from one connected restaurant management system.
                </p>

                <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-2">
                    <a href="#request-demo" class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-sm active:scale-95 shadow-xl shadow-amber-500/20 hover:from-amber-400 hover:to-amber-300 transition-all flex items-center justify-center space-x-2">
                        <span>Request a Demo</span>
                        <span>→</span>
                    </a>
                    <a href="#showcase" class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-white font-bold text-sm hover:border-zinc-700 active:scale-95 transition-all">
                        Explore the Platform ↓
                    </a>
                </div>
            </div>

            <!-- Realistic RMS Dashboard UI Mockup Preview -->
            <div class="max-w-5xl mx-auto bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 sm:p-6 shadow-2xl space-y-6 relative overflow-hidden backdrop-blur">
                <!-- Top Window Header -->
                <div class="flex items-center justify-between border-b border-zinc-800 pb-4">
                    <div class="flex items-center space-x-2">
                        <div class="w-3 h-3 rounded-full bg-rose-500"></div>
                        <div class="w-3 h-3 rounded-full bg-amber-500"></div>
                        <div class="w-3 h-3 rounded-full bg-emerald-500"></div>
                        <span class="text-xs font-mono font-bold text-zinc-400 ml-2 hidden sm:inline">RMS Operations Center — Live Restaurant Workspace #1</span>
                    </div>
                    <div class="flex items-center space-x-3">
                        <span class="px-2.5 py-1 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase">Live System Operational</span>
                    </div>
                </div>

                <!-- Dashboard Preview Grid -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wider">Today's Revenue</div>
                        <div class="text-xl sm:text-2xl font-black text-white mt-1">NPR 48,250</div>
                        <div class="text-[10px] text-emerald-400 font-bold mt-1">↑ +14% vs yesterday</div>
                    </div>
                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wider">Active Orders</div>
                        <div class="text-xl sm:text-2xl font-black text-amber-400 mt-1">18 Batches</div>
                        <div class="text-[10px] text-zinc-500 font-medium mt-1">12 QR &bull; 6 POS</div>
                    </div>
                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wider">Floor Occupancy</div>
                        <div class="text-xl sm:text-2xl font-black text-white mt-1">14 / 20 Tables</div>
                        <div class="text-[10px] text-amber-400 font-bold mt-1">70% Capacity Occupied</div>
                    </div>
                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wider">Kitchen Queue (KDS)</div>
                        <div class="text-xl sm:text-2xl font-black text-emerald-400 mt-1">4 Tickets</div>
                        <div class="text-[10px] text-zinc-500 font-medium mt-1">Avg Prep Time: 12 min</div>
                    </div>
                </div>

                <!-- Live Orders Status Stream Table Preview -->
                <div class="bg-zinc-950 border border-zinc-800 rounded-2xl p-4 overflow-x-auto">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-black text-white uppercase tracking-wider">Realtime Live Order Monitor</span>
                        <span class="text-[10px] text-zinc-400 font-mono">Updated 2s ago</span>
                    </div>
                    <div class="space-y-2 min-w-[500px]">
                        <div class="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 flex items-center justify-between text-xs">
                            <div class="flex items-center space-x-3">
                                <span class="px-2 py-0.5 rounded-md bg-amber-500 text-zinc-950 font-black text-[10px]">Table 04</span>
                                <span class="font-bold text-white">Order #1084 &bull; Royal Chicken Biryani x2, Cold Coffee x2</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <span class="text-zinc-400 font-mono">NPR 1,450</span>
                                <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px] font-black">PREPARING</span>
                            </div>
                        </div>
                        <div class="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 flex items-center justify-between text-xs">
                            <div class="flex items-center space-x-3">
                                <span class="px-2 py-0.5 rounded-md bg-zinc-800 text-white font-black text-[10px]">Table 09</span>
                                <span class="font-bold text-white">Order #1083 &bull; Paneer Butter Masala x1, Butter Naan x4</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <span class="text-zinc-400 font-mono">NPR 920</span>
                                <span class="px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] font-black">READY</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 4. TRUST / VALUE STRIP -->
    <section class="border-b border-zinc-800/80 bg-zinc-900/50 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 text-center">
                <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center space-x-2">
                    <span class="text-base">⚡</span>
                    <span class="text-xs font-bold text-zinc-300">Real-Time Operations</span>
                </div>
                <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center space-x-2">
                    <span class="text-base">📱</span>
                    <span class="text-xs font-bold text-zinc-300">QR Table Ordering</span>
                </div>
                <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center space-x-2">
                    <span class="text-base">👨‍🍳</span>
                    <span class="text-xs font-bold text-zinc-300">Kitchen KDS</span>
                </div>
                <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center space-x-2">
                    <span class="text-base">📦</span>
                    <span class="text-xs font-bold text-zinc-300">Inventory Control</span>
                </div>
                <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center space-x-2">
                    <span class="text-base">💳</span>
                    <span class="text-xs font-bold text-zinc-300">Digital Payments</span>
                </div>
                <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center space-x-2">
                    <span class="text-base">📊</span>
                    <span class="text-xs font-bold text-zinc-300">Business Analytics</span>
                </div>
            </div>
        </div>
    </section>

    <!-- 5. WHY RMS? SECTION (PROBLEM VS SOLUTION) -->
    <section id="features" class="py-20 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Stop Managing Your Restaurant With Disconnected Tools</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium">Eliminate order errors, kitchen delays, and inventory leaks with a single unified platform.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Left Column: Traditional Operations -->
                <div class="bg-zinc-900/60 border border-rose-500/20 rounded-3xl p-8 space-y-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center justify-center text-xl font-black">✕</div>
                        <h3 class="text-xl font-black text-white">Traditional Restaurant Operations</h3>
                    </div>
                    <ul class="space-y-3 text-xs sm:text-sm text-zinc-400 font-medium">
                        <li class="flex items-start space-x-3">
                            <span class="text-rose-400 font-bold mt-0.5">✕</span>
                            <span>Manual paper order tickets causing order loss & delay</span>
                        </li>
                        <li class="flex items-start space-x-3">
                            <span class="text-rose-400 font-bold mt-0.5">✕</span>
                            <span>No communication between kitchen staff and front table waiters</span>
                        </li>
                        <li class="flex items-start space-x-3">
                            <span class="text-rose-400 font-bold mt-0.5">✕</span>
                            <span>Separate, disconnected inventory spreadsheets and stock counts</span>
                        </li>
                        <li class="flex items-start space-x-3">
                            <span class="text-rose-400 font-bold mt-0.5">✕</span>
                            <span>Unnoticed ingredient waste and stock shortages during peak hours</span>
                        </li>
                        <li class="flex items-start space-x-3">
                            <span class="text-rose-400 font-bold mt-0.5">✕</span>
                            <span>Manual table occupancy tracking causing long guest wait times</span>
                        </li>
                        <li class="flex items-start space-x-3">
                            <span class="text-rose-400 font-bold mt-0.5">✕</span>
                            <span>End-of-day cash and digital payment reconciliation headaches</span>
                        </li>
                    </ul>
                </div>

                <!-- Right Column: With RMS -->
                <div class="bg-zinc-900/80 border border-emerald-500/30 rounded-3xl p-8 space-y-6 shadow-2xl relative">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center justify-center text-xl font-black">✓</div>
                        <h3 class="text-xl font-black text-white">With RMS SaaS Platform</h3>
                    </div>
                    <ul class="space-y-3 text-xs sm:text-sm text-zinc-300 font-semibold">
                        <li class="flex items-start space-x-3">
                            <span class="text-emerald-400 font-bold mt-0.5">✓</span>
                            <span>Instant digital ordering via table QR codes & POS terminals</span>
                        </li>
                        <li class="flex items-start space-x-3">
                            <span class="text-emerald-400 font-bold mt-0.5">✓</span>
                            <span>Real-time Kitchen Display System (KDS) with live timers</span>
                        </li>
                        <li class="flex items-start space-x-3">
                            <span class="text-emerald-400 font-bold mt-0.5">✓</span>
                            <span>Automatic ingredient deduction based on exact menu recipes</span>
                        </li>
                        <li class="flex items-start space-x-3">
                            <span class="text-emerald-400 font-bold mt-0.5">✓</span>
                            <span>Automated low-stock alerts, purchase orders, and supplier tracking</span>
                        </li>
                        <li class="flex items-start space-x-3">
                            <span class="text-emerald-400 font-bold mt-0.5">✓</span>
                            <span>Live interactive floor map with vacant/occupied table status</span>
                        </li>
                        <li class="flex items-start space-x-3">
                            <span class="text-emerald-400 font-bold mt-0.5">✓</span>
                            <span>Centralized digital payment records with eSewa, Khalti & Cash</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- 6. PRODUCT SHOWCASE -->
    <section id="showcase" class="py-20 border-b border-zinc-800/80 bg-zinc-900/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Everything Your Restaurant Needs — In One Dashboard</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium">Explore the actual modules powering daily restaurant operations.</p>
            </div>

            <!-- Showcase Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- 1. Operations Center -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 hover:border-amber-500/40 transition-all flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl font-black">📊</div>
                        <h3 class="text-lg font-black text-white">1. Operations Center</h3>
                        <p class="text-xs text-zinc-400 leading-relaxed">Central command hub providing real-time sales aggregations, active dining orders, and floor stats.</p>
                    </div>
                    <ul class="text-[11px] text-zinc-300 space-y-1.5 font-medium border-t border-zinc-800 pt-3">
                        <li>⚡ Real-time SSE dashboard updates</li>
                        <li>📈 Today's total sales & order counts</li>
                        <li>🔔 Waiter call alerts</li>
                    </ul>
                </div>

                <!-- 2. Floor & Tables -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 hover:border-amber-500/40 transition-all flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl font-black">📍</div>
                        <h3 class="text-lg font-black text-white">2. Floor & Tables</h3>
                        <p class="text-xs text-zinc-400 leading-relaxed">Visual floor management with table status indicators, assigned waiters, guest counts, and QR generation.</p>
                    </div>
                    <ul class="text-[11px] text-zinc-300 space-y-1.5 font-medium border-t border-zinc-800 pt-3">
                        <li>🪑 Vacant, occupied & reserved status</li>
                        <li>📱 Cryptographic QR token generator</li>
                        <li>👤 Assigned waiter tracking</li>
                    </ul>
                </div>

                <!-- 3. Orders Queue -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 hover:border-amber-500/40 transition-all flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl font-black">📋</div>
                        <h3 class="text-lg font-black text-white">3. Orders Queue</h3>
                        <p class="text-xs text-zinc-400 leading-relaxed">Master list of table orders, batch additions, status transitions, and customer notes.</p>
                    </div>
                    <ul class="text-[11px] text-zinc-300 space-y-1.5 font-medium border-t border-zinc-800 pt-3">
                        <li>🔄 Multi-round order batching</li>
                        <li>💳 Instant bill settlement link</li>
                        <li>❌ Order cancellation & refund handling</li>
                    </ul>
                </div>

                <!-- 4. Kitchen Display System (KDS) -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 hover:border-amber-500/40 transition-all flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl font-black">👨‍🍳</div>
                        <h3 class="text-lg font-black text-white">4. Kitchen KDS</h3>
                        <p class="text-xs text-zinc-400 leading-relaxed">Digital kitchen tickets for chefs with prep time counters, dietary tags, and preparation status controls.</p>
                    </div>
                    <ul class="text-[11px] text-zinc-300 space-y-1.5 font-medium border-t border-zinc-800 pt-3">
                        <li>⏱️ Live order preparation timers</li>
                        <li>🌱 Veg / Non-Veg / Vegan tags</li>
                        <li>🔔 Audio alert on new tickets</li>
                    </ul>
                </div>

                <!-- 5. Menu Management -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 hover:border-amber-500/40 transition-all flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl font-black">🍔</div>
                        <h3 class="text-lg font-black text-white">5. Menu Catalog</h3>
                        <p class="text-xs text-zinc-400 leading-relaxed">Organize categories, dietary tags, prices, cost prices, allergens, and instant sold-out stock toggles.</p>
                    </div>
                    <ul class="text-[11px] text-zinc-300 space-y-1.5 font-medium border-t border-zinc-800 pt-3">
                        <li>🏷️ Category & subcategory hierarchy</li>
                        <li>🚫 Instant sold-out item toggle</li>
                        <li>➕ Addon customizations & pricing</li>
                    </ul>
                </div>

                <!-- 6. Inventory Management -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 hover:border-amber-500/40 transition-all flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl font-black">📦</div>
                        <h3 class="text-lg font-black text-white">6. Inventory Control</h3>
                        <p class="text-xs text-zinc-400 leading-relaxed">Track raw ingredients, recipe deductions, purchase orders, suppliers, stock audits, and waste logs.</p>
                    </div>
                    <ul class="text-[11px] text-zinc-300 space-y-1.5 font-medium border-t border-zinc-800 pt-3">
                        <li>🌾 Automatic recipe stock deduction</li>
                        <li>⚠️ Low-stock alert thresholds</li>
                        <li>📑 Purchase orders & supplier profiles</li>
                    </ul>
                </div>

                <!-- 7. Asset Management -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 hover:border-amber-500/40 transition-all flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl font-black">🛠️</div>
                        <h3 class="text-lg font-black text-white">7. Asset Management</h3>
                        <p class="text-xs text-zinc-400 leading-relaxed">Register physical equipment, warranty expiration tracking, maintenance logs, and asset QR scans.</p>
                    </div>
                    <ul class="text-[11px] text-zinc-300 space-y-1.5 font-medium border-t border-zinc-800 pt-3">
                        <li>🏷️ Asset tag barcode & QR scans</li>
                        <li>🛡️ Warranty expiration reminders</li>
                        <li>🔧 Equipment maintenance logs</li>
                    </ul>
                </div>

                <!-- 8. Security & IAM -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 hover:border-amber-500/40 transition-all flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-xl font-black">🛡️</div>
                        <h3 class="text-lg font-black text-white">8. Security & IAM</h3>
                        <p class="text-xs text-zinc-400 leading-relaxed">Manage staff user roles, multi-tenant isolation rules, session timeout policies, and audit logs.</p>
                    </div>
                    <ul class="text-[11px] text-zinc-300 space-y-1.5 font-medium border-t border-zinc-800 pt-3">
                        <li>👥 Granular RBAC permissions</li>
                        <li>🔒 Strict tenant data isolation</li>
                        <li>📋 Complete security audit trail</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- 7. CORE MODULES GRID -->
    <section id="modules" class="py-20 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">12 Integrated Core Modules</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium">Outcome-focused capabilities engineered for seamless restaurant performance.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">🖥️</div>
                    <h3 class="text-base font-black text-white">POS & Billing</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Fast, intuitive billing interface for cashiers with instant bill generation and dining session settlement.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">📱</div>
                    <h3 class="text-base font-black text-white">QR Table Ordering</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Let customers scan a table QR code, browse your live menu and place orders directly from their phones.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">👨‍🍳</div>
                    <h3 class="text-base font-black text-white">Kitchen Display System</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Real-time order tickets for chefs with preparation timers, order batching, and status updates.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">📍</div>
                    <h3 class="text-base font-black text-white">Floor & Table Management</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Live floor map tracking table occupancy, guest counts, assigned waiters, and table reservation status.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">📋</div>
                    <h3 class="text-base font-black text-white">Order Management</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Filter and process new, preparing, ready, and served orders across all active dining sessions.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">🍔</div>
                    <h3 class="text-base font-black text-white">Menu Management</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Organize categories, dietary tags, allergens, calories, and sold-out stock toggles in seconds.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">📦</div>
                    <h3 class="text-base font-black text-white">Inventory Management</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Track ingredient stock levels, low-stock alerts, purchase orders, supplier profiles, and recipe deductions.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">🛠️</div>
                    <h3 class="text-base font-black text-white">Asset Management</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Register equipment assets, warranty dates, depreciation schedules, maintenance logs, and QR tags.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">💳</div>
                    <h3 class="text-base font-black text-white">Payment Management</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Configure gateway keys, manage cash register balances, and settle payments with idempotency protection.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">👥</div>
                    <h3 class="text-base font-black text-white">Staff & Roles</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Assign granular RBAC roles for Owners, Managers, Cashiers, Kitchen, Waiters, and Inventory Managers.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">📊</div>
                    <h3 class="text-base font-black text-white">Reports & Analytics</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Gain insights into daily sales, popular dishes, cost margins, peak hours, and staff performance.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-3">
                    <div class="text-2xl">🛡️</div>
                    <h3 class="text-base font-black text-white">Security & IAM</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Enforce multi-tenant data isolation, session timeout policies, rate limiting, and security audit logs.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 8. REAL-TIME OPERATIONS SECTION -->
    <section class="py-20 border-b border-zinc-800/80 bg-zinc-900/40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div class="space-y-6">
                    <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[10px] font-extrabold uppercase tracking-wider">
                        ⚡ REAL-TIME MONITORING
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">
                        Know What's Happening Across Your Restaurant — In Real Time
                    </h2>
                    <p class="text-sm sm:text-base text-zinc-400 leading-relaxed font-medium">
                        Never miss a ticket or order status update. The live Operations Center streams realtime updates across floor tables, kitchen KDS screens, and cashier terminals.
                    </p>
                    <div class="grid grid-cols-2 gap-4 text-xs font-bold text-zinc-300">
                        <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center space-x-2">
                            <span class="text-amber-400">⚡</span>
                            <span>Live Order Batches</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center space-x-2">
                            <span class="text-amber-400">👨‍🍳</span>
                            <span>Kitchen Workload</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center space-x-2">
                            <span class="text-amber-400">📍</span>
                            <span>Table Occupancy</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center space-x-2">
                            <span class="text-amber-400">💳</span>
                            <span>Payment Due Status</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center space-x-2">
                            <span class="text-amber-400">📦</span>
                            <span>Low Stock Alerts</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center space-x-2">
                            <span class="text-amber-400">📊</span>
                            <span>Realtime Revenue</span>
                        </div>
                    </div>
                </div>

                <!-- Real-time Metrics Card Graphic -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-2xl">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <span class="text-xs font-black text-white uppercase tracking-wider">Live Stream Operations Monitor</span>
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                    </div>
                    <div class="space-y-3">
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold text-white block">Table 05 &bull; Dining Session Active</span>
                                <span class="text-[10px] text-zinc-400">Guest Count: 4 &bull; Waiter: Ramesh</span>
                            </div>
                            <span class="px-2.5 py-1 rounded-xl bg-amber-500/10 text-amber-400 text-[10px] font-black">NPR 2,150</span>
                        </div>
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold text-white block">KDS Alert &bull; Ticket #1085 Ready</span>
                                <span class="text-[10px] text-zinc-400">Chef: Station 1 &bull; Prep Time: 14 mins</span>
                            </div>
                            <span class="px-2.5 py-1 rounded-xl bg-emerald-500/10 text-emerald-400 text-[10px] font-black">SERVED</span>
                        </div>
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold text-white block">Stock Alert &bull; Chicken Breast (Low)</span>
                                <span class="text-[10px] text-rose-400 font-bold">Remaining: 3.5 kg (Min: 5 kg)</span>
                            </div>
                            <span class="px-2.5 py-1 rounded-xl bg-rose-500/10 text-rose-400 text-[10px] font-black">RESTOCK</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 9. QR ORDERING FLOW -->
    <section class="py-20 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Seamless 4-Step QR Dining Experience</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium">How table-side QR ordering works for your dining guests.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-3xl space-y-3 relative">
                    <div class="text-2xl font-black text-amber-400 font-mono">01</div>
                    <h3 class="text-base font-black text-white">Scan Table QR</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Customer scans the printed QR code on their dining table using any smartphone camera.</p>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-3xl space-y-3 relative">
                    <div class="text-2xl font-black text-amber-400 font-mono">02</div>
                    <h3 class="text-base font-black text-white">Browse Live Menu</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Customer views live menu items, photos, dietary tags, prices, and addon options in their browser.</p>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-3xl space-y-3 relative">
                    <div class="text-2xl font-black text-amber-400 font-mono">03</div>
                    <h3 class="text-base font-black text-white">Place Order</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Customer confirms items and submits order directly to the dining session without waiter delay.</p>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-3xl space-y-3 relative">
                    <div class="text-2xl font-black text-amber-400 font-mono">04</div>
                    <h3 class="text-base font-black text-white">Kitchen & POS Update</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Order instantly appears on kitchen KDS screens and cashier POS terminals for fulfillment.</p>
                </div>
            </div>

            <!-- Multi-Round Ordering Feature Callout -->
            <div class="p-6 rounded-3xl bg-amber-500/10 border border-amber-500/30 text-center max-w-3xl mx-auto space-y-2">
                <span class="text-xs font-black text-amber-400 uppercase tracking-wider">💡 Multi-Round Dining Sessions</span>
                <p class="text-xs sm:text-sm text-zinc-300 font-medium">
                    Customers can continue ordering additional items, drinks, or desserts from the same table session before the final bill is settled at checkout.
                </p>
            </div>
        </div>
    </section>

    <!-- 10. KITCHEN DISPLAY SYSTEM (KDS) -->
    <section class="py-20 border-b border-zinc-800/80 bg-zinc-900/40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Turn Every Order Into a Clear Kitchen Workflow</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium">Streamline kitchen ticket preparation with live timers and status transitions.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="p-6 rounded-3xl bg-zinc-900 border border-amber-500/30 space-y-2">
                    <span class="px-2.5 py-1 rounded-xl bg-amber-500/10 text-amber-400 text-[10px] font-black uppercase">Stage 1</span>
                    <h3 class="text-lg font-black text-white pt-1">New Orders</h3>
                    <p class="text-xs text-zinc-400">Incoming tickets audio-alert kitchen staff instantly with exact quantities and customizations.</p>
                </div>
                <div class="p-6 rounded-3xl bg-zinc-900 border border-blue-500/30 space-y-2">
                    <span class="px-2.5 py-1 rounded-xl bg-blue-500/10 text-blue-400 text-[10px] font-black uppercase">Stage 2</span>
                    <h3 class="text-lg font-black text-white pt-1">Preparing</h3>
                    <p class="text-xs text-zinc-400">Chefs mark ticket status to Preparing. Preparation timer starts counting for accuracy.</p>
                </div>
                <div class="p-6 rounded-3xl bg-zinc-900 border border-emerald-500/30 space-y-2">
                    <span class="px-2.5 py-1 rounded-xl bg-emerald-500/10 text-emerald-400 text-[10px] font-black uppercase">Stage 3</span>
                    <h3 class="text-lg font-black text-white pt-1">Ready</h3>
                    <p class="text-xs text-zinc-400">Marked Ready when food is plated. Waiter notification alerts assigned table server.</p>
                </div>
                <div class="p-6 rounded-3xl bg-zinc-900 border border-purple-500/30 space-y-2">
                    <span class="px-2.5 py-1 rounded-xl bg-purple-500/10 text-purple-400 text-[10px] font-black uppercase">Stage 4</span>
                    <h3 class="text-lg font-black text-white pt-1">Served</h3>
                    <p class="text-xs text-zinc-400">Ticket completed and archived. Dining session running total automatically updated.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 11. INVENTORY + ASSET MANAGEMENT -->
    <section class="py-20 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Control Your Stock, Supplies & Restaurant Assets</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium">Distinct, connected sub-systems to manage raw ingredient inventory and physical equipment assets.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Left: Inventory Features -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-8 space-y-6">
                    <div class="flex items-center space-x-3 border-b border-zinc-800 pb-4">
                        <span class="text-2xl">📦</span>
                        <h3 class="text-xl font-black text-white">Ingredient Inventory Management</h3>
                    </div>
                    <ul class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs text-zinc-300 font-semibold">
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">🌾 Stock items & units</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">📑 Purchase orders</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">🤝 Supplier profiles</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">📥 Goods receiving</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">⚖️ Stock adjustments</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">🗑️ Waste recording</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">⚠️ Low-stock alerts</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">🍲 Recipe deduction</li>
                    </ul>
                </div>

                <!-- Right: Asset Features -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-8 space-y-6">
                    <div class="flex items-center space-x-3 border-b border-zinc-800 pb-4">
                        <span class="text-2xl">🛠️</span>
                        <h3 class="text-xl font-black text-white">Equipment & Asset Management</h3>
                    </div>
                    <ul class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs text-zinc-300 font-semibold">
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">🏷️ Asset register & tags</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">📂 Asset categories</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">🛡️ Warranty tracking</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">🔧 Maintenance logs</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">📊 Depreciation calculations</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">📍 Location & status</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">📱 Asset QR code scans</li>
                        <li class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800">📜 Asset history logs</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- 12. PAYMENT INTEGRATIONS -->
    <section class="py-20 border-b border-zinc-800/80 bg-zinc-900/40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Flexible & Reliable Payment Processing</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium">Accept digital payments with clear status transparency.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
                <div class="bg-zinc-900 border border-emerald-500/30 rounded-3xl p-8 space-y-4">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="text-lg font-black text-white">Supported Payment Gateways</h3>
                        <span class="px-2.5 py-1 rounded-xl bg-emerald-500/10 text-emerald-400 text-[10px] font-black uppercase">Active Now</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-xs font-bold text-zinc-200">
                        <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center space-x-2">
                            <span>💵</span>
                            <span>Cash Register</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center space-x-2">
                            <span>💚</span>
                            <span>eSewa Wallet</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center space-x-2">
                            <span>💜</span>
                            <span>Khalti Wallet</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center space-x-2">
                            <span>📱</span>
                            <span>Fonepay QR</span>
                        </div>
                    </div>
                </div>

                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-8 space-y-4 opacity-80">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="text-lg font-black text-white">Planned Integrations</h3>
                        <span class="px-2.5 py-1 rounded-xl bg-zinc-800 text-zinc-400 text-[10px] font-black uppercase">Roadmap</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-xs font-bold text-zinc-400">
                        <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center space-x-2">
                            <span>🏦</span>
                            <span>ConnectIPS</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center space-x-2">
                            <span>🔴</span>
                            <span>IME Pay</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center space-x-2">
                            <span>💳</span>
                            <span>Card POS Terminals</span>
                        </div>
                        <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center space-x-2">
                            <span>🌐</span>
                            <span>International Cards</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 13. MULTI-TENANT SaaS ARCHITECTURE -->
    <section class="py-20 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">One Platform. Multiple Restaurants. Completely Isolated Workspaces.</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium">Enterprise multi-tenant security architecture ensuring absolute data privacy.</p>
            </div>

            <!-- Architecture Visual Diagram -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-8 max-w-4xl mx-auto space-y-6 shadow-2xl">
                <div class="text-center font-mono text-xs text-amber-400 font-bold bg-zinc-950 py-3 rounded-2xl border border-zinc-800">
                    RMS SaaS Platform Architecture
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="p-6 rounded-2xl bg-zinc-950 border border-amber-500/30 text-center space-y-3">
                        <div class="text-2xl">🏪</div>
                        <h4 class="text-sm font-black text-white">Restaurant A</h4>
                        <div class="text-[10px] text-zinc-400 font-mono">Tenant ID: #001</div>
                        <p class="text-[11px] text-zinc-500">Dedicated Menu, POS, Orders, Staff & Inventory Data</p>
                    </div>
                    <div class="p-6 rounded-2xl bg-zinc-950 border border-amber-500/30 text-center space-y-3">
                        <div class="text-2xl">🏬</div>
                        <h4 class="text-sm font-black text-white">Restaurant B</h4>
                        <div class="text-[10px] text-amber-400 font-mono">Tenant ID: #002</div>
                        <p class="text-[11px] text-zinc-500">Dedicated Menu, POS, Orders, Staff & Inventory Data</p>
                    </div>
                    <div class="p-6 rounded-2xl bg-zinc-950 border border-amber-500/30 text-center space-y-3">
                        <div class="text-2xl">🏤</div>
                        <h4 class="text-sm font-black text-white">Restaurant C</h4>
                        <div class="text-[10px] text-zinc-400 font-mono">Tenant ID: #003</div>
                        <p class="text-[11px] text-zinc-500">Dedicated Menu, POS, Orders, Staff & Inventory Data</p>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-400 leading-relaxed text-center font-medium">
                    🛡️ <strong>Tenant Data Isolation:</strong> Each restaurant operates inside its own secure workspace boundary. Cross-tenant access is strictly blocked at the session and database layer.
                </div>
            </div>
        </div>
    </section>

    <!-- 14. HOW ONBOARDING WORKS -->
    <section id="workflow" class="py-20 border-b border-zinc-800/80 bg-zinc-900/40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Get Your Restaurant Live in 4 Simple Steps</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-xl mx-auto font-medium">Controlled, Super-Admin-guided onboarding workflow.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-3xl space-y-3">
                    <div class="text-xl font-black text-amber-400 font-mono">01 — Request Access</div>
                    <h3 class="text-base font-black text-white">Submit Registration</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Restaurant owner submits the online request demo form below.</p>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-3xl space-y-3">
                    <div class="text-xl font-black text-amber-400 font-mono">02 — Review</div>
                    <h3 class="text-base font-black text-white">Super Admin Review</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Our platform team reviews your details, contacts you, and approves your request.</p>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-3xl space-y-3">
                    <div class="text-xl font-black text-amber-400 font-mono">03 — Account Provision</div>
                    <h3 class="text-base font-black text-white">Workspace Created</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Super Admin provisions your restaurant account and generates temporary credentials.</p>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-3xl space-y-3">
                    <div class="text-xl font-black text-amber-400 font-mono">04 — Setup & Go Live</div>
                    <h3 class="text-base font-black text-white">Configure & Launch</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Owner logs in, completes the 8-step setup wizard, prints table QR codes, and goes live!</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 15. PRICING -->
    <section id="pricing" class="py-20 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Transparent Plans for Every Restaurant Size</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-xl mx-auto font-medium">Nepal-friendly NPR pricing tailored to your seating capacity and operational scale.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach ($plans as $p): ?>
                    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-8 space-y-6 flex flex-col justify-between hover:border-amber-500/40 transition-all shadow-xl relative">
                        <?php if ($p['plan_code'] === 'BUSINESS'): ?>
                            <div class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full bg-amber-500 text-zinc-950 text-[10px] font-black uppercase tracking-wider">
                                Most Popular
                            </div>
                        <?php endif; ?>

                        <div class="space-y-4">
                            <span class="px-3 py-1 rounded-xl text-[10px] font-black uppercase tracking-wider bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                <?= htmlspecialchars($p['plan_code']) ?>
                            </span>
                            <h3 class="text-xl font-black text-white"><?= htmlspecialchars($p['name']) ?></h3>
                            <div class="text-3xl font-black text-white">
                                NPR <?= number_format($p['price_monthly']) ?> <span class="text-xs text-zinc-500 font-normal">/ month</span>
                            </div>
                            <div class="text-xs text-zinc-300 border-t border-zinc-800 pt-4 space-y-2 font-medium">
                                <div class="flex items-center space-x-2">
                                    <span class="text-emerald-400">✓</span>
                                    <span>Up to <strong><?= $p['max_tables'] ?></strong> Dining Tables</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-emerald-400">✓</span>
                                    <span>Up to <strong><?= $p['max_staff'] ?></strong> Staff Users</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-emerald-400">✓</span>
                                    <span><?= htmlspecialchars($p['features']) ?></span>
                                </div>
                            </div>
                        </div>

                        <a href="#request-demo" onclick="document.querySelector('select[name=preferred_plan]').value='<?= htmlspecialchars($p['plan_code']) ?>';" class="block w-full py-3.5 text-center rounded-2xl bg-zinc-800 hover:bg-amber-500 hover:text-zinc-950 text-white font-black text-xs transition-all">
                            Choose <?= htmlspecialchars($p['name']) ?> →
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- 16. SECURITY / TRUST SECTION -->
    <section class="py-20 border-b border-zinc-800/80 bg-zinc-900/40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Built for Secure Restaurant Operations</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-xl mx-auto font-medium">Commercial-grade security architecture protecting your business data.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-2">
                    <div class="text-2xl">🔐</div>
                    <h3 class="text-base font-black text-white">Tenant Data Isolation</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Logical database context scoping guarantees your menu, sales, and staff records remain completely isolated.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-2">
                    <div class="text-2xl">👥</div>
                    <h3 class="text-base font-black text-white">Role-Based Access</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Restrict staff permissions based on exact job duties (Owner, Manager, Cashier, Kitchen, Waiter, Inventory Manager).</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-2">
                    <div class="text-2xl">🛡️</div>
                    <h3 class="text-base font-black text-white">Secure Authentication</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">BCRYPT password hashing, forced temporary password changes, and session fixation defense.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-2">
                    <div class="text-2xl">📋</div>
                    <h3 class="text-base font-black text-white">Audit Logs</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Complete event log records tracking user logins, password updates, and support impersonation events.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-2">
                    <div class="text-2xl">🔑</div>
                    <h3 class="text-base font-black text-white">Session Management</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">HttpOnly, SameSite cookie flags and automatic 2-hour idle session timeout guards.</p>
                </div>

                <div class="p-6 rounded-3xl bg-zinc-900 border border-zinc-800 space-y-2">
                    <div class="text-2xl">🔒</div>
                    <h3 class="text-base font-black text-white">Protected Credentials</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">Payment gateway API keys and merchant tokens are stored per restaurant tenant with access controls.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 17. RESTAURANT REQUEST FORM -->
    <section id="request-demo" class="py-20 bg-zinc-950 border-b border-zinc-800/80">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Ready to Digitize Your Restaurant?</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-xl mx-auto font-medium">Submit your information to request a demo or register for your private RMS SaaS workspace.</p>
            </div>

            <?php if ($requestSuccess): ?>
                <div class="p-8 rounded-3xl bg-emerald-500/10 border border-emerald-500/30 text-center space-y-4 shadow-2xl">
                    <div class="w-16 h-16 rounded-3xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-3xl font-black mx-auto">
                        🎉
                    </div>
                    <h3 class="text-2xl font-black text-white">Request Received Successfully!</h3>
                    <p class="text-xs sm:text-sm text-zinc-300 max-w-lg mx-auto leading-relaxed font-medium">
                        Thanks for your interest in RMS SaaS! Our team will contact you shortly to discuss your restaurant setup and provision your workspace.
                    </p>
                </div>
            <?php else: ?>

                <?php if ($requestError): ?>
                    <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold text-center">
                        ⚠️ <?= htmlspecialchars($requestError) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 sm:p-8 space-y-6 shadow-2xl">
                    <?= $csrfField ?>
                    <input type="hidden" name="action" value="submit_restaurant_request">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-zinc-400 mb-1.5 uppercase tracking-wider">Restaurant Name *</label>
                            <input type="text" name="restaurant_name" required placeholder="e.g. Royal Taste Cafe" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-400 mb-1.5 uppercase tracking-wider">Owner Full Name *</label>
                            <input type="text" name="owner_name" required placeholder="e.g. Ramesh Sharma" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-400 mb-1.5 uppercase tracking-wider">Phone Number *</label>
                            <input type="text" name="phone" required placeholder="98XXXXXXXX" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-400 mb-1.5 uppercase tracking-wider">Email Address *</label>
                            <input type="email" name="email" required placeholder="owner@restaurant.com" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-400 mb-1.5 uppercase tracking-wider">Number of Tables *</label>
                            <input type="number" name="table_count" min="1" max="500" value="10" required class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-400 mb-1.5 uppercase tracking-wider">Restaurant Type</label>
                            <select name="restaurant_type" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white outline-none focus:border-amber-500 transition-colors">
                                <option value="Fine Dining">Fine Dining</option>
                                <option value="Casual Dining" selected>Casual Dining</option>
                                <option value="Fast Food / QSR">Fast Food / QSR</option>
                                <option value="Cafe & Bakery">Cafe & Bakery</option>
                                <option value="Food Court">Food Court</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-zinc-400 mb-1.5 uppercase tracking-wider">Preferred Plan</label>
                            <select name="preferred_plan" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white outline-none focus:border-amber-500 transition-colors">
                                <option value="STARTER">Starter Plan (NPR 2,999/mo)</option>
                                <option value="BUSINESS" selected>Business Plan (NPR 5,999/mo)</option>
                                <option value="PRO">Pro Plan (NPR 9,999/mo)</option>
                                <option value="ENTERPRISE">Enterprise Plan (Custom Pricing)</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-zinc-400 mb-1.5 uppercase tracking-wider">Requirements / Message</label>
                            <textarea name="message" rows="3" placeholder="Tell us about your restaurant requirements..." class="w-full bg-zinc-950 border border-zinc-800 rounded-2xl p-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors"></textarea>
                        </div>
                    </div>

                    <button type="submit" class="w-full h-14 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-sm hover:from-amber-400 hover:to-amber-300 transition-all shadow-xl shadow-amber-500/20 flex items-center justify-center space-x-2">
                        <span>Request a Demo</span>
                        <span>→</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- 18. FAQ SECTION -->
    <section id="faq" class="py-20 border-b border-zinc-800/80 bg-zinc-900/40">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
            <div class="text-center space-y-3">
                <h2 class="text-3xl sm:text-4xl font-black text-white tracking-tight">Frequently Asked Questions</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-xl mx-auto font-medium">Common questions about RMS SaaS platform implementation.</p>
            </div>

            <div class="space-y-4">
                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
                    <button onclick="toggleFaq(this)" class="w-full p-5 text-left flex items-center justify-between font-bold text-sm text-white hover:text-amber-400 transition-colors">
                        <span>What is RMS SaaS?</span>
                        <span class="faq-icon text-amber-400 font-mono text-lg">+</span>
                    </button>
                    <div class="faq-content hidden p-5 pt-0 text-xs text-zinc-400 leading-relaxed border-t border-zinc-800/50">
                        RMS SaaS is a multi-restaurant management software platform that integrates POS billing, QR table ordering, kitchen display screens (KDS), inventory control, payment management, and analytics into one connected cloud system.
                    </div>
                </div>

                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
                    <button onclick="toggleFaq(this)" class="w-full p-5 text-left flex items-center justify-between font-bold text-sm text-white hover:text-amber-400 transition-colors">
                        <span>How does restaurant onboarding work?</span>
                        <span class="faq-icon text-amber-400 font-mono text-lg">+</span>
                    </button>
                    <div class="faq-content hidden p-5 pt-0 text-xs text-zinc-400 leading-relaxed border-t border-zinc-800/50">
                        Restaurant owners submit a request demo form. The Super Admin team reviews the request, contacts the owner, provisions a private tenant workspace, and provides secure temporary credentials.
                    </div>
                </div>

                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
                    <button onclick="toggleFaq(this)" class="w-full p-5 text-left flex items-center justify-between font-bold text-sm text-white hover:text-amber-400 transition-colors">
                        <span>Is my restaurant data isolated and secure?</span>
                        <span class="faq-icon text-amber-400 font-mono text-lg">+</span>
                    </button>
                    <div class="faq-content hidden p-5 pt-0 text-xs text-zinc-400 leading-relaxed border-t border-zinc-800/50">
                        Yes. Each restaurant operates within a logically isolated tenant workspace (`restaurant_id`). Staff and customers from one restaurant cannot view or access data belonging to another restaurant.
                    </div>
                </div>

                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
                    <button onclick="toggleFaq(this)" class="w-full p-5 text-left flex items-center justify-between font-bold text-sm text-white hover:text-amber-400 transition-colors">
                        <span>Can customers order using QR codes?</span>
                        <span class="faq-icon text-amber-400 font-mono text-lg">+</span>
                    </button>
                    <div class="faq-content hidden p-5 pt-0 text-xs text-zinc-400 leading-relaxed border-t border-zinc-800/50">
                        Yes. Guests scan the printed QR code on their dining table using any smartphone camera to open the menu, customize items, and place orders directly to the kitchen.
                    </div>
                </div>

                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
                    <button onclick="toggleFaq(this)" class="w-full p-5 text-left flex items-center justify-between font-bold text-sm text-white hover:text-amber-400 transition-colors">
                        <span>Does RMS include a Kitchen Display System (KDS)?</span>
                        <span class="faq-icon text-amber-400 font-mono text-lg">+</span>
                    </button>
                    <div class="faq-content hidden p-5 pt-0 text-xs text-zinc-400 leading-relaxed border-t border-zinc-800/50">
                        Yes. The dedicated KDS displays incoming ticket batches to chefs in real time, complete with preparation timers, dietary tags, and preparation status controls.
                    </div>
                </div>

                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
                    <button onclick="toggleFaq(this)" class="w-full p-5 text-left flex items-center justify-between font-bold text-sm text-white hover:text-amber-400 transition-colors">
                        <span>Can I manage inventory and physical assets?</span>
                        <span class="faq-icon text-amber-400 font-mono text-lg">+</span>
                    </button>
                    <div class="faq-content hidden p-5 pt-0 text-xs text-zinc-400 leading-relaxed border-t border-zinc-800/50">
                        Yes. RMS contains separate sub-systems for raw ingredient inventory tracking (recipe deductions, low-stock alerts, purchase orders) and physical asset register management (warranty, maintenance logs, asset tags).
                    </div>
                </div>

                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
                    <button onclick="toggleFaq(this)" class="w-full p-5 text-left flex items-center justify-between font-bold text-sm text-white hover:text-amber-400 transition-colors">
                        <span>Which payment methods are supported?</span>
                        <span class="faq-icon text-amber-400 font-mono text-lg">+</span>
                    </button>
                    <div class="faq-content hidden p-5 pt-0 text-xs text-zinc-400 leading-relaxed border-t border-zinc-800/50">
                        Currently supported payment methods include Cash, eSewa, Khalti, and Fonepay. ConnectIPS and card terminal integrations are on the product roadmap.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 19. FINAL CTA -->
    <section class="py-20 bg-zinc-950 relative overflow-hidden">
        <div class="max-w-5xl mx-auto px-4 text-center relative z-10 space-y-6">
            <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight">Ready to Run Your Restaurant Smarter?</h2>
            <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium">Bring your restaurant's ordering, kitchen, tables, payments and inventory into one connected platform.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-4">
                <a href="#request-demo" class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-sm active:scale-95 shadow-xl shadow-amber-500/20">
                    Request a Demo →
                </a>
                <a href="#request-demo" class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-white font-bold text-sm hover:border-zinc-700">
                    Contact RMS
                </a>
            </div>
        </div>
    </section>

    <!-- 20. FOOTER -->
    <footer class="border-t border-zinc-800 bg-zinc-950 py-12 text-xs text-zinc-500">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
                <div class="space-y-3">
                    <div class="flex items-center space-x-2">
                        <div class="w-7 h-7 rounded-xl bg-amber-500 text-zinc-950 font-black flex items-center justify-center text-xs">⚡</div>
                        <span class="font-bold text-white text-sm">RMS SaaS Platform</span>
                    </div>
                    <p class="text-[11px] text-zinc-500 leading-relaxed">Enterprise Multi-Tenant Restaurant Operating System.</p>
                </div>
                <div class="space-y-2">
                    <div class="font-bold text-white text-xs uppercase tracking-wider">Product</div>
                    <a href="#features" class="block text-zinc-400 hover:text-amber-400">Features</a>
                    <a href="#showcase" class="block text-zinc-400 hover:text-amber-400">Modules</a>
                    <a href="#pricing" class="block text-zinc-400 hover:text-amber-400">Pricing Tiers</a>
                </div>
                <div class="space-y-2">
                    <div class="font-bold text-white text-xs uppercase tracking-wider">Company</div>
                    <a href="#workflow" class="block text-zinc-400 hover:text-amber-400">How It Works</a>
                    <a href="#request-demo" class="block text-zinc-400 hover:text-amber-400">Request Demo</a>
                </div>
                <div class="space-y-2">
                    <div class="font-bold text-white text-xs uppercase tracking-wider">Portals</div>
                    <a href="admin/login.php" class="block text-amber-400 hover:underline font-bold">Restaurant Login 🗝️</a>
                    <a href="super-admin/login.php" class="block text-zinc-400 hover:text-amber-400 font-bold">Super Admin ⚡</a>
                </div>
            </div>

            <div class="border-t border-zinc-800/80 pt-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-[11px]">
                <div>© 2026 RMS SaaS Platform. All rights reserved.</div>
                <div class="flex space-x-4">
                    <a href="#" class="hover:text-zinc-400">Privacy Policy</a>
                    <a href="#" class="hover:text-zinc-400">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Vanilla JavaScript for Interactivity -->
    <script>
        // Mobile menu toggle
        const menuBtn = document.getElementById('mobile-menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        if (menuBtn && mobileMenu) {
            menuBtn.addEventListener('click', () => {
                mobileMenu.classList.toggle('hidden');
            });
        }

        // FAQ Accordion toggle
        function toggleFaq(btn) {
            const container = btn.parentElement;
            const content = container.querySelector('.faq-content');
            const icon = container.querySelector('.faq-icon');
            if (content) {
                content.classList.toggle('hidden');
                if (icon) {
                    icon.textContent = content.classList.contains('hidden') ? '+' : '−';
                }
            }
        }
    </script>
</body>
</html>
