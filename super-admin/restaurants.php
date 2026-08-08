<?php
// super-admin/restaurants.php - Tenant Restaurant Management & Impersonation Surface
$pageTitle = 'Restaurant Tenants Management';
require_once __DIR__ . '/includes/header.php';

$conn = getDBConnection();
$message = null;
$error = null;

// Handle Actions (Suspend, Activate, Reset Credentials, Impersonate)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed.";
    } else {
        $action = $_POST['action'] ?? '';
        $restId = (int)($_POST['restaurant_id'] ?? 0);

        if ($restId > 0 && $conn) {
            if ($action === 'suspend') {
                $conn->query("UPDATE restaurants SET status = 'SUSPENDED' WHERE id = {$restId}");
                Security::logAudit("SUPER_ADMIN_SUSPEND_TENANT", "Super Admin suspended restaurant tenant ID: {$restId}");
                $message = "Restaurant tenant account suspended successfully.";
            } elseif ($action === 'activate') {
                $conn->query("UPDATE restaurants SET status = 'ACTIVE' WHERE id = {$restId}");
                Security::logAudit("SUPER_ADMIN_ACTIVATE_TENANT", "Super Admin activated restaurant tenant ID: {$restId}");
                $message = "Restaurant tenant account activated successfully.";
            } elseif ($action === 'reset_credentials') {
                // Generate new secure temporary password
                $newTempPass = bin2hex(random_bytes(6)) . '!' . rand(10, 99);
                $hashPass = password_hash($newTempPass, PASSWORD_DEFAULT);

                // Update owner password in admin_users
                $stmt = $conn->prepare("UPDATE admin_users SET password = ?, force_password_change = 1 WHERE restaurant_id = ? AND role IN ('admin', 'owner') ORDER BY id ASC LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("si", $hashPass, $restId);
                    $stmt->execute();
                    $stmt->close();
                }

                Security::logAudit("SUPER_ADMIN_RESET_CREDENTIALS", "Super Admin reset owner password for restaurant ID: {$restId}");
                $message = "Temporary credentials reset! New Password: <strong class='text-amber-400 font-mono select-all'>{$newTempPass}</strong> (User will be forced to change it on login).";
            } elseif ($action === 'impersonate') {
                // Log impersonation audit event
                Security::logAudit("SUPER_ADMIN_IMPERSONATE_TENANT", "Super Admin (" . $_SESSION['username'] . ") initiated support impersonation session for restaurant ID: {$restId}");
                
                // Store impersonation flags in session
                $_SESSION['impersonating_superadmin'] = $_SESSION['admin_id'];
                $_SESSION['restaurant_id'] = $restId;
                
                // Fetch restaurant owner user for session binding
                $ownerRes = $conn->query("SELECT id, username, full_name, role FROM admin_users WHERE restaurant_id = {$restId} ORDER BY id ASC LIMIT 1");
                if ($ownerRes && $owner = $ownerRes->fetch_assoc()) {
                    $_SESSION['admin_id'] = $owner['id'];
                    $_SESSION['role'] = $owner['role'];
                }

                header('Location: ../admin/index.php');
                exit;
            }
        }
    }
}

// Search and Filtering
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$whereClauses = ["1=1"];
if (!empty($search)) {
    $safeSearch = $conn->real_escape_string($search);
    $whereClauses[] = "(r.restaurant_name LIKE '%{$safeSearch}%' OR r.owner_name LIKE '%{$safeSearch}%' OR r.email LIKE '%{$safeSearch}%' OR r.phone LIKE '%{$safeSearch}%' OR r.restaurant_code LIKE '%{$safeSearch}%' OR r.pan_number LIKE '%{$safeSearch}%')";
}
if (!empty($statusFilter)) {
    $safeStatus = $conn->real_escape_string($statusFilter);
    $whereClauses[] = "r.status = '{$safeStatus}'";
}

$whereSql = implode(' AND ', $whereClauses);
$query = "
    SELECT r.*, p.name as plan_name,
    (SELECT COUNT(*) FROM tables t WHERE t.restaurant_id = r.id) as table_count,
    (SELECT COUNT(*) FROM orders o WHERE o.restaurant_id = r.id) as order_count,
    (SELECT COUNT(*) FROM admin_users u WHERE u.restaurant_id = r.id) as user_count
    FROM restaurants r
    LEFT JOIN subscription_plans p ON r.subscription_plan_id = p.id
    WHERE {$whereSql}
    ORDER BY r.id DESC
";

$restaurants = [];
if ($conn) {
    $res = $conn->query($query);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $restaurants[] = $row;
        }
    }
}

$csrfField = CSRF::getField();
?>

