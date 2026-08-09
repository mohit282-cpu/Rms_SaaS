<?php
// admin/includes/sidebar.php - Master Shared Sidebar Navigation Component
// Usage: $currentPage = 'dashboard'; include 'includes/sidebar.php';
$currentPage = $currentPage ?? '';

// Check User Role
$userRole = strtolower($_SESSION['user_role'] ?? $_SESSION['role'] ?? 'admin');
$isAdminOrManager = in_array($userRole, ['admin', 'manager', 'owner']);

function sidebarLink($href, $icon, $label, $currentPage, $pageKey) {
    $isActive = ($currentPage === $pageKey);
    $baseClass = "flex items-center gap-3 px-3.5 py-2.5 rounded-2xl font-bold text-xs transition-all w-full select-none ";
    if ($isActive) {
        return '<a href="' . $href . '" class="' . $baseClass . 'bg-amber-500 text-zinc-950 font-black shadow-lg shadow-amber-500/20"><span class="text-base w-5 text-center shrink-0">' . $icon . '</span><span class="truncate">' . $label . '</span></a>';
    }
    return '<a href="' . $href . '" class="' . $baseClass . 'text-zinc-400 hover:text-white hover:bg-zinc-900"><span class="text-base w-5 text-center shrink-0">' . $icon . '</span><span class="truncate">' . $label . '</span></a>';
}

function sidebarSubLink($href, $icon, $label, $currentPage, $pageKey) {
    $isActive = ($currentPage === $pageKey);
    $baseClass = "flex items-center gap-2.5 px-3 py-1.5 rounded-xl text-[11px] transition-all w-full select-none ";
    if ($isActive) {
        return '<a href="' . $href . '" class="' . $baseClass . 'bg-amber-500/10 border border-amber-500/30 text-amber-400 font-bold"><span class="text-xs w-4 text-center shrink-0">' . $icon . '</span><span class="truncate">' . $label . '</span></a>';
    }
    return '<a href="' . $href . '" class="' . $baseClass . 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-900/80 font-medium"><span class="text-xs w-4 text-center shrink-0">' . $icon . '</span><span class="truncate">' . $label . '</span></a>';
}

$invPages = ['inventory','inventory-items','inventory-categories','suppliers','purchase-orders','goods-receiving','stock-movements','recipes','waste','stock-audit','inventory-reports'];
$assetPages = ['asset-dashboard','assets','asset-categories','asset-maintenance','asset-warranty','asset-transfers','asset-qr','asset-depreciation','asset-reports'];
$isInvSection = in_array($currentPage, $invPages);
$isAssetSection = in_array($currentPage, $assetPages);
?>

