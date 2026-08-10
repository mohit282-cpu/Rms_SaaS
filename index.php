<?php
// index.php - RMS Multi-Restaurant SaaS Public Landing Website & Lead Generation Portal
require_once 'config.php';

Auth::startSession();
$conn = getDBConnection();

$requestSuccess = false;
$requestError = null;
$lastRequestCode = '';

// Canonical URL helper (no hardcoded host / no localhost URLs)
function rmsCanonicalUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    $uri    = strtok($uri, '?');
    return $scheme . '://' . $host . $uri;
}

// Inline SVG icon system (stroke-based, inherits currentColor)
function svg_icon($name, $class = 'w-5 h-5') {
    $icons = [
        'bolt'        => '<path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/>',
        'scan'        => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3z"/><path d="M21 14v.01M14 21h.01M17 21h4"/>',
        'terminal'    => '<path d="m4 8 4 4-4 4"/><path d="M12 16h8"/>',
        'monitor'     => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'chef'        => '<path d="M17 21a1 1 0 0 0 1-1v-5.35c0-.46.32-.84.73-1.04a4 4 0 0 0-2.14-7.59 5 5 0 0 0-9.18 0 4 4 0 0 0-2.14 7.59c.41.2.73.58.73 1.04V20a1 1 0 0 0 1 1Z"/><path d="M6 17h12"/>',
        'grid'        => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box'         => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'wrench'      => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'card'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'chart'       => '<path d="M3 3v18h18"/><path d="M8 17v-4"/><path d="M13 17V7"/><path d="M18 17v-7"/>',
        'shield'      => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'lock'        => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'check'       => '<path d="M20 6 9 17l-5-5"/>',
        'x'           => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'arrow'       => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'menu'        => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'phone'       => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'clock'       => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'plus'        => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'activity'    => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
        'login'       => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        'utensils'    => '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
        'receipt'     => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'spark'       => '<path d="m12 3 1.912 5.813a2 2 0 0 0 1.275 1.275L21 12l-5.813 1.912a2 2 0 0 0-1.275 1.275L12 21l-1.912-5.813a2 2 0 0 0-1.275-1.275L3 12l5.813-1.912a2 2 0 0 0 1.275-1.275L12 3z"/>',
        'split'         => '<path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.17-2.83L4 4"/><path d="m20 4-6.83 6.87A4 4 0 0 0 12 13.7"/>',
        'calendar'      => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'badge-percent' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="M9 9h.01"/><path d="M15 15h.01"/>',
        'rocket'        => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-3.05 11a22.35 22.35 0 0 1-3.95 2z"/><path d="M9 20a22 22 0 0 1 2-3.95"/>',
        'user-check'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
    ];
    if (!isset($icons[$name])) {
        return '';
    }
    return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[$name] . '</svg>';
}

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
            $panNumber = Security::sanitize(trim($_POST['pan_number'] ?? ''));
            $address = Security::sanitize(trim($_POST['address'] ?? ''));
            $restType = Security::sanitize(trim($_POST['restaurant_type'] ?? 'Casual Dining'));
            $tableCount = max(1, min(1000, (int)($_POST['table_count'] ?? 10)));
            $preferredPlan = Security::sanitize(trim($_POST['preferred_plan'] ?? 'BUSINESS'));
            $currentPos = Security::sanitize(trim($_POST['current_system'] ?? ''));
            $userMsg = Security::sanitize(trim($_POST['message'] ?? ''));

            $message = $userMsg;
            if (!empty($currentPos)) {
                $message = "Current POS/System: " . $currentPos . ($userMsg ? "\n\nNote: " . $userMsg : "");
            }

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
                                (request_code, restaurant_name, owner_name, email, phone, pan_number, address, restaurant_type, table_count, preferred_plan, message, status, ip_address)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)
                            ");
                            if ($stmt) {
                                $stmt->bind_param("ssssssssisss", $requestCode, $restName, $ownerName, $email, $phone, $panNumber, $address, $restType, $tableCount, $preferredPlan, $message, $ip);
                                if ($stmt->execute()) {
                                    $reqId = $stmt->insert_id;
                                    $stmt->close();
                                    $lastRequestCode = $requestCode;

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

// Static NPR pricing tiers
$pricingPlans = [
    [
        'code'    => 'ESSENTIAL',
        'name'    => 'Essential',
        'price'   => 'NPR 1,500',
        'suffix'  => '/ month',
        'tagline' => 'For small cafés & boutique diners needing fast ordering & billing.',
        'cta'     => 'Request Essential',
        'popular' => false,
        'features'=> [
            'RPOS Cashier Register & Billing',
            'Digital QR Code Table Ordering',
            'Kitchen Display System (KDS)',
            'Floor & Table Status Map',
            'Basic Revenue Reports',
            'Single Tenant Account',
            'Up to 3 Staff Accounts',
        ],
    ],
    [
        'code'    => 'BUSINESS',
        'name'    => 'Business',
        'price'   => 'NPR 2,500',
        'suffix'  => '/ month',
        'tagline' => 'For busy, high-volume restaurants requiring complete operations control.',
        'cta'     => 'Request Business',
        'popular' => true,
        'features'=> [
            'Everything in Essential, plus:',
            'Split Bill & Partial Payments',
            'NCR / Complimentary Billing',
            'Loyalty Points & Customer Profiles',
            'Ingredient & Recipe Stock Deductions',
            'Nepal Digital Wallets (eSewa, Khalti)',
            'Waste & Reorder Stock Alerts',
            'Up to 10 Staff Roles & Accounts',
        ],
    ],
    [
        'code'    => 'BUSINESS_PRO',
        'name'    => 'Business Pro',
        'price'   => 'NPR 4,500',
        'suffix'  => '/ month',
        'tagline' => 'For multi-station establishments demanding advanced governance & inventory.',
        'cta'     => 'Request Business Pro',
        'popular' => false,
        'features'=> [
            'Everything in Business, plus:',
            'Kitchen Asset & Warranty Register',
            'Purchase Order Management',
            'Full RBAC Role & Permission Schemas',
            'Audit Logging & Security Controls',
            'Product Performance & Margin Analytics',
            'Unlimited Staff Accounts',
            'Priority Onboarding & Dedicated Support',
        ],
    ],
    [
        'code'    => 'ENTERPRISE',
        'name'    => 'Enterprise',
        'price'   => 'Custom Pricing',
        'suffix'  => '',
        'tagline' => 'For multi-branch restaurant chains and franchise networks.',
        'cta'     => 'Contact Sales',
        'popular' => false,
        'features'=> [
            'Tailored multi-location workspace',
            'Centralized Franchise Analytics',
            'Custom Payment Gateway Connectors',
            'Custom SLA & Automated Backups',
            'Dedicated Account Manager',
            'On-Site Staff Training & Setup',
            'Direct API & Export Access',
        ],
    ],
];

$csrfField = CSRF::getField();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth bg-[#090909]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#090909">
    <title>RMS SaaS — Restaurant Management System</title>
    <meta name="description" content="Run your entire restaurant with one connected platform for RPOS, QR ordering, kitchen display, table management, billing, inventory, staff, and analytics.">
    <link rel="canonical" href="<?= rmsCanonicalUrl() ?>">

    <!-- Open Graph / Social -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="RMS SaaS">
    <meta property="og:title" content="RMS SaaS — Restaurant Management System">
    <meta property="og:description" content="Run your entire restaurant from one powerful platform: RPOS, KDS, Table Management, Billing, Inventory, QR Ordering, Staff Management & Analytics.">
    <meta property="og:url" content="<?= rmsCanonicalUrl() ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="RMS SaaS — Restaurant Management System">
    <meta name="twitter:description" content="Commercial restaurant operating system for modern venues. POS, QR ordering, KDS, inventory & real-time analytics.">

    <!-- Favicon (inline SVG) -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23f59e0b'/%3E%3Cpath d='M17.5 4 8 18h6.5L13 28l9.5-14H16l1.5-10z' fill='%2309090b'/%3E%3C/svg%3E">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            bg: '#090909',
                            surface: '#111111',
                            surface2: '#161616',
                            border: '#242424',
                            amber: '#f59e0b',
                            amberHover: '#d97706'
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background-color: #090909; color: #FFFFFF; }
        ::selection { background: #f59e0b; color: #090909; }

        /* Anchor offset below sticky header */
        [id] { scroll-margin-top: 96px; }

        /* Focus states */
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
            outline: 2px solid #f59e0b;
            outline-offset: 2px;
            border-radius: 8px;
        }

        /* Subtle grid background */
        .bg-subtle-grid {
            background-image:
                linear-gradient(to right, rgba(255, 255, 255, 0.025) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255, 255, 255, 0.025) 1px, transparent 1px);
            background-size: 48px 48px;
        }

        /* Ambient glow for hero screenshot frame */
        .ambient-glow {
            box-shadow: 0 0 70px -15px rgba(245, 158, 11, 0.18), 0 25px 50px -12px rgba(0, 0, 0, 0.95);
        }

        /* FAQ chevron animation */
        .faq-btn[aria-expanded="true"] .faq-icon { transform: rotate(180deg); }
        .faq-icon { transition: transform .25s ease; }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-[#090909] text-white antialiased font-sans">

    <!-- ============ STICKY NAVIGATION ============ -->
    <header class="sticky top-0 z-50 bg-[#090909]/95 backdrop-blur-md border-b border-[#242424]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Brand Logo -->
                <a href="index.php" class="flex items-center gap-3 group" aria-label="RMS SaaS Homepage">
                    <div class="w-10 h-10 rounded-xl bg-amber-500 flex items-center justify-center text-[#090909] font-black group-hover:scale-105 transition-transform shadow-md">
                        <?= svg_icon('bolt', 'w-5 h-5 stroke-[2.4]') ?>
                    </div>
                    <div class="leading-none">
                        <span class="block text-lg font-extrabold tracking-tight text-white">RMS SaaS</span>
                        <span class="block text-[10px] font-bold uppercase tracking-widest text-zinc-400 mt-0.5">Restaurant Platform</span>
                    </div>
                </a>

                <!-- Desktop links -->
                <nav class="hidden md:flex items-center gap-8 text-sm font-semibold text-zinc-300" aria-label="Main Navigation">
                    <a href="#showcase" class="hover:text-amber-400 transition-colors">Product</a>
                    <a href="#features" class="hover:text-amber-400 transition-colors">Features</a>
                    <a href="#how-it-works" class="hover:text-amber-400 transition-colors">How It Works</a>
                    <a href="#pricing" class="hover:text-amber-400 transition-colors">Pricing</a>
                    <a href="#faq" class="hover:text-amber-400 transition-colors">FAQ</a>
                </nav>

                <!-- Action CTAs -->
                <div class="hidden sm:flex items-center gap-3.5">
                    <a href="admin/login.php" class="px-4 py-2.5 rounded-xl border border-[#242424] bg-[#111111] text-xs font-bold text-zinc-200 hover:text-white hover:border-zinc-600 transition-all">
                        Login
                    </a>
                    <a href="#request-demo" class="px-5 py-2.5 rounded-xl bg-amber-500 text-[#090909] text-xs font-extrabold hover:bg-amber-400 active:scale-95 transition-all shadow-md">
                        Request Demo
                    </a>
                </div>

                <!-- Mobile menu toggle button -->
                <button id="mobile-menu-btn" type="button" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="mobile-menu" class="md:hidden p-2.5 rounded-xl border border-[#242424] bg-[#111111] text-zinc-300 hover:text-white">
                    <?= svg_icon('menu', 'w-6 h-6') ?>
                </button>
            </div>
        </div>

        <!-- Mobile Drawer Navigation -->
        <div id="mobile-menu" class="hidden md:hidden border-t border-[#242424] bg-[#090909] px-4 pt-3 pb-6 space-y-3">
            <a href="#showcase" class="mobile-link block py-2 text-sm font-semibold text-zinc-300 hover:text-amber-400">Product Showcase</a>
            <a href="#features" class="mobile-link block py-2 text-sm font-semibold text-zinc-300 hover:text-amber-400">Features</a>
            <a href="#how-it-works" class="mobile-link block py-2 text-sm font-semibold text-zinc-300 hover:text-amber-400">How It Works</a>
            <a href="#pricing" class="mobile-link block py-2 text-sm font-semibold text-zinc-300 hover:text-amber-400">Pricing</a>
            <a href="#faq" class="mobile-link block py-2 text-sm font-semibold text-zinc-300 hover:text-amber-400">FAQ</a>
            <div class="pt-3 border-t border-[#242424] flex flex-col gap-2.5">
                <a href="admin/login.php" class="w-full text-center py-2.5 rounded-xl border border-[#242424] bg-[#111111] text-xs font-bold text-zinc-200">
                    Restaurant Login
                </a>
                <a href="#request-demo" class="w-full text-center py-3 rounded-xl bg-amber-500 text-[#090909] text-xs font-extrabold">
                    Request a Demo
                </a>
            </div>
        </div>
    </header>

    <!-- ============ 1. HERO SECTION ============ -->
    <section class="relative pt-16 pb-20 md:pt-28 md:pb-32 border-b border-[#242424] overflow-hidden bg-subtle-grid">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl mx-auto text-center space-y-6">
                <!-- Trust Badge -->
                <div class="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full bg-[#111111] border border-[#242424] text-amber-400 text-xs font-extrabold uppercase tracking-wider">
                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                    <span>Built for Modern Restaurants</span>
                </div>

                <!-- Headline -->
                <h1 class="text-4xl sm:text-6xl lg:text-7xl font-black text-white tracking-tight leading-[1.05]">
                    Run Your Entire Restaurant From One Powerful Platform
                </h1>

                <!-- Supporting Copy explaining modules -->
                <p class="text-base sm:text-lg lg:text-xl text-[#A1A1AA] max-w-3xl mx-auto leading-relaxed font-normal">
                    Connect <strong class="text-white font-semibold">RPOS</strong>, <strong class="text-white font-semibold">Kitchen Display System</strong>, <strong class="text-white font-semibold">Table Management</strong>, <strong class="text-white font-semibold">Billing</strong>, <strong class="text-white font-semibold">Inventory</strong>, <strong class="text-white font-semibold">QR Ordering</strong>, <strong class="text-white font-semibold">Staff Management</strong>, and <strong class="text-white font-semibold">Analytics</strong> into a single unified workspace.
                </p>

                <!-- Action CTAs -->
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-3">
                    <a href="#request-demo" class="w-full sm:w-auto inline-flex items-center justify-center gap-2.5 px-8 py-4 rounded-xl bg-amber-500 text-[#090909] font-extrabold text-sm hover:bg-amber-400 active:scale-95 transition-all shadow-lg">
                        Request a Demo
                        <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                    </a>
                    <a href="#showcase" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl bg-[#111111] border border-[#242424] text-white font-semibold text-sm hover:border-zinc-600 hover:bg-[#161616] active:scale-95 transition-all">
                        Explore Platform
                    </a>
                </div>

                <!-- Clean Trust Statement -->
                <div class="flex flex-wrap items-center justify-center gap-x-8 gap-y-2 pt-2 text-xs font-semibold text-zinc-400">
                    <span class="inline-flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Multi-Tenant SaaS</span>
                    <span class="inline-flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Real-Time KDS &amp; POS</span>
                    <span class="inline-flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Nepal Digital Payments</span>
                    <span class="inline-flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Enterprise RBAC</span>
                </div>
            </div>

            <!-- DOMINANT REAL PRODUCT SCREENSHOT PREVIEW -->
            <div class="relative max-w-5xl mx-auto mt-14 md:mt-20">
                <div class="rounded-2xl border border-[#242424] bg-[#111111] overflow-hidden ambient-glow">
                    <!-- Frame Header -->
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-[#242424] bg-[#161616]">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-rose-500/80"></span>
                            <span class="w-3 h-3 rounded-full bg-amber-500/80"></span>
                            <span class="w-3 h-3 rounded-full bg-emerald-500/80"></span>
                        </div>
                        <div class="flex items-center gap-2 px-4 py-1 rounded-md bg-[#090909] border border-[#242424] text-xs font-mono text-zinc-300">
                            <?= svg_icon('lock', 'w-3.5 h-3.5 text-emerald-400') ?>
                            app.rms-saas.com/workspace/live
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-500/10 text-emerald-400 text-[11px] font-bold">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            Live System
                        </span>
                    </div>

                    <!-- Inner Mockup Display -->
                    <div class="p-5 sm:p-8 space-y-6">
                        <!-- Top Operational Metrics -->
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="p-4 rounded-xl bg-[#090909] border border-[#242424]">
                                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Today's Revenue</div>
                                <div class="text-2xl font-black text-white mt-1">NPR 48,250</div>
                                <div class="text-xs font-semibold text-emerald-400 mt-1">↑ 14% vs yesterday</div>
                            </div>
                            <div class="p-4 rounded-xl bg-[#090909] border border-[#242424]">
                                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Active Tables</div>
                                <div class="text-2xl font-black text-amber-400 mt-1">14 / 20 Occupied</div>
                                <div class="text-xs text-zinc-400 mt-1">70% capacity active</div>
                            </div>
                            <div class="p-4 rounded-xl bg-[#090909] border border-[#242424]">
                                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Kitchen Queue</div>
                                <div class="text-2xl font-black text-white mt-1">4 Prep Tickets</div>
                                <div class="text-xs text-zinc-400 mt-1">Avg prep: 11 mins</div>
                            </div>
                            <div class="p-4 rounded-xl bg-[#090909] border border-[#242424]">
                                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">QR Digital Orders</div>
                                <div class="text-2xl font-black text-emerald-400 mt-1">32 Sessions</div>
                                <div class="text-xs text-zinc-400 mt-1">Direct guest orders</div>
                            </div>
                        </div>

                        <!-- Main Split Interface Mock -->
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- Live Orders Feed -->
                            <div class="lg:col-span-2 p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-4">
                                <div class="flex items-center justify-between border-b border-[#242424] pb-3">
                                    <span class="text-xs font-extrabold text-white uppercase tracking-wider">Live Order Monitor</span>
                                    <span class="text-xs text-zinc-400 font-mono">Updated just now</span>
                                </div>
                                <div class="space-y-3">
                                    <div class="p-3.5 rounded-xl bg-[#161616] border border-[#242424] flex items-center justify-between gap-4">
                                        <div class="flex items-center gap-3">
                                            <span class="px-2.5 py-1 rounded-lg bg-amber-500 text-[#090909] font-black text-xs">T-04</span>
                                            <div>
                                                <div class="text-xs font-bold text-white">2× Royal Chicken Biryani, 2× Cold Coffee</div>
                                                <div class="text-[11px] text-zinc-400 mt-0.5">QR Session #1085 &bull; Starters served</div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/20 text-xs font-bold">PREPARING</span>
                                            <div class="text-xs font-mono text-zinc-300 mt-1">NPR 1,450</div>
                                        </div>
                                    </div>

                                    <div class="p-3.5 rounded-xl bg-[#161616] border border-[#242424] flex items-center justify-between gap-4">
                                        <div class="flex items-center gap-3">
                                            <span class="px-2.5 py-1 rounded-lg bg-zinc-700 text-white font-black text-xs">T-09</span>
                                            <div>
                                                <div class="text-xs font-bold text-white">1× Paneer Masala, 4× Butter Naan</div>
                                                <div class="text-[11px] text-zinc-400 mt-0.5">Cashier Order &bull; Bill generated</div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold">READY</span>
                                            <div class="text-xs font-mono text-zinc-300 mt-1">NPR 920</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Live KDS Ticket Frame -->
                            <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-4">
                                <div class="flex items-center justify-between border-b border-[#242424] pb-3">
                                    <span class="text-xs font-extrabold text-white uppercase tracking-wider">KDS Prep Ticket</span>
                                    <span class="text-xs font-mono text-amber-400">08:42</span>
                                </div>
                                <div class="space-y-2 text-xs">
                                    <div class="flex justify-between text-zinc-400"><span>Table 05 &bull; 4 Guests</span><span class="text-zinc-500">Ticket #1085</span></div>
                                    <div class="border-t border-[#242424] pt-2 space-y-1.5">
                                        <div class="flex justify-between font-bold text-white"><span>Chicken Chowmein</span><span>×2</span></div>
                                        <div class="flex justify-between font-bold text-white"><span>Chicken Momo (steam)</span><span>×1</span></div>
                                        <div class="flex justify-between text-zinc-400"><span>Masala Tea</span><span>×2</span></div>
                                    </div>
                                    <div class="pt-2">
                                        <span class="px-2 py-1 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[11px] font-semibold">Station 1 &bull; Preparing</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 4. "EXPERIENCE RMS IN ACTION" (INTERACTIVE SHOWCASE) ============ -->
    <section id="showcase" class="py-20 md:py-28 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-12">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Experience RMS in Action
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Select a core restaurant module to view its specialized real-time interface.
                </p>
            </div>

            <!-- Tab Buttons -->
            <div class="flex flex-wrap justify-center gap-2.5 mb-10">
                <button type="button" class="tab-btn active px-5 py-3 rounded-xl border border-amber-500 bg-amber-500/10 text-amber-400 text-xs font-extrabold transition-all" data-tab="rpos">
                    RPOS Register
                </button>
                <button type="button" class="tab-btn px-5 py-3 rounded-xl border border-[#242424] bg-[#111111] text-zinc-400 hover:text-white text-xs font-extrabold transition-all" data-tab="kds">
                    Kitchen Display (KDS)
                </button>
                <button type="button" class="tab-btn px-5 py-3 rounded-xl border border-[#242424] bg-[#111111] text-zinc-400 hover:text-white text-xs font-extrabold transition-all" data-tab="floor">
                    Floor &amp; Tables
                </button>
                <button type="button" class="tab-btn px-5 py-3 rounded-xl border border-[#242424] bg-[#111111] text-zinc-400 hover:text-white text-xs font-extrabold transition-all" data-tab="billing">
                    Billing &amp; Payments
                </button>
                <button type="button" class="tab-btn px-5 py-3 rounded-xl border border-[#242424] bg-[#111111] text-zinc-400 hover:text-white text-xs font-extrabold transition-all" data-tab="inventory">
                    Inventory Control
                </button>
            </div>

            <!-- Tab Panels -->
            <div class="max-w-5xl mx-auto">
                <!-- RPOS Panel -->
                <div id="tab-rpos" class="tab-panel rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#242424] pb-6">
                        <div>
                            <h3 class="text-xl sm:text-2xl font-extrabold text-white">RPOS Register</h3>
                            <p class="text-xs sm:text-sm text-zinc-400 mt-1">Fast table-based billing and order management.</p>
                        </div>
                        <span class="px-3 py-1 rounded-lg bg-amber-500/10 text-amber-400 text-xs font-mono font-bold">Cashier POS Module</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="md:col-span-2 p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-4">
                            <div class="text-xs font-bold text-zinc-400">QUICK MENU SELECTION</div>
                            <div class="grid grid-cols-3 gap-2.5">
                                <div class="p-3 rounded-lg bg-[#161616] border border-[#242424] text-center"><div class="text-xs font-bold text-white">Chicken Biryani</div><div class="text-[11px] text-amber-400 font-mono mt-0.5">NPR 450</div></div>
                                <div class="p-3 rounded-lg bg-[#161616] border border-[#242424] text-center"><div class="text-xs font-bold text-white">Steam Momo</div><div class="text-[11px] text-amber-400 font-mono mt-0.5">NPR 200</div></div>
                                <div class="p-3 rounded-lg bg-[#161616] border border-[#242424] text-center"><div class="text-xs font-bold text-white">Veg Chowmein</div><div class="text-[11px] text-amber-400 font-mono mt-0.5">NPR 180</div></div>
                                <div class="p-3 rounded-lg bg-[#161616] border border-[#242424] text-center"><div class="text-xs font-bold text-white">Cold Coffee</div><div class="text-[11px] text-amber-400 font-mono mt-0.5">NPR 150</div></div>
                                <div class="p-3 rounded-lg bg-[#161616] border border-[#242424] text-center"><div class="text-xs font-bold text-white">Mango Lassi</div><div class="text-[11px] text-amber-400 font-mono mt-0.5">NPR 120</div></div>
                                <div class="p-3 rounded-lg bg-[#161616] border border-[#242424] text-center"><div class="text-xs font-bold text-white">Butter Naan</div><div class="text-[11px] text-amber-400 font-mono mt-0.5">NPR 60</div></div>
                            </div>
                        </div>
                        <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-3 text-xs">
                            <div class="font-bold text-white uppercase tracking-wider">ACTIVE REGISTER CART</div>
                            <div class="flex justify-between text-zinc-400"><span>Table 04 Session</span><span>#1085</span></div>
                            <div class="flex justify-between text-zinc-300"><span>2× Biryani</span><span>NPR 900</span></div>
                            <div class="flex justify-between text-zinc-300"><span>2× Cold Coffee</span><span>NPR 300</span></div>
                            <div class="flex justify-between text-zinc-400 pt-2 border-t border-[#242424]"><span>VAT (13%)</span><span>NPR 156</span></div>
                            <div class="flex justify-between font-extrabold text-white text-sm pt-2 border-t border-[#242424]"><span>TOTAL BILL</span><span>NPR 1,356</span></div>
                            <div class="pt-2"><button type="button" class="w-full py-2.5 bg-amber-500 text-[#090909] font-black rounded-lg text-xs">PRINT RECEIPT &amp; SETTLE</button></div>
                        </div>
                    </div>
                </div>

                <!-- KDS Panel -->
                <div id="tab-kds" class="tab-panel hidden rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#242424] pb-6">
                        <div>
                            <h3 class="text-xl sm:text-2xl font-extrabold text-white">Kitchen Display System</h3>
                            <p class="text-xs sm:text-sm text-zinc-400 mt-1">Send orders directly to kitchen workflows with audio alerts &amp; live timers.</p>
                        </div>
                        <span class="px-3 py-1 rounded-lg bg-amber-500/10 text-amber-400 text-xs font-mono font-bold">Kitchen Screen</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="p-4 rounded-xl bg-[#090909] border border-amber-500/30 space-y-3 text-xs">
                            <div class="flex justify-between font-bold"><span class="text-amber-400">Ticket #1085 &bull; Table 04</span><span class="text-zinc-400 font-mono">08:12</span></div>
                            <div class="space-y-1 font-semibold text-white">
                                <div>2× Royal Chicken Biryani</div>
                                <div>2× Cold Coffee</div>
                            </div>
                            <button type="button" class="w-full py-2 bg-amber-500 text-[#090909] font-black rounded-lg">MARK PREPARING</button>
                        </div>
                        <div class="p-4 rounded-xl bg-[#090909] border border-emerald-500/30 space-y-3 text-xs">
                            <div class="flex justify-between font-bold"><span class="text-emerald-400">Ticket #1084 &bull; Table 09</span><span class="text-zinc-400 font-mono">14:05</span></div>
                            <div class="space-y-1 font-semibold text-white">
                                <div>1× Paneer Butter Masala</div>
                                <div>4× Butter Naan</div>
                            </div>
                            <button type="button" class="w-full py-2 bg-emerald-500 text-[#090909] font-black rounded-lg">MARK READY</button>
                        </div>
                        <div class="p-4 rounded-xl bg-[#090909] border border-[#242424] space-y-3 text-xs opacity-60">
                            <div class="flex justify-between font-bold"><span class="text-zinc-400">Ticket #1083 &bull; Table 12</span><span class="text-zinc-500">Served</span></div>
                            <div class="space-y-1 text-zinc-400">
                                <div>2× Veg Chowmein</div>
                            </div>
                            <div class="text-[10px] text-zinc-400 font-bold text-center pt-2">COMPLETED</div>
                        </div>
                    </div>
                </div>

                <!-- Floor & Tables Panel -->
                <div id="tab-floor" class="tab-panel hidden rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#242424] pb-6">
                        <div>
                            <h3 class="text-xl sm:text-2xl font-extrabold text-white">Floor &amp; Tables Management</h3>
                            <p class="text-xs sm:text-sm text-zinc-400 mt-1">See table status and active orders in real time across floor layouts.</p>
                        </div>
                        <span class="px-3 py-1 rounded-lg bg-emerald-500/10 text-emerald-400 text-xs font-mono font-bold">Floor Map</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                        <div class="p-4 rounded-xl bg-[#090909] border border-amber-500/40 text-center space-y-1">
                            <div class="font-extrabold text-amber-400 text-base">Table 01</div>
                            <div class="text-white font-semibold">Occupied (4 Guests)</div>
                            <div class="text-[11px] text-zinc-400">NPR 2,400 &bull; Active</div>
                        </div>
                        <div class="p-4 rounded-xl bg-[#090909] border border-emerald-500/40 text-center space-y-1">
                            <div class="font-extrabold text-emerald-400 text-base">Table 02</div>
                            <div class="text-white font-semibold">Vacant</div>
                            <div class="text-[11px] text-zinc-400">Ready for guests</div>
                        </div>
                        <div class="p-4 rounded-xl bg-[#090909] border border-amber-500/40 text-center space-y-1">
                            <div class="font-extrabold text-amber-400 text-base">Table 03</div>
                            <div class="text-white font-semibold">Occupied (2 Guests)</div>
                            <div class="text-[11px] text-zinc-400">NPR 1,150 &bull; Active</div>
                        </div>
                        <div class="p-4 rounded-xl bg-[#090909] border border-[#242424] text-center space-y-1">
                            <div class="font-extrabold text-zinc-400 text-base">Table 04</div>
                            <div class="text-white font-semibold">Reserved</div>
                            <div class="text-[11px] text-zinc-400">Arrival at 7:30 PM</div>
                        </div>
                    </div>
                </div>

                <!-- Billing Panel -->
                <div id="tab-billing" class="tab-panel hidden rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#242424] pb-6">
                        <div>
                            <h3 class="text-xl sm:text-2xl font-extrabold text-white">Billing &amp; Payments</h3>
                            <p class="text-xs sm:text-sm text-zinc-400 mt-1">Complete customer payments directly from the counter with split &amp; NCR options.</p>
                        </div>
                        <span class="px-3 py-1 rounded-lg bg-amber-500/10 text-amber-400 text-xs font-mono font-bold">Counter Billing</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
                        <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-3">
                            <div class="font-bold text-white">SUPPORTED PAYMENT MODES</div>
                            <div class="grid grid-cols-2 gap-2">
                                <div class="p-3 rounded-lg bg-[#161616] border border-[#242424] text-center font-bold text-emerald-400">eSewa QR</div>
                                <div class="p-3 rounded-lg bg-[#161616] border border-[#242424] text-center font-bold text-purple-400">Khalti Pay</div>
                                <div class="p-3 rounded-lg bg-[#161616] border border-[#242424] text-center font-bold text-amber-400">Cash Settlement</div>
                                <div class="p-3 rounded-lg bg-[#161616] border border-[#242424] text-center font-bold text-blue-400">POS Card Swipe</div>
                            </div>
                        </div>
                        <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-3">
                            <div class="font-bold text-white">ADVANCED FINANCIAL ACTIONS</div>
                            <div class="space-y-2 text-zinc-300">
                                <div class="p-2.5 rounded bg-[#161616] flex justify-between"><span>Equal &amp; Item Split Bill</span><span class="text-amber-400 font-bold">Active</span></div>
                                <div class="p-2.5 rounded bg-[#161616] flex justify-between"><span>NCR / Complimentary Waiver</span><span class="text-emerald-400 font-bold">Manager Guarded</span></div>
                                <div class="p-2.5 rounded bg-[#161616] flex justify-between"><span>Refund &amp; Restock Engine</span><span class="text-emerald-400 font-bold">Audit Logged</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Inventory Panel -->
                <div id="tab-inventory" class="tab-panel hidden rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#242424] pb-6">
                        <div>
                            <h3 class="text-xl sm:text-2xl font-extrabold text-white">Inventory Control</h3>
                            <p class="text-xs sm:text-sm text-zinc-400 mt-1">Track stock, consumption and critical inventory with recipe deductions.</p>
                        </div>
                        <span class="px-3 py-1 rounded-lg bg-amber-500/10 text-amber-400 text-xs font-mono font-bold">Stock Ledger</span>
                    </div>
                    <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-3 text-xs">
                        <div class="grid grid-cols-4 font-bold text-zinc-400 border-b border-[#242424] pb-2">
                            <span>INGREDIENT</span><span>STOCK</span><span>REORDER LEVEL</span><span>AUTO DEDUCTION</span>
                        </div>
                        <div class="grid grid-cols-4 text-white"><span>Chicken Breast</span><span>14.5 kg</span><span>5.0 kg</span><span class="text-emerald-400">−0.40 kg / sale</span></div>
                        <div class="grid grid-cols-4 text-white"><span>Basmati Rice</span><span>42.0 kg</span><span>10.0 kg</span><span class="text-emerald-400">−0.30 kg / sale</span></div>
                        <div class="grid grid-cols-4 text-white"><span>Paneer Block</span><span>2.8 kg</span><span class="text-amber-400 font-bold">3.0 kg (LOW)</span><span class="text-emerald-400">−0.25 kg / sale</span></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 5. CATEGORIZED FEATURES SECTION ============ -->
    <section id="features" class="py-20 md:py-28 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-16">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Comprehensive Feature Architecture
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Organized into 5 specialized operational domains built for restaurant execution.
                </p>
            </div>

            <!-- Feature Categories Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-6">

                <!-- Category 1: CORE POS -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 space-y-5 hover:border-zinc-600 transition-all flex flex-col justify-between">
                    <div class="space-y-4">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <?= svg_icon('terminal', 'w-5 h-5') ?>
                        </div>
                        <div>
                            <h3 class="text-lg font-extrabold text-white">CORE POS</h3>
                            <p class="text-xs text-zinc-400 mt-1">Counter &amp; cashier billing register</p>
                        </div>
                        <ul class="space-y-2 text-xs text-zinc-300 border-t border-[#242424] pt-4">
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> RPOS Terminal</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Counter Billing</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Split Bill Engine</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Order Refunds</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Order Voids</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> NCR Complimentary</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Multi-Payment Support</li>
                        </ul>
                    </div>
                </div>

                <!-- Category 2: RESTAURANT OPERATIONS -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 space-y-5 hover:border-zinc-600 transition-all flex flex-col justify-between">
                    <div class="space-y-4">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <?= svg_icon('utensils', 'w-5 h-5') ?>
                        </div>
                        <div>
                            <h3 class="text-lg font-extrabold text-white">OPERATIONS</h3>
                            <p class="text-xs text-zinc-400 mt-1">Floor &amp; kitchen workflows</p>
                        </div>
                        <ul class="space-y-2 text-xs text-zinc-300 border-t border-[#242424] pt-4">
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Floor &amp; Table Map</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Table Reservations</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Kitchen Display (KDS)</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Live Order Queue</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Staff Management</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Waiter Call Alerts</li>
                        </ul>
                    </div>
                </div>

                <!-- Category 3: INVENTORY -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 space-y-5 hover:border-zinc-600 transition-all flex flex-col justify-between">
                    <div class="space-y-4">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <?= svg_icon('box', 'w-5 h-5') ?>
                        </div>
                        <div>
                            <h3 class="text-lg font-extrabold text-white">INVENTORY</h3>
                            <p class="text-xs text-zinc-400 mt-1">Stock &amp; recipe tracking</p>
                        </div>
                        <ul class="space-y-2 text-xs text-zinc-300 border-t border-[#242424] pt-4">
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Product Catalog</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Categories &amp; Addons</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Recipe Deductions</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Unit Stock Counts</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Supplier Purchases</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Wastage Logging</li>
                        </ul>
                    </div>
                </div>

                <!-- Category 4: CUSTOMER -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 space-y-5 hover:border-zinc-600 transition-all flex flex-col justify-between">
                    <div class="space-y-4">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <?= svg_icon('users', 'w-5 h-5') ?>
                        </div>
                        <div>
                            <h3 class="text-lg font-extrabold text-white">CUSTOMER</h3>
                            <p class="text-xs text-zinc-400 mt-1">Guest engagement &amp; QR</p>
                        </div>
                        <ul class="space-y-2 text-xs text-zinc-300 border-t border-[#242424] pt-4">
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Customer Profiles</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Loyalty Points System</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Phone Number Lookup</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Table QR Code Menu</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Guest Order History</li>
                        </ul>
                    </div>
                </div>

                <!-- Category 5: ANALYTICS -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 space-y-5 hover:border-zinc-600 transition-all flex flex-col justify-between">
                    <div class="space-y-4">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <?= svg_icon('chart', 'w-5 h-5') ?>
                        </div>
                        <div>
                            <h3 class="text-lg font-extrabold text-white">ANALYTICS</h3>
                            <p class="text-xs text-zinc-400 mt-1">Reporting &amp; margins</p>
                        </div>
                        <ul class="space-y-2 text-xs text-zinc-300 border-t border-[#242424] pt-4">
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Daily Revenue Reports</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Sales Breakdown</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Item Performance</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Inventory Valuation</li>
                            <li class="flex items-center gap-2"><span class="text-amber-400">✓</span> Executive Reports</li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ============ 8. TRUST & ARCHITECTURE ============ -->
    <section class="py-20 md:py-28 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-16">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Everything You Need to Run a Modern Restaurant
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Tested multi-tenant architecture engineered for continuous uptime and strict data protection.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <div class="p-6 rounded-2xl border border-[#242424] bg-[#111111] space-y-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                        <?= svg_icon('shield', 'w-5 h-5') ?>
                    </div>
                    <h3 class="text-lg font-extrabold text-white">Secure Multi-Tenant Architecture</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">Database and session isolation ensures absolute logical separation between restaurant accounts.</p>
                </div>

                <div class="p-6 rounded-2xl border border-[#242424] bg-[#111111] space-y-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                        <?= svg_icon('users', 'w-5 h-5') ?>
                    </div>
                    <h3 class="text-lg font-extrabold text-white">Role-Based Access (RBAC)</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">Strict role control for Owner, Manager, Cashier, Chef, Waiter, and Inventory staff accounts.</p>
                </div>

                <div class="p-6 rounded-2xl border border-[#242424] bg-[#111111] space-y-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                        <?= svg_icon('activity', 'w-5 h-5') ?>
                    </div>
                    <h3 class="text-lg font-extrabold text-white">Real-Time Restaurant Operations</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">Live socket/polling synchronization between table QR scans, cashiers, and kitchen displays.</p>
                </div>

                <div class="p-6 rounded-2xl border border-[#242424] bg-[#111111] space-y-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                        <?= svg_icon('card', 'w-5 h-5') ?>
                    </div>
                    <h3 class="text-lg font-extrabold text-white">Secure Payments</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">Integrates eSewa, Khalti, Cash, and Card settlements with server-side DECIMAL calculation.</p>
                </div>

                <div class="p-6 rounded-2xl border border-[#242424] bg-[#111111] space-y-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                        <?= svg_icon('box', 'w-5 h-5') ?>
                    </div>
                    <h3 class="text-lg font-extrabold text-white">Inventory Control</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">Automatic recipe stock deductions, waste recording, supplier orders, and reorder threshold alerts.</p>
                </div>

                <div class="p-6 rounded-2xl border border-[#242424] bg-[#111111] space-y-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                        <?= svg_icon('chart', 'w-5 h-5') ?>
                    </div>
                    <h3 class="text-lg font-extrabold text-white">Automated Reporting</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">Real-time daily revenue, item margins, staff activity, and audit logs exported on demand.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ HOW IT WORKS SECTION ============ -->
    <section id="how-it-works" class="py-20 md:py-28 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-16">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-[#111111] border border-[#242424] text-amber-400 text-xs font-extrabold uppercase tracking-wider">
                    <span>ONBOARDING WORKFLOW</span>
                </div>
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    How It Works
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Get your restaurant running on RMS in four simple steps.
                </p>
            </div>

            <!-- 4-Step Connected Timeline -->
            <div class="relative">
                <!-- Connecting Line (Desktop) -->
                <div class="hidden lg:block absolute top-14 left-[12%] right-[12%] h-0.5 bg-[#242424] z-0"></div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 relative z-10">

                    <!-- Step 01 -->
                    <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 flex flex-col justify-between space-y-5 hover:border-amber-500/50 transition-all group shadow-sm">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20 flex items-center justify-center font-black text-sm group-hover:scale-105 transition-transform">
                                    01
                                </div>
                                <div class="p-2 rounded-lg bg-[#161616] text-zinc-400">
                                    <?= svg_icon('mail', 'w-5 h-5') ?>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-white">01 — Request a Demo</h3>
                                <p class="text-xs text-[#A1A1AA] mt-1.5 leading-relaxed">
                                    Tell us about your restaurant and request access to RMS.
                                </p>
                            </div>

                            <!-- Small Form Preview Mockup -->
                            <div class="p-3.5 rounded-xl bg-[#090909] border border-[#242424] space-y-2 text-[11px] font-mono">
                                <div class="flex justify-between text-zinc-400"><span>Restaurant</span><span class="text-white font-sans font-bold">Himalayan Kitchen</span></div>
                                <div class="flex justify-between text-zinc-400"><span>Owner</span><span class="text-white font-sans">Ramesh Sharma</span></div>
                                <div class="flex justify-between text-zinc-400"><span>Tables</span><span class="text-amber-400 font-bold">12 Tables</span></div>
                                <div class="flex justify-between text-zinc-400"><span>Plan</span><span class="text-emerald-400 font-bold">Business (NPR 2.5k)</span></div>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-[#242424]">
                            <a href="#request-demo" class="inline-flex items-center gap-1.5 text-xs font-extrabold text-amber-400 hover:text-amber-300">
                                Request a Demo →
                            </a>
                        </div>
                    </div>

                    <!-- Step 02 -->
                    <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 flex flex-col justify-between space-y-5 hover:border-amber-500/50 transition-all group shadow-sm">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20 flex items-center justify-center font-black text-sm group-hover:scale-105 transition-transform">
                                    02
                                </div>
                                <div class="p-2 rounded-lg bg-[#161616] text-zinc-400">
                                    <?= svg_icon('user-check', 'w-5 h-5') ?>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-white">02 — Super Admin Review</h3>
                                <p class="text-xs text-[#A1A1AA] mt-1.5 leading-relaxed">
                                    Our team reviews your restaurant request and contacts you to confirm requirements and plan.
                                </p>
                            </div>

                            <!-- Small Pipeline Mockup -->
                            <div class="p-3.5 rounded-xl bg-[#090909] border border-[#242424] space-y-2 text-[11px]">
                                <div class="text-zinc-400 font-bold uppercase tracking-wider text-[10px]">REVIEW PIPELINE</div>
                                <div class="flex items-center justify-between font-mono pt-1 text-[#A1A1AA]">
                                    <span>Request</span>
                                    <span>→</span>
                                    <span>Review</span>
                                    <span>→</span>
                                    <span class="text-emerald-400 font-bold">Approved</span>
                                </div>
                                <div class="pt-1 text-[10px] text-emerald-400 font-semibold bg-emerald-500/10 p-1.5 rounded text-center">
                                    STATUS: VERIFIED &amp; APPROVED
                                </div>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-[#242424] text-[11px] text-zinc-400 font-medium">
                            Super Admin Verification
                        </div>
                    </div>

                    <!-- Step 03 -->
                    <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 flex flex-col justify-between space-y-5 hover:border-amber-500/50 transition-all group shadow-sm">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20 flex items-center justify-center font-black text-sm group-hover:scale-105 transition-transform">
                                    03
                                </div>
                                <div class="p-2 rounded-lg bg-[#161616] text-zinc-400">
                                    <?= svg_icon('key', 'w-5 h-5') ?>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-white">03 — Receive Your Credentials</h3>
                                <p class="text-xs text-[#A1A1AA] mt-1.5 leading-relaxed">
                                    After approval, your restaurant account is created manually by the Super Admin.
                                </p>
                            </div>

                            <!-- Small Credentials Mockup -->
                            <div class="p-3.5 rounded-xl bg-[#090909] border border-[#242424] space-y-2 text-[11px] font-mono">
                                <div class="text-zinc-400 font-bold uppercase tracking-wider text-[10px]">TENANT PROVISIONING</div>
                                <div class="flex justify-between text-zinc-400"><span>Username</span><span class="text-amber-400 font-bold">admin_himalayan</span></div>
                                <div class="flex justify-between text-zinc-400"><span>Password</span><span class="text-zinc-400">••••••••••••</span></div>
                                <div class="flex justify-between text-zinc-400"><span>Isolation</span><span class="text-emerald-400 font-bold">Tenant ID #12</span></div>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-[#242424] text-[11px] text-zinc-400 font-medium">
                            Secure Owner Account Created
                        </div>
                    </div>

                    <!-- Step 04 -->
                    <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 flex flex-col justify-between space-y-5 hover:border-amber-500/50 transition-all group shadow-sm">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20 flex items-center justify-center font-black text-sm group-hover:scale-105 transition-transform">
                                    04
                                </div>
                                <div class="p-2 rounded-lg bg-[#161616] text-zinc-400">
                                    <?= svg_icon('rocket', 'w-5 h-5') ?>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-white">04 — Login &amp; Go Live</h3>
                                <p class="text-xs text-[#A1A1AA] mt-1.5 leading-relaxed">
                                    Log in to your private restaurant portal, configure your restaurant, menu, tables, staff and operations, then start using RMS.
                                </p>
                            </div>

                            <!-- Small Workflow Mockup -->
                            <div class="p-3.5 rounded-xl bg-[#090909] border border-[#242424] space-y-1.5 text-[10px] font-mono">
                                <div class="flex items-center justify-between text-zinc-400">
                                    <span>Login</span> → <span>Setup</span> → <span>Menu</span> → <span class="text-emerald-400 font-bold">Live Ops</span>
                                </div>
                                <div class="p-1 rounded bg-emerald-500/10 text-emerald-400 font-sans font-bold text-center mt-1">
                                    Ready for Table Sales &amp; POS
                                </div>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-[#242424]">
                            <a href="admin/login.php" class="inline-flex items-center gap-1.5 text-xs font-extrabold text-amber-400 hover:text-amber-300">
                                Restaurant Login →
                            </a>
                        </div>
                    </div>

                </div>
            </div>

            <!-- "Once You're Inside RMS" Checklist -->
            <div class="mt-20 max-w-4xl mx-auto rounded-2xl border border-[#242424] bg-[#111111] p-8 sm:p-10 space-y-6">
                <div class="text-center space-y-2">
                    <span class="text-xs font-extrabold uppercase tracking-widest text-amber-400">ONBOARDING CHECKLIST</span>
                    <h3 class="text-2xl sm:text-3xl font-extrabold text-white">Once You're Inside RMS</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 text-xs font-semibold text-zinc-200 pt-2">
                    <div class="flex items-center gap-3 p-3.5 rounded-xl bg-[#090909] border border-[#242424]">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black shrink-0">✓</span>
                        <span>Configure restaurant settings</span>
                    </div>
                    <div class="flex items-center gap-3 p-3.5 rounded-xl bg-[#090909] border border-[#242424]">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black shrink-0">✓</span>
                        <span>Add tables and floors</span>
                    </div>
                    <div class="flex items-center gap-3 p-3.5 rounded-xl bg-[#090909] border border-[#242424]">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black shrink-0">✓</span>
                        <span>Add menu and products</span>
                    </div>
                    <div class="flex items-center gap-3 p-3.5 rounded-xl bg-[#090909] border border-[#242424]">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black shrink-0">✓</span>
                        <span>Configure kitchen &amp; KDS</span>
                    </div>
                    <div class="flex items-center gap-3 p-3.5 rounded-xl bg-[#090909] border border-[#242424]">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black shrink-0">✓</span>
                        <span>Add staff and permissions</span>
                    </div>
                    <div class="flex items-center gap-3 p-3.5 rounded-xl bg-[#090909] border border-[#242424]">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black shrink-0">✓</span>
                        <span>Start taking orders</span>
                    </div>
                    <div class="flex items-center gap-3 p-3.5 rounded-xl bg-[#090909] border border-[#242424]">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black shrink-0">✓</span>
                        <span>Manage billing and payments</span>
                    </div>
                    <div class="flex items-center gap-3 p-3.5 rounded-xl bg-[#090909] border border-[#242424]">
                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black shrink-0">✓</span>
                        <span>Track inventory and reports</span>
                    </div>
                </div>
            </div>

            <!-- Section Bottom CTA -->
            <div class="mt-16 text-center space-y-6">
                <h3 class="text-2xl sm:text-3xl font-extrabold text-white">
                    Ready to get your restaurant started?
                </h3>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="#request-demo" class="w-full sm:w-auto inline-flex items-center justify-center gap-2.5 px-8 py-3.5 rounded-xl bg-amber-500 text-[#090909] font-extrabold text-xs hover:bg-amber-400 active:scale-95 transition-all shadow-lg">
                        Request a Demo →
                    </a>
                    <a href="#pricing" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-3.5 rounded-xl bg-[#111111] border border-[#242424] text-white font-semibold text-xs hover:border-zinc-600 active:scale-95 transition-all">
                        View Pricing
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 7. PRICING SECTION ============ -->
    <section id="pricing" class="py-20 md:py-28 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-16">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Simple NPR Subscription Plans
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Transparent pricing tailored to your restaurant's volume and staffing needs.
                </p>
            </div>

            <!-- Pricing Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 items-stretch">
                <?php foreach ($pricingPlans as $p): ?>
                    <div class="<?= $p['popular'] ? 'border-2 border-amber-500 bg-[#161616]' : 'border border-[#242424] bg-[#111111]' ?> rounded-2xl p-7 flex flex-col justify-between space-y-6 relative hover:border-amber-500/50 transition-all">

                        <?php if ($p['popular']): ?>
                            <div class="absolute -top-3.5 left-1/2 -translate-x-1/2 px-4 py-1 rounded-full bg-amber-500 text-[#090909] text-[10px] font-black uppercase tracking-wider shadow-md">
                                Most Popular
                            </div>
                        <?php endif; ?>

                        <div class="space-y-4">
                            <div>
                                <div class="text-xs font-bold uppercase tracking-wider text-amber-400"><?= $p['name'] ?></div>
                                <div class="text-3xl font-black text-white mt-1"><?= $p['price'] ?></div>
                                <div class="text-xs text-zinc-400 mt-0.5"><?= $p['suffix'] ?: 'Custom contract' ?></div>
                            </div>
                            <p class="text-xs text-[#A1A1AA] leading-relaxed"><?= $p['tagline'] ?></p>

                            <div class="pt-4 border-t border-[#242424] space-y-2 text-xs text-zinc-300">
                                <?php foreach ($p['features'] as $f): ?>
                                    <div class="flex items-start gap-2">
                                        <span class="text-emerald-400 font-bold">✓</span>
                                        <span><?= $f ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <a href="#request-demo" data-plan-code="<?= $p['code'] ?>" class="<?= $p['popular'] ? 'bg-amber-500 text-[#090909] hover:bg-amber-400' : 'bg-[#161616] text-white border border-[#242424] hover:border-zinc-500' ?> w-full text-center py-3 rounded-xl text-xs font-extrabold transition-all">
                            <?= $p['cta'] ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ 9. REQUEST DEMO FORM ============ -->
    <section id="request-demo" class="py-20 md:py-28 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 mb-12">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Request a Demo &amp; Setup
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Provide your restaurant details to request a demonstration and tenant workspace setup.
                </p>
            </div>

            <?php if ($requestSuccess): ?>
                <div class="p-8 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-center space-y-4">
                    <div class="w-12 h-12 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center mx-auto">
                        <?= svg_icon('check', 'w-6 h-6 stroke-[3]') ?>
                    </div>
                    <h3 class="text-2xl font-extrabold text-white">Demo Request Received</h3>
                    <p class="text-xs sm:text-sm text-zinc-300 max-w-md mx-auto leading-relaxed">
                        Thank you! Your request code is <span class="font-mono text-amber-400 font-bold"><?= htmlspecialchars($lastRequestCode) ?></span>. Our team will review your application and contact you shortly.
                    </p>
                    <a href="index.php" class="inline-block mt-4 px-6 py-2.5 rounded-xl bg-[#111111] border border-[#242424] text-xs font-bold text-white hover:border-zinc-600">Return to Home</a>
                </div>
            <?php else: ?>

                <?php if ($requestError): ?>
                    <div class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold flex items-center gap-2">
                        <?= svg_icon('x', 'w-4 h-4') ?>
                        <span><?= htmlspecialchars($requestError) ?></span>
                    </div>
                <?php endif; ?>

                <form id="restaurantRequestForm" method="POST" class="rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6" novalidate>
                    <?= $csrfField ?>
                    <input type="hidden" name="action" value="submit_restaurant_request">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 text-xs">
                        <div>
                            <label for="restaurant_name" class="block font-bold text-zinc-200 mb-1.5">Restaurant Name <span class="text-amber-400">*</span></label>
                            <input type="text" id="restaurant_name" name="restaurant_name" required placeholder="e.g. Himalayan Kitchen" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label for="owner_name" class="block font-bold text-zinc-200 mb-1.5">Owner Full Name <span class="text-amber-400">*</span></label>
                            <input type="text" id="owner_name" name="owner_name" required placeholder="e.g. Ramesh Sharma" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label for="email" class="block font-bold text-zinc-200 mb-1.5">Email Address <span class="text-amber-400">*</span></label>
                            <input type="email" id="email" name="email" required placeholder="owner@restaurant.com" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label for="phone" class="block font-bold text-zinc-200 mb-1.5">Contact Phone <span class="text-amber-400">*</span></label>
                            <input type="tel" id="phone" name="phone" required placeholder="98XXXXXXXX" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label for="restaurant_type" class="block font-bold text-zinc-200 mb-1.5">Restaurant Type</label>
                            <select id="restaurant_type" name="restaurant_type" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white outline-none focus:border-amber-500">
                                <option value="Casual Dining" selected>Casual Dining</option>
                                <option value="Fine Dining">Fine Dining</option>
                                <option value="Fast Food / QSR">Fast Food / QSR</option>
                                <option value="Cafe & Bakery">Cafe &amp; Bakery</option>
                                <option value="Bar & Lounge">Bar &amp; Lounge</option>
                            </select>
                        </div>

                        <div>
                            <label for="table_count" class="block font-bold text-zinc-200 mb-1.5">Number of Tables <span class="text-amber-400">*</span></label>
                            <input type="number" id="table_count" name="table_count" min="1" max="1000" value="10" required class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label for="current_system" class="block font-bold text-zinc-200 mb-1.5">Current POS / System (Optional)</label>
                            <input type="text" id="current_system" name="current_system" placeholder="e.g. Manual paper bills, legacy POS" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label for="preferred_plan" class="block font-bold text-zinc-200 mb-1.5">Preferred Plan</label>
                            <select id="preferred_plan" name="preferred_plan" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white outline-none focus:border-amber-500">
                                <option value="ESSENTIAL">Essential — NPR 1,500/month</option>
                                <option value="BUSINESS" selected>Business — NPR 2,500/month</option>
                                <option value="BUSINESS_PRO">Business Pro — NPR 4,500/month</option>
                                <option value="ENTERPRISE">Enterprise — Custom Pricing</option>
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <label for="message" class="block font-bold text-zinc-200 mb-1.5">Additional Requirements (Optional)</label>
                            <textarea id="message" name="message" rows="3" placeholder="Tell us about your restaurant setup..." class="w-full bg-[#090909] border border-[#242424] rounded-xl p-3 text-white placeholder-zinc-600 outline-none focus:border-amber-500"></textarea>
                        </div>
                    </div>

                    <div class="pt-2 flex flex-col sm:flex-row items-center gap-4">
                        <button type="submit" class="btn-submit w-full sm:w-auto inline-flex items-center justify-center gap-2.5 px-8 py-3.5 rounded-xl bg-amber-500 text-[#090909] font-extrabold text-xs hover:bg-amber-400 active:scale-95 transition-all">
                            <span class="btn-label">Request a Demo</span>
                            <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                        </button>
                        <span class="text-[11px] text-zinc-400 font-medium">No payment required for demo setup.</span>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============ 10. FAQ SECTION ============ -->
    <section id="faq" class="py-20 md:py-28 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 mb-14">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Frequently Asked Questions
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Clear answers regarding RMS SaaS implementation, features, and billing.
                </p>
            </div>

            <div class="space-y-3">
                <?php
                $faqs = [
                    ['What is RMS SaaS?', 'RMS SaaS is a multi-restaurant management platform engineered for Nepal restaurants. It unifies RPOS cashier billing, QR code table ordering, Kitchen Display System (KDS), floor & table management, inventory, digital payments, loyalty, and analytics in a single cloud workspace.'],
                    ['What features are included?', 'RMS includes RPOS billing, digital QR menus, live KDS screens, floor maps, split/merge billing, NCR complimentary waivers, ingredient recipe stock deductions, customer loyalty points, staff RBAC permissions, and automated revenue reporting.'],
                    ['Can I manage multiple restaurants?', 'Yes. RMS operates on a multi-tenant SaaS architecture where each restaurant runs inside its own logically isolated workspace with dedicated table, staff, and financial data.'],
                    ['Does it support table-based billing?', 'Yes. Cashiers and waiters can create, update, split, merge, and settle table-based bills in real time with instant physical or digital receipt generation.'],
                    ['Does it support QR ordering?', 'Yes. Guests scan unique table QR codes using standard smartphone cameras, browse digital menus, and place orders directly to kitchen displays without downloading any application.'],
                    ['Does it have a Kitchen Display System?', 'Yes. The Kitchen Display System (KDS) renders incoming orders on kitchen screens with prep timers, status buttons (Preparing/Ready), and audio alerts.'],
                    ['Can I manage staff permissions?', 'Yes. RMS provides granular Role-Based Access Control (RBAC) across Owner, Manager, Cashier, Chef, Waiter, and Inventory staff roles.'],
                    ['Can customers earn loyalty points?', 'Yes. Customers can earn and redeem loyalty points associated with their phone numbers during checkout, with automatic reversals on order refunds.'],
                    ['Can I split bills?', 'Yes. RMS supports equal split, custom amount split, item-based split, and quantity-based split payments with automatic table clearing upon full payment.'],
                    ['Can I use different payment methods?', 'Yes. RMS supports eSewa, Khalti, Cash, and Card payments alongside Non-Chargeable (NCR) complimentary waivers.'],
                    ['What happens when my subscription expires?', 'Super Admins can grant grace periods or update plans. System data and historical audit logs remain securely preserved in isolated database storage.'],
                ];
                foreach ($faqs as $i => $faq): ?>
                    <div class="rounded-xl border border-[#242424] bg-[#111111] overflow-hidden hover:border-zinc-600 transition-colors">
                        <h3>
                            <button type="button" class="faq-btn w-full flex items-center justify-between gap-4 p-5 text-left" aria-expanded="false" aria-controls="faq-<?= $i + 1 ?>" id="faq-btn-<?= $i + 1 ?>">
                                <span class="text-sm font-extrabold text-white"><?= $faq[0] ?></span>
                                <span class="faq-icon w-6 h-6 shrink-0 rounded-lg bg-[#161616] border border-[#242424] text-amber-400 flex items-center justify-center"><?= svg_icon('plus', 'w-3.5 h-3.5') ?></span>
                            </button>
                        </h3>
                        <div id="faq-<?= $i + 1 ?>" class="faq-panel hidden px-5 pb-5" role="region" aria-labelledby="faq-btn-<?= $i + 1 ?>">
                            <div class="text-xs sm:text-sm text-[#A1A1AA] leading-relaxed border-t border-[#242424] pt-3"><?= $faq[1] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ 11. FINAL CTA ============ -->
    <section class="py-20 md:py-28 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-4xl mx-auto px-4 text-center space-y-6">
            <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                Ready to Run Your Restaurant Smarter?
            </h2>
            <p class="text-base sm:text-lg text-[#A1A1AA] max-w-2xl mx-auto font-normal leading-relaxed">
                Manage orders, tables, billing, kitchen operations, inventory and customers from one platform.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-2">
                <a href="#request-demo" class="w-full sm:w-auto inline-flex items-center justify-center gap-2.5 px-8 py-4 rounded-xl bg-amber-500 text-[#090909] font-extrabold text-sm hover:bg-amber-400 active:scale-95 transition-all shadow-lg">
                    Request a Demo
                    <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                </a>
                <a href="#showcase" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl bg-[#111111] border border-[#242424] text-white font-semibold text-sm hover:border-zinc-600 active:scale-95 transition-all">
                    Explore RMS
                </a>
            </div>
        </div>
    </section>

    <!-- ============ FOOTER ============ -->
    <footer class="bg-[#090909] border-t border-[#242424]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-amber-500 flex items-center justify-center text-[#090909] font-black">
                        <?= svg_icon('bolt', 'w-5 h-5 stroke-[2.4]') ?>
                    </div>
                    <span class="font-extrabold text-white text-base">RMS SaaS Platform</span>
                </div>

                <div class="flex flex-wrap justify-center gap-6 text-xs font-semibold text-[#A1A1AA]">
                    <a href="#showcase" class="hover:text-amber-400 transition-colors">Product</a>
                    <a href="#features" class="hover:text-amber-400 transition-colors">Features</a>
                    <a href="#pricing" class="hover:text-amber-400 transition-colors">Pricing</a>
                    <a href="#faq" class="hover:text-amber-400 transition-colors">FAQ</a>
                    <a href="admin/login.php" class="hover:text-white transition-colors text-amber-400 font-bold">Restaurant Login</a>
                    <a href="super-admin/login.php" class="hover:text-white transition-colors">Super Admin</a>
                    <a href="privacy-policy.php" class="hover:text-white transition-colors">Privacy Policy</a>
                    <a href="terms-of-service.php" class="hover:text-white transition-colors">Terms of Service</a>
                </div>

                <div class="text-xs text-zinc-400 font-medium">
                    © <?= date('Y') ?> RMS SaaS Platform. All rights reserved.
                </div>
            </div>
        </div>
    </footer>

    <!-- ============ INTERACTIVITY SCRIPTS ============ -->
    <script>
    (function () {
        'use strict';

        /* Mobile menu toggle */
        var menuBtn = document.getElementById('mobile-menu-btn');
        var mobileMenu = document.getElementById('mobile-menu');
        if (menuBtn && mobileMenu) {
            menuBtn.addEventListener('click', function () {
                var open = mobileMenu.classList.toggle('hidden') === false;
                menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            mobileMenu.querySelectorAll('.mobile-link').forEach(function (link) {
                link.addEventListener('click', function () {
                    mobileMenu.classList.add('hidden');
                    menuBtn.setAttribute('aria-expanded', 'false');
                });
            });
        }

        /* Interactive Showcase Tabs */
        var tabBtns = document.querySelectorAll('.tab-btn');
        var tabPanels = document.querySelectorAll('.tab-panel');
        tabBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-tab');
                tabBtns.forEach(function (b) {
                    b.classList.remove('border-amber-500', 'bg-amber-500/10', 'text-amber-400');
                    b.classList.add('border-[#242424]', 'bg-[#111111]', 'text-zinc-400');
                });
                btn.classList.remove('border-[#242424]', 'bg-[#111111]', 'text-zinc-400');
                btn.classList.add('border-amber-500', 'bg-amber-500/10', 'text-amber-400');

                tabPanels.forEach(function (panel) {
                    if (panel.id === 'tab-' + target) {
                        panel.classList.remove('hidden');
                    } else {
                        panel.classList.add('hidden');
                    }
                });
            });
        });

        /* FAQ Accordion */
        var faqBtns = document.querySelectorAll('.faq-btn');
        faqBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var panel = document.getElementById(btn.getAttribute('aria-controls'));
                var isOpen = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                if (panel) panel.classList.toggle('hidden', isOpen);
            });
        });

        /* Pricing Plan Selection Sync */
        var planSelect = document.querySelector('select[name="preferred_plan"]');
        document.querySelectorAll('[data-plan-code]').forEach(function (el) {
            el.addEventListener('click', function () {
                if (planSelect) planSelect.value = el.getAttribute('data-plan-code');
            });
        });

        /* Form Double-Submit Protection & Validation */
        var form = document.getElementById('restaurantRequestForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                var btn = form.querySelector('.btn-submit');
                var label = form.querySelector('.btn-label');
                if (btn.disabled) {
                    e.preventDefault();
                    return;
                }
                if (!form.checkValidity()) {
                    form.reportValidity();
                    e.preventDefault();
                    return;
                }
                btn.disabled = true;
                if (label) label.textContent = 'Submitting Request…';
            });
        }
    })();
    </script>
</body>
</html>
