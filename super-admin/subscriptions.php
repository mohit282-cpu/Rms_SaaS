<?php
// super-admin/subscriptions.php - SaaS Subscription Plans & Tenant Subscriptions Governance
require_once __DIR__ . '/../config.php';
Auth::requireSuperAdmin();

$conn = getDBConnection();
$message = null;
$error = null;
$historyLogs = [];
$historyTenantName = '';

// Handle Subscription Actions (Processed before HTML output for clean header redirects)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_tenant_subscription') {
            $restId = (int)($_POST['restaurant_id'] ?? 0);
            $newPlanId = (int)($_POST['plan_id'] ?? 1);
            $subStatus = Security::sanitize($_POST['subscription_status'] ?? 'ACTIVE');
            $endDate = Security::sanitize($_POST['subscription_end'] ?? '');
            $reason = Security::sanitize(trim($_POST['change_reason'] ?? 'Manual Governance Update'));

            if ($restId > 0 && $conn) {
                // Fetch Current Tenant Data & Usage
                $rRes = $conn->query("
                    SELECT r.*, p.name as current_plan_name, p.max_tables as current_max_tables, p.max_staff as current_max_staff,
                    (SELECT COUNT(*) FROM tables t WHERE t.restaurant_id = r.id) as table_count,
                    (SELECT COUNT(*) FROM admin_users u WHERE u.restaurant_id = r.id) as user_count
                    FROM restaurants r
                    LEFT JOIN subscription_plans p ON r.subscription_plan_id = p.id
                    WHERE r.id = {$restId} LIMIT 1
                ");
                $tenant = ($rRes) ? $rRes->fetch_assoc() : null;

                // Fetch Target Plan Limits
                $pRes = $conn->query("SELECT * FROM subscription_plans WHERE id = {$newPlanId} LIMIT 1");
                $targetPlan = ($pRes) ? $pRes->fetch_assoc() : null;

                if (!$tenant || !$targetPlan) {
                    $error = "Invalid tenant or subscription plan selected.";
                } else {
                    // Check Downgrade Usage Limits (Tables and Staff)
                    $currentTables = (int)$tenant['table_count'];
                    $currentStaff = (int)$tenant['user_count'];
                    $targetMaxTables = (int)$targetPlan['max_tables'];
                    $targetMaxStaff = (int)$targetPlan['max_staff'];

                    if ($currentTables > $targetMaxTables) {
                        $error = "Current usage exceeds selected plan limits! Restaurant has {$currentTables} active tables, but '{$targetPlan['name']}' allows maximum {$targetMaxTables} tables. Please adjust tenant usage before downgrading.";
                    } elseif ($currentStaff > $targetMaxStaff) {
                        $error = "Current usage exceeds selected plan limits! Restaurant has {$currentStaff} staff users, but '{$targetPlan['name']}' allows maximum {$targetMaxStaff} staff. Please adjust tenant staff before downgrading.";
                    } else {
                        // Atomic Subscription Update Transaction
                        $conn->begin_transaction();

                        try {
                            $oldPlan = $tenant['current_plan_name'] ?? 'Unassigned';
                            $newPlan = $targetPlan['name'];

                            // Update restaurants table
                            $stmt1 = $conn->prepare("UPDATE restaurants SET subscription_plan_id = ?, subscription_status = ?, subscription_end = ? WHERE id = ?");
                            $stmt1->bind_param("issi", $newPlanId, $subStatus, $endDate, $restId);
                            $stmt1->execute();
                            $stmt1->close();

                            // Update subscriptions table
                            $stmt2 = $conn->prepare("UPDATE subscriptions SET plan_id = ?, status = ?, end_date = ? WHERE restaurant_id = ?");
                            $stmt2->bind_param("issi", $newPlanId, $subStatus, $endDate, $restId);
                            $stmt2->execute();
                            $stmt2->close();

                            // Record Security Audit Log with Change History Reason
                            $logText = "Changed subscription for tenant #{$restId} ({$tenant['restaurant_name']}) from '{$oldPlan}' ({$tenant['subscription_status']}) to '{$newPlan}' ({$subStatus}). Reason: {$reason}";
                            Security::logAudit("SUBSCRIPTION_CHANGED", $logText);

                            $conn->commit();
                            $message = "Subscription updated successfully for '{$tenant['restaurant_name']}'.";
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Failed to update tenant subscription: " . $e->getMessage();
                        }
                    }
                }
            }
        } elseif ($action === 'create_plan' || $action === 'edit_plan') {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $planCode = strtoupper(Security::sanitize(trim($_POST['plan_code'] ?? '')));
            $name = Security::sanitize(trim($_POST['name'] ?? ''));
            $slug = strtolower(Security::sanitize(trim($_POST['slug'] ?? '')));
            if (empty($slug)) $slug = strtolower($planCode);
            $description = Security::sanitize(trim($_POST['description'] ?? ''));
            $billingType = Security::sanitize(trim($_POST['billing_type'] ?? 'MONTHLY'));
            $maxTables = (int)($_POST['max_tables'] ?? 10);
            $maxStaff = (int)($_POST['max_staff'] ?? 5);
            $features = Security::sanitize(trim($_POST['features'] ?? ''));
            $status = Security::sanitize(trim($_POST['status'] ?? 'active'));

            if ($billingType === 'CUSTOM') {
                $priceMonthly = null;
                $priceYearly = null;
            } else {
                $priceMonthly = (float)($_POST['price_monthly'] ?? 0);
                $priceYearly = (float)($_POST['price_yearly'] ?? 0);
            }

            if (empty($name) || empty($planCode)) {
                $error = "Plan Code and Plan Name are required.";
            } elseif ($billingType !== 'CUSTOM' && ($priceMonthly < 0 || $priceYearly < 0)) {
                $error = "Subscription pricing cannot be negative.";
            } elseif ($maxTables < 1 || $maxStaff < 1) {
                $error = "Table and staff limits must be at least 1.";
            } else {
                if ($action === 'edit_plan' && $planId > 0) {
                    $stmt = $conn->prepare("UPDATE subscription_plans SET plan_code = ?, slug = ?, name = ?, description = ?, price_monthly = ?, price_yearly = ?, billing_type = ?, max_tables = ?, max_staff = ?, features = ?, status = ? WHERE id = ?");
                    $stmt->bind_param("ssssddsiissi", $planCode, $slug, $name, $description, $priceMonthly, $priceYearly, $billingType, $maxTables, $maxStaff, $features, $status, $planId);
                    $stmt->execute();
                    $stmt->close();

                    Security::logAudit("SUPER_ADMIN_EDIT_PLAN", "Updated subscription plan tier ID: {$planId} ({$name})");
                    $message = "Subscription plan tier '{$name}' updated successfully.";
                } else {
                    // Check slug uniqueness
                    $cSlug = $conn->prepare("SELECT id FROM subscription_plans WHERE slug = ? OR plan_code = ? LIMIT 1");
                    $cSlug->bind_param("ss", $slug, $planCode);
                    $cSlug->execute();
                    if ($cSlug->get_result()->num_rows > 0) {
                        $error = "A plan tier with this code/slug already exists. Please choose a unique code.";
                        $cSlug->close();
                    } else {
                        $cSlug->close();

                        $stmt = $conn->prepare("INSERT INTO subscription_plans (plan_code, slug, name, description, price_monthly, price_yearly, billing_type, max_tables, max_staff, features, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssddsiiss", $planCode, $slug, $name, $description, $priceMonthly, $priceYearly, $billingType, $maxTables, $maxStaff, $features, $status);
                        $stmt->execute();
                        $stmt->close();

                        Security::logAudit("SUPER_ADMIN_CREATE_PLAN", "Created new subscription plan tier: {$name} ({$planCode})");
                        $message = "New subscription plan tier '{$name}' created successfully.";
                    }
                }
            }
        } elseif ($action === 'toggle_plan_status') {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $newStatus = Security::sanitize($_POST['status'] ?? 'inactive');

            if ($planId > 0 && $conn) {
                if ($newStatus === 'inactive') {
                    // Check if plan is currently assigned to active tenants
                    $checkUsage = $conn->query("SELECT COUNT(*) as cnt FROM restaurants WHERE subscription_plan_id = {$planId} AND status = 'ACTIVE'");
                    $activeCount = ($checkUsage && $row = $checkUsage->fetch_assoc()) ? (int)$row['cnt'] : 0;
                    
                    if ($activeCount > 0) {
                        $error = "This plan is currently assigned to {$activeCount} active tenant(s) and cannot be deleted or deactivated.";
                    } else {
                        $conn->query("UPDATE subscription_plans SET status = 'inactive' WHERE id = {$planId}");
                        Security::logAudit("SUPER_ADMIN_DEACTIVATE_PLAN", "Deactivated subscription plan ID: {$planId}");
                        $message = "Subscription plan tier deactivated successfully.";
                    }
                } else {
                    $conn->query("UPDATE subscription_plans SET status = 'active' WHERE id = {$planId}");
                    Security::logAudit("SUPER_ADMIN_ACTIVATE_PLAN", "Activated subscription plan ID: {$planId}");
                    $message = "Subscription plan tier activated successfully.";
                }
            }
        }
    }
}

