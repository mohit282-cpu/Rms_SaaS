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
        if (in_array($status, ['SUSPENDED', 'CANCELLED', 'EXPIRED'])) {
            return false;
        }

        if (!empty($tenant['subscription_end']) && strtotime($tenant['subscription_end']) < strtotime('today')) {
            // Update subscription status in DB to EXPIRED if date has passed
            $conn->query("UPDATE restaurants SET subscription_status = 'EXPIRED' WHERE id = " . (int)$restaurantId);
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

        $diff = strtotime($tenant['subscription_end']) - time();
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
}
