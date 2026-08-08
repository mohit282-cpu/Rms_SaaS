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

                        // Get username for display
                        $uRes = $conn->query("SELECT username FROM admin_users WHERE restaurant_id = {$restId} AND is_super_admin = 0 LIMIT 1");
                        $uName = ($uRes && $u = $uRes->fetch_assoc()) ? $u['username'] : 'Admin';

                        Security::logAudit("SUPER_ADMIN_RESET_PASSWORD", "Super Admin reset password for restaurant ID: {$restId} (Admin User: {$uName})");
                        $resetResult = [
                            'username' => $uName,
                            'password' => $newPass
                        ];
                        $message = "Administrator password updated successfully.";
                    }
                }
            } elseif ($action === 'change_username') {
                $rawUsername = trim($_POST['new_username'] ?? '');
                $newUsername = strtolower(Security::sanitize($rawUsername));

                if (empty($newUsername) || !preg_match('/^[a-zA-Z0-9_]{4,30}$/', $newUsername)) {
                    $error = "Username must be between 4 and 30 characters and contain only letters, numbers, or underscores.";
                } else {
                    // Check duplicate username
                    $checkUser = $conn->prepare("SELECT id FROM admin_users WHERE username = ? AND restaurant_id != ? LIMIT 1");
                    $checkUser->bind_param("si", $newUsername, $restId);
                    $checkUser->execute();
                    if ($checkUser->get_result()->num_rows > 0) {
                        $error = "Username already exists. Please choose another username.";
                        $checkUser->close();
                    } else {
                        $checkUser->close();

                        $stmt = $conn->prepare("UPDATE admin_users SET username = ? WHERE restaurant_id = ? AND is_super_admin = 0 ORDER BY id ASC LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param("si", $newUsername, $restId);
                            $stmt->execute();
                            $stmt->close();
                        }

                        Security::logAudit("SUPER_ADMIN_CHANGE_USERNAME", "Super Admin changed username to {$newUsername} for restaurant ID: {$restId}");
                        $message = "Administrator username updated successfully to '{$newUsername}'.";
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

                $stmt = $conn->prepare("UPDATE restaurants SET restaurant_name = ?, owner_name = ?, email = ?, phone = ?, pan_number = ?, subscription_plan_id = ?, status = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("sssssisi", $restName, $ownerName, $email, $phone, $panNumber, $planId, $status, $restId);
                    $stmt->execute();
                    $stmt->close();
                }

                Security::logAudit("SUPER_ADMIN_EDIT_TENANT", "Super Admin updated details for restaurant ID: {$restId}");
                $message = "Restaurant details updated successfully.";
            } elseif ($action === 'impersonate') {
                Security::logAudit("SUPER_ADMIN_IMPERSONATE_TENANT", "Super Admin (" . $_SESSION['username'] . ") initiated support impersonation session for restaurant ID: {$restId}");
                
                $_SESSION['impersonating_superadmin'] = $_SESSION['admin_id'];
                $_SESSION['sa_restaurant_id'] = isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : 1;
                $_SESSION['restaurant_id'] = $restId;
                
                $ownerRes = $conn->query("SELECT id, username, full_name, role FROM admin_users WHERE restaurant_id = {$restId} ORDER BY id ASC LIMIT 1");
                if ($ownerRes && $owner = $ownerRes->fetch_assoc()) {
                    $_SESSION['admin_id'] = $owner['id'];
                    $_SESSION['role'] = strtoupper($owner['role']);
                }

                header('Location: ../admin/index.php');
                exit;
            }
        }
    }
}

$pageTitle = 'Restaurant Tenants Management';
require_once __DIR__ . '/includes/header.php';

// Search and Filtering
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$whereClauses = ["1=1"];
if (!empty($search)) {
    $safeSearch = $conn->real_escape_string($search);
    $whereClauses[] = "(r.restaurant_name LIKE '%{$safeSearch}%' OR r.owner_name LIKE '%{$safeSearch}%' OR r.email LIKE '%{$safeSearch}%' OR r.phone LIKE '%{$safeSearch}%' OR r.restaurant_code LIKE '%{$safeSearch}%' OR u.username LIKE '%{$safeSearch}%')";
}
if (!empty($statusFilter)) {
    $safeStatus = $conn->real_escape_string($statusFilter);
    $whereClauses[] = "r.status = '{$safeStatus}'";
}