// Fetch Subscription Plans
$plans = [];
if ($conn) {
    $res = $conn->query("SELECT * FROM subscription_plans ORDER BY id ASC");
    if ($res) {
        while ($p = $res->fetch_assoc()) {
            $plans[] = $p;
        }
    }
}

// Search, Filter & Pagination Logic for Tenant Subscriptions
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$whereClauses = ["1=1"];
if (!empty($search)) {
    $safeSearch = $conn->real_escape_string($search);
    $whereClauses[] = "(r.restaurant_name LIKE '%{$safeSearch}%' OR r.restaurant_code LIKE '%{$safeSearch}%' OR r.owner_name LIKE '%{$safeSearch}%' OR p.name LIKE '%{$safeSearch}%')";
}
if (!empty($statusFilter)) {
    $safeStatus = $conn->real_escape_string($statusFilter);
    $whereClauses[] = "r.subscription_status = '{$safeStatus}'";
}
$whereSql = implode(' AND ', $whereClauses);

// Count Total Tenant Subscriptions
$totalRecords = 0;
$countRes = $conn->query("
    SELECT COUNT(DISTINCT r.id) as total 
    FROM restaurants r 
    LEFT JOIN subscription_plans p ON r.subscription_plan_id = p.id 
    WHERE {$whereSql}
");
if ($countRes && $cRow = $countRes->fetch_assoc()) {
    $totalRecords = (int)$cRow['total'];
}
$totalPages = max(1, ceil($totalRecords / $limit));

// Query Paginated Tenant Subscriptions
$tenantSubs = [];
if ($conn) {
    $query = "
        SELECT r.id, r.restaurant_name, r.restaurant_code, r.owner_name, r.subscription_status, r.subscription_start, r.subscription_end,
        p.name as plan_name, p.id as plan_id, p.price_monthly, p.billing_type,
        (SELECT COUNT(*) FROM tables t WHERE t.restaurant_id = r.id) as table_count,
        (SELECT COUNT(*) FROM admin_users u WHERE u.restaurant_id = r.id) as user_count
        FROM restaurants r
        LEFT JOIN subscription_plans p ON r.subscription_plan_id = p.id
        WHERE {$whereSql}
        ORDER BY r.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $res = $conn->query($query);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $tenantSubs[] = $row;
        }
    }
}

