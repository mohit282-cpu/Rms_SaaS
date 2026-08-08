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

// Lightweight inline SVG icon system (stroke-based, inherits currentColor)
function svg_icon($name, $class = 'w-5 h-5') {
    $icons = [
        'bolt'      => '<path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/>',
        'scan'      => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3z"/><path d="M21 14v.01M14 21h.01M17 21h4"/>',
        'terminal'  => '<path d="m4 8 4 4-4 4"/><path d="M12 16h8"/>',
        'monitor'   => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'chef'      => '<path d="M17 21a1 1 0 0 0 1-1v-5.35c0-.46.32-.84.73-1.04a4 4 0 0 0-2.14-7.59 5 5 0 0 0-9.18 0 4 4 0 0 0-2.14 7.59c.41.2.73.58.73 1.04V20a1 1 0 0 0 1 1Z"/><path d="M6 17h12"/>',
        'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box'       => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'book'      => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>',
        'truck'     => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        'wrench'    => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'card'      => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'wallet'    => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'chart'     => '<path d="M3 3v18h18"/><path d="M8 17v-4"/><path d="M13 17V7"/><path d="M18 17v-7"/>',
        'shield'    => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'lock'      => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'key'       => '<path d="m21 2-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78Zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
        'x'         => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'arrow'     => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'menu'      => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'close'     => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'phone'     => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'mail'      => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'bell'      => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'refresh'   => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'layers'    => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'building'  => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M12 6h.01M8 10h.01M16 10h.01M12 10h.01M8 14h.01M16 14h.01M12 14h.01"/>',
        'plus'      => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'activity'  => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
        'login'     => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        'utensils'  => '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
        'receipt'   => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'spark'     => '<path d="m12 3 1.912 5.813a2 2 0 0 0 1.275 1.275L21 12l-5.813 1.912a2 2 0 0 0-1.275 1.275L12 21l-1.912-5.813a2 2 0 0 0-1.275-1.275L3 12l5.813-1.912a2 2 0 0 0 1.275-1.275L12 3z"/>',
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

// Static NPR pricing tiers (shown to all visitors; plan is stored with the onboarding request)
$pricingPlans = [
    [
        'code'    => 'ESSENTIAL',
        'name'    => 'Essential',
        'price'   => 'NPR 1,500',
        'suffix'  => '/ month',
        'tagline' => 'For small cafés & boutique diners',
        'cta'     => 'Choose Essential',
        'popular' => false,
        'base'    => '',
        'features'=> [
            'QR Table Ordering & Digital Menu',
            'Cashier Billing POS Terminal',
            'Kitchen Display System (KDS)',
            'Table & Floor Map Management',
            'Order Status & Live Receipts',
            'Basic Daily Sales Reports',
            'Single Restaurant Tenant Account',
            'Up to 3 Staff User Accounts',
        ],
    ],
    [
        'code'    => 'BUSINESS',
        'name'    => 'Business',
        'price'   => 'NPR 2,500',
        'suffix'  => '/ month',
        'tagline' => 'For growing high-velocity restaurants',
        'cta'     => 'Choose Business',
        'popular' => true,
        'base'    => 'Everything in Essential, plus:',
        'features'=> [
            'Advanced POS & Split Bills',
            'Full KDS Timer & Audio Alerts',
            'Inventory & Unit Stock Tracking',
            'Recipe & Ingredient Deductions',
            'Supplier Records & Purchases',
            'Nepal Digital Payments (eSewa, Khalti)',
            'Advanced Revenue & Margin Reports',
            'Up to 10 Staff Roles & Accounts',
        ],
    ],
    [
        'code'    => 'BUSINESS_PRO',
        'name'    => 'Business Pro',
        'price'   => 'NPR 4,500',
        'suffix'  => '/ month',
        'tagline' => 'For busy, multi-station establishments',
        'cta'     => 'Choose Business Pro',
        'popular' => false,
        'base'    => 'Everything in Business, plus:',
        'features'=> [
            'Kitchen Asset & Warranty Register',
            'Purchase Order & Stock Adjustments',
            'Waste Recording & Low-Stock Alerts',
            'Full Role-Based Access Control (RBAC)',
            'Audit Logging & Security Trail',
            'Unlimited Staff User Accounts',
            'Priority Onboarding & Support',
            'Increased System Throughput Limits',
        ],
    ],
    [
        'code'    => 'ENTERPRISE',
        'name'    => 'Enterprise',
        'price'   => 'Custom Pricing',
        'suffix'  => '',
        'tagline' => 'For multi-branch restaurant chains',
        'cta'     => 'Contact Sales',
        'popular' => false,
        'base'    => 'Tailored multi-location deployment:',
        'features'=> [
            'Multi-branch Central Workspace',
            'Centralized Franchise Reporting',
            'Custom Role & Permission Schemas',
            'Custom Payment Gateway Integrations',
            'Dedicated Account Manager',
            'Custom SLA & Backup Options',
            'On-Site Staff Training',
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
    <meta name="description" content="Run your restaurant with one connected platform for POS, QR ordering, kitchen operations, inventory, payments and analytics.">
    <link rel="canonical" href="<?= rmsCanonicalUrl() ?>">

    <!-- Open Graph / Social -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="RMS SaaS">
    <meta property="og:title" content="RMS SaaS — Restaurant Management System">
    <meta property="og:description" content="Run your restaurant with one connected platform for POS, QR ordering, kitchen operations, inventory, payments and analytics.">
    <meta property="og:url" content="<?= rmsCanonicalUrl() ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="RMS SaaS — Restaurant Management System">
    <meta name="twitter:description" content="POS, QR ordering, KDS, inventory, payments and real-time operations — connected in one restaurant operating system.">

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
        [id] { scroll-margin-top: 88px; }

        /* Focus states */
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
            outline: 2px solid #f59e0b;
            outline-offset: 2px;
            border-radius: 8px;
        }

        /* Subtle grid background */
        .bg-subtle-grid {
            background-image:
                linear-gradient(to right, rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 56px 56px;
        }

        /* Ambient subtle glow */
        .ambient-glow {
            box-shadow: 0 0 80px -20px rgba(245, 158, 11, 0.15), 0 30px 60px -20px rgba(0, 0, 0, 0.9);
        }

        /* FAQ chevron rotation */
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

    <!-- ============ NAVIGATION ============ -->
    <header class="sticky top-0 z-50 bg-[#090909]/90 backdrop-blur-md border-b border-[#242424]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Brand -->
                <a href="index.php" class="flex items-center gap-3 group" aria-label="RMS SaaS Homepage">
                    <div class="w-9 h-9 rounded-xl bg-amber-500 flex items-center justify-center text-[#090909] font-black group-hover:scale-105 transition-transform">
                        <?= svg_icon('bolt', 'w-5 h-5 stroke-[2.2]') ?>
                    </div>
                    <div class="leading-none">
                        <span class="block text-lg font-extrabold tracking-tight text-white">RMS SaaS</span>
                        <span class="block text-[10px] font-bold uppercase tracking-widest text-zinc-500 mt-0.5">Platform</span>
                    </div>
                </a>

                <!-- Desktop links -->
                <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-zinc-400" aria-label="Main Navigation">
                    <a href="#features" class="hover:text-white transition-colors">Features</a>
                    <a href="#showcase" class="hover:text-white transition-colors">Platform</a>
                    <a href="#pricing" class="hover:text-white transition-colors">Pricing</a>
                    <a href="#how-it-works" class="hover:text-white transition-colors">How It Works</a>
                    <a href="#faq" class="hover:text-white transition-colors">FAQ</a>
                </nav>

                <!-- Action CTAs -->
                <div class="hidden sm:flex items-center gap-4">
                    <a href="admin/login.php" class="px-4 py-2 rounded-xl border border-[#242424] bg-[#111111] text-xs font-semibold text-zinc-300 hover:text-white hover:border-zinc-700 transition-all">
                        Login
                    </a>
                    <a href="#request-demo" class="px-5 py-2.5 rounded-xl bg-amber-500 text-[#090909] text-xs font-extrabold hover:bg-amber-400 active:scale-95 transition-all shadow-md">
                        Request Demo
                    </a>
                </div>

                <!-- Mobile menu button -->
                <button id="mobile-menu-btn" type="button" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="mobile-menu" class="md:hidden p-2 rounded-lg border border-[#242424] bg-[#111111] text-zinc-300 hover:text-white">
                    <?= svg_icon('menu', 'w-6 h-6') ?>
                </button>
            </div>
        </div>

        <!-- Mobile menu dropdown -->
        <div id="mobile-menu" class="hidden md:hidden border-t border-[#242424] bg-[#090909] px-4 pt-3 pb-6 space-y-3">
            <a href="#features" class="mobile-link block py-2 text-sm font-medium text-zinc-300 hover:text-white">Features</a>
            <a href="#showcase" class="mobile-link block py-2 text-sm font-medium text-zinc-300 hover:text-white">Platform</a>
            <a href="#pricing" class="mobile-link block py-2 text-sm font-medium text-zinc-300 hover:text-white">Pricing</a>
            <a href="#how-it-works" class="mobile-link block py-2 text-sm font-medium text-zinc-300 hover:text-white">How It Works</a>
            <a href="#faq" class="mobile-link block py-2 text-sm font-medium text-zinc-300 hover:text-white">FAQ</a>
            <div class="pt-3 border-t border-[#242424] flex flex-col gap-2.5">
                <a href="admin/login.php" class="w-full text-center py-2.5 rounded-xl border border-[#242424] bg-[#111111] text-xs font-semibold text-zinc-200">
                    Restaurant Login
                </a>
                <a href="#request-demo" class="w-full text-center py-3 rounded-xl bg-amber-500 text-[#090909] text-xs font-extrabold">
                    Request a Demo
                </a>
            </div>
        </div>
    </header>

    <!-- ============ HERO SECTION ============ -->
    <section class="relative pt-20 pb-24 md:pt-32 md:pb-36 border-b border-[#242424] overflow-hidden bg-subtle-grid">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl mx-auto text-center space-y-8">
                <!-- Badge -->
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-zinc-900 border border-[#242424] text-amber-400 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <span>Restaurant Operating System</span>
                </div>

                <!-- Main Hero Headline -->
                <h1 class="text-4xl sm:text-6xl lg:text-[76px] font-black text-white tracking-tight leading-[1.05]">
                    Run Your Entire Restaurant<br class="hidden sm:block"> From One Powerful Platform
                </h1>

                <!-- Supporting copy -->
                <p class="text-base sm:text-xl text-[#A1A1AA] max-w-3xl mx-auto leading-relaxed font-normal">
                    POS, QR ordering, kitchen operations, inventory, assets, payments and analytics — connected in one restaurant operating system.
                </p>

                <!-- CTAs -->
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-2">
                    <a href="#request-demo" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl bg-amber-500 text-[#090909] font-extrabold text-sm hover:bg-amber-400 active:scale-95 transition-all shadow-lg">
                        Request a Demo
                        <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                    </a>
                    <a href="#features" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl bg-[#111111] border border-[#242424] text-white font-semibold text-sm hover:border-zinc-700 hover:bg-[#161616] active:scale-95 transition-all">
                        Explore Platform
                    </a>
                </div>

                <!-- Trust indicators -->
                <div class="flex flex-wrap items-center justify-center gap-x-8 gap-y-2 pt-4 text-xs font-medium text-zinc-500">
                    <span class="inline-flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Multi-Tenant SaaS Architecture</span>
                    <span class="inline-flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Real-Time KDS &amp; POS</span>
                    <span class="inline-flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Integrated Digital Payments</span>
                </div>
            </div>

            <!-- ============ DOMINANT REAL PRODUCT VISUAL ============ -->
            <div class="relative max-w-6xl mx-auto mt-16 md:mt-24">
                <div class="rounded-2xl border border-[#242424] bg-[#111111] overflow-hidden ambient-glow">
                    <!-- Browser Window Header -->
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-[#242424] bg-[#161616]">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-zinc-700"></span>
                            <span class="w-3 h-3 rounded-full bg-zinc-700"></span>
                            <span class="w-3 h-3 rounded-full bg-zinc-700"></span>
                        </div>
                        <div class="flex items-center gap-2 px-4 py-1 rounded-md bg-[#090909] border border-[#242424] text-xs font-mono text-zinc-400">
                            <?= svg_icon('lock', 'w-3.5 h-3.5 text-emerald-400') ?>
                            app.rms-saas.com/workspace/live
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-500/10 text-emerald-400 text-[11px] font-semibold">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                            Live System
                        </span>
                    </div>

                    <!-- Inner Product UI Preview -->
                    <div class="p-6 sm:p-8 space-y-6">
                        <!-- Top Stats Row -->
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="p-4 rounded-xl bg-[#090909] border border-[#242424]">
                                <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Today's Revenue</div>
                                <div class="text-2xl font-black text-white mt-1">NPR 48,250</div>
                                <div class="text-xs font-medium text-emerald-400 mt-1">↑ 14% vs yesterday</div>
                            </div>
                            <div class="p-4 rounded-xl bg-[#090909] border border-[#242424]">
                                <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Active Table Orders</div>
                                <div class="text-2xl font-black text-amber-400 mt-1">18 Sessions</div>
                                <div class="text-xs text-zinc-500 mt-1">12 QR &bull; 6 Cashier</div>
                            </div>
                            <div class="p-4 rounded-xl bg-[#090909] border border-[#242424]">
                                <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Floor Occupancy</div>
                                <div class="text-2xl font-black text-white mt-1">14 / 20 Tables</div>
                                <div class="text-xs font-medium text-zinc-400 mt-1">70% capacity occupied</div>
                            </div>
                            <div class="p-4 rounded-xl bg-[#090909] border border-[#242424]">
                                <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Kitchen Queue (KDS)</div>
                                <div class="text-2xl font-black text-emerald-400 mt-1">4 Prep Tickets</div>
                                <div class="text-xs text-zinc-500 mt-1">Avg prep time: 11 mins</div>
                            </div>
                        </div>

                        <!-- Mid Operational Layout -->
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- Live Orders Stream -->
                            <div class="lg:col-span-2 p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-4">
                                <div class="flex items-center justify-between border-b border-[#242424] pb-3">
                                    <span class="text-xs font-bold text-white uppercase tracking-wider">Live Order Monitor</span>
                                    <span class="text-xs text-zinc-500 font-mono">Updated just now</span>
                                </div>
                                <div class="space-y-3">
                                    <div class="p-3.5 rounded-xl bg-[#161616] border border-[#242424] flex items-center justify-between gap-4">
                                        <div class="flex items-center gap-3">
                                            <span class="px-2 py-1 rounded bg-amber-500 text-[#090909] font-black text-xs">T-04</span>
                                            <div>
                                                <div class="text-xs font-extrabold text-white">Chicken Biryani ×2, Cold Coffee ×2</div>
                                                <div class="text-[11px] text-zinc-500 mt-0.5">QR Session #1085 &bull; Starters served</div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="px-2.5 py-1 rounded bg-amber-500/10 text-amber-400 border border-amber-500/20 text-xs font-semibold">PREPARING</span>
                                            <div class="text-xs font-mono text-zinc-400 mt-1">NPR 1,450</div>
                                        </div>
                                    </div>

                                    <div class="p-3.5 rounded-xl bg-[#161616] border border-[#242424] flex items-center justify-between gap-4">
                                        <div class="flex items-center gap-3">
                                            <span class="px-2 py-1 rounded bg-zinc-700 text-white font-black text-xs">T-09</span>
                                            <div>
                                                <div class="text-xs font-extrabold text-white">Paneer Butter Masala, Butter Naan ×4</div>
                                                <div class="text-[11px] text-zinc-500 mt-0.5">Cashier Order &bull; Bill generated</div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="px-2.5 py-1 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-semibold">READY</span>
                                            <div class="text-xs font-mono text-zinc-400 mt-1">NPR 920</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Live KDS Ticket View -->
                            <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-4">
                                <div class="flex items-center justify-between border-b border-[#242424] pb-3">
                                    <span class="text-xs font-bold text-white uppercase tracking-wider">Kitchen Ticket</span>
                                    <span class="text-xs font-mono text-amber-400">Timer: 08:42</span>
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

    <!-- ============ PROBLEM → SOLUTION ============ -->
    <section class="py-24 md:py-32 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-16">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Stop Managing Your Restaurant With Disconnected Tools
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    POS in one system, kitchen tickets on paper, inventory in spreadsheets, and payments logged manually. Disconnected tools cause order delays, stock leakage, and fragmented reporting.
                </p>
            </div>

            <!-- Solution Highlight -->
            <div class="max-w-4xl mx-auto rounded-2xl border border-[#242424] bg-[#111111] p-8 sm:p-12 space-y-8">
                <div class="text-center space-y-2">
                    <span class="text-xs font-extrabold uppercase tracking-widest text-amber-400">The RMS Architecture</span>
                    <h3 class="text-2xl sm:text-3xl font-extrabold text-white">One Platform. One Source of Truth.</h3>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 pt-4">
                    <!-- Disconnected Old Way -->
                    <div class="space-y-4 pr-0 md:pr-6 border-b md:border-b-0 md:border-r border-[#242424] pb-6 md:pb-0">
                        <div class="text-sm font-extrabold text-zinc-400 uppercase tracking-wider">The Disconnected Way</div>
                        <ul class="space-y-3 text-sm text-zinc-400">
                            <li class="flex items-start gap-3">
                                <span class="text-rose-500 font-bold">✕</span>
                                <span>POS software operating independently from kitchen staff</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="text-rose-500 font-bold">✕</span>
                                <span>Paper tickets getting lost or misplaced during peak hours</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="text-rose-500 font-bold">✕</span>
                                <span>Inventory counts drifting with untracked waste &amp; leakage</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="text-rose-500 font-bold">✕</span>
                                <span>Manual end-of-day calculations across separate ledgers</span>
                            </li>
                        </ul>
                    </div>

                    <!-- The Unified RMS Way -->
                    <div class="space-y-4 pl-0 md:pl-6">
                        <div class="text-sm font-extrabold text-emerald-400 uppercase tracking-wider">The RMS SaaS Solution</div>
                        <ul class="space-y-3 text-sm text-zinc-200 font-medium">
                            <li class="flex items-start gap-3">
                                <span class="text-emerald-400 font-bold">✓</span>
                                <span>QR ordering &amp; POS send tickets straight to the KDS</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="text-emerald-400 font-bold">✓</span>
                                <span>Live preparation timers keep kitchen staff accountable</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="text-emerald-400 font-bold">✓</span>
                                <span>Recipe-based automatic stock deduction on every sale</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="text-emerald-400 font-bold">✓</span>
                                <span>Unified real-time revenue, margins, and staff reports</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ CORE PRODUCT FEATURES (BENTO LAYOUT) ============ -->
    <section id="features" class="py-24 md:py-32 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-16">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Everything Your Restaurant Needs
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Eight connected modules designed specifically for modern restaurant execution.
                </p>
            </div>

            <!-- Bento Grid Layout -->
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-6">

                <!-- 1. Restaurant POS (Large Feature - Spans 2 cols) -->
                <div class="md:col-span-2 rounded-2xl border border-[#242424] bg-[#111111] p-8 flex flex-col justify-between space-y-6 hover:border-zinc-700 transition-colors">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <?= svg_icon('terminal', 'w-5 h-5') ?>
                        </div>
                        <h3 class="text-2xl font-extrabold text-white">1. Restaurant POS</h3>
                        <p class="text-sm text-[#A1A1AA] leading-relaxed">
                            A high-speed cashier terminal for bill generation, split payments, dining session management, and receipt printing.
                        </p>
                    </div>

                    <!-- Mini POS UI Visual -->
                    <div class="p-4 rounded-xl bg-[#090909] border border-[#242424] space-y-3 font-mono text-xs">
                        <div class="flex justify-between text-zinc-400 border-b border-[#242424] pb-2">
                            <span>SESSION T-04</span>
                            <span class="text-emerald-400">POS BILLING ACTIVE</span>
                        </div>
                        <div class="flex justify-between text-white">
                            <span>2× Royal Chicken Biryani</span>
                            <span>NPR 900</span>
                        </div>
                        <div class="flex justify-between text-white">
                            <span>2× Cold Coffee</span>
                            <span>NPR 300</span>
                        </div>
                        <div class="flex justify-between text-amber-400 font-extrabold pt-2 border-t border-[#242424]">
                            <span>TOTAL BILL</span>
                            <span>NPR 1,200</span>
                        </div>
                    </div>
                </div>

                <!-- 2. QR Table Ordering (Medium Feature) -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-8 flex flex-col justify-between space-y-6 hover:border-zinc-700 transition-colors">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <?= svg_icon('scan', 'w-5 h-5') ?>
                        </div>
                        <h3 class="text-xl font-extrabold text-white">2. QR Table Ordering</h3>
                        <p class="text-sm text-[#A1A1AA] leading-relaxed">
                            Guests scan table QR codes to browse digital menus and place orders without waiting.
                        </p>
                    </div>
                    <div class="p-3 rounded-xl bg-[#090909] border border-[#242424] text-xs text-zinc-400 space-y-1">
                        <div class="font-bold text-white">Phone Browser Menu</div>
                        <div>No app installation required</div>
                    </div>
                </div>

                <!-- 3. Kitchen Display System (Medium Feature) -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-8 flex flex-col justify-between space-y-6 hover:border-zinc-700 transition-colors">
                    <div class="space-y-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <?= svg_icon('chef', 'w-5 h-5') ?>
                        </div>
                        <h3 class="text-xl font-extrabold text-white">3. Kitchen Display (KDS)</h3>
                        <p class="text-sm text-[#A1A1AA] leading-relaxed">
                            Real-time order screens for kitchen staff with live timers and status management.
                        </p>
                    </div>
                    <div class="p-3 rounded-xl bg-[#090909] border border-[#242424] text-xs text-emerald-400 font-mono">
                        Ticket #1085 &bull; 06:14 prep timer
                    </div>
                </div>

                <!-- 4. Inventory Management (Small Feature) -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 space-y-3 hover:border-zinc-700 transition-colors">
                    <div class="w-9 h-9 rounded-lg bg-zinc-900 border border-[#242424] text-amber-400 flex items-center justify-center">
                        <?= svg_icon('box', 'w-4 h-4') ?>
                    </div>
                    <h3 class="text-lg font-bold text-white">4. Inventory Control</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">
                        Recipe-based ingredient deduction, low-stock alerts, purchase records, and waste tracking.
                    </p>
                </div>

                <!-- 5. Asset Management (Small Feature) -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 space-y-3 hover:border-zinc-700 transition-colors">
                    <div class="w-9 h-9 rounded-lg bg-zinc-900 border border-[#242424] text-amber-400 flex items-center justify-center">
                        <?= svg_icon('wrench', 'w-4 h-4') ?>
                    </div>
                    <h3 class="text-lg font-bold text-white">5. Asset Management</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">
                        Track kitchen equipment, warranty dates, maintenance logs, and asset depreciation.
                    </p>
                </div>

                <!-- 6. Payment Management (Small Feature) -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 space-y-3 hover:border-zinc-700 transition-colors">
                    <div class="w-9 h-9 rounded-lg bg-zinc-900 border border-[#242424] text-amber-400 flex items-center justify-center">
                        <?= svg_icon('card', 'w-4 h-4') ?>
                    </div>
                    <h3 class="text-lg font-bold text-white">6. Payment Management</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">
                        Configurable Nepal digital wallets (eSewa, Khalti, Fonepay) alongside cash and card settlement.
                    </p>
                </div>

                <!-- 7. Staff & RBAC (Small Feature) -->
                <div class="rounded-2xl border border-[#242424] bg-[#111111] p-6 space-y-3 hover:border-zinc-700 transition-colors">
                    <div class="w-9 h-9 rounded-lg bg-zinc-900 border border-[#242424] text-amber-400 flex items-center justify-center">
                        <?= svg_icon('users', 'w-4 h-4') ?>
                    </div>
                    <h3 class="text-lg font-bold text-white">7. Staff &amp; RBAC</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">
                        Role-based access permissions for Owner, Manager, Cashier, Kitchen, Waiter, and Inventory staff.
                    </p>
                </div>

            </div>
        </div>
    </section>

    <!-- ============ INTERACTIVE PRODUCT SHOWCASE (TABS) ============ -->
    <section id="showcase" class="py-24 md:py-32 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-12">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Experience RMS in Action
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Explore the specialized module interfaces that power daily restaurant operations.
                </p>
            </div>

            <!-- Tab Buttons -->
            <div class="flex flex-wrap justify-center gap-2 mb-10">
                <button type="button" class="tab-btn active px-5 py-2.5 rounded-xl border border-amber-500 bg-amber-500/10 text-amber-400 text-xs font-bold transition-all" data-tab="pos">
                    POS Terminal
                </button>
                <button type="button" class="tab-btn px-5 py-2.5 rounded-xl border border-[#242424] bg-[#111111] text-zinc-400 hover:text-white text-xs font-bold transition-all" data-tab="qr">
                    QR Ordering
                </button>
                <button type="button" class="tab-btn px-5 py-2.5 rounded-xl border border-[#242424] bg-[#111111] text-zinc-400 hover:text-white text-xs font-bold transition-all" data-tab="kitchen">
                    Kitchen KDS
                </button>
                <button type="button" class="tab-btn px-5 py-2.5 rounded-xl border border-[#242424] bg-[#111111] text-zinc-400 hover:text-white text-xs font-bold transition-all" data-tab="inventory">
                    Inventory Control
                </button>
                <button type="button" class="tab-btn px-5 py-2.5 rounded-xl border border-[#242424] bg-[#111111] text-zinc-400 hover:text-white text-xs font-bold transition-all" data-tab="assets">
                    Asset Register
                </button>
            </div>

            <!-- Tab Panels -->
            <div class="max-w-5xl mx-auto">
                <!-- POS Panel -->
                <div id="tab-pos" class="tab-panel rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#242424] pb-6">
                        <div>
                            <h3 class="text-xl font-extrabold text-white">Restaurant POS Terminal</h3>
                            <p class="text-xs text-zinc-400 mt-1">Rapid cashier billing &amp; instant session totals</p>
                        </div>
                        <span class="px-3 py-1 rounded bg-amber-500/10 text-amber-400 text-xs font-mono font-bold">Fast Cashier Mode</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="md:col-span-2 p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-4">
                            <div class="text-xs font-bold text-zinc-400">QUICK BILLING GRID</div>
                            <div class="grid grid-cols-3 gap-2">
                                <div class="p-3 rounded-lg bg-[#161616] text-center"><div class="text-xs font-bold">Biryani</div><div class="text-[10px] text-zinc-500">NPR 450</div></div>
                                <div class="p-3 rounded-lg bg-[#161616] text-center"><div class="text-xs font-bold">Momo</div><div class="text-[10px] text-zinc-500">NPR 180</div></div>
                                <div class="p-3 rounded-lg bg-[#161616] text-center"><div class="text-xs font-bold">Chowmein</div><div class="text-[10px] text-zinc-500">NPR 220</div></div>
                                <div class="p-3 rounded-lg bg-[#161616] text-center"><div class="text-xs font-bold">Tea</div><div class="text-[10px] text-zinc-500">NPR 80</div></div>
                                <div class="p-3 rounded-lg bg-[#161616] text-center"><div class="text-xs font-bold">Lassi</div><div class="text-[10px] text-zinc-500">NPR 120</div></div>
                                <div class="p-3 rounded-lg bg-[#161616] text-center"><div class="text-xs font-bold">Naan</div><div class="text-[10px] text-zinc-500">NPR 60</div></div>
                            </div>
                        </div>
                        <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-3 text-xs">
                            <div class="font-bold text-white">BILL SUMMARY</div>
                            <div class="flex justify-between text-zinc-400"><span>Table 04 Session</span><span>#1085</span></div>
                            <div class="flex justify-between text-zinc-400"><span>Items Total</span><span>NPR 1,200</span></div>
                            <div class="flex justify-between text-zinc-400"><span>VAT (13%)</span><span>NPR 156</span></div>
                            <div class="flex justify-between font-extrabold text-white text-sm pt-2 border-t border-[#242424]"><span>NET TOTAL</span><span>NPR 1,356</span></div>
                            <div class="pt-2"><button type="button" class="w-full py-2 bg-amber-500 text-[#090909] font-black rounded text-xs">PRINT RECEIPT &amp; SETTLE</button></div>
                        </div>
                    </div>
                </div>

                <!-- QR Panel -->
                <div id="tab-qr" class="tab-panel hidden rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#242424] pb-6">
                        <div>
                            <h3 class="text-xl font-extrabold text-white">QR Table Ordering Interface</h3>
                            <p class="text-xs text-zinc-400 mt-1">Direct guest ordering from table QR codes</p>
                        </div>
                        <span class="px-3 py-1 rounded bg-emerald-500/10 text-emerald-400 text-xs font-mono font-bold">Zero App Download</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-3">
                            <div class="text-xs font-bold text-white">DIGITAL MENU VIEW</div>
                            <div class="p-3 rounded bg-[#161616] flex justify-between items-center text-xs">
                                <div><div class="font-bold">Steam Chicken Momo</div><div class="text-[10px] text-zinc-500">10 pcs with special chutney</div></div>
                                <span class="text-amber-400 font-bold">NPR 220</span>
                            </div>
                            <div class="p-3 rounded bg-[#161616] flex justify-between items-center text-xs">
                                <div><div class="font-bold">Paneer Butter Masala</div><div class="text-[10px] text-zinc-500">Rich gravy with butter</div></div>
                                <span class="text-amber-400 font-bold">NPR 380</span>
                            </div>
                        </div>
                        <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-3 text-xs">
                            <div class="font-bold text-white">LIVE SESSION ORDERING</div>
                            <p class="text-zinc-400 leading-relaxed">Guests can order initial items, wait for food delivery, and add extra drinks or desserts to the exact same table bill at any time during their visit.</p>
                            <div class="p-3 rounded bg-emerald-500/10 text-emerald-400 font-semibold text-[11px]">
                                Instant Kitchen Dispatch Upon Order Confirmation
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kitchen Panel -->
                <div id="tab-kitchen" class="tab-panel hidden rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#242424] pb-6">
                        <div>
                            <h3 class="text-xl font-extrabold text-white">Kitchen Display System (KDS)</h3>
                            <p class="text-xs text-zinc-400 mt-1">Live order tickets with prep timers and audio notifications</p>
                        </div>
                        <span class="px-3 py-1 rounded bg-amber-500/10 text-amber-400 text-xs font-mono font-bold">Station 1 Screen</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="p-4 rounded-xl bg-[#090909] border border-amber-500/30 space-y-3 text-xs">
                            <div class="flex justify-between font-bold"><span class="text-amber-400">#1085 &bull; Table 04</span><span class="text-zinc-500">08:12</span></div>
                            <div class="space-y-1 font-semibold text-white">
                                <div>2× Royal Chicken Biryani</div>
                                <div>2× Cold Coffee</div>
                            </div>
                            <button type="button" class="w-full py-1.5 bg-amber-500 text-[#090909] font-black rounded">MARK AS PREPARING</button>
                        </div>
                        <div class="p-4 rounded-xl bg-[#090909] border border-emerald-500/30 space-y-3 text-xs">
                            <div class="flex justify-between font-bold"><span class="text-emerald-400">#1084 &bull; Table 09</span><span class="text-zinc-500">14:05</span></div>
                            <div class="space-y-1 font-semibold text-white">
                                <div>1× Paneer Butter Masala</div>
                                <div>4× Butter Naan</div>
                            </div>
                            <button type="button" class="w-full py-1.5 bg-emerald-500 text-[#090909] font-black rounded">MARK AS READY</button>
                        </div>
                        <div class="p-4 rounded-xl bg-[#090909] border border-[#242424] space-y-3 text-xs opacity-60">
                            <div class="flex justify-between font-bold"><span class="text-zinc-400">#1083 &bull; Table 12</span><span class="text-zinc-500">Served</span></div>
                            <div class="space-y-1 text-zinc-400">
                                <div>2× Veg Chowmein</div>
                            </div>
                            <div class="text-[10px] text-zinc-500 font-bold text-center">COMPLETED</div>
                        </div>
                    </div>
                </div>

                <!-- Inventory Panel -->
                <div id="tab-inventory" class="tab-panel hidden rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#242424] pb-6">
                        <div>
                            <h3 class="text-xl font-extrabold text-white">Inventory &amp; Recipe Deductions</h3>
                            <p class="text-xs text-zinc-400 mt-1">Automatic stock management tied to dish sales</p>
                        </div>
                        <span class="px-3 py-1 rounded bg-amber-500/10 text-amber-400 text-xs font-mono font-bold">Live Stock Ledger</span>
                    </div>
                    <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-3 text-xs">
                        <div class="grid grid-cols-4 font-bold text-zinc-400 border-b border-[#242424] pb-2">
                            <span>INGREDIENT</span><span>CURRENT STOCK</span><span>REORDER LEVEL</span><span>AUTO DEDUCTION</span>
                        </div>
                        <div class="grid grid-cols-4 text-white"><span>Chicken Breast</span><span>14.5 kg</span><span>5.0 kg</span><span class="text-emerald-400">−0.40 kg / sale</span></div>
                        <div class="grid grid-cols-4 text-white"><span>Basmati Rice</span><span>42.0 kg</span><span>10.0 kg</span><span class="text-emerald-400">−0.30 kg / sale</span></div>
                        <div class="grid grid-cols-4 text-white"><span>Paneer Block</span><span>2.8 kg</span><span class="text-amber-400 font-bold">3.0 kg (LOW)</span><span class="text-emerald-400">−0.25 kg / sale</span></div>
                    </div>
                </div>

                <!-- Assets Panel -->
                <div id="tab-assets" class="tab-panel hidden rounded-2xl border border-[#242424] bg-[#111111] p-6 sm:p-10 space-y-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-[#242424] pb-6">
                        <div>
                            <h3 class="text-xl font-extrabold text-white">Kitchen Asset Management</h3>
                            <p class="text-xs text-zinc-400 mt-1">Equipment warranty, maintenance, and valuation tracking</p>
                        </div>
                        <span class="px-3 py-1 rounded bg-amber-500/10 text-amber-400 text-xs font-mono font-bold">Asset Register</span>
                    </div>
                    <div class="p-5 rounded-xl bg-[#090909] border border-[#242424] space-y-3 text-xs">
                        <div class="grid grid-cols-4 font-bold text-zinc-400 border-b border-[#242424] pb-2">
                            <span>EQUIPMENT</span><span>PURCHASE COST</span><span>WARRANTY STATUS</span><span>MAINTENANCE</span>
                        </div>
                        <div class="grid grid-cols-4 text-white"><span>Commercial Espresso Machine</span><span>NPR 180,000</span><span class="text-emerald-400">Active (2027)</span><span>Serviced July 2026</span></div>
                        <div class="grid grid-cols-4 text-white"><span>Deep Fryer (Double Tank)</span><span>NPR 65,000</span><span class="text-amber-400">Expires Oct 2026</span><span>Check Scheduled</span></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ HOW IT WORKS ============ -->
    <section id="how-it-works" class="py-24 md:py-32 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-16">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Go Live in 4 Simple Steps
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    A streamlined onboarding process guided by the RMS platform team.
                </p>
            </div>

            <!-- Steps Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="space-y-3">
                    <div class="text-4xl font-black text-amber-400">01</div>
                    <h3 class="text-lg font-extrabold text-white">Request Demo</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">
                        Fill in your restaurant details and preferred subscription plan using our online form.
                    </p>
                </div>

                <div class="space-y-3">
                    <div class="text-4xl font-black text-zinc-600">02</div>
                    <h3 class="text-lg font-extrabold text-white">Super Admin Reviews</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">
                        Our platform administration team reviews your application and validates your requirements.
                    </p>
                </div>

                <div class="space-y-3">
                    <div class="text-4xl font-black text-zinc-600">03</div>
                    <h3 class="text-lg font-extrabold text-white">Receive Credentials</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">
                        Get isolated tenant access credentials for your restaurant owner account.
                    </p>
                </div>

                <div class="space-y-3">
                    <div class="text-4xl font-black text-zinc-600">04</div>
                    <h3 class="text-lg font-extrabold text-white">Start Operating</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">
                        Configure table QR codes, input menu items, setup staff roles, and launch sales.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ PRICING SECTION ============ -->
    <section id="pricing" class="py-24 md:py-32 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-16">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Simple NPR Pricing for Every Restaurant
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Transparent plans that scale with your restaurant's volume.
                </p>
            </div>

            <!-- Pricing Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 items-stretch">
                <?php foreach ($pricingPlans as $p): ?>
                    <div class="<?= $p['popular'] ? 'border-2 border-amber-500 bg-[#161616]' : 'border border-[#242424] bg-[#111111]' ?> rounded-2xl p-7 flex flex-col justify-between space-y-6 relative">

                        <?php if ($p['popular']): ?>
                            <div class="absolute -top-3 left-1/2 -translate-x-1/2 px-3.5 py-0.5 rounded-full bg-amber-500 text-[#090909] text-[10px] font-black uppercase tracking-wider">
                                Most Popular
                            </div>
                        <?php endif; ?>

                        <div class="space-y-4">
                            <div>
                                <div class="text-xs font-bold uppercase tracking-wider text-amber-400"><?= $p['name'] ?></div>
                                <div class="text-3xl font-black text-white mt-1"><?= $p['price'] ?></div>
                                <div class="text-xs text-zinc-500 mt-0.5"><?= $p['suffix'] ?: 'Custom setup' ?></div>
                            </div>
                            <p class="text-xs text-[#A1A1AA] leading-relaxed"><?= $p['tagline'] ?></p>

                            <div class="pt-4 border-t border-[#242424] space-y-2 text-xs text-zinc-300">
                                <?php if ($p['base']): ?><div class="font-bold text-zinc-400 mb-2"><?= $p['base'] ?></div><?php endif; ?>
                                <?php foreach ($p['features'] as $f): ?>
                                    <div class="flex items-start gap-2">
                                        <span class="text-emerald-400 font-bold">✓</span>
                                        <span><?= $f ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <a href="#request-demo" data-plan-code="<?= $p['code'] ?>" class="<?= $p['popular'] ? 'bg-amber-500 text-[#090909] hover:bg-amber-400' : 'bg-[#161616] text-white border border-[#242424] hover:border-zinc-600' ?> w-full text-center py-3 rounded-xl text-xs font-extrabold transition-all">
                            <?= $p['cta'] ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ TRUST & SECURITY ============ -->
    <section class="py-24 md:py-32 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center space-y-4 mb-16">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Built for Secure Enterprise Operations
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Proven multi-tenant isolation and data protection safeguards.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="p-6 rounded-2xl border border-[#242424] bg-[#111111] space-y-2">
                    <div class="w-8 h-8 rounded-lg bg-zinc-900 text-emerald-400 flex items-center justify-center mb-3">
                        <?= svg_icon('shield', 'w-4 h-4') ?>
                    </div>
                    <h3 class="text-base font-bold text-white">Tenant Isolation</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">Database and session isolation ensures complete separation between restaurant accounts.</p>
                </div>

                <div class="p-6 rounded-2xl border border-[#242424] bg-[#111111] space-y-2">
                    <div class="w-8 h-8 rounded-lg bg-zinc-900 text-emerald-400 flex items-center justify-center mb-3">
                        <?= svg_icon('users', 'w-4 h-4') ?>
                    </div>
                    <h3 class="text-base font-bold text-white">Role-Based Access</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">Strict role permissions for owners, managers, cashiers, chefs, waiters, and inventory staff.</p>
                </div>

                <div class="p-6 rounded-2xl border border-[#242424] bg-[#111111] space-y-2">
                    <div class="w-8 h-8 rounded-lg bg-zinc-900 text-emerald-400 flex items-center justify-center mb-3">
                        <?= svg_icon('lock', 'w-4 h-4') ?>
                    </div>
                    <h3 class="text-base font-bold text-white">Secure Auth</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">BCRYPT password hashing, CSRF token verification, and session timeout controls.</p>
                </div>

                <div class="p-6 rounded-2xl border border-[#242424] bg-[#111111] space-y-2">
                    <div class="w-8 h-8 rounded-lg bg-zinc-900 text-emerald-400 flex items-center justify-center mb-3">
                        <?= svg_icon('receipt', 'w-4 h-4') ?>
                    </div>
                    <h3 class="text-base font-bold text-white">Audit Logging</h3>
                    <p class="text-xs text-[#A1A1AA] leading-relaxed">Full audit logging for sensitive administrative and financial transactions.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ RESTAURANT REQUEST FORM ============ -->
    <section id="request-demo" class="py-24 md:py-32 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 mb-12">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Request Your Restaurant Account
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Fill out your restaurant details below to request a demonstration and tenant workspace setup.
                </p>
            </div>

            <?php if ($requestSuccess): ?>
                <div class="p-8 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-center space-y-4">
                    <div class="w-12 h-12 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center mx-auto">
                        <?= svg_icon('check', 'w-6 h-6 stroke-[3]') ?>
                    </div>
                    <h3 class="text-xl font-extrabold text-white">Demo Request Received</h3>
                    <p class="text-xs text-zinc-300 max-w-md mx-auto leading-relaxed">
                        Thank you! Your request code is <span class="font-mono text-amber-400 font-bold"><?= htmlspecialchars($lastRequestCode) ?></span>. Our team will review your application and contact you shortly.
                    </p>
                    <a href="index.php" class="inline-block mt-4 px-6 py-2.5 rounded-xl bg-[#111111] border border-[#242424] text-xs font-bold text-white">Return to Home</a>
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
                            <label for="restaurant_name" class="block font-bold text-zinc-300 mb-1.5">Restaurant Name <span class="text-amber-400">*</span></label>
                            <input type="text" id="restaurant_name" name="restaurant_name" required placeholder="e.g. Himalayan Kitchen" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label for="owner_name" class="block font-bold text-zinc-300 mb-1.5">Owner Full Name <span class="text-amber-400">*</span></label>
                            <input type="text" id="owner_name" name="owner_name" required placeholder="e.g. Ramesh Sharma" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label for="email" class="block font-bold text-zinc-300 mb-1.5">Email Address <span class="text-amber-400">*</span></label>
                            <input type="email" id="email" name="email" required placeholder="owner@restaurant.com" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label for="phone" class="block font-bold text-zinc-300 mb-1.5">Contact Phone <span class="text-amber-400">*</span></label>
                            <input type="tel" id="phone" name="phone" required placeholder="98XXXXXXXX" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                        </div>

                        <div>
                            <label for="restaurant_type" class="block font-bold text-zinc-300 mb-1.5">Restaurant Type</label>
                            <select id="restaurant_type" name="restaurant_type" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white outline-none focus:border-amber-500">
                                <option value="Casual Dining" selected>Casual Dining</option>
                                <option value="Fine Dining">Fine Dining</option>
                                <option value="Fast Food / QSR">Fast Food / QSR</option>
                                <option value="Cafe & Bakery">Cafe & Bakery</option>
                                <option value="Bar & Lounge">Bar & Lounge</option>
                            </select>
                        </div>

                        <div>
                            <label for="table_count" class="block font-bold text-zinc-300 mb-1.5">Number of Tables <span class="text-amber-400">*</span></label>
                            <input type="number" id="table_count" name="table_count" min="1" max="1000" value="10" required class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white outline-none focus:border-amber-500">
                        </div>

                        <div class="sm:col-span-2">
                            <label for="preferred_plan" class="block font-bold text-zinc-300 mb-1.5">Preferred Plan</label>
                            <select id="preferred_plan" name="preferred_plan" class="w-full h-11 bg-[#090909] border border-[#242424] rounded-xl px-3.5 text-white outline-none focus:border-amber-500">
                                <option value="ESSENTIAL">Essential — NPR 1,500/month</option>
                                <option value="BUSINESS" selected>Business — NPR 2,500/month</option>
                                <option value="BUSINESS_PRO">Business Pro — NPR 4,500/month</option>
                                <option value="ENTERPRISE">Enterprise — Custom Pricing</option>
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <label for="message" class="block font-bold text-zinc-300 mb-1.5">Additional Requirements (Optional)</label>
                            <textarea id="message" name="message" rows="3" placeholder="Tell us about your restaurant..." class="w-full bg-[#090909] border border-[#242424] rounded-xl p-3 text-white placeholder-zinc-600 outline-none focus:border-amber-500"></textarea>
                        </div>
                    </div>

                    <div class="pt-2 flex flex-col sm:flex-row items-center gap-4">
                        <button type="submit" class="btn-submit w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-3.5 rounded-xl bg-amber-500 text-[#090909] font-extrabold text-xs hover:bg-amber-400 active:scale-95 transition-all">
                            <span class="btn-label">Request a Demo</span>
                            <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                        </button>
                        <span class="text-[11px] text-zinc-500 font-medium">No payment is collected during demo request.</span>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============ FAQ SECTION ============ -->
    <section id="faq" class="py-24 md:py-32 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 mb-14">
                <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                    Frequently Asked Questions
                </h2>
                <p class="text-base sm:text-lg text-[#A1A1AA] font-normal leading-relaxed">
                    Common answers for restaurant operators evaluating RMS SaaS.
                </p>
            </div>

            <div class="space-y-3">
                <?php
                $faqs = [
                    ['What is RMS SaaS?', 'RMS is an all-in-one restaurant management platform for Nepal that connects POS billing, QR table ordering, Kitchen Display System (KDS), floor & table management, inventory, payments and analytics in a single cloud workspace.'],
                    ['How does restaurant onboarding work?', 'You submit the request form. The Super Admin reviews it, contacts you, provisions your restaurant tenant workspace, and sends secure owner credentials.'],
                    ['Can I manage multiple restaurants?', 'Yes. RMS supports multi-tenant SaaS architecture where each restaurant operates inside its own logically isolated workspace.'],
                    ['Does RMS support QR table ordering?', 'Yes. Guests scan table QR codes using smartphone cameras, browse live digital menus, and place orders directly to the kitchen without app downloads.'],
                    ['Does RMS include inventory management?', 'Yes. RMS includes recipe-based stock deduction, item stock counts, reorder alerts, supplier purchase tracking, and waste logging.'],
                    ['Can I create staff accounts?', 'Yes. You can provision staff accounts for Manager, Cashier, Chef, Waiter, and Inventory roles with strict role-based access control.'],
                    ['What happens after requesting a demo?', 'Our team reviews your submission, contacts you via phone/email, and provides a walkthrough of your tenant workspace setup.'],
                    ['Can I upgrade my subscription plan?', 'Yes. You can upgrade between Essential, Business, and Business Pro plans as your restaurant volume expands.'],
                    ['How does pricing work?', 'Pricing is billed in simple monthly NPR tiers (NPR 1,500 / NPR 2,500 / NPR 4,500) based on module features and staff role needs.'],
                ];
                foreach ($faqs as $i => $faq): ?>
                    <div class="rounded-xl border border-[#242424] bg-[#111111] overflow-hidden">
                        <h3>
                            <button type="button" class="faq-btn w-full flex items-center justify-between gap-4 p-5 text-left" aria-expanded="false" aria-controls="faq-<?= $i + 1 ?>" id="faq-btn-<?= $i + 1 ?>">
                                <span class="text-sm font-bold text-white"><?= $faq[0] ?></span>
                                <span class="faq-icon w-6 h-6 shrink-0 rounded bg-zinc-900 border border-[#242424] text-amber-400 flex items-center justify-center"><?= svg_icon('plus', 'w-3.5 h-3.5') ?></span>
                            </button>
                        </h3>
                        <div id="faq-<?= $i + 1 ?>" class="faq-panel hidden px-5 pb-5" role="region" aria-labelledby="faq-btn-<?= $i + 1 ?>">
                            <div class="text-xs text-[#A1A1AA] leading-relaxed border-t border-[#242424] pt-3"><?= $faq[1] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ FINAL CTA ============ -->
    <section class="py-24 md:py-32 border-b border-[#242424] bg-[#090909]">
        <div class="max-w-4xl mx-auto px-4 text-center space-y-6">
            <h2 class="text-3xl sm:text-5xl font-black text-white tracking-tight leading-tight">
                Ready to Run Your Restaurant Smarter?
            </h2>
            <p class="text-base sm:text-lg text-[#A1A1AA] max-w-xl mx-auto font-normal leading-relaxed">
                Bring your restaurant operations, kitchen, inventory and payments into one connected platform.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-2">
                <a href="#request-demo" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl bg-amber-500 text-[#090909] font-extrabold text-sm hover:bg-amber-400 active:scale-95 transition-all shadow-lg">
                    Request a Demo
                    <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                </a>
                <a href="#pricing" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl bg-[#111111] border border-[#242424] text-white font-semibold text-sm hover:border-zinc-700 active:scale-95 transition-all">
                    View Pricing
                </a>
            </div>
        </div>
    </section>

    <!-- ============ FOOTER ============ -->
    <footer class="bg-[#090909] border-t border-[#242424]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-amber-500 flex items-center justify-center text-[#090909] font-black">
                        <?= svg_icon('bolt', 'w-4 h-4 stroke-[2.2]') ?>
                    </div>
                    <span class="font-extrabold text-white text-sm">RMS SaaS Platform</span>
                </div>

                <div class="flex flex-wrap justify-center gap-6 text-xs text-[#A1A1AA]">
                    <a href="#features" class="hover:text-white transition-colors">Features</a>
                    <a href="#showcase" class="hover:text-white transition-colors">Platform</a>
                    <a href="#pricing" class="hover:text-white transition-colors">Pricing</a>
                    <a href="#faq" class="hover:text-white transition-colors">FAQ</a>
                    <a href="admin/login.php" class="hover:text-white transition-colors text-amber-400 font-bold">Restaurant Login</a>
                    <a href="super-admin/login.php" class="hover:text-white transition-colors">Super Admin</a>
                    <a href="privacy-policy.php" class="hover:text-white transition-colors">Privacy Policy</a>
                    <a href="terms-of-service.php" class="hover:text-white transition-colors">Terms of Service</a>
                </div>

                <div class="text-xs text-zinc-500 font-medium">
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
            mobileMenu.querySelectorAll('a').forEach(function (link) {
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

        /* Form Double-Submit Protection */
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
