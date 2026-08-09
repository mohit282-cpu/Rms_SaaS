<?php
// admin/staff.php - Staff Management & RBAC Role Assignment UI (Phase 1)
require_once __DIR__ . '/../config.php';
requireAdminLogin();
RBAC::requirePermission('manage_staff');

$currentPage = 'staff';
$tenantId = TenantContext::getTenantId();

$conn = getDBConnection();
$stmt = $conn->prepare("
    SELECT id, username, full_name, role, is_super_admin, force_password_change, created_at 
    FROM admin_users 
    WHERE restaurant_id = ? 
    ORDER BY id ASC
");
$stmt->bind_param("i", $tenantId);
$stmt->execute();
$staffMembers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Staff Management & RBAC Roles - QR Cafe</title>
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

    <div class="flex min-h-screen">
        <?php include 'includes/sidebar.php'; ?>

        <main class="flex-1 md:pl-64">
            <!-- HEADER BAR -->
            <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-lg md:text-xl font-black text-white flex items-center gap-2">
                        <span>👥</span> Staff Management & RBAC Roles
                    </h1>
                    <p class="text-xs text-zinc-400">Create staff accounts, assign granular RBAC roles, and manage permissions</p>
                </div>
                <button onclick="openCreateModal()" class="h-10 px-5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 transition-all flex items-center gap-2 shadow-lg shadow-amber-500/20">
                    <span>➕</span> <span>Add New Staff Account</span>
                </button>
            </header>

            <div class="p-4 md:p-8 max-w-6xl mx-auto space-y-6">

                <!-- STAFF DIRECTORY TABLE -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                            <span>👥</span> Staff Accounts Directory
                        </h3>
                        <span class="text-xs font-bold text-amber-400 bg-amber-500/10 border border-amber-500/30 px-3 py-1 rounded-full">
                            Total Accounts: <?= count($staffMembers) ?>
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider font-bold">
                                    <th class="py-3 px-4">User</th>
                                    <th class="py-3 px-4">Role</th>
                                    <th class="py-3 px-4">Status / Privileges</th>
                                    <th class="py-3 px-4">Created Date</th>
                                    <th class="py-3 px-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-200">
                                <?php foreach ($staffMembers as $u): ?>
                                    <tr class="hover:bg-zinc-800/30 transition-colors">
                                        <td class="py-3.5 px-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-zinc-800 border border-zinc-700 flex items-center justify-center text-sm font-black text-amber-400">
                                                    <?= strtoupper(substr($u['username'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div class="font-bold text-white"><?= htmlspecialchars($u['full_name']) ?></div>
                                                    <div class="text-[10px] text-zinc-400">@<?= htmlspecialchars($u['username']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <?php
                                            $roleBadge = 'bg-zinc-800 text-zinc-300 border-zinc-700';
                                            $rStr = strtoupper($u['role']);
                                            if (in_array($rStr, ['OWNER', 'ADMIN', 'SUPER_ADMIN'])) $roleBadge = 'bg-amber-500/10 text-amber-400 border-amber-500/30';
                                            if ($rStr === 'MANAGER') $roleBadge = 'bg-blue-500/10 text-blue-400 border-blue-500/30';
                                            if ($rStr === 'CASHIER') $roleBadge = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30';
                                            if ($rStr === 'WAITER') $roleBadge = 'bg-purple-500/10 text-purple-400 border-purple-500/30';
                                            if ($rStr === 'KITCHEN') $roleBadge = 'bg-rose-500/10 text-rose-400 border-rose-500/30';
                                            ?>
                                            <span class="px-2.5 py-1 rounded-xl text-[10px] font-black border <?= $roleBadge ?>">
                                                <?= htmlspecialchars($u['role']) ?>
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <?php if ($u['is_super_admin']): ?>
                                                <span class="px-2 py-0.5 rounded-lg bg-amber-500 text-zinc-950 font-black text-[10px]">👑 Platform Super Admin</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-bold text-[10px]">Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-zinc-400">
                                            <?= date('M d, Y', strtotime($u['created_at'])) ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-right space-x-2">
                                            <button onclick="openResetPasswordModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')" class="px-3 py-1 rounded-xl bg-zinc-800 border border-zinc-700 text-zinc-300 font-bold text-[11px] hover:border-amber-500">
                                                🔑 Reset Pass
                                            </button>
                                            <?php if (!$u['is_super_admin'] && $u['id'] !== (int)$_SESSION['admin_id']): ?>
                                                <button onclick="deleteStaff(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')" class="px-2.5 py-1 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 font-bold text-[11px] hover:bg-rose-500 hover:text-white">
                                                    🗑️
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- RBAC ROLE PERMISSIONS OVERVIEW CARDS -->
                <div class="space-y-4 pt-4">
                    <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                        <span>🛡️</span> RBAC System Role Definitions
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-2">
                            <span class="px-2.5 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 font-black text-[10px]">OWNER / MANAGER</span>
                            <h4 class="text-xs font-bold text-white">Full Operations & Administrative Access</h4>
                            <p class="text-[11px] text-zinc-400">Full access to orders, menu, inventory, reports, staff management, settings, and shift management.</p>
                        </div>
                        <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-2">
                            <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-black text-[10px]">CASHIER</span>
                            <h4 class="text-xs font-bold text-white">POS Checkout & Payment Processing</h4>
                            <p class="text-[11px] text-zinc-400">Access to create/edit orders, apply discounts, process payments, customer records, and shift opening/closing.</p>
                        </div>
                        <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-2">
                            <span class="px-2.5 py-0.5 rounded-full bg-rose-500/10 border border-rose-500/30 text-rose-400 font-black text-[10px]">KITCHEN & WAITER</span>
                            <h4 class="text-xs font-bold text-white">KDS Live Line & Table Operations</h4>
                            <p class="text-[11px] text-zinc-400">Access to KDS station displays, order preparation status updates, and table order entry.</p>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- CREATE STAFF MODAL -->
    <div id="createModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 w-full max-w-md space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 class="text-sm font-black text-white flex items-center gap-2"><span>➕</span> Add New Staff Account</h3>
                <button onclick="closeCreateModal()" class="text-zinc-500 hover:text-white font-bold text-sm">✕</button>
            </div>

            <form id="createStaffForm" onsubmit="event.preventDefault(); submitCreateStaff();" class="space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="create">

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Full Name</label>
                    <input type="text" name="full_name" required placeholder="e.g. Ram Kumar" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Login Username</label>
                    <input type="text" name="username" required placeholder="e.g. ram_cashier" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Assigned Role</label>
                    <select name="role" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                        <option value="CASHIER">Cashier (POS & Checkout)</option>
                        <option value="WAITER">Waiter (Order Entry)</option>
                        <option value="KITCHEN">Kitchen Staff (KDS Display)</option>
                        <option value="INVENTORY_MANAGER">Inventory Manager</option>
                        <option value="MANAGER">Restaurant Manager</option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Password</label>
                    <input type="password" name="password" required minlength="6" placeholder="At least 6 characters" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <button type="submit" id="createStaffBtn" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 shadow-md">
                    Save & Create Account
                </button>
            </form>
        </div>
    </div>

    <!-- RESET PASSWORD MODAL -->
    <div id="resetModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 w-full max-w-md space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 class="text-sm font-black text-white flex items-center gap-2"><span>🔑</span> Reset Password</h3>
                <button onclick="closeResetModal()" class="text-zinc-500 hover:text-white font-bold text-sm">✕</button>
            </div>

            <form id="resetStaffForm" onsubmit="event.preventDefault(); submitResetPassword();" class="space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="staff_id" id="resetStaffId">

                <div>
                    <p class="text-xs text-zinc-400">Resetting password for user: <strong id="resetTargetUsername" class="text-white"></strong></p>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">New Password</label>
                    <input type="password" name="new_password" required minlength="6" placeholder="Enter new password" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <button type="submit" id="resetStaffBtn" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 shadow-md">
                    Update Password
                </button>
            </form>
        </div>
    </div>

    <script src="../js/modern.js"></script>
    <script>
        function openCreateModal() {
            document.getElementById('createModal').classList.remove('hidden');
        }
        function closeCreateModal() {
            document.getElementById('createModal').classList.add('hidden');
        }
        function openResetPasswordModal(id, username) {
            document.getElementById('resetStaffId').value = id;
            document.getElementById('resetTargetUsername').textContent = '@' + username;
            document.getElementById('resetModal').classList.remove('hidden');
        }
        function closeResetModal() {
            document.getElementById('resetModal').classList.add('hidden');
        }

        function submitCreateStaff() {
            const btn = document.getElementById('createStaffBtn');
            btn.disabled = true;
            btn.innerHTML = 'Saving...';

            const formData = new FormData(document.getElementById('createStaffForm'));
            fetch('../api/staff.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = 'Save & Create Account';
                    if (data.success) {
                        showToast('Staff account created!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(data.message || 'Failed to create staff', 'error');
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerHTML = 'Save & Create Account';
                    showToast('Network error creating staff account', 'error');
                });
        }

        function submitResetPassword() {
            const btn = document.getElementById('resetStaffBtn');
            btn.disabled = true;
            btn.innerHTML = 'Updating...';

            const formData = new FormData(document.getElementById('resetStaffForm'));
            fetch('../api/staff.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = 'Update Password';
                    if (data.success) {
                        showToast('Staff password updated!', 'success');
                        closeResetModal();
                    } else {
                        showToast(data.message || 'Failed to reset password', 'error');
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerHTML = 'Update Password';
                    showToast('Network error updating password', 'error');
                });
        }

        function deleteStaff(id, username) {
            if (!confirm(`Are you sure you want to delete staff account @${username}?`)) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('staff_id', id);
            formData.append('csrf_token', '<?= CSRF::generateToken() ?>');

            fetch('../api/staff.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast('Staff account deleted!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(data.message || 'Failed to delete staff', 'error');
                    }
                });
        }
    </script>
</body>
</html>