$whereSql = implode(' AND ', $whereClauses);
$query = "
    SELECT r.*, p.name as plan_name,
    u.username as admin_username,
    u.id as admin_user_id,
    (SELECT COUNT(*) FROM tables t WHERE t.restaurant_id = r.id) as table_count,
    (SELECT COUNT(*) FROM orders o WHERE o.restaurant_id = r.id) as order_count,
    (SELECT created_at FROM audit_logs a WHERE a.restaurant_id = r.id AND a.event_type = 'STAFF_LOGIN' ORDER BY a.id DESC LIMIT 1) as last_login
    FROM restaurants r
    LEFT JOIN subscription_plans p ON r.subscription_plan_id = p.id
    LEFT JOIN admin_users u ON u.restaurant_id = r.id AND u.is_super_admin = 0
    WHERE {$whereSql}
    GROUP BY r.id
    ORDER BY r.id DESC
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
            <p class="text-xs text-zinc-400 mt-1 font-medium">Provision accounts, assign manual credentials, reset passwords, and manage tenant subscriptions.</p>
        </div>
        <a href="create-restaurant.php" class="px-4 py-2.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-xs hover:from-amber-400 hover:to-amber-300 transition-all shadow-lg shadow-amber-500/20 inline-flex items-center space-x-1.5 self-start sm:self-auto">
            <span>+ Create Restaurant Account</span>
        </a>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold flex items-center justify-between">
            <div>✅ <?= htmlspecialchars($message) ?></div>
            <?php if ($resetResult): ?>
                <div class="font-mono text-xs text-white">
                    Username: <strong class="text-amber-400 select-all"><?= htmlspecialchars($resetResult['username']) ?></strong> | 
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
    <form method="GET" class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800 flex flex-col md:flex-row items-center gap-4">
        <div class="flex-1 w-full">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by restaurant name, owner, username, email, phone, code..." class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 transition-colors">
        </div>
        <div class="w-full md:w-48">
            <select name="status" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                <option value="">All Statuses</option>
                <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                <option value="SUSPENDED" <?= $statusFilter === 'SUSPENDED' ? 'selected' : '' ?>>SUSPENDED</option>
                <option value="PENDING" <?= $statusFilter === 'PENDING' ? 'selected' : '' ?>>PENDING</option>
                <option value="INACTIVE" <?= $statusFilter === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option>
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
                        <th class="py-3.5 px-4">Restaurant & Code</th>
                        <th class="py-3.5 px-4">Admin Username & Contact</th>
                        <th class="py-3.5 px-4">Plan & Subscription</th>
                        <th class="py-3.5 px-4">Created / Last Login</th>
                        <th class="py-3.5 px-4">Status</th>
                        <th class="py-3.5 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60 text-xs">
                    <?php if (empty($restaurants)): ?>
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500 font-semibold">
                                No restaurant accounts found matching search criteria.
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
                                    <div class="font-mono font-bold text-amber-400 text-xs">
                                        👤 <?= htmlspecialchars($r['admin_username'] ?: 'Unassigned') ?>
                                    </div>
                                    <div class="font-semibold text-zinc-200 text-[11px] mt-0.5"><?= htmlspecialchars($r['owner_name']) ?></div>
                                    <div class="text-zinc-400 text-[11px]"><?= htmlspecialchars($r['email']) ?> &bull; <?= htmlspecialchars($r['phone']) ?></div>
                                </td>

                                <td class="py-4 px-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px] font-black uppercase">
                                        <?= htmlspecialchars($r['plan_name'] ?? 'Starter') ?>
                                    </span>
                                    <div class="text-[10px] text-zinc-400 mt-1">
                                        Sub: <strong class="text-zinc-200"><?= htmlspecialchars($r['subscription_status']) ?></strong>
                                    </div>
                                    <div class="text-[10px] text-zinc-500">
                                        Tables: <?= number_format($r['table_count']) ?>
                                    </div>
                                </td>

                                <td class="py-4 px-4">
                                    <div class="text-[11px] text-zinc-300 font-medium">Created: <?= date('M d, Y', strtotime($r['created_at'])) ?></div>
                                    <div class="text-[10px] text-zinc-500 mt-0.5">
                                        Last Login: <?= !empty($r['last_login']) ? date('M d, H:i', strtotime($r['last_login'])) : 'Never' ?>
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
                                        <!-- Edit Username Modal Trigger Button -->
                                        <button type="button" onclick="openUsernameModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['admin_username'], ENT_QUOTES) ?>')" title="Change Admin Username" class="p-2 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs font-bold transition-all">
                                            👤 Username
                                        </button>

                                        <!-- Reset Password Modal Trigger Button -->
                                        <button type="button" onclick="openResetModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['admin_username'], ENT_QUOTES) ?>')" title="Reset Password Manually" class="p-2 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 text-xs font-bold transition-all">
                                            🔑 Reset Pass
                                        </button>

                                        <!-- Impersonate Support Login -->
                                        <form method="POST" class="inline" onsubmit="return confirm('Initiate Super Admin support impersonation session for <?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>? All actions will be logged.');">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="action" value="impersonate">
                                            <input type="hidden" name="restaurant_id" value="<?= $r['id'] ?>">
                                            <button type="submit" title="Login as Restaurant (Support Impersonation)" class="p-2 rounded-xl bg-purple-500/10 border border-purple-500/30 text-purple-400 hover:bg-purple-500/20 text-xs font-bold transition-all">
                                                👁️ Support
                                            </button>
                                        </form>

                                        <!-- Status Toggle Controls -->
                                        <?php if ($r['status'] === 'ACTIVE'): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Suspend restaurant <?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>?');">
                                                <?= $csrfField ?>
                                                <input type="hidden" name="action" value="suspend">
                                                <input type="hidden" name="restaurant_id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 hover:bg-rose-500/20 text-[11px] font-bold transition-all">
                                                    Suspend
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" class="inline">
                                                <?= $csrfField ?>
                                                <input type="hidden" name="action" value="activate">
                                                <input type="hidden" name="restaurant_id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20 text-[11px] font-bold transition-all">
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