// Fetch History Logs if view_history GET param present
if (isset($_GET['view_history']) && (int)$_GET['view_history'] > 0 && $conn) {
    $hId = (int)$_GET['view_history'];
    $hRestRes = $conn->query("SELECT restaurant_name FROM restaurants WHERE id = {$hId} LIMIT 1");
    if ($hRestRes && $hRest = $hRestRes->fetch_assoc()) {
        $historyTenantName = $hRest['restaurant_name'];
    }
    $hLogsRes = $conn->query("SELECT * FROM audit_logs WHERE event_type IN ('SUBSCRIPTION_CHANGED', 'SUPER_ADMIN_UPDATE_SUBSCRIPTION', 'SUPER_ADMIN_CREATE_TENANT') AND (description LIKE '%tenant #{$hId}%' OR description LIKE '%restaurant ID: {$hId}%') ORDER BY id DESC LIMIT 20");
    if ($hLogsRes) {
        while ($l = $hLogsRes->fetch_assoc()) {
            $historyLogs[] = $l;
        }
    }
}

$pageTitle = 'Subscription Governance';
require_once __DIR__ . '/includes/header.php';
$csrfField = CSRF::getField();
?>

<div class="space-y-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-zinc-800 pb-6">
        <div>
            <h1 class="text-2xl font-black text-white tracking-tight">SaaS Subscription Governance</h1>
            <p class="text-xs text-zinc-400 mt-1 font-medium">Manage plan tiers, official NPR pricing, tenant subscription cycles, and usage limit enforcement.</p>
        </div>
        <button type="button" onclick="openCreatePlanModal();" class="px-4 py-2.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-xs hover:from-amber-400 hover:to-amber-300 transition-all flex items-center space-x-1.5 self-start sm:self-auto shadow-lg shadow-amber-500/20">
            <span>+ Add Subscription Plan Tier</span>
        </button>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">
            ✅ <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">
            ⚠️ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Plans Cards Overview Section -->
    <div class="space-y-4">
        <h2 class="text-base font-black text-white flex items-center justify-between">
            <span>Official RMS SaaS Subscription Tier Plans</span>
            <span class="text-xs font-mono text-amber-500 font-bold">Centralized Pricing Engine</span>
        </h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($plans as $p): ?>
                <?php
                $isCustom = ($p['billing_type'] === 'CUSTOM');
                $isActive = ($p['status'] === 'active');
                ?>
                <div class="bg-zinc-900 border <?= $isActive ? 'border-zinc-800' : 'border-rose-500/30 opacity-75' ?> rounded-3xl p-6 space-y-4 relative overflow-hidden flex flex-col justify-between shadow-xl group hover:border-zinc-700 transition-all">
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-wider <?= $isActive ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20' ?>">
                                <?= htmlspecialchars($p['plan_code']) ?>
                            </span>

                            <div class="flex items-center space-x-1.5">
                                <button type="button" onclick="openEditPlanModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>);" class="text-xs text-zinc-400 hover:text-amber-400 font-bold transition-colors">
                                    ✏️ Edit
                                </button>
                                <form method="POST" class="inline" onsubmit="return confirm('Toggle status for <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>?');">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="action" value="toggle_plan_status">
                                    <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                                    <button type="submit" class="text-[10px] font-bold <?= $isActive ? 'text-rose-400 hover:underline' : 'text-emerald-400 hover:underline' ?>">
                                        <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-lg font-black text-white pt-1"><?= htmlspecialchars($p['name']) ?></h3>
                            <?php if ($isCustom): ?>
                                <div class="text-xl font-black text-amber-400 mt-1">Custom Pricing</div>
                                <div class="text-[11px] text-zinc-500 font-medium">Contact Sales / Dedicated Agreement</div>
                            <?php else: ?>
                                <div class="text-2xl font-black text-white mt-1">
                                    NPR <?= number_format($p['price_monthly']) ?> <span class="text-xs text-zinc-500 font-normal">/ mo</span>
                                </div>
                                <div class="text-[11px] text-zinc-400 font-mono mt-0.5">
                                    Yearly: NPR <?= number_format($p['price_yearly']) ?> / yr <span class="text-zinc-500">(≈ NPR <?= number_format(round($p['price_yearly'] / 12)) ?>/mo)</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="space-y-2 text-xs text-zinc-400 border-t border-zinc-800/80 pt-4">
                        <div class="flex justify-between">
                            <span>Max Tables:</span>
                            <strong class="text-white"><?= $p['max_tables'] == 999 ? 'Unlimited (999)' : $p['max_tables'] ?> Tables</strong>
                        </div>
                        <div class="flex justify-between">
                            <span>Max Staff Users:</span>
                            <strong class="text-white"><?= $p['max_staff'] ?> Staff</strong>
                        </div>
                        <div class="text-[11px] text-zinc-500 pt-1 leading-relaxed">
                            <strong>Features:</strong> <?= htmlspecialchars($p['features']) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tenant Subscriptions Table Section -->
    <div class="space-y-4">
        <h2 class="text-base font-black text-white">Tenant Subscriptions Audit & Update</h2>

        <!-- Search & Filter Bar -->
        <form method="GET" class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800 flex flex-col md:flex-row items-center gap-4 shadow-xl">
            <div class="flex-1 w-full">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by restaurant name, code, owner, plan..." class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 transition-colors">
            </div>

            <div class="w-full md:w-48">
                <select name="status" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                    <option value="">All Statuses</option>
                    <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                    <option value="TRIAL" <?= $statusFilter === 'TRIAL' ? 'selected' : '' ?>>TRIAL</option>
                    <option value="PAST_DUE" <?= $statusFilter === 'PAST_DUE' ? 'selected' : '' ?>>PAST_DUE</option>
                    <option value="SUSPENDED" <?= $statusFilter === 'SUSPENDED' ? 'selected' : '' ?>>SUSPENDED</option>
                    <option value="EXPIRED" <?= $statusFilter === 'EXPIRED' ? 'selected' : '' ?>>EXPIRED</option>
                    <option value="CANCELLED" <?= $statusFilter === 'CANCELLED' ? 'selected' : '' ?>>CANCELLED</option>
                </select>
            </div>

            <div class="flex items-center space-x-2 w-full md:w-auto">
                <button type="submit" class="h-10 px-5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all shadow-md">
                    Search
                </button>
                <?php if (!empty($search) || !empty($statusFilter)): ?>
                    <a href="subscriptions.php" class="h-10 px-4 rounded-xl border border-zinc-800 bg-zinc-950 text-xs font-bold text-zinc-400 hover:text-white flex items-center justify-center">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl overflow-hidden shadow-2xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse min-w-[850px]">
                    <thead>
                        <tr class="border-b border-zinc-800 bg-zinc-950/60 text-[11px] font-black uppercase text-zinc-400 tracking-wider">
                            <th class="py-3.5 px-4">Restaurant</th>
                            <th class="py-3.5 px-4">Assigned Plan</th>
                            <th class="py-3.5 px-4">Current Status</th>
                            <th class="py-3.5 px-4">Expiration Date</th>
                            <th class="py-3.5 px-4 text-right">Actions Workflow</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/60 text-xs">
                        <?php if (empty($tenantSubs)): ?>
                            <tr>
                                <td colspan="5" class="py-12 text-center text-zinc-500 font-semibold">
                                    No tenant subscriptions found matching criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tenantSubs as $ts): ?>
                                <tr class="hover:bg-zinc-800/30 transition-colors">
                                    <td class="py-4 px-4">
                                        <div class="font-bold text-white"><?= htmlspecialchars($ts['restaurant_name']) ?></div>
                                        <div class="text-[10px] text-amber-400 font-mono mt-0.5">ID: #<?= $ts['id'] ?> &bull; Code: <?= htmlspecialchars($ts['restaurant_code']) ?></div>
                                        <div class="text-[10px] text-zinc-500 mt-0.5">Owner: <?= htmlspecialchars($ts['owner_name']) ?></div>
                                    </td>

                                    <td class="py-4 px-4 font-semibold text-zinc-300">
                                        <span class="inline-block px-2.5 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px] font-black uppercase">
                                            <?= htmlspecialchars($ts['plan_name'] ?? 'Starter') ?>
                                        </span>
                                        <div class="text-[10px] text-zinc-500 mt-1">Usage: <?= $ts['table_count'] ?> Tables / <?= $ts['user_count'] ?> Staff</div>
                                    </td>

                                    <td class="py-4 px-4">
                                        <?php if ($ts['subscription_status'] === 'ACTIVE'): ?>
                                            <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">ACTIVE</span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-rose-500/10 text-rose-400 border border-rose-500/20"><?= htmlspecialchars($ts['subscription_status']) ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-4 px-4 text-zinc-400 font-mono">
                                        <?= !empty($ts['subscription_end']) ? date('M d, Y', strtotime($ts['subscription_end'])) : 'Indefinite' ?>
                                    </td>

                                    <td class="py-4 px-4 text-right">
                                        <div class="flex items-center justify-end space-x-2">
                                            <button type="button" onclick="openUpdateSubModal(<?= htmlspecialchars(json_encode($ts), ENT_QUOTES) ?>);" class="px-3 py-1.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all shadow-sm">
                                                ⚙️ Update Subscription
                                            </button>
                                            <a href="subscriptions.php?view_history=<?= $ts['id'] ?>" class="px-2.5 py-1.5 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs font-bold transition-all">
                                                📜 History
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Controls -->
            <?php if ($totalPages > 1): ?>
                <div class="px-6 py-4 border-t border-zinc-800 bg-zinc-950/60 flex items-center justify-between text-xs text-zinc-400">
                    <div>
                        Showing <strong class="text-white"><?= min($totalRecords, $offset + 1) ?></strong> to <strong class="text-white"><?= min($totalRecords, $offset + count($tenantSubs)) ?></strong> of <strong class="text-white"><?= number_format($totalRecords) ?></strong> subscriptions
                    </div>
                    <div class="flex items-center space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>" class="px-3 py-1.5 rounded-xl border border-zinc-800 bg-zinc-900 text-white font-bold hover:bg-zinc-800">← Previous</a>
                        <?php endif; ?>
                        <span class="px-3 py-1.5 rounded-xl bg-zinc-900 border border-zinc-800 font-bold text-amber-400">Page <?= $page ?> of <?= $totalPages ?></span>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>" class="px-3 py-1.5 rounded-xl border border-zinc-800 bg-zinc-900 text-white font-bold hover:bg-zinc-800">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL 1: ADD / EDIT PLAN TIER -->
