<?php
// helpers/TenantContext.php - Multi-Tenant Security & Context Isolation Manager

class TenantContext {
    private static $cachedTenant = null;

    /**
     * Resolve active restaurant_id from authenticated session or dining session token.
     * Returns null when NO tenant context exists. NEVER falls back to a default tenant.
     * NEVER trusts raw $_GET or $_POST parameters.
     */
    public static function resolveTenantId(): ?int {
        Auth::startSession();

        // 1. Authenticated Staff / Restaurant Owner Session
        if (isset($_SESSION['restaurant_id']) && is_numeric($_SESSION['restaurant_id']) && $_SESSION['restaurant_id'] > 0) {
            return (int)$_SESSION['restaurant_id'];
        }

        // 2. Customer Dining Session Context
        if (isset($_SESSION['customer_restaurant_id']) && is_numeric($_SESSION['customer_restaurant_id']) && $_SESSION['customer_restaurant_id'] > 0) {
            return (int)$_SESSION['customer_restaurant_id'];
        }

        // 3. Kitchen (KDS) Session Context
        if (isset($_SESSION['kitchen_restaurant_id']) && is_numeric($_SESSION['kitchen_restaurant_id']) && $_SESSION['kitchen_restaurant_id'] > 0) {
            return (int)$_SESSION['kitchen_restaurant_id'];
        }

        // 4. NO fallback. An unresolved tenant context is returned as null and callers MUST fail closed.
        return null;
    }

    /**
     * Get active restaurant_id as an integer.
     * Returns 0 (falsy) when no tenant context exists so that any
     * accidentally-unscoped query fails closed (returns no rows) instead of
     * silently leaking tenant 1 data.
     */
    public static function getTenantId(): int {
        return self::resolveTenantId() ?? 0;
    }

    /**
     * Check if a valid restaurant context exists in current session.
     * A bare Super Admin session (no restaurant selected) does NOT count as a tenant context.
     */
    public static function hasTenant(): bool {
        Auth::startSession();
        return (isset($_SESSION['restaurant_id']) && is_numeric($_SESSION['restaurant_id']) && $_SESSION['restaurant_id'] > 0) ||
               (isset($_SESSION['customer_restaurant_id']) && is_numeric($_SESSION['customer_restaurant_id']) && $_SESSION['customer_restaurant_id'] > 0);
    }

