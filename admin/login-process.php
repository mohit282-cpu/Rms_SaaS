<?php
// admin/login-process.php - Hardened Multi-Tenant Email Authentication Controller
require_once '../config.php';

Auth::startSession();

$login_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$raw_email = trim($_POST['email'] ?? '');
$email = strtolower($raw_email);
$login_rl_key = 'admin_login_' . ($email !== '' ? md5($email) : 'anon') . '_' . $login_ip;

// Rate limiting (5 attempts per 5 minutes per email/IP)
RateLimiter::enforce($login_rl_key, 5, 300);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();

    $password = $_POST['password'] ?? '';

    // Validate email format and required fields
    if (empty($email) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        RateLimiter::hit($login_rl_key, 5, 300);
        Security::logAudit("LOGIN_FAILED_INVALID_INPUT", "Invalid email format or empty fields for input: " . Security::sanitize($email));
        $_SESSION['error'] = 'Invalid email or password.';
        header('Location: login.php');
        exit;
    }

    $conn = getDBConnection();

    if ($conn) {
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.email, u.password, u.full_name, u.role, u.is_super_admin, u.restaurant_id, u.force_password_change, r.status as tenant_status 
            FROM admin_users u
            LEFT JOIN restaurants r ON u.restaurant_id = r.id
            WHERE LOWER(u.email) = ? LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($user = $res->fetch_assoc()) {
                if (password_verify($password, $user['password'])) {
                    // Check if tenant account is ACTIVE
                    if (!$user['is_super_admin'] && !empty($user['tenant_status']) && $user['tenant_status'] !== 'ACTIVE') {
                        RateLimiter::hit($login_rl_key, 5, 300);
                        Security::logAudit("LOGIN_BLOCKED_SUSPENDED", "Login blocked for inactive/suspended tenant account: {$email}");
                        $_SESSION['error'] = 'Your restaurant account is currently ' . strtolower($user['tenant_status']) . '. Please contact system administrator.';
                        header('Location: login.php');
                        exit;
                    }

                    // Clear rate limiter history on successful login
                    RateLimiter::clear($login_rl_key);

                    // Regenerate session ID to prevent Session Fixation Attacks
                    Auth::regenerateSession();

                    $userRestId = (int)($user['restaurant_id'] ?? 0);
                    if (!$user['is_super_admin'] && $userRestId <= 0) {
                        RateLimiter::hit($login_rl_key, 5, 300);
                        Security::logAudit("LOGIN_BLOCKED_NO_TENANT", "Login blocked for account with no assigned restaurant: {$email}");
                        $_SESSION['error'] = 'Your account has no assigned restaurant. Please contact support.';
                        header('Location: login.php');
                        exit;
                    }

                    // Super Admin has no tenant of their own; they land on the platform
                    // demo tenant (id 1) ONLY if it exists and is ACTIVE. Everything is
                    // stored in sa_restaurant_id so impersonation exit restores it.
                    $sessionRestId = $userRestId;
                    if ($user['is_super_admin'] && $userRestId <= 0) {
                        $demoStmt = $conn->prepare("SELECT id FROM restaurants WHERE id = 1 AND status = 'ACTIVE' LIMIT 1");
                        if ($demoStmt) {
                            $demoStmt->execute();
                            $demoRow = $demoStmt->get_result()->fetch_assoc();
                            $demoStmt->close();
                        }
                        if ($demoRow) {
                            $sessionRestId = 1;
                        } else {
                            RateLimiter::hit($login_rl_key, 5, 300);
                            Security::logAudit("LOGIN_BLOCKED_NO_DEMO_TENANT", "Super Admin login blocked: default tenant (id 1) is missing or inactive: {$email}");
                            $_SESSION['error'] = 'Platform default tenant is not available. Please contact support.';
                            header('Location: login.php');
                            exit;
                        }
                    }

                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_email'] = $user['email'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['username'] = !empty($user['email']) ? $user['email'] : $user['username'];
                    $_SESSION['admin_username'] = $_SESSION['username'];
                    $_SESSION['admin_full_name'] = $user['full_name'];
                    $_SESSION['role'] = strtoupper($user['role'] ?? 'OWNER');
                    $_SESSION['is_super_admin'] = (bool)($user['is_super_admin'] ?? false);
                    $_SESSION['restaurant_id'] = $sessionRestId > 0 ? $sessionRestId : 1;
                    $_SESSION['sa_restaurant_id'] = $sessionRestId;
                    $_SESSION['force_password_change'] = (bool)($user['force_password_change'] ?? false);

                    Security::logAudit("STAFF_LOGIN", "Staff logged in via email: {$email} (Tenant ID: {$_SESSION['restaurant_id']})");

                    // Force password change if temporary credentials issued
                    if ($_SESSION['force_password_change']) {
                        $_SESSION['info_msg'] = "You are logged in with temporary credentials. Please update your password to secure your account.";
                        header('Location: change-password.php');
                        exit;
                    }

                    // Check first-time setup status
                    $tCheck = $conn->query("SELECT COUNT(*) as cnt FROM tables WHERE restaurant_id = " . (int)$_SESSION['restaurant_id']);
                    $tableCount = 0;
                    if ($tCheck && $tr = $tCheck->fetch_assoc()) $tableCount = (int)$tr['cnt'];

                    if ($tableCount == 0) {
                        header('Location: setup-wizard.php');
                        exit;
                    }

                    header('Location: index.php');
                    exit;
                }
            }
            $stmt->close();
        }
    }

    // Record failed attempt
    RateLimiter::hit($login_rl_key, 5, 300);
    Security::logAudit("LOGIN_FAILED", "Failed authentication attempt for email: " . Security::sanitize($email));

    $_SESSION['error'] = 'Invalid email or password.';
    header('Location: login.php');
    exit;
} else {
    header('Location: login.php');
    exit;
}
