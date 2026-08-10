<?php
// admin/staff.php - Staff Management & RBAC User Role Administration
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
$conn = getDBConnection();

$userRole = strtolower($_SESSION['user_role'] ?? $_SESSION['role'] ?? 'admin');
if (!in_array($userRole, ['admin', 'manager', 'owner', 'super_admin'])) {
    die("Access denied. Staff management requires Admin/Manager role.");
}

$currentPage = 'staff';
$message = '';
$error = '';

// Handle POST Staff Creation / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();

    $action = $_POST['action'] ?? '';
    if ($action === 'create_staff') {
        $username = Security::sanitize($_POST['username'] ?? '');
        $fullName = Security::sanitize($_POST['full_name'] ?? '');
        $role = Security::sanitize($_POST['role'] ?? 'CASHIER');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = "Username and password are required.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admin_users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $username, $hash, $fullName, $role);
            if ($stmt->execute()) {
                $message = "Staff member '$username' created successfully!";
            } else {
                $error = "Failed to create staff member: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// Fetch Staff List
$staffMembers = [];
$res = $conn->query("SELECT id, username, full_name, role, created_at FROM admin_users ORDER BY id ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $staffMembers[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 font-sans antialiased text-white selection:bg-amber-500 selection:text-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff &amp; RBAC Management — RMS SaaS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { amber: { 500: '#f59e0b', 600: '#d97706' } } } } }
    </script>
</head>
<body class="min-h-full pb-12 font-sans antialiased">
    <?php include 'includes/sidebar.php'; ?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5 flex items-center justify-between">
            <div>
                <h1 class="text-lg md:text-xl font-black text-white">Staff Management &amp; RBAC</h1>
                <p class="text-xs text-zinc-400">Configure Staff Accounts, Role-Based Access Controls &amp; User Credentials</p>
            </div>
            <button onclick="document.getElementById('addStaffModal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                ➕ Add Staff Member
            </button>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <?php if ($message): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">✅ <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-black text-white">Active Staff Accounts</h2>
                    <span class="text-xs text-zinc-500 font-bold">Role-Based Access Hierarchy</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <th class="py-2.5 px-3">ID</th>
                                <th class="py-2.5 px-3">Username</th>
                                <th class="py-2.5 px-3">Full Name</th>
                                <th class="py-2.5 px-3">Role</th>
                                <th class="py-2.5 px-3">Permissions Scope</th>
                                <th class="py-2.5 px-3">Created Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                            <?php foreach ($staffMembers as $sm): ?>
                                <tr class="hover:bg-zinc-800/40">
                                    <td class="py-3 px-3 text-zinc-500 font-mono">#<?= $sm['id'] ?></td>
                                    <td class="py-3 px-3 font-bold text-amber-400"><?= htmlspecialchars($sm['username']) ?></td>
                                    <td class="py-3 px-3 text-white"><?= htmlspecialchars($sm['full_name'] ?: 'Staff User') ?></td>
                                    <td class="py-3 px-3">
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-zinc-800 border border-zinc-700 text-amber-400">
                                            <?= htmlspecialchars($sm['role']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-3 text-zinc-400 text-[11px]">
                                        <?= implode(', ', array_slice(PermissionService::expandPermissions($sm['role']), 0, 4)) ?>...
                                    </td>
                                    <td class="py-3 px-3 text-zinc-400 text-[11px]"><?= date('Y-m-d', strtotime($sm['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- Add Staff Modal -->
    <div id="addStaffModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
            <?= CSRF::getField() ?>
            <input type="hidden" name="action" value="create_staff">
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">Add New Staff Member</h3>
                <button type="button" onclick="document.getElementById('addStaffModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Username</label>
                    <input type="text" name="username" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Full Name</label>
                    <input type="text" name="full_name" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Assign Role</label>
                    <select name="role" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                        <option value="CASHIER">CASHIER (Billing &amp; Payments)</option>
                        <option value="WAITER">WAITER (Order Taking &amp; Tables)</option>
                        <option value="KITCHEN">KITCHEN (KDS Ticket Management)</option>
                        <option value="MANAGER">MANAGER (Full Operational Control)</option>
                        <option value="OWNER">OWNER (Full Administrative Access)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Password</label>
                    <input type="password" name="password" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('addStaffModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">Save Staff Account</button>
            </div>
        </form>
    </div>
</body>
</html>
