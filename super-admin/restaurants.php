<?php
// super-admin/restaurants.php - Tenant Restaurant Management & Manual Governance Surface
require_once __DIR__ . '/../config.php';
Auth::requireSuperAdmin();

$conn = getDBConnection();
$message = null;
$error = null;
$resetResult = null;

// Handle Super Admin Actions (Processed before HTML output to allow clean HTTP redirects)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed. Please try again.";
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
            } elseif ($action === 'disable') {
                $conn->query("UPDATE restaurants SET status = 'INACTIVE' WHERE id = {$restId}");
                Security::logAudit("SUPER_ADMIN_DISABLE_TENANT", "Super Admin disabled restaurant tenant ID: {$restId}");
                $message = "Restaurant tenant account disabled successfully.";
            } elseif ($action === 'delete_restaurant') {
                $delStmt = $conn->prepare("SELECT restaurant_name FROM restaurants WHERE id = ? LIMIT 1");
                $delStmt->bind_param("i", $restId);
                $delStmt->execute();
                $delRow = $delStmt->get_result()->fetch_assoc();
                $delStmt->close();

                if (!$delRow) {
                    $error = "Restaurant tenant not found.";
                } else {
                    // Check if this tenant hosts any Super Admin user accounts
                    $saCheck = $conn->query("SELECT id FROM admin_users WHERE restaurant_id = {$restId} AND is_super_admin = 1");
                    $hasSaUsers = ($saCheck && $saCheck->num_rows > 0);

                    // Find alternative tenant for Super Admin preservation if needed
                    $targetRestId = 1;
                    if ($hasSaUsers) {
                        $altRes = $conn->query("SELECT id FROM restaurants WHERE id != {$restId} ORDER BY id ASC LIMIT 1");
                        if ($altRes && $altRow = $altRes->fetch_assoc()) {
                            $targetRestId = (int)$altRow['id'];
                        }
                    }

                    $resDel = TenantDeletionService::deleteTenant($conn, $restId);
                    if ($resDel['success']) {
                        $message = $resDel['message'];
                    } else {
                        $error = $resDel['error'];
                    }
                }
            } elseif ($action === 'reset_password') {
                $newPass = $_POST['new_password'] ?? '';
                $confirmPass = $_POST['confirm_password'] ?? '';

                if (empty($newPass) || empty($confirmPass)) {
                    $error = "Please fill in both New Password and Confirm Password fields.";
                } elseif ($newPass !== $confirmPass) {
                    $error = "New Password and Confirm Password do not match.";
                } elseif (strlen($newPass) < 8) {
                    $error = "Password must be at least 8 characters long.";
                } else {
                    $hashPass = password_hash($newPass, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("UPDATE admin_users SET password = ?, force_password_change = 0 WHERE restaurant_id = ? AND is_super_admin = 0 ORDER BY id ASC LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param("si", $hashPass, $restId);
                        $stmt->execute();
                        $stmt->close();

                        // Get admin email for display
                        $uRes = $conn->query("SELECT email FROM admin_users WHERE restaurant_id = {$restId} AND is_super_admin = 0 LIMIT 1");
                        $uName = ($uRes && $u = $uRes->fetch_assoc()) ? $u['email'] : 'Admin';

                        Security::logAudit("SUPER_ADMIN_RESET_PASSWORD", "Super Admin reset password for restaurant ID: {$restId} (Admin User: {$uName})");
                        $resetResult = [
                            'email' => $uName,
                            'password' => $newPass
                        ];
                        $message = "Administrator password has been reset successfully.";
                    }
                }
            } elseif ($action === 'change_email') {
                $rawEmail = trim($_POST['new_email'] ?? '');
                $newEmail = strtolower(Security::sanitize($rawEmail));

                if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = "A valid email address is required.";
                } else {
                    // Check duplicate email (globally unique - email is the login identity)
                    $checkUser = $conn->prepare("SELECT id FROM admin_users WHERE LOWER(email) = ? AND id != (SELECT id FROM admin_users WHERE restaurant_id = ? AND is_super_admin = 0 ORDER BY id ASC LIMIT 1) LIMIT 1");
                    $checkUser->bind_param("si", $newEmail, $restId);
                    $checkUser->execute();
                    if ($checkUser->get_result()->num_rows > 0) {
                        $error = "An account already exists with this email address.";
                        $checkUser->close();
                    } else {
                        $checkUser->close();

                        $stmt = $conn->prepare("UPDATE admin_users SET email = ? WHERE restaurant_id = ? AND is_super_admin = 0 ORDER BY id ASC LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param("si", $newEmail, $restId);
                            $stmt->execute();
                            $stmt->close();
                        }

                        Security::logAudit("SUPER_ADMIN_CHANGE_EMAIL", "Super Admin changed admin login email to {$newEmail} for restaurant ID: {$restId}");
                        $message = "Administrator login email updated successfully to '{$newEmail}'.";
                    }
                }
            } elseif ($action === 'edit_restaurant') {
                $restName = Security::sanitize(trim($_POST['restaurant_name'] ?? ''));
                $ownerName = Security::sanitize(trim($_POST['owner_name'] ?? ''));
                $email = strtolower(Security::sanitize(trim($_POST['email'] ?? '')));
                $phone = Security::sanitize(trim($_POST['phone'] ?? ''));
                $panNumber = Security::sanitize(trim($_POST['pan_number'] ?? ''));
                $planId = (int)($_POST['plan_id'] ?? 2);
                $status = Security::sanitize(trim($_POST['status'] ?? 'ACTIVE'));
                $subStatus = Security::sanitize(trim($_POST['subscription_status'] ?? 'ACTIVE'));
                $subEnd = Security::sanitize(trim($_POST['subscription_end'] ?? ''));

                $stmt = $conn->prepare("UPDATE restaurants SET restaurant_name = ?, owner_name = ?, email = ?, phone = ?, pan_number = ?, subscription_plan_id = ?, status = ?, subscription_status = ?, subscription_end = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("sssssissis", $restName, $ownerName, $email, $phone, $panNumber, $planId, $status, $subStatus, $subEnd, $restId);
                    $stmt->execute();
                    $stmt->close();
                }

                Security::logAudit("SUPER_ADMIN_EDIT_TENANT", "Super Admin updated details for restaurant ID: {$restId}");
                $message = "Restaurant details updated successfully.";
            } elseif ($action === 'impersonate') {
                Security::logAudit("SUPER_ADMIN_IMPERSONATE_TENANT", "Super Admin (" . ($_SESSION['email'] ?? 'unknown') . ") initiated support impersonation session for restaurant ID: {$restId}");
                
                // Regenerate session ID to prevent session fixation
                session_regenerate_id(true);
                
                // Preserve superadmin context
                $_SESSION['impersonating_superadmin'] = true;
                $_SESSION['sa_original_admin_id'] = $_SESSION['admin_id'];
                $_SESSION['sa_original_email'] = $_SESSION['email'];
                $_SESSION['sa_original_role'] = $_SESSION['role'];
                $_SESSION['sa_original_restaurant_id'] = isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : 1;
                
                // Set impersonation context
                $_SESSION['restaurant_id'] = $restId;
                $_SESSION['is_super_admin'] = false; // Impersonation uses tenant role
                
                $ownerRes = $conn->query("SELECT id, email, full_name, role FROM admin_users WHERE restaurant_id = {$restId} ORDER BY id ASC LIMIT 1");
                if ($ownerRes && $owner = $ownerRes->fetch_assoc()) {
                    $_SESSION['admin_id'] = $owner['id'];
                    $_SESSION['user_id'] = $owner['id'];
                    $_SESSION['email'] = $owner['email'];
                    $_SESSION['admin_email'] = $owner['email'];
                    $_SESSION['full_name'] = $owner['full_name'];
                    $_SESSION['role'] = strtoupper($owner['role']);
                }
                
                // Force password change check for impersonated account
                $forceChangeRes = $conn->query("SELECT force_password_change FROM admin_users WHERE id = " . $owner['id']);
                if ($forceChangeRes && $fc = $forceChangeRes->fetch_assoc()) {
                    $_SESSION['force_password_change'] = (bool)$fc['force_password_change'];
                }

                header('Location: ../admin/index.php');
                exit;
            } elseif ($action === 'exit_impersonation') {
                // Exit impersonation and restore superadmin context
                Security::logAudit("SUPER_ADMIN_EXIT_IMPERSONATION", "Super Admin (" . ($_SESSION['sa_original_email'] ?? 'unknown') . ") exited impersonation session");
                
                if (isset($_SESSION['impersonating_superadmin']) && $_SESSION['impersonating_superadmin']) {
                    $_SESSION['admin_id'] = $_SESSION['sa_original_admin_id'];
                    $_SESSION['user_id'] = $_SESSION['sa_original_admin_id'];
                    $_SESSION['email'] = $_SESSION['sa_original_email'];
                    $_SESSION['admin_email'] = $_SESSION['sa_original_email'];
                    $_SESSION['role'] = $_SESSION['sa_original_role'];
                    $_SESSION['restaurant_id'] = $_SESSION['sa_original_restaurant_id'];
                    $_SESSION['is_super_admin'] = true;
                    
                    // Clear impersonation session vars
                    unset($_SESSION['impersonating_superadmin']);
                    unset($_SESSION['sa_original_admin_id']);
                    unset($_SESSION['sa_original_email']);
                    unset($_SESSION['sa_original_role']);
                    unset($_SESSION['sa_original_restaurant_id']);
                    unset($_SESSION['force_password_change']);
                    
                    // Regenerate session ID
                    session_regenerate_id(true);
                }
                
                header('Location: restaurants.php');
                exit;
            }
        }
    }
}

