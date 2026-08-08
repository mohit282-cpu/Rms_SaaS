<?php
// helpers/SubscriptionService.php - SaaS Tenant Subscription & Plan Feature Guard

class SubscriptionService {

    /**
     * Check if tenant's subscription is currently active
     */
    public static function isActive(int $restaurantId): bool {
        $conn = getDBConnection();
        if (!$conn) return false;

        $stmt = $conn->prepare("SELECT subscription_status, subscription_end FROM restaurants WHERE id = ? LIMIT 1");
        if (!$stmt) return false;

        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $res = $stmt->get_result();
        $tenant = $res->fetch_assoc();
        $stmt->close();

        if (!$tenant) return false;

        $status = strtoupper($tenant['subscription_status'] ?? '');
        $endDate = trim((string)($tenant['subscription_end'] ?? ''));
        $isOpenEnded = ($endDate === '' || $endDate === '0000-00-00' || $endDate === '0000-00-00 00:00:00');

        // Manual suspension/cancellation always blocks
        if (in_array($status, ['SUSPENDED', 'CANCELLED'])) {
            return false;
        }

        if (!$isOpenEnded && strtotime($endDate) < strtotime('today')) {
            // Subscription date has passed => mark EXPIRED and block access.
            $conn->query("UPDATE restaurants SET subscription_status = 'EXPIRED' WHERE id = " . (int)$restaurantId);
            return false;
        }

        // An EXPIRED subscription NEVER auto-converts back to ACTIVE.
        if ($status === 'EXPIRED') {
            return false;
        }

        return true;
    }

    /**
     * Alias check for tenant access validation
     */
    public static function canAccessTenant(int $restaurantId): bool {
        return self::isActive($restaurantId);
    }

    /**
     * Calculate remaining days in tenant subscription
     */
    public static function getRemainingDays(int $restaurantId): int {
        $conn = getDBConnection();
        if (!$conn) return 0;

        $stmt = $conn->prepare("SELECT subscription_end FROM restaurants WHERE id = ? LIMIT 1");
        if (!$stmt) return 0;

        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $res = $stmt->get_result();
        $tenant = $res->fetch_assoc();
        $stmt->close();

        if (!$tenant || empty($tenant['subscription_end'])) return 0;

        $endDate = trim($tenant['subscription_end']);
        if ($endDate === '0000-00-00' || $endDate === '0000-00-00 00:00:00') return 36500;

        $diff = strtotime($endDate) - time();
        return max(0, (int)ceil($diff / (60 * 60 * 24)));
    }

    /**
     * Get plan limits for a restaurant tenant
     */
    public static function getTenantPlanLimits(int $restaurantId): array {
        $conn = getDBConnection();
        $default = ['max_tables' => 10, 'max_staff' => 5, 'plan_name' => 'Starter Plan'];
        if (!$conn) return $default;

        $stmt = $conn->prepare("
            SELECT p.max_tables, p.max_staff, p.name as plan_name, p.features
            FROM restaurants r
            LEFT JOIN subscription_plans p ON r.subscription_plan_id = p.id
            WHERE r.id = ? LIMIT 1
        ");
        if (!$stmt) return $default;

        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $res = $stmt->get_result();
        $plan = $res->fetch_assoc();
        $stmt->close();

        return $plan ? $plan : $default;
    }

    /**
     * Check if a tenant is allowed to add another table under its current plan limits
     */
    public static function canAddTable(int $restaurantId): bool {
        $limits = self::getTenantPlanLimits($restaurantId);
        $maxTables = (int)($limits['max_tables'] ?? 10);
        if ($maxTables >= 999) return true; // Unlimited / Enterprise

        $conn = getDBConnection();
        if (!$conn) return false;

        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tables WHERE restaurant_id = ?");
        if (!$stmt) return false;

        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        $currentCount = (int)($row['cnt'] ?? 0);
        return $currentCount < $maxTables;
    }

    /**
     * Check if a tenant is allowed to add another staff user under its current plan limits
     */
    public static function canAddStaff(int $restaurantId): bool {
        $limits = self::getTenantPlanLimits($restaurantId);
        $maxStaff = (int)($limits['max_staff'] ?? 5);
        if ($maxStaff >= 999) return true; // Unlimited / Enterprise

        $conn = getDBConnection();
        if (!$conn) return false;

        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM admin_users WHERE restaurant_id = ?");
        if (!$stmt) return false;

        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        $currentCount = (int)($row['cnt'] ?? 0);
        return $currentCount < $maxStaff;
    }

    /**
     * Enforce table creation limit or exit with 403 error
     */
    public static function assertCanAddTable(int $restaurantId): void {
        if (!self::canAddTable($restaurantId)) {
            $limits = self::getTenantPlanLimits($restaurantId);
            $maxTables = (int)($limits['max_tables'] ?? 10);
            $msg = "Subscription Plan Limit Reached: Your current plan (" . ($limits['plan_name'] ?? 'Plan') . ") allows a maximum of {$maxTables} tables. Please upgrade your subscription to add more tables.";
            
            http_response_code(403);
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            die($msg);
        }
    }

    /**
     * Enforce staff creation limit or exit with 403 error
     */
    public static function assertCanAddStaff(int $restaurantId): void {
        if (!self::canAddStaff($restaurantId)) {
            $limits = self::getTenantPlanLimits($restaurantId);
            $maxStaff = (int)($limits['max_staff'] ?? 5);
            $msg = "Subscription Plan Limit Reached: Your current plan (" . ($limits['plan_name'] ?? 'Plan') . ") allows a maximum of {$maxStaff} staff accounts. Please upgrade your subscription to add more staff.";
            
            http_response_code(403);
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            die($msg);
        }
    }
}