<div class="space-y-6">
    <!-- Header Banner -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-zinc-800 pb-6">
        <div>
            <h1 class="text-2xl font-black text-white tracking-tight">Restaurant Tenants Management</h1>
            <p class="text-xs text-zinc-400 mt-1 font-medium">View, provision, audit, and manage isolated restaurant workspaces.</p>
        </div>
        <a href="create-restaurant.php" class="px-4 py-2.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-xs hover:from-amber-400 hover:to-amber-300 transition-all shadow-lg shadow-amber-500/20 inline-flex items-center space-x-1.5 self-start sm:self-auto">
            <span>+ Create New Restaurant</span>
        </a>
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

    <!-- Search & Filter Controls -->
    <form method="GET" class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800 flex flex-col md:flex-row items-center gap-4">
        <div class="flex-1 w-full">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, owner, email, code, phone, PAN..." class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 transition-colors">
        </div>
        <div class="w-full md:w-48">
            <select name="status" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                <option value="">All Statuses</option>
                <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                <option value="SUSPENDED" <?= $statusFilter === 'SUSPENDED' ? 'selected' : '' ?>>SUSPENDED</option>
                <option value="PENDING" <?= $statusFilter === 'PENDING' ? 'selected' : '' ?>>PENDING</option>
                <option value="EXPIRED" <?= $statusFilter === 'EXPIRED' ? 'selected' : '' ?>>EXPIRED</option>
            </select>
        </div>
        <div class="flex items-center space-x-2 w-full md:w-auto">
            <button type="submit" class="h-10 px-5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all">
                Search
            </button>
            <?php if (!empty($search) || !empty($statusFilter)): ?>
                <a href="restaurants.php" class="h-10 px-4 rounded-xl border border-zinc-800 bg-zinc-950 text-xs font-bold text-zinc-400 hover:text-white flex items-center justify-center">
                    Reset
                </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Restaurant Tenants Table -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl overflow-hidden shadow-2xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-zinc-800 bg-zinc-950/60 text-[11px] font-black uppercase text-zinc-400 tracking-wider">
                        <th class="py-3.5 px-4">Code & Restaurant</th>
                        <th class="py-3.5 px-4">Owner & Contact</th>
                        <th class="py-3.5 px-4">Plan & Subscription</th>
                        <th class="py-3.5 px-4 text-center">Tables / Orders</th>
                        <th class="py-3.5 px-4">Status</th>
                        <th class="py-3.5 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60 text-xs">
                    <?php if (empty($restaurants)): ?>
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500 font-semibold">
                                No restaurant tenants found matching criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($restaurants as $r): ?>
                            <tr class="hover:bg-zinc-800/30 transition-colors">
                                <td class="py-4 px-4">
                                    <div class="font-bold text-white text-sm"><?= htmlspecialchars($r['restaurant_name']) ?></div>
                                    <div class="text-[10px] text-zinc-400 font-mono mt-0.5">
                                        ID: #<?= $r['id'] ?> &bull; Code: <strong class="text-amber-400"><?= htmlspecialchars($r['restaurant_code']) ?></strong>
                                    </div>
                                    <div class="text-[10px] text-zinc-500 mt-0.5"><?= htmlspecialchars($r['restaurant_type']) ?> &bull; PAN: <?= htmlspecialchars($r['pan_number'] ?: 'N/A') ?></div>
                                </td>

                                <td class="py-4 px-4">
                                    <div class="font-bold text-zinc-200"><?= htmlspecialchars($r['owner_name']) ?></div>
                                    <div class="text-zinc-400 text-[11px]"><?= htmlspecialchars($r['email']) ?></div>
                                    <div class="text-zinc-500 text-[11px]"><?= htmlspecialchars($r['phone']) ?></div>
                                </td>

                                <td class="py-4 px-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px] font-black uppercase">
                                        <?= htmlspecialchars($r['plan_name'] ?? 'Starter') ?>
                                    </span>
                                    <div class="text-[10px] text-zinc-400 mt-1">
                                        Sub Status: <strong class="text-zinc-200"><?= htmlspecialchars($r['subscription_status']) ?></strong>
                                    </div>
                                    <div class="text-[10px] text-zinc-500">
                                        Expires: <?= !empty($r['subscription_end']) ? date('M d, Y', strtotime($r['subscription_end'])) : 'Infinite' ?>
                                    </div>
                                </td>

                                <td class="py-4 px-4 text-center">
                                    <div class="font-bold text-white"><?= number_format($r['table_count']) ?> Tables</div>
                                    <div class="text-[10px] text-zinc-400"><?= number_format($r['order_count']) ?> Orders</div>
                                </td>

                                <td class="py-4 px-4">
                                    <?php if ($r['status'] === 'ACTIVE'): ?>
                                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">ACTIVE</span>
                                    <?php elseif ($r['status'] === 'SUSPENDED'): ?>
                                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-rose-500/10 text-rose-400 border border-rose-500/20">SUSPENDED</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-zinc-800 text-zinc-400"><?= htmlspecialchars($r['status']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-4 px-4 text-right">
                                    <div class="flex items-center justify-end space-x-1.5">
                                        <!-- Impersonate Support Login -->
                                        <form method="POST" class="inline" onsubmit="return confirm('Initiate Super Admin support impersonation session for <?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>? All actions will be logged.');">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="action" value="impersonate">
                                            <input type="hidden" name="restaurant_id" value="<?= $r['id'] ?>">
                                            <button type="submit" title="Login as Restaurant (Support Impersonation)" class="p-2 rounded-xl bg-purple-500/10 border border-purple-500/30 text-purple-400 hover:bg-purple-500/20 text-xs font-bold transition-all">
                                                👤 Impersonate
                                            </button>
                                        </form>

                                        <!-- Reset Credentials -->
                                        <form method="POST" class="inline" onsubmit="return confirm('Generate new temporary password for <?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?> owner?');">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="action" value="reset_credentials">
                                            <input type="hidden" name="restaurant_id" value="<?= $r['id'] ?>">
                                            <button type="submit" title="Reset Owner Password" class="p-2 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-amber-400 text-xs font-bold transition-all">
                                                🔑 Reset Pass
                                            </button>
                                        </form>

                                        <!-- Toggle Status (Suspend / Activate) -->
                                        <?php if ($r['status'] === 'ACTIVE'): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Suspend restaurant <?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>? Users will lose access immediately.');">
                                                <?= $csrfField ?>
                                                <input type="hidden" name="action" value="suspend">
                                                <input type="hidden" name="restaurant_id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="px-3 py-1.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 hover:bg-rose-500/20 text-xs font-bold transition-all">
                                                    Suspend
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" class="inline">
                                                <?= $csrfField ?>
                                                <input type="hidden" name="action" value="activate">
                                                <input type="hidden" name="restaurant_id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="px-3 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20 text-xs font-bold transition-all">
                                                    Activate
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
