<?php
// super-admin/create-restaurant.php - Production-Hardened Tenant Provisioning & Manual Credential Assignment
require_once __DIR__ . '/../config.php';
Auth::requireSuperAdmin();

$conn = getDBConnection();
$error = null;
$createdData = null;

// Pre-fill from onboarding request if request_id passed
$prefill = [
    'restaurant_name' => '',
    'restaurant_code' => '',
    'owner_name' => '',
    'email' => '',
    'phone' => '',
    'pan_number' => '',
    'address' => '',
    'restaurant_type' => 'Casual Dining',
    'custom_type' => '',
    'table_count' => 10,
    'plan_id' => 2,
    'account_status' => 'ACTIVE',
    'username' => '',
    'request_id' => 0
];

// Generate Suggested Unique Restaurant Code (e.g. RTC001)
if ($conn) {
    $cRes = $conn->query("SELECT MAX(id) as max_id FROM restaurants");
    $nextId = ($cRes && $row = $cRes->fetch_assoc()) ? ((int)$row['max_id'] + 1) : 1;
    $prefill['restaurant_code'] = 'RMS-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
}

if (isset($_GET['request_id']) && (int)$_GET['request_id'] > 0 && $conn) {
    $reqId = (int)$_GET['request_id'];
    $stmt = $conn->prepare("SELECT * FROM restaurant_requests WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $reqId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($req) {
            if ($req['status'] === 'CONVERTED') {
                $error = "This onboarding request has already been converted into a restaurant tenant account.";
            }
            $prefill['restaurant_name'] = $req['restaurant_name'];
            $prefill['owner_name'] = $req['owner_name'];
            $prefill['email'] = $req['email'];
            $prefill['phone'] = $req['phone'];
            $prefill['pan_number'] = $req['pan_number'] ?? '';
            $prefill['address'] = $req['address'] ?? '';
            $prefill['restaurant_type'] = $req['restaurant_type'] ?? 'Casual Dining';
            $prefill['table_count'] = max(1, (int)($req['table_count'] ?? 10));
            $prefill['request_id'] = $req['id'];
            
            // Suggest clean username based on owner/restaurant
            $cleanUser = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $req['owner_name']));
            $prefill['username'] = substr($cleanUser, 0, 20);

            // Generate clean code from restaurant name
            $words = explode(' ', preg_replace('/[^a-zA-Z0-9 ]/', '', $req['restaurant_name']));
            $initials = '';
            foreach ($words as $w) {
                if (!empty($w)) $initials .= strtoupper($w[0]);
            }
            if (strlen($initials) >= 2) {
                $prefill['restaurant_code'] = substr($initials, 0, 4) . str_pad($reqId, 3, '0', STR_PAD_LEFT);
            }
        }
    }
}

