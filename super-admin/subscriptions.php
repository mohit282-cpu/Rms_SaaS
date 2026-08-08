<?php
// super-admin/subscriptions.php - SaaS Subscription Plans & Tenant Subscriptions Governance
$pageTitle = 'Subscription Governance';
require_once __DIR__ . '/includes/header.php';

$conn = getDBConnection();
$message = null;
$error = null;

// Handle Subscription Plan or Tenant Subscription Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed.";
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_tenant_subscription') {
            $restId = (int)($_POST['restaurant_id'] ?? 0);
            $planId = (int)($_POST['plan_id'] ?? 1);
            $subStatus = Security::sanitize($_POST['subscription_status'] ?? 'ACTIVE');
            $endDate = Security::sanitize($_POST['subscription_end'] ?? '');

            if ($restId > 0 && $conn) {
                $stmt = $conn->prepare("UPDATE restaurants SET subscription_plan_id = ?, subscription_status = ?, subscription_end = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("issi", $planId, $subStatus, $endDate, $restId);
                    $stmt->execute();
                    $stmt->close();
                    Security::logAudit("SUPER_ADMIN_UPDATE_SUBSCRIPTION", "Updated subscription for tenant ID: {$restId}");
                    $message = "Tenant subscription updated successfully.";
                }
            }
        }
    }
}

// Fetch Subscription Plans
$plans = [];
if ($conn) {
    $res = $conn->query("SELECT * FROM subscription_plans ORDER BY price_monthly ASC");
    if ($res) {
        while ($p = $res->fetch_assoc()) {
            $plans[] = $p;
        }
    }
}

// Fetch Tenant Subscriptions
$tenantSubs = [];
if ($conn) {
    $res = $conn->query("
        SELECT r.id, r.restaurant_name, r.restaurant_code, r.subscription_status, r.subscription_end, p.name as plan_name, p.id as plan_id
        FROM restaurants r
        LEFT JOIN subscription_plans p ON r.subscription_plan_id = p.id
        ORDER BY r.id DESC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $tenantSubs[] = $row;
        }
    }
}

$csrfField = CSRF::getField();
?>

<div class="space-y-8">
    <div class="border-b border-zinc-800 pb-6">
        <h1 class="text-2xl font-black text-white tracking-tight">SaaS Subscription Governance</h1>
        <p class="text-xs text-zinc-400 mt-1 font-medium">Manage plan tiers, tenant subscription cycles, and feature access boundaries.</p>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">
            ✅ <?= $message ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">
            ⚠️ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Plans Cards Overview -->
    <div class="space-y-4">
        <h2 class="text-base font-black text-white">Active Subscription Tier Plans</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($plans as $p): ?>
                <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 space-y-4 relative overflow-hidden flex flex-col justify-between shadow-xl">
                    <div class="space-y-2">
                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-wider bg-amber-500/10 text-amber-400 border border-amber-500/20">
                            <?= htmlspecialchars($p['plan_code']) ?>
                        </span>
                        <h3 class="text-lg font-black text-white pt-1"><?= htmlspecialchars($p['name']) ?></h3>
                        <div class="text-2xl font-black text-white">
                            $<?= number_format($p['price_monthly'], 2) ?> <span class="text-xs text-zinc-500 font-normal">/ mo</span>
                        </div>
                    </div>

                    <div class="space-y-2 text-xs text-zinc-400 border-t border-zinc-800/80 pt-4">
                        <div class="flex justify-between">
                            <span>Max Tables:</span>
                            <strong class="text-white"><?= $p['max_tables'] ?></strong>
                        </div>
                        <div class="flex justify-between">
                            <span>Max Staff Users:</span>
                            <strong class="text-white"><?= $p['max_staff'] ?></strong>
                        </div>
                        <div class="text-[11px] text-zinc-500 pt-1">
                            Features: <?= htmlspecialchars($p['features']) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tenant Subscriptions Table -->
    <div class="space-y-4">
        <h2 class="text-base font-black text-white">Tenant Subscriptions Audit</h2>
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl overflow-hidden shadow-2xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-zinc-800 bg-zinc-950/60 text-[11px] font-black uppercase text-zinc-400 tracking-wider">
                            <th class="py-3.5 px-4">Restaurant</th>
                            <th class="py-3.5 px-4">Assigned Plan</th>
                            <th class="py-3.5 px-4">Sub Status</th>
                            <th class="py-3.5 px-4">Expiration Date</th>
                            <th class="py-3.5 px-4 text-right">Update Subscription</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/60 text-xs">
                        <?php foreach ($tenantSubs as $ts): ?>
                            <tr class="hover:bg-zinc-800/30 transition-colors">
                                <td class="py-4 px-4">
                                    <div class="font-bold text-white"><?= htmlspecialchars($ts['restaurant_name']) ?></div>
                                    <div class="text-[10px] text-amber-400 font-mono"><?= htmlspecialchars($ts['restaurant_code']) ?></div>
                                </td>
                                <td class="py-4 px-4 font-semibold text-zinc-300">
                                    <?= htmlspecialchars($ts['plan_name'] ?? 'Starter') ?>
                                </td>
                                <td class="py-4 px-4">
                                    <?php if ($ts['subscription_status'] === 'ACTIVE'): ?>
                                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-rose-500/10 text-rose-400 border border-rose-500/20"><?= htmlspecialchars($ts['subscription_status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-4 text-zinc-400 font-mono">
                                    <?= !empty($ts['subscription_end']) ? htmlspecialchars($ts['subscription_end']) : 'Indefinite' ?>
                                </td>
                                <td class="py-4 px-4 text-right">
                                    <form method="POST" class="flex items-center justify-end space-x-2">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="action" value="update_tenant_subscription">
                                        <input type="hidden" name="restaurant_id" value="<?= $ts['id'] ?>">

                                        <select name="plan_id" class="h-8 bg-zinc-950 border border-zinc-800 rounded-lg px-2 text-[11px] text-white">
                                            <?php foreach ($plans as $p): ?>
                                                <option value="<?= $p['id'] ?>" <?= $p['id'] == $ts['plan_id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <select name="subscription_status" class="h-8 bg-zinc-950 border border-zinc-800 rounded-lg px-2 text-[11px] text-white">
                                            <option value="ACTIVE" <?= $ts['subscription_status'] === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                                            <option value="TRIAL" <?= $ts['subscription_status'] === 'TRIAL' ? 'selected' : '' ?>>TRIAL</option>
                                            <option value="PAST_DUE" <?= $ts['subscription_status'] === 'PAST_DUE' ? 'selected' : '' ?>>PAST_DUE</option>
                                            <option value="EXPIRED" <?= $ts['subscription_status'] === 'EXPIRED' ? 'selected' : '' ?>>EXPIRED</option>
                                            <option value="SUSPENDED" <?= $ts['subscription_status'] === 'SUSPENDED' ? 'selected' : '' ?>>SUSPENDED</option>
                                        </select>

                                        <input type="date" name="subscription_end" value="<?= htmlspecialchars($ts['subscription_end'] ?? '') ?>" class="h-8 bg-zinc-950 border border-zinc-800 rounded-lg px-2 text-[11px] text-white">

                                        <button type="submit" class="h-8 px-3 rounded-lg bg-amber-500 text-zinc-950 font-black text-[11px] hover:bg-amber-400 transition-all">
                                            Save
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