<!-- DESKTOP SIDEBAR NAVIGATION -->
<aside class="hidden md:flex flex-col fixed top-0 left-0 bottom-0 w-64 bg-zinc-950/95 backdrop-blur-2xl border-r border-zinc-800/80 z-50 select-none">
    <?php if (isset($_SESSION['restaurant_id']) && $_SESSION['restaurant_id'] == 1 && (Auth::isSuperAdmin() || isset($_SESSION['impersonating_superadmin']))): ?>
        <!-- Super Admin Internal Test Tenant Banner -->
        <div class="bg-amber-500/20 border-b border-amber-500/40 p-2.5 text-center text-[10px] font-bold text-amber-300">
            🧪 SUPER ADMIN TEST MODE - INTERNAL TEST TENANT (#1)
            <a href="../super-admin/index.php" class="block font-black text-amber-400 hover:underline mt-0.5">Return to Super Admin →</a>
        </div>
    <?php elseif (isset($_SESSION['impersonating_superadmin'])): ?>
        <!-- Impersonation Notice Banner -->
        <div class="bg-purple-500/20 border-b border-purple-500/40 p-2.5 text-center text-[10px] font-bold text-purple-300">
            👤 Support Impersonation Mode
            <a href="../super-admin/restaurants.php?action=exit_impersonation" class="block font-black text-amber-400 hover:underline mt-0.5">Exit to Super Admin →</a>
        </div>
    <?php endif; ?>

    <!-- Brand Header -->
    <div class="p-4 border-b border-zinc-800/80 flex items-center gap-3 shrink-0">
        <div class="w-9 h-9 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-lg shrink-0">⚡</div>
        <div class="overflow-hidden">
            <h2 class="font-black text-xs text-white leading-tight truncate">RMS Portal</h2>
            <p class="text-[9px] text-amber-400 font-bold truncate tracking-tight">Tenant ID: #<?= TenantContext::getTenantId() ?></p>
        </div>
    </div>

    <!-- Scrollable Navigation Menu -->
    <div class="flex-1 overflow-y-auto no-scrollbar p-3 space-y-4">
        <!-- MAIN SECTION -->
        <div class="space-y-0.5">
            <div class="px-3 py-1 text-[9px] font-black text-zinc-500 uppercase tracking-widest">Main</div>
            <?php echo sidebarLink('index.php', '📊', 'Operations Center', $currentPage, 'dashboard'); ?>
            <?php echo sidebarLink('tables.php', '📍', 'Floor & Tables', $currentPage, 'tables'); ?>
            <?php echo sidebarLink('orders.php', '📋', 'Orders Queue', $currentPage, 'orders'); ?>
            <?php echo sidebarLink('menu-items.php', '🍔', 'Menu Catalog', $currentPage, 'menu-items'); ?>
            <?php echo sidebarLink('categories.php', '🏷️', 'Categories', $currentPage, 'categories'); ?>
            <?php echo sidebarLink('payment-settings.php', '💳', 'Payment Configuration', $currentPage, 'payment-settings'); ?>
            <?php if ($isAdminOrManager): ?>
                <?php echo sidebarLink('change-password.php', '🔐', 'Security & IAM', $currentPage, 'security'); ?>
            <?php endif; ?>
        </div>

        <!-- INVENTORY SYSTEM ACCORDION -->
        <details id="invDetails" class="group" <?php echo $isInvSection ? 'open' : ''; ?>>
            <summary class="flex items-center justify-between px-3 py-2 text-[10px] font-black text-zinc-400 uppercase tracking-widest cursor-pointer hover:text-white transition-all list-none">
                <span class="flex items-center gap-1.5"><span>📦</span> INVENTORY SYSTEM</span>
                <span class="text-xs transition-transform group-open:rotate-180">▾</span>
            </summary>
            <nav class="mt-1 space-y-0.5 pl-2 border-l border-zinc-800/80 ml-3">
                <?php echo sidebarSubLink('inventory.php', '📦', 'Inventory Dashboard', $currentPage, 'inventory'); ?>
                <?php echo sidebarSubLink('inventory-items.php', '📦', 'Stock Items', $currentPage, 'inventory-items'); ?>
                <?php echo sidebarSubLink('inventory-categories.php', '🏷️', 'Inventory Categories', $currentPage, 'inventory-categories'); ?>
                <?php echo sidebarSubLink('suppliers.php', '🚚', 'Suppliers', $currentPage, 'suppliers'); ?>
                <?php echo sidebarSubLink('purchase-orders.php', '📥', 'Purchase Orders', $currentPage, 'purchase-orders'); ?>
                <?php echo sidebarSubLink('goods-receiving.php', '📦', 'Stock Receiving', $currentPage, 'goods-receiving'); ?>
                <?php echo sidebarSubLink('stock-audit.php', '🧾', 'Stock Adjustments', $currentPage, 'stock-audit'); ?>
                <?php echo sidebarSubLink('waste.php', '🗑️', 'Waste Management', $currentPage, 'waste'); ?>
            </nav>
        </details>

        <!-- ASSET MANAGEMENT ACCORDION -->
        <details id="assetDetails" class="group" <?php echo $isAssetSection ? 'open' : ''; ?>>
            <summary class="flex items-center justify-between px-3 py-2 text-[10px] font-black text-zinc-400 uppercase tracking-widest cursor-pointer hover:text-white transition-all list-none">
                <span class="flex items-center gap-1.5"><span>🏢</span> ASSET MANAGEMENT</span>
                <span class="text-xs transition-transform group-open:rotate-180">▾</span>
            </summary>
            <nav class="mt-1 space-y-0.5 pl-2 border-l border-zinc-800/80 ml-3">
                <?php echo sidebarSubLink('asset-dashboard.php', '🏢', 'Asset Dashboard', $currentPage, 'asset-dashboard'); ?>
                <?php echo sidebarSubLink('assets.php', '🧾', 'Asset Register', $currentPage, 'assets'); ?>
                <?php echo sidebarSubLink('asset-maintenance.php', '🔧', 'Maintenance', $currentPage, 'asset-maintenance'); ?>
                <?php echo sidebarSubLink('asset-transfers.php', '📅', 'Asset Assignments', $currentPage, 'asset-transfers'); ?>
                <?php echo sidebarSubLink('asset-reports.php', '📊', 'Asset Reports', $currentPage, 'asset-reports'); ?>
            </nav>
        </details>

        <!-- SYSTEM SECTION -->
        <?php if ($isAdminOrManager): ?>
        <div class="space-y-0.5 pt-1 border-t border-zinc-800/60">
            <div class="px-3 py-1 text-[9px] font-black text-zinc-500 uppercase tracking-widest">System</div>
            <?php echo sidebarLink('payment-settings.php', '⚙️', 'Settings', $currentPage, 'settings'); ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Fixed Footer Exit Action -->
    <div class="p-3 border-t border-zinc-800/80 bg-zinc-950 shrink-0">
        <a href="logout.php" class="flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-bold text-rose-400 hover:bg-rose-500/10 transition-all w-full">
            <span>🚪</span> Exit Manager Console
        </a>
    </div>
</aside>

<!-- MOBILE OFF-CANVAS SIDEBAR DRAWER -->
<div id="mobileSidebarDrawer" class="fixed inset-0 z-50 hidden md:hidden bg-black/80 backdrop-blur-sm transition-opacity">
    <div class="w-64 h-full bg-zinc-950 border-r border-zinc-800 flex flex-col p-4 space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-zinc-800">
            <div class="flex items-center gap-2">
                <span class="text-xl">☕</span>
                <span class="font-black text-xs text-white">QR Cafe POS</span>
            </div>
            <button onclick="toggleMobileSidebar()" class="text-zinc-400 text-lg hover:text-white p-1">✕</button>
        </div>
        <div class="flex-1 overflow-y-auto space-y-3">
            <nav class="space-y-1">
                <?php echo sidebarLink('index.php', '📊', 'Operations Center', $currentPage, 'dashboard'); ?>
                <?php echo sidebarLink('tables.php', '📍', 'Floor & Tables', $currentPage, 'tables'); ?>
                <?php echo sidebarLink('orders.php', '📋', 'Orders Queue', $currentPage, 'orders'); ?>
                <?php echo sidebarLink('menu-items.php', '🍔', 'Menu Catalog', $currentPage, 'menu-items'); ?>
                <?php echo sidebarLink('inventory.php', '📦', 'Inventory System', $currentPage, 'inventory'); ?>
                <?php echo sidebarLink('asset-dashboard.php', '🏢', 'Asset Management', $currentPage, 'asset-dashboard'); ?>
                <?php echo sidebarLink('payment-settings.php', '⚙️', 'Settings', $currentPage, 'settings'); ?>
            </nav>
        </div>
        <div class="pt-3 border-t border-zinc-800">
            <a href="logout.php" class="flex items-center gap-2 text-xs font-bold text-rose-400">🚪 Exit Manager Console</a>
        </div>
    </div>
</div>

<script>
    function toggleMobileSidebar() {
        const el = document.getElementById('mobileSidebarDrawer');
        if (el) {
            el.classList.toggle('hidden');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const inv = document.getElementById('invDetails');
        const asset = document.getElementById('assetDetails');
        if (inv) {
            if (localStorage.getItem('sb_inv_open') === 'true') inv.open = true;
            inv.addEventListener('toggle', () => localStorage.setItem('sb_inv_open', inv.open));
        }
        if (asset) {
            if (localStorage.getItem('sb_asset_open') === 'true') asset.open = true;
            asset.addEventListener('toggle', () => localStorage.setItem('sb_asset_open', asset.open));
        }
    });
</script>