// Fetch Active Subscription Plans from Database Source
$plans = [];
if ($conn) {
    $pRes = $conn->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price_monthly ASC");
    if ($pRes) {
        while ($p = $pRes->fetch_assoc()) {
            $plans[] = $p;
        }
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed. Please refresh the page and try again.";
    } else {
        $restName = Security::sanitize(trim($_POST['restaurant_name'] ?? ''));
        $restCode = strtoupper(Security::sanitize(trim($_POST['restaurant_code'] ?? '')));
        $ownerName = Security::sanitize(trim($_POST['owner_name'] ?? ''));
        $email = strtolower(Security::sanitize(trim($_POST['email'] ?? '')));
        $phone = Security::sanitize(trim($_POST['phone'] ?? ''));
        $panNumber = Security::sanitize(trim($_POST['pan_number'] ?? ''));
        $address = Security::sanitize(trim($_POST['address'] ?? ''));
        $restType = Security::sanitize(trim($_POST['restaurant_type'] ?? 'Casual Dining'));
        if ($restType === 'Other' && !empty($_POST['custom_type'])) {
            $restType = Security::sanitize(trim($_POST['custom_type']));
        }
        $tableCount = max(1, (int)($_POST['table_count'] ?? 10));
        $planId = (int)($_POST['plan_id'] ?? 2);
        $accountStatus = Security::sanitize(trim($_POST['account_status'] ?? 'ACTIVE'));
        
        // Manual Credentials Input (MUST NOT BE AUTO-GENERATED)
        $rawUsername = trim($_POST['username'] ?? '');
        $username = strtolower(Security::sanitize($rawUsername));
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $reqId = (int)($_POST['request_id'] ?? 0);

        // Normalize Nepali Phone Number (+977 or 98/97/96 prefix)
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
        if (preg_match('/^(?:\+?977)?([9][678][0-9]{8})$/', $cleanPhone, $mMatches)) {
            $normalizedPhone = '+977' . $mMatches[1];
        } else {
            $normalizedPhone = $cleanPhone;
        }

        // Check if request is already converted
        if ($reqId > 0 && $conn) {
            $rCheck = $conn->query("SELECT status FROM restaurant_requests WHERE id = {$reqId} LIMIT 1");
            if ($rCheck && $rRow = $rCheck->fetch_assoc()) {
                if ($rRow['status'] === 'CONVERTED') {
                    $error = "This onboarding request has already been converted into a restaurant tenant account.";
                }
            }
        }

        if (empty($error)) {
            // Validation Rules
            if (empty($restName) || empty($restCode) || empty($ownerName) || empty($email) || empty($phone) || empty($username) || empty($password) || empty($confirmPassword)) {
                $error = "Please fill in all required fields marked with *.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } elseif (!preg_match('/^(?:\+?977)?([9][678][0-9]{8})$/', $cleanPhone)) {
                $error = "Please enter a valid Nepali mobile number (e.g. 98XXXXXXXX, 97XXXXXXXX, or +97798XXXXXXXX).";
            } elseif ($password !== $confirmPassword) {
                $error = "Passwords do not match.";
            } elseif (strlen($password) < 8) {
                $error = "Password must be at least 8 characters long.";
            } else {
                // Server-Side Uniqueness Checks
                
                // 1. Check duplicate user email
                $checkUser = $conn->prepare("SELECT id FROM admin_users WHERE LOWER(email) = ? LIMIT 1");
                $checkUser->bind_param("s", $email);
                $checkUser->execute();
                if ($checkUser->get_result()->num_rows > 0) {
                    $error = "An account with this email address already exists.";
                    $checkUser->close();
                } else {
                    $checkUser->close();

                    // 2. Check duplicate restaurant code
                    $checkCode = $conn->prepare("SELECT id FROM restaurants WHERE restaurant_code = ? LIMIT 1");
                    $checkCode->bind_param("s", $restCode);
                    $checkCode->execute();
                    if ($checkCode->get_result()->num_rows > 0) {
                        $error = "Restaurant code is already registered. Please choose another code.";
                        $checkCode->close();
                    } else {
                        $checkCode->close();

                        // 3. Check duplicate email in restaurants
                        $checkEmail = $conn->prepare("SELECT id FROM restaurants WHERE email = ? LIMIT 1");
                        $checkEmail->bind_param("s", $email);
                        $checkEmail->execute();
                        if ($checkEmail->get_result()->num_rows > 0) {
                            $error = "An account with this email address already exists.";
                            $checkEmail->close();
                        } else {
                            $checkEmail->close();

                            // 4. Check duplicate PAN / VAT number (if provided)
                            if (!empty($panNumber)) {
                                $checkPan = $conn->prepare("SELECT id FROM restaurants WHERE pan_number = ? LIMIT 1");
                                $checkPan->bind_param("s", $panNumber);
                                $checkPan->execute();
                                if ($checkPan->get_result()->num_rows > 0) {
                                    $error = "An account already exists with this PAN/VAT number.";
                                    $checkPan->close();
                                } else {
                                    $checkPan->close();
                                }
                            }

                            if (empty($error)) {
                                // ALL VALIDATIONS PASSED -> BEGIN ATOMIC DATABASE TRANSACTION
                                $conn->begin_transaction();

                                try {
                                    $uuid = 'rest_' . bin2hex(random_bytes(12));
                                    $hashedPass = password_hash($password, PASSWORD_BCRYPT);

                                    // Insert Restaurant Record
                                    $stmtRest = $conn->prepare("
                                        INSERT INTO restaurants 
                                        (uuid, restaurant_code, restaurant_name, owner_name, email, phone, pan_number, address, restaurant_type, status, subscription_plan_id, subscription_status, subscription_start, subscription_end)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR))
                                    ");
                                    $stmtRest->bind_param("ssssssssssi", $uuid, $restCode, $restName, $ownerName, $email, $normalizedPhone, $panNumber, $address, $restType, $accountStatus, $planId);
                                    $stmtRest->execute();
                                    $newRestId = $stmtRest->insert_id;
                                    $stmtRest->close();

                                    // Insert Restaurant Admin User in admin_users (Role: owner / RESTAURANT_ADMIN)
                                    $usernameVal = !empty($username) ? $username : $email;
                                    $stmtUser = $conn->prepare("
                                        INSERT INTO admin_users (username, email, password, full_name, role, force_password_change, is_super_admin, restaurant_id)
                                        VALUES (?, ?, ?, ?, 'owner', 0, 0, ?)
                                    ");
                                    $stmtUser->bind_param("ssssi", $usernameVal, $email, $hashedPass, $ownerName, $newRestId);
                                    $stmtUser->execute();
                                    $stmtUser->close();

                                    // Insert Subscription Record
                                    $stmtSub = $conn->prepare("
                                        INSERT INTO subscriptions (restaurant_id, plan_id, status, start_date, end_date)
                                        VALUES (?, ?, 'ACTIVE', CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR))
                                    ");
                                    $stmtSub->bind_param("ii", $newRestId, $planId);
                                    $stmtSub->execute();
                                    $stmtSub->close();

                                    // Provision Default Tables (T-1 to T-N)
                                    $stmtTable = $conn->prepare("INSERT INTO tables (restaurant_id, table_number, qr_code, status, capacity) VALUES (?, ?, ?, 'AVAILABLE', 4)");
                                    for ($i = 1; $i <= $tableCount; $i++) {
                                        $tNum = "T-" . $i;
                                        $qrCode = "QR-" . $restCode . "-" . $i;
                                        $stmtTable->bind_param("iss", $newRestId, $tNum, $qrCode);
                                        $stmtTable->execute();
                                    }
                                    $stmtTable->close();

                                    // Provision Default Category ('General')
                                    $stmtCat = $conn->prepare("INSERT INTO categories (restaurant_id, name, display_order) VALUES (?, 'General', 1)");
                                    $stmtCat->bind_param("i", $newRestId);
                                    $stmtCat->execute();
                                    $stmtCat->close();

                                    // Update onboarding request status if converted
                                    if ($reqId > 0) {
                                        $conn->query("UPDATE restaurant_requests SET status = 'CONVERTED', internal_notes = 'Converted to Tenant ID #{$newRestId} ({$restCode})' WHERE id = {$reqId}");
                                    }

                                    // Record Security Audit Log (NEVER LOGGING PLAINTEXT PASSWORD!)
                                    Security::logAudit("SUPER_ADMIN_CREATE_TENANT", "Created restaurant tenant ID #{$newRestId} ({$restCode} - {$restName}) with admin username: {$username}");

                                    // COMMIT TRANSACTION
                                    $conn->commit();

                                    // Memory payload for immediate delivery screen
                                    $createdData = [
                                        'restaurant_id' => $newRestId,
                                        'restaurant_code' => $restCode,
                                        'restaurant_name' => $restName,
                                        'owner_name' => $ownerName,
                                        'email' => $email,
                                        'phone' => $normalizedPhone,
                                        'username' => $username,
                                        'password' => $password,
                                        'plan_id' => $planId,
                                        'table_count' => $tableCount
                                    ];
                                } catch (Exception $e) {
                                    $conn->rollback();
                                    $error = "Unable to create restaurant account: " . $e->getMessage();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$pageTitle = 'Create Restaurant Account';
require_once __DIR__ . '/includes/header.php';
$csrfField = CSRF::getField();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between border-b border-zinc-800 pb-6">
        <div>
            <h1 class="text-2xl font-black text-white tracking-tight">Create Restaurant Account</h1>
            <p class="text-xs text-zinc-400 mt-1 font-medium">Provision isolated multi-tenant workspace and manually assign administrator credentials.</p>
        </div>
        <a href="restaurants.php" onclick="return confirmCancel();" class="px-4 py-2 rounded-xl bg-zinc-900 border border-zinc-800 text-xs font-bold text-zinc-400 hover:text-white transition-colors">
            ← Back to Restaurants
        </a>
    </div>

    <?php if ($createdData): ?>
        <!-- CREDENTIAL DELIVERY CONFIRMATION SCREEN -->
        <div class="p-8 rounded-3xl bg-emerald-500/10 border border-emerald-500/30 space-y-6 shadow-2xl">
            <div class="flex items-center space-x-4">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-2xl font-black">
                    ✓
                </div>
                <div>
                    <h2 class="text-lg font-black text-white">Restaurant Account Created Successfully</h2>
                    <p class="text-xs text-emerald-400 font-semibold">Tenant workspace provisioned and administrator credentials securely initialized.</p>
                </div>
            </div>

            <div class="bg-zinc-950 p-6 rounded-2xl border border-zinc-800 space-y-4 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Restaurant Name</span>
                        <span class="text-white font-bold text-base block mt-0.5"><?= htmlspecialchars($createdData['restaurant_name']) ?></span>
                        <span class="text-amber-400 font-mono text-xs block font-bold">ID: #<?= $createdData['restaurant_id'] ?> &bull; Code: <?= htmlspecialchars($createdData['restaurant_code']) ?></span>
                    </div>
                    <div>
                        <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Owner & Contact</span>
                        <span class="text-zinc-200 font-bold block mt-0.5"><?= htmlspecialchars($createdData['owner_name']) ?></span>
                        <span class="text-zinc-400 block"><?= htmlspecialchars($createdData['email']) ?></span>
                        <span class="text-amber-400 font-mono block"><?= htmlspecialchars($createdData['phone']) ?></span>
                    </div>
                    <div>
                        <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Provisioned Environment</span>
                        <span class="text-emerald-400 font-bold block mt-0.5">Status: ACTIVE</span>
                        <span class="text-zinc-400 block"><?= $createdData['table_count'] ?> Tables Pre-Configured</span>
                    </div>
                </div>

                <div class="border-t border-zinc-800 pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Admin Username</span>
                        <span id="created-username" class="text-amber-400 font-mono font-black text-lg block mt-0.5 select-all"><?= htmlspecialchars($createdData['username']) ?></span>
                    </div>
                    <div>
                        <span class="text-zinc-500 block uppercase tracking-wider text-[10px] font-bold">Temporary Admin Password</span>
                        <span id="created-password" class="text-white font-mono font-bold text-lg block mt-0.5 select-all"><?= htmlspecialchars($createdData['password']) ?></span>
                    </div>
                </div>
            </div>

            <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-300 font-medium space-y-1">
                <div class="font-bold">⚠️ Save these credentials securely now.</div>
                <div>The password is not stored in plaintext anywhere in database records or audit logs and will not be displayed again.</div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" onclick="copyField('created-username', 'Copy Username')" id="btn-copy-user" class="px-5 py-2.5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all flex items-center space-x-1.5 shadow-lg shadow-amber-500/20">
                    <span>📋</span>
                    <span>Copy Username</span>
                </button>
                <button type="button" onclick="copyField('created-password', 'Copy Password')" id="btn-copy-pass" class="px-5 py-2.5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all flex items-center space-x-1.5 shadow-lg shadow-amber-500/20">
                    <span>🔑</span>
                    <span>Copy Password</span>
                </button>
                <button type="button" onclick="copyAllDetails()" id="btn-copy-all" class="px-5 py-2.5 rounded-2xl bg-zinc-800 text-white font-bold text-xs hover:bg-zinc-700 transition-all flex items-center space-x-1.5">
                    <span>📦</span>
                    <span>Copy All Details</span>
                </button>
                <a href="restaurants.php" class="px-6 py-2.5 rounded-2xl bg-emerald-500 text-zinc-950 font-black text-xs hover:bg-emerald-400 transition-all">
                    Done →
                </a>
            </div>
        </div>

        <script>
            function copyField(elementId, label) {
                const text = document.getElementById(elementId).innerText.trim();
                navigator.clipboard.writeText(text).then(() => {
                    alert(label + ' copied to clipboard!');
                });
            }

            function copyAllDetails() {
                const user = document.getElementById('created-username').innerText.trim();
                const pass = document.getElementById('created-password').innerText.trim();
                const rest = "<?= htmlspecialchars($createdData['restaurant_name'], ENT_QUOTES) ?>";
                const code = "<?= htmlspecialchars($createdData['restaurant_code'], ENT_QUOTES) ?>";
                const loginUrl = window.location.origin + "/admin/login.php";
                const text = `RMS SaaS Restaurant Account Credentials\n------------------------------------\nRestaurant: ${rest} (${code})\nAdmin Username: ${user}\nAdmin Password: ${pass}\nLogin Portal URL: ${loginUrl}`;
                
                navigator.clipboard.writeText(text).then(() => {
                    alert('All account credentials copied to clipboard!');
                });
            }
        </script>

    <?php else: ?>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold flex items-center space-x-2">
                <span>⚠️</span>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" id="create-form" onsubmit="return submitForm(this);" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-8 space-y-8 shadow-2xl">
            <?= $csrfField ?>
            <input type="hidden" name="request_id" value="<?= htmlspecialchars($prefill['request_id']) ?>">

            <!-- SECTION 1: RESTAURANT INFORMATION -->
            <div class="space-y-4">
                <h3 class="text-sm font-black uppercase tracking-wider text-amber-500 border-b border-zinc-800 pb-2 flex items-center justify-between">
                    <span>1. Restaurant & Tenant Information</span>
                    <span class="text-[10px] text-zinc-500 font-mono">System Tenant ID: Auto-Generated</span>
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Restaurant Name *</label>
                        <input type="text" name="restaurant_name" required value="<?= htmlspecialchars($_POST['restaurant_name'] ?? $prefill['restaurant_name']) ?>" placeholder="e.g. Royal Taste Cafe" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Restaurant Code * <span class="text-[10px] text-zinc-500">(Unique Identifier)</span></label>
                        <input type="text" name="restaurant_code" required value="<?= htmlspecialchars($_POST['restaurant_code'] ?? $prefill['restaurant_code']) ?>" placeholder="e.g. RTC001" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-amber-400 font-mono uppercase placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Owner Full Name *</label>
                        <input type="text" name="owner_name" required value="<?= htmlspecialchars($_POST['owner_name'] ?? $prefill['owner_name']) ?>" placeholder="e.g. Ramesh Sharma" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Owner Email Address *</label>
                        <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? $prefill['email']) ?>" placeholder="owner@restaurant.com" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Phone Number * <span class="text-[10px] text-zinc-500">(Nepali 98/97/96)</span></label>
                        <input type="text" name="phone" required value="<?= htmlspecialchars($_POST['phone'] ?? $prefill['phone']) ?>" placeholder="98XXXXXXXX or +97798XXXXXXXX" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500 font-mono">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">PAN / VAT Number <span class="text-[10px] text-zinc-500">(Optional)</span></label>
                        <input type="text" name="pan_number" value="<?= htmlspecialchars($_POST['pan_number'] ?? $prefill['pan_number']) ?>" placeholder="123456789" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Restaurant Type</label>
                        <select name="restaurant_type" id="rest-type-select" onchange="toggleCustomType(this);" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                            <option value="Fine Dining" <?= $prefill['restaurant_type'] === 'Fine Dining' ? 'selected' : '' ?>>Fine Dining</option>
                            <option value="Casual Dining" <?= $prefill['restaurant_type'] === 'Casual Dining' ? 'selected' : '' ?>>Casual Dining</option>
                            <option value="Fast Food / QSR" <?= $prefill['restaurant_type'] === 'Fast Food / QSR' ? 'selected' : '' ?>>Fast Food / QSR</option>
                            <option value="Cafe & Bakery" <?= $prefill['restaurant_type'] === 'Cafe & Bakery' ? 'selected' : '' ?>>Cafe & Bakery</option>
                            <option value="Food Court" <?= $prefill['restaurant_type'] === 'Food Court' ? 'selected' : '' ?>>Food Court</option>
                            <option value="Cloud Kitchen" <?= $prefill['restaurant_type'] === 'Cloud Kitchen' ? 'selected' : '' ?>>Cloud Kitchen</option>
                            <option value="Bar & Pub" <?= $prefill['restaurant_type'] === 'Bar & Pub' ? 'selected' : '' ?>>Bar & Pub</option>
                            <option value="Other">Other (Custom Type)</option>
                        </select>
                        <input type="text" name="custom_type" id="custom-type-input" placeholder="Enter custom type..." class="hidden mt-2 w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Initial Number of Tables *</label>
                        <input type="number" name="table_count" required min="1" max="100" value="<?= htmlspecialchars($_POST['table_count'] ?? $prefill['table_count']) ?>" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white outline-none focus:border-amber-500 font-mono">
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
                            <option value="TRIAL">TRIAL (Evaluation)</option>
                            <option value="SUSPENDED">SUSPENDED (Locked)</option>
                            <option value="INACTIVE">INACTIVE (Disabled)</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Full Address</label>
                        <textarea name="address" rows="2" placeholder="Street, City, District" class="w-full bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500"><?= htmlspecialchars($_POST['address'] ?? $prefill['address']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: MANUAL LOGIN CREDENTIALS -->
            <div class="space-y-4">
                <h3 class="text-sm font-black uppercase tracking-wider text-amber-500 border-b border-zinc-800 pb-2">2. Manual Administrator Credentials</h3>
                <p class="text-xs text-zinc-400">Super Admin explicitly assigns the login username and password. Credentials are not automatically generated.</p>
                
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Admin Username *</label>
                        <input type="text" name="username" id="username-field" required value="<?= htmlspecialchars($_POST['username'] ?? $prefill['username']) ?>" placeholder="e.g. royal_admin" onkeyup="checkUsernameStrength(this.value);" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500 font-mono">
                        <div id="username-hint" class="text-[10px] text-zinc-500 mt-1 font-mono">Allowed: letters, numbers, underscores (4-30 chars)</div>
                    </div>

                    <div class="relative">
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Admin Password *</label>
                        <div class="relative">
                            <input type="password" name="password" id="pass-field" required placeholder="••••••••••••" onkeyup="updatePassMeter(this.value);" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl pl-3.5 pr-10 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                            <button type="button" onclick="togglePassVisibility('pass-field');" class="absolute right-3 top-3 text-zinc-500 hover:text-white text-xs">👁️</button>
                        </div>
                        <!-- Password Strength Indicator -->
                        <div class="mt-1.5 flex items-center space-x-2">
                            <div class="flex-1 h-1.5 bg-zinc-950 border border-zinc-800 rounded-full overflow-hidden">
                                <div id="pass-meter" class="h-full w-0 bg-rose-500 transition-all duration-300"></div>
                            </div>
                            <span id="pass-label" class="text-[10px] font-bold text-zinc-500">Weak</span>
                        </div>
                    </div>

                    <div class="relative">
                        <label class="block text-xs font-bold text-zinc-400 mb-1.5">Confirm Password *</label>
                        <div class="relative">
                            <input type="password" name="confirm_password" id="confirm-pass-field" required placeholder="••••••••••••" onkeyup="checkPassMatch();" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-xl pl-3.5 pr-10 text-xs text-white placeholder-zinc-600 outline-none focus:border-amber-500">
                            <button type="button" onclick="togglePassVisibility('confirm-pass-field');" class="absolute right-3 top-3 text-zinc-500 hover:text-white text-xs">👁️</button>
                        </div>
                        <div id="match-hint" class="text-[10px] text-zinc-500 mt-1">Must match Admin Password</div>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-zinc-800 flex items-center justify-end space-x-3">
                <a href="restaurants.php" onclick="return confirmCancel();" class="px-5 py-3 rounded-2xl bg-zinc-800 text-xs font-bold text-zinc-300 hover:bg-zinc-700 transition-all">
                    Cancel
                </a>
                <button type="submit" id="submit-btn" class="px-6 py-3 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-xs hover:from-amber-400 hover:to-amber-300 transition-all shadow-lg shadow-amber-500/20">
                    Create Restaurant Account →
                </button>
            </div>
        </form>

        <script>
            let formIsDirty = false;
            document.querySelectorAll('#create-form input, #create-form select, #create-form textarea').forEach(el => {
                el.addEventListener('change', () => { formIsDirty = true; });
            });

            window.addEventListener('beforeunload', (e) => {
                if (formIsDirty) {
                    e.preventDefault();
                    e.returnValue = 'You have unsaved changes.';
                }
            });

            function confirmCancel() {
                if (formIsDirty) {
                    return confirm('You have unsaved changes. Are you sure you want to leave?');
                }
                return true;
            }

            function toggleCustomType(selectEl) {
                const customInput = document.getElementById('custom-type-input');
                if (selectEl.value === 'Other') {
                    customInput.classList.remove('hidden');
                    customInput.required = true;
                } else {
                    customInput.classList.add('hidden');
                    customInput.required = false;
                }
            }

            function togglePassVisibility(fieldId) {
                const el = document.getElementById(fieldId);
                el.type = (el.type === 'password') ? 'text' : 'password';
            }

            function updatePassMeter(val) {
                const meter = document.getElementById('pass-meter');
                const label = document.getElementById('pass-label');
                let score = 0;

                if (val.length >= 8) score++;
                if (/[A-Z]/.test(val)) score++;
                if (/[0-9]/.test(val)) score++;
                if (/[^a-zA-Z0-9]/.test(val)) score++;

                if (score <= 1) {
                    meter.style.width = '25%';
                    meter.className = 'h-full bg-rose-500 transition-all duration-300';
                    label.innerText = 'Weak';
                    label.className = 'text-[10px] font-bold text-rose-400';
                } else if (score === 2 || score === 3) {
                    meter.style.width = '60%';
                    meter.className = 'h-full bg-amber-500 transition-all duration-300';
                    label.innerText = 'Medium';
                    label.className = 'text-[10px] font-bold text-amber-400';
                } else {
                    meter.style.width = '100%';
                    meter.className = 'h-full bg-emerald-500 transition-all duration-300';
                    label.innerText = 'Strong';
                    label.className = 'text-[10px] font-bold text-emerald-400';
                }
                checkPassMatch();
            }

            function checkPassMatch() {
                const pass = document.getElementById('pass-field').value;
                const confirmPass = document.getElementById('confirm-pass-field').value;
                const hint = document.getElementById('match-hint');

                if (confirmPass.length > 0) {
                    if (pass === confirmPass) {
                        hint.innerText = '✓ Passwords match';
                        hint.className = 'text-[10px] font-bold text-emerald-400 mt-1';
                    } else {
                        hint.innerText = '✕ Passwords do not match';
                        hint.className = 'text-[10px] font-bold text-rose-400 mt-1';
                    }
                } else {
                    hint.innerText = 'Must match Admin Password';
                    hint.className = 'text-[10px] text-zinc-500 mt-1';
                }
            }

            function checkUsernameStrength(val) {
                const hint = document.getElementById('username-hint');
                if (val.length > 0) {
                    if (/^[a-zA-Z0-9_]{4,30}$/.test(val)) {
                        hint.innerText = '✓ Username format valid';
                        hint.className = 'text-[10px] font-bold text-emerald-400 mt-1 font-mono';
                    } else {
                        hint.innerText = '✕ 4-30 characters, letters/numbers/underscores only';
                        hint.className = 'text-[10px] font-bold text-rose-400 mt-1 font-mono';
                    }
                }
            }

            function submitForm(form) {
                const btn = document.getElementById('submit-btn');
                btn.disabled = true;
                btn.innerText = 'Creating Restaurant Account...';
                btn.classList.add('opacity-75', 'cursor-not-allowed');
                formIsDirty = false;
                return true;
            }
        </script>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
