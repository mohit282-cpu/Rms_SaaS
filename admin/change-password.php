<?php
// admin/change-password.php - Enterprise Security & Account Profile Settings Center
require_once '../config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) {
    die("Database connection error");
}

$admin_id = (int)($_SESSION['admin_id'] ?? 1);
$tenantId = (int)($_SESSION['restaurant_id'] ?? 1);

// Fetch active user account profile
$userStmt = $conn->prepare("
    SELECT u.id, u.username, u.email, u.full_name, u.role, u.is_super_admin, u.restaurant_id, u.created_at, r.restaurant_name, r.phone as rest_phone, r.status as tenant_status
    FROM admin_users u
    LEFT JOIN restaurants r ON u.restaurant_id = r.id
    WHERE u.id = ? LIMIT 1
");
$userStmt->bind_param("i", $admin_id);
$userStmt->execute();
$currentUser = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$currentUser) {
    die("Account record not found.");
}

// Fetch matching employee record for phone number if available
$empPhone = '';
$empStmt = $conn->prepare("SELECT phone FROM employees WHERE user_id = ? AND restaurant_id = ? LIMIT 1");
if ($empStmt) {
    $empStmt->bind_param("ii", $admin_id, $tenantId);
    $empStmt->execute();
    if ($eRes = $empStmt->get_result()->fetch_assoc()) {
        $empPhone = $eRes['phone'] ?? '';
    }
    $empStmt->close();
}
$displayPhone = !empty($empPhone) ? $empPhone : ($currentUser['rest_phone'] ?? 'N/A');

