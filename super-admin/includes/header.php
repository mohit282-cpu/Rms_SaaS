<?php
// super-admin/includes/header.php - Super Admin Dashboard Header & Navigation Bar
require_once __DIR__ . '/../../config.php';
Auth::requireSuperAdmin();

$conn = getDBConnection();
$actionableRequests = 0;
if ($conn) {
    $nRes = $conn->query("SELECT COUNT(*) as cnt FROM restaurant_requests WHERE status IN ('PENDING', 'CONTACTED')");
    if ($nRes && $row = $nRes->fetch_assoc()) {
        $actionableRequests = (int)$row['cnt'];
    }
}
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($pageTitle ?? 'Super Admin Dashboard') ?> - RMS SaaS Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="h-full flex flex-col bg-zinc-950 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">
    <!-- Top Navigation Bar -->
    <header class="border-b border-zinc-800/80 bg-zinc-900/90 backdrop-blur sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-6">
                <a href="index.php" class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-amber-400 flex items-center justify-center text-zinc-950 font-black text-xl shadow-lg shadow-amber-500/20">
                        ⚡
                    </div>
                    <div>
                        <span class="text-base font-black tracking-tight text-white block">RMS SaaS</span>
                        <span class="text-[10px] text-amber-500 font-bold uppercase tracking-wider block">Super Admin Portal</span>
                    </div>
                </a>

                <nav class="hidden md:flex items-center space-x-1 pl-6 border-l border-zinc-800">
                    <a href="index.php" class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $currentPage === 'index.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'text-zinc-400 hover:text-white hover:bg-zinc-800/50' ?>">
                        📊 Overview
                    </a>
                    <a href="restaurants.php" class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $currentPage === 'restaurants.php' || $currentPage === 'create-restaurant.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'text-zinc-400 hover:text-white hover:bg-zinc-800/50' ?>">
                        🏪 Restaurants
                    </a>
                    <a href="requests.php" class="px-3.5 py-2 rounded-xl text-xs font-bold relative transition-all <?= $currentPage === 'requests.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'text-zinc-400 hover:text-white hover:bg-zinc-800/50' ?>">
                        📬 Requests
                        <?php if ($actionableRequests > 0): ?>
                            <span class="ml-1.5 inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-black leading-none text-zinc-950 bg-amber-400 rounded-full animate-pulse">
                                <?= $actionableRequests ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <a href="subscriptions.php" class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $currentPage === 'subscriptions.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'text-zinc-400 hover:text-white hover:bg-zinc-800/50' ?>">
                        💳 Subscriptions
                    </a>
                </nav>
            </div>

            <div class="flex items-center space-x-3">
                <a href="../index.php" target="_blank" class="hidden sm:inline-flex items-center px-3 py-1.5 rounded-xl border border-zinc-800 bg-zinc-900 text-xs font-semibold text-zinc-300 hover:bg-zinc-800 transition-colors">
                    🌐 Public Site ↗
                </a>
                <div class="flex items-center space-x-3 pl-3 border-l border-zinc-800">
                    <div class="text-right hidden sm:block">
                        <span class="text-xs font-bold text-white block"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Super Admin') ?></span>
                        <span class="text-[10px] text-zinc-500 font-semibold block">Platform Administrator</span>
                    </div>
                    <a href="logout.php" class="p-2 rounded-xl border border-rose-500/20 bg-rose-500/10 text-rose-400 hover:bg-rose-500/20 text-xs font-bold transition-all">
                        Logout 🚪
                    </a>
                </div>

                <!-- Mobile Navigation Toggle -->
                <button type="button" onclick="document.getElementById('sa-mobile-nav').classList.toggle('hidden');" class="md:hidden p-2 rounded-xl border border-zinc-800 bg-zinc-900 text-zinc-300">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Mobile Responsive Dropdown Navigation -->
        <div id="sa-mobile-nav" class="hidden md:hidden border-t border-zinc-800 bg-zinc-900 p-4 space-y-2">
            <a href="index.php" class="block px-3 py-2 rounded-xl text-xs font-bold text-zinc-300 hover:bg-zinc-800">📊 Overview</a>
            <a href="restaurants.php" class="block px-3 py-2 rounded-xl text-xs font-bold text-zinc-300 hover:bg-zinc-800">🏪 Restaurants</a>
            <a href="requests.php" class="block px-3 py-2 rounded-xl text-xs font-bold text-zinc-300 hover:bg-zinc-800">📬 Requests (<?= $actionableRequests ?>)</a>
            <a href="subscriptions.php" class="block px-3 py-2 rounded-xl text-xs font-bold text-zinc-300 hover:bg-zinc-800">💳 Subscriptions</a>
            <a href="../index.php" target="_blank" class="block px-3 py-2 rounded-xl text-xs font-bold text-amber-400 hover:bg-zinc-800">🌐 Public Landing Site ↗</a>
        </div>
    </header>
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
