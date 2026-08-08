<?php
// admin/login-process.php - Hardened Authentication Controller
require_once '../config.php';

// Enforce Rate Limiting to prevent Brute Force Dictionary Attacks (5 attempts per 5 minutes)
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
    $admin_id = 0;
    $full_name = '';

    if ($conn) {
        $stmt = $conn->prepare("SELECT id, username, password, full_name, role FROM admin_users WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($user = $res->fetch_assoc()) {
                // Enterprise BCRYPT Password Verification (No hardcoded backdoors)
                if (password_verify($password, $user['password'])) {
                    $authenticated = true;
                    $admin_id = $user['id'];
                    $full_name = $user['full_name'] ?? 'Administrator';
                    $role = $user['role'] ?? 'admin';
                }
            }
            $stmt->close();
        }
    }

    if ($authenticated) {
        // Clear rate limiter history on successful login
        RateLimiter::clear('admin_login');

        // Regenerate session ID to prevent Session Fixation Attacks
        Auth::regenerateSession();

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $admin_id;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_full_name'] = $full_name;
        $_SESSION['admin_role'] = $role;

        Inventory::audit('auth.login', "Staff login: {$username} (" . Inventory::roleLabel() . ")");

        header('Location: index.php');
        exit;
    } else {
        // Record failed attempt
        RateLimiter::hit('admin_login', 5, 300);

        $_SESSION['error'] = 'Invalid username or password.';
        header('Location: login.php');
        exit;
    }
} else {
    header('Location: login.php');
    exit;
}
