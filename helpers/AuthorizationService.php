<?php
// helpers/AuthorizationService.php - Central Authorization Guard
// Combines authentication + tenant isolation + subscription status + role permission.

class AuthorizationService {

    /**
     * Return the active tenant id from the session, or 0 when there is none.
     * Never falls back to a default tenant.
     */
    public static function tenantId(): int {
        return TenantContext::getTenantId();
    }

    /**
     * Require a restaurant tenant context for a JSON API endpoint.
     * Exits with 401/403 JSON on failure. Returns the active tenant id on success.
     */
    public static function requireTenantApi(): int {
        Auth::startSession();

        $isStaff = Auth::isAdminLoggedIn();
        $isKitchen = Auth::isKitchenLoggedIn();
        $isCustomer = isset($_SESSION['customer_restaurant_id']);

        if (!$isStaff && !$isKitchen && !$isCustomer) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Authentication required.']);
            exit;
        }

        $tenantId = self::tenantId();
        if ($tenantId <= 0) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Forbidden. No restaurant tenant context is associated with this session.']);
            exit;
        }

        $check = TenantContext::tenantAccessible($tenantId);
        if (!$check['ok']) {
            http_response_code($check['status']);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $check['message']]);
            exit;
        }

        return $tenantId;
    }

    /**
     * Require a restaurant STAFF (admin OR kitchen) tenant context for a JSON API endpoint.
     * Customer dining sessions are NOT accepted. Exits with 401/403 JSON on failure.
     */
    public static function requireStaffApi(): int {
        Auth::startSession();

        if (!Auth::isAdminLoggedIn() && !Auth::isKitchenLoggedIn()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Staff authentication required.']);
            exit;
        }

        $tenantId = self::tenantId();
        if ($tenantId <= 0) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Forbidden. No restaurant tenant context is associated with this session.']);
            exit;
        }

        $check = TenantContext::tenantAccessible($tenantId);
        if (!$check['ok']) {
            http_response_code($check['status']);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $check['message']]);
            exit;
        }

        return $tenantId;
    }

    /**
     * Require an authenticated staff/restaurant session for a page or API.
     * Exits with 401/404 on failure. Returns the active tenant id on success.
     */
    public static function requireStaff(): int {
        Auth::requireRestaurant();
        $tenantId = self::tenantId();
        // Super admin without a tenant context is the only valid zero-tenant case
        // for staff pages; otherwise fail closed.
        if ($tenantId <= 0 && !Auth::isSuperAdmin()) {
            TenantContext::requireTenant();
        }
        return $tenantId;
    }

    /**
     * Require an authenticated staff session AND a specific permission.
     * Exits with 403 when the role lacks the permission.
     */
    public static function requirePermission(string $permission): int {
        $tenantId = self::requireStaff();
        if (!self::hasPermission($permission)) {
            http_response_code(403);
            $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                      (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Access Denied: Your role does not have permission for this action.']);
                exit;
            }
            die('Access Denied: Your role does not have permission for this action.');
        }
        return $tenantId;
    }

    /**
     * Permission check for the current session user. Super admins bypass role checks.
     */
    public static function hasPermission(string $permission): bool {
        Auth::startSession();
        if (Auth::isSuperAdmin()) return true;
        $role = PermissionService::normalizeRole($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
        return PermissionService::hasPermission($role, $permission);
    }

    /**
     * Resolve the current user's role ('' when unknown/unset).
     */
    public static function currentRole(): string {
        Auth::startSession();
        return PermissionService::normalizeRole($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
    }

    /**
     * Assert that a record belongs to the active tenant (IDOR protection).
     * Exits with 404/403 on failure. Returns true on success.
     */
    public static function assertOwnership(mysqli $conn, string $tableName, $recordId, string $idColumn = 'id'): bool {
        return TenantContext::assertOwnership($conn, $tableName, $recordId, $idColumn);
    }
}
