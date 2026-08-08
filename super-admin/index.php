<?php
// super-admin/index.php - Super Admin SaaS Platform Control Dashboard
$pageTitle = 'Super Admin Overview';
require_once __DIR__ . '/includes/header.php';

$conn = getDBConnection();

// Metrics aggregation
$totalRestaurants = 0;
$activeRestaurants = 0;
$suspendedRestaurants = 0;
$pendingRequests = 0;
$activeSubscriptions = 0;
$expiredSubscriptions = 0;
$recentRestaurants = [];
$recentRequests = [];

if ($conn) {
    // Total & Status counts
    $res = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'SUSPENDED' THEN 1 ELSE 0 END) as suspended,
        SUM(CASE WHEN subscription_status = 'ACTIVE' THEN 1 ELSE 0 END) as sub_active,
        SUM(CASE WHEN subscription_status = 'EXPIRED' THEN 1 ELSE 0 END) as sub_expired
        FROM restaurants");
    if ($res && $row = $res->fetch_assoc()) {
        $totalRestaurants = (int)$row['total'];
        $activeRestaurants = (int)$row['active'];
        $suspendedRestaurants = (int)$row['suspended'];
        $activeSubscriptions = (int)$row['sub_active'];
        $expiredSubscriptions = (int)$row['sub_expired'];
    }

    // Pending onboarding requests count
    $reqRes = $conn->query("SELECT COUNT(*) as cnt FROM restaurant_requests WHERE status = 'PENDING'");
    if ($reqRes && $row = $reqRes->fetch_assoc()) {
        $pendingRequests = (int)$row['cnt'];
    }

    // Recently created restaurants
    $recRes = $conn->query("SELECT r.*, p.name as plan_name FROM restaurants r LEFT JOIN subscription_plans p ON r.subscription_plan_id = p.id ORDER BY r.created_at DESC LIMIT 5");
    if ($recRes) {
        while ($row = $recRes->fetch_assoc()) {
            $recentRestaurants[] = $row;
        }
    }

    // Recent onboarding requests
    $reqListRes = $conn->query("SELECT * FROM restaurant_requests ORDER BY created_at DESC LIMIT 5");
    if ($reqListRes) {
        while ($row = $reqListRes->fetch_assoc()) {
            $recentRequests[] = $row;
        }
    }
}
?>