<div id="plan-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 id="plan-modal-title" class="text-base font-black text-white">Edit Subscription Plan Tier</h3>
            <button onclick="closePlanModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>
        <form method="POST" class="space-y-4 text-xs">
            <?= $csrfField ?>
            <input type="hidden" name="action" id="plan-modal-action" value="edit_plan">
            <input type="hidden" name="plan_id" id="plan-id-field">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Plan Code *</label>
                    <input type="text" name="plan_code" id="plan-code-field" required placeholder="e.g. ESSENTIAL" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-amber-400 font-mono uppercase outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Slug *</label>
                    <input type="text" name="slug" id="plan-slug-field" placeholder="e.g. essential" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white font-mono lowercase outline-none focus:border-amber-500">
                </div>
            </div>

            <div>
                <label class="block font-bold text-zinc-400 mb-1">Plan Name *</label>
                <input type="text" name="name" id="plan-name-field" required placeholder="e.g. Essential Plan" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block font-bold text-zinc-400 mb-1">Billing Type *</label>
                <select name="billing_type" id="plan-billing-field" onchange="toggleBillingTypeInputs(this.value);" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    <option value="MONTHLY">MONTHLY (Standard Pricing)</option>
                    <option value="YEARLY">YEARLY (Annual Pricing)</option>
                    <option value="CUSTOM">CUSTOM (Contact Sales / Dedicated)</option>
                </select>
            </div>

            <div id="price-fields-container" class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Price Monthly (NPR) *</label>
                    <input type="number" step="0.01" name="price_monthly" id="plan-pmonth-field" placeholder="1500" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500 font-mono">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Price Yearly (NPR) *</label>
                    <input type="number" step="0.01" name="price_yearly" id="plan-pyear-field" placeholder="15000" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500 font-mono">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Max Tables *</label>
                    <input type="number" name="max_tables" id="plan-tables-field" required min="1" max="999" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500 font-mono">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Max Staff Users *</label>
                    <input type="number" name="max_staff" id="plan-staff-field" required min="1" max="500" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500 font-mono">
                </div>
            </div>

            <div>
                <label class="block font-bold text-zinc-400 mb-1">Features Description</label>
                <textarea name="features" id="plan-features-field" rows="2" placeholder="Comma separated feature list..." class="w-full bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-white outline-none focus:border-amber-500"></textarea>
            </div>

            <div>
                <label class="block font-bold text-zinc-400 mb-1">Status *</label>
                <select name="status" id="plan-status-field" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closePlanModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400">Save Plan →</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: UPDATE TENANT SUBSCRIPTION CONFIRMATION -->
