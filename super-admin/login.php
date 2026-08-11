<?php
// super-admin/login.php - Platform Super Admin Authentication Portal
require_once __DIR__ . '/../config.php';

Auth::startSession();

// If already logged in as Super Admin, redirect to index
if (Auth::isSuperAdmin()) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF Token
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $error = "CSRF security verification failed. Please refresh and try again.";
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        // Rate Limit check
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check("superadmin_login_" . $ip, 5, 300)) {
            $error = "Too many failed login attempts. Please wait 5 minutes.";
        } elseif (empty($email) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            RateLimiter::hit("superadmin_login_" . $ip, 5, 300);
            Security::logAudit("SUPER_ADMIN_FAILED_LOGIN", "Invalid email format or empty fields for input: " . Security::sanitize($email));
            $error = "Invalid email or password.";
        } else {
            $conn = getDBConnection();
            if (!$conn) {
                $error = "Database connection error.";
            } else {
                $stmt = $conn->prepare("SELECT id, email, password, full_name, role, is_super_admin, restaurant_id, force_password_change FROM admin_users WHERE LOWER(email) = ? AND (is_super_admin = 1 OR LOWER(role) = 'super_admin') LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $user = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($user && password_verify($password, $user['password'])) {
                        // Successful login
                        RateLimiter::clear("superadmin_login_" . $ip);
                        Auth::regenerateSession();
                        $_SESSION['admin_id'] = $user['id'];
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['is_super_admin'] = true;
                        $_SESSION['role'] = 'SUPER_ADMIN';
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['admin_email'] = $user['email'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['restaurant_id'] = (int)($user['restaurant_id'] ?? 0) > 0 ? (int)$user['restaurant_id'] : 1;
                        $_SESSION['sa_restaurant_id'] = (int)($user['restaurant_id'] ?? 0) > 0 ? (int)$user['restaurant_id'] : 1;
                        $_SESSION['force_password_change'] = (int)($user['force_password_change'] ?? 0);

                        Security::logAudit("SUPER_ADMIN_LOGIN", "Super Admin logged in successfully: " . $user['email']);

                        // Enforce mandatory password change if required
                        if (!empty($user['force_password_change'])) {
                            header('Location: ../admin/change-password.php');
                            exit;
                        }
                        header('Location: index.php');
                        exit;
                    } else {
                        RateLimiter::hit("superadmin_login_" . $ip, 5, 300);
                        Security::logAudit("SUPER_ADMIN_FAILED_LOGIN", "Failed login attempt for email: " . Security::sanitize($email));
                        $error = "Invalid email or password.";
                    }
                } else {
                    $error = "Database query preparation error.";
                }
            }
        }
    }
}

$csrfField = CSRF::getField();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Portal Login - RMS SaaS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Plus Jakarta Sans', sans-serif; } </style>
</head>
<body class="h-full flex items-center justify-center p-4 bg-zinc-950 antialiased selection:bg-amber-500 selection:text-zinc-950">
    <div class="max-w-md w-full bg-zinc-900 border border-zinc-800 rounded-3xl p-8 shadow-2xl space-y-6">
        <div class="text-center space-y-2">
            <div class="w-14 h-14 bg-gradient-to-tr from-amber-500 to-amber-400 rounded-2xl flex items-center justify-center text-zinc-950 text-2xl font-black mx-auto shadow-xl shadow-amber-500/20">
                ⚡
            </div>
            <h1 class="text-2xl font-black text-white tracking-tight">Super Admin Portal</h1>
            <p class="text-xs text-zinc-400 font-medium">RMS SaaS Platform Operations & Governance</p>
        </div>

        <?php if ($error): ?>
            <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold text-center">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?= $csrfField ?>
            <div>
                <label class="block text-xs font-bold text-zinc-400 mb-1.5 uppercase tracking-wider">Email Address</label>
                <input type="email" name="email" required placeholder="sovryxrms29@gmail.com" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors font-medium" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div>
                <label class="block text-xs font-bold text-zinc-400 mb-1.5 uppercase tracking-wider">Master Password</label>
                <input type="password" name="password" required placeholder="••••••••••••" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-600 outline-none focus:border-amber-500 transition-colors font-medium">
            </div>

            <button type="submit" class="w-full h-12 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-zinc-950 font-black text-sm hover:from-amber-400 hover:to-amber-300 transition-all active:scale-[0.98] shadow-lg shadow-amber-500/20 flex items-center justify-center space-x-2">
                <span>Authenticate Platform Access</span>
                <span>→</span>
            </button>
        </form>

        <div class="pt-4 border-t border-zinc-800 text-center space-y-2">
            <a href="../index.php" class="text-xs font-bold text-zinc-500 hover:text-amber-400 transition-colors block">
                ← Return to Public Website
            </a>
        </div>
    </div>
</body>
</html>
