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
        'tagline' => 'For small cafés and restaurants',
        'cta'     => 'Choose Essential',
        'popular' => false,
        'base'    => '',
        'features'=> [
            'QR Table Ordering', 'Digital Menu', 'Basic POS', 'Kitchen Display System',
            'Table Management', 'Order Management', 'Basic Sales Reports',
            'Single Restaurant', 'Basic Staff Management',
        ],
    ],
    [
        'code'    => 'BUSINESS',
        'name'    => 'Business',
        'price'   => 'NPR 2,500',
        'suffix'  => '/ month',
        'tagline' => 'For growing restaurants',
        'cta'     => 'Choose Business',
        'popular' => true,
        'base'    => 'Everything in Essential, plus:',
        'features'=> [
            'Advanced POS', 'Full KDS', 'Inventory Management',
            'Recipe & Ingredient Tracking', 'Supplier Management', 'Digital Payments',
            'Advanced Reports', 'More Staff Accounts', 'Advanced Table Management',
        ],
    ],
    [
        'code'    => 'BUSINESS_PRO',
        'name'    => 'Business Pro',
        'price'   => 'NPR 4,500',
        'suffix'  => '/ month',
        'tagline' => 'For high-volume restaurants',
        'cta'     => 'Choose Business Pro',
        'popular' => false,
        'base'    => 'Everything in Business, plus:',
        'features'=> [
            'Advanced Inventory', 'Purchase & Stock Management', 'Asset Management',
            'Waste Management', 'Advanced Analytics', 'Advanced RBAC',
            'Audit Logs', 'Priority Support', 'Higher operational limits',
        ],
    ],
    [
        'code'    => 'ENTERPRISE',
        'name'    => 'Enterprise',
        'price'   => 'Custom Pricing',
        'suffix'  => '',
        'tagline' => 'For restaurant chains & multi-location businesses',
        'cta'     => 'Contact Sales',
        'popular' => false,
        'base'    => 'A dedicated, tailored deployment:',
        'features'=> [
            'Multi-location support', 'Centralized management', 'Multiple branches',
            'Custom roles & permissions', 'Custom integrations', 'API access',
            'Dedicated support', 'Custom deployment options', 'Enterprise SLA',
        ],
    ],
];