<!-- MODAL: MANUAL PASSWORD RESET -->
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
                <label class="block text-xs font-bold text-zinc-400 mb-1">Confirm New Password *</label>
                <input type="password" name="confirm_password" required minlength="8" placeholder="••••••••••••" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closeResetModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400">Reset Password →</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: CHANGE ADMIN USERNAME -->
<div id="username-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-black text-white">Change Admin Username</h3>
            <button onclick="closeUsernameModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= $csrfField ?>
            <input type="hidden" name="action" value="change_username">
            <input type="hidden" name="restaurant_id" id="user-rest-id">

            <div>
                <label class="block text-xs font-bold text-zinc-400 mb-1">New Admin Username *</label>
                <input type="text" name="new_username" id="new-user-field" required placeholder="e.g. royal_admin" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white font-mono outline-none focus:border-amber-500">
                <p class="text-[10px] text-zinc-500 mt-1">Must be 4–30 alphanumeric/underscore characters and unique across all tenants.</p>
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closeUsernameModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400">Update Username →</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openResetModal(restId, restName, username) {
        document.getElementById('reset-rest-id').value = restId;
        document.getElementById('reset-rest-name').innerText = restName;
        document.getElementById('reset-user-name').innerText = 'Username: ' + username;
        document.getElementById('reset-modal').classList.remove('hidden');
    }
    function closeResetModal() {
        document.getElementById('reset-modal').classList.add('hidden');
    }

    function openUsernameModal(restId, currentUsername) {
        document.getElementById('user-rest-id').value = restId;
        document.getElementById('new-user-field').value = currentUsername;
        document.getElementById('username-modal').classList.remove('hidden');
    }
    function closeUsernameModal() {
        document.getElementById('username-modal').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
