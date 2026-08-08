<?php
// super-admin/create-restaurant.php - Onboard New Restaurant Tenant & Provision Credentials
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
    'restaurant_type' => 'Fine Dining',
    'plan_id' => 2,
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
            $prefill['pan_number'] = $req['pan_number'];
            $prefill['address'] = $req['address'];
            $prefill['restaurant_type'] = $req['restaurant_type'];
            $prefill['request_id'] = $req['id'];
        }
    }
}

// Subscription Plans
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
        $error = "CSRF verification failed.";
    } else {
        $restName = Security::sanitize(trim($_POST['restaurant_name'] ?? ''));
        $ownerName = Security::sanitize(trim($_POST['owner_name'] ?? ''));
        $email = strtolower(Security::sanitize(trim($_POST['email'] ?? '')));
        $phone = Security::sanitize(trim($_POST['phone'] ?? ''));
        $panNumber = Security::sanitize(trim($_POST['pan_number'] ?? ''));
        $address = Security::sanitize(trim($_POST['address'] ?? ''));
        $restType = Security::sanitize(trim($_POST['restaurant_type'] ?? 'Fine Dining'));
        $planId = (int)($_POST['plan_id'] ?? 2);
        $username = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['username'] ?? '')));
        $reqId = (int)($_POST['request_id'] ?? 0);

        if (empty($restName) || empty($ownerName) || empty($email) || empty($username)) {
            $error = "Please fill in all required fields (Restaurant Name, Owner Name, Email, Username).";
        } else {
            // Check email or username uniqueness
            $checkUser = $conn->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
            $checkUser->bind_param("s", $username);
            $checkUser->execute();
            if ($checkUser->get_result()->num_rows > 0) {
                $error = "Username '{$username}' is already taken. Please choose another.";
                $checkUser->close();
            } else {
                $checkUser->close();

                // Check restaurant email
                $checkEmail = $conn->prepare("SELECT id FROM restaurants WHERE email = ? LIMIT 1");
                $checkEmail->bind_param("s", $email);
                $checkEmail->execute();
                if ($checkEmail->get_result()->num_rows > 0) {
                    $error = "Restaurant email '{$email}' is already registered.";
                    $checkEmail->close();
                } else {
                    $checkEmail->close();

                    // Generate UUID, Restaurant Code, & Secure Temporary Password
                    $uuid = 'rest_' . bin2hex(random_bytes(12));
                    $tempPass = 'Rms@' . rand(100000, 999999);
                    $hashedPass = password_hash($tempPass, PASSWORD_DEFAULT);

                    // Insert Restaurant
                    $stmtRest = $conn->prepare("
                        INSERT INTO restaurants 
                        (uuid, restaurant_code, restaurant_name, owner_name, email, phone, pan_number, address, restaurant_type, status, subscription_plan_id, subscription_status, subscription_start, subscription_end)
                        VALUES (?, 'TMP-CODE', ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?, 'ACTIVE', CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR))
                    ");

                    if ($stmtRest) {
                        $stmtRest->bind_param("ssssssssi", $uuid, $restName, $ownerName, $email, $phone, $panNumber, $address, $restType, $planId);
                        if ($stmtRest->execute()) {
                            $newRestId = $stmtRest->insert_id;
                            $stmtRest->close();

                            // Generate formatted code e.g. RMS-000125
                            $restCode = 'RMS-' . str_pad($newRestId, 6, '0', STR_PAD_LEFT);
                            $conn->query("UPDATE restaurants SET restaurant_code = '{$restCode}' WHERE id = {$newRestId}");

                            // Insert Owner User
                            $stmtUser = $conn->prepare("
                                INSERT INTO admin_users (username, password, full_name, role, force_password_change, is_super_admin, restaurant_id)
                                VALUES (?, ?, ?, 'owner', 1, 0, ?)
                            ");
                            $stmtUser->bind_param("sssi", $username, $hashedPass, $ownerName, $newRestId);
                            $stmtUser->execute();
                            $stmtUser->close();

                            // Insert Subscription
                            $stmtSub = $conn->prepare("
                                INSERT INTO subscriptions (restaurant_id, plan_id, status, start_date, end_date)
                                VALUES (?, ?, 'ACTIVE', CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR))
                            ");
                            $stmtSub->bind_param("ii", $newRestId, $planId);
                            $stmtSub->execute();
                            $stmtSub->close();

                            // If converted from onboarding request, update request status
                            if ($reqId > 0) {
                                $conn->query("UPDATE restaurant_requests SET status = 'CONVERTED', internal_notes = 'Converted to Restaurant Tenant {$restCode}' WHERE id = {$reqId}");
                            }

                            Security::logAudit("SUPER_ADMIN_CREATE_TENANT", "Created new restaurant tenant {$restCode} ({$restName}) with owner username {$username}");

                            $createdData = [
                                'restaurant_id' => $newRestId,
                                'restaurant_code' => $restCode,
                                'restaurant_name' => $restName,
                                'owner_name' => $ownerName,
                                'email' => $email,
                                'username' => $username,
                                'temp_password' => $tempPass
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
            <h1 class="text-2xl font-black text-white tracking-tight">Onboard New Restaurant Tenant</h1>
            <p class="text-xs text-zinc-400 mt-1 font-medium">Provision isolated RMS workspace, assign subscription plan, and issue credentials.</p>
        </div>
        <a href="restaurants.php" class="px-4 py-2 rounded-xl bg-zinc-900 border border-zinc-800 text-xs font-bold text-zinc-400 hover:text-white transition-colors">
            ← Back to Restaurants
        </a>
    </div>

    <?php if ($createdData): ?>
        <!-- Success Banner with Credentials -->
        <div class="p-8 rounded-3xl bg-emerald-500/10 border border-emerald-500/30 space-y-6 shadow-2xl">
            <div class="flex items-center space-x-3">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-2xl font-black">
                    🎉
                </div>
                <div>
                    <h2 class="text-lg font-black text-white">Restaurant Tenant Provisioned Successfully!</h2>
                    <p class="text-xs text-emerald-400 font-semibold">Account is active and ready for setup.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-zinc-950 p-6 rounded-2xl border border-zinc-800 text-xs">
                <div>
                    <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Restaurant Code</span>
                    <span class="text-amber-400 font-mono font-black text-base block mt-0.5"><?= htmlspecialchars($createdData['restaurant_code']) ?></span>
                </div>
                <div>
                    <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Restaurant Name</span>
                    <span class="text-white font-bold text-base block mt-0.5"><?= htmlspecialchars($createdData['restaurant_name']) ?></span>
                </div>
                <div>
                    <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Owner Username</span>
                    <span class="text-white font-bold block mt-0.5 select-all"><?= htmlspecialchars($createdData['username']) ?></span>
                </div>
                <div>
                    <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Temporary Password</span>
                    <span class="text-amber-400 font-mono font-bold text-base block mt-0.5 select-all"><?= htmlspecialchars($createdData['temp_password']) ?></span>
                </div>
            </div>

            <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-300 font-medium">
                💡 <strong>Important:</strong> Copy these credentials and securely communicate them to the restaurant owner. The owner will be required to change this temporary password upon first login.
            </div>

            <div class="flex items-center space-x-3">
                <a href="restaurants.php" class="px-5 py-2.5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all">
                    Return to Restaurants List
                </a>
                <a href="create-restaurant.php" class="px-5 py-2.5 rounded-2xl bg-zinc-800 text-white font-bold text-xs hover:bg-zinc-700 transition-all">
                    Onboard Another Restaurant
                </a>
            </div>
        </div>
    <?php else: ?>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-8 space-y-6 shadow-2xl">
            <?= $csrfField ?>
            <input type="hidden" name="request_id" value="<?= htmlspecialchars($prefill['request_id']) ?>">

            <div>
                <h3 class="text-sm font-black uppercase tracking-wider text-amber-500 mb-4 border-b border-zinc-800 pb-2">1. Restaurant & Contact Information</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Restaurant Name *</label>
                        <input type="text" name="restaurant_name" required value="<?= htmlspecialchars($prefill['restaurant_name']) ?>" placeholder="e.g. Royal Taste Cafe" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Owner Full Name *</label>
                        <input type="text" name="owner_name" required value="<?= htmlspecialchars($prefill['owner_name']) ?>" placeholder="e.g. John Smith" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Owner Email Address *</label>
                        <input type="email" name="email" required value="<?= htmlspecialchars($prefill['email']) ?>" placeholder="owner@restaurant.com" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Phone Number</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($prefill['phone']) ?>" placeholder="98XXXXXXXX" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">PAN / VAT Number</label>
                        <input type="text" name="pan_number" value="<?= htmlspecialchars($prefill['pan_number']) ?>" placeholder="123456789" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Restaurant Type</label>
                        <select name="restaurant_type" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                            <option value="Fine Dining">Fine Dining</option>
                            <option value="Casual Dining">Casual Dining</option>
                            <option value="Fast Food / QSR">Fast Food / QSR</option>
                            <option value="Cafe & Bakery">Cafe & Bakery</option>
                            <option value="Food Court / Truck">Food Court / Truck</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Full Address</label>
                        <textarea name="address" rows="2" placeholder="Street, City, Country" class="w-full bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500"><?= htmlspecialchars($prefill['address']) ?></textarea>
                    </div>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-black uppercase tracking-wider text-amber-500 mb-4 border-b border-zinc-800 pb-2">2. Subscription Plan & Credentials Setup</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Subscription Plan *</label>
                        <select name="plan_id" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                            <?php foreach ($plans as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $p['id'] == $prefill['plan_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?> ($<?= number_format($p['price_monthly'], 2) ?>/mo — Max <?= $p['max_tables'] ?> Tables)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Owner Portal Username *</label>
                        <input type="text" name="username" required placeholder="e.g. royal_cafe_owner" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500 font-mono">
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-zinc-800 flex items-center justify-end space-x-3">
                <a href="restaurants.php" class="px-5 py-3 rounded-2xl bg-zinc-800 text-xs font-bold text-zinc-300 hover:bg-zinc-700 transition-all">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-3 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-xs hover:from-amber-400 hover:to-amber-300 transition-all shadow-lg shadow-amber-500/20">
                    Provision Restaurant Tenant & Generate Credentials →
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
