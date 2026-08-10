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
        'bolt'          => '<path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/>',
        'scan'          => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3z"/><path d="M21 14v.01M14 21h.01M17 21h4"/>',
        'terminal'      => '<path d="m4 8 4 4-4 4"/><path d="M12 16h8"/>',
        'monitor'       => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'chef'          => '<path d="M17 21a1 1 0 0 0 1-1v-5.35c0-.46.32-.84.73-1.04a4 4 0 0 0-2.14-7.59 5 5 0 0 0-9.18 0 4 4 0 0 0-2.14 7.59c.41.2.73.58.73 1.04V20a1 1 0 0 0 1 1Z"/><path d="M6 17h12"/>',
        'grid'          => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box'           => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'wrench'        => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'card'          => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'users'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'chart'         => '<path d="M3 3v18h18"/><path d="M8 17v-4"/><path d="M13 17V7"/><path d="M18 17v-7"/>',
        'shield'        => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'lock'          => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'check'         => '<path d="M20 6 9 17l-5-5"/>',
        'x'             => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'arrow'         => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'menu'          => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'phone'         => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'mail'          => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'clock'         => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'plus'          => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'activity'      => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
        'login'         => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        'utensils'      => '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
        'receipt'       => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'spark'         => '<path d="m12 3 1.912 5.813a2 2 0 0 0 1.275 1.275L21 12l-5.813 1.912a2 2 0 0 0-1.275 1.275L12 21l-1.912-5.813a2 2 0 0 0-1.275-1.275L3 12l5.813-1.912a2 2 0 0 0 1.275-1.275L12 3z"/>',
        'split'         => '<path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.17-2.83L4 4"/><path d="m20 4-6.83 6.87A4 4 0 0 0 12 13.7"/>',
        'calendar'      => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'badge-percent' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="M9 9h.01"/><path d="M15 15h.01"/>',
        'rocket'        => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-3.05 11a22.35 22.35 0 0 1-3.95 2z"/><path d="M9 20a22 22 0 0 1 2-3.95"/>',
        'user-check'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
        'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
        'arrow-right'   => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'star'          => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'heart'         => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'alert-triangle'=> '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
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
        'tagline' => 'For small cafés and boutique diners needing fast ordering and billing.',
        'cta'     => 'Request Demo',
        'popular' => false,
        'features'=> [
            'Floor & Table Billing System',
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
        'tagline' => 'Everything a growing restaurant needs to run daily operations.',
        'cta'     => 'Request Demo',
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
        'tagline' => 'For high-volume restaurants needing advanced inventory and security controls.',
        'cta'     => 'Request Demo',
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
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0a0a">
    <title>RMS SaaS — Restaurant Management Platform</title>
    <meta name="description" content="Run your entire restaurant from one connected platform. Table billing, kitchen display, QR ordering, inventory, customer loyalty, and real-time analytics.">
    <link rel="canonical" href="<?= rmsCanonicalUrl() ?>">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="RMS SaaS">
    <meta property="og:title" content="RMS SaaS — Restaurant Management Platform">
    <meta property="og:description" content="Run your entire restaurant from one powerful platform. Table billing, KDS, inventory, customer loyalty, staff RBAC, and analytics.">
    <meta property="og:url" content="<?= rmsCanonicalUrl() ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="RMS SaaS — Restaurant Management Platform">
    <meta name="twitter:description" content="Complete restaurant operating system. POS, QR ordering, KDS, inventory and real-time analytics.">

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23f59e0b'/%3E%3Cpath d='M17.5 4 8 18h6.5L13 28l9.5-14H16l1.5-10z' fill='%230a0a0a'/%3E%3C/svg%3E">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'] },
                    maxWidth: { '8xl': '1320px' }
                }
            }
        }
    </script>

    <style>
        :root {
            --bg: #0a0a0a;
            --surface: #141414;
            --surface-2: #1c1c1c;
            --border: #262626;
            --border-light: #333333;
            --text: #fafafa;
            --text-muted: #a1a1aa;
            --accent: #f59e0b;
            --accent-hover: #d97706;
        }

        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); }
        ::selection { background: var(--accent); color: var(--bg); }
        [id] { scroll-margin-top: 80px; }

        /* Focus outline */
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
            outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 8px;
        }

        /* Accordion transition */
        .faq-btn[aria-expanded="true"] .faq-chevron { transform: rotate(180deg); }
        .faq-chevron { transition: transform .2s ease; }

        /* Scroll reveal */
        .reveal { opacity: 0; transform: translateY(24px); transition: opacity .5s ease, transform .5s ease; }
        .reveal.visible { opacity: 1; transform: translateY(0); }

        /* Navbar scroll state */
        .nav-scrolled { background: rgba(10,10,10,0.92) !important; backdrop-filter: blur(16px); border-bottom-color: var(--border) !important; }

        /* Pricing toggle */
        .billing-toggle input:checked + .toggle-track { background: var(--accent); }
        .billing-toggle input:checked + .toggle-track .toggle-thumb { transform: translateX(20px); }
        .toggle-track { width: 44px; height: 24px; background: #333; border-radius: 12px; position: relative; transition: background .2s; cursor: pointer; }
        .toggle-thumb { width: 20px; height: 20px; background: white; border-radius: 50%; position: absolute; top: 2px; left: 2px; transition: transform .2s; }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .reveal { opacity: 1; transform: none; transition: none; }
            *, *::before, *::after { animation: none !important; transition-duration: 0s !important; }
        }
    </style>
