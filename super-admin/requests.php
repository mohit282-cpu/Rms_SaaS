<?php
// super-admin/requests.php - Public Onboarding Requests Pipeline & Tenant Provisioning Workflow
require_once __DIR__ . '/../config.php';
Auth::requireSuperAdmin();

$conn = getDBConnection();
$message = null;
$error = null;
$createdData = null;

// Handle Request Actions (Processed before HTML output)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        $reqId = (int)($_POST['request_id'] ?? 0);

        if ($reqId > 0 && $conn) {
            // Fetch target request
            $rStmt = $conn->prepare("SELECT * FROM restaurant_requests WHERE id = ? LIMIT 1");
            $rStmt->bind_param("i", $reqId);
            $rStmt->execute();
            $req = $rStmt->get_result()->fetch_assoc();
            $rStmt->close();

            if (!$req) {
                $error = "Onboarding request not found.";
            } else {
                if ($action === 'mark_contacted') {
                    if ($req['status'] === 'CONVERTED') {
                        $error = "This restaurant has already been onboarded.";
                    } else {
                        $notes = Security::sanitize(trim($_POST['internal_notes'] ?? $req['internal_notes']));
                        $conn->query("UPDATE restaurant_requests SET status = 'CONTACTED', internal_notes = '{$notes}' WHERE id = {$reqId}");
                        Security::logAudit("REQUEST_CONTACTED", "Super Admin marked request #{$reqId} ({$req['restaurant_name']}) as CONTACTED");
                        $message = "Request status updated to CONTACTED.";
                    }
                } elseif ($action === 'reject') {
                    if ($req['status'] === 'CONVERTED') {
                        $error = "Cannot reject an already onboarded restaurant request.";
                    } else {
                        $reason = Security::sanitize(trim($_POST['rejection_reason'] ?? 'Invalid Information'));
                        $notes = Security::sanitize(trim($_POST['internal_notes'] ?? ''));
                        $fullNotes = trim($req['internal_notes'] . "\nRejection Reason: " . $reason . ($notes ? " ({$notes})" : ""));
                        
                        $stmt = $conn->prepare("UPDATE restaurant_requests SET status = 'REJECTED', rejection_reason = ?, internal_notes = ? WHERE id = ?");
                        if ($stmt) {
                            $stmt->bind_param("ssi", $reason, $fullNotes, $reqId);
                            $stmt->execute();
                            $stmt->close();
                        }
                        Security::logAudit("REQUEST_REJECTED", "Super Admin rejected request #{$reqId} ({$req['restaurant_name']}). Reason: {$reason}");
                        $message = "Request rejected successfully.";
                    }
                } elseif ($action === 'update_notes') {
                    $notes = Security::sanitize(trim($_POST['internal_notes'] ?? ''));
                    $conn->query("UPDATE restaurant_requests SET internal_notes = '{$notes}' WHERE id = {$reqId}");
                    $message = "Internal notes saved successfully.";
                } elseif ($action === 'onboard_tenant') {
                    // Full Onboarding & Tenant Creation Workflow
                    if ($req['status'] === 'CONVERTED') {
                        $error = "This restaurant has already been onboarded.";
                    } else {
                        $restName = Security::sanitize(trim($_POST['restaurant_name'] ?? $req['restaurant_name']));
                        $restCode = strtoupper(Security::sanitize(trim($_POST['restaurant_code'] ?? '')));
                        $ownerName = Security::sanitize(trim($_POST['owner_name'] ?? $req['owner_name']));
                        $email = strtolower(Security::sanitize(trim($_POST['email'] ?? $req['email'])));
                        $phone = Security::sanitize(trim($_POST['phone'] ?? $req['phone']));
                        $panNumber = Security::sanitize(trim($_POST['pan_number'] ?? $req['pan_number']));
                        $address = Security::sanitize(trim($_POST['address'] ?? $req['address']));
                        $restType = Security::sanitize(trim($_POST['restaurant_type'] ?? $req['restaurant_type']));
                        $tableCount = max(1, (int)($_POST['table_count'] ?? $req['table_count']));
                        $planId = (int)($_POST['plan_id'] ?? 2);
                        
                        // Manual Admin Credentials Input
                        $rawUsername = trim($_POST['username'] ?? '');
                        $username = strtolower(Security::sanitize($rawUsername));
                        $password = $_POST['password'] ?? '';
                        $confirmPassword = $_POST['confirm_password'] ?? '';

                        // Normalize Phone
                        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
                        if (preg_match('/^(?:\+?977)?([9][678][0-9]{8})$/', $cleanPhone, $mMatches)) {
                            $normalizedPhone = '+977' . $mMatches[1];
                        } else {
                            $normalizedPhone = $cleanPhone;
                        }

                        // Validation Rules
                        if (empty($restName) || empty($restCode) || empty($ownerName) || empty($email) || empty($phone) || empty($username) || empty($password) || empty($confirmPassword)) {
                            $error = "Please fill in all required fields marked with *.";
                        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $error = "Please enter a valid email address.";
                        } elseif (!preg_match('/^[a-zA-Z0-9_]{4,30}$/', $username)) {
                            $error = "Username must be between 4 and 30 characters long and contain only letters, numbers, or underscores.";
                        } elseif ($password !== $confirmPassword) {
                            $error = "Passwords do not match.";
                        } elseif (strlen($password) < 8) {
                            $error = "Password must be at least 8 characters long.";
                        } else {
                            // Server-Side Uniqueness Checks
                            $cUser = $conn->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
                            $cUser->bind_param("s", $username);
                            $cUser->execute();
                            if ($cUser->get_result()->num_rows > 0) {
                                $error = "Admin username is already in use. Please choose another username.";
                                $cUser->close();
                            } else {
                                $cUser->close();

                                $cCode = $conn->prepare("SELECT id FROM restaurants WHERE restaurant_code = ? LIMIT 1");
                                $cCode->bind_param("s", $restCode);
                                $cCode->execute();
                                if ($cCode->get_result()->num_rows > 0) {
                                    $error = "Restaurant code is already registered. Please choose another code.";
                                    $cCode->close();
                                } else {
                                    $cCode->close();

                                    $cEmail = $conn->prepare("SELECT id FROM restaurants WHERE email = ? LIMIT 1");
                                    $cEmail->bind_param("s", $email);
                                    $cEmail->execute();
                                    if ($cEmail->get_result()->num_rows > 0) {
                                        $error = "An account with this email address already exists.";
                                        $cEmail->close();
                                    } else {
                                        $cEmail->close();

                                        // ATOMIC TRANSACTION FOR PROVISIONING
                                        $conn->begin_transaction();

                                        try {
                                            $uuid = 'rest_' . bin2hex(random_bytes(12));
                                            $hashedPass = password_hash($password, PASSWORD_BCRYPT);

                                            // Insert Restaurant
                                            $stmtRest = $conn->prepare("
                                                INSERT INTO restaurants 
                                                (uuid, restaurant_code, restaurant_name, owner_name, email, phone, pan_number, address, restaurant_type, status, subscription_plan_id, subscription_status, subscription_start, subscription_end)
                                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?, 'ACTIVE', CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR))
                                            ");
                                            $stmtRest->bind_param("sssssssssi", $uuid, $restCode, $restName, $ownerName, $email, $normalizedPhone, $panNumber, $address, $restType, $planId);
                                            $stmtRest->execute();
                                            $newRestId = $stmtRest->insert_id;
                                            $stmtRest->close();

                                            // Insert Admin User (Owner Role)
                                            $stmtUser = $conn->prepare("
                                                INSERT INTO admin_users (username, password, full_name, role, force_password_change, is_super_admin, restaurant_id)
                                                VALUES (?, ?, ?, 'owner', 0, 0, ?)
                                            ");
                                            $stmtUser->bind_param("sssi", $username, $hashedPass, $ownerName, $newRestId);
                                            $stmtUser->execute();
                                            $stmtUser->close();

                                            // Insert Active Subscription
                                            $stmtSub = $conn->prepare("
                                                INSERT INTO subscriptions (restaurant_id, plan_id, status, start_date, end_date)
                                                VALUES (?, ?, 'ACTIVE', CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR))
                                            ");
                                            $stmtSub->bind_param("ii", $newRestId, $planId);
                                            $stmtSub->execute();
                                            $stmtSub->close();

                                            // Insert Default Tables
                                            $stmtTable = $conn->prepare("INSERT INTO tables (restaurant_id, table_number, qr_code, status, capacity) VALUES (?, ?, ?, 'AVAILABLE', 4)");
                                            for ($i = 1; $i <= $tableCount; $i++) {
                                                $tNum = "T-" . $i;
                                                $qrCode = "QR-" . $restCode . "-" . $i;
                                                $stmtTable->bind_param("iss", $newRestId, $tNum, $qrCode);
                                                $stmtTable->execute();
                                            }
                                            $stmtTable->close();

                                            // Insert Default Category
                                            $stmtCat = $conn->prepare("INSERT INTO categories (restaurant_id, name, display_order) VALUES (?, 'General', 1)");
                                            $stmtCat->bind_param("i", $newRestId);
                                            $stmtCat->execute();
                                            $stmtCat->close();

                                            // Mark Request as CONVERTED & Link Tenant ID
                                            $conn->query("UPDATE restaurant_requests SET status = 'CONVERTED', tenant_id = {$newRestId}, internal_notes = 'Onboarded to Tenant ID #{$newRestId} ({$restCode})' WHERE id = {$reqId}");

                                            // Security Audit Logging (NEVER LOGGING PASSWORD)
                                            Security::logAudit("SUPER_ADMIN_CREATE_TENANT", "Onboarded request #{$reqId} into restaurant tenant #{$newRestId} ({$restCode}) with admin username: {$username}");

                                            // COMMIT TRANSACTION
                                            $conn->commit();

                                            $createdData = [
                                                'restaurant_id' => $newRestId,
                                                'restaurant_code' => $restCode,
                                                'restaurant_name' => $restName,
                                                'owner_name' => $ownerName,
                                                'email' => $email,
                                                'username' => $username,
                                                'password' => $password
                                            ];
                                        } catch (Exception $e) {
                                            $conn->rollback();
                                            $error = "Unable to complete restaurant onboarding: " . $e->getMessage();
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// Fetch Active Subscription Plans for Modal Dropdown
$plans = [];
if ($conn) {
    $pRes = $conn->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price_monthly ASC");
    if ($pRes) {
        while ($p = $pRes->fetch_assoc()) {
            $plans[] = $p;
        }
    }
}

// Search & Filtering & Pagination Logic
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$sortOrder = trim($_GET['sort'] ?? 'newest');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$whereClauses = ["1=1"];
if (!empty($search)) {
    $safeSearch = $conn->real_escape_string($search);
    $whereClauses[] = "(restaurant_name LIKE '%{$safeSearch}%' OR owner_name LIKE '%{$safeSearch}%' OR email LIKE '%{$safeSearch}%' OR phone LIKE '%{$safeSearch}%' OR pan_number LIKE '%{$safeSearch}%' OR request_code LIKE '%{$safeSearch}%' OR id = '{$safeSearch}')";
}

if (!empty($statusFilter)) {
    $safeStatus = $conn->real_escape_string($statusFilter);
    $whereClauses[] = "status = '{$safeStatus}'";
}

$whereSql = implode(' AND ', $whereClauses);

$orderBy = "id DESC";
if ($sortOrder === 'oldest') $orderBy = "id ASC";
elseif ($sortOrder === 'updated') $orderBy = "updated_at DESC";

// Count Total Records
$totalRecords = 0;
$countRes = $conn->query("SELECT COUNT(*) as total FROM restaurant_requests WHERE {$whereSql}");
if ($countRes && $cRow = $countRes->fetch_assoc()) {
    $totalRecords = (int)$cRow['total'];
}
$totalPages = max(1, ceil($totalRecords / $limit));

$query = "SELECT * FROM restaurant_requests WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}";
$requests = [];
if ($conn) {
    $res = $conn->query($query);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $requests[] = $row;
        }
    }
}

$pageTitle = 'Restaurant Onboarding Requests';
require_once __DIR__ . '/includes/header.php';
$csrfField = CSRF::getField();
?>

<div class="space-y-6">
    <!-- Header Title Banner -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-zinc-800 pb-6">
        <div>
            <h1 class="text-2xl font-black text-white tracking-tight">Onboarding Requests Pipeline</h1>
            <p class="text-xs text-zinc-400 mt-1 font-medium">Review demo requests, contact owners, approve applications, and provision tenant accounts.</p>
        </div>
        <a href="create-restaurant.php" class="px-4 py-2.5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all flex items-center space-x-1.5 self-start sm:self-auto shadow-lg shadow-amber-500/20">
            <span>+ Manual Account Onboarding</span>
        </a>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold flex items-center space-x-2">
            <span>✅</span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold flex items-center space-x-2">
            <span>⚠️</span>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($createdData): ?>
        <!-- SUCCESS CREDENTIAL DELIVERY BANNER -->
        <div class="p-8 rounded-3xl bg-emerald-500/10 border border-emerald-500/30 space-y-4 shadow-2xl">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-xl font-black">
                    ✓
                </div>
                <div>
                    <h2 class="text-base font-black text-white">Restaurant Tenant Provisioned Successfully!</h2>
                    <p class="text-xs text-emerald-400 font-semibold">Account created and onboarding request transitioned to CONVERTED.</p>
                </div>
            </div>

            <div class="bg-zinc-950 p-4 rounded-2xl border border-zinc-800 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs font-mono">
                <div>
                    <span class="text-zinc-500 block text-[10px]">Restaurant</span>
                    <strong class="text-white select-all"><?= htmlspecialchars($createdData['restaurant_name']) ?> (<?= htmlspecialchars($createdData['restaurant_code']) ?>)</strong>
                </div>
                <div>
                    <span class="text-zinc-500 block text-[10px]">Admin Username</span>
                    <strong id="deliv-user" class="text-amber-400 select-all"><?= htmlspecialchars($createdData['username']) ?></strong>
                </div>
                <div>
                    <span class="text-zinc-500 block text-[10px]">Admin Password</span>
                    <strong id="deliv-pass" class="text-white select-all"><?= htmlspecialchars($createdData['password']) ?></strong>
                </div>
            </div>

            <div class="flex items-center space-x-2">
                <button type="button" onclick="copyDeliveryDetails();" id="btn-copy-deliv" class="px-5 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400">
                    📋 Copy Login Credentials
                </button>
                <a href="requests.php" class="px-4 py-2.5 rounded-xl bg-zinc-800 text-white font-bold text-xs hover:bg-zinc-700">Dismiss</a>
            </div>
        </div>

        <script>
            function copyDeliveryDetails() {
                const user = document.getElementById('deliv-user').innerText.trim();
                const pass = document.getElementById('deliv-pass').innerText.trim();
                const text = `RMS SaaS Credentials\nUsername: ${user}\nPassword: ${pass}\nLogin: http://${window.location.host}/admin/login.php`;
                navigator.clipboard.writeText(text).then(() => {
                    alert('Credentials copied to clipboard!');
                });
            }
        </script>
    <?php endif; ?>

    <!-- Search & Filter Controls Bar -->
    <form method="GET" class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800 flex flex-col md:flex-row items-center gap-4 shadow-xl">
        <div class="flex-1 w-full">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by restaurant, owner, email, phone, PAN, request code..." class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3.5 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 transition-colors">
        </div>

        <div class="w-full md:w-40">
            <select name="status" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                <option value="">All Statuses</option>
                <option value="PENDING" <?= $statusFilter === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                <option value="CONTACTED" <?= $statusFilter === 'CONTACTED' ? 'selected' : '' ?>>Contacted</option>
                <option value="APPROVED" <?= $statusFilter === 'APPROVED' ? 'selected' : '' ?>>Approved</option>
                <option value="CONVERTED" <?= $statusFilter === 'CONVERTED' ? 'selected' : '' ?>>Onboarded</option>
                <option value="REJECTED" <?= $statusFilter === 'REJECTED' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </div>

        <div class="w-full md:w-36">
            <select name="sort" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-xs text-white outline-none focus:border-amber-500">
                <option value="newest" <?= $sortOrder === 'newest' ? 'selected' : '' ?>>Newest First</option>
                <option value="oldest" <?= $sortOrder === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                <option value="updated" <?= $sortOrder === 'updated' ? 'selected' : '' ?>>Recently Updated</option>
            </select>
        </div>

        <div class="flex items-center space-x-2 w-full md:w-auto">
            <button type="submit" class="h-10 px-5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all shadow-md">
                Search
            </button>
            <?php if (!empty($search) || !empty($statusFilter) || $sortOrder !== 'newest'): ?>
                <a href="requests.php" class="h-10 px-4 rounded-xl border border-zinc-800 bg-zinc-950 text-xs font-bold text-zinc-400 hover:text-white flex items-center justify-center">
                    Reset
                </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Requests Table -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl overflow-hidden shadow-2xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[850px]">
                <thead>
                    <tr class="border-b border-zinc-800 bg-zinc-950/60 text-[11px] font-black uppercase text-zinc-400 tracking-wider">
                        <th class="py-3.5 px-4">Restaurant & Owner</th>
                        <th class="py-3.5 px-4">Contact Info</th>
                        <th class="py-3.5 px-4">Requested Plan & Tables</th>
                        <th class="py-3.5 px-4">Status</th>
                        <th class="py-3.5 px-4 text-right">Workflow Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60 text-xs">
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="5" class="py-12 text-center space-y-2">
                                <div class="text-4xl">📬</div>
                                <div class="text-sm font-bold text-white">No onboarding requests found.</div>
                                <p class="text-xs text-zinc-500">New restaurant registration requests submitted via the public landing page will appear here.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req): ?>
                            <tr class="hover:bg-zinc-800/30 transition-colors">
                                <td class="py-4 px-4">
                                    <div class="font-bold text-white text-sm flex items-center space-x-2">
                                        <span><?= htmlspecialchars($req['restaurant_name']) ?></span>
                                        <span class="text-[10px] font-mono px-2 py-0.5 rounded-md bg-zinc-800 text-amber-400 font-bold"><?= htmlspecialchars($req['request_code'] ?: ('REQ-' . $req['id'])) ?></span>
                                    </div>
                                    <div class="text-xs text-zinc-300 font-medium mt-0.5">Owner: <?= htmlspecialchars($req['owner_name']) ?></div>
                                    <div class="text-[10px] text-zinc-500 mt-0.5">Submitted <?= date('M d, Y H:i', strtotime($req['created_at'])) ?></div>
                                </td>

                                <td class="py-4 px-4">
                                    <div class="font-mono text-amber-400 font-bold"><?= htmlspecialchars($req['phone']) ?></div>
                                    <div class="text-zinc-400 text-[11px]"><?= htmlspecialchars($req['email']) ?></div>
                                    <div class="text-zinc-500 text-[10px]">PAN: <?= htmlspecialchars($req['pan_number'] ?: 'N/A') ?></div>
                                </td>

                                <td class="py-4 px-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px] font-black uppercase">
                                        <?= htmlspecialchars($req['preferred_plan'] ?: 'BUSINESS') ?>
                                    </span>
                                    <div class="text-zinc-300 font-semibold text-[11px] mt-1"><?= htmlspecialchars($req['restaurant_type'] ?: 'Casual Dining') ?></div>
                                    <div class="text-[10px] text-zinc-500"><?= (int)$req['table_count'] ?> Tables Requested</div>
                                </td>

                                <td class="py-4 px-4">
                                    <?php
                                    $st = $req['status'];
                                    $badge = 'bg-zinc-800 text-zinc-400';
                                    if ($st === 'PENDING') $badge = 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
                                    elseif ($st === 'CONTACTED') $badge = 'bg-blue-500/10 text-blue-400 border border-blue-500/20';
                                    elseif ($st === 'APPROVED' || $st === 'CONVERTED') $badge = 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
                                    elseif ($st === 'REJECTED') $badge = 'bg-rose-500/10 text-rose-400 border border-rose-500/20';
                                    ?>
                                    <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase <?= $badge ?>">
                                        <?= ($st === 'CONVERTED') ? 'ONBOARDED' : htmlspecialchars($st) ?>
                                    </span>
                                </td>

                                <td class="py-4 px-4 text-right">
                                    <div class="flex items-center justify-end space-x-1.5">
                                        <!-- View Full Details Drawer Trigger -->
                                        <button type="button" onclick="openDetailsModal(<?= htmlspecialchars(json_encode($req), ENT_QUOTES) ?>);" class="px-2.5 py-1.5 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-[11px] font-bold transition-all">
                                            📋 Details
                                        </button>

                                        <?php if ($st === 'PENDING' || $st === 'CONTACTED'): ?>
                                            <!-- Approve & Onboard Modal Trigger -->
                                            <button type="button" onclick="openOnboardModal(<?= htmlspecialchars(json_encode($req), ENT_QUOTES) ?>);" class="px-3 py-1.5 rounded-xl bg-emerald-500 text-zinc-950 font-black text-xs hover:bg-emerald-400 transition-all shadow-md shadow-emerald-500/20">
                                                ✓ Approve & Onboard
                                            </button>

                                            <!-- Mark Contacted -->
                                            <?php if ($st === 'PENDING'): ?>
                                                <form method="POST" class="inline">
                                                    <?= $csrfField ?>
                                                    <input type="hidden" name="action" value="mark_contacted">
                                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                    <button type="submit" class="px-2.5 py-1.5 rounded-xl bg-blue-500/10 border border-blue-500/30 text-blue-400 hover:bg-blue-500/20 text-[11px] font-bold transition-all">
                                                        Mark Contacted
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Reject Modal Trigger -->
                                            <button type="button" onclick="openRejectModal(<?= $req['id'] ?>, '<?= htmlspecialchars($req['restaurant_name'], ENT_QUOTES) ?>');" class="px-2.5 py-1.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 hover:bg-rose-500/20 text-[11px] font-bold transition-all">
                                                ✕ Reject
                                            </button>
                                        <?php else: ?>
                                            <span class="text-[11px] text-zinc-500 font-medium px-2 py-1 bg-zinc-950 rounded-lg">Immutable</span>
                                        <?php endif; ?>
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
                    Showing <strong class="text-white"><?= min($totalRecords, $offset + 1) ?></strong> to <strong class="text-white"><?= min($totalRecords, $offset + count($requests)) ?></strong> of <strong class="text-white"><?= number_format($totalRecords) ?></strong> requests
                </div>
                <div class="flex items-center space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= urlencode($sortOrder) ?>" class="px-3 py-1.5 rounded-xl border border-zinc-800 bg-zinc-900 text-white font-bold hover:bg-zinc-800">← Previous</a>
                    <?php endif; ?>
                    <span class="px-3 py-1.5 rounded-xl bg-zinc-900 border border-zinc-800 font-bold text-amber-400">Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= urlencode($sortOrder) ?>" class="px-3 py-1.5 rounded-xl border border-zinc-800 bg-zinc-900 text-white font-bold hover:bg-zinc-800">Next →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL 1: APPROVE & ONBOARD TENANT PROVISIONING -->
<div id="onboard-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-emerald-500/30 rounded-3xl p-6 max-w-2xl w-full space-y-6 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <div>
                <h3 class="text-base font-black text-white">Approve Request & Provision Tenant</h3>
                <p id="onb-req-code" class="text-xs text-amber-400 font-mono font-bold"></p>
            </div>
            <button onclick="closeOnboardModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>

        <form method="POST" onsubmit="return confirmOnboard(this);" class="space-y-6 text-xs">
            <?= $csrfField ?>
            <input type="hidden" name="action" value="onboard_tenant">
            <input type="hidden" name="request_id" id="onb-req-id">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Restaurant Name *</label>
                    <input type="text" name="restaurant_name" id="onb-rest-name" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">System Restaurant Code *</label>
                    <input type="text" name="restaurant_code" id="onb-rest-code" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-amber-400 font-mono uppercase outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Owner Full Name *</label>
                    <input type="text" name="owner_name" id="onb-owner-name" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Owner Email *</label>
                    <input type="email" name="email" id="onb-email" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Phone Number *</label>
                    <input type="text" name="phone" id="onb-phone" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500 font-mono">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">PAN / VAT Number</label>
                    <input type="text" name="pan_number" id="onb-pan" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500 font-mono">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Initial Table Count *</label>
                    <input type="number" name="table_count" id="onb-tables" required min="1" max="100" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500 font-mono">
                </div>
                <div>
                    <label class="block font-bold text-zinc-400 mb-1">Subscription Plan *</label>
                    <select name="plan_id" id="onb-plan-id" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                        <?php foreach ($plans as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (NPR <?= number_format($p['price_monthly']) ?>/mo)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Manual Administrator Credentials Input -->
            <div class="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-4">
                <div class="font-bold text-amber-400 uppercase tracking-wider text-[11px]">Manual Administrator Credentials</div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-bold text-zinc-400 mb-1">Admin Username *</label>
                        <input type="text" name="username" id="onb-user" required placeholder="e.g. royal_admin" class="w-full h-10 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white font-mono outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-400 mb-1">Admin Password *</label>
                        <input type="password" name="password" required minlength="8" placeholder="••••••••••••" class="w-full h-10 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-400 mb-1">Confirm Password *</label>
                        <input type="password" name="confirm_password" required minlength="8" placeholder="••••••••••••" class="w-full h-10 bg-zinc-900 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closeOnboardModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" id="onb-submit-btn" class="px-6 py-2.5 rounded-xl bg-emerald-500 text-zinc-950 font-black text-xs hover:bg-emerald-400 shadow-lg shadow-emerald-500/20">Confirm & Provision Tenant →</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: REJECT REQUEST CONFIRMATION -->
<div id="reject-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-rose-500/30 rounded-3xl p-6 max-w-md w-full space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-black text-white">Reject Onboarding Request</h3>
            <button onclick="closeRejectModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>
        <form method="POST" class="space-y-4 text-xs">
            <?= $csrfField ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="rej-req-id">

            <div>
                <span class="text-zinc-400 block">Target Request:</span>
                <div id="rej-rest-name" class="text-sm font-bold text-white mt-0.5"></div>
            </div>

            <div>
                <label class="block font-bold text-zinc-400 mb-1">Rejection Reason *</label>
                <select name="rejection_reason" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                    <option value="Invalid Information">Invalid Information</option>
                    <option value="Restaurant Not Eligible">Restaurant Not Eligible</option>
                    <option value="Duplicate Application">Duplicate Application</option>
                    <option value="Owner Cancelled Request">Owner Cancelled Request</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div>
                <label class="block font-bold text-zinc-400 mb-1">Additional Internal Notes</label>
                <textarea name="internal_notes" rows="2" placeholder="Optional explanation..." class="w-full bg-zinc-950 border border-zinc-800 rounded-xl p-3 text-white outline-none focus:border-amber-500"></textarea>
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-zinc-800">
                <button type="button" onclick="closeRejectModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-rose-500 text-white font-black text-xs hover:bg-rose-600 shadow-lg shadow-rose-500/20">Reject Request</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: REQUEST DETAILS & AUDIT WORKFLOW DRAWER -->
<div id="details-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-2xl w-full space-y-6 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <div>
                <h3 class="text-base font-black text-white">Onboarding Request Details</h3>
                <p id="det-code" class="text-xs text-amber-400 font-mono font-bold"></p>
            </div>
            <button onclick="closeDetailsModal()" class="text-zinc-500 hover:text-white font-mono text-lg">&times;</button>
        </div>

        <div class="space-y-6 text-xs">
            <!-- Workflow Lifecycle Progress Bar -->
            <div>
                <span class="text-zinc-500 uppercase tracking-wider text-[10px] font-bold block mb-2">Request Lifecycle Progression</span>
                <div class="flex items-center justify-between bg-zinc-950 p-3 rounded-2xl border border-zinc-800 text-[11px] font-bold">
                    <span id="wf-pending" class="px-2.5 py-1 rounded-xl bg-zinc-800 text-zinc-400">1. PENDING</span>
                    <span class="text-zinc-700">→</span>
                    <span id="wf-contacted" class="px-2.5 py-1 rounded-xl bg-zinc-800 text-zinc-400">2. CONTACTED</span>
                    <span class="text-zinc-700">→</span>
                    <span id="wf-final" class="px-2.5 py-1 rounded-xl bg-zinc-800 text-zinc-400">3. ONBOARDED</span>
                </div>
            </div>

            <!-- Details Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-zinc-950 p-4 rounded-2xl border border-zinc-800 space-y-2">
                    <div class="font-bold text-amber-400 uppercase tracking-wider text-[10px]">Restaurant Information</div>
                    <div>Name: <strong id="det-rest-name" class="text-white"></strong></div>
                    <div>Type: <strong id="det-rest-type" class="text-zinc-300"></strong></div>
                    <div>Tables Requested: <strong id="det-tables" class="text-white"></strong></div>
                    <div>PAN/VAT: <strong id="det-pan" class="text-zinc-300"></strong></div>
                    <div>Address: <span id="det-address" class="text-zinc-400"></span></div>
                </div>

                <div class="bg-zinc-950 p-4 rounded-2xl border border-zinc-800 space-y-2">
                    <div class="font-bold text-amber-400 uppercase tracking-wider text-[10px]">Owner & Contact</div>
                    <div>Owner Name: <strong id="det-owner" class="text-white"></strong></div>
                    <div>Email: <strong id="det-email" class="text-zinc-300"></strong></div>
                    <div>Phone: <strong id="det-phone" class="text-amber-400 font-mono"></strong></div>
                    <div>Submitted At: <span id="det-submitted" class="text-zinc-400 font-mono"></span></div>
                </div>
            </div>

            <!-- Business Requirements Message -->
            <div class="bg-zinc-950 p-4 rounded-2xl border border-zinc-800 space-y-1">
                <div class="font-bold text-zinc-400 uppercase tracking-wider text-[10px]">Requirements Message</div>
                <p id="det-msg" class="text-zinc-300 italic leading-relaxed"></p>
            </div>

            <!-- Internal Notes Editor -->
            <form method="POST" class="bg-zinc-950 p-4 rounded-2xl border border-zinc-800 space-y-3">
                <?= $csrfField ?>
                <input type="hidden" name="action" value="update_notes">
                <input type="hidden" name="request_id" id="det-notes-req-id">
                <div class="font-bold text-amber-400 uppercase tracking-wider text-[10px]">Super Admin Internal Notes</div>
                <textarea name="internal_notes" id="det-notes-text" rows="3" placeholder="Add confidential notes visible only to Super Admin..." class="w-full bg-zinc-900 border border-zinc-800 rounded-xl p-3 text-xs text-white outline-none focus:border-amber-500"></textarea>
                <div class="flex justify-end">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400">Save Internal Notes</button>
                </div>
            </form>
        </div>

        <div class="flex items-center justify-end pt-2 border-t border-zinc-800">
            <button type="button" onclick="closeDetailsModal()" class="px-4 py-2 rounded-xl bg-zinc-800 text-xs font-bold text-zinc-300">Close</button>
        </div>
    </div>
</div>

<script>
    // Modal 1: Approve & Onboard
    function openOnboardModal(req) {
        document.getElementById('onb-req-id').value = req.id;
        document.getElementById('onb-req-code').innerText = 'Request Code: ' + (req.request_code || ('REQ-' + req.id));
        document.getElementById('onb-rest-name').value = req.restaurant_name;
        document.getElementById('onb-owner-name').value = req.owner_name;
        document.getElementById('onb-email').value = req.email;
        document.getElementById('onb-phone').value = req.phone;
        document.getElementById('onb-pan').value = req.pan_number || '';
        document.getElementById('onb-tables').value = req.table_count || 10;
        
        // Auto-generate clean code suggestion
        const cleanCode = 'RMS-' + String(req.id).padStart(6, '0');
        document.getElementById('onb-rest-code').value = cleanCode;

        // Auto-suggest clean username based on owner
        const cleanUser = req.owner_name.toLowerCase().replace(/[^a-z0-9_]/g, '');
        document.getElementById('onb-user').value = cleanUser.substring(0, 20);

        document.getElementById('onboard-modal').classList.remove('hidden');
    }
    function closeOnboardModal() {
        document.getElementById('onboard-modal').classList.add('hidden');
    }
    function confirmOnboard(form) {
        const btn = document.getElementById('onb-submit-btn');
        btn.disabled = true;
        btn.innerText = 'Provisioning Tenant...';
        btn.classList.add('opacity-75', 'cursor-not-allowed');
        return true;
    }

    // Modal 2: Reject
    function openRejectModal(reqId, restName) {
        document.getElementById('rej-req-id').value = reqId;
        document.getElementById('rej-rest-name').innerText = restName;
        document.getElementById('reject-modal').classList.remove('hidden');
    }
    function closeRejectModal() {
        document.getElementById('reject-modal').classList.add('hidden');
    }

    // Modal 3: Request Details Drawer
    function openDetailsModal(req) {
        document.getElementById('det-code').innerText = 'Code: ' + (req.request_code || ('REQ-' + req.id));
        document.getElementById('det-rest-name').innerText = req.restaurant_name;
        document.getElementById('det-rest-type').innerText = req.restaurant_type || 'Casual Dining';
        document.getElementById('det-tables').innerText = req.table_count || 10;
        document.getElementById('det-pan').innerText = req.pan_number || 'N/A';
        document.getElementById('det-address').innerText = req.address || 'N/A';
        
        document.getElementById('det-owner').innerText = req.owner_name;
        document.getElementById('det-email').innerText = req.email;
        document.getElementById('det-phone').innerText = req.phone;
        document.getElementById('det-submitted').innerText = req.created_at;
        document.getElementById('det-msg').innerText = req.message ? ('"' + req.message + '"') : 'No additional requirements specified.';

        document.getElementById('det-notes-req-id').value = req.id;
        document.getElementById('det-notes-text').value = req.internal_notes || '';

        // Lifecycle Progression Styling
        const st = req.status;
        const pEl = document.getElementById('wf-pending');
        const cEl = document.getElementById('wf-contacted');
        const fEl = document.getElementById('wf-final');

        pEl.className = 'px-2.5 py-1 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20';
        cEl.className = (st === 'CONTACTED' || st === 'APPROVED' || st === 'CONVERTED') ? 'px-2.5 py-1 rounded-xl bg-blue-500/10 text-blue-400 border border-blue-500/20' : 'px-2.5 py-1 rounded-xl bg-zinc-800 text-zinc-500';
        
        if (st === 'CONVERTED' || st === 'APPROVED') {
            fEl.innerText = '3. ONBOARDED';
            fEl.className = 'px-2.5 py-1 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
        } else if (st === 'REJECTED') {
            fEl.innerText = '3. REJECTED';
            fEl.className = 'px-2.5 py-1 rounded-xl bg-rose-500/10 text-rose-400 border border-rose-500/20';
        } else {
            fEl.innerText = '3. ONBOARDED';
            fEl.className = 'px-2.5 py-1 rounded-xl bg-zinc-800 text-zinc-500';
        }

        document.getElementById('details-modal').classList.remove('hidden');
    }
    function closeDetailsModal() {
        document.getElementById('details-modal').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