    /**
     * Fetch a tenant row (public fields only) without any session dependency.
     */
    public static function getTenant(int $tenantId): ?array {
        if ($tenantId <= 0) {
            return null;
        }
        $conn = getDBConnection();
        if (!$conn) {
            return null;
        }
        $stmt = $conn->prepare("SELECT id, restaurant_name, status, subscription_status, subscription_end FROM restaurants WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $tenantId);
        $stmt->execute();
        $res = $stmt->get_result();
        $tenant = $res->fetch_assoc();
        $stmt->close();
        return $tenant ?: null;
    }

    /**
     * Validate a tenant: exists, is ACTIVE and subscription allows access.
     * Returns array with 'ok' => bool and optional 'message' on failure.
     */
    public static function tenantAccessible(int $tenantId): array {
        $tenant = self::getTenant($tenantId);
        if (!$tenant) {
            return ['ok' => false, 'status' => 404, 'message' => 'Tenant account not found or has been removed.'];
        }
        if ($tenant['status'] === 'SUSPENDED') {
            return ['ok' => false, 'status' => 403, 'message' => 'Restaurant account suspended. Contact support.'];
        }
        if ($tenant['status'] === 'INACTIVE' || $tenant['status'] === 'PENDING') {
            return ['ok' => false, 'status' => 403, 'message' => 'Restaurant account is not active.'];
        }
        if (class_exists('SubscriptionService') && !Auth::isSuperAdmin()) {
            if (!SubscriptionService::canAccessTenant($tenantId)) {
                return ['ok' => false, 'status' => 403, 'message' => 'Subscription expired or inactive. Please renew to restore access.'];
            }
        }
        return ['ok' => true];
    }

    /**
     * Require active tenant context & enforce account status & subscription guards.
     * Handles both HTML (die with access-denied page) and JSON (die with JSON error) clients.
     */
    public static function requireTenant() {
        Auth::startSession();

        // Super Admin viewing global platform (no restaurant selected) is allowed.
        if (Auth::isSuperAdmin() && !self::hasTenant()) {
            return;
        }

        if (!Auth::isAdminLoggedIn() && !isset($_SESSION['customer_restaurant_id'])) {
            if (self::wantsJson()) {
                self::sendJsonError(401, 'Authentication required.');
            }
            Auth::requireAdmin();
            return;
        }

        $tenantId = self::getTenantId();
        if ($tenantId <= 0) {
            if (self::wantsJson()) {
                self::sendJsonError(403, 'No restaurant tenant context is associated with this session.');
            }
            http_response_code(403);
            die(self::renderAccessDeniedPage('No Tenant Context', 'No restaurant tenant context is associated with this session. Please sign in to a restaurant account.'));
        }

        $check = self::tenantAccessible($tenantId);
        if (!$check['ok']) {
            if (self::wantsJson()) {
                self::sendJsonError($check['status'], $check['message']);
            }
            http_response_code($check['status']);
            die(self::renderAccessDeniedPage('403 - Access Denied', $check['message']));
        }
    }

    /**
     * Assert that a record in the database belongs to the currently active tenant (IDOR Protection).
     * Returns true on success, or exits with 404/403 on failure.
     * For tables that use a UUID-style string PK, pass $idColumn accordingly.
     */
    public static function assertOwnership(mysqli $conn, string $tableName, $recordId, string $idColumn = 'id'): bool {
        $tenantId = self::getTenantId();
        if ($tenantId <= 0 || $recordId === null || $recordId === '') {
            self::sendNotFound();
            return false;
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $safeCol = preg_replace('/[^a-zA-Z0-9_]/', '', $idColumn);
        if ($safeTable === '' || $safeCol === '') {
            self::sendForbidden('Invalid table or column name.');
            return false;
        }

        $stmt = $conn->prepare("SELECT restaurant_id FROM `{$safeTable}` WHERE `{$safeCol}` = ? LIMIT 1");
        if (!$stmt) {
            self::sendForbidden('Database error during ownership assertion.');
            return false;
        }

        // Numeric id columns are bound as integers (avoids type coercion surprises).
        $isIntId = ctype_digit((string)$recordId) || (is_int($recordId) || (is_numeric($recordId) && strpos((string)$recordId, '.') === false));
        if ($isIntId) {
            $idInt = (int)$recordId;
            $stmt->bind_param("i", $idInt);
        } else {
            $stmt->bind_param("s", $recordId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) {
            self::sendNotFound();
            return false;
        }

        if ((int)$row['restaurant_id'] !== $tenantId && !Auth::isSuperAdmin()) {
            Security::logAudit('IDOR_SECURITY_VIOLATION', "Attempted cross-tenant access to {$safeTable} (ID: " . (is_scalar($recordId) ? $recordId : 'n/a') . ") belonging to restaurant_id {$row['restaurant_id']} from active restaurant_id {$tenantId}");
            self::sendForbidden('Access Denied: You do not have permission to access this resource.');
            return false;
        }

        return true;
    }

    private static function wantsJson(): bool {
        return (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
               (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
               (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
    }

    private static function sendJsonError(int $code, string $message): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }

    private static function sendNotFound(): void {
        if (self::wantsJson()) {
            self::sendJsonError(404, 'Resource not found.');
        }
        http_response_code(404);
        die('Resource not found.');
    }

    private static function sendForbidden(string $message): void {
        if (self::wantsJson()) {
            self::sendJsonError(403, $message);
        }
        http_response_code(403);
        die(self::renderAccessDeniedPage('403 - Forbidden Access', $message));
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
</head>
<body class="h-full flex items-center justify-center p-4 font-sans antialiased">
    <div class="max-w-md w-full bg-zinc-900 border border-zinc-800 rounded-3xl p-8 shadow-2xl text-center space-y-6">
        <div class="w-16 h-16 bg-rose-500/10 border border-rose-500/30 text-rose-500 rounded-2xl flex items-center justify-center mx-auto text-3xl font-black">
            &#128274;
        </div>
        <div>
            <h1 class="text-xl font-black text-white">' . htmlspecialchars($title) . '</h1>
            <p class="text-xs text-zinc-400 mt-2 leading-relaxed">' . htmlspecialchars($message) . '</p>
        </div>
        <div class="pt-4 border-t border-zinc-800 flex flex-col gap-2">
            <a href="/Rms_SaaS/admin/login.php" class="px-5 py-3 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:bg-amber-400 transition-all shadow-lg shadow-amber-500/20">
                Return to Login Portal
            </a>
        </div>
    </div>
</body>
</html>';
    }
}