</head>
<body class="min-h-screen antialiased text-[var(--text)] bg-[var(--bg)]">

    <!-- ======== NAVIGATION ======== -->
    <header id="main-nav" class="sticky top-0 z-50 bg-[var(--bg)]/95 border-b border-transparent transition-all duration-300">
        <div class="max-w-8xl mx-auto px-5 sm:px-8">
            <div class="flex items-center justify-between h-[72px]">
                <a href="index.php" class="flex items-center gap-2.5" aria-label="RMS SaaS Home">
                    <div class="w-9 h-9 rounded-lg bg-amber-500 flex items-center justify-center text-[var(--bg)]">
                        <?= svg_icon('bolt', 'w-[18px] h-[18px] stroke-[2.5]') ?>
                    </div>
                    <span class="text-[17px] font-extrabold tracking-tight text-white">RMS SaaS</span>
                </a>

                <nav class="hidden md:flex items-center gap-7 text-[13px] font-semibold text-[var(--text-muted)]" aria-label="Main Navigation">
                    <a href="#product" class="hover:text-white transition-colors">Product</a>
                    <a href="#problem" class="hover:text-white transition-colors">The Problem</a>
                    <a href="#workflow" class="hover:text-white transition-colors">Workflow</a>
                    <a href="#pillars" class="hover:text-white transition-colors">Features</a>
                    <a href="#pricing" class="hover:text-white transition-colors">Pricing</a>
                    <a href="#faq" class="hover:text-white transition-colors">FAQ</a>
                </nav>

                <div class="hidden sm:flex items-center gap-3">
                    <a href="admin/login.php" class="px-4 py-2 text-[13px] font-semibold text-[var(--text-muted)] hover:text-white transition-colors">Login</a>
                    <a href="#request-demo" class="px-5 py-2.5 rounded-lg bg-amber-500 text-[var(--bg)] text-[13px] font-bold hover:bg-amber-400 transition-colors">Request a Demo</a>
                </div>

                <button id="mobile-menu-btn" type="button" aria-label="Toggle menu" aria-expanded="false" aria-controls="mobile-menu" class="md:hidden p-2 text-zinc-400 hover:text-white">
                    <?= svg_icon('menu', 'w-6 h-6') ?>
                </button>
            </div>
        </div>

        <div id="mobile-menu" class="hidden md:hidden border-t border-[var(--border)] bg-[var(--bg)] px-5 pb-6 pt-3 space-y-1">
            <a href="#product" class="mobile-link block py-2.5 text-[15px] font-medium text-zinc-300 hover:text-white">Product</a>
            <a href="#problem" class="mobile-link block py-2.5 text-[15px] font-medium text-zinc-300 hover:text-white">The Problem</a>
            <a href="#workflow" class="mobile-link block py-2.5 text-[15px] font-medium text-zinc-300 hover:text-white">Workflow</a>
            <a href="#pillars" class="mobile-link block py-2.5 text-[15px] font-medium text-zinc-300 hover:text-white">Features</a>
            <a href="#pricing" class="mobile-link block py-2.5 text-[15px] font-medium text-zinc-300 hover:text-white">Pricing</a>
            <a href="#faq" class="mobile-link block py-2.5 text-[15px] font-medium text-zinc-300 hover:text-white">FAQ</a>
            <div class="pt-4 border-t border-[var(--border)] flex flex-col gap-2.5">
                <a href="admin/login.php" class="w-full text-center py-2.5 rounded-lg border border-[var(--border)] text-[13px] font-semibold text-zinc-300">Login</a>
                <a href="#request-demo" class="w-full text-center py-3 rounded-lg bg-amber-500 text-[var(--bg)] text-[13px] font-bold">Request a Restaurant Demo</a>
            </div>
        </div>
    </header>

    <!-- ======== HERO ======== -->
    <section class="pt-16 pb-20 md:pt-28 md:pb-32 overflow-hidden">
        <div class="max-w-8xl mx-auto px-5 sm:px-8">
            <div class="max-w-[860px] mx-auto text-center">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-[var(--surface)] border border-[var(--border)] text-amber-400 text-[12px] font-bold tracking-wide mb-6">
                    <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                    <span>All-in-One Multi-Tenant Restaurant OS</span>
                </div>

                <h1 class="text-[clamp(2.25rem,5.5vw,4.25rem)] font-black text-white leading-[1.08] tracking-tight">
                    Run Your Entire Restaurant From One Powerful Platform
                </h1>

                <p class="mt-6 text-[17px] sm:text-[19px] text-[var(--text-muted)] leading-relaxed max-w-[740px] mx-auto font-normal">
                    Connect POS billing, kitchen display, table management, customer loyalty, inventory, and real-time revenue analytics in one unified workspace.
                </p>

                <div class="mt-9 flex flex-col sm:flex-row items-center justify-center gap-3.5">
                    <a href="#request-demo" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-lg bg-amber-500 text-[var(--bg)] font-extrabold text-[15px] hover:bg-amber-400 transition-colors shadow-lg shadow-amber-500/10">
                        Request a Restaurant Demo <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.5]') ?>
                    </a>
                    <a href="#workflow" class="w-full sm:w-auto inline-flex items-center justify-center px-7 py-3.5 rounded-lg border border-[var(--border)] text-white font-semibold text-[15px] hover:border-zinc-500 transition-colors">
                        See How RMS Works
                    </a>
                </div>

                <!-- Feature badges -->
                <div class="mt-10 flex flex-wrap items-center justify-center gap-3 text-[12px] font-semibold text-zinc-400">
                    <span class="px-3 py-1.5 rounded-md bg-[var(--surface)] border border-[var(--border)] text-zinc-300">POS & Billing</span>
                    <span class="px-3 py-1.5 rounded-md bg-[var(--surface)] border border-[var(--border)] text-zinc-300">Kitchen Display</span>
                    <span class="px-3 py-1.5 rounded-md bg-[var(--surface)] border border-[var(--border)] text-zinc-300">Floor & Tables</span>
                    <span class="px-3 py-1.5 rounded-md bg-[var(--surface)] border border-[var(--border)] text-zinc-300">QR Ordering</span>
                    <span class="px-3 py-1.5 rounded-md bg-[var(--surface)] border border-[var(--border)] text-zinc-300">Inventory & Recipe Stock</span>
                    <span class="px-3 py-1.5 rounded-md bg-[var(--surface)] border border-[var(--border)] text-zinc-300">Customer Loyalty</span>
                    <span class="px-3 py-1.5 rounded-md bg-[var(--surface)] border border-[var(--border)] text-zinc-300">Analytics</span>
                </div>
            </div>

            <!-- High Quality RMS Product Application Preview -->
            <div class="relative max-w-[1140px] mx-auto mt-14 md:mt-18">
                <div class="rounded-xl overflow-hidden border border-[var(--border)] bg-[var(--surface)]" style="box-shadow: 0 32px 64px -16px rgba(0,0,0,.8);">
                    <!-- Chrome Window Header -->
                    <div class="flex items-center justify-between px-4 py-3 border-b border-[var(--border)] bg-[var(--surface-2)]">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-[#ff5f57]"></span>
                            <span class="w-3 h-3 rounded-full bg-[#febc2e]"></span>
                            <span class="w-3 h-3 rounded-full bg-[#28c840]"></span>
                        </div>
                        <div class="flex items-center gap-2 px-4 py-1 rounded-md bg-[var(--bg)] text-[12px] text-zinc-400 font-mono border border-[var(--border)]">
                            <?= svg_icon('lock', 'w-3.5 h-3.5 text-emerald-400') ?>
                            <span class="text-zinc-200">admin/tables.php</span> &middot; <span class="text-amber-400 font-bold">Himalayan Kitchen (Table 04)</span>
                        </div>
                        <div class="text-[11px] font-bold text-emerald-400 bg-emerald-500/10 px-2.5 py-1 rounded border border-emerald-500/20">
                            TENANT ID #12 ACTIVE
                        </div>
                    </div>

                    <!-- Application Dashboard Content -->
                    <div class="p-4 sm:p-6 space-y-4">
                        <!-- Top Summary Cards -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <div class="p-3.5 rounded-lg bg-[var(--bg)] border border-[var(--border)]">
                                <div class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Today's Revenue</div>
                                <div class="text-xl font-black text-white mt-1">NPR 48,250</div>
                                <div class="text-[11px] font-semibold text-emerald-400 mt-0.5">18 Orders Settle Today</div>
                            </div>
                            <div class="p-3.5 rounded-lg bg-[var(--bg)] border border-[var(--border)]">
                                <div class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Active Tables</div>
                                <div class="text-xl font-black text-amber-400 mt-1">14 / 20 Occupied</div>
                                <div class="text-[11px] font-semibold text-zinc-400 mt-0.5">70% Occupancy</div>
                            </div>
                            <div class="p-3.5 rounded-lg bg-[var(--bg)] border border-[var(--border)]">
                                <div class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Kitchen Display</div>
                                <div class="text-xl font-black text-white mt-1">4 Prep Tickets</div>
                                <div class="text-[11px] font-semibold text-amber-400 mt-0.5">Avg Prep: 11 Min</div>
                            </div>
                            <div class="p-3.5 rounded-lg bg-[var(--bg)] border border-[var(--border)]">
                                <div class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Customer Loyalty</div>
                                <div class="text-xl font-black text-emerald-400 mt-1">1,240 Points</div>
                                <div class="text-[11px] font-semibold text-zinc-400 mt-0.5">Redeemed Today: NPR 250</div>
                            </div>
                        </div>

                        <!-- Main Split Interface: Table Grid + Table Operations Billing Drawer -->
                        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
                            <!-- Left: Table Layout Grid (8 cols) -->
                            <div class="lg:col-span-7 p-4 rounded-lg bg-[var(--bg)] border border-[var(--border)] space-y-3">
                                <div class="flex items-center justify-between border-b border-[var(--border)] pb-2.5">
                                    <div class="flex items-center gap-2">
                                        <?= svg_icon('grid', 'w-4 h-4 text-amber-400') ?>
                                        <span class="text-[13px] font-extrabold text-white">Main Dining Floor (Map)</span>
                                    </div>
                                    <div class="flex items-center gap-3 text-[11px] font-semibold">
                                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span> Occupied</span>
                                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Vacant</span>
                                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> Waiting Bill</span>
                                    </div>
                                </div>

                                <div class="grid grid-cols-3 sm:grid-cols-4 gap-2.5 pt-1">
                                    <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/40 text-center">
                                        <div class="font-black text-rose-400 text-sm">Table 01</div>
                                        <div class="text-[11px] text-zinc-300 mt-1">4 Guests &middot; Running</div>
                                        <div class="text-[11px] font-mono text-zinc-400 font-bold mt-0.5">NPR 1,850</div>
                                    </div>
                                    <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-center">
                                        <div class="font-black text-emerald-400 text-sm">Table 02</div>
                                        <div class="text-[11px] text-zinc-400 mt-1">Vacant</div>
                                        <div class="text-[11px] text-zinc-500 mt-0.5">Ready for guests</div>
                                    </div>
                                    <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/40 text-center">
                                        <div class="font-black text-rose-400 text-sm">Table 03</div>
                                        <div class="text-[11px] text-zinc-300 mt-1">2 Guests &middot; Running</div>
                                        <div class="text-[11px] font-mono text-zinc-400 font-bold mt-0.5">NPR 920</div>
                                    </div>
                                    <div class="p-3 rounded-lg bg-amber-500/20 border-2 border-amber-500 text-center ring-2 ring-amber-500/30">
                                        <div class="font-black text-amber-400 text-sm">Table 04 ★</div>
                                        <div class="text-[11px] text-amber-200 font-bold mt-1">Waiting Bill</div>
                                        <div class="text-[11px] font-mono text-amber-400 font-bold mt-0.5">NPR 1,441.60</div>
                                    </div>
                                    <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-center">
                                        <div class="font-black text-emerald-400 text-sm">Table 05</div>
                                        <div class="text-[11px] text-zinc-400 mt-1">Vacant</div>
                                        <div class="text-[11px] text-zinc-500 mt-0.5">Ready for guests</div>
                                    </div>
                                    <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/40 text-center">
                                        <div class="font-black text-rose-400 text-sm">Table 06</div>
                                        <div class="text-[11px] text-zinc-300 mt-1">6 Guests &middot; Running</div>
                                        <div class="text-[11px] font-mono text-zinc-400 font-bold mt-0.5">NPR 3,400</div>
                                    </div>
                                    <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-center">
                                        <div class="font-black text-emerald-400 text-sm">Table 07</div>
                                        <div class="text-[11px] text-zinc-400 mt-1">Vacant</div>
                                        <div class="text-[11px] text-zinc-500 mt-0.5">Ready for guests</div>
                                    </div>
                                    <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/40 text-center">
                                        <div class="font-black text-rose-400 text-sm">Table 08</div>
                                        <div class="text-[11px] text-zinc-300 mt-1">3 Guests &middot; Running</div>
                                        <div class="text-[11px] font-mono text-zinc-400 font-bold mt-0.5">NPR 1,200</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right: Real Table Operations Drawer (5 cols) -->
                            <div class="lg:col-span-5 p-4 rounded-lg bg-[var(--surface-2)] border border-amber-500/40 space-y-3">
                                <div class="flex items-center justify-between border-b border-[var(--border)] pb-2">
                                    <div>
                                        <span class="text-xs font-bold text-amber-400">TABLE OPERATIONS DRAWER</span>
                                        <h4 class="text-sm font-extrabold text-white">Table 04 &middot; Bill & Settle</h4>
                                    </div>
                                    <span class="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-400 text-[10px] font-bold">UNPAID</span>
                                </div>

                                <div class="space-y-1.5 text-[12px]">
                                    <div class="flex justify-between text-zinc-300 font-medium"><span>2× Royal Chicken Biryani</span><span class="font-mono">NPR 900.00</span></div>
                                    <div class="flex justify-between text-zinc-300 font-medium"><span>2× Special Cold Coffee</span><span class="font-mono">NPR 300.00</span></div>
                                    <div class="border-t border-[var(--border)] pt-1.5 space-y-1">
                                        <div class="flex justify-between text-zinc-400"><span>Subtotal</span><span class="font-mono">NPR 1,200.00</span></div>
                                        <div class="flex justify-between text-zinc-400"><span>Service Charge (10%)</span><span class="font-mono">NPR 120.00</span></div>
                                        <div class="flex justify-between text-zinc-400"><span>VAT (13%)</span><span class="font-mono">NPR 171.60</span></div>
                                        <div class="flex justify-between text-emerald-400 font-semibold"><span>Loyalty Discount (100 Pts)</span><span class="font-mono">−NPR 50.00</span></div>
                                        <div class="flex justify-between font-extrabold text-white text-[14px] pt-1.5 border-t border-[var(--border)]">
                                            <span>Amount Due</span>
                                            <span class="text-amber-400 font-mono">NPR 1,441.60</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="pt-1 flex gap-2">
                                    <button type="button" class="flex-1 py-2 bg-amber-500 text-[var(--bg)] font-extrabold rounded text-[12px] text-center shadow">
                                        💵 MARK AS PAID (CASH)
                                    </button>
                                    <button type="button" class="px-3 py-2 bg-[var(--bg)] border border-[var(--border)] text-zinc-300 font-semibold rounded text-[12px]">
                                        🧾 Receipt
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ======== TRUST & CREDIBILITY ======== -->
    <section class="py-16 border-t border-[var(--border)] bg-[var(--surface)]">
        <div class="max-w-8xl mx-auto px-5 sm:px-8 text-center reveal">
            <p class="text-[12px] font-bold uppercase tracking-[.2em] text-zinc-400 mb-6">Built for Modern Restaurant Operations</p>
            <div class="grid grid-cols-2 md:grid-cols-6 gap-4 items-center justify-center text-[14px] font-bold text-zinc-300">
                <div class="p-3.5 rounded-lg bg-[var(--bg)] border border-[var(--border)]">Cafés & Bakeries</div>
                <div class="p-3.5 rounded-lg bg-[var(--bg)] border border-[var(--border)]">Casual Dining</div>
                <div class="p-3.5 rounded-lg bg-[var(--bg)] border border-[var(--border)]">Fast Food & QSR</div>
                <div class="p-3.5 rounded-lg bg-[var(--bg)] border border-[var(--border)]">Fine Dining</div>
                <div class="p-3.5 rounded-lg bg-[var(--bg)] border border-[var(--border)]">Bars & Lounges</div>
                <div class="p-3.5 rounded-lg bg-[var(--bg)] border border-[var(--border)]">Restaurant Groups</div>
            </div>
        </div>
    </section>

    <!-- ======== THE PROBLEM SECTION ======== -->
    <section id="problem" class="py-24 md:py-32 border-t border-[var(--border)]">
        <div class="max-w-8xl mx-auto px-5 sm:px-8 reveal">
            <div class="max-w-[760px] mx-auto text-center mb-16">
                <p class="text-[12px] font-bold uppercase tracking-[.2em] text-amber-500 mb-4">The Restaurant Challenge</p>
                <h2 class="text-[clamp(1.75rem,4vw,2.75rem)] font-extrabold text-white tracking-tight leading-tight">
                    Disconnected Tools Slow Down Your Restaurant
                </h2>
                <p class="mt-4 text-[16px] text-[var(--text-muted)] leading-relaxed">
                    Most restaurants struggle by juggling separate software for POS billing, kitchen tickets, table management, inventory stock, customer details, and reports.
                </p>
            </div>

            <!-- Visual Flow: Disconnected vs RMS -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-stretch max-w-[1080px] mx-auto">
                <!-- Problem Side -->
                <div class="p-8 rounded-xl border border-rose-500/30 bg-rose-500/5 space-y-6 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center gap-2.5 text-rose-400 font-extrabold text-sm uppercase tracking-wider mb-2">
                            <?= svg_icon('alert-triangle', 'w-5 h-5') ?> The Disconnected Reality
                        </div>
                        <h3 class="text-xl font-bold text-white mb-4">Fragmented Systems Create Chaos</h3>
                        <div class="space-y-3 text-[14px]">
                            <div class="flex items-start gap-3 p-3 rounded-lg bg-[var(--bg)] border border-rose-500/20">
                                <span class="text-rose-400 font-bold">1</span>
                                <div class="text-zinc-300"><strong class="text-white">Disconnected POS & Kitchen:</strong> Waiters manually carry paper KOTs to kitchen cooks causing delays and lost items.</div>
                            </div>
                            <div class="flex items-start gap-3 p-3 rounded-lg bg-[var(--bg)] border border-rose-500/20">
                                <span class="text-rose-400 font-bold">2</span>
                                <div class="text-zinc-300"><strong class="text-white">Manual Table Tracking:</strong> Cashiers don't know table billing status or guest count leading to settlement confusion.</div>
                            </div>
                            <div class="flex items-start gap-3 p-3 rounded-lg bg-[var(--bg)] border border-rose-500/20">
                                <span class="text-rose-400 font-bold">3</span>
                                <div class="text-zinc-300"><strong class="text-white">Un-tracked Stock & Wastage:</strong> Menu items sell out without recipe inventory deductions or reorder alerts.</div>
                            </div>
                        </div>
                    </div>
                    <div class="p-3 rounded-lg bg-rose-500/10 text-rose-300 text-xs font-semibold text-center border border-rose-500/20">
                        Result: High operational friction, billing calculation errors & frustrated guests.
                    </div>
                </div>

                <!-- Solution Side (RMS) -->
                <div class="p-8 rounded-xl border border-emerald-500/30 bg-emerald-500/5 space-y-6 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center gap-2.5 text-emerald-400 font-extrabold text-sm uppercase tracking-wider mb-2">
                            <?= svg_icon('check', 'w-5 h-5') ?> The RMS Solution
                        </div>
                        <h3 class="text-xl font-bold text-white mb-4">One Connected Operating System</h3>
                        <div class="space-y-3 text-[14px]">
                            <div class="flex items-start gap-3 p-3 rounded-lg bg-[var(--bg)] border border-emerald-500/20">
                                <span class="text-emerald-400 font-bold">✓</span>
                                <div class="text-zinc-300"><strong class="text-white">Instant KDS Sync:</strong> Orders placed at tables appear immediately on kitchen display screens with prep timers.</div>
                            </div>
                            <div class="flex items-start gap-3 p-3 rounded-lg bg-[var(--bg)] border border-emerald-500/20">
                                <span class="text-emerald-400 font-bold">✓</span>
                                <div class="text-zinc-300"><strong class="text-white">Unified Table Operations:</strong> Open, update, split, or settle bills directly inside the Table Operations drawer.</div>
                            </div>
                            <div class="flex items-start gap-3 p-3 rounded-lg bg-[var(--bg)] border border-emerald-500/20">
                                <span class="text-emerald-400 font-bold">✓</span>
                                <div class="text-zinc-300"><strong class="text-white">Automated Recipe Deductions:</strong> Stock counts update automatically per order with low-stock alerts.</div>
                            </div>
                        </div>
                    </div>
                    <div class="p-3 rounded-lg bg-emerald-500/10 text-emerald-300 text-xs font-semibold text-center border border-emerald-500/20">
                        Result: Faster table turnover, accurate billing, loyal customers & complete financial control.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ======== SHOW THE REAL RMS WORKFLOW ======== -->
    <section id="workflow" class="py-24 md:py-32 border-t border-[var(--border)] bg-[var(--surface)]">
        <div class="max-w-8xl mx-auto px-5 sm:px-8 reveal">
            <div class="max-w-[720px] mx-auto text-center mb-16">
                <p class="text-[12px] font-bold uppercase tracking-[.2em] text-amber-500 mb-4">Step-by-Step Operations</p>
                <h2 class="text-[clamp(1.75rem,4vw,2.75rem)] font-extrabold text-white tracking-tight leading-tight">
                    How Orders Flow Through RMS
                </h2>
                <p class="mt-4 text-[16px] text-[var(--text-muted)]">
                    Follow an order from customer seating to kitchen preparation, payment, loyalty points, and revenue reports.
                </p>
            </div>

            <!-- Workflow Pipeline Grid -->
            <div class="max-w-[1080px] mx-auto">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 relative">
                    <!-- Step 1 -->
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="w-8 h-8 rounded-full bg-amber-500 text-[var(--bg)] font-black text-xs flex items-center justify-center">01</span>
                            <span class="text-[11px] font-mono text-zinc-500">Floor & QR</span>
                        </div>
                        <h3 class="text-base font-bold text-white">Customer Seated & Order Created</h3>
                        <p class="text-[13px] text-zinc-400 leading-relaxed">
                            Guests sit at Table 3. Order is created via waiter POS terminal or guest scans table QR code.
                        </p>
                    </div>

                    <!-- Step 2 -->
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="w-8 h-8 rounded-full bg-amber-500 text-[var(--bg)] font-black text-xs flex items-center justify-center">02</span>
                            <span class="text-[11px] font-mono text-zinc-500">KDS Ticket</span>
                        </div>
                        <h3 class="text-base font-bold text-white">Kitchen Receives & Prepares</h3>
                        <p class="text-[13px] text-zinc-400 leading-relaxed">
                            Order instantly arrives on Kitchen Display System (KDS). Chef marks items "Preparing" then "Ready".
                        </p>
                    </div>

                    <!-- Step 3 -->
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="w-8 h-8 rounded-full bg-amber-500 text-[var(--bg)] font-black text-xs flex items-center justify-center">03</span>
                            <span class="text-[11px] font-mono text-zinc-500">Table Status</span>
                        </div>
                        <h3 class="text-base font-bold text-white">Table Becomes "Waiting Bill"</h3>
                        <p class="text-[13px] text-zinc-400 leading-relaxed">
                            Floor map automatically updates Table 3 status to "Waiting Bill" for cashier visibility.
                        </p>
                    </div>

                    <!-- Step 4 -->
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="w-8 h-8 rounded-full bg-amber-500 text-[var(--bg)] font-black text-xs flex items-center justify-center">04</span>
                            <span class="text-[11px] font-mono text-zinc-500">Table Drawer</span>
                        </div>
                        <h3 class="text-base font-bold text-white">Cashier Opens Table 3</h3>
                        <p class="text-[13px] text-zinc-400 leading-relaxed">
                            Cashier opens Table Operations drawer directly in <code class="text-amber-400 font-mono text-[11px]">tables.php</code> without leaving the floor view.
                        </p>
                    </div>

                    <!-- Step 5 -->
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="w-8 h-8 rounded-full bg-amber-500 text-[var(--bg)] font-black text-xs flex items-center justify-center">05</span>
                            <span class="text-[11px] font-mono text-zinc-500">Loyalty & CRM</span>
                        </div>
                        <h3 class="text-base font-bold text-white">Customer Identified & Loyalty Applied</h3>
                        <p class="text-[13px] text-zinc-400 leading-relaxed">
                            Customer phone lookup pulls profile and applies available loyalty points to discount the bill.
                        </p>
                    </div>

                    <!-- Step 6 -->
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="w-8 h-8 rounded-full bg-amber-500 text-[var(--bg)] font-black text-xs flex items-center justify-center">06</span>
                            <span class="text-[11px] font-mono text-zinc-500">Settlement</span>
                        </div>
                        <h3 class="text-base font-bold text-white">Payment Completed & Receipt Printed</h3>
                        <p class="text-[13px] text-zinc-400 leading-relaxed">
                            Settle with Cash, Card, or Digital QR. Itemized tax receipt is generated instantly.
                        </p>
                    </div>
                </div>

                <div class="mt-8 p-4 rounded-xl bg-[var(--surface-2)] border border-[var(--border)] flex flex-wrap items-center justify-between gap-4 text-xs font-semibold text-zinc-300">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
                        <span>Table Auto-Clears to Vacant</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                        <span>Recipe Stock Auto-Deducted</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
                        <span>Revenue & Tax Recorded in Analytics</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ======== FIVE CORE PILLARS ======== -->
    <section id="pillars" class="py-24 md:py-32 border-t border-[var(--border)]">
        <div class="max-w-8xl mx-auto px-5 sm:px-8">
            <div class="max-w-[720px] text-center mx-auto mb-20 reveal">
                <p class="text-[12px] font-bold uppercase tracking-[.2em] text-amber-500 mb-4">Core Operating System</p>
                <h2 class="text-[clamp(1.75rem,4vw,2.75rem)] font-extrabold text-white tracking-tight leading-tight">
                    Five Pillars of RMS SaaS
                </h2>
            </div>

            <div class="space-y-24">
                <!-- Pillar 1: POS & Billing -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
                    <div>
                        <span class="text-[12px] font-bold uppercase tracking-[.15em] text-amber-500">01 &middot; POS & Billing</span>
                        <h3 class="mt-2 text-[26px] sm:text-[30px] font-black text-white leading-tight">Fast Table Billing & Tax Calculations</h3>
                        <p class="mt-3 text-[15px] text-[var(--text-muted)] leading-relaxed">
                            Create orders, calculate subtotal, 10% Service Charge, and 13% VAT automatically using server-side DECIMAL precision.
                        </p>
                        <ul class="mt-5 space-y-2 text-[14px] text-zinc-300">
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Cash, Card, and Digital QR code settlements</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Split bill by equal share, custom amount, or item</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Non-Chargeable (NCR) complimentary waivers</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Instant physical thermal or digital receipts</li>
                        </ul>
                    </div>
                    <div class="p-5 rounded-xl bg-[var(--surface)] border border-[var(--border)]">
                        <div class="p-4 rounded bg-[var(--bg)] border border-[var(--border)] space-y-2 text-[12px]">
                            <div class="flex justify-between font-bold text-amber-400 pb-2 border-b border-[var(--border)]">
                                <span>BILL SETTLEMENT &middot; TABLE 04</span>
                                <span>ORDER #1085</span>
                            </div>
                            <div class="flex justify-between text-zinc-300"><span>Subtotal (2 Items)</span><span class="font-mono">NPR 1,200.00</span></div>
                            <div class="flex justify-between text-zinc-400"><span>Service Charge (10%)</span><span class="font-mono">NPR 120.00</span></div>
                            <div class="flex justify-between text-zinc-400"><span>VAT (13%)</span><span class="font-mono">NPR 171.60</span></div>
                            <div class="flex justify-between text-emerald-400 font-semibold"><span>Loyalty Discount Applied</span><span class="font-mono">−NPR 50.00</span></div>
                            <div class="flex justify-between font-extrabold text-white text-[15px] pt-2 border-t border-[var(--border)]">
                                <span>TOTAL DUE</span>
                                <span class="text-amber-400 font-mono">NPR 1,441.60</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pillar 2: Kitchen & Orders -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
                    <div class="order-2 lg:order-1 p-5 rounded-xl bg-[var(--surface)] border border-[var(--border)]">
                        <div class="p-4 rounded bg-[var(--bg)] border border-[var(--border)] space-y-2.5 text-[12px]">
                            <div class="flex justify-between font-bold text-white border-b border-[var(--border)] pb-2">
                                <span>KITCHEN DISPLAY (KDS)</span>
                                <span class="text-amber-400 font-mono">4 TICKETS QUEUED</span>
                            </div>
                            <div class="p-2.5 rounded bg-[var(--surface)] border-l-4 border-l-amber-500 flex justify-between items-center">
                                <div>
                                    <div class="font-bold text-white">Ticket #1085 &middot; Table 04</div>
                                    <div class="text-zinc-400 text-[11px]">2× Chicken Biryani, 2× Cold Coffee</div>
                                </div>
                                <span class="px-2 py-1 rounded bg-amber-500/20 text-amber-400 font-bold text-[11px]">PREPARING (08m)</span>
                            </div>
                            <div class="p-2.5 rounded bg-[var(--surface)] border-l-4 border-l-emerald-500 flex justify-between items-center">
                                <div>
                                    <div class="font-bold text-white">Ticket #1084 &middot; Table 09</div>
                                    <div class="text-zinc-400 text-[11px]">1× Paneer Masala, 4× Butter Naan</div>
                                </div>
                                <span class="px-2 py-1 rounded bg-emerald-500/20 text-emerald-400 font-bold text-[11px]">READY</span>
                            </div>
                        </div>
                    </div>
                    <div class="order-1 lg:order-2">
                        <span class="text-[12px] font-bold uppercase tracking-[.15em] text-amber-500">02 &middot; Kitchen & Orders</span>
                        <h3 class="mt-2 text-[26px] sm:text-[30px] font-black text-white leading-tight">Real-Time Kitchen Display System (KDS)</h3>
                        <p class="mt-3 text-[15px] text-[var(--text-muted)] leading-relaxed">
                            Eliminate lost paper tickets. Orders route directly to kitchen screens with active prep timers and status buttons.
                        </p>
                        <ul class="mt-5 space-y-2 text-[14px] text-zinc-300">
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Live order status tracking (New → Preparing → Ready)</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Audio notifications for incoming table & QR orders</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Station routing per kitchen department</li>
                        </ul>
                    </div>
                </div>

                <!-- Pillar 3: Tables & Floor Operations -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
                    <div>
                        <span class="text-[12px] font-bold uppercase tracking-[.15em] text-amber-500">03 &middot; Tables & Floor Operations</span>
                        <h3 class="mt-2 text-[26px] sm:text-[30px] font-black text-white leading-tight">Live Floor Map & Digital Table QR Ordering</h3>
                        <p class="mt-3 text-[15px] text-[var(--text-muted)] leading-relaxed">
                            Monitor table occupancy, guest counts, and running order totals in real time on a color-coded floor map.
                        </p>
                        <ul class="mt-5 space-y-2 text-[14px] text-zinc-300">
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Visual table status indicators (Occupied, Vacant, Waiting Bill)</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Digital QR code ordering directly from guest smartphones</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Slide-out Table Operations drawer for quick billing</li>
                        </ul>
                    </div>
                    <div class="p-5 rounded-xl bg-[var(--surface)] border border-[var(--border)]">
                        <div class="grid grid-cols-2 gap-3 text-center text-[12px]">
                            <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/30">
                                <div class="font-bold text-rose-400">Table 01 &middot; Occupied</div>
                                <div class="text-zinc-400 mt-0.5">4 Guests &middot; NPR 1,850</div>
                            </div>
                            <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30">
                                <div class="font-bold text-emerald-400">Table 02 &middot; Vacant</div>
                                <div class="text-zinc-400 mt-0.5">Clean & Ready</div>
                            </div>
                            <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/30">
                                <div class="font-bold text-amber-400">Table 04 &middot; Waiting Bill</div>
                                <div class="text-zinc-400 mt-0.5">NPR 1,441.60</div>
                            </div>
                            <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30">
                                <div class="font-bold text-emerald-400">Table 05 &middot; Vacant</div>
                                <div class="text-zinc-400 mt-0.5">Clean & Ready</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pillar 4: Customers & Loyalty -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
                    <div class="order-2 lg:order-1 p-5 rounded-xl bg-[var(--surface)] border border-[var(--border)]">
                        <div class="p-4 rounded bg-[var(--bg)] border border-[var(--border)] space-y-3 text-[12px]">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-white">CUSTOMER PROFILE & LOYALTY</span>
                                <span class="text-amber-400 font-bold">184 POINTS</span>
                            </div>
                            <div class="text-zinc-300">
                                <div class="font-extrabold text-white text-[14px]">Ramesh Sharma</div>
                                <div class="text-zinc-400 text-[11px]">Phone: 9841XXXXXX &middot; 24 Visits</div>
                            </div>
                            <div class="p-2.5 rounded bg-[var(--surface)] border border-amber-500/30 text-amber-300 text-[11px] flex justify-between">
                                <span>Redeemable Points: 100 Pts</span>
                                <span class="font-bold text-amber-400">= NPR 50.00 Off Bill</span>
                            </div>
                        </div>
                    </div>
                    <div class="order-1 lg:order-2">
                        <span class="text-[12px] font-bold uppercase tracking-[.15em] text-amber-500">04 &middot; Customers & Loyalty</span>
                        <h3 class="mt-2 text-[26px] sm:text-[30px] font-black text-white leading-tight">Customer CRM & Loyalty Program</h3>
                        <p class="mt-3 text-[15px] text-[var(--text-muted)] leading-relaxed">
                            Build customer profiles with phone numbers, visit counts, total spend history, and earnable loyalty rewards.
                        </p>
                        <ul class="mt-5 space-y-2 text-[14px] text-zinc-300">
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Customer phone lookup during table billing</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Configurable earning & redemption thresholds in settings</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Automatic refund point reversals on order voiding</li>
                        </ul>
                    </div>
                </div>

                <!-- Pillar 5: Inventory & Analytics -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
                    <div>
                        <span class="text-[12px] font-bold uppercase tracking-[.15em] text-amber-500">05 &middot; Inventory & Analytics</span>
                        <h3 class="mt-2 text-[26px] sm:text-[30px] font-black text-white leading-tight">Ingredient Stock & Revenue Reports</h3>
                        <p class="mt-3 text-[15px] text-[var(--text-muted)] leading-relaxed">
                            Link recipes to menu products for automatic inventory stock deductions. View daily sales, top products, and item profit margins.
                        </p>
                        <ul class="mt-5 space-y-2 text-[14px] text-zinc-300">
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Recipe stock deduction per order item</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Low stock reorder threshold notifications</li>
                            <li class="flex gap-2.5"><?= svg_icon('check', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5') ?> Real-time daily revenue and sales breakdown</li>
                        </ul>
                    </div>
                    <div class="p-5 rounded-xl bg-[var(--surface)] border border-[var(--border)]">
                        <div class="p-4 rounded bg-[var(--bg)] border border-[var(--border)] space-y-3 text-[12px]">
                            <div class="flex justify-between font-bold text-white border-b border-[var(--border)] pb-2">
                                <span>INVENTORY STOCK ALERTS</span>
                                <span class="text-emerald-400">VALUATION: NPR 1,24,000</span>
                            </div>
                            <div class="space-y-1.5">
                                <div class="flex justify-between text-zinc-300"><span>Basmati Rice</span><span class="text-emerald-400 font-bold">45 kg (Sufficient)</span></div>
                                <div class="flex justify-between text-zinc-300"><span>Fresh Chicken</span><span class="text-amber-400 font-bold">8 kg (Low Stock)</span></div>
                                <div class="flex justify-between text-zinc-300"><span>Cooking Oil</span><span class="text-rose-400 font-bold">2 L (Reorder Alert)</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ======== PRODUCT DEMONSTRATION (Interactive Switcher) ======== -->
    <section id="product" class="py-24 md:py-32 border-t border-[var(--border)] bg-[var(--surface)]">
        <div class="max-w-8xl mx-auto px-5 sm:px-8 reveal">
            <div class="max-w-[720px] mx-auto text-center mb-12">
                <p class="text-[12px] font-bold uppercase tracking-[.2em] text-amber-500 mb-4">Live Product Demonstration</p>
                <h2 class="text-[clamp(1.75rem,4vw,2.75rem)] font-extrabold text-white tracking-tight leading-tight">
                    Experience RMS in Action
                </h2>
                <p class="mt-4 text-[16px] text-[var(--text-muted)]">
                    Switch between module previews to explore the actual application interface.
                </p>
            </div>

            <!-- Tab Buttons -->
            <div class="flex flex-wrap justify-center gap-2 mb-10">
                <button type="button" class="tab-btn active px-4 py-2.5 rounded-lg text-[13px] font-bold transition-all border border-amber-500/50 bg-amber-500/10 text-amber-400" data-tab="table-billing">Floor & Tables</button>
                <button type="button" class="tab-btn px-4 py-2.5 rounded-lg text-[13px] font-bold transition-all border border-[var(--border)] text-zinc-400 hover:text-white" data-tab="billing">Billing & Payments</button>
                <button type="button" class="tab-btn px-4 py-2.5 rounded-lg text-[13px] font-bold transition-all border border-[var(--border)] text-zinc-400 hover:text-white" data-tab="kds">Kitchen Display (KDS)</button>
                <button type="button" class="tab-btn px-4 py-2.5 rounded-lg text-[13px] font-bold transition-all border border-[var(--border)] text-zinc-400 hover:text-white" data-tab="inventory">Inventory Control</button>
                <button type="button" class="tab-btn px-4 py-2.5 rounded-lg text-[13px] font-bold transition-all border border-[var(--border)] text-zinc-400 hover:text-white" data-tab="loyalty">Customer Loyalty</button>
                <button type="button" class="tab-btn px-4 py-2.5 rounded-lg text-[13px] font-bold transition-all border border-[var(--border)] text-zinc-400 hover:text-white" data-tab="analytics">Revenue Analytics</button>
            </div>

            <!-- Visual Switcher Panels -->
            <div class="max-w-[1080px] mx-auto">
                <!-- Panel 1: Floor & Tables -->
                <div id="tab-table-billing" class="tab-panel">
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-4">
                        <div class="flex items-center justify-between border-b border-[var(--border)] pb-3">
                            <h3 class="font-extrabold text-white text-lg">Floor & Table Operations (<code class="text-amber-400 font-mono text-sm">admin/tables.php</code>)</h3>
                            <span class="text-xs text-amber-400 font-bold bg-amber-500/10 px-3 py-1 rounded border border-amber-500/20">LIVE MAP VIEW</span>
                        </div>
                        <p class="text-sm text-zinc-300">
                            Real-time floor grid showing occupied, vacant, and waiting-bill tables. Cashiers click any table to launch the Table Operations billing drawer.
                        </p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center text-xs">
                            <div class="p-4 rounded-lg bg-rose-500/10 border border-rose-500/30">
                                <div class="font-bold text-rose-400 text-sm">Table 01</div>
                                <div class="text-zinc-300 mt-1">4 Guests &middot; Running</div>
                                <div class="font-mono text-zinc-400 font-bold mt-1">NPR 1,850</div>
                            </div>
                            <div class="p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30">
                                <div class="font-bold text-emerald-400 text-sm">Table 02</div>
                                <div class="text-zinc-400 mt-1">Vacant</div>
                                <div class="text-zinc-500 mt-1">Ready for guests</div>
                            </div>
                            <div class="p-4 rounded-lg bg-amber-500/20 border-2 border-amber-500">
                                <div class="font-bold text-amber-400 text-sm">Table 04</div>
                                <div class="text-amber-200 font-bold mt-1">Waiting Bill</div>
                                <div class="font-mono text-amber-400 font-bold mt-1">NPR 1,441.60</div>
                            </div>
                            <div class="p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30">
                                <div class="font-bold text-emerald-400 text-sm">Table 05</div>
                                <div class="text-zinc-400 mt-1">Vacant</div>
                                <div class="text-zinc-500 mt-1">Ready for guests</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Panel 2: Billing & Payments -->
                <div id="tab-billing" class="tab-panel hidden">
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-4">
                        <div class="flex items-center justify-between border-b border-[var(--border)] pb-3">
                            <h3 class="font-extrabold text-white text-lg">Table Operations Drawer & Settlement</h3>
                            <span class="text-xs text-emerald-400 font-bold bg-emerald-500/10 px-3 py-1 rounded border border-emerald-500/20">PAYMENT & TAX LOGIC</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
                            <div class="space-y-2">
                                <div class="font-bold text-white text-sm">Order Summary</div>
                                <div class="p-3 rounded bg-[var(--surface)] border border-[var(--border)] space-y-1.5">
                                    <div class="flex justify-between text-zinc-300"><span>2× Royal Chicken Biryani</span><span>NPR 900.00</span></div>
                                    <div class="flex justify-between text-zinc-300"><span>2× Cold Coffee</span><span>NPR 300.00</span></div>
                                    <div class="flex justify-between text-zinc-400 border-t border-[var(--border)] pt-1.5"><span>Subtotal</span><span>NPR 1,200.00</span></div>
                                    <div class="flex justify-between text-zinc-400"><span>Service Charge (10%)</span><span>NPR 120.00</span></div>
                                    <div class="flex justify-between text-zinc-400"><span>VAT (13%)</span><span>NPR 171.60</span></div>
                                    <div class="flex justify-between text-emerald-400 font-semibold"><span>Loyalty Discount</span><span>−NPR 50.00</span></div>
                                    <div class="flex justify-between font-extrabold text-white text-sm pt-1.5 border-t border-[var(--border)]"><span>Amount Due</span><span class="text-amber-400">NPR 1,441.60</span></div>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div class="font-bold text-white text-sm">Payment Methods</div>
                                <div class="grid grid-cols-3 gap-2">
                                    <div class="p-2.5 rounded bg-amber-500 text-[var(--bg)] font-bold text-center">💵 Cash</div>
                                    <div class="p-2.5 rounded bg-[var(--surface)] border border-[var(--border)] text-zinc-300 font-semibold text-center">💳 Card</div>
                                    <div class="p-2.5 rounded bg-[var(--surface)] border border-[var(--border)] text-zinc-300 font-semibold text-center">📱 Digital QR</div>
                                </div>
                                <div class="p-3 rounded bg-[var(--surface)] border border-[var(--border)] space-y-1">
                                    <div class="flex justify-between text-zinc-400"><span>Cash Received:</span><span class="text-white font-bold">NPR 2,000.00</span></div>
                                    <div class="flex justify-between text-zinc-400"><span>Change Due:</span><span class="text-emerald-400 font-bold">NPR 558.40</span></div>
                                </div>
                                <button type="button" class="w-full py-2.5 bg-amber-500 text-[var(--bg)] font-extrabold rounded text-xs">MARK AS PAID & PRINT RECEIPT</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Panel 3: KDS -->
                <div id="tab-kds" class="tab-panel hidden">
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-4">
                        <div class="flex items-center justify-between border-b border-[var(--border)] pb-3">
                            <h3 class="font-extrabold text-white text-lg">Kitchen Display System (<code class="text-amber-400 font-mono text-sm">kitchen-dashboard.php</code>)</h3>
                            <span class="text-xs text-amber-400 font-bold bg-amber-500/10 px-3 py-1 rounded border border-amber-500/20">REAL-TIME KITCHEN TICKETS</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div class="p-4 rounded-lg bg-[var(--surface)] border-l-4 border-l-amber-500 space-y-2">
                                <div class="flex justify-between font-bold"><span class="text-amber-400">Ticket #1085 &middot; Table 04</span><span class="text-zinc-400 font-mono">08m 14s</span></div>
                                <div class="text-white space-y-0.5"><div>2× Chicken Biryani</div><div>2× Cold Coffee</div></div>
                                <button type="button" class="w-full py-1.5 bg-amber-500 text-[var(--bg)] font-bold rounded">MARK PREPARING</button>
                            </div>
                            <div class="p-4 rounded-lg bg-[var(--surface)] border-l-4 border-l-emerald-500 space-y-2">
                                <div class="flex justify-between font-bold"><span class="text-emerald-400">Ticket #1084 &middot; Table 09</span><span class="text-zinc-400 font-mono">14m 02s</span></div>
                                <div class="text-white space-y-0.5"><div>1× Paneer Masala</div><div>4× Butter Naan</div></div>
                                <button type="button" class="w-full py-1.5 bg-emerald-500 text-[var(--bg)] font-bold rounded">MARK READY</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Panel 4: Inventory -->
                <div id="tab-inventory" class="tab-panel hidden">
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-4">
                        <div class="flex items-center justify-between border-b border-[var(--border)] pb-3">
                            <h3 class="font-extrabold text-white text-lg">Recipe Stock & Inventory Control</h3>
                            <span class="text-xs text-emerald-400 font-bold bg-emerald-500/10 px-3 py-1 rounded border border-emerald-500/20">AUTO-DEDUCTION ON SALE</span>
                        </div>
                        <div class="space-y-2 text-xs">
                            <div class="flex justify-between p-3 rounded bg-[var(--surface)] text-zinc-300"><span>Basmati Rice</span><span class="text-emerald-400 font-bold">45 kg Available</span></div>
                            <div class="flex justify-between p-3 rounded bg-[var(--surface)] text-zinc-300"><span>Fresh Chicken</span><span class="text-amber-400 font-bold">8 kg Available (Low Stock Alert)</span></div>
                            <div class="flex justify-between p-3 rounded bg-[var(--surface)] text-zinc-300"><span>Cooking Oil</span><span class="text-rose-400 font-bold">2 L Available (Reorder Threshold Reached)</span></div>
                        </div>
                    </div>
                </div>

                <!-- Panel 5: Loyalty -->
                <div id="tab-loyalty" class="tab-panel hidden">
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-4">
                        <div class="flex items-center justify-between border-b border-[var(--border)] pb-3">
                            <h3 class="font-extrabold text-white text-lg">Customer CRM & Loyalty Settings (<code class="text-amber-400 font-mono text-sm">admin/settings.php</code>)</h3>
                            <span class="text-xs text-amber-400 font-bold bg-amber-500/10 px-3 py-1 rounded border border-amber-500/20">TENANT SPECIFIC RULES</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                            <div class="p-4 rounded bg-[var(--surface)] border border-[var(--border)] space-y-2">
                                <div class="font-bold text-white">Loyalty Program Configuration</div>
                                <div class="text-zinc-400">Earn Rate: NPR 100 spent = 1 Loyalty Point</div>
                                <div class="text-zinc-400">Redemption Rate: 2 Points = NPR 1.00 Discount</div>
                                <div class="text-zinc-400">Min Points to Redeem: 100 Points</div>
                            </div>
                            <div class="p-4 rounded bg-[var(--surface)] border border-[var(--border)] space-y-2">
                                <div class="font-bold text-white">Customer Profile Lookup</div>
                                <div class="text-zinc-300">Customer: Ramesh Sharma (9841XXXXXX)</div>
                                <div class="text-amber-400 font-bold">Available Balance: 184 Points</div>
                                <div class="text-emerald-400">Redeemable Today: NPR 50.00 Off</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Panel 6: Analytics -->
                <div id="tab-analytics" class="tab-panel hidden">
                    <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-4">
                        <div class="flex items-center justify-between border-b border-[var(--border)] pb-3">
                            <h3 class="font-extrabold text-white text-lg">Revenue & Sales Analytics</h3>
                            <span class="text-xs text-emerald-400 font-bold bg-emerald-500/10 px-3 py-1 rounded border border-emerald-500/20">REAL-TIME DASHBOARD</span>
                        </div>
                        <div class="grid grid-cols-3 gap-3 text-center text-xs">
                            <div class="p-3.5 rounded bg-[var(--surface)]"><div class="text-zinc-400">Daily Revenue</div><div class="text-white font-extrabold text-base mt-1">NPR 48,250</div></div>
                            <div class="p-3.5 rounded bg-[var(--surface)]"><div class="text-zinc-400">Weekly Total</div><div class="text-white font-extrabold text-base mt-1">NPR 3,24,800</div></div>
                            <div class="p-3.5 rounded bg-[var(--surface)]"><div class="text-zinc-400">Top Selling Item</div><div class="text-amber-400 font-extrabold text-base mt-1">Chicken Biryani</div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ======== WHY RMS SECTION ======== -->
    <section class="py-24 md:py-32 border-t border-[var(--border)]">
        <div class="max-w-8xl mx-auto px-5 sm:px-8 reveal">
            <div class="max-w-[720px] mx-auto text-center mb-16">
                <p class="text-[12px] font-bold uppercase tracking-[.2em] text-amber-500 mb-4">Positioning & Architecture</p>
                <h2 class="text-[clamp(1.75rem,4vw,2.75rem)] font-extrabold text-white tracking-tight leading-tight">
                    One Platform. One Source of Truth.
                </h2>
                <p class="mt-4 text-[16px] text-[var(--text-muted)]">
                    Compare traditional disconnected restaurant setups with RMS unified multi-tenant SaaS.
                </p>
            </div>

            <!-- Comparison Table -->
            <div class="max-w-[960px] mx-auto rounded-xl border border-[var(--border)] bg-[var(--surface)] overflow-hidden">
                <div class="grid grid-cols-12 bg-[var(--surface-2)] p-4 text-xs font-extrabold tracking-wider text-zinc-400 border-b border-[var(--border)]">
                    <div class="col-span-4 uppercase">Capability</div>
                    <div class="col-span-4 uppercase text-rose-400">Disconnected Legacy Tools</div>
                    <div class="col-span-4 uppercase text-emerald-400">RMS Unified SaaS</div>
                </div>

                <div class="divide-y divide-[var(--border)] text-xs sm:text-sm">
                    <div class="grid grid-cols-12 p-4 items-center">
                        <div class="col-span-4 font-bold text-white">Table & Kitchen Sync</div>
                        <div class="col-span-4 text-zinc-400">Manual paper KOTs carried to kitchen</div>
                        <div class="col-span-4 font-semibold text-emerald-400">Instant digital KDS display & timers</div>
                    </div>
                    <div class="grid grid-cols-12 p-4 items-center">
                        <div class="col-span-4 font-bold text-white">Tax & Bill Calculation</div>
                        <div class="col-span-4 text-zinc-400">Manual math prone to rounding errors</div>
                        <div class="col-span-4 font-semibold text-emerald-400">Server-side DECIMAL exact precision</div>
                    </div>
                    <div class="grid grid-cols-12 p-4 items-center">
                        <div class="col-span-4 font-bold text-white">Inventory Stock</div>
                        <div class="col-span-4 text-zinc-400">Manual daily count or un-tracked</div>
                        <div class="col-span-4 font-semibold text-emerald-400">Automatic recipe deduction per sale</div>
                    </div>
                    <div class="grid grid-cols-12 p-4 items-center">
                        <div class="col-span-4 font-bold text-white">Customer Loyalty</div>
                        <div class="col-span-4 text-zinc-400">Paper stamp cards or separate app</div>
                        <div class="col-span-4 font-semibold text-emerald-400">Integrated phone lookup & checkout discount</div>
                    </div>
                    <div class="grid grid-cols-12 p-4 items-center">
                        <div class="col-span-4 font-bold text-white">Security & Roles</div>
                        <div class="col-span-4 text-zinc-400">Shared login passwords</div>
                        <div class="col-span-4 font-semibold text-emerald-400">Strict RBAC for 6 staff roles + audit logs</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ======== SECURITY & ENTERPRISE OPERATIONS ======== -->
    <section class="py-24 md:py-32 border-t border-[var(--border)] bg-[var(--surface)]">
        <div class="max-w-8xl mx-auto px-5 sm:px-8 reveal">
            <div class="max-w-[720px] mx-auto text-center mb-16">
                <p class="text-[12px] font-bold uppercase tracking-[.2em] text-amber-500 mb-4">Enterprise Reliability</p>
                <h2 class="text-[clamp(1.75rem,4vw,2.75rem)] font-extrabold text-white tracking-tight leading-tight">
                    Security & Multi-Tenant Isolation
                </h2>
                <p class="mt-4 text-[16px] text-[var(--text-muted)]">
                    Built with strict software architecture principles to ensure data privacy and continuous uptime.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 max-w-[1080px] mx-auto">
                <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center"><?= svg_icon('shield', 'w-5 h-5') ?></div>
                    <h3 class="font-bold text-white text-base">Tenant Data Isolation</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">
                        Every query is scoped by tenant context, guaranteeing logical data separation between restaurant accounts.
                    </p>
                </div>
                <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center"><?= svg_icon('users', 'w-5 h-5') ?></div>
                    <h3 class="font-bold text-white text-base">Role-Based Access (RBAC)</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">
                        Granular roles for Owner, Manager, Cashier, Chef, Waiter, and Inventory staff accounts.
                    </p>
                </div>
                <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center"><?= svg_icon('activity', 'w-5 h-5') ?></div>
                    <h3 class="font-bold text-white text-base">Audit Trail Logging</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">
                        All financial actions, voids, payment settlements, and logins logged with IP addresses and timestamps.
                    </p>
                </div>
                <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center"><?= svg_icon('lock', 'w-5 h-5') ?></div>
                    <h3 class="font-bold text-white text-base">Secure Authentication</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">
                        Password hashing, session validation, CSRF verification tokens, and IP rate-limiting.
                    </p>
                </div>
                <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center"><?= svg_icon('card', 'w-5 h-5') ?></div>
                    <h3 class="font-bold text-white text-base">Exact Decimal Math</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">
                        Database operations use exact DECIMAL numbers preventing floating-point rounding errors on bills.
                    </p>
                </div>
                <div class="p-6 rounded-xl bg-[var(--bg)] border border-[var(--border)] space-y-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center"><?= svg_icon('box', 'w-5 h-5') ?></div>
                    <h3 class="font-bold text-white text-base">Data Protection</h3>
                    <p class="text-xs text-zinc-400 leading-relaxed">
                        Parameterized SQL queries protecting against SQL injection across all customer-facing and admin APIs.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ======== PRICING SECTION ======== -->
    <section id="pricing" class="py-24 md:py-32 border-t border-[var(--border)]">
        <div class="max-w-8xl mx-auto px-5 sm:px-8 reveal">
            <div class="max-w-[580px] mx-auto text-center mb-14">
                <p class="text-[12px] font-bold uppercase tracking-[.2em] text-amber-500 mb-4">Transparent Subscriptions</p>
                <h2 class="text-[clamp(1.75rem,4vw,2.75rem)] font-extrabold text-white tracking-tight leading-tight">
                    Simple NPR Subscription Plans
                </h2>
                <p class="mt-4 text-[16px] text-[var(--text-muted)]">Choose the plan that matches your restaurant size and operations.</p>

                <!-- Billing Toggle -->
                <div class="mt-6 flex items-center justify-center gap-3">
                    <span class="text-[13px] font-semibold text-white" id="billing-monthly-label">Monthly</span>
                    <label class="billing-toggle relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer" id="billing-toggle-input">
                        <div class="toggle-track"><div class="toggle-thumb"></div></div>
                    </label>
                    <span class="text-[13px] font-semibold text-zinc-400" id="billing-yearly-label">Yearly <span class="text-amber-500 font-bold">Save ~20%</span></span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5 items-stretch max-w-[1200px] mx-auto">
                <?php foreach ($pricingPlans as $p): ?>
                    <div class="<?= $p['popular'] ? 'border-2 border-amber-500 bg-[var(--surface-2)] ring-1 ring-amber-500/20 scale-[1.02]' : 'border border-[var(--border)] bg-[var(--surface)]' ?> rounded-xl p-6 flex flex-col justify-between relative">

                        <?php if ($p['popular']): ?>
                            <div class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full bg-amber-500 text-[var(--bg)] text-[11px] font-black uppercase tracking-wider">Recommended</div>
                        <?php endif; ?>

                        <div class="space-y-4">
                            <div>
                                <div class="text-[12px] font-bold uppercase tracking-wider text-amber-500"><?= $p['name'] ?></div>
                                <div class="text-[28px] font-extrabold text-white mt-1 pricing-amount" data-monthly="<?= $p['price'] ?>" data-yearly="<?= $p['code'] !== 'ENTERPRISE' ? 'NPR ' . number_format((int)str_replace(['NPR ', ','], '', $p['price']) * 10, 0, '.', ',') : 'Custom Pricing' ?>"><?= $p['price'] ?></div>
                                <div class="text-[13px] text-zinc-500 mt-0.5 pricing-suffix" data-monthly="<?= $p['suffix'] ?: 'Custom contract' ?>" data-yearly="<?= $p['code'] !== 'ENTERPRISE' ? '/ year' : 'Custom contract' ?>"><?= $p['suffix'] ?: 'Custom contract' ?></div>
                            </div>
                            <p class="text-[13px] text-zinc-400 leading-relaxed"><?= $p['tagline'] ?></p>

                            <div class="pt-3 border-t border-[var(--border)] space-y-2 text-[13px] text-zinc-300">
                                <?php foreach ($p['features'] as $f): ?>
                                    <div class="flex items-start gap-2">
                                        <span class="text-emerald-400 mt-0.5 shrink-0"><?= svg_icon('check', 'w-3.5 h-3.5 stroke-[2.5]') ?></span>
                                        <span><?= $f ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mt-6 space-y-2">
                            <a href="#request-demo" data-plan-code="<?= $p['code'] ?>" class="block w-full text-center py-3 rounded-lg text-[13px] font-extrabold transition-colors <?= $p['popular'] ? 'bg-amber-500 text-[var(--bg)] hover:bg-amber-400' : 'bg-[var(--bg)] text-white border border-[var(--border)] hover:border-zinc-500' ?>">
                                <?= $p['cta'] ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ======== FAQ ======== -->
    <section id="faq" class="py-24 md:py-32 border-t border-[var(--border)] bg-[var(--surface)]">
        <div class="max-w-[780px] mx-auto px-5 sm:px-8 reveal">
            <div class="text-center mb-14">
                <p class="text-[12px] font-bold uppercase tracking-[.2em] text-amber-500 mb-4">Got Questions?</p>
                <h2 class="text-[clamp(1.75rem,4vw,2.75rem)] font-extrabold text-white tracking-tight">Frequently Asked Questions</h2>
            </div>

            <div class="space-y-2.5">
                <?php
                $faqs = [
                    ['What is RMS SaaS?', 'RMS SaaS is an all-in-one multi-tenant restaurant management platform built for Nepal restaurants. It unifies table billing, digital QR code ordering, Kitchen Display System (KDS), floor map management, recipe inventory stock, customer loyalty, and real-time revenue analytics in one unified workspace.'],
                    ['What type of restaurant can use RMS?', 'RMS is designed for cafés, casual dining, fine dining, fast food/QSR, bars & lounges, and multi-location restaurant groups.'],
                    ['Does RMS include POS and billing?', 'Yes. Cashiers and waiters can open tables, manage order items, calculate service charges (10%) and VAT (13%), split bills, apply loyalty discounts, and settle via Cash, Card, or Digital QR with instant receipt printing.'],
                    ['Does RMS support kitchen operations?', 'Yes. The real-time Kitchen Display System (KDS) displays incoming table and QR orders on kitchen screens with prep timers, status workflow buttons (Preparing/Ready), and audio alerts.'],
                    ['Does RMS support customer loyalty?', 'Yes. Restaurant owners can configure loyalty earning and redemption rules. Customer profiles track visit counts, total spend, and redeemable points balance.'],
                    ['Can multiple restaurants use RMS?', 'Yes. RMS runs on a multi-tenant architecture where each restaurant operates in its own isolated database workspace.'],
                    ['How does restaurant setup work?', 'After requesting a demo, our Super Admin team reviews your details, provisions your isolated tenant workspace, and sends your Owner login credentials.'],
                    ['What happens after requesting a demo?', 'You will receive a request confirmation code immediately. Our team will review your application and contact you within 24 hours to schedule setup and staff guidance.'],
                ];
                foreach ($faqs as $i => $faq): ?>
                    <div class="rounded-lg border border-[var(--border)] bg-[var(--bg)] overflow-hidden">
                        <h3>
                            <button type="button" class="faq-btn w-full flex items-center justify-between gap-4 p-4 sm:p-5 text-left" aria-expanded="false" aria-controls="faq-<?= $i + 1 ?>" id="faq-btn-<?= $i + 1 ?>">
                                <span class="text-[14px] sm:text-[15px] font-bold text-white"><?= $faq[0] ?></span>
                                <span class="faq-chevron shrink-0 text-amber-400"><?= svg_icon('chevron-down', 'w-4 h-4') ?></span>
                            </button>
                        </h3>
                        <div id="faq-<?= $i + 1 ?>" class="faq-panel hidden px-4 sm:px-5 pb-4 sm:pb-5" role="region" aria-labelledby="faq-btn-<?= $i + 1 ?>">
                            <p class="text-[14px] text-zinc-400 leading-relaxed border-t border-[var(--border)] pt-3"><?= $faq[1] ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ======== DEMO FORM ======== -->
    <section id="request-demo" class="py-24 md:py-32 border-t border-[var(--border)]">
        <div class="max-w-[760px] mx-auto px-5 sm:px-8 reveal">
            <div class="text-center mb-10">
                <h2 class="text-[clamp(1.75rem,4vw,2.5rem)] font-extrabold text-white tracking-tight">
                    Request a Restaurant Demo
                </h2>
                <p class="mt-3 text-[16px] text-[var(--text-muted)]">Provide your restaurant details to set up your isolated RMS workspace.</p>
            </div>

            <?php if ($requestSuccess): ?>
                <div class="p-8 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-center space-y-4">
                    <div class="w-12 h-12 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center mx-auto">
                        <?= svg_icon('check', 'w-6 h-6 stroke-[3]') ?>
                    </div>
                    <h3 class="text-xl font-extrabold text-white">Demo Request Submitted</h3>
                    <p class="text-[14px] text-zinc-300 max-w-md mx-auto leading-relaxed">
                        Thank you! Your tracking code is <span class="font-mono text-amber-400 font-bold"><?= htmlspecialchars($lastRequestCode) ?></span>. Our team will contact you shortly.
                    </p>
                    <a href="index.php" class="inline-block mt-4 px-6 py-2.5 rounded-lg border border-[var(--border)] text-[13px] font-semibold text-white hover:border-zinc-500">Return Home</a>
                </div>
            <?php else: ?>

                <?php if ($requestError): ?>
                    <div class="mb-6 p-4 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-400 text-[13px] font-medium flex items-center gap-2">
                        <?= svg_icon('x', 'w-4 h-4 shrink-0') ?>
                        <span><?= htmlspecialchars($requestError) ?></span>
                    </div>
                <?php endif; ?>

                <form id="restaurantRequestForm" method="POST" class="rounded-xl border border-[var(--border)] bg-[var(--surface)] p-6 sm:p-8 space-y-5" novalidate>
                    <?= $csrfField ?>
                    <input type="hidden" name="action" value="submit_restaurant_request">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="restaurant_name" class="block text-[13px] font-semibold text-zinc-300 mb-1.5">Restaurant Name <span class="text-amber-500">*</span></label>
                            <input type="text" id="restaurant_name" name="restaurant_name" required placeholder="e.g. Himalayan Kitchen" class="w-full h-11 bg-[var(--bg)] border border-[var(--border)] rounded-lg px-3.5 text-white text-[14px] placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="owner_name" class="block text-[13px] font-semibold text-zinc-300 mb-1.5">Owner Full Name <span class="text-amber-500">*</span></label>
                            <input type="text" id="owner_name" name="owner_name" required placeholder="e.g. Ramesh Sharma" class="w-full h-11 bg-[var(--bg)] border border-[var(--border)] rounded-lg px-3.5 text-white text-[14px] placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="email" class="block text-[13px] font-semibold text-zinc-300 mb-1.5">Email Address <span class="text-amber-500">*</span></label>
                            <input type="email" id="email" name="email" required placeholder="owner@restaurant.com" class="w-full h-11 bg-[var(--bg)] border border-[var(--border)] rounded-lg px-3.5 text-white text-[14px] placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="phone" class="block text-[13px] font-semibold text-zinc-300 mb-1.5">Contact Phone <span class="text-amber-500">*</span></label>
                            <input type="tel" id="phone" name="phone" required placeholder="98XXXXXXXX" class="w-full h-11 bg-[var(--bg)] border border-[var(--border)] rounded-lg px-3.5 text-white text-[14px] placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="restaurant_type" class="block text-[13px] font-semibold text-zinc-300 mb-1.5">Restaurant Type</label>
                            <select id="restaurant_type" name="restaurant_type" class="w-full h-11 bg-[var(--bg)] border border-[var(--border)] rounded-lg px-3.5 text-white text-[14px] outline-none focus:border-amber-500 transition-colors">
                                <option value="Casual Dining" selected>Casual Dining</option>
                                <option value="Fine Dining">Fine Dining</option>
                                <option value="Fast Food / QSR">Fast Food / QSR</option>
                                <option value="Cafe & Bakery">Cafe & Bakery</option>
                                <option value="Bar & Lounge">Bar & Lounge</option>
                            </select>
                        </div>
                        <div>
                            <label for="table_count" class="block text-[13px] font-semibold text-zinc-300 mb-1.5">Number of Tables <span class="text-amber-500">*</span></label>
                            <input type="number" id="table_count" name="table_count" min="1" max="1000" value="10" required class="w-full h-11 bg-[var(--bg)] border border-[var(--border)] rounded-lg px-3.5 text-white text-[14px] outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="current_system" class="block text-[13px] font-semibold text-zinc-300 mb-1.5">Current POS / System</label>
                            <input type="text" id="current_system" name="current_system" placeholder="e.g. Paper bills, legacy software" class="w-full h-11 bg-[var(--bg)] border border-[var(--border)] rounded-lg px-3.5 text-white text-[14px] placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors">
                        </div>
                        <div>
                            <label for="preferred_plan" class="block text-[13px] font-semibold text-zinc-300 mb-1.5">Preferred Plan</label>
                            <select id="preferred_plan" name="preferred_plan" class="w-full h-11 bg-[var(--bg)] border border-[var(--border)] rounded-lg px-3.5 text-white text-[14px] outline-none focus:border-amber-500 transition-colors">
                                <option value="ESSENTIAL">Essential — NPR 1,500/month</option>
                                <option value="BUSINESS" selected>Business — NPR 2,500/month</option>
                                <option value="BUSINESS_PRO">Business Pro — NPR 4,500/month</option>
                                <option value="ENTERPRISE">Enterprise — Custom Pricing</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="message" class="block text-[13px] font-semibold text-zinc-300 mb-1.5">Additional Requirements</label>
                            <textarea id="message" name="message" rows="3" placeholder="Tell us about your restaurant setup..." class="w-full bg-[var(--bg)] border border-[var(--border)] rounded-lg p-3.5 text-white text-[14px] placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors"></textarea>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row items-center gap-4 pt-2">
                        <button type="submit" class="btn-submit w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-lg bg-amber-500 text-[var(--bg)] font-extrabold text-[15px] hover:bg-amber-400 transition-colors">
                            <span class="btn-label">Request a Restaurant Demo</span>
                            <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.5]') ?>
                        </button>
                        <span class="text-[13px] text-zinc-500">No payment required for setup.</span>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- ======== FINAL CTA ======== -->
    <section class="py-24 md:py-32 border-t border-[var(--border)] bg-[var(--surface)]">
        <div class="max-w-[720px] mx-auto px-5 text-center reveal">
            <h2 class="text-[clamp(1.75rem,4vw,2.75rem)] font-extrabold text-white tracking-tight leading-tight">
                Ready to Run Your Restaurant Smarter?
            </h2>
            <p class="mt-4 text-[16px] text-[var(--text-muted)] leading-relaxed">
                Connect your orders, kitchen, tables, billing, customers and operations in one platform.
            </p>
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3.5">
                <a href="#request-demo" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-lg bg-amber-500 text-[var(--bg)] font-extrabold text-[15px] hover:bg-amber-400 transition-colors shadow-lg">
                    Request Your Restaurant Demo <?= svg_icon('arrow', 'w-4 h-4 stroke-[2.5]') ?>
                </a>
                <a href="#product" class="w-full sm:w-auto inline-flex items-center justify-center px-7 py-3.5 rounded-lg border border-[var(--border)] text-white font-semibold text-[15px] hover:border-zinc-500 transition-colors">
                    Explore RMS
                </a>
            </div>
        </div>
    </section>

    <!-- ======== FOOTER ======== -->
    <footer class="border-t border-[var(--border)] bg-[var(--bg)]">
        <div class="max-w-8xl mx-auto px-5 sm:px-8 py-16">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-8">
                <!-- Brand -->
                <div class="col-span-2 md:col-span-1 space-y-3">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-amber-500 flex items-center justify-center text-[var(--bg)]">
                            <?= svg_icon('bolt', 'w-4 h-4 stroke-[2.5]') ?>
                        </div>
                        <span class="text-[16px] font-extrabold text-white">RMS SaaS</span>
                    </div>
                    <p class="text-[13px] text-zinc-500 leading-relaxed">Multi-restaurant operations platform built for modern venues.</p>
                </div>

                <!-- Product -->
                <div>
                    <h4 class="text-[12px] font-bold uppercase tracking-wider text-zinc-400 mb-4">Product</h4>
                    <ul class="space-y-2.5 text-[13px]">
                        <li><a href="#product" class="text-zinc-500 hover:text-white transition-colors">Table Operations</a></li>
                        <li><a href="#product" class="text-zinc-500 hover:text-white transition-colors">Kitchen KDS</a></li>
                        <li><a href="#product" class="text-zinc-500 hover:text-white transition-colors">Inventory Control</a></li>
                        <li><a href="#product" class="text-zinc-500 hover:text-white transition-colors">Customer CRM</a></li>
                    </ul>
                </div>

                <!-- Company -->
                <div>
                    <h4 class="text-[12px] font-bold uppercase tracking-wider text-zinc-400 mb-4">Company</h4>
                    <ul class="space-y-2.5 text-[13px]">
                        <li><a href="#pricing" class="text-zinc-500 hover:text-white transition-colors">Pricing</a></li>
                        <li><a href="#request-demo" class="text-zinc-500 hover:text-white transition-colors">Contact</a></li>
                        <li><a href="admin/login.php" class="text-zinc-500 hover:text-white transition-colors">Restaurant Login</a></li>
                    </ul>
                </div>

                <!-- Resources -->
                <div>
                    <h4 class="text-[12px] font-bold uppercase tracking-wider text-zinc-400 mb-4">Resources</h4>
                    <ul class="space-y-2.5 text-[13px]">
                        <li><a href="#faq" class="text-zinc-500 hover:text-white transition-colors">FAQ</a></li>
                        <li><a href="#workflow" class="text-zinc-500 hover:text-white transition-colors">How It Works</a></li>
                        <li><a href="#pillars" class="text-zinc-500 hover:text-white transition-colors">Features</a></li>
                    </ul>
                </div>

                <!-- Legal -->
                <div>
                    <h4 class="text-[12px] font-bold uppercase tracking-wider text-zinc-400 mb-4">Legal & Portal</h4>
                    <ul class="space-y-2.5 text-[13px]">
                        <li><a href="privacy-policy.php" class="text-zinc-500 hover:text-white transition-colors">Privacy Policy</a></li>
                        <li><a href="terms-of-service.php" class="text-zinc-500 hover:text-white transition-colors">Terms of Service</a></li>
                        <li><a href="super-admin/login.php" class="text-amber-400 hover:text-white transition-colors font-bold">Super Admin</a></li>
                    </ul>
                </div>
            </div>

            <div class="mt-12 pt-6 border-t border-[var(--border)] text-[12px] text-zinc-600 text-center">
                © <?= date('Y') ?> RMS SaaS Platform. All rights reserved.
            </div>
        </div>
    </footer>

    <!-- ======== INTERACTIVITY SCRIPTS ======== -->
    <script>
    (function () {
        'use strict';

        /* Mobile menu */
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

        /* Navbar scroll state */
        var nav = document.getElementById('main-nav');
        if (nav) {
            var scrolled = false;
            window.addEventListener('scroll', function () {
                var isScrolled = window.scrollY > 20;
                if (isScrolled !== scrolled) {
                    scrolled = isScrolled;
                    nav.classList.toggle('nav-scrolled', isScrolled);
                }
            }, { passive: true });
        }

        /* Tab Switcher */
        var tabBtns = document.querySelectorAll('.tab-btn');
        var tabPanels = document.querySelectorAll('.tab-panel');
        tabBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-tab');
                tabBtns.forEach(function (b) {
                    b.classList.remove('border-amber-500/50', 'bg-amber-500/10', 'text-amber-400');
                    b.classList.add('border-[var(--border)]', 'text-zinc-400');
                });
                btn.classList.remove('border-[var(--border)]', 'text-zinc-400');
                btn.classList.add('border-amber-500/50', 'bg-amber-500/10', 'text-amber-400');

                tabPanels.forEach(function (panel) {
                    panel.classList.toggle('hidden', panel.id !== 'tab-' + target);
                });
            });
        });

        /* FAQ Accordion */
        document.querySelectorAll('.faq-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var panel = document.getElementById(btn.getAttribute('aria-controls'));
                var isOpen = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                if (panel) panel.classList.toggle('hidden', isOpen);
            });
        });

        /* Pricing Plan Sync with Demo Form */
        var planSelect = document.querySelector('select[name="preferred_plan"]');
        document.querySelectorAll('[data-plan-code]').forEach(function (el) {
            el.addEventListener('click', function () {
                if (planSelect) planSelect.value = el.getAttribute('data-plan-code');
            });
        });

        /* Pricing Monthly/Yearly Toggle */
        var billingToggle = document.getElementById('billing-toggle-input');
        if (billingToggle) {
            billingToggle.addEventListener('change', function () {
                var isYearly = this.checked;
                document.getElementById('billing-monthly-label').classList.toggle('text-zinc-400', isYearly);
                document.getElementById('billing-monthly-label').classList.toggle('text-white', !isYearly);
                document.getElementById('billing-yearly-label').classList.toggle('text-white', isYearly);
                document.getElementById('billing-yearly-label').classList.toggle('text-zinc-400', !isYearly);

                document.querySelectorAll('.pricing-amount').forEach(function (el) {
                    el.textContent = isYearly ? el.dataset.yearly : el.dataset.monthly;
                });
                document.querySelectorAll('.pricing-suffix').forEach(function (el) {
                    el.textContent = isYearly ? el.dataset.yearly : el.dataset.monthly;
                });
            });
        }

        /* Form double-submit protection */
        var form = document.getElementById('restaurantRequestForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                var btn = form.querySelector('.btn-submit');
                var label = form.querySelector('.btn-label');
                if (btn.disabled) { e.preventDefault(); return; }
                if (!form.checkValidity()) { form.reportValidity(); e.preventDefault(); return; }
                btn.disabled = true;
                if (label) label.textContent = 'Submitting Request…';
            });
        }

        /* Scroll reveal observer */
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

            document.querySelectorAll('.reveal').forEach(function (el) { observer.observe(el); });
        } else {
            document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('visible'); });
        }
    })();
    </script>
</body>
</html>
