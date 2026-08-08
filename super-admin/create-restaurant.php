<?php
// super-admin/create-restaurant.php - Onboard New Restaurant Tenant & Manual Credential Assignment
$pageTitle = 'Create Restaurant Account';
require_once __DIR__ . '/includes/header.php';

$conn = getDBConnection();
$error = null;
$createdData = null;

// Pre-fill from onboarding request if request_id passed
$prefill = [
    'restaurant_name' => '',
    'owner_name' => '',
    'email' => '',
    'phone' => '',
    'pan_number' => '',
    'address' => '',
    'restaurant_type' => 'Casual Dining',
    'plan_id' => 2,
    'account_status' => 'ACTIVE',
    'username' => '',
    'request_id' => 0
];

if (isset($_GET['request_id']) && (int)$_GET['request_id'] > 0 && $conn) {
    $reqId = (int)$_GET['request_id'];
    $stmt = $conn->prepare("SELECT * FROM restaurant_requests WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $reqId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($req) {
            $prefill['restaurant_name'] = $req['restaurant_name'];
            $prefill['owner_name'] = $req['owner_name'];
            $prefill['email'] = $req['email'];
            $prefill['phone'] = $req['phone'];
            $prefill['pan_number'] = $req['pan_number'] ?? '';
            $prefill['address'] = $req['address'] ?? '';
            $prefill['restaurant_type'] = $req['restaurant_type'] ?? 'Casual Dining';
            $prefill['request_id'] = $req['id'];
            
            // Suggest clean username based on owner/restaurant
            $cleanUser = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $req['owner_name']));
            $prefill['username'] = substr($cleanUser, 0, 20);
        }
    }
}