<div class="space-y-8">
    <!-- Header Title Banner -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-zinc-800 pb-6">
        <div>
            <h1 class="text-2xl font-black text-white tracking-tight">SaaS Platform Dashboard</h1>
            <p class="text-xs text-zinc-400 mt-1 font-medium">Real-time metrics, tenant governance, and onboarding pipeline control.</p>
        </div>
        <div class="flex items-center space-x-3">
            <a href="requests.php" class="px-4 py-2.5 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 font-bold text-xs hover:bg-amber-500/20 transition-all flex items-center space-x-2">
                <span>📬 Review Pending Requests</span>
                <?php if ($pendingRequests > 0): ?>
                    <span class="px-2 py-0.5 rounded-full bg-amber-500 text-zinc-950 font-black text-[10px]"><?= $pendingRequests ?></span>
                <?php endif; ?>
            </a>
            <a href="create-restaurant.php" class="px-4 py-2.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-xs hover:from-amber-400 hover:to-amber-300 transition-all shadow-lg shadow-amber-500/20 flex items-center space-x-1.5">
                <span>+ Onboard Restaurant</span>
            </a>
        </div>
    </div>

    <!-- Metrics Cards Grid -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
        <div class="bg-zinc-900/80 border border-zinc-800 rounded-3xl p-6 shadow-xl relative overflow-hidden group hover:border-zinc-700 transition-all">
            <div class="text-xs font-bold text-zinc-400 uppercase tracking-wider mb-2">Total Restaurants</div>
            <div class="text-3xl font-black text-white group-hover:scale-105 transition-transform"><?= number_format($totalRestaurants) ?></div>
            <div class="text-[11px] text-zinc-500 mt-2 font-semibold">Registered Multi-Tenant Accounts</div>
            <div class="absolute right-4 bottom-4 text-4xl opacity-10 font-black">🏪</div>
        </div>

        <div class="bg-zinc-900/80 border border-zinc-800 rounded-3xl p-6 shadow-xl relative overflow-hidden group hover:border-zinc-700 transition-all">
            <div class="text-xs font-bold text-emerald-400 uppercase tracking-wider mb-2">Active Tenants</div>
            <div class="text-3xl font-black text-emerald-400 group-hover:scale-105 transition-transform"><?= number_format($activeRestaurants) ?></div>
            <div class="text-[11px] text-zinc-500 mt-2 font-semibold">Operational RMS Portals</div>
            <div class="absolute right-4 bottom-4 text-4xl opacity-10 font-black text-emerald-400">🟢</div>
        </div>

        <div class="bg-zinc-900/80 border border-zinc-800 rounded-3xl p-6 shadow-xl relative overflow-hidden group hover:border-zinc-700 transition-all">
            <div class="text-xs font-bold text-amber-400 uppercase tracking-wider mb-2">Pending Requests</div>
            <div class="text-3xl font-black text-amber-400 group-hover:scale-105 transition-transform"><?= number_format($pendingRequests) ?></div>
            <div class="text-[11px] text-zinc-500 mt-2 font-semibold">Awaiting Super Admin Approval</div>
            <div class="absolute right-4 bottom-4 text-4xl opacity-10 font-black text-amber-400">📬</div>
        </div>

        <div class="bg-zinc-900/80 border border-zinc-800 rounded-3xl p-6 shadow-xl relative overflow-hidden group hover:border-zinc-700 transition-all">
            <div class="text-xs font-bold text-rose-400 uppercase tracking-wider mb-2">Suspended / Expired</div>
            <div class="text-3xl font-black text-rose-400 group-hover:scale-105 transition-transform"><?= number_format($suspendedRestaurants + $expiredSubscriptions) ?></div>
            <div class="text-[11px] text-zinc-500 mt-2 font-semibold">Suspended or Past Due Accounts</div>
            <div class="absolute right-4 bottom-4 text-4xl opacity-10 font-black text-rose-400">🚫</div>
        </div>
    </div>

    <!-- Onboarding Pipeline & Recent Tenants Split View -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Recent Onboarding Requests -->
        <div class="bg-zinc-900/80 border border-zinc-800 rounded-3xl p-6 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-black text-white">Pending Onboarding Requests</h2>
                    <p class="text-xs text-zinc-400 mt-0.5">New restaurant access requests from landing page</p>
                </div>
                <a href="requests.php" class="text-xs font-bold text-amber-400 hover:underline">View All →</a>
            </div>

            <?php if (empty($recentRequests)): ?>
                <div class="text-center py-8 text-xs text-zinc-500 font-semibold border border-dashed border-zinc-800 rounded-2xl">
                    No pending onboarding requests.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentRequests as $req): ?>
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center justify-between gap-4">
                            <div>
                                <div class="text-sm font-bold text-white"><?= htmlspecialchars($req['restaurant_name']) ?></div>
                                <div class="text-xs text-zinc-400 mt-0.5">
                                    Owner: <strong><?= htmlspecialchars($req['owner_name']) ?></strong> &bull; <?= htmlspecialchars($req['phone']) ?> &bull; <?= htmlspecialchars($req['email']) ?>
                                </div>
                                <div class="text-[10px] text-zinc-500 mt-1">Submitted <?= date('M d, Y H:i', strtotime($req['created_at'])) ?></div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-wider bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                    <?= htmlspecialchars($req['status']) ?>
                                </span>
                                <a href="requests.php?id=<?= $req['id'] ?>" class="px-3 py-1.5 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-xs font-bold text-white transition-colors">
                                    Review
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Restaurants -->
        <div class="bg-zinc-900/80 border border-zinc-800 rounded-3xl p-6 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-black text-white">Active Tenants Overview</h2>
                    <p class="text-xs text-zinc-400 mt-0.5">Recently provisioned restaurant environments</p>
                </div>
                <a href="restaurants.php" class="text-xs font-bold text-amber-400 hover:underline">Manage All →</a>
            </div>

            <?php if (empty($recentRestaurants)): ?>
                <div class="text-center py-8 text-xs text-zinc-500 font-semibold border border-dashed border-zinc-800 rounded-2xl">
                    No restaurants provisioned yet.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentRestaurants as $rest): ?>
                        <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 flex items-center justify-between gap-4">
                            <div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-sm font-bold text-white"><?= htmlspecialchars($rest['restaurant_name']) ?></span>
                                    <span class="text-[10px] font-mono px-2 py-0.5 rounded-md bg-zinc-800 text-amber-400 font-bold"><?= htmlspecialchars($rest['restaurant_code']) ?></span>
                                </div>
                                <div class="text-xs text-zinc-400 mt-0.5">
                                    Owner: <?= htmlspecialchars($rest['owner_name']) ?> &bull; Plan: <strong><?= htmlspecialchars($rest['plan_name'] ?? 'Starter') ?></strong>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <?php if ($rest['status'] === 'ACTIVE'): ?>
                                    <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-wider bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Active</span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-wider bg-rose-500/10 text-rose-400 border border-rose-500/20"><?= htmlspecialchars($rest['status']) ?></span>
                                <?php endif; ?>
                                <a href="restaurants.php?action=view&id=<?= $rest['id'] ?>" class="px-3 py-1.5 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-xs font-bold text-white transition-colors">
                                    Manage
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
