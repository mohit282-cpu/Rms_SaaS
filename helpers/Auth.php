<?php
// helpers/Auth.php - Authentication, Secure Session Management & Role Authorization Guard

class Auth {

    /**
     * Initialize secure PHP session with cookie flags
     */
    public static function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            session_start();
        }

        // Automatic session timeout (default 2 hours for idle session)
        $maxIdleTime = 7200;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $maxIdleTime)) {
            self::logout();
        }
        $_SESSION['last_activity'] = time();
    }

    /**
     * Regenerate session ID to prevent Session Fixation
     */
    public static function regenerateSession() {
        if (session_status() === PHP_SESSION_NONE) {
            self::startSession();
        }
        session_regenerate_id(true);
    }

    /**
     * Check if Admin is logged in
     */
    public static function isAdminLoggedIn() {
        self::startSession();
        return (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) || isset($_SESSION['admin_id']);
    }

    /**
     * Check if Kitchen is logged in
     */
    public static function isKitchenLoggedIn() {
        self::startSession();
        return (isset($_SESSION['kitchen_logged_in']) && $_SESSION['kitchen_logged_in'] === true) || self::isAdminLoggedIn();
    }

    /**
     * Require Admin Login or return 404 / 401
     */
    public static function requireAdmin() {
        if (!self::isAdminLoggedIn()) {
            $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                      (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
            if ($isJson) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Unauthorized admin access required.']);
                exit;
            }
            http_response_code(404);
            die('<!DOCTYPE html><html lang="en" class="h-full bg-zinc-950 text-white"><head><meta charset="UTF-8"><title>404 Page Not Found</title><script src="https://cdn.tailwindcss.com"></script></head><body class="h-full flex items-center justify-center p-4 text-center"><div class="max-w-md bg-zinc-900 border border-zinc-800 p-8 rounded-3xl space-y-4"><div class="text-6xl">⚠️</div><h1 class="text-2xl font-black text-white">404 - Page Not Found</h1><p class="text-xs text-zinc-400">The requested URL was not found on this server.</p><div class="pt-2"><a href="../index.php" class="inline-block px-5 py-2.5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs">Go to Home Portal</a></div></div></body></html>');
        }
    }

    /**
     * Require Kitchen Login or return lock screen
     */
    public static function requireKitchen() {
        self::startSession();

        if (isset($_GET['kds_logout'])) {
            unset($_SESSION['kitchen_logged_in']);
            header('Location: kitchen-dashboard.php');
            exit;
        }

        if (isset($_POST['kds_action']) && $_POST['kds_action'] === 'kds_login') {
            $pass = trim($_POST['kds_password'] ?? '');
            
            // Fetch KDS password hash or plain text fallback from DB
            $expected = 'kitchen123';
            $conn = getDBConnection();
            if ($conn) {
                $res = $conn->query("SELECT kds_password FROM landing_page_settings LIMIT 1");
                if ($res && $row = $res->fetch_assoc()) {
                    if (!empty($row['kds_password'])) $expected = $row['kds_password'];
                }
            }

            if ($pass === $expected || password_verify($pass, $expected)) {
                self::regenerateSession();
                $_SESSION['kitchen_logged_in'] = true;
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $_SESSION['kds_error'] = 'Invalid Kitchen Password!';
            }
        }

        if (self::isAdminLoggedIn()) {
            $_SESSION['kitchen_logged_in'] = true;
        }

        if (!isset($_SESSION['kitchen_logged_in']) || $_SESSION['kitchen_logged_in'] !== true) {
            $error = $_SESSION['kds_error'] ?? null;
            unset($_SESSION['kds_error']);
            $csrfField = CSRF::getField();
            echo '<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Access Lock - QR Cafe</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center p-4 font-sans antialiased">
    <div class="max-w-md w-full bg-zinc-900 border border-zinc-800 rounded-3xl p-8 shadow-2xl space-y-6 text-center">
        <div class="text-6xl">👨‍🍳</div>
        <div>
            <h1 class="text-xl font-black text-white">Kitchen Display System (KDS)</h1>
            <p class="text-xs text-zinc-400 mt-1">Protected Area — Enter Kitchen Password to Continue</p>
        </div>' .
        ($error ? '<div class="p-3 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">⚠️ ' . htmlspecialchars($error) . '</div>' : '') .
        '<form method="POST" class="space-y-4">' . $csrfField . '
            <input type="hidden" name="kds_action" value="kds_login">
            <div>
                <input type="password" name="kds_password" required placeholder="Enter Kitchen Password" class="w-full h-12 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-sm text-white placeholder-zinc-500 outline-none focus:border-amber-500 text-center font-bold">
            </div>
            <button type="submit" class="h-12 w-full rounded-2xl bg-amber-500 text-zinc-950 font-black text-sm active:scale-95 shadow-lg shadow-amber-500/20">
                🔓 Unlock Kitchen Display
            </button>
        </form>
        <div class="pt-1">
            <a href="admin/login.php" class="text-xs text-zinc-500 font-bold hover:text-amber-400 transition-colors">← Back to Staff & Manager Portal</a>
        </div>
    </div>
</body>
</html>';
            exit;
        }
    }

    /**
     * Check if active session is Super Admin
     */
    public static function isSuperAdmin(): bool {
        self::startSession();
        return (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) ||
               (isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'SUPER_ADMIN');
    }

    /**
     * Require Super Admin authorization guard
     */
    public static function requireSuperAdmin() {
        if (!self::isSuperAdmin()) {
            $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            if ($isJson) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Super Admin access required.']);
                exit;
            }
            header('Location: /RMS_System/super-admin/login.php');
            exit;
        }
    }

    /**
     * Require Restaurant Tenant Login
     */
    public static function requireRestaurant() {
        self::requireAdmin();
        if (class_exists('TenantContext')) {
            TenantContext::requireTenant();
        }
    }

    /**
     * Check if user has specific permission based on role
     */
    public static function checkPermission(string $permission): bool {
        self::startSession();
        if (self::isSuperAdmin()) return true;

        $role = strtoupper($_SESSION['role'] ?? 'OWNER');
        
        $rolePermissions = [
            'OWNER' => ['*'],
            'MANAGER' => ['orders.*', 'payments.*', 'inventory.*', 'tables.*', 'menu.*', 'reports.view'],
            'CASHIER' => ['orders.view', 'orders.create', 'payments.view', 'payments.settle', 'tables.view'],
            'KITCHEN' => ['orders.view', 'orders.update'],
            'WAITER' => ['orders.view', 'orders.create', 'tables.view', 'waiter_calls.manage'],
            'INVENTORY_MANAGER' => ['inventory.*', 'suppliers.*', 'purchase_orders.*', 'recipes.*']
        ];

        if (isset($rolePermissions[$role])) {
            $perms = $rolePermissions[$role];
            if (in_array('*', $perms)) return true;
            if (in_array($permission, $perms)) return true;
            
            // Check wildcards like 'orders.*'
            list($group) = explode('.', $permission);
            if (in_array($group . '.*', $perms)) return true;
        }

        return false;
    }

    /**
     * Clear and destroy active session cleanly (Secure Logout)
     */
    public static function logout() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}

