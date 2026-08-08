<?php
// admin/login-process.php - Hardened Multi-Tenant Authentication Controller
require_once '../config.php';

// Enforce Rate Limiting (5 attempts per 5 minutes)
RateLimiter::enforce('admin_login', 5, 300);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();

    $username = Security::sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = 'Please enter both username and password.';
        header('Location: login.php');
        exit;
    }

    $conn = getDBConnection();
    $authenticated = false;

    if ($conn) {
        $stmt = $conn->prepare("SELECT id, username, password, full_name, role, is_super_admin, restaurant_id, force_password_change FROM admin_users WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($user = $res->fetch_assoc()) {
                if (password_verify($password, $user['password'])) {
                    $authenticated = true;

                    // Clear rate limiter history on successful login
                    RateLimiter::clear('admin_login');

                    // Regenerate session ID to prevent Session Fixation Attacks
                    Auth::regenerateSession();

                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_username'] = $user['username'];
                    $_SESSION['admin_full_name'] = $user['full_name'];
                    $_SESSION['role'] = strtoupper($user['role'] ?? 'OWNER');
                    $_SESSION['is_super_admin'] = (bool)($user['is_super_admin'] ?? false);
                    $_SESSION['restaurant_id'] = (int)($user['restaurant_id'] ?? 1);
                    $_SESSION['force_password_change'] = (bool)($user['force_password_change'] ?? false);

                    Security::logAudit("STAFF_LOGIN", "Staff logged in: {$username} (Tenant ID: {$_SESSION['restaurant_id']})");

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
    RateLimiter::hit('admin_login', 5, 300);

    $_SESSION['error'] = 'Invalid username or password.';
    header('Location: login.php');
    exit;
} else {
    header('Location: login.php');
    exit;
}
