<?php
// helpers/TenantContext.php - Multi-Tenant Security & Context Isolation Manager

class TenantContext {
    private static $cachedTenant = null;

    /**
     * Get active restaurant_id from authenticated session or dining session token.
     * NEVER trusts raw $_GET or $_POST parameters.
     */
    public static function getTenantId(): int {
        Auth::startSession();

        // 1. Authenticated Staff / Restaurant Owner Session
        if (isset($_SESSION['restaurant_id']) && is_numeric($_SESSION['restaurant_id']) && $_SESSION['restaurant_id'] > 0) {
            return (int)$_SESSION['restaurant_id'];
        }

        // 2. Customer Dining Session Context
        if (isset($_SESSION['customer_restaurant_id']) && is_numeric($_SESSION['customer_restaurant_id']) && $_SESSION['customer_restaurant_id'] > 0) {
            return (int)$_SESSION['customer_restaurant_id'];
        }

        // 3. Backward Compatibility / Default Tenant Fallback
        return 1;
    }

    /**
     * Check if a valid restaurant context exists in current session
     */
    public static function hasTenant(): bool {
        Auth::startSession();
        return (isset($_SESSION['restaurant_id']) && $_SESSION['restaurant_id'] > 0) ||
               (isset($_SESSION['customer_restaurant_id']) && $_SESSION['customer_restaurant_id'] > 0);
    }

    /**
     * Require active tenant context & enforce account status & subscription guards
     */
    public static function requireTenant() {
        Auth::startSession();

        // Super Admin overriding/impersonating or in Super Admin portal
        if (Auth::isSuperAdmin() && !isset($_SESSION['restaurant_id'])) {
            return; // Super admin viewing global platform
        }

        if (!Auth::isAdminLoggedIn() && !isset($_SESSION['customer_restaurant_id'])) {
            Auth::requireAdmin();
            return;
        }

        $tenantId = self::getTenantId();
        $conn = getDBConnection();
        if (!$conn) {
            die("Database Connection Failure in Tenant Context Guard.");
        }

        // Fetch tenant details
        $stmt = $conn->prepare("SELECT id, restaurant_name, status, subscription_status, subscription_end FROM restaurants WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $tenantId);
            $stmt->execute();
            $res = $stmt->get_result();
            $tenant = $res->fetch_assoc();
            $stmt->close();

            if (!$tenant) {
                http_response_code(403);
                die(self::renderAccessDeniedPage("Tenant Account Not Found", "The requested restaurant tenant account does not exist or has been removed."));
            }

            // Enforce Account Status Rules
            if ($tenant['status'] === 'SUSPENDED') {
                http_response_code(403);
                die(self::renderAccessDeniedPage("Restaurant Account Suspended", "Access to " . htmlspecialchars($tenant['restaurant_name']) . " has been suspended by the Platform Administrator. Please contact support."));
            }

            if ($tenant['status'] === 'INACTIVE') {
                http_response_code(403);
                die(self::renderAccessDeniedPage("Restaurant Account Inactive", "This restaurant account is currently inactive."));
            }

            // Enforce Subscription Access Rules via SubscriptionService
            if (class_exists('SubscriptionService') && !Auth::isSuperAdmin()) {
                if (!SubscriptionService::canAccessTenant($tenantId)) {
                    http_response_code(403);
                    die(self::renderAccessDeniedPage("Subscription Expired", "Your restaurant's subscription has expired or is inactive. Please renew your subscription to restore access."));
                }
            }
        }
    }

    /**
     * Assert that a record in the database belongs to the currently active tenant (IDOR Protection)
     * Triggers HTTP 403 / 404 if record is cross-tenant.
     */
    public static function assertOwnership(mysqli $conn, string $tableName, $recordId, string $idColumn = 'id'): bool {
        $tenantId = self::getTenantId();
        if (empty($recordId)) return false;

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $safeCol = preg_replace('/[^a-zA-Z0-9_]/', '', $idColumn);

        $stmt = $conn->prepare("SELECT restaurant_id FROM `{$safeTable}` WHERE `{$safeCol}` = ? LIMIT 1");
        if (!$stmt) return false;

        $stmt->bind_param("s", $recordId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) {
            // Record not found
            http_response_code(404);
            $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Resource not found.']);
                exit;
            }
            die("Resource not found.");
        }

        if ((int)$row['restaurant_id'] !== $tenantId && !Auth::isSuperAdmin()) {
            // IDOR Attempt Detected!
            Security::logAudit("IDOR_SECURITY_VIOLATION", "Attempted cross-tenant access to {$tableName} (ID: {$recordId}) belonging to restaurant_id {$row['restaurant_id']} from active restaurant_id {$tenantId}");
            http_response_code(403);
            $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to access this resource.']);
                exit;
            }
            die(self::renderAccessDeniedPage("403 - Forbidden Access", "Security Guard Alert: You are not authorized to view or edit resources belonging to another restaurant tenant."));
        }

        return true;
    }

    /**
     * Helper to render tenant access denied HTML UI
     */
    public static function renderAccessDeniedPage(string $title, string $message): string {
        return '<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . ' - RMS SaaS</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center p-4 font-sans antialiased">
    <div class="max-w-md w-full bg-zinc-900 border border-zinc-800 rounded-3xl p-8 shadow-2xl text-center space-y-6">
        <div class="w-16 h-16 bg-rose-500/10 border border-rose-500/30 text-rose-500 rounded-2xl flex items-center justify-center mx-auto text-3xl font-black">
            🔒
        </div>
        <div>
            <h1 class="text-xl font-black text-white">' . htmlspecialchars($title) . '</h1>
            <p class="text-xs text-zinc-400 mt-2 leading-relaxed">' . htmlspecialchars($message) . '</p>
        </div>
        <div class="pt-4 border-t border-zinc-800 flex flex-col gap-2">
            <a href="/RMS_System/admin/login.php" class="px-5 py-3 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all shadow-lg shadow-amber-500/20">
                Return to Login Portal
            </a>
        </div>
    </div>
</body>
</html>';
    }
}