$csrfField = CSRF::getField();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth bg-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#09090b">
    <title>RMS SaaS — Restaurant POS, QR Ordering, KDS &amp; Inventory Management</title>
    <meta name="description" content="RMS is an all-in-one restaurant management platform with POS, QR ordering, Kitchen Display System, inventory, payments, table management and real-time restaurant operations.">
    <link rel="canonical" href="<?= rmsCanonicalUrl() ?>">

    <!-- Open Graph / Social -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="RMS SaaS">
    <meta property="og:title" content="RMS SaaS — Restaurant POS, QR Ordering, KDS &amp; Inventory Management">
    <meta property="og:description" content="RMS is an all-in-one restaurant management platform with POS, QR ordering, Kitchen Display System, inventory, payments, table management and real-time restaurant operations.">
    <meta property="og:url" content="<?= rmsCanonicalUrl() ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="RMS SaaS — Restaurant POS, QR Ordering, KDS &amp; Inventory Management">
    <meta name="twitter:description" content="POS, QR ordering, KDS, inventory, payments and real-time operations — one connected platform for your restaurant.">

    <!-- Favicon (inline SVG) -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23f59e0b'/%3E%3Cpath d='M17.5 4 8 18h6.5L13 28l9.5-14H16l1.5-10z' fill='%2309090b'/%3E%3C/svg%3E">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif']
                    },
                    animation: {
                        'pulse-soft': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'float': 'float 9s ease-in-out infinite'
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-12px)' }
                        }
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
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        ::selection { background: #f59e0b; color: #09090b; }

        /* Anchor offset below sticky header */
        [id] { scroll-margin-top: 96px; }

        /* Accessible focus states */
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
            outline: 2px solid #f59e0b;
            outline-offset: 2px;
            border-radius: 8px;
        }

        /* Brand gradient headline */
        .text-gradient-amber {
            background: linear-gradient(115deg, #fcd34d 0%, #f59e0b 55%, #d97706 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        /* Hero dashboard glow */
        .glow-amber {
            box-shadow:
                0 0 0 1px rgba(245, 158, 11, 0.28),
                0 0 50px -8px rgba(245, 158, 11, 0.35),
                0 40px 90px -30px rgba(0, 0, 0, 0.85);
        }
        .glow-amber-strong {
            box-shadow:
                0 0 0 1px rgba(245, 158, 11, 0.45),
                0 0 60px -6px rgba(245, 158, 11, 0.5),
                0 40px 90px -30px rgba(0, 0, 0, 0.9);
        }

        /* Scroll reveal */
        .reveal { opacity: 0; transform: translateY(26px); transition: opacity .7s cubic-bezier(.2,.6,.2,1), transform .7s cubic-bezier(.2,.6,.2,1); }
        .reveal.is-visible { opacity: 1; transform: none; }

        /* Subtle grid background for premium sections */
        .bg-grid {
            background-image:
                linear-gradient(to right, rgba(245, 158, 11, 0.045) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(245, 158, 11, 0.045) 1px, transparent 1px);
            background-size: 44px 44px;
        }

        /* Thin scrollbars inside mockups */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* FAQ chevron rotation */
        .faq-btn[aria-expanded="true"] .faq-icon { transform: rotate(180deg); }
        .faq-icon { transition: transform .25s ease; }

        /* Respect reduced motion */
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { animation: none !important; transition: none !important; }
            .reveal { opacity: 1; transform: none; }
        }
    </style>
    <noscript><style>.reveal { opacity: 1; transform: none; }</style></noscript>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased font-sans">

    <!-- ============ 1. TOP ANNOUNCEMENT BAR ============ -->
    <div class="relative bg-gradient-to-r from-amber-600 via-amber-400 to-amber-600 text-zinc-950">
        <div class="max-w-7xl mx-auto px-4 py-2.5 text-center text-[12px] sm:text-sm font-bold tracking-tight">
            <span class="inline-flex flex-wrap items-center justify-center gap-x-1.5">
                <?= svg_icon('bolt', 'w-4 h-4') ?>
                <span>Nepal's restaurant operating system — POS, QR ordering, KDS &amp; inventory in one platform.</span>
                <a href="#request-demo" class="underline underline-offset-2 font-extrabold hover:opacity-80">Request Your RMS Demo →</a>
            </span>
        </div>
    </div>

    <!-- ============ 2. STICKY NAVIGATION ============ -->
    <header class="sticky top-0 z-50 bg-zinc-950/85 backdrop-blur-xl border-b border-zinc-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between gap-4 h-[72px]">
                <!-- Brand -->
                <a href="index.php" class="flex items-center gap-3 group" aria-label="RMS SaaS — Home">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-amber-400 flex items-center justify-center text-zinc-950 shadow-lg shadow-amber-500/25 group-hover:scale-105 transition-transform">
                        <?= svg_icon('bolt', 'w-5 h-5 text-zinc-950 stroke-[2.2]') ?>
                    </div>
                    <div class="leading-tight">
                        <span class="block text-base sm:text-lg font-extrabold tracking-tight text-white">RMS SaaS</span>
                        <span class="block text-[10px] font-bold uppercase tracking-widest text-zinc-400">Restaurant Operating System</span>
                    </div>
                </a>

                <!-- Desktop links -->
                <nav class="hidden lg:flex items-center gap-7 text-[13px] font-semibold text-zinc-300" aria-label="Primary navigation">
                    <a href="#features" class="nav-link hover:text-amber-400 transition-colors">Features</a>
                    <a href="#how-it-works" class="nav-link hover:text-amber-400 transition-colors">How It Works</a>
                    <a href="#modules" class="nav-link hover:text-amber-400 transition-colors">Modules</a>
                    <a href="#pricing" class="nav-link hover:text-amber-400 transition-colors">Pricing</a>
                    <a href="#faq" class="nav-link hover:text-amber-400 transition-colors">FAQ</a>
                </nav>

                <!-- Action buttons -->
                <div class="hidden sm:flex items-center gap-3">
                    <a href="admin/login.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-zinc-800 bg-zinc-900 text-[13px] font-bold text-zinc-200 hover:text-white hover:border-zinc-700 transition-all">
                        <?= svg_icon('login', 'w-4 h-4 text-zinc-400') ?>
                        Restaurant Login
                    </a>
                    <a href="super-admin/login.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 text-[13px] font-bold text-amber-400 hover:bg-amber-500/20 transition-all">
                        <?= svg_icon('shield', 'w-4 h-4') ?>
                        Super Admin
                    </a>
                    <a href="#request-demo" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-amber-500 text-zinc-950 text-[13px] font-extrabold shadow-lg shadow-amber-500/25 hover:bg-amber-400 active:scale-95 transition-all">
                        Request Demo
                        <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                    </a>
                </div>

                <!-- Mobile hamburger -->
                <button id="mobile-menu-btn" type="button" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="mobile-menu" class="lg:hidden p-2.5 rounded-xl border border-zinc-800 bg-zinc-900 text-zinc-200 hover:text-white">
                    <?= svg_icon('menu', 'w-6 h-6') ?>
                </button>
            </div>
        </div>

        <!-- Mobile dropdown menu -->
        <div id="mobile-menu" class="hidden lg:hidden border-t border-zinc-800/80 bg-zinc-950/98 backdrop-blur-xl px-4 pt-4 pb-6 space-y-1">
            <a href="#features" class="mobile-link block px-3 py-2.5 rounded-xl text-sm font-bold text-zinc-300 hover:bg-zinc-900 hover:text-amber-400">Features</a>
            <a href="#how-it-works" class="mobile-link block px-3 py-2.5 rounded-xl text-sm font-bold text-zinc-300 hover:bg-zinc-900 hover:text-amber-400">How It Works</a>
            <a href="#modules" class="mobile-link block px-3 py-2.5 rounded-xl text-sm font-bold text-zinc-300 hover:bg-zinc-900 hover:text-amber-400">Modules</a>
            <a href="#pricing" class="mobile-link block px-3 py-2.5 rounded-xl text-sm font-bold text-zinc-300 hover:bg-zinc-900 hover:text-amber-400">Pricing</a>
            <a href="#faq" class="mobile-link block px-3 py-2.5 rounded-xl text-sm font-bold text-zinc-300 hover:bg-zinc-900 hover:text-amber-400">FAQ</a>
            <div class="pt-4 mt-2 border-t border-zinc-800 flex flex-col gap-2.5">
                <a href="admin/login.php" class="w-full inline-flex items-center justify-center gap-2 py-3 rounded-xl border border-zinc-800 bg-zinc-900 text-sm font-bold text-white">
                    <?= svg_icon('login', 'w-4 h-4 text-zinc-400') ?>
                    Restaurant Login
                </a>
                <a href="super-admin/login.php" class="w-full inline-flex items-center justify-center gap-2 py-3 rounded-xl border border-amber-500/30 bg-amber-500/10 text-sm font-bold text-amber-400">
                    <?= svg_icon('shield', 'w-4 h-4') ?>
                    Super Admin
                </a>
                <a href="#request-demo" class="w-full inline-flex items-center justify-center gap-2 py-3.5 rounded-xl bg-amber-500 text-zinc-950 text-sm font-extrabold shadow-lg shadow-amber-500/25">
                    Request a Demo
                    <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                </a>
            </div>
        </div>
    </header>

    <!-- ============ 3. HERO SECTION ============ -->
    <section class="relative overflow-hidden bg-zinc-950 border-b border-zinc-800/80">
        <div class="absolute inset-0 bg-grid opacity-60 pointer-events-none"></div>
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[900px] h-[520px] bg-amber-500/10 blur-[120px] rounded-full pointer-events-none"></div>
        <div class="absolute -top-40 -right-40 w-[480px] h-[480px] bg-amber-500/5 blur-[100px] rounded-full pointer-events-none"></div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-20 md:pt-24 md:pb-28">
            <div class="max-w-4xl mx-auto text-center space-y-7">
                <!-- Badge -->
                <div class="reveal inline-flex items-center gap-2 px-4 py-2 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] sm:text-xs font-extrabold tracking-[0.18em] uppercase">
                    <?= svg_icon('bolt', 'w-3.5 h-3.5') ?>
                    Built for Modern Restaurants
                </div>

                <!-- Headline -->
                <h1 class="reveal text-4xl sm:text-6xl lg:text-[4.25rem] font-black text-white tracking-tight leading-[1.05]">
                    Run Your Restaurant From<br class="hidden sm:block">
                    <span class="text-gradient-amber">One Powerful Platform</span>
                </h1>

                <!-- Supporting copy -->
                <p class="reveal text-base sm:text-lg md:text-xl text-zinc-400 max-w-3xl mx-auto leading-relaxed font-medium">
                    Restaurant POS, QR ordering, kitchen display, inventory, payments, asset management and real-time restaurant operations — all in one platform.
                </p>

                <!-- CTAs -->
                <div class="reveal flex flex-col sm:flex-row items-center justify-center gap-4 pt-2">
                    <a href="#request-demo" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-9 py-4 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-extrabold text-sm hover:from-amber-400 hover:to-amber-300 active:scale-95 shadow-xl shadow-amber-500/25 transition-all">
                        Request a Demo
                        <?= svg_icon('arrow', 'w-5 h-5 stroke-[2.4]') ?>
                    </a>
                    <a href="#features" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-9 py-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-white font-bold text-sm hover:border-zinc-600 hover:bg-zinc-800/80 active:scale-95 transition-all">
                        <?= svg_icon('search', 'w-5 h-5') ?>
                        Explore Platform
                    </a>
                </div>

                <!-- Trust mini-row -->
                <div class="reveal flex flex-wrap items-center justify-center gap-x-6 gap-y-2 pt-2 text-xs font-semibold text-zinc-500">
                    <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Real-time operations</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Multi-restaurant SaaS</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Nepal digital payments</span>
                </div>
            </div>

            <!-- ============ LARGE PRODUCT PREVIEW ============ -->
            <div class="reveal relative max-w-6xl mx-auto mt-16 md:mt-20">
                <!-- Ambient glow -->
                <div class="absolute -inset-5 sm:-inset-8 bg-amber-500/20 blur-3xl rounded-[48px] pointer-events-none"></div>

                <div class="relative rounded-3xl border border-zinc-800 bg-zinc-900/80 backdrop-blur-sm overflow-hidden glow-amber">
                    <!-- Browser chrome -->
                    <div class="flex items-center justify-between gap-4 px-5 py-3.5 border-b border-zinc-800 bg-zinc-950/60">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-rose-500/80"></span>
                            <span class="w-3 h-3 rounded-full bg-amber-500/80"></span>
                            <span class="w-3 h-3 rounded-full bg-emerald-500/80"></span>
                        </div>
                        <div class="hidden sm:flex items-center gap-2 px-3.5 py-1.5 rounded-lg bg-zinc-900 border border-zinc-800 text-[11px] font-mono text-zinc-400">
                            <?= svg_icon('lock', 'w-3.5 h-3.5 text-emerald-400') ?>
                            rms-saas.com/workspace/live
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/10 border border-emerald-500/25 text-emerald-400 text-[10px] font-extrabold uppercase tracking-wider">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse-soft"></span>
                            Live
                        </span>
                    </div>

                    <div class="p-4 sm:p-6 lg:p-8 space-y-5">
                        <!-- KPI row -->
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                            <div class="p-4 rounded-2xl bg-zinc-950/80 border border-zinc-800">
                                <div class="flex items-center gap-1.5 text-[11px] font-bold text-zinc-400 uppercase tracking-wider"><?= svg_icon('wallet', 'w-3.5 h-3.5 text-amber-400') ?> Today's Revenue</div>
                                <div class="text-xl sm:text-2xl lg:text-[1.7rem] font-black text-white mt-1.5">NPR 48,250</div>
                                <div class="text-[11px] font-bold text-emerald-400 mt-1">↑ 14% vs yesterday</div>
                            </div>
                            <div class="p-4 rounded-2xl bg-zinc-950/80 border border-zinc-800">
                                <div class="flex items-center gap-1.5 text-[11px] font-bold text-zinc-400 uppercase tracking-wider"><?= svg_icon('receipt', 'w-3.5 h-3.5 text-amber-400') ?> Active Orders</div>
                                <div class="text-xl sm:text-2xl lg:text-[1.7rem] font-black text-amber-400 mt-1.5">18 Batches</div>
                                <div class="text-[11px] text-zinc-500 font-medium mt-1">12 QR &bull; 6 POS</div>
                            </div>
                            <div class="p-4 rounded-2xl bg-zinc-950/80 border border-zinc-800">
                                <div class="flex items-center gap-1.5 text-[11px] font-bold text-zinc-400 uppercase tracking-wider"><?= svg_icon('grid', 'w-3.5 h-3.5 text-amber-400') ?> Floor Occupancy</div>
                                <div class="text-xl sm:text-2xl lg:text-[1.7rem] font-black text-white mt-1.5">14 / 20 Tables</div>
                                <div class="text-[11px] font-bold text-amber-400 mt-1">70% capacity occupied</div>
                            </div>
                            <div class="p-4 rounded-2xl bg-zinc-950/80 border border-zinc-800">
                                <div class="flex items-center gap-1.5 text-[11px] font-bold text-zinc-400 uppercase tracking-wider"><?= svg_icon('chef', 'w-3.5 h-3.5 text-amber-400') ?> Kitchen Queue (KDS)</div>
                                <div class="text-xl sm:text-2xl lg:text-[1.7rem] font-black text-emerald-400 mt-1.5">4 Tickets</div>
                                <div class="text-[11px] text-zinc-500 font-medium mt-1">Avg prep time 12 min</div>
                            </div>
                        </div>

                        <!-- Mid row: chart + live feed -->
                        <div class="grid grid-cols-1 lg:grid-cols-5 gap-3 sm:gap-4">
                            <!-- Revenue chart -->
                            <div class="lg:col-span-3 p-4 sm:p-5 rounded-2xl bg-zinc-950/80 border border-zinc-800">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-xs font-extrabold text-white uppercase tracking-wider">Revenue Today</span>
                                    <span class="text-[10px] font-mono text-zinc-500">Updated 2s ago</span>
                                </div>
                                <div class="flex items-end justify-between gap-1.5 sm:gap-2.5 h-28 sm:h-32">
                                    <?php $bars = [['10',32],['11',45],['12',58],['13',72],['14',55],['15',40],['16',38],['17',52],['18',74],['19',88],['20',78],['21',64]]; foreach ($bars as $b): ?>
                                        <div class="flex-1 flex flex-col items-center gap-1.5 group">
                                            <div class="w-full rounded-t-lg bg-gradient-to-t from-amber-600/60 to-amber-400/90 transition-all group-hover:from-amber-500 group-hover:to-amber-300" style="height:<?= $b[1] ?>%"></div>
                                            <span class="text-[9px] sm:text-[10px] font-mono text-zinc-600"><?= $b[0] ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Live order feed -->
                            <div class="lg:col-span-2 p-4 sm:p-5 rounded-2xl bg-zinc-950/80 border border-zinc-800">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-xs font-extrabold text-white uppercase tracking-wider">Live Order Monitor</span>
                                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse-soft"></span>
                                </div>
                                <div class="space-y-2.5">
                                    <div class="p-3 rounded-xl bg-zinc-900/70 border border-zinc-800">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="px-1.5 py-0.5 rounded-md bg-amber-500 text-zinc-950 font-black text-[10px]">T-04</span>
                                            <span class="text-[11px] font-mono text-zinc-400">NPR 1,450</span>
                                        </div>
                                        <div class="text-xs font-bold text-white">Royal Chicken Biryani ×2, Cold Coffee ×2</div>
                                        <span class="inline-block mt-2 px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/25 text-[10px] font-extrabold">PREPARING</span>
                                    </div>
                                    <div class="p-3 rounded-xl bg-zinc-900/70 border border-zinc-800">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="px-1.5 py-0.5 rounded-md bg-zinc-700 text-white font-black text-[10px]">T-09</span>
                                            <span class="text-[11px] font-mono text-zinc-400">NPR 920</span>
                                        </div>
                                        <div class="text-xs font-bold text-white">Paneer Butter Masala, Butter Naan ×4</div>
                                        <span class="inline-block mt-2 px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/25 text-[10px] font-extrabold">READY</span>
                                    </div>
                                    <div class="p-3 rounded-xl bg-zinc-900/70 border border-zinc-800">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="px-1.5 py-0.5 rounded-md bg-zinc-700 text-white font-black text-[10px]">T-12</span>
                                            <span class="text-[11px] font-mono text-zinc-400">NPR 610</span>
                                        </div>
                                        <div class="text-xs font-bold text-white">Veg Chowmein ×2, Lemon Tea ×2</div>
                                        <span class="inline-block mt-2 px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/25 text-[10px] font-extrabold">SERVED</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bottom row: floor map + KDS tick -->
                        <div class="grid grid-cols-1 lg:grid-cols-5 gap-3 sm:gap-4">
                            <!-- Floor map -->
                            <div class="lg:col-span-3 p-4 sm:p-5 rounded-2xl bg-zinc-950/80 border border-zinc-800">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-xs font-extrabold text-white uppercase tracking-wider">Floor &amp; Tables</span>
                                    <span class="flex items-center gap-2 text-[10px] font-bold text-zinc-500">
                                        <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-sm bg-zinc-700"></span>Free</span>
                                        <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-sm bg-amber-400"></span>Occupied</span>
                                    </span>
                                </div>
                                <div class="grid grid-cols-4 gap-2">
                                    <?php $tables = [['T1','occ'],['T2','occ'],['T3','free'],['T4','occ'],['T5','occ'],['T6','free'],['T7','occ'],['T8','free'],['T9','occ'],['T10','occ'],['T11','free'],['T12','occ']]; foreach ($tables as $t): ?>
                                        <div class="p-2 rounded-lg border text-center <?= $t[1] === 'occ' ? 'bg-amber-500/10 border-amber-500/30' : 'bg-zinc-900/70 border-zinc-800' ?>">
                                            <div class="text-[10px] font-black <?= $t[1] === 'occ' ? 'text-amber-400' : 'text-zinc-500' ?>"><?= $t[0] ?></div>
                                            <div class="text-[10px] font-semibold <?= $t[1] === 'occ' ? 'text-zinc-400' : 'text-zinc-600' ?>"><?= $t[1] === 'occ' ? '4 guests' : 'Vacant' ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- KDS ticket -->
                            <div class="lg:col-span-2 p-4 sm:p-5 rounded-2xl bg-zinc-950/80 border border-zinc-800">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-xs font-extrabold text-white uppercase tracking-wider">KDS Ticket</span>
                                    <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/25 text-[10px] font-extrabold">#1085</span>
                                </div>
                                <div class="space-y-2 text-xs">
                                    <div class="flex items-center justify-between text-zinc-400"><span>Table 05 &bull; 4 guests</span><span class="font-mono text-zinc-500">2:14</span></div>
                                    <div class="border-t border-dashed border-zinc-800 pt-2.5 space-y-1.5">
                                        <div class="flex justify-between"><span class="font-bold text-white">Chicken Chowmein</span><span class="text-zinc-400 font-mono">×2</span></div>
                                        <div class="flex justify-between"><span class="font-bold text-white">Chicken Momo (steam)</span><span class="text-zinc-400 font-mono">×1</span></div>
                                        <div class="flex justify-between"><span class="font-bold text-white">Masala Tea</span><span class="text-zinc-400 font-mono">×2</span></div>
                                    </div>
                                    <div class="flex items-center gap-2 pt-2">
                                        <span class="px-2 py-1 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/25 text-[10px] font-extrabold">PREPARING</span>
                                        <span class="text-[10px] text-zinc-500 font-medium">Station 1 &bull; Chef Bikash</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 4. VALUE STRIP ============ -->
    <section class="border-b border-zinc-800/80 bg-zinc-900/40 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                <?php
                $valueStrip = [
                    ['activity', 'Real-Time Operations'],
                    ['scan', 'QR Table Ordering'],
                    ['chef', 'Kitchen KDS'],
                    ['box', 'Inventory Control'],
                    ['card', 'Digital Payments'],
                    ['chart', 'Business Analytics'],
                ];
                foreach ($valueStrip as $chip): ?>
                    <div class="flex items-center justify-center gap-2.5 p-3.5 rounded-2xl bg-zinc-900 border border-zinc-800">
                        <?= svg_icon($chip[0], 'w-5 h-5 text-amber-400') ?>
                        <span class="text-[12px] sm:text-[13px] font-bold text-zinc-300"><?= $chip[1] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ 5. PROBLEM → SOLUTION ============ -->
    <section id="why-rms" class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-3xl mx-auto mb-14">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">The RMS Difference</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">Stop Managing Your Restaurant With Disconnected Tools</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">Eliminate order errors, kitchen delays and inventory leaks. One unified platform connects your floor, kitchen, payments and stock.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-8">
                <!-- Without RMS -->
                <div class="reveal rounded-3xl border border-rose-500/20 bg-zinc-900/50 p-7 sm:p-9">
                    <div class="flex items-center gap-3 mb-7">
                        <div class="w-11 h-11 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center justify-center">
                            <?= svg_icon('x', 'w-5 h-5 stroke-[2.4]') ?>
                        </div>
                        <div>
                            <h3 class="text-xl font-black text-white">Without RMS</h3>
                            <p class="text-xs text-zinc-500 font-medium">The old, disconnected way</p>
                        </div>
                    </div>
                    <ul class="space-y-3.5">
                        <?php
                        $without = [
                            ['Manual order handling', 'Orders passed by voice and paper — mistakes slip through.'],
                            ['Paper kitchen tickets', 'Slow handoffs and lost tickets during peak hours.'],
                            ['Spreadsheet inventory', 'Stock counts drift from reality; shortages appear mid-service.'],
                            ['Manual table tracking', 'Guests wait while staff guess which table is free.'],
                            ['Disconnected payment records', 'Cash and digital payments live in separate ledgers.'],
                            ['No centralized reporting', 'You discover yesterday\'s problems at the end of the month.'],
                        ];
                        foreach ($without as $item): ?>
                            <li class="flex items-start gap-3">
                                <span class="mt-0.5 w-5 h-5 rounded-full bg-rose-500/15 border border-rose-500/30 text-rose-400 flex items-center justify-center shrink-0"><?= svg_icon('x', 'w-3 h-3 stroke-[3]') ?></span>
                                <div>
                                    <div class="text-sm font-bold text-zinc-200"><?= $item[0] ?></div>
                                    <div class="text-[13px] text-zinc-500 leading-relaxed mt-0.5"><?= $item[1] ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- With RMS -->
                <div class="reveal rounded-3xl border border-emerald-500/30 bg-zinc-900/80 p-7 sm:p-9 shadow-2xl relative">
                    <div class="absolute -top-3 right-6 px-3 py-1 rounded-full bg-emerald-500 text-zinc-950 text-[10px] font-black uppercase tracking-wider shadow-lg">Recommended</div>
                    <div class="flex items-center gap-3 mb-7">
                        <div class="w-11 h-11 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center justify-center">
                            <?= svg_icon('check', 'w-5 h-5 stroke-[2.4]') ?>
                        </div>
                        <div>
                            <h3 class="text-xl font-black text-white">With RMS</h3>
                            <p class="text-xs text-zinc-500 font-medium">One connected operating platform</p>
                        </div>
                    </div>
                    <ul class="space-y-3.5">
                        <?php
                        $with = [
                            ['QR-based ordering', 'Guests order from their phone — orders hit the kitchen instantly.'],
                            ['Real-time KDS', 'Chefs see live tickets with timers and a clear status flow.'],
                            ['Automatic inventory tracking', 'Every sale deducts stock by recipe, automatically.'],
                            ['Digital floor & table management', 'Live floor map with vacant and occupied status.'],
                            ['Integrated payment processing', 'Cash and digital payments recorded on one bill.'],
                            ['Centralized analytics', 'One dashboard for sales, items, margins and staff.'],
                        ];
                        foreach ($with as $item): ?>
                            <li class="flex items-start gap-3">
                                <span class="mt-0.5 w-5 h-5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex items-center justify-center shrink-0"><?= svg_icon('check', 'w-3 h-3 stroke-[3]') ?></span>
                                <div>
                                    <div class="text-sm font-bold text-zinc-100"><?= $item[0] ?></div>
                                    <div class="text-[13px] text-zinc-400 leading-relaxed mt-0.5"><?= $item[1] ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 6. CORE CAPABILITIES ============ -->
    <section id="features" class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-900/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-3xl mx-auto mb-14">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">Capabilities</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">Everything Your Restaurant Needs — In One Dashboard</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">Twelve connected modules that work together so your floor, kitchen, back office and payments stay in sync.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
                <?php
                $capabilities = [
                    ['terminal', 'Restaurant POS', 'A fast billing terminal for cashiers — create bills, settle sessions and share receipts in seconds.'],
                    ['scan', 'QR Table Ordering', 'Turn every table into a self-ordering station. Guests scan, browse the live menu and order without waiting.'],
                    ['monitor', 'Kitchen Display System', 'Orders land on kitchen screens the moment they\'re placed — with prep timers, status controls and audio alerts.'],
                    ['grid', 'Floor & Table Management', 'See your whole floor at a glance. Track occupancy, guest counts, assigned waiters and per-table QR codes.'],
                    ['box', 'Inventory Management', 'Know exactly what\'s in stock and when to reorder. Low-stock alerts stop interruptions before they happen.'],
                    ['book', 'Recipe & Ingredient Tracking', 'Link each menu item to a recipe so every sale deducts the correct ingredients automatically.'],
                    ['truck', 'Purchase & Supplier Management', 'Raise purchase orders, receive goods and manage supplier records — all connected to live stock levels.'],
                    ['wrench', 'Asset Management', 'Track equipment, warranties, maintenance and depreciation so your kitchen assets never surprise you.'],
                    ['card', 'Digital Payments', 'Settle with cash, card or Nepal digital wallets, with a clear recorded payment status on every bill.'],
                    ['users', 'Staff & RBAC', 'Give each staff member exactly the access their role needs — nothing more, nothing less.'],
                    ['chart', 'Reports & Analytics', 'Daily sales, popular items, margins and peak hours in clear, decision-ready reports.'],
                    ['shield', 'Security & Audit Logs', 'Isolated workspaces and a complete audit trail keep your data safe and accountable.'],
                ];
                foreach ($capabilities as $cap): ?>
                    <div class="reveal group p-6 rounded-3xl bg-zinc-900 border border-zinc-800 hover:border-amber-500/40 hover:bg-zinc-900/70 transition-all duration-300">
                        <div class="w-11 h-11 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center mb-4 group-hover:bg-amber-500 group-hover:text-zinc-950 group-hover:border-amber-500 transition-colors duration-300">
                            <?= svg_icon($cap[0], 'w-5 h-5') ?>
                        </div>
                        <h3 class="text-[17px] font-extrabold text-white mb-2"><?= $cap[1] ?></h3>
                        <p class="text-[13px] text-zinc-400 leading-relaxed"><?= $cap[2] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ 7. 12 INTEGRATED CORE MODULES ============ -->
    <section id="modules" class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-3xl mx-auto mb-14">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">Modules</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">12 Integrated Core Modules</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">Outcome-focused capabilities engineered for seamless daily restaurant performance.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
                <?php
                $modules = [
                    ['01', 'terminal', 'POS & Billing', 'Fast cashier billing with instant bill generation, split settlement and dining-session totals.'],
                    ['02', 'scan', 'QR Ordering', 'Guests scan the table QR to browse the live menu and order straight from their phone.'],
                    ['03', 'monitor', 'Kitchen KDS', 'Digital tickets for chefs with live prep timers, dietary tags and status controls.'],
                    ['04', 'grid', 'Floor & Tables', 'Live floor map with vacant, occupied and reserved table status at a glance.'],
                    ['05', 'box', 'Inventory', 'Track stock, units, low-stock alerts and recipe-based ingredient deductions.'],
                    ['06', 'book', 'Recipe Management', 'Define exact recipes so every sale automatically deducts the right ingredients.'],
                    ['07', 'truck', 'Purchases & Suppliers', 'Purchase orders, goods receiving, supplier profiles and stock adjustments.'],
                    ['08', 'wrench', 'Asset Management', 'Register equipment with warranty, maintenance, depreciation and full history.'],
                    ['09', 'card', 'Payments', 'Cash, card and Nepal digital wallets with clear, recorded settlement status.'],
                    ['10', 'users', 'Staff & RBAC', 'Roles for owner, manager, cashier, kitchen, waiter and inventory staff.'],
                    ['11', 'chart', 'Reports & Analytics', 'Sales, popular items, margins, peak hours and staff performance insights.'],
                    ['12', 'shield', 'Security & Audit', 'Tenant isolation, secure sessions and audit trails for every action.'],
                ];
                foreach ($modules as $m): ?>
                    <div class="reveal group relative p-6 rounded-3xl bg-zinc-900 border border-zinc-800 hover:border-amber-500/40 transition-all duration-300 overflow-hidden">
                        <span class="absolute -top-2 -right-1 text-[64px] font-black leading-none text-zinc-800/40 select-none pointer-events-none group-hover:text-amber-500/15 transition-colors"><?= $m[0] ?></span>
                        <div class="w-11 h-11 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center mb-4">
                            <?= svg_icon($m[2] === 'QR Ordering' ? 'scan' : $m[1], 'w-5 h-5') ?>
                        </div>
                        <h3 class="text-[16px] font-extrabold text-white mb-2"><?= $m[0] ?> &bull; <?= $m[2] ?></h3>
                        <p class="text-[13px] text-zinc-400 leading-relaxed"><?= $m[3] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ 8. REAL-TIME OPERATIONS ============ -->
    <section id="how-it-works" class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-900/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 items-center">
                <!-- Left: copy -->
                <div class="space-y-6">
                    <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">
                        <?= svg_icon('activity', 'w-3.5 h-3.5') ?>
                        Real-Time Operations
                    </span>
                    <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">
                        From Order to Payment to Stock — All in Real Time
                    </h2>
                    <p class="text-sm sm:text-base text-zinc-400 leading-relaxed font-medium">
                        Every stage of a table's dining session flows through one connected system. The POS, kitchen KDS and inventory move together — no paper, no retyping, no chasing staff.
                    </p>
                    <div class="rounded-3xl border border-amber-500/30 bg-amber-500/5 p-6">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center shrink-0"><?= svg_icon('refresh', 'w-5 h-5') ?></div>
                            <div>
                                <div class="text-[15px] font-extrabold text-white">Your entire restaurant operation stays synchronized in real time.</div>
                                <p class="text-[13px] text-zinc-400 mt-1 leading-relaxed">Order statuses, kitchen tickets, table states and stock levels update together the instant anything changes.</p>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-[13px] font-bold text-zinc-300">
                        <div class="p-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center gap-2.5"><?= svg_icon('bell', 'w-4 h-4 text-amber-400') ?> Live kitchen alerts</div>
                        <div class="p-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center gap-2.5"><?= svg_icon('clock', 'w-4 h-4 text-amber-400') ?> Prep time tracking</div>
                        <div class="p-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center gap-2.5"><?= svg_icon('grid', 'w-4 h-4 text-amber-400') ?> Table status changes</div>
                        <div class="p-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center gap-2.5"><?= svg_icon('box', 'w-4 h-4 text-amber-400') ?> Automatic stock updates</div>
                    </div>
                </div>

                <!-- Right: live workflow visual -->
                <div class="reveal relative">
                    <div class="absolute -inset-4 bg-amber-500/10 blur-3xl rounded-[40px] pointer-events-none"></div>
                    <div class="relative rounded-3xl border border-zinc-800 bg-zinc-900/90 p-6 sm:p-8 overflow-hidden">
                        <div class="flex items-center justify-between mb-6 border-b border-zinc-800 pb-4">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center"><?= svg_icon('receipt', 'w-4 h-4') ?></div>
                                <div>
                                    <div class="text-sm font-extrabold text-white">Live Order — Table 07</div>
                                    <div class="text-[11px] font-mono text-zinc-500">Session #1084 &bull; 3 guests</div>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/10 border border-emerald-500/25 text-emerald-400 text-[10px] font-extrabold uppercase">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse-soft"></span> Live
                            </span>
                        </div>

                        <ol class="space-y-0">
                            <?php
                            $flow = [
                                ['check', 'Order Received', 'Chicken Biryani ×2 · Momo ×1', '12:01 PM'],
                                ['check', 'Kitchen Preparing', 'Ticket #1085 · Station 1', '12:03 PM'],
                                ['check', 'Ready', 'Ticket marked ready — waiter notified', '12:19 PM'],
                                ['check', 'Served', 'Dishes delivered to Table 07', '12:21 PM'],
                                ['check', 'Additional Order', 'Masala Tea ×2 added to the session', '12:40 PM'],
                                ['check', 'Final Bill', 'Session total · NPR 1,450', '1:02 PM'],
                                ['check', 'Payment', 'eSewa · NPR 1,450', '1:04 PM'],
                            ];
                            $flowCount = count($flow);
                            foreach ($flow as $i => $step): ?>
                                <li class="relative flex gap-4 pb-4">
                                    <?php if ($i < $flowCount - 1): ?><span class="absolute left-[15px] top-9 bottom-0 w-px bg-zinc-800" aria-hidden="true"></span><?php endif; ?>
                                    <span class="relative z-10 w-8 h-8 shrink-0 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center justify-center"><?= svg_icon($step[0], 'w-4 h-4 stroke-[2.2]') ?></span>
                                    <div class="flex-1 flex items-center justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-bold text-white"><?= $step[1] ?></div>
                                            <div class="text-[12px] text-zinc-500 mt-0.5"><?= $step[2] ?></div>
                                        </div>
                                        <span class="text-[11px] font-mono text-zinc-600 shrink-0"><?= $step[3] ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                            <!-- Inventory updated node -->
                            <li class="relative flex gap-4">
                                <span class="relative z-10 w-8 h-8 shrink-0 rounded-full bg-amber-500 text-zinc-950 flex items-center justify-center"><?= svg_icon('box', 'w-4 h-4 stroke-[2.2]') ?></span>
                                <div class="flex-1 flex items-center justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-extrabold text-white">Inventory Updated</div>
                                        <div class="text-[12px] text-zinc-500 mt-0.5">Chicken breast −0.4 kg · Rice −0.6 kg · auto-deducted by recipe</div>
                                    </div>
                                    <span class="shrink-0 px-2 py-1 rounded-lg bg-amber-500/10 border border-amber-500/25 text-amber-400 text-[10px] font-extrabold uppercase">Auto</span>
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 9. QR ORDERING ============ -->
    <section class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-3xl mx-auto mb-14">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">QR Ordering</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">QR Table Ordering — From Scan to Bill</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">No app downloads, no waiter chases. Guests scan, order, and the restaurant fulfills — all in one seamless session.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 items-start">
                <!-- Phone mockup -->
                <div class="reveal flex justify-center lg:justify-end">
                    <div class="relative w-[300px] sm:w-[340px] rounded-[36px] border-[6px] border-zinc-800 bg-zinc-900 overflow-hidden shadow-2xl animate-float">
                        <div class="bg-zinc-950 px-5 py-4 border-b border-zinc-800 flex items-center justify-between">
                            <span class="text-xs font-extrabold text-white"><?= svg_icon('utensils', 'w-3.5 h-3.5 inline mr-1 text-amber-400') ?> Himalayan Kitchen</span>
                            <span class="w-6 h-6 rounded-full bg-amber-500/15 border border-amber-500/40 flex items-center justify-center"><?= svg_icon('scan', 'w-3.5 h-3.5 text-amber-400') ?></span>
                        </div>
                        <div class="p-5 space-y-3">
                            <div class="text-[11px] font-extrabold text-zinc-400 uppercase tracking-wider">Digital Menu — Table 07</div>
                            <div class="p-3 rounded-xl bg-zinc-950 border border-zinc-800">
                                <div class="flex justify-between text-xs"><span class="font-bold text-white">Chicken Biryani</span><span class="font-mono text-amber-400">NPR 450</span></div>
                                <div class="flex justify-between items-center mt-2">
                                    <span class="text-[10px] text-zinc-500">Qty ×1</span>
                                    <span class="px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/25 text-[10px] font-extrabold">ADDED</span>
                                </div>
                            </div>
                            <div class="p-3 rounded-xl bg-zinc-950 border border-zinc-800">
                                <div class="flex justify-between text-xs"><span class="font-bold text-white">Veg Momo (steam)</span><span class="font-mono text-amber-400">NPR 180</span></div>
                                <div class="flex justify-between items-center mt-2">
                                    <span class="text-[10px] text-zinc-500">Qty ×2</span>
                                    <span class="px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/25 text-[10px] font-extrabold">ADDED</span>
                                </div>
                            </div>
                            <div class="p-3 rounded-xl bg-zinc-950 border border-zinc-800">
                                <div class="flex justify-between text-xs"><span class="font-bold text-white">Masala Tea</span><span class="font-mono text-amber-400">NPR 80</span></div>
                                <div class="flex justify-between items-center mt-2">
                                    <span class="text-[10px] text-zinc-500">Qty ×2</span>
                                    <span class="px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/25 text-[10px] font-extrabold">ADDED</span>
                                </div>
                            </div>
                            <div class="p-3.5 rounded-xl bg-amber-500 text-zinc-950 text-xs font-extrabold flex items-center justify-center gap-2">
                                <?= svg_icon('check', 'w-4 h-4 stroke-[2.4]') ?>
                                Order Placed — Sent to Kitchen
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 8 steps -->
                <div class="space-y-5">
                    <?php
                    $qrSteps = [
                        ['scan', 'Scan table QR', 'Guests scan the QR code printed on their table with any smartphone camera.'],
                        ['monitor', 'Open the digital menu', 'The live menu opens instantly in the browser — no app download needed.'],
                        ['utensils', 'Select items', 'Guests pick dishes, addons and quantities directly on screen.'],
                        ['bell', 'Order reaches the restaurant', 'The order lands in the restaurant\'s order queue the moment it\'s placed.'],
                        ['chef', 'Kitchen receives the order', 'Kitchen staff see the ticket on the KDS with every prep detail.'],
                        ['refresh', 'Staff prepares the food', 'Chefs cook, mark the ticket, and the status updates in real time.'],
                        ['plus', 'Order more before the bill', 'Guests can add extra items in the same table session — even after earlier food is served — before the final bill.'],
                        ['card', 'Final bill is settled', 'When guests finish, the full table session is billed and settled in one go.'],
                    ];
                    foreach ($qrSteps as $i => $step): ?>
                        <div class="reveal flex items-start gap-4">
                            <div class="w-9 h-9 shrink-0 rounded-2xl bg-amber-500/10 border border-amber-500/25 text-amber-400 flex items-center justify-center font-black text-[13px]"><?= $i + 1 ?></div>
                            <div class="flex-1 pt-0.5">
                                <h3 class="text-[15px] font-extrabold text-white"><?= $step[1] ?></h3>
                                <p class="text-[13px] text-zinc-400 leading-relaxed mt-0.5"><?= $step[2] ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Multi-round callout -->
            <div class="mt-14 max-w-4xl mx-auto rounded-3xl border border-amber-500/30 bg-amber-500/5 p-6 sm:p-8 text-center">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-wider mb-4"><?= svg_icon('plus', 'w-3.5 h-3.5') ?> Multi-round dining sessions</div>
                <p class="text-sm sm:text-[15px] text-zinc-300 font-medium leading-relaxed max-w-2xl mx-auto">
                    Guests don't need to re-scan or start over. After their first order is served, they can open the same session, add starters, mains or desserts, and the restaurant receives each order instantly — all tracked on one final bill.
                </p>
            </div>
        </div>
    </section>

    <!-- ============ 10. INVENTORY + ASSET MANAGEMENT ============ -->
    <section class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-900/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-3xl mx-auto mb-14">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">Back Office</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">Control Your Stock, Supplies &amp; Assets</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">A major differentiator — two connected sub-systems that protect your margins and your equipment.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8">
                <!-- Inventory -->
                <div class="reveal rounded-3xl border border-zinc-800 bg-zinc-900/80 p-7 sm:p-9">
                    <div class="flex items-center gap-3 border-b border-zinc-800 pb-5 mb-6">
                        <div class="w-11 h-11 rounded-2xl bg-amber-500/10 border border-amber-500/25 text-amber-400 flex items-center justify-center"><?= svg_icon('box', 'w-5 h-5') ?></div>
                        <div>
                            <h3 class="text-xl font-black text-white">Inventory Management</h3>
                            <p class="text-xs text-zinc-500 font-medium mt-0.5">Know exactly what you have, and exactly what you'll need</p>
                        </div>
                    </div>
                    <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <?php
                        $inv = [
                            ['box', 'Stock items & units'], ['truck', 'Purchase entries'],
                            ['refresh', 'Stock receiving'], ['wrench', 'Stock adjustments'],
                            ['bell', 'Low-stock alerts'], ['receipt', 'Waste recording'],
                            ['book', 'Recipe-based deductions'], ['users', 'Supplier management'],
                        ];
                        foreach ($inv as $i): ?>
                            <li class="flex items-center gap-2.5 p-3 rounded-xl bg-zinc-950 border border-zinc-800 text-[13px] font-semibold text-zinc-200">
                                <?= svg_icon($i[0], 'w-4 h-4 text-amber-400 shrink-0') ?>
                                <?= $i[1] ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Assets -->
                <div class="reveal rounded-3xl border border-zinc-800 bg-zinc-900/80 p-7 sm:p-9">
                    <div class="flex items-center gap-3 border-b border-zinc-800 pb-5 mb-6">
                        <div class="w-11 h-11 rounded-2xl bg-amber-500/10 border border-amber-500/25 text-amber-400 flex items-center justify-center"><?= svg_icon('wrench', 'w-5 h-5') ?></div>
                        <div>
                            <h3 class="text-xl font-black text-white">Asset Management</h3>
                            <p class="text-xs text-zinc-500 font-medium mt-0.5">Protect the equipment your kitchen depends on</p>
                        </div>
                    </div>
                    <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <?php
                        $assets = [
                            ['box', 'Asset register'], ['grid', 'Asset category'],
                            ['card', 'Purchase date & cost'], ['chart', 'Current value'],
                            ['shield', 'Warranty'], ['wrench', 'Maintenance'],
                            ['activity', 'Asset status'], ['clock', 'Depreciation'],
                            ['receipt', 'Asset history'],
                        ];
                        foreach ($assets as $a): ?>
                            <li class="flex items-center gap-2.5 p-3 rounded-xl bg-zinc-950 border border-zinc-800 text-[13px] font-semibold text-zinc-200">
                                <?= svg_icon($a[0], 'w-4 h-4 text-amber-400 shrink-0') ?>
                                <?= $a[1] ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 11. PAYMENTS ============ -->
    <section class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-3xl mx-auto mb-14">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">Payments</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">Flexible Payment Processing for Nepal</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">Settle every bill the way your guests prefer — with a clear, recorded payment status on each transaction.</p>
            </div>

            <div class="max-w-4xl mx-auto rounded-3xl border border-zinc-800 bg-zinc-900/80 p-7 sm:p-10">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <?php
                    $payments = [
                        ['wallet', 'eSewa', 'Configurable'],
                        ['card', 'Khalti', 'Configurable'],
                        ['scan', 'Fonepay', 'Configurable'],
                        ['key', 'ConnectIPS', 'Configurable'],
                        ['building', 'IME Pay', 'Configurable'],
                        ['receipt', 'Cash', 'Supported'],
                        ['card', 'Card', 'Supported'],
                    ];
                    foreach ($payments as $pay): ?>
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 flex flex-col items-center gap-2 text-center">
                            <span class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center"><?= svg_icon($pay[0], 'w-5 h-5') ?></span>
                            <span class="text-[13px] font-extrabold text-white"><?= $pay[1] ?></span>
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-extrabold uppercase <?= $pay[2] === 'Supported' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/25' : 'bg-zinc-800 text-zinc-400 border border-zinc-700' ?>"><?= $pay[2] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-6 flex items-start gap-3 p-4 rounded-2xl bg-zinc-950 border border-zinc-800">
                    <?= svg_icon('key', 'w-5 h-5 text-amber-400 shrink-0 mt-0.5') ?>
                    <p class="text-[13px] text-zinc-400 leading-relaxed">
                        Payment gateway integrations are <strong class="text-zinc-200">configurable per restaurant</strong>. Bring your own merchant credentials for eSewa, Khalti, Fonepay, ConnectIPS or IME Pay, and enable the gateways your customers actually use — alongside cash and card settlement.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 12. MULTI-RESTAURANT SaaS ============ -->
    <section class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-900/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-3xl mx-auto mb-14">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">SaaS Architecture</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">One Platform. Multiple Restaurants. Completely Isolated Workspaces.</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">A true multi-tenant architecture — every restaurant runs inside its own private workspace.</p>
            </div>

            <div class="max-w-4xl mx-auto rounded-3xl border border-zinc-800 bg-zinc-900/80 p-7 sm:p-10">
                <!-- Hierarchy -->
                <ol class="space-y-3">
                    <?php
                    $arch = [
                        ['shield', 'Super Admin', 'Manages tenants, reviews onboarding requests, provisions accounts'],
                        ['building', 'Restaurant Accounts', 'Each registered restaurant is its own isolated tenant'],
                        ['grid', 'Restaurant Workspace', 'Menu, tables, staff, payments and settings for that restaurant only'],
                        ['users', 'Staff Accounts', 'Owner, manager, cashier, kitchen, waiter & inventory roles'],
                        ['layers', 'POS · QR · KDS · Inventory · Reports', 'Every module runs inside the restaurant\'s workspace'],
                    ];
                    foreach ($arch as $a): ?>
                        <li class="flex items-center gap-4 p-4 rounded-2xl bg-zinc-950 border border-zinc-800">
                            <span class="w-10 h-10 shrink-0 rounded-xl bg-amber-500/10 border border-amber-500/25 text-amber-400 flex items-center justify-center"><?= svg_icon($a[0], 'w-5 h-5') ?></span>
                            <div>
                                <div class="text-[15px] font-extrabold text-white"><?= $a[1] ?></div>
                                <div class="text-[12px] text-zinc-500 mt-0.5"><?= $a[2] ?></div>
                            </div>
                        </li>
                        <?php if ($a[1] !== 'POS · QR · KDS · Inventory · Reports'): ?>
                            <li class="flex justify-center text-zinc-600 -my-1" aria-hidden="true"><?= svg_icon('arrow', 'w-4 h-4 rotate-90') ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>

                <!-- Isolation chips -->
                <div class="mt-8">
                    <div class="text-center text-xs font-extrabold text-zinc-400 uppercase tracking-wider mb-4">Fully isolated for each restaurant</div>
                    <div class="flex flex-wrap justify-center gap-2">
                        <?php
                        $isolated = ['Orders', 'Customers', 'Tables', 'Menu', 'Inventory', 'Staff', 'Payments', 'Reports', 'Settings'];
                        foreach ($isolated as $iso): ?>
                            <span class="px-3.5 py-1.5 rounded-full bg-zinc-950 border border-zinc-800 text-[12px] font-bold text-zinc-300"><?= $iso ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mt-6 p-4 rounded-2xl bg-zinc-950 border border-emerald-500/25 text-[13px] text-zinc-400 leading-relaxed text-center">
                    <?= svg_icon('shield', 'w-4 h-4 inline text-emerald-400 mr-1.5 -mt-0.5') ?>
                    <strong class="text-zinc-200">Tenant data isolation:</strong> no restaurant can access another restaurant's data — isolation is enforced at the session and database layer.
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 13. ONBOARDING ============ -->
    <section class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-3xl mx-auto mb-14">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">Onboarding</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">Go Live in 4 Simple Steps</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">A controlled, super-admin-guided onboarding flow — your restaurant is set up by the platform team, not left to figure it out alone.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <?php
                $onboard = [
                    ['01', 'Request Access', 'Submit the online request form below with your restaurant details.'],
                    ['02', 'Super Admin Review', 'Our team reviews your request and contacts you to confirm your setup.'],
                    ['03', 'Receive Credentials', 'The Super Admin creates your restaurant tenant and sends secure login credentials.'],
                    ['04', 'Configure & Go Live', 'Set up tables, menu, staff, payments and settings — then go live.'],
                ];
                foreach ($onboard as $o): ?>
                    <div class="reveal relative p-6 rounded-3xl bg-zinc-900 border border-zinc-800 hover:border-amber-500/40 transition-all duration-300">
                        <span class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-lg shadow-lg shadow-amber-500/25 mb-4"><?= $o[0] ?></span>
                        <h3 class="text-[16px] font-extrabold text-white mb-2"><?= $o[1] ?></h3>
                        <p class="text-[13px] text-zinc-400 leading-relaxed"><?= $o[2] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="text-center mt-12">
                <a href="#request-demo" class="inline-flex items-center gap-2 px-8 py-4 rounded-2xl bg-amber-500 text-zinc-950 font-extrabold text-sm hover:bg-amber-400 active:scale-95 shadow-xl shadow-amber-500/25 transition-all">
                    Request Restaurant Account
                    <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                </a>
            </div>
        </div>
    </section>

    <!-- ============ 14. PRICING ============ -->
    <section id="pricing" class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-900/30 relative overflow-hidden">
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[700px] h-[300px] bg-amber-500/5 blur-[100px] rounded-full pointer-events-none"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-3xl mx-auto mb-14">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">Pricing</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">Simple NPR Pricing for Every Restaurant</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">Start small, grow without switching systems. All plans include real-time operations and a single connected workspace.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 items-stretch">
                <?php foreach ($pricingPlans as $p): ?>
                    <div class="<?= $p['popular'] ? 'relative border-2 border-amber-500 bg-gradient-to-b from-amber-500/10 to-zinc-900 shadow-[0_0_45px_-10px_rgba(245,158,11,0.45)]' : 'relative border border-zinc-800 bg-zinc-900/80' ?> rounded-3xl p-7 flex flex-col">
                        <?php if ($p['popular']): ?>
                            <div class="absolute -top-3.5 left-1/2 -translate-x-1/2 px-4 py-1 rounded-full bg-amber-500 text-zinc-950 text-[10px] font-black uppercase tracking-widest shadow-lg shadow-amber-500/40 whitespace-nowrap">
                                Most Popular
                            </div>
                        <?php endif; ?>

                        <div class="mb-6">
                            <div class="text-[11px] font-black uppercase tracking-[0.18em] text-amber-400 mb-2"><?= $p['name'] ?></div>
                            <div class="text-3xl font-black text-white leading-none"><?= $p['price'] ?></div>
                            <?php if ($p['suffix']): ?><div class="text-[13px] text-zinc-500 font-medium mt-1.5"><?= $p['suffix'] ?></div><?php else: ?><div class="text-[13px] text-zinc-500 font-medium mt-1.5">Tailored to your needs</div><?php endif; ?>
                            <p class="text-[13px] text-zinc-400 mt-3 leading-relaxed"><?= $p['tagline'] ?></p>
                        </div>

                        <div class="flex-1 space-y-2.5 pb-6">
                            <?php if ($p['base']): ?><div class="text-[12px] font-bold text-zinc-400"><?= $p['base'] ?></div><?php endif; ?>
                            <?php foreach ($p['features'] as $f): ?>
                                <div class="flex items-start gap-2.5 text-[13px] text-zinc-300">
                                    <span class="mt-0.5 w-4 h-4 shrink-0 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex items-center justify-center"><?= svg_icon('check', 'w-2.5 h-2.5 stroke-[3]') ?></span>
                                    <span class="leading-snug"><?= $f ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <a href="#request-demo" data-plan-code="<?= $p['code'] ?>" class="<?= $p['popular'] ? 'bg-amber-500 hover:bg-amber-400 text-zinc-950 shadow-lg shadow-amber-500/30' : 'bg-zinc-800 hover:bg-amber-500 hover:text-zinc-950 text-white border border-zinc-700 hover:border-amber-500' ?> w-full inline-flex items-center justify-center gap-2 py-3.5 rounded-2xl text-sm font-extrabold active:scale-95 transition-all">
                            <?= $p['cta'] ?>
                            <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="text-center text-[12px] text-zinc-500 mt-8 font-medium">Pricing is in NPR. Custom configurations, multi-branch setups and enterprise needs — <a href="#request-demo" data-plan-code="ENTERPRISE" class="text-amber-400 hover:underline font-bold">contact sales</a>.</p>
        </div>
    </section>

    <!-- ============ 15. SECURITY ============ -->
    <section class="py-20 md:py-28 border-b border-zinc-800/80 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-3xl mx-auto mb-14">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">Security</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">Built for Secure Restaurant Operations</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">Commercial-grade safeguards protect your menu, sales, staff and customer data.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <?php
                $security = [
                    ['layers', 'Tenant Isolation', 'Every restaurant operates inside a logically isolated workspace. Cross-tenant access is blocked at the session and database layer.'],
                    ['users', 'Role-Based Access Control', 'Owner, manager, cashier, kitchen, waiter and inventory roles — each with exactly the permissions their job requires.'],
                    ['key', 'Secure Authentication', 'BCRYPT password hashing with forced temporary-password changes on first login.'],
                    ['lock', 'Session Management', 'HttpOnly, SameSite cookies with automatic idle timeout to protect logged-in sessions.'],
                    ['receipt', 'Audit Logs', 'A complete trail of logins, password changes and administrative actions for accountability.'],
                    ['shield', 'Data & API Isolation', 'Secure API architecture scopes every request to the authenticated restaurant tenant.'],
                ];
                foreach ($security as $s): ?>
                    <div class="reveal p-6 rounded-3xl bg-zinc-900 border border-zinc-800 hover:border-amber-500/40 transition-all duration-300">
                        <div class="w-11 h-11 rounded-2xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-400 flex items-center justify-center mb-4"><?= svg_icon($s[0], 'w-5 h-5') ?></div>
                        <h3 class="text-[16px] font-extrabold text-white mb-2"><?= $s[1] ?></h3>
                        <p class="text-[13px] text-zinc-400 leading-relaxed"><?= $s[2] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ 16. REQUEST FORM ============ -->
    <section id="request-demo" class="py-20 md:py-28 bg-zinc-950 border-b border-zinc-800/80 relative overflow-hidden">
        <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[800px] h-[400px] bg-amber-500/5 blur-[120px] rounded-full pointer-events-none"></div>
        <div class="relative max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-2xl mx-auto mb-12">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">Get Started</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">Request Your Restaurant Account</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-xl mx-auto font-medium leading-relaxed">Tell us about your restaurant and we'll set up a private RMS workspace — our team reviews every request personally.</p>
            </div>

            <?php if ($requestSuccess): ?>
                <div class="p-8 sm:p-10 rounded-3xl bg-emerald-500/10 border border-emerald-500/30 text-center shadow-2xl">
                    <div class="w-16 h-16 rounded-3xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center mx-auto mb-5">
                        <?= svg_icon('check', 'w-8 h-8 stroke-[2.2]') ?>
                    </div>
                    <h3 class="text-2xl font-black text-white mb-2">Request Received Successfully!</h3>
                    <p class="text-sm text-zinc-300 max-w-lg mx-auto leading-relaxed font-medium">
                        Thanks for your interest in RMS SaaS. Our team will contact you shortly to discuss your restaurant setup and provision your workspace.
                    </p>
                    <?php if ($lastRequestCode): ?>
                        <div class="inline-flex items-center gap-2 mt-5 px-4 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs font-mono text-zinc-400">
                            Reference: <span class="text-amber-400 font-extrabold"><?= htmlspecialchars($lastRequestCode) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="mt-6">
                        <a href="index.php" class="inline-flex items-center gap-2 px-6 py-3 rounded-2xl bg-zinc-800 text-white text-sm font-bold hover:bg-zinc-700 transition-all">Submit another request</a>
                    </div>
                </div>
            <?php else: ?>

                <?php if ($requestError): ?>
                    <div class="mb-6 flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-sm font-bold">
                        <?= svg_icon('x', 'w-5 h-5 stroke-[2.4] shrink-0 mt-0.5') ?>
                        <span><?= htmlspecialchars($requestError) ?></span>
                    </div>
                <?php endif; ?>

                <form id="restaurantRequestForm" method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 sm:p-10 shadow-2xl" novalidate>
                    <?= $csrfField ?>
                    <input type="hidden" name="action" value="submit_restaurant_request">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label for="restaurant_name" class="block text-[13px] font-bold text-zinc-300 mb-1.5">Restaurant Name <span class="text-amber-400">*</span></label>
                            <input type="text" id="restaurant_name" name="restaurant_name" required autocomplete="organization" placeholder="e.g. Himalayan Kitchen" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="owner_name" class="block text-[13px] font-bold text-zinc-300 mb-1.5">Owner Full Name <span class="text-amber-400">*</span></label>
                            <input type="text" id="owner_name" name="owner_name" required autocomplete="name" placeholder="e.g. Ramesh Sharma" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="email" class="block text-[13px] font-bold text-zinc-300 mb-1.5">Email Address <span class="text-amber-400">*</span></label>
                            <input type="email" id="email" name="email" required autocomplete="email" placeholder="owner@restaurant.com" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="phone" class="block text-[13px] font-bold text-zinc-300 mb-1.5">Contact Phone <span class="text-amber-400">*</span></label>
                            <input type="tel" id="phone" name="phone" required autocomplete="tel" inputmode="tel" pattern="[0-9+\-\s]{7,15}" placeholder="98XXXXXXXX" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="pan_number" class="block text-[13px] font-bold text-zinc-300 mb-1.5">PAN / VAT Number</label>
                            <input type="text" id="pan_number" name="pan_number" autocomplete="off" placeholder="Optional" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="restaurant_type" class="block text-[13px] font-bold text-zinc-300 mb-1.5">Restaurant Type</label>
                            <select id="restaurant_type" name="restaurant_type" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white outline-none focus:border-amber-500 transition-colors">
                                <option value="Fine Dining">Fine Dining</option>
                                <option value="Casual Dining" selected>Casual Dining</option>
                                <option value="Fast Food / QSR">Fast Food / QSR</option>
                                <option value="Cafe & Bakery">Cafe & Bakery</option>
                                <option value="Food Court">Food Court</option>
                                <option value="Bar & Lounge">Bar & Lounge</option>
                            </select>
                        </div>
                        <div>
                            <label for="table_count" class="block text-[13px] font-bold text-zinc-300 mb-1.5">Number of Tables <span class="text-amber-400">*</span></label>
                            <input type="number" id="table_count" name="table_count" min="1" max="1000" value="10" required class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="preferred_plan" class="block text-[13px] font-bold text-zinc-300 mb-1.5">Preferred Plan</label>
                            <select id="preferred_plan" name="preferred_plan" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white outline-none focus:border-amber-500 transition-colors">
                                <option value="ESSENTIAL">Essential — NPR 1,500/month</option>
                                <option value="BUSINESS" selected>Business — NPR 2,500/month</option>
                                <option value="BUSINESS_PRO">Business Pro — NPR 4,500/month</option>
                                <option value="ENTERPRISE">Enterprise — Custom Pricing</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="address" class="block text-[13px] font-bold text-zinc-300 mb-1.5">Address</label>
                            <input type="text" id="address" name="address" autocomplete="street-address" placeholder="City, area, street — e.g. Thamel, Kathmandu" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="message" class="block text-[13px] font-bold text-zinc-300 mb-1.5">Requirements / Message</label>
                            <textarea id="message" name="message" rows="3" placeholder="Tell us about your restaurant, current setup and what you'd like RMS to solve..." class="w-full bg-zinc-950 border border-zinc-800 rounded-2xl p-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors"></textarea>
                        </div>
                    </div>

                    <div class="mt-7 flex flex-col sm:flex-row items-center gap-4">
                        <button type="submit" class="btn-submit w-full sm:w-auto inline-flex items-center justify-center gap-2 px-9 py-4 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-extrabold text-sm hover:from-amber-400 hover:to-amber-300 active:scale-95 shadow-xl shadow-amber-500/25 transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                            <span class="btn-label">Submit Restaurant Request</span>
                            <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.4]') ?>
                        </button>
                        <p class="text-[12px] text-zinc-500 font-medium text-center sm:text-left">
                            Our team reviews every request. No payment is collected here.
                        </p>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============ 17. FAQ ============ -->
    <section id="faq" class="py-20 md:py-28 bg-zinc-950">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-4 max-w-2xl mx-auto mb-12">
                <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em]">FAQ</span>
                <h2 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-black text-white tracking-tight leading-tight">Frequently Asked Questions</h2>
                <p class="text-sm sm:text-base text-zinc-400 max-w-xl mx-auto font-medium leading-relaxed">Everything restaurant owners ask before switching to RMS.</p>
            </div>

            <div class="space-y-3">
                <?php
                $faqs = [
                    ['What is RMS?', 'RMS is an all-in-one restaurant management platform for Nepal that connects POS billing, QR table ordering, Kitchen Display System (KDS), floor & table management, inventory, payments and analytics in a single cloud workspace.'],
                    ['Is RMS suitable for small restaurants?', 'Yes. The Essential plan (NPR 1,500/month) is built for small cafés and restaurants — it includes QR ordering, a digital menu, basic POS, KDS and table management. You can upgrade as you grow.'],
                    ['Can customers order using QR?', 'Yes. Guests scan the QR code printed on their table with any smartphone camera, browse the live menu, and place orders directly to your restaurant — no app download required.'],
                    ['Does RMS have a Kitchen Display System?', 'Yes. The dedicated KDS shows incoming tickets to chefs in real time with preparation timers, dietary tags and status controls (new → preparing → ready → served).'],
                    ['Can customers place additional orders?', 'Yes. Guests can add extra items during the same table session — even after earlier orders are served — and everything is tracked on a single final bill.'],
                    ['Does RMS manage inventory?', 'Yes. RMS tracks stock items and units, purchase entries, goods receiving, adjustments, low-stock alerts, waste and automatic recipe-based ingredient deductions.'],
                    ['Which payment gateways are supported?', 'eSewa, Khalti, Fonepay, ConnectIPS and IME Pay are configurable per restaurant with your own merchant credentials, alongside cash and card settlement.'],
                    ['Can multiple restaurants use RMS?', 'Yes. RMS is a multi-tenant SaaS — each restaurant gets a completely isolated workspace. No restaurant can access another restaurant\'s data.'],
                    ['How does restaurant onboarding work?', 'You submit the request form. The Super Admin reviews it, contacts you, creates your restaurant tenant, and sends secure login credentials. Then you configure tables, menu, staff and payments before going live.'],
                    ['Can I upgrade my plan?', 'Yes. You can move between Essential, Business and Business Pro as your restaurant grows — contact the platform team or use the request form to change your plan.'],
                    ['Is there a free trial or demo?', 'Request a demo through the form on this page and our team will walk you through the platform with your restaurant\'s scenario before you commit to a plan.'],
                    ['How secure is restaurant data?', 'Restaurant data is stored in isolated tenant workspaces with role-based access control, secure authentication, session management and full audit logs. Isolation is enforced at the session and database layer.'],
                ];
                foreach ($faqs as $i => $faq): ?>
                    <div class="rounded-2xl bg-zinc-900 border border-zinc-800 overflow-hidden">
                        <h3>
                            <button type="button" class="faq-btn w-full flex items-center justify-between gap-4 p-5 text-left" aria-expanded="false" aria-controls="faq-<?= $i + 1 ?>" id="faq-btn-<?= $i + 1 ?>">
                                <span class="text-[15px] font-bold text-white"><?= $faq[0] ?></span>
                                <span class="faq-icon w-7 h-7 shrink-0 rounded-lg bg-amber-500/10 border border-amber-500/25 text-amber-400 flex items-center justify-center"><?= svg_icon('plus', 'w-4 h-4 stroke-[2.4]') ?></span>
                            </button>
                        </h3>
                        <div id="faq-<?= $i + 1 ?>" class="faq-panel hidden px-5 pb-5" role="region" aria-labelledby="faq-btn-<?= $i + 1 ?>">
                            <div class="text-[14px] text-zinc-400 leading-relaxed border-t border-zinc-800 pt-4"><?= $faq[1] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============ 18. FINAL CTA ============ -->
    <section class="py-20 md:py-28 bg-zinc-950 border-t border-zinc-800/80 relative overflow-hidden">
        <div class="absolute inset-0 bg-grid opacity-50 pointer-events-none"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[700px] h-[400px] bg-amber-500/10 blur-[120px] rounded-full pointer-events-none"></div>
        <div class="relative max-w-4xl mx-auto px-4 text-center space-y-7">
            <h2 class="text-3xl sm:text-5xl lg:text-[3.25rem] font-black text-white tracking-tight leading-tight">Ready to Run Your Restaurant Smarter?</h2>
            <p class="text-base sm:text-lg text-zinc-400 max-w-2xl mx-auto font-medium leading-relaxed">Replace disconnected tools with one powerful restaurant operating platform.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-2">
                <a href="#request-demo" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-9 py-4 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-extrabold text-sm hover:from-amber-400 hover:to-amber-300 active:scale-95 shadow-xl shadow-amber-500/25 transition-all">
                    Request a Demo
                    <?= svg_icon('arrow', 'w-5 h-5 stroke-[2.4]') ?>
                </a>
                <a href="admin/login.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-9 py-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-white font-bold text-sm hover:border-zinc-600 hover:bg-zinc-800/80 active:scale-95 transition-all">
                    <?= svg_icon('login', 'w-5 h-5') ?>
                    Restaurant Login
                </a>
            </div>
        </div>
    </section>

    <!-- ============ 19. FOOTER ============ -->
    <footer class="border-t border-zinc-800 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-10">
                <div class="col-span-2 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-amber-500 to-amber-400 text-zinc-950 flex items-center justify-center">
                            <?= svg_icon('bolt', 'w-5 h-5 stroke-[2.2]') ?>
                        </div>
                        <span class="font-extrabold text-white text-[15px]">RMS SaaS Platform</span>
                    </div>
                    <p class="text-[13px] text-zinc-500 leading-relaxed max-w-xs">
                        The multi-tenant restaurant operating system for Nepal — POS, QR ordering, KDS, inventory, payments and real-time operations in one platform.
                    </p>
                </div>

                <div class="space-y-3">
                    <div class="text-[11px] font-black text-white uppercase tracking-[0.16em]">Product</div>
                    <a href="#features" class="block text-[13px] text-zinc-400 hover:text-amber-400 transition-colors">Features</a>
                    <a href="#modules" class="block text-[13px] text-zinc-400 hover:text-amber-400 transition-colors">Modules</a>
                    <a href="#pricing" class="block text-[13px] text-zinc-400 hover:text-amber-400 transition-colors">Pricing</a>
                    <a href="#faq" class="block text-[13px] text-zinc-400 hover:text-amber-400 transition-colors">FAQ</a>
                </div>

                <div class="space-y-3">
                    <div class="text-[11px] font-black text-white uppercase tracking-[0.16em]">Company</div>
                    <a href="#why-rms" class="block text-[13px] text-zinc-400 hover:text-amber-400 transition-colors">About</a>
                    <a href="#request-demo" class="block text-[13px] text-zinc-400 hover:text-amber-400 transition-colors">Contact</a>
                    <a href="#request-demo" class="block text-[13px] text-zinc-400 hover:text-amber-400 transition-colors">Request Demo</a>
                </div>

                <div class="space-y-3">
                    <div class="text-[11px] font-black text-white uppercase tracking-[0.16em]">Portal</div>
                    <a href="admin/login.php" class="block text-[13px] text-amber-400 font-bold hover:underline transition-colors">Restaurant Login</a>
                    <a href="super-admin/login.php" class="block text-[13px] text-zinc-400 hover:text-amber-400 transition-colors">Super Admin Login</a>
                </div>

                <div class="space-y-3">
                    <div class="text-[11px] font-black text-white uppercase tracking-[0.16em]">Legal</div>
                    <a href="privacy-policy.php" class="block text-[13px] text-zinc-400 hover:text-amber-400 transition-colors">Privacy Policy</a>
                    <a href="terms-of-service.php" class="block text-[13px] text-zinc-400 hover:text-amber-400 transition-colors">Terms of Service</a>
                </div>
            </div>

            <div class="border-t border-zinc-800/80 mt-12 pt-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-[12px] text-zinc-500">
                <div>© 2026 RMS SaaS Platform. All rights reserved.</div>
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                    All systems operational
                </div>
            </div>
        </div>
    </footer>

    <!-- ============ INTERACTIVITY ============ -->
    <script>
    (function () {
        'use strict';

        /* ---------- Mobile menu ---------- */
        var menuBtn = document.getElementById('mobile-menu-btn');
        var mobileMenu = document.getElementById('mobile-menu');
        if (menuBtn && mobileMenu) {
            menuBtn.addEventListener('click', function () {
                var open = mobileMenu.classList.toggle('hidden') === false;
                menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                menuBtn.querySelector('svg').innerHTML = open
                    ? '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'
                    : '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>';
            });
            // Close menu when a mobile link is clicked
            mobileMenu.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    mobileMenu.classList.add('hidden');
                    menuBtn.setAttribute('aria-expanded', 'false');
                });
            });
        }

        /* ---------- FAQ accordion ---------- */
        var faqBtns = document.querySelectorAll('.faq-btn');
        faqBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var panel = document.getElementById(btn.getAttribute('aria-controls'));
                var isOpen = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                if (panel) panel.classList.toggle('hidden', isOpen);
            });
        });

        /* ---------- Pricing plan -> form sync ---------- */
        var planSelect = document.querySelector('select[name="preferred_plan"]');
        document.querySelectorAll('[data-plan-code]').forEach(function (el) {
            el.addEventListener('click', function () {
                if (planSelect) planSelect.value = el.getAttribute('data-plan-code');
            });
        });

        /* ---------- Request form: loading + double-submit guard ---------- */
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
                var arrow = btn.querySelector('svg');
                if (arrow) arrow.style.opacity = '0.4';
            });
        }

        /* ---------- Scroll reveal ---------- */
        var revealEls = document.querySelectorAll('.reveal');
        if ('IntersectionObserver' in window) {
            var revealObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        revealObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
            revealEls.forEach(function (el) { revealObserver.observe(el); });
        } else {
            revealEls.forEach(function (el) { el.classList.add('is-visible'); });
        }

        /* ---------- Scroll-spy for nav ---------- */
        var navLinks = document.querySelectorAll('.nav-link');
        if (navLinks.length && 'IntersectionObserver' in window) {
            var sectionIds = Array.prototype.map.call(navLinks, function (l) {
                return l.getAttribute('href');
            });
            var spyObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        navLinks.forEach(function (l) {
                            var active = l.getAttribute('href') === '#' + entry.target.id;
                            l.classList.toggle('text-amber-400', active);
                            l.classList.toggle('text-zinc-300', !active);
                        });
                    }
                });
            }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });
            sectionIds.forEach(function (href) {
                var sec = document.querySelector(href);
                if (sec) spyObserver.observe(sec);
            });
        }
    })();
    </script>
</body>
</html>