$pageTitle = 'Restaurant Tenants Management';
require_once __DIR__ . '/includes/header.php';

// Search and Filtering Logic
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$whereClauses = ["1=1"];
if (!empty($search)) {
    $safeSearch = $conn->real_escape_string($search);
    $whereClauses[] = "(r.restaurant_name LIKE '%{$safeSearch}%' OR r.owner_name LIKE '%{$safeSearch}%' OR r.email LIKE '%{$safeSearch}%' OR r.phone LIKE '%{$safeSearch}%' OR r.restaurant_code LIKE '%{$safeSearch}%' OR r.pan_number LIKE '%{$safeSearch}%' OR u.email LIKE '%{$safeSearch}%' OR r.id = '{$safeSearch}')";
}

if (!empty($statusFilter)) {
    $safeStatus = $conn->real_escape_string($statusFilter);
    if (in_array($safeStatus, ['ACTIVE', 'SUSPENDED', 'PENDING', 'INACTIVE'])) {
        $whereClauses[] = "r.status = '{$safeStatus}'";
    } else {
        $whereClauses[] = "r.subscription_status = '{$safeStatus}'";
    }
}

$whereSql = implode(' AND ', $whereClauses);

// Count Total Records for Pagination
$totalRecords = 0;
$countRes = $conn->query("
    SELECT COUNT(DISTINCT r.id) as total 
    FROM restaurants r 
    LEFT JOIN admin_users u ON u.restaurant_id = r.id AND u.is_super_admin = 0 
    WHERE {$whereSql}
");
if ($countRes && $cRow = $countRes->fetch_assoc()) {
    $totalRecords = (int)$cRow['total'];
}
$totalPages = max(1, ceil($totalRecords / $limit));

$query = "
    SELECT r.*, p.name as plan_name,
    u.email as admin_email,
    u.id as admin_user_id,
    (SELECT COUNT(*) FROM tables t WHERE t.restaurant_id = r.id) as table_count,
    (SELECT COUNT(*) FROM orders o WHERE o.restaurant_id = r.id) as order_count,
    (SELECT COUNT(*) FROM admin_users u2 WHERE u2.restaurant_id = r.id) as user_count,
    (SELECT created_at FROM audit_logs a WHERE a.restaurant_id = r.id AND a.event_type = 'STAFF_LOGIN' ORDER BY a.id DESC LIMIT 1) as last_login
    FROM restaurants r
    LEFT JOIN subscription_plans p ON r.subscription_plan_id = p.id
    LEFT JOIN admin_users u ON u.restaurant_id = r.id AND u.is_super_admin = 0
    WHERE {$whereSql}
    GROUP BY r.id
    ORDER BY r.id DESC
    LIMIT {$limit} OFFSET {$offset}
";

$restaurants = [];
$plans = [];
if ($conn) {
    $res = $conn->query($query);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $restaurants[] = $row;
        }
    }

    $pRes = $conn->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY id ASC");
    if ($pRes) {
        while ($p = $pRes->fetch_assoc()) {
            $plans[] = $p;
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
            <p class="text-xs text-zinc-400 mt-1 font-medium">Manage tenant accounts, manual credentials, subscriptions, security status, and support tools.</p>
        </div>
        <a href="create-restaurant.php" class="px-4 py-2.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-xs hover:from-amber-400 hover:to-amber-300 transition-all shadow-lg shadow-amber-500/20 inline-flex items-center space-x-1.5 self-start sm:self-auto">
            <span>+ Create Restaurant Account</span>
        </a>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>✅ <?= htmlspecialchars($message) ?></div>
            <?php if ($resetResult): ?>
                <div class="font-mono text-xs text-white">
                    Login Email: <strong class="text-amber-400 select-all"><?= htmlspecialchars($resetResult['email']) ?></strong> | 
                    Password: <strong class="text-amber-400 select-all"><?= htmlspecialchars($resetResult['password']) ?></strong>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">
            ⚠️ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Search & Filter Controls -->
    <form method="GET" class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800 flex flex-col md:flex-row items-center gap-4 shadow-xl">
        <div class="flex-1 w-full">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by restaurant name, code, tenant ID, owner, email, phone, PAN..." class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 transition-colors">
        </div>
        <div class="w-full md:w-48">
            <select name="status" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                <option value="">All Statuses</option>
                <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                <option value="TRIAL" <?= $statusFilter === 'TRIAL' ? 'selected' : '' ?>>Trial</option>
                <option value="SUSPENDED" <?= $statusFilter === 'SUSPENDED' ? 'selected' : '' ?>>Suspended</option>
                <option value="EXPIRED" <?= $statusFilter === 'EXPIRED' ? 'selected' : '' ?>>Expired</option>
                <option value="PAST_DUE" <?= $statusFilter === 'PAST_DUE' ? 'selected' : '' ?>>Past Due</option>
                <option value="CANCELLED" <?= $statusFilter === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                <option value="INACTIVE" <?= $statusFilter === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="flex items-center space-x-2 w-full md:w-auto">
            <button type="submit" class="h-10 px-5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all shadow-md">
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
            <table class="w-full text-left border-collapse min-w-[850px]">
                <thead>
                    <tr class="border-b border-zinc-800 bg-zinc-950/60 text-[11px] font-black uppercase text-zinc-400 tracking-wider">
                        <th class="py-3.5 px-4">Restaurant & Code</th>
                        <th class="py-3.5 px-4">Admin Email & Contact</th>
                        <th class="py-3.5 px-4">Plan & Subscription</th>
                        <th class="py-3.5 px-4">Created / Last Login</th>
                        <th class="py-3.5 px-4">Status</th>
                        <th class="py-3.5 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60 text-xs">
                    <?php if (empty($restaurants)): ?>
                        <tr>
                            <td colspan="6" class="py-12 text-center space-y-3">
                                <div class="text-4xl">🏪</div>
                                <div class="text-sm font-bold text-white">No restaurants found.</div>
                                <p class="text-xs text-zinc-500">No restaurant tenant accounts match your current search and filter criteria.</p>
                                <?php if (!empty($search) || !empty($statusFilter)): ?>
                                    <a href="restaurants.php" class="inline-block px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-amber-400 hover:bg-zinc-700">Clear Search Filters</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($restaurants as $r): ?>
                            <?php
                            // Expiry Status Calculation
                            $isExpired = false;
                            $isExpiringSoon = false;
                            if ($r['subscription_status'] === 'EXPIRED') {
                                $isExpired = true;
                            } elseif (!empty($r['subscription_end'])) {
                                $endTs = strtotime($r['subscription_end']);
                                $nowTs = time();
                                if ($endTs < $nowTs) {
                                    $isExpired = true;
                                } elseif ($endTs <= ($nowTs + (14 * 86400))) {
                                    $isExpiringSoon = true;
                                }
                            }
                            ?>
                            <tr class="hover:bg-zinc-800/30 transition-colors">
                                <td class="py-4 px-4">
                                    <div class="flex items-center space-x-2">
                                        <div class="font-bold text-white text-sm"><?= htmlspecialchars($r['restaurant_name'] ?: 'N/A') ?></div>
                                        <?php if ($r['id'] == 1): ?>
                                            <span class="px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[10px] font-black uppercase tracking-wider">
                                                🧪 INTERNAL TEST TENANT
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[10px] text-zinc-400 font-mono mt-0.5">
                                        Tenant ID: #<?= $r['id'] ?> &bull; Code: <strong class="text-amber-400"><?= htmlspecialchars($r['restaurant_code'] ?: 'N/A') ?></strong>
                                    </div>
                                    <div class="text-[10px] text-zinc-500 mt-0.5"><?= htmlspecialchars($r['restaurant_type'] ?: 'N/A') ?> &bull; PAN: <?= htmlspecialchars($r['pan_number'] ?: 'N/A') ?></div>
                                </td>

                                <td class="py-4 px-4">
                                    <!-- Email action triggers Account Details Modal -->
                                    <button type="button" onclick="openAccountModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['restaurant_code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['admin_email'] ?: 'Unassigned', ENT_QUOTES) ?>', '<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['phone'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['status'], ENT_QUOTES) ?>', '<?= date('M d, Y', strtotime($r['created_at'])) ?>', '<?= !empty($r['last_login']) ? date('M d, Y H:i', strtotime($r['last_login'])) : 'Never' ?>')" class="font-mono font-bold text-amber-400 text-xs hover:underline cursor-pointer inline-flex items-center space-x-1">
                                        <span>✉️</span>
                                        <span><?= htmlspecialchars($r['admin_email'] ?: 'N/A') ?></span>
                                    </button>
                                    <div class="font-semibold text-zinc-200 text-[11px] mt-0.5"><?= htmlspecialchars($r['owner_name'] ?: 'N/A') ?></div>
                                    <div class="text-zinc-400 text-[11px]"><?= htmlspecialchars($r['email'] ?: 'N/A') ?> &bull; <?= htmlspecialchars($r['phone'] ?: 'N/A') ?></div>
                                </td>

                                <td class="py-4 px-4">
                                    <div class="flex items-center space-x-2">
                                        <span class="inline-block px-2.5 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px] font-black uppercase">
                                            <?= $r['id'] == 1 ? 'Unlimited Test Plan' : htmlspecialchars($r['plan_name'] ?? 'Starter') ?>
                                        </span>
                                        <?php if ($isExpired && $r['id'] != 1): ?>
                                            <span class="px-2 py-0.5 rounded-md bg-rose-500/10 text-rose-400 border border-rose-500/20 text-[9px] font-black uppercase">EXPIRED</span>
                                        <?php elseif ($isExpiringSoon && $r['id'] != 1): ?>
                                            <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[9px] font-black uppercase">EXPIRING SOON</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[10px] text-zinc-400 mt-1">
                                        Status: <strong class="text-zinc-200"><?= htmlspecialchars($r['subscription_status'] ?: 'ACTIVE') ?></strong>
                                    </div>
                                    <div class="text-[10px] text-zinc-500">
                                        Expires: <?= $r['id'] == 1 ? 'Unlimited Test Access' : (!empty($r['subscription_end']) ? date('M d, Y', strtotime($r['subscription_end'])) : 'Infinite') ?>
                                    </div>
                                </td>

                                <td class="py-4 px-4">
                                    <div class="text-[11px] text-zinc-300 font-medium">Created: <?= date('M d, Y', strtotime($r['created_at'])) ?></div>
                                    <div class="text-[10px] text-zinc-500 mt-0.5">
                                        Last Login: <?= !empty($r['last_login']) ? date('M d, Y H:i', strtotime($r['last_login'])) : 'Never' ?>
                                    </div>
                                </td>

                                <td class="py-4 px-4">
                                    <?php if ($r['status'] === 'ACTIVE'): ?>
                                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">ACTIVE</span>
                                    <?php elseif ($r['status'] === 'SUSPENDED'): ?>
                                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-rose-500/10 text-rose-400 border border-rose-500/20">SUSPENDED</span>
                                    <?php elseif ($r['status'] === 'INACTIVE'): ?>
                                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-zinc-800 text-zinc-400 border border-zinc-700">INACTIVE</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-amber-500/10 text-amber-400 border border-amber-500/20"><?= htmlspecialchars($r['status']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-4 px-4 text-right">
                                    <div class="flex items-center justify-end space-x-1.5">
                                        <?php if ($r['id'] == 1): ?>
                                            <!-- Open Internal Test Environment for Super Admin -->
                                            <form method="POST" class="inline">
                                                <?= $csrfField ?>
                                                <input type="hidden" name="action" value="impersonate">
                                                <input type="hidden" name="restaurant_id" value="1">
                                                <button type="submit" title="Open Internal Test Environment for Super Admin Testing" class="px-3 py-1.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-[11px] hover:from-amber-400 hover:to-amber-300 transition-all shadow-md inline-flex items-center space-x-1">
                                                    <span>🧪 Open Test Environment</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Manage Modal Trigger Button -->
                                        <button type="button" onclick="openManageModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['restaurant_code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['owner_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['phone'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['pan_number'] ?: 'N/A', ENT_QUOTES) ?>', '<?= htmlspecialchars($r['address'] ?: 'N/A', ENT_QUOTES) ?>', '<?= htmlspecialchars($r['restaurant_type'], ENT_QUOTES) ?>', <?= (int)$r['subscription_plan_id'] ?>, '<?= htmlspecialchars($r['plan_name'] ?? 'Starter', ENT_QUOTES) ?>', '<?= htmlspecialchars($r['subscription_status'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['subscription_start'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($r['subscription_end'] ?? '', ENT_QUOTES) ?>', <?= (int)$r['table_count'] ?>, <?= (int)$r['order_count'] ?>, <?= (int)$r['user_count'] ?>, '<?= date('M d, Y', strtotime($r['created_at'])) ?>', '<?= !empty($r['last_login']) ? date('M d, Y H:i', strtotime($r['last_login'])) : 'Never' ?>')" title="Manage Tenant Details & Subscription" class="px-2.5 py-1.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-[11px] hover:bg-amber-400 transition-all shadow-sm">
                                            ⚙️ Manage
                                        </button>

                                        <!-- Reset Password Modal Trigger Button -->
                                        <button type="button" onclick="openResetModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['admin_email'] ?: 'Admin', ENT_QUOTES) ?>')" title="Reset Administrator Password" class="p-2 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-amber-400 text-xs font-bold transition-all">
                                            🔑 Reset Pass
                                        </button>

                                        <!-- Support Modal Trigger Button -->
                                        <button type="button" onclick="openSupportModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['owner_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['phone'], ENT_QUOTES) ?>')" title="Tenant Support & Impersonation" class="p-2 rounded-xl bg-purple-500/10 border border-purple-500/30 text-purple-400 hover:bg-purple-500/20 text-xs font-bold transition-all">
                                            👁️ Support
                                        </button>

                                        <!-- Status Toggle Controls (Suspend / Activate) -->
                                        <?php if ($r['status'] === 'ACTIVE'): ?>
                                            <button type="button" onclick="openSuspendModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>')" class="px-2.5 py-1.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 hover:bg-rose-500/20 text-[11px] font-bold transition-all">
                                                Suspend
                                            </button>
                                        <?php else: ?>
                                            <button type="button" onclick="openActivateModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>')" class="px-2.5 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20 text-[11px] font-bold transition-all">
                                                Activate
                                            </button>
                                        <?php endif; ?>

                                        <!-- Permanently Delete Tenant Trigger Button -->
                                        <button type="button" onclick="openDeleteModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>')" title="Permanently Delete Tenant & All Data" class="px-2.5 py-1.5 rounded-xl bg-rose-500 text-white font-black text-[11px] hover:bg-rose-600 transition-all shadow-sm">
                                            🗑️ Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 border-t border-zinc-800 bg-zinc-950/60 flex items-center justify-between text-xs text-zinc-400">
                <div>
                    Showing <strong class="text-white"><?= min($totalRecords, $offset + 1) ?></strong> to <strong class="text-white"><?= min($totalRecords, $offset + count($restaurants)) ?></strong> of <strong class="text-white"><?= number_format($totalRecords) ?></strong> restaurants
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

<!-- MODAL 1: ACCOUNT DETAILS & LOGIN EMAIL VIEW -->
<div id="username-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-black text-white">Administrator Account Details</h3>
            <button onclick="closeUsernameModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>
        <div class="space-y-3 text-xs">
            <div>
                <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Restaurant</span>
                <span id="u-modal-rest" class="text-white font-bold text-sm block"></span>
                <span id="u-modal-code" class="text-amber-400 font-mono text-xs font-bold block"></span>
            </div>
            <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
                <div class="flex justify-between">
                    <span class="text-zinc-400">Login Email:</span>
                    <strong id="u-modal-user" class="text-amber-400 font-mono select-all"></strong>
                </div>
                <div class="flex justify-between">
                    <span class="text-zinc-400">Owner Email:</span>
                    <strong id="u-modal-email" class="text-zinc-200"></strong>
                </div>
                <div class="flex justify-between">
                    <span class="text-zinc-400">Phone:</span>
                    <strong id="u-modal-phone" class="text-zinc-200"></strong>
                </div>
                <div class="flex justify-between">
                    <span class="text-zinc-400">Account Status:</span>
                    <strong id="u-modal-status" class="text-emerald-400"></strong>
                </div>
                <div class="flex justify-between">
                    <span class="text-zinc-400">Last Login:</span>
                    <strong id="u-modal-login" class="text-zinc-300"></strong>
                </div>
            </div>
            <form method="POST" class="p-3 rounded-xl bg-zinc-950 border border-zinc-800 space-y-2">
                <?= $csrfField ?>
                <input type="hidden" name="action" value="change_email">
                <input type="hidden" name="restaurant_id" id="u-modal-restid">
                <span class="text-zinc-400 block text-[10px] font-bold uppercase tracking-wider">Update Login Email</span>
                <div class="flex gap-2">
                    <input type="email" name="new_email" id="u-modal-newemail" placeholder="admin@restaurant.com" class="flex-1 h-9 bg-zinc-900 border border-zinc-800 rounded-lg px-2.5 text-white font-mono text-[11px] outline-none focus:border-amber-500">
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-500 text-zinc-950 font-black text-[10px] hover:bg-amber-400">Update</button>
                </div>
            </form>
            <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-[11px] text-amber-300 font-medium">
                🔒 Plaintext passwords are never stored or displayed for security compliance.
            </div>
        </div>
        <div class="flex items-center justify-between pt-2 border-t border-zinc-800">
            <button type="button" onclick="closeUsernameModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Close</button>
            <button type="button" onclick="switchToResetFromDetails()" class="px-5 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400">🔑 Reset Password →</button>
        </div>
    </div>
</div>

<!-- MODAL 2: RESET PASSWORD -->
<div id="reset-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-black text-white">Reset Administrator Password</h3>
            <button onclick="closeResetModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= $csrfField ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="restaurant_id" id="reset-rest-id">

            <div>
                <label class="block text-xs font-bold text-zinc-400 mb-1">Target Account</label>
                <div id="reset-rest-name" class="text-sm font-bold text-amber-400"></div>
                <div id="reset-user-name" class="text-xs font-mono text-zinc-400"></div>
            </div>

            <div>
                <label class="block text-xs font-bold text-zinc-400 mb-1">New Password *</label>
                <input type="password" name="new_password" required minlength="8" placeholder="••••••••••••" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-zinc-400 mb-1">Confirm Password *</label>
                <input type="password" name="confirm_password" required minlength="8" placeholder="••••••••••••" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closeResetModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: SUSPEND CONFIRMATION -->
<div id="suspend-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-rose-500/30 rounded-3xl p-6 max-w-md w-full space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-black text-white">Suspend Restaurant Account?</h3>
            <button onclick="closeSuspendModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= $csrfField ?>
            <input type="hidden" name="action" value="suspend">
            <input type="hidden" name="restaurant_id" id="suspend-rest-id">

            <div>
                <span class="text-xs text-zinc-400 block font-semibold">Target Restaurant:</span>
                <div id="suspend-rest-name" class="text-base font-black text-white mt-0.5"></div>
            </div>

            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-xs text-rose-300 leading-relaxed font-medium">
                ⚠️ <strong>Warning:</strong> The restaurant administrator and staff will no longer be able to access this RMS portal account. All existing orders and historical data will remain intact.
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closeSuspendModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-rose-500 text-white font-black text-xs hover:bg-rose-600 shadow-lg shadow-rose-500/20">Suspend Restaurant</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 4: ACTIVATE CONFIRMATION -->
<div id="activate-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-emerald-500/30 rounded-3xl p-6 max-w-md w-full space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-black text-white">Activate Restaurant Tenant</h3>
            <button onclick="closeActivateModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= $csrfField ?>
            <input type="hidden" name="action" value="activate">
            <input type="hidden" name="restaurant_id" id="activate-rest-id">

            <div>
                <span class="text-xs text-zinc-400 block font-semibold">Target Restaurant:</span>
                <div id="activate-rest-name" class="text-base font-black text-white mt-0.5"></div>
            </div>

            <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-xs text-emerald-300 leading-relaxed font-medium">
                ✓ This will restore administrator and staff access to their dedicated RMS workspace.
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closeActivateModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-emerald-500 text-zinc-950 font-black text-xs hover:bg-emerald-400 shadow-lg shadow-emerald-500/20">Activate Restaurant</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 5: MANAGE TENANT DETAILS & SUBSCRIPTION -->
<div id="manage-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-2xl w-full space-y-6 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <div>
                <h3 class="text-base font-black text-white">Manage Restaurant Tenant</h3>
                <p id="m-rest-subtitle" class="text-xs text-amber-400 font-mono font-bold"></p>
            </div>
            <button onclick="closeManageModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>

        <form method="POST" class="space-y-6">
            <?= $csrfField ?>
            <input type="hidden" name="action" value="edit_restaurant">
            <input type="hidden" name="restaurant_id" id="m-rest-id">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Restaurant Name *</label>
                    <input type="text" name="restaurant_name" id="m-rest-name" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Owner Name *</label>
                    <input type="text" name="owner_name" id="m-owner-name" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Owner Email *</label>
                    <input type="email" name="email" id="m-email" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Phone Number *</label>
                    <input type="text" name="phone" id="m-phone" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">PAN / VAT Number</label>
                    <input type="text" name="pan_number" id="m-pan" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Subscription Plan *</label>
                    <select name="plan_id" id="m-plan-id" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                        <?php foreach ($plans as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (NPR <?= number_format($p['price_monthly']) ?>/mo)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Account Status *</label>
                    <select name="status" id="m-status" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                        <option value="ACTIVE">ACTIVE</option>
                        <option value="SUSPENDED">SUSPENDED</option>
                        <option value="INACTIVE">INACTIVE</option>
                        <option value="PENDING">PENDING</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Subscription Status *</label>
                    <select name="subscription_status" id="m-sub-status" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                        <option value="ACTIVE">ACTIVE</option>
                        <option value="TRIAL">TRIAL</option>
                        <option value="PAST_DUE">PAST_DUE</option>
                        <option value="EXPIRED">EXPIRED</option>
                        <option value="SUSPENDED">SUSPENDED</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block font-bold text-zinc-400 mb-1">Subscription Expiry Date</label>
                    <input type="date" name="subscription_end" id="m-sub-end" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
            </div>

            <!-- Realtime Metrics Grid inside Manage Modal -->
            <div class="grid grid-cols-3 gap-3 bg-zinc-950 p-4 rounded-2xl border border-zinc-800 text-center text-xs">
                <div>
                    <span class="text-zinc-500 uppercase tracking-wider text-[10px] font-bold block">Total Tables</span>
                    <strong id="m-stat-tables" class="text-white text-base block mt-0.5">0</strong>
                </div>
                <div>
                    <span class="text-zinc-500 uppercase tracking-wider text-[10px] font-bold block">Total Orders</span>
                    <strong id="m-stat-orders" class="text-amber-400 text-base block mt-0.5">0</strong>
                </div>
                <div>
                    <span class="text-zinc-500 uppercase tracking-wider text-[10px] font-bold block">Staff Users</span>
                    <strong id="m-stat-users" class="text-emerald-400 text-base block mt-0.5">0</strong>
                </div>
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closeManageModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400">Save Tenant Changes →</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 6: SUPPORT & IMPERSONATION -->
<div id="support-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-purple-500/30 rounded-3xl p-6 max-w-md w-full space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-black text-white">Tenant Support & Impersonation</h3>
            <button onclick="closeSupportModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>
        <div class="space-y-3 text-xs">
            <div>
                <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Restaurant Account</span>
                <div id="sup-rest-name" class="text-white font-bold text-base"></div>
            </div>
            <div class="p-3 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-1.5">
                <div>Owner: <strong id="sup-owner" class="text-zinc-200"></strong></div>
                <div>Email: <strong id="sup-email" class="text-zinc-200"></strong></div>
                <div>Phone: <strong id="sup-phone" class="text-amber-400 font-mono"></strong></div>
            </div>
            <div class="p-3 rounded-xl bg-purple-500/10 border border-purple-500/20 text-[11px] text-purple-300 leading-relaxed font-medium">
                👁️ <strong>Support Impersonation:</strong> This action initiates an audited support session inside the tenant's RMS portal. All impersonation actions are recorded in the security audit logs.
            </div>
        </div>
        <form method="POST" class="pt-2 border-t border-zinc-800 flex items-center justify-end space-x-2">
            <?= $csrfField ?>
            <input type="hidden" name="action" value="impersonate">
            <input type="hidden" name="restaurant_id" id="sup-rest-id">
            <button type="button" onclick="closeSupportModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
            <button type="submit" class="px-5 py-2 rounded-xl bg-purple-500 text-white font-black text-xs hover:bg-purple-600 shadow-lg shadow-purple-500/20">Initiate Impersonation →</button>
        </form>
    </div>
</div>

<!-- MODAL 7: PERMANENT DELETE CONFIRMATION -->
<div id="delete-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-rose-500/30 rounded-3xl p-6 max-w-md w-full space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-black text-rose-400">Permanently Delete Restaurant?</h3>
            <button onclick="closeDeleteModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= $csrfField ?>
            <input type="hidden" name="action" value="delete_restaurant">
            <input type="hidden" name="restaurant_id" id="delete-rest-id">

            <div>
                <span class="text-xs text-zinc-400 block font-semibold">Target Restaurant:</span>
                <div id="delete-rest-name" class="text-base font-black text-white mt-0.5"></div>
            </div>

            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-xs text-rose-300 leading-relaxed font-medium">
                🗑️ <strong>This action is irreversible.</strong> The restaurant tenant, all staff accounts, orders, tables, menu items, inventory records, subscriptions, and audit history will be permanently deleted. Type <strong class="font-mono text-white" id="delete-confirm-label"></strong> to confirm.
            </div>

            <input type="text" id="delete-confirm-input" autocomplete="off" placeholder="Type restaurant name to confirm..." oninput="toggleDeleteConfirm()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white placeholder-zinc-500 outline-none focus:border-rose-500">

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" id="delete-submit-btn" disabled class="px-5 py-2 rounded-xl bg-rose-500 text-white font-black text-xs hover:bg-rose-600 opacity-40 cursor-not-allowed">🗑️ Delete Permanently</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Modal 1: Account Details & Login Email
    function openAccountModal(restId, restName, restCode, adminEmail, email, phone, status, createdAt, lastLogin) {
        document.getElementById('u-modal-rest').innerText = restName;
        document.getElementById('u-modal-code').innerText = restCode;
        document.getElementById('u-modal-user').innerText = adminEmail;
        document.getElementById('u-modal-email').innerText = email;
        document.getElementById('u-modal-phone').innerText = phone;
        document.getElementById('u-modal-status').innerText = status;
        document.getElementById('u-modal-login').innerText = lastLogin;
        document.getElementById('u-modal-restid').value = restId;
        document.getElementById('u-modal-newemail').value = adminEmail;
        
        window.currentSelectedRest = { id: restId, name: restName, username: adminEmail };
        document.getElementById('username-modal').classList.remove('hidden');
    }
    function closeUsernameModal() {
        document.getElementById('username-modal').classList.add('hidden');
    }
    function switchToResetFromDetails() {
        closeUsernameModal();
        if (window.currentSelectedRest) {
            openResetModal(window.currentSelectedRest.id, window.currentSelectedRest.name, window.currentSelectedRest.username);
        }
    }

    // Modal 2: Reset Password
    function openResetModal(restId, restName, username) {
        document.getElementById('reset-rest-id').value = restId;
        document.getElementById('reset-rest-name').innerText = restName;
        document.getElementById('reset-user-name').innerText = 'Login Email: ' + username;
        document.getElementById('reset-modal').classList.remove('hidden');
    }
    function closeResetModal() {
        document.getElementById('reset-modal').classList.add('hidden');
    }

    // Modal 3: Suspend Confirmation
    function openSuspendModal(restId, restName) {
        document.getElementById('suspend-rest-id').value = restId;
        document.getElementById('suspend-rest-name').innerText = restName;
        document.getElementById('suspend-modal').classList.remove('hidden');
    }
    function closeSuspendModal() {
        document.getElementById('suspend-modal').classList.add('hidden');
    }

    // Modal 4: Activate Confirmation
    function openActivateModal(restId, restName) {
        document.getElementById('activate-rest-id').value = restId;
        document.getElementById('activate-rest-name').innerText = restName;
        document.getElementById('activate-modal').classList.remove('hidden');
    }
    function closeActivateModal() {
        document.getElementById('activate-modal').classList.add('hidden');
    }

    // Modal 5: Manage Tenant
    function openManageModal(restId, restName, restCode, owner, email, phone, pan, address, restType, planId, planName, subStatus, subStart, subEnd, tables, orders, users, createdAt, lastLogin) {
        document.getElementById('m-rest-id').value = restId;
        document.getElementById('m-rest-subtitle').innerText = restName + ' (' + restCode + ')';
        document.getElementById('m-rest-name').value = restName;
        document.getElementById('m-owner-name').value = owner;
        document.getElementById('m-email').value = email;
        document.getElementById('m-phone').value = phone;
        document.getElementById('m-pan').value = (pan === 'N/A') ? '' : pan;
        document.getElementById('m-plan-id').value = planId;
        document.getElementById('m-sub-status').value = subStatus;
        document.getElementById('m-sub-end').value = subEnd;
        
        document.getElementById('m-stat-tables').innerText = tables;
        document.getElementById('m-stat-orders').innerText = orders;
        document.getElementById('m-stat-users').innerText = users;

        document.getElementById('manage-modal').classList.remove('hidden');
    }
    function closeManageModal() {
        document.getElementById('manage-modal').classList.add('hidden');
    }

    // Modal 6: Support Modal
    function openSupportModal(restId, restName, owner, email, phone) {
        document.getElementById('sup-rest-id').value = restId;
        document.getElementById('sup-rest-name').innerText = restName;
        document.getElementById('sup-owner').innerText = owner;
        document.getElementById('sup-email').innerText = email;
        document.getElementById('sup-phone').innerText = phone;
        document.getElementById('support-modal').classList.remove('hidden');
    }
    function closeSupportModal() {
        document.getElementById('support-modal').classList.add('hidden');
    }

    // Modal 7: Permanently Delete Confirmation
    let deleteConfirmTarget = '';
    function openDeleteModal(restId, restName) {
        document.getElementById('delete-rest-id').value = restId;
        document.getElementById('delete-rest-name').innerText = restName;
        document.getElementById('delete-confirm-label').innerText = '"' + restName + '"';
        document.getElementById('delete-confirm-input').value = '';
        deleteConfirmTarget = restName;
        document.getElementById('delete-submit-btn').disabled = true;
        document.getElementById('delete-submit-btn').classList.add('opacity-40', 'cursor-not-allowed');
        document.getElementById('delete-modal').classList.remove('hidden');
    }
    function closeDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
    }
    function toggleDeleteConfirm() {
        const inputVal = document.getElementById('delete-confirm-input').value.trim();
        const btn = document.getElementById('delete-submit-btn');
        const matches = (inputVal === deleteConfirmTarget);
        btn.disabled = !matches;
        if (matches) {
            btn.classList.remove('opacity-40', 'cursor-not-allowed');
        } else {
            btn.classList.add('opacity-40', 'cursor-not-allowed');
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