// Fetch Active Subscription Plans
$plans = [];
if ($conn) {
    $pRes = $conn->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY id ASC");
    if ($pRes) {
        while ($p = $pRes->fetch_assoc()) {
            $plans[] = $p;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed. Please refresh the page and try again.";
    } else {
        $restName = Security::sanitize(trim($_POST['restaurant_name'] ?? ''));
        $ownerName = Security::sanitize(trim($_POST['owner_name'] ?? ''));
        $email = strtolower(Security::sanitize(trim($_POST['email'] ?? '')));
        $phone = Security::sanitize(trim($_POST['phone'] ?? ''));
        $panNumber = Security::sanitize(trim($_POST['pan_number'] ?? ''));
        $address = Security::sanitize(trim($_POST['address'] ?? ''));
        $restType = Security::sanitize(trim($_POST['restaurant_type'] ?? 'Casual Dining'));
        $planId = (int)($_POST['plan_id'] ?? 2);
        $accountStatus = Security::sanitize(trim($_POST['account_status'] ?? 'ACTIVE'));
        
        // Manual Credentials Input (MUST NOT BE AUTO-GENERATED)
        $rawUsername = trim($_POST['username'] ?? '');
        $username = strtolower(Security::sanitize($rawUsername));
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $reqId = (int)($_POST['request_id'] ?? 0);

        // Validation Rules
        if (empty($restName) || empty($ownerName) || empty($email) || empty($phone) || empty($username) || empty($password) || empty($confirmPassword)) {
            $error = "Please fill in all required fields marked with *.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]{4,30}$/', $username)) {
            $error = "Username must be between 4 and 30 characters long and contain only letters, numbers, or underscores.";
        } elseif ($password !== $confirmPassword) {
            $error = "Admin Password and Confirm Password do not match.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } else {
            // Requirement Check: Duplicate username validation
            $checkUser = $conn->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
            $checkUser->bind_param("s", $username);
            $checkUser->execute();
            if ($checkUser->get_result()->num_rows > 0) {
                // Exact prompt required message:
                $error = "Username already exists. Please choose another username.";
                $checkUser->close();
            } else {
                $checkUser->close();

                // Check restaurant email uniqueness
                $checkEmail = $conn->prepare("SELECT id FROM restaurants WHERE email = ? LIMIT 1");
                $checkEmail->bind_param("s", $email);
                $checkEmail->execute();
                if ($checkEmail->get_result()->num_rows > 0) {
                    $error = "Restaurant email '{$email}' is already registered.";
                    $checkEmail->close();
                } else {
                    $checkEmail->close();

                    // Generate UUID & Secure BCRYPT Password Hash (NO Plaintext password stored!)
                    $uuid = 'rest_' . bin2hex(random_bytes(12));
                    $hashedPass = password_hash($password, PASSWORD_BCRYPT);

                    // Insert Restaurant Record
                    $stmtRest = $conn->prepare("
                        INSERT INTO restaurants 
                        (uuid, restaurant_code, restaurant_name, owner_name, email, phone, pan_number, address, restaurant_type, status, subscription_plan_id, subscription_status, subscription_start, subscription_end)
                        VALUES (?, 'TMP-CODE', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR))
                    ");

                    if ($stmtRest) {
                        $stmtRest->bind_param("sssssssssi", $uuid, $restName, $ownerName, $email, $phone, $panNumber, $address, $restType, $accountStatus, $planId);
                        if ($stmtRest->execute()) {
                            $newRestId = $stmtRest->insert_id;
                            $stmtRest->close();

                            // Formatted Code e.g. RMS-000125
                            $restCode = 'RMS-' . str_pad($newRestId, 6, '0', STR_PAD_LEFT);
                            $conn->query("UPDATE restaurants SET restaurant_code = '{$restCode}' WHERE id = {$newRestId}");

                            // Insert Owner / Restaurant Admin User in admin_users
                            $stmtUser = $conn->prepare("
                                INSERT INTO admin_users (username, password, full_name, role, force_password_change, is_super_admin, restaurant_id)
                                VALUES (?, ?, ?, 'owner', 0, 0, ?)
                            ");
                            $stmtUser->bind_param("sssi", $username, $hashedPass, $ownerName, $newRestId);
                            $stmtUser->execute();
                            $stmtUser->close();

                            // Insert Active Subscription Record
                            $stmtSub = $conn->prepare("
                                INSERT INTO subscriptions (restaurant_id, plan_id, status, start_date, end_date)
                                VALUES (?, ?, 'ACTIVE', CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR))
                            ");
                            $stmtSub->bind_param("ii", $newRestId, $planId);
                            $stmtSub->execute();
                            $stmtSub->close();

                            // Update request status if converted from public lead
                            if ($reqId > 0) {
                                $conn->query("UPDATE restaurant_requests SET status = 'CONVERTED', internal_notes = 'Converted to Restaurant Tenant {$restCode}' WHERE id = {$reqId}");
                            }

                            // Security Audit Logging (NEVER RECORDING THE PASSWORD!)
                            Security::logAudit("SUPER_ADMIN_CREATE_TENANT", "Created restaurant account {$restCode} ({$restName}) with admin username: {$username}");

                            // Memory payload for confirmation view
                            $createdData = [
                                'restaurant_id' => $newRestId,
                                'restaurant_code' => $restCode,
                                'restaurant_name' => $restName,
                                'owner_name' => $ownerName,
                                'email' => $email,
                                'phone' => $phone,
                                'username' => $username,
                                'password' => $password
                            ];
                        } else {
                            $error = "Failed to create restaurant record: " . $conn->error;
                        }
                    }
                }
            }
        }
    }
}

$csrfField = CSRF::getField();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between border-b border-zinc-800 pb-6">
        <div>
            <h1 class="text-2xl font-black text-white tracking-tight">Create Restaurant Account</h1>
            <p class="text-xs text-zinc-400 mt-1 font-medium">Provision isolated restaurant tenant and manually assign administrator login credentials.</p>
        </div>
        <a href="restaurants.php" class="px-4 py-2 rounded-xl bg-zinc-900 border border-zinc-800 text-xs font-bold text-zinc-400 hover:text-white transition-colors">
            ← Back to Restaurants
        </a>
    </div>

    <?php if ($createdData): ?>
        <!-- CREDENTIAL DELIVERY CONFIRMATION SCREEN -->
        <div class="p-8 rounded-3xl bg-emerald-500/10 border border-emerald-500/30 space-y-6 shadow-2xl">
            <div class="flex items-center space-x-3">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-2xl font-black">
                    ✓
                </div>
                <div>
                    <h2 class="text-lg font-black text-white">Restaurant Account Created Successfully</h2>
                    <p class="text-xs text-emerald-400 font-semibold">Tenant workspace provisioned and administrator credentials securely stored.</p>
                </div>
            </div>

            <div class="bg-zinc-950 p-6 rounded-2xl border border-zinc-800 space-y-4 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Restaurant Name</span>
                        <span class="text-white font-bold text-base block mt-0.5"><?= htmlspecialchars($createdData['restaurant_name']) ?></span>
                        <span class="text-amber-400 font-mono text-xs block font-bold"><?= htmlspecialchars($createdData['restaurant_code']) ?></span>
                    </div>
                    <div>
                        <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Owner Name & Email</span>
                        <span class="text-zinc-200 font-bold block mt-0.5"><?= htmlspecialchars($createdData['owner_name']) ?></span>
                        <span class="text-zinc-400 block"><?= htmlspecialchars($createdData['email']) ?></span>
                    </div>
                </div>

                <div class="border-t border-zinc-800 pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Admin Username</span>
                        <span id="created-username" class="text-amber-400 font-mono font-black text-base block mt-0.5 select-all"><?= htmlspecialchars($createdData['username']) ?></span>
                    </div>
                    <div>
                        <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Assigned Password</span>
                        <span id="created-password" class="text-white font-mono font-bold text-base block mt-0.5 select-all"><?= htmlspecialchars($createdData['password']) ?></span>
                    </div>
                </div>
            </div>

            <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-300 font-medium">
                💡 <strong>Credential Delivery:</strong> Copy these credentials and securely transmit them to the restaurant administrator. Plaintext passwords are not stored in audit logs or database records.
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" onclick="copyCredentials()" id="copy-btn" class="px-6 py-3 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all flex items-center space-x-2 shadow-lg shadow-amber-500/20">
                    <span>📋</span>
                    <span>Copy Credentials</span>
                </button>
                <a href="restaurants.php" class="px-6 py-3 rounded-2xl bg-zinc-800 text-white font-bold text-xs hover:bg-zinc-700 transition-all">
                    Done
                </a>
            </div>
        </div>

        <script>
            function copyCredentials() {
                const user = document.getElementById('created-username').innerText.trim();
                const pass = document.getElementById('created-password').innerText.trim();
                const rest = "<?= htmlspecialchars($createdData['restaurant_name'], ENT_QUOTES) ?>";
                const text = `Restaurant: ${rest}\nAdmin Username: ${user}\nPassword: ${pass}\nLogin URL: http://${window.location.host}/admin/login.php`;
                
                navigator.clipboard.writeText(text).then(() => {
                    const btn = document.getElementById('copy-btn');
                    btn.innerHTML = '<span>✓</span> <span>Copied to Clipboard!</span>';
                    btn.classList.replace('bg-amber-500', 'bg-emerald-500');
                    btn.classList.replace('text-zinc-950', 'text-white');
                    setTimeout(() => {
                        btn.innerHTML = '<span>📋</span> <span>Copy Credentials</span>';
                        btn.classList.replace('bg-emerald-500', 'bg-amber-500');
                        btn.classList.replace('text-white', 'text-zinc-950');
                    }, 3000);
                });
            }
        </script>

    <?php else: ?>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-8 space-y-8 shadow-2xl">
            <?= $csrfField ?>
            <input type="hidden" name="request_id" value="<?= htmlspecialchars($prefill['request_id']) ?>">

            <!-- SECTION 1: RESTAURANT INFORMATION -->
            <div class="space-y-4">
                <h3 class="text-sm font-black uppercase tracking-wider text-amber-500 border-b border-zinc-800 pb-2">1. Restaurant Information</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Restaurant Name *</label>
                        <input type="text" name="restaurant_name" required value="<?= htmlspecialchars($prefill['restaurant_name']) ?>" placeholder="e.g. Royal Taste Cafe" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Owner Full Name *</label>
                        <input type="text" name="owner_name" required value="<?= htmlspecialchars($prefill['owner_name']) ?>" placeholder="e.g. Ramesh Sharma" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Owner Email Address *</label>
                        <input type="email" name="email" required value="<?= htmlspecialchars($prefill['email']) ?>" placeholder="owner@restaurant.com" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Phone Number *</label>
                        <input type="text" name="phone" required value="<?= htmlspecialchars($prefill['phone']) ?>" placeholder="98XXXXXXXX" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">PAN / VAT Number</label>
                        <input type="text" name="pan_number" value="<?= htmlspecialchars($prefill['pan_number']) ?>" placeholder="123456789" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Restaurant Type</label>
                        <select name="restaurant_type" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                            <option value="Fine Dining" <?= $prefill['restaurant_type'] === 'Fine Dining' ? 'selected' : '' ?>>Fine Dining</option>
                            <option value="Casual Dining" <?= $prefill['restaurant_type'] === 'Casual Dining' ? 'selected' : '' ?>>Casual Dining</option>
                            <option value="Fast Food / QSR" <?= $prefill['restaurant_type'] === 'Fast Food / QSR' ? 'selected' : '' ?>>Fast Food / QSR</option>
                            <option value="Cafe & Bakery" <?= $prefill['restaurant_type'] === 'Cafe & Bakery' ? 'selected' : '' ?>>Cafe & Bakery</option>
                            <option value="Food Court" <?= $prefill['restaurant_type'] === 'Food Court' ? 'selected' : '' ?>>Food Court</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Subscription Plan *</label>
                        <select name="plan_id" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                            <?php foreach ($plans as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $p['id'] == $prefill['plan_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?> (NPR <?= number_format($p['price_monthly']) ?>/mo — Max <?= $p['max_tables'] ?> Tables)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Account Status *</label>
                        <select name="account_status" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                            <option value="ACTIVE" selected>ACTIVE (Operational)</option>
                            <option value="PENDING">PENDING (Reviewing)</option>
                            <option value="SUSPENDED">SUSPENDED (Locked)</option>
                            <option value="INACTIVE">INACTIVE (Disabled)</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Full Address</label>
                        <textarea name="address" rows="2" placeholder="Street, City, Country" class="w-full bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500"><?= htmlspecialchars($prefill['address']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: MANUAL LOGIN CREDENTIALS -->
            <div class="space-y-4">
                <h3 class="text-sm font-black uppercase tracking-wider text-amber-500 border-b border-zinc-800 pb-2">2. Manual Admin Login Credentials</h3>
                <p class="text-xs text-zinc-400">Specify the administrator login username and password manually. Credentials are not automatically generated.</p>
                
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Admin Username *</label>
                        <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? $prefill['username']) ?>" placeholder="e.g. royal_admin" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500 font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Admin Password *</label>
                        <input type="password" name="password" required placeholder="••••••••••••" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Confirm Password *</label>
                        <input type="password" name="confirm_password" required placeholder="••••••••••••" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-zinc-800 flex items-center justify-end space-x-3">
                <a href="restaurants.php" class="px-5 py-3 rounded-2xl bg-zinc-800 text-xs font-bold text-zinc-300 hover:bg-zinc-700 transition-all">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-3 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-xs hover:from-amber-400 hover:to-amber-300 transition-all shadow-lg shadow-amber-500/20">
                    Create Restaurant Account →
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
