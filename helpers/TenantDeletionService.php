<?php
// helpers/TenantDeletionService.php - Centralized Dependency-Aware Tenant Deletion Purge Service
// Safely purges ALL 24+ tenant-owned database tables in exact foreign key dependency order.

class TenantDeletionService {

    /**
     * Complete dependency-ordered list of all tenant-owned tables to purge.
     */
    private static array $purgeTables = [
        'register_cash_movements',
        'shifts',
        'registers',
        'expenses',
        'loyalty_transactions',
        'customers',
        'payroll_items',
        'payroll_periods',
        'salary_history',
        'salary_advances',
        'employee_shifts',
        'attendance',
        'employees',
        'shift_templates',
        'hr_settings',
        'payment_transactions',
        'order_items',
        'orders',
        'menu_addons',
        'recipe_items',
        'recipes',
        'menu_items',
        'categories',
        'inventory_transactions',
        'stock_audits',
        'inventory_alerts',
        'inventory_waste',
        'goods_receipts',
        'purchase_order_items',
        'purchase_orders',
        'suppliers',
        'inventory_items',
        'inventory_units',
        'inventory_categories',
        'asset_logs',
        'asset_depreciation',
        'asset_maintenance',
        'asset_transfers',
        'asset_warranties',
        'assets',
        'asset_categories',
        'waiter_calls',
        'dining_sessions',
        'tables',
        'notifications',
        'audit_logs',
        'landing_page_settings',
        'payment_gateways',
        'payment_settings',
        'subscriptions'
    ];

    /**
     * Permanently purge all tenant-owned data and the restaurant record itself.
     * Returns array with status, message, and deleted counts.
     */
    public static function deleteTenant(mysqli $conn, int $restaurantId): array {
        if ($restaurantId <= 0) {
            return ['success' => false, 'error' => 'Invalid restaurant tenant ID.'];
        }

        // Fetch tenant details for audit logging
        $stmt = $conn->prepare("SELECT restaurant_name FROM restaurants WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $rRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$rRow) {
            return ['success' => false, 'error' => "Restaurant tenant ID #{$restaurantId} not found."];
        }
        $restaurantName = $rRow['restaurant_name'];

        $conn->begin_transaction();
        try {
            $purgedCounts = [];

            // 1. Purge all child data tables
            foreach (self::$purgeTables as $table) {
                // Check if table exists before deleting
                $check = $conn->query("SHOW TABLES LIKE '{$table}'");
                if ($check && $check->num_rows > 0) {
                    $delStmt = $conn->prepare("DELETE FROM `{$table}` WHERE restaurant_id = ?");
                    $delStmt->bind_param("i", $restaurantId);
                    $delStmt->execute();
                    $purgedCounts[$table] = $delStmt->affected_rows;
                    $delStmt->close();
                }
            }

            // 2. Resolve Super Admin users attached to this tenant so they are not orphaned
            $hasSaUsers = false;
            $saRes = $conn->query("SELECT COUNT(*) AS cnt FROM admin_users WHERE restaurant_id = {$restaurantId} AND is_super_admin = 1");
            if ($saRes && (int)($saRes->fetch_assoc()['cnt'] ?? 0) > 0) {
                $hasSaUsers = true;
            }

            // Target alternative active tenant if available for super admin reassignment
            $targetRestId = 1;
            if ($hasSaUsers) {
                $altRes = $conn->query("SELECT id FROM restaurants WHERE id != {$restaurantId} ORDER BY id ASC LIMIT 1");
                if ($altRes && $altRow = $altRes->fetch_assoc()) {
                    $targetRestId = (int)$altRow['id'];
                }
            }

            // Delete non-super-admin users
            $conn->query("DELETE FROM admin_users WHERE restaurant_id = {$restaurantId} AND is_super_admin = 0");

            // Reassign Super Admin users to alternative tenant
            if ($hasSaUsers) {
                $conn->query("UPDATE admin_users SET restaurant_id = {$targetRestId} WHERE restaurant_id = {$restaurantId} AND is_super_admin = 1");
            }

            // 3. Delete the parent restaurant record
            $dRest = $conn->prepare("DELETE FROM restaurants WHERE id = ?");
            $dRest->bind_param("i", $restaurantId);
            $dRest->execute();
            $dRest->close();

            $conn->commit();

            Security::logAudit("SUPER_ADMIN_DELETE_TENANT", "Super Admin permanently purged tenant #{$restaurantId} ({$restaurantName}) and all associated database records.");

            return [
                'success' => true,
                'restaurant_id' => $restaurantId,
                'restaurant_name' => $restaurantName,
                'message' => "Restaurant tenant '{$restaurantName}' and all associated tenant records were permanently deleted.",
                'purged_tables_count' => count($purgedCounts)
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['success' => false, 'error' => "Failed to delete tenant: " . $e->getMessage()];
        }
    }

    /**
     * Verify that no orphaned records remain in any tenant-owned table for a given tenant ID.
     */
    public static function verifyZeroOrphans(mysqli $conn, int $restaurantId): array {
        $remaining = [];
        foreach (self::$purgeTables as $table) {
            $check = $conn->query("SHOW TABLES LIKE '{$table}'");
            if ($check && $check->num_rows > 0) {
                $res = $conn->query("SELECT COUNT(*) AS cnt FROM `{$table}` WHERE restaurant_id = {$restaurantId}");
                $cnt = $res ? (int)($res->fetch_assoc()['cnt'] ?? 0) : 0;
                if ($cnt > 0) {
                    $remaining[$table] = $cnt;
                }
            }
        }
        return [
            'is_clean' => empty($remaining),
            'orphans' => $remaining
        ];
    }
}