// Handle Form Submissions (Profile Update, Password Update & KDS PIN Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    CSRF::requireValidToken();

    $action = $_POST['action'];

    if ($action === 'update_profile') {
        $newName = trim($_POST['full_name'] ?? '');
        $newEmail = strtolower(trim($_POST['email'] ?? ''));
        $currentPassword = $_POST['current_password'] ?? '';

        if (empty($newName) || empty($newEmail) || empty($currentPassword)) {
            $_SESSION['error'] = 'Name, email address, and current password are required.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Invalid email address format.';
        } else {
            // Verify current password first
            $vStmt = $conn->prepare("SELECT password FROM admin_users WHERE id = ? LIMIT 1");
            $vStmt->bind_param("i", $admin_id);
            $vStmt->execute();
            $vRes = $vStmt->get_result()->fetch_assoc();
            $vStmt->close();

            if (!$vRes || !password_verify($currentPassword, $vRes['password'])) {
                $_SESSION['error'] = 'Current password verification failed.';
            } else {
                // Check email uniqueness across users
                $dupStmt = $conn->prepare("SELECT id FROM admin_users WHERE LOWER(email) = ? AND id != ? LIMIT 1");
                $dupStmt->bind_param("si", $newEmail, $admin_id);
                $dupStmt->execute();
                if ($dupStmt->get_result()->num_rows > 0) {
                    $dupStmt->close();
                    $_SESSION['error'] = 'An account with this email address already exists.';
                } else {
                    $dupStmt->close();
                    // Update user profile
                    $upStmt = $conn->prepare("UPDATE admin_users SET full_name = ?, email = ? WHERE id = ?");
                    $upStmt->bind_param("ssi", $newName, $newEmail, $admin_id);
                    if ($upStmt->execute()) {
                        $upStmt->close();
                        $_SESSION['admin_full_name'] = $newName;
                        $_SESSION['admin_email'] = $newEmail;
                        $_SESSION['email'] = $newEmail;
                        $_SESSION['username'] = $newEmail;

                        Security::logAudit("PROFILE_UPDATED", "User ID {$admin_id} updated profile name to '{$newName}' and login email to '{$newEmail}'");
                        $_SESSION['success'] = 'Profile updated successfully! Your new login email is ' . htmlspecialchars($newEmail) . '.';
                    } else {
                        $_SESSION['error'] = 'Failed to update profile record.';
                    }
                }
            }
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $_SESSION['error'] = 'All password fields are required.';
        } elseif ($new_password !== $confirm_password) {
            $_SESSION['error'] = 'New password and confirmation do not match.';
        } elseif (strlen($new_password) < 8) {
            $_SESSION['error'] = 'New password must be at least 8 characters long.';
        } else {
            $stmt = $conn->prepare("SELECT password FROM admin_users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $admin_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $user = $res ? $res->fetch_assoc() : null;
                $stmt->close();

                if ($user && password_verify($current_password, $user['password'])) {
                    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                    $up_stmt = $conn->prepare("UPDATE admin_users SET password = ?, force_password_change = 0 WHERE id = ?");
                    if ($up_stmt) {
                        $up_stmt->bind_param("si", $new_hash, $admin_id);
                        $up_stmt->execute();
                        $up_stmt->close();

                        unset($_SESSION['force_password_change']);

                        // Log audit trail
                        Security::logAudit("PASSWORD_CHANGED", "User ID {$admin_id} changed password successfully");
                        $_SESSION['success'] = 'Password changed successfully! Your temporary password restriction has been removed.';

                        if (Auth::isSuperAdmin()) {
                            header('Location: ../super-admin/index.php');
                            exit;
                        }
                    }
                } else {
                    $_SESSION['error'] = 'Current password verification failed.';
                }
            }
        }
    } elseif ($action === 'change_kitchen_pin') {
        $kitchen_pass = $_POST['kitchen_password'] ?? '';
        if (!empty($kitchen_pass)) {
            $k_hash = password_hash($kitchen_pass, PASSWORD_BCRYPT);
            $kCheck = $conn->query("SELECT id FROM landing_page_settings WHERE restaurant_id = $tenantId LIMIT 1");
            if ($kCheck && $kCheck->num_rows > 0) {
                $kStmt = $conn->prepare("UPDATE landing_page_settings SET kds_password = ? WHERE restaurant_id = ?");
                if ($kStmt) {
                    $kStmt->bind_param("si", $k_hash, $tenantId);
                    $kStmt->execute();
                    $kStmt->close();
                }
            } else {
                $kStmt = $conn->prepare("INSERT INTO landing_page_settings (restaurant_id, kds_password) VALUES (?, ?)");
                if ($kStmt) {
                    $kStmt->bind_param("is", $tenantId, $k_hash);
                    $kStmt->execute();
                    $kStmt->close();
                }
            }
            $_SESSION['success'] = 'Kitchen Display (KDS) PIN code updated successfully!';
        }
    }

    if (Auth::isSuperAdmin()) {
        header('Location: ../super-admin/index.php');
        exit;
    }

    header('Location: change-password.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>Account Settings & Security - RMS SaaS</title>
    <link rel="manifest" href="../manifest.json">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              amber: { 500: '#f59e0b', 600: '#d97706' }
            }
          }
        }
      }
    </script>
    <style>
        body { overscroll-behavior-y: contain; -webkit-tap-highlight-color: transparent; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="min-h-full pb-20 md:pb-8 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <!-- DESKTOP SIDEBAR NAVIGATION -->
    <?php $currentPage = 'security'; include 'includes/sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="md:pl-64 min-h-screen">

        <!-- HEADER BAR -->
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg md:text-xl font-black text-white">Account & Security Management Center</h1>
                        <span class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black uppercase tracking-wider">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span> Enterprise IAM
                        </span>
                    </div>
                    <p class="text-xs text-zinc-400 hidden sm:block">Email Identity Management, Role-Based Access Control, Session Auditing & Security Logs</p>
                </div>

                <!-- Action Controls -->
                <div class="flex items-center gap-2 shrink-0">
                    <?php if (Auth::isSuperAdmin()): ?>
                        <a href="../super-admin/index.php" class="h-10 px-4 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs inline-flex items-center gap-1.5 shadow-md hover:bg-amber-400">
                            ⚡ Return to Super Admin Portal
                        </a>
                    <?php endif; ?>
                    <button onclick="refreshSecurityStream()" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
                        🔄 Refresh Audit Stream
                    </button>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 space-y-6">

            <!-- NOTIFICATION ALERTS -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold flex items-center justify-between">
                    <span>✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-zinc-400 hover:text-white">✕</button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold flex items-center justify-between">
                    <span>⚠️ <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-zinc-400 hover:text-white">✕</button>
                </div>
            <?php endif; ?>

            <!-- 1. USER ACCOUNT PROFILE CARD (REQUIREMENT 2 & 18) -->
            <section class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 shadow-2xl space-y-4">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-zinc-800 pb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-amber-500 to-amber-400 flex items-center justify-center text-zinc-950 font-black text-2xl shadow-xl shadow-amber-500/20">
                            👤
                        </div>
                        <div>
                            <h2 class="text-lg font-black text-white"><?= htmlspecialchars($currentUser['full_name']) ?></h2>
                            <p class="text-xs text-amber-400 font-bold"><?= htmlspecialchars(strtoupper($currentUser['role'])) ?> &middot; <?= htmlspecialchars($currentUser['restaurant_name'] ?: 'RMS SaaS Restaurant') ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-extrabold text-xs">
                            🟢 <?= htmlspecialchars($currentUser['tenant_status'] ?: 'ACTIVE') ?>
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-2">
                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-1">
                        <span class="text-[10px] font-bold text-zinc-500 uppercase tracking-wider block">Full Name</span>
                        <span class="text-sm font-extrabold text-white block"><?= htmlspecialchars($currentUser['full_name']) ?></span>
                    </div>

                    <div class="p-4 rounded-2xl bg-zinc-950 border border-amber-500/30 bg-amber-500/5 space-y-1">
                        <span class="text-[10px] font-black text-amber-400 uppercase tracking-wider block">Login Email Address</span>
                        <span class="text-sm font-black text-amber-300 font-mono block select-all"><?= htmlspecialchars($currentUser['email'] ?: 'No Email Configured') ?></span>
                    </div>

                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-1">
                        <span class="text-[10px] font-bold text-zinc-500 uppercase tracking-wider block">Phone Number</span>
                        <span class="text-sm font-bold text-zinc-200 block"><?= htmlspecialchars($displayPhone) ?></span>
                    </div>

                    <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-1">
                        <span class="text-[10px] font-bold text-zinc-500 uppercase tracking-wider block">Account Role</span>
                        <span class="text-sm font-bold text-white block"><?= htmlspecialchars(strtoupper($currentUser['role'])) ?></span>
                    </div>
                </div>
            </section>

            <!-- 2. TABBED SECURITY CENTER NAVIGATION -->
            <section class="space-y-6">
                <div class="flex items-center gap-2 border-b border-zinc-800 pb-2 overflow-x-auto">
                    <button onclick="switchSecurityTab('profile')" id="secTabProfile" class="px-4 py-2 rounded-2xl font-black text-xs bg-amber-500 text-zinc-950 shadow-md">✏️ Edit Profile</button>
                    <button onclick="switchSecurityTab('credentials')" id="secTabCredentials" class="px-4 py-2 rounded-2xl font-bold text-xs bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">🔐 Change Password</button>
                    <button onclick="switchSecurityTab('rbac')" id="secTabRBAC" class="px-4 py-2 rounded-2xl font-bold text-xs bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">👥 RBAC Matrix</button>
                    <button onclick="switchSecurityTab('sessions')" id="secTabSessions" class="px-4 py-2 rounded-2xl font-bold text-xs bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">📱 Active Sessions</button>
                    <button onclick="switchSecurityTab('audit')" id="secTabAudit" class="px-4 py-2 rounded-2xl font-bold text-xs bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white">📋 Audit Logs</button>
                </div>

                <!-- TAB 1: EDIT PROFILE (NAME & EMAIL CHANGE) -->
                <div id="secContentProfile" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                        <div class="border-b border-zinc-800 pb-3">
                            <h3 class="font-black text-white text-base">✏️ Update Account Profile & Login Email</h3>
                            <p class="text-xs text-zinc-400">Update your account name and primary login email address</p>
                        </div>

                        <form method="POST" action="change-password.php" class="space-y-3">
                            <?php echo CSRF::getField(); ?>
                            <input type="hidden" name="action" value="update_profile">

                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Full Name *</label>
                                <input type="text" name="full_name" required value="<?= htmlspecialchars($currentUser['full_name']) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Login Email Address *</label>
                                <input type="email" name="email" required value="<?= htmlspecialchars($currentUser['email']) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-mono font-bold outline-none focus:border-amber-500">
                                <p class="text-[10px] text-amber-500/90 mt-1">⚠️ Changing your email address will update your primary login credential.</p>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Current Password (Required to authorize changes) *</label>
                                <input type="password" name="current_password" required placeholder="••••••••••••" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                            </div>

                            <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">Save Profile & Login Email</button>
                        </form>
                    </div>

                    <!-- Kitchen KDS Access PIN Card -->
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                        <div class="border-b border-zinc-800 pb-3">
                            <h3 class="font-black text-white text-base">👨‍🍳 Kitchen Display (KDS) Security PIN</h3>
                            <p class="text-xs text-zinc-400">Configure quick access PIN for Kitchen Staff Portal</p>
                        </div>

                        <form method="POST" action="change-password.php" class="space-y-3">
                            <?php echo CSRF::getField(); ?>
                            <input type="hidden" name="action" value="change_kitchen_pin">

                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">New Kitchen Access PIN *</label>
                                <input type="password" name="kitchen_password" required placeholder="e.g. 1234" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                            </div>

                            <p class="text-[11px] text-zinc-500">Kitchen staff use this PIN to log into the Kitchen Display System (KDS).</p>

                            <button type="submit" class="w-full h-11 rounded-2xl bg-zinc-800 border border-zinc-700 text-white font-bold text-xs hover:border-amber-500/40">Update Kitchen PIN</button>
                        </form>
                    </div>
                </div>

                <!-- TAB 2: CREDENTIALS & PASSWORD -->
                <div id="secContentCredentials" class="grid grid-cols-1 md:grid-cols-2 gap-6 hidden">
                    
                    <!-- Admin Password Change Card -->
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                        <div class="border-b border-zinc-800 pb-3">
                            <h3 class="font-black text-white text-base">🔑 Administrator Password Update</h3>
                            <p class="text-xs text-zinc-400">Enforce strong BCRYPT credentials for POS Manager Portal</p>
                        </div>

                        <form method="POST" action="change-password.php" class="space-y-3">
                            <?php echo CSRF::getField(); ?>
                            <input type="hidden" name="action" value="change_password">

                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Current Password *</label>
                                <input type="password" name="current_password" required placeholder="••••••••••••" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">New Password (Min 8 Chars) *</label>
                                <input type="password" name="new_password" required placeholder="••••••••••••" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Confirm New Password *</label>
                                <input type="password" name="confirm_password" required placeholder="••••••••••••" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                            </div>

                            <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">Update Admin Credentials</button>
                        </form>
                    </div>

                </div>

                <!-- TAB 3: RBAC ROLES & USERS -->
                <div id="secContentRBAC" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4 hidden">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="font-black text-white text-base">👥 System Users & Role Permissions Matrix</h3>
                        <span class="px-2.5 py-0.5 rounded-full bg-blue-500/10 text-blue-400 font-extrabold text-[10px]">5 Active Roles</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-400 font-bold">
                                    <th class="py-2.5 px-3">Role Name</th>
                                    <th class="py-2.5 px-3">Login Email</th>
                                    <th class="py-2.5 px-3">Access Level</th>
                                    <th class="py-2.5 px-3 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-b border-zinc-800/60">
                                    <td class="py-3 px-3 font-bold text-white">Owner / Admin</td>
                                    <td class="py-3 px-3 text-amber-400 font-mono"><?= htmlspecialchars($currentUser['email']) ?></td>
                                    <td class="py-3 px-3 font-black text-amber-400">Full System Access</td>
                                    <td class="py-3 px-3 text-right"><span class="px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 font-bold text-[10px]">🟢 Active</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 4: ACTIVE SESSIONS -->
                <div id="secContentSessions" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4 hidden">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="font-black text-white text-base">📱 Active Sessions & Connected Devices</h3>
                    </div>

                    <div id="sessionsContainer" class="space-y-3">
                        <div class="p-3.5 bg-zinc-950 rounded-2xl border border-zinc-800 text-xs flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="text-2xl">💻</span>
                                <div>
                                    <span class="font-bold text-white">Current Admin Browser Session</span>
                                    <div class="text-[10px] text-zinc-500">IP: <?php echo $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'; ?> • Active Now</div>
                                </div>
                            </div>
                            <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 font-extrabold text-[10px]">THIS DEVICE</span>
                        </div>
                    </div>
                </div>

                <!-- TAB 5: REALTIME AUDIT LOGS -->
                <div id="secContentAudit" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4 hidden">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="font-black text-white text-base">📋 Realtime Security Audit Logs</h3>
                        <span class="text-xs text-zinc-500 font-bold">Immutable System Trail</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-400 font-bold">
                                    <th class="py-2.5 px-3">Event Type</th>
                                    <th class="py-2.5 px-3">Description</th>
                                    <th class="py-2.5 px-3">User</th>
                                    <th class="py-2.5 px-3">IP Address</th>
                                    <th class="py-2.5 px-3 text-right">Timestamp</th>
                                </tr>
                            </thead>
                            <tbody id="auditLogsTableBody">
                                <tr>
                                    <td colspan="5" class="py-8 text-center text-zinc-500">Loading security audit logs...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </section>

        </main>
    </div>

    <!-- REALTIME SECURITY STREAM CONTROLLER -->
    <script src="../js/modern.js"></script>
    <script>
        function switchSecurityTab(tab) {
            ['profile', 'credentials', 'rbac', 'sessions', 'audit'].forEach(t => {
                const btn = document.getElementById('secTab' + t.charAt(0).toUpperCase() + t.slice(1));
                const content = document.getElementById('secContent' + t.charAt(0).toUpperCase() + t.slice(1));
                if (btn && content) {
                    if (t === tab) {
                        btn.className = 'px-4 py-2 rounded-2xl font-black text-xs bg-amber-500 text-zinc-950 shadow-md';
                        content.classList.remove('hidden');
                    } else {
                        btn.className = 'px-4 py-2 rounded-2xl font-bold text-xs bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white';
                        content.classList.add('hidden');
                    }
                }
            });
        }

        function refreshSecurityStream() {
            fetch('../api/security-stream.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderAuditLogs(data.audit_logs || []);
                    }
                })
                .catch(err => console.error('Security stream error:', err));
        }

        function renderAuditLogs(logs) {
            const tbody = document.getElementById('auditLogsTableBody');
            if (!tbody) return;
            if (logs.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" class="py-8 text-center text-zinc-500">No audit events recorded today</td></tr>`;
                return;
            }

            tbody.innerHTML = logs.map(l => `
                <tr class="border-b border-zinc-800/60 hover:bg-zinc-950/60">
                    <td class="py-3 px-3 font-bold text-amber-400">${l.event_type}</td>
                    <td class="py-3 px-3 text-zinc-200">${l.description}</td>
                    <td class="py-3 px-3 text-zinc-400 font-medium">${l.username}</td>
                    <td class="py-3 px-3 text-zinc-500 font-mono">${l.ip_address}</td>
                    <td class="py-3 px-3 text-right text-zinc-500">${getTimeAgo(l.created_at)}</td>
                </tr>
            `).join('');
        }

        document.addEventListener('DOMContentLoaded', () => {
            refreshSecurityStream();
        });
    </script>
</body>
</html>
