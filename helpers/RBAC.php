<?php
// helpers/RBAC.php - Granular Role-Based Access Control (RBAC) Service for RMS SaaS
// Enforces server-side permissions on protected actions & API routes per tenant.

class RBAC {

    public static $allPermissions = [
        'view_dashboard'       => 'View Overview Dashboard',
        'view_orders'          => 'View Live Orders & KDS',
        'create_orders'        => 'Create New POS/Table Orders',
        'edit_orders'          => 'Edit Active Orders',
        'cancel_orders'        => 'Cancel Orders',
        'void_orders'          => 'Void Orders or Items',
        'apply_discount'       => 'Apply Order Discounts',
        'process_payment'      => 'Process Order Payments & Checkout',
        'refund_payment'       => 'Issue Order Refunds',
        'view_revenue'         => 'View Financial Revenue & Sales',
        'view_reports'         => 'View Advanced Analytics & Reports',
        'manage_menu'          => 'Manage Menu Items & Prices',
        'manage_categories'    => 'Manage Menu Categories',
        'manage_modifiers'     => 'Manage Modifiers & Add-ons',
        'manage_inventory'     => 'Manage Stock & Inventory',
        'manage_suppliers'     => 'Manage Suppliers & Purchase Orders',
        'manage_customers'     => 'Manage CRM & Customer Profiles',
        'manage_reservations'  => 'Manage Table Reservations',
        'manage_expenses'      => 'Manage Operating Expenses',
        'manage_staff'         => 'Manage Staff Accounts & Roles',
        'manage_settings'      => 'Manage Restaurant Settings',
        'manage_shifts'        => 'Open & Close Work Shifts',
        'manage_loyalty'       => 'Manage Loyalty Program & Points'
    ];

    /**
     * Default permission matrices for standard roles
     */
    public static $defaultRolePermissions = [
        'OWNER' => [
            'view_dashboard', 'view_orders', 'create_orders', 'edit_orders', 'cancel_orders', 'void_orders',
            'apply_discount', 'process_payment', 'refund_payment', 'view_revenue', 'view_reports', 'manage_menu',
            'manage_categories', 'manage_modifiers', 'manage_inventory', 'manage_suppliers', 'manage_customers',
            'manage_reservations', 'manage_expenses', 'manage_staff', 'manage_settings', 'manage_shifts', 'manage_loyalty'
        ],
        'MANAGER' => [
            'view_dashboard', 'view_orders', 'create_orders', 'edit_orders', 'cancel_orders', 'void_orders',
            'apply_discount', 'process_payment', 'refund_payment', 'view_revenue', 'view_reports', 'manage_menu',
            'manage_categories', 'manage_modifiers', 'manage_inventory', 'manage_suppliers', 'manage_customers',
            'manage_reservations', 'manage_expenses', 'manage_shifts', 'manage_loyalty'
        ],
        'CASHIER' => [
            'view_dashboard', 'view_orders', 'create_orders', 'edit_orders', 'apply_discount',
            'process_payment', 'manage_customers', 'manage_reservations', 'manage_shifts'
        ],
        'WAITER' => [
            'view_orders', 'create_orders', 'edit_orders', 'manage_customers', 'manage_reservations'
        ],
        'KITCHEN' => [
            'view_orders', 'edit_orders'
        ],
        'INVENTORY_MANAGER' => [
            'view_dashboard', 'manage_inventory', 'manage_suppliers', 'manage_expenses'
        ]
    ];

    /**
     * Check if currently authenticated user has specific permission
     */
    public static function hasPermission(string $permissionCode): bool {
        Auth::startSession();

        // Platform Super Admin has universal permission across all tenants
        if (!empty($_SESSION['is_super_admin'])) {
            return true;
        }

        $userRole = strtoupper(trim($_SESSION['role'] ?? ''));
        if (empty($userRole)) {
            return false;
        }

        // Restaurant Owners have full permission
        if (in_array($userRole, ['OWNER', 'ADMIN', 'RESTAURANT OWNER', 'SUPER_ADMIN'], true)) {
            return true;
        }

        $tenantId = TenantContext::getTenantId();
        $conn = getDBConnection();
        if (!$conn || $tenantId <= 0) {
            return false;
        }

        // Check custom role_permissions in database (tenant-scoped)
        $stmt = $conn->prepare("SELECT id FROM role_permissions WHERE restaurant_id = ? AND role_name = ? AND permission_code = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("iss", $tenantId, $userRole, $permissionCode);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $stmt->close();
                return true;
            }
            $stmt->close();
        }

        // Fallback to default matrix
        $allowed = self::$defaultRolePermissions[$userRole] ?? [];
        return in_array($permissionCode, $allowed, true);
    }

    /**
     * Assert permission or exit with HTTP 403 / JSON error
     */
    public static function requirePermission(string $permissionCode): void {
        if (!self::hasPermission($permissionCode)) {
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                Response::error("Forbidden. You do not have permission to perform this action ({$permissionCode}).", 403);
            } else {
                http_response_code(403);
                die("<div style='background:#09090b;color:#f43f5e;padding:2rem;font-family:sans-serif;text-align:center;'>
                        <h2>⛔ Access Denied</h2>
                        <p>Your role is not authorized to access this feature (<code>{$permissionCode}</code>).</p>
                        <a href='index.php' style='color:#f59e0b;text-decoration:none;font-weight:bold;'>← Return to Dashboard</a>
                     </div>");
            }
        }
    }
}
