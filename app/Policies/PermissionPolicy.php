<?php
namespace App\Policies;

use Auth;
use Security;
use TenantContext;

class PermissionPolicy {

    /**
     * Comprehensive RBAC Role-Permission Matrix
     */
    private const ROLE_PERMISSIONS = [
        'super_admin' => ['*'],
        'owner' => ['*'],
        'manager' => [
            'orders.*', 'billing.*', 'menu.*', 'tables.*', 'inventory.*',
            'customers.*', 'reservations.*', 'staff.view', 'staff.create',
            'reports.*', 'settings.view', 'expenses.*', 'assets.*'
        ],
        'cashier' => [
            'orders.view', 'orders.create', 'orders.edit',
            'billing.view', 'billing.create', 'billing.print',
            'tables.view', 'customers.view', 'customers.create',
            'loyalty.view', 'loyalty.redeem', 'shifts.manage'
        ],
        'waiter' => [
            'orders.view', 'orders.create', 'orders.edit',
            'tables.view', 'tables.update_status', 'menu.view',
            'waiter.call_respond'
        ],
        'kitchen' => [
            'kds.view', 'kds.update_status', 'menu.toggle_item_status',
            'orders.view'
        ],
        'hr' => [
            'staff.*', 'attendance.*', 'payroll.*', 'shifts.*'
        ],
        'accountant' => [
            'billing.view', 'reports.*', 'expenses.*', 'payroll.view',
            'assets.view'
        ]
    ];

    /**
     * Check if active user role has a specific permission string.
     */
    public static function can(array $user, string $permission): bool {
        if (empty($user)) {
            return false;
        }

        // Super Admin or Owner role has unrestricted platform/tenant permissions
        $isSuperAdmin = !empty($user['is_super_admin']) || (int)($user['is_super_admin'] ?? 0) === 1;
        $role = strtolower(trim((string)($user['role'] ?? '')));

        if ($isSuperAdmin || $role === 'super_admin' || $role === 'owner') {
            return true;
        }

        $granted = self::ROLE_PERMISSIONS[$role] ?? [];
        if (in_array('*', $granted, true)) {
            return true;
        }

        foreach ($granted as $pattern) {
            if ($pattern === $permission) {
                return true;
            }
            if (strpos($pattern, '.*') !== false) {
                $prefix = substr($pattern, 0, -2);
                if (strpos($permission, $prefix . '.') === 0 || $permission === $prefix) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Enforce permission or exit with HTTP 403 Forbidden
     */
    public static function requirePermission(string $permission): void {
        Auth::startSession();
        $user = [
            'id' => $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0,
            'role' => $_SESSION['role'] ?? 'guest',
            'is_super_admin' => Auth::isSuperAdmin() ? 1 : 0
        ];

        if (!self::can($user, $permission)) {
            Security::logAudit('UNAUTHORIZED_ACCESS_ATTEMPT', "User #{$user['id']} (role: {$user['role']}) attempted unauthorized action '{$permission}'");
            http_response_code(403);
            $wantsJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                        (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);

            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => "Access Denied: Permission '{$permission}' required."]);
                exit;
            }

            die(TenantContext::renderAccessDeniedPage('403 - Permission Denied', "You do not have permission to execute action '{$permission}'."));
        }
    }
}
