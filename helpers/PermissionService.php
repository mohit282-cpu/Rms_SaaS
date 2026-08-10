<?php
// helpers/PermissionService.php - Central RBAC (Role-Based Access Control) Definition

class PermissionService {

    // Group => list of action-level permissions. '*' = all actions in group.
    private static $groupActions = [
        'orders'    => ['view', 'create', 'update', 'cancel', 'delete', 'settle'],
        'payments'  => ['view', 'create', 'settle', 'refund', 'ncr'],
        'inventory' => ['view', 'create', 'update', 'delete', 'adjust'],
        'suppliers' => ['view', 'create', 'update', 'delete'],
        'purchase_orders' => ['view', 'create', 'update', 'delete', 'receive'],
        'recipes'   => ['view', 'create', 'update', 'delete'],
        'assets'    => ['view', 'create', 'update', 'delete', 'dispose'],
        'tables'    => ['view', 'create', 'update', 'delete', 'manage'],
        'menu'      => ['view', 'create', 'update', 'delete'],
        'staff'     => ['view', 'create', 'update', 'delete'],
        'reports'   => ['view'],
        'settings'  => ['view', 'update'],
        'waiter_calls' => ['view', 'manage'],
        'notifications' => ['view', 'update'],
        'hr'          => ['view', 'create', 'update', 'delete', 'manage_salary'],
        'shifts'      => ['view', 'create', 'update', 'delete', 'assign'],
        'attendance'  => ['view', 'clock', 'manage'],
        'payroll'     => ['view', 'calculate', 'approve', 'pay'],
    ];

    private static $rolePermissions = [
        'OWNER'             => ['*'],
        'MANAGER'           => ['orders.*', 'payments.*', 'inventory.*', 'suppliers.*', 'purchase_orders.*', 'recipes.*', 'assets.*', 'tables.*', 'menu.*', 'reports.view', 'staff.view', 'staff.create', 'staff.update', 'settings.view', 'settings.update', 'notifications.*', 'hr.view', 'hr.create', 'hr.update', 'shifts.*', 'attendance.*'],
        'HR_MANAGER'        => ['staff.*', 'hr.*', 'shifts.*', 'attendance.*', 'payroll.*', 'reports.view', 'notifications.*'],
        'ACCOUNTANT'        => ['payroll.*', 'reports.view', 'payments.view', 'orders.view', 'hr.view'],
        'CASHIER'           => ['orders.view', 'orders.create', 'orders.settle', 'payments.view', 'payments.settle', 'tables.view', 'reports.view', 'attendance.clock'],
        'KITCHEN'           => ['orders.view', 'orders.update', 'attendance.clock'],
        'WAITER'            => ['orders.view', 'orders.create', 'tables.view', 'waiter_calls.manage', 'attendance.clock'],
        'INVENTORY_MANAGER' => ['inventory.*', 'suppliers.*', 'purchase_orders.*', 'recipes.*', 'assets.view', 'attendance.clock'],
        'SUPER_ADMIN'       => ['*'],
    ];

    /**
     * Normalize a role string to its canonical key.
     */
    public static function normalizeRole(?string $role): string {
        $r = strtoupper(trim((string)($role ?? '')));
        if ($r === 'ADMIN') return 'OWNER';
        if ($r === 'STORE_KEEPER') return 'INVENTORY_MANAGER';
        if ($r === 'AUDITOR') return 'ACCOUNTANT';
        return $r;
    }

    /**
     * Return the permission set granted to a role, or null when the role is unknown.
     */
    public static function permissionsFor(?string $role): ?array {
        $r = self::normalizeRole($role);
        if ($r === '') return null;
        return self::$rolePermissions[$r] ?? null;
    }

    /**
     * Check a permission against a role. Fail-closed for unknown roles and permissions.
     */
    public static function hasPermission(?string $role, string $permission): bool {
        $perms = self::permissionsFor($role);
        if ($perms === null) return false;

        if (in_array('*', $perms, true)) return true;

        $permission = strtolower(trim($permission));
        if ($permission === '') return false;

        if (in_array($permission, $perms, true)) return true;

        $parts = explode('.', $permission, 2);
        $group = $parts[0];
        if (in_array($group . '.*', $perms, true)) return true;

        return false;
    }

    /**
     * Return the list of concrete permissions a role holds (expanded wildcards).
     */
    public static function expandPermissions(?string $role): array {
        $perms = self::permissionsFor($role);
        if ($perms === null) return [];
        if (in_array('*', $perms, true)) return ['*'];

        $expanded = [];
        foreach ($perms as $p) {
            if (substr($p, -2) === '.*') {
                $group = substr($p, 0, -2);
                foreach (self::$groupActions[$group] ?? [] as $action) {
                    $expanded[] = $group . '.' . $action;
                }
            } else {
                $expanded[] = $p;
            }
        }
        return array_values(array_unique($expanded));
    }

    /**
     * List all known roles.
     */
    public static function allRoles(): array {
        return array_keys(self::$rolePermissions);
    }
}