<div id="sub-update-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-black text-white">Update Tenant Subscription</h3>
            <button onclick="closeUpdateSubModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateSubUpdate(this);" class="space-y-4 text-xs">
            <?= $csrfField ?>
            <input type="hidden" name="action" value="update_tenant_subscription">
            <input type="hidden" name="restaurant_id" id="sub-rest-id">

            <div>
                <span class="text-zinc-400 block font-semibold">Target Restaurant:</span>
                <div id="sub-rest-name" class="text-base font-black text-white mt-0.5"></div>
                <div id="sub-rest-code" class="text-xs font-mono text-amber-400 font-bold"></div>
            </div>

            <div>
                <label class="block font-bold text-zinc-400 mb-1">New Subscription Plan *</label>
                <select name="plan_id" id="sub-plan-id" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    <?php foreach ($plans as $p): ?>
                        <option value="<?= $p['id'] ?>" data-tables="<?= $p['max_tables'] ?>" data-staff="<?= $p['max_staff'] ?>">
                            <?= htmlspecialchars($p['name']) ?> (Max <?= $p['max_tables'] ?> Tables, <?= $p['max_staff'] ?> Staff)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-zinc-400 mb-1">Subscription Status *</label>
                <select name="subscription_status" id="sub-status-field" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    <option value="ACTIVE">ACTIVE</option>
                    <option value="TRIAL">TRIAL</option>
                    <option value="PAST_DUE">PAST_DUE</option>
                    <option value="EXPIRED">EXPIRED</option>
                    <option value="SUSPENDED">SUSPENDED</option>
                    <option value="CANCELLED">CANCELLED</option>
                </select>
            </div>

            <div>
                <label class="block font-bold text-zinc-400 mb-1">Expiration Date</label>
                <input type="date" name="subscription_end" id="sub-end-field" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block font-bold text-zinc-400 mb-1">Reason for Change *</label>
                <input type="text" name="change_reason" required placeholder="e.g. Customer upgrade, Payment issue, Enterprise agreement" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closeUpdateSubModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400">Confirm Update →</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: TENANT SUBSCRIPTION HISTORY LOGS -->
<?php if (!empty($historyTenantName) || !empty($historyLogs)): ?>
    <div id="history-modal" class="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-xl w-full space-y-4 shadow-2xl max-h-[85vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <div>
                    <h3 class="text-base font-black text-white">Subscription Governance Audit History</h3>
                    <p class="text-xs text-amber-400 font-mono font-bold"><?= htmlspecialchars($historyTenantName) ?></p>
                </div>
                <a href="subscriptions.php" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</a>
            </div>

            <div class="space-y-3 text-xs">
                <?php if (empty($historyLogs)): ?>
                    <div class="p-4 text-center text-zinc-500">No subscription history records found for this tenant.</div>
                <?php else: ?>
                    <?php foreach ($historyLogs as $hl): ?>
                        <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-1">
                            <div class="flex items-center justify-between text-[10px]">
                                <span class="font-bold text-amber-400 font-mono"><?= htmlspecialchars($hl['event_type']) ?></span>
                                <span class="text-zinc-500 font-mono"><?= date('M d, Y H:i:s', strtotime($hl['created_at'])) ?></span>
                            </div>
                            <p class="text-zinc-300 font-medium text-xs leading-relaxed"><?= htmlspecialchars($hl['description']) ?></p>
                            <div class="text-[10px] text-zinc-500">Performed by User ID #<?= (int)$hl['user_id'] ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="flex justify-end pt-2 border-t border-zinc-800">
                <a href="subscriptions.php" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Close Audit History</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    function toggleBillingTypeInputs(val) {
        const container = document.getElementById('price-fields-container');
        if (val === 'CUSTOM') {
            container.classList.add('hidden');
        } else {
            container.classList.remove('hidden');
        }
    }

    function openEditPlanModal(plan) {
        document.getElementById('plan-modal-title').innerText = 'Edit Subscription Plan Tier';
        document.getElementById('plan-modal-action').value = 'edit_plan';
        document.getElementById('plan-id-field').value = plan.id;
        document.getElementById('plan-code-field').value = plan.plan_code;
        document.getElementById('plan-slug-field').value = plan.slug || plan.plan_code.toLowerCase();
        document.getElementById('plan-name-field').value = plan.name;
        document.getElementById('plan-billing-field').value = plan.billing_type || 'MONTHLY';
        document.getElementById('plan-pmonth-field').value = plan.price_monthly || '';
        document.getElementById('plan-pyear-field').value = plan.price_yearly || '';
        document.getElementById('plan-tables-field').value = plan.max_tables;
        document.getElementById('plan-staff-field').value = plan.max_staff;
        document.getElementById('plan-features-field').value = plan.features;
        document.getElementById('plan-status-field').value = plan.status;
        
        toggleBillingTypeInputs(plan.billing_type || 'MONTHLY');
        document.getElementById('plan-modal').classList.remove('hidden');
    }

    function openCreatePlanModal() {
        document.getElementById('plan-modal-title').innerText = 'Create Subscription Plan Tier';
        document.getElementById('plan-modal-action').value = 'create_plan';
        document.getElementById('plan-id-field').value = '';
        document.getElementById('plan-code-field').value = '';
        document.getElementById('plan-slug-field').value = '';
        document.getElementById('plan-name-field').value = '';
        document.getElementById('plan-billing-field').value = 'MONTHLY';
        document.getElementById('plan-pmonth-field').value = '1500';
        document.getElementById('plan-pyear-field').value = '15000';
        document.getElementById('plan-tables-field').value = '15';
        document.getElementById('plan-staff-field').value = '5';
        document.getElementById('plan-features-field').value = '';
        document.getElementById('plan-status-field').value = 'active';

        toggleBillingTypeInputs('MONTHLY');
        document.getElementById('plan-modal').classList.remove('hidden');
    }

    function closePlanModal() {
        document.getElementById('plan-modal').classList.add('hidden');
    }

    function openUpdateSubModal(ts) {
        window.currentTenantSub = ts;
        document.getElementById('sub-rest-id').value = ts.id;
        document.getElementById('sub-rest-name').innerText = ts.restaurant_name;
        document.getElementById('sub-rest-code').innerText = ts.restaurant_code;
        document.getElementById('sub-plan-id').value = ts.plan_id;
        document.getElementById('sub-status-field').value = ts.subscription_status;
        document.getElementById('sub-end-field').value = ts.subscription_end || '';
        document.getElementById('sub-update-modal').classList.remove('hidden');
    }

    function closeUpdateSubModal() {
        document.getElementById('sub-update-modal').classList.add('hidden');
    }

    function validateSubUpdate(form) {
        if (!window.currentTenantSub) return true;
        const select = document.getElementById('sub-plan-id');
        const selectedOpt = select.options[select.selectedIndex];
        const maxTables = parseInt(selectedOpt.getAttribute('data-tables') || 999);
        const maxStaff = parseInt(selectedOpt.getAttribute('data-staff') || 999);
        const curTables = parseInt(window.currentTenantSub.table_count || 0);
        const curStaff = parseInt(window.currentTenantSub.user_count || 0);

        if (curTables > maxTables) {
            alert(`Cannot downgrade plan! Restaurant currently has ${curTables} tables, but target plan allows max ${maxTables} tables. Please reduce table count before downgrading.`);
            return false;
        }
        if (curStaff > maxStaff) {
            alert(`Cannot downgrade plan! Restaurant currently has ${curStaff} staff users, but target plan allows max ${maxStaff} staff. Please reduce staff count before downgrading.`);
            return false;
        }

        return confirm(`Confirm subscription update for ${window.currentTenantSub.restaurant_name}?`);
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
