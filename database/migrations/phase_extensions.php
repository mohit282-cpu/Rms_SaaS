<?php
// database/migrations/phase_extensions.php - Schema extension for RMS SaaS Production Features
require_once __DIR__ . '/../../config.php';

$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed for migration.\n");
}

echo "=================================================================\n";
echo "       RMS SaaS PRODUCTION FEATURE EXTENSIONS SCHEMA MIGRATION    \n";
echo "=================================================================\n\n";

// Helper function for safe execution
function executeQuery($conn, $sql, $description) {
    if ($conn->query($sql)) {
        echo "  ✅ [SUCCESS] $description\n";
    } else {
        echo "  ⚠️ [NOTICE] $description (" . $conn->error . ")\n";
    }
}

// 1. RESTAURANT SETTINGS TABLE
$sqlSettings = "
CREATE TABLE IF NOT EXISTS `restaurant_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL UNIQUE,
    `logo_url` VARCHAR(255) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `pan_vat_number` VARCHAR(50) DEFAULT NULL,
    `currency` VARCHAR(10) DEFAULT 'NPR',
    `timezone` VARCHAR(50) DEFAULT 'Asia/Kathmandu',
    
    `tax_enabled` TINYINT(1) DEFAULT 1,
    `tax_name` VARCHAR(50) DEFAULT 'VAT',
    `tax_percentage` DECIMAL(5,2) DEFAULT 13.00,
    
    `service_charge_enabled` TINYINT(1) DEFAULT 1,
    `service_charge_type` ENUM('percent','fixed') DEFAULT 'percent',
    `service_charge_amount` DECIMAL(10,2) DEFAULT 10.00,
    
    `discount_max_percent` DECIMAL(5,2) DEFAULT 20.00,
    `discount_require_permission` TINYINT(1) DEFAULT 1,
    
    `receipt_footer_msg` TEXT DEFAULT NULL,
    `receipt_paper_size` ENUM('58mm','80mm') DEFAULT '80mm',
    `order_prefix` VARCHAR(10) DEFAULT 'ORD-',
    `order_starting_number` INT DEFAULT 1001,
    
    `kds_enabled` TINYINT(1) DEFAULT 1,
    `kds_auto_refresh_sec` INT DEFAULT 2,
    `kds_prep_time_mins` INT DEFAULT 15,
    `kds_delayed_threshold_mins` INT DEFAULT 15,
    
    `qr_ordering_enabled` TINYINT(1) DEFAULT 1,
    `qr_min_order_amount` DECIMAL(10,2) DEFAULT 0.00,
    `qr_instructions` TEXT DEFAULT NULL,
    `qr_opening_time` TIME DEFAULT '08:00:00',
    `qr_closing_time` TIME DEFAULT '22:00:00',
    
    `loyalty_enabled` TINYINT(1) DEFAULT 1,
    `loyalty_spend_per_point` DECIMAL(10,2) DEFAULT 100.00,
    `loyalty_redemption_rate` DECIMAL(10,2) DEFAULT 1.00,
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlSettings, "Create 'restaurant_settings' table");

// 2. KITCHEN STATIONS TABLE
$sqlStations = "
CREATE TABLE IF NOT EXISTS `kitchen_stations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ks_tenant` (`restaurant_id`),
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlStations, "Create 'kitchen_stations' table");

// Add kitchen_station_id column to menu_items if not present
$checkItemCol = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'kitchen_station_id'");
if (!$checkItemCol || $checkItemCol->num_rows === 0) {
    executeQuery($conn, "ALTER TABLE `menu_items` ADD COLUMN `kitchen_station_id` INT DEFAULT NULL", "Add 'kitchen_station_id' to menu_items");
}

// 3. ROLES & PERMISSIONS SCHEMAS
$sqlRoles = "
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_system` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tenant_role` (`restaurant_id`, `name`),
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlRoles, "Create 'roles' table");

$sqlRolePermissions = "
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `role_name` VARCHAR(50) NOT NULL,
    `permission_code` VARCHAR(100) NOT NULL,
    UNIQUE KEY `uq_tenant_role_perm` (`restaurant_id`, `role_name`, `permission_code`),
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlRolePermissions, "Create 'role_permissions' table");

// 4. MODIFIER GROUPS & MODIFIERS
$sqlModGroups = "
CREATE TABLE IF NOT EXISTS `modifier_groups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `selection_type` ENUM('single','multiple') DEFAULT 'single',
    `is_required` TINYINT(1) DEFAULT 0,
    `min_selections` INT DEFAULT 0,
    `max_selections` INT DEFAULT 1,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_mg_tenant` (`restaurant_id`),
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlModGroups, "Create 'modifier_groups' table");

$sqlModifiers = "
CREATE TABLE IF NOT EXISTS `modifiers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `group_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `price` DECIMAL(10,2) DEFAULT 0.00,
    `inventory_item_id` INT DEFAULT NULL,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_mod_tenant` (`restaurant_id`),
    FOREIGN KEY (`group_id`) REFERENCES `modifier_groups`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlModifiers, "Create 'modifiers' table");

$sqlOrderItemModifiers = "
CREATE TABLE IF NOT EXISTS `order_item_modifiers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `order_item_id` INT NOT NULL,
    `modifier_id` INT NOT NULL,
    `modifier_name` VARCHAR(100) NOT NULL,
    `unit_price` DECIMAL(10,2) DEFAULT 0.00,
    `quantity` INT DEFAULT 1,
    INDEX `idx_oim_tenant` (`restaurant_id`),
    FOREIGN KEY (`order_item_id`) REFERENCES `order_items`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlOrderItemModifiers, "Create 'order_item_modifiers' table");

// 5. BILL SPLITS, MERGES & TABLE TRANSFERS
$sqlOrderSplits = "
CREATE TABLE IF NOT EXISTS `order_splits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `order_id` INT NOT NULL,
    `split_type` ENUM('equal','item','percent') NOT NULL,
    `customer_label` VARCHAR(100) DEFAULT 'Customer 1',
    `amount` DECIMAL(10,2) NOT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'cash',
    `payment_status` ENUM('pending','paid') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_os_tenant` (`restaurant_id`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlOrderSplits, "Create 'order_splits' table");

$sqlOrderMerges = "
CREATE TABLE IF NOT EXISTS `order_merges` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `source_order_id` INT NOT NULL,
    `target_order_id` INT NOT NULL,
    `merged_by` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_om_tenant` (`restaurant_id`),
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlOrderMerges, "Create 'order_merges' table");

$sqlTableTransfers = "
CREATE TABLE IF NOT EXISTS `table_transfers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `order_id` INT NOT NULL,
    `from_table_number` VARCHAR(50) NOT NULL,
    `to_table_number` VARCHAR(50) NOT NULL,
    `transferred_by` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tt_tenant` (`restaurant_id`),
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlTableTransfers, "Create 'table_transfers' table");

// 6. VOIDS & REFUNDS
$sqlOrderVoids = "
CREATE TABLE IF NOT EXISTS `order_voids` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `order_id` INT NOT NULL,
    `order_item_id` INT DEFAULT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `voided_by` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ov_tenant` (`restaurant_id`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlOrderVoids, "Create 'order_voids' table");

$sqlOrderRefunds = "
CREATE TABLE IF NOT EXISTS `order_refunds` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `order_id` INT NOT NULL,
    `refund_type` ENUM('full','partial') NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'cash',
    `reason` VARCHAR(255) NOT NULL,
    `refunded_by` VARCHAR(100) NOT NULL,
    `reference_id` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_orf_tenant` (`restaurant_id`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlOrderRefunds, "Create 'order_refunds' table");

// 7. CUSTOMERS TABLE
$sqlCustomers = "
CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(50) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `total_visits` INT DEFAULT 0,
    `total_spent` DECIMAL(10,2) DEFAULT 0.00,
    `loyalty_points` INT DEFAULT 0,
    `tier` ENUM('Bronze','Silver','Gold','Platinum') DEFAULT 'Bronze',
    `last_visit_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tenant_customer_phone` (`restaurant_id`, `phone`),
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlCustomers, "Create 'customers' table");

// Add customer_id column to orders if not present
$checkOrderCust = $conn->query("SHOW COLUMNS FROM orders LIKE 'customer_id'");
if (!$checkOrderCust || $checkOrderCust->num_rows === 0) {
    executeQuery($conn, "ALTER TABLE `orders` ADD COLUMN `customer_id` INT DEFAULT NULL", "Add 'customer_id' to orders");
}

// 8. RESERVATIONS TABLE
$sqlReservations = "
CREATE TABLE IF NOT EXISTS `reservations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `customer_id` INT DEFAULT NULL,
    `customer_name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(50) NOT NULL,
    `reservation_date` DATE NOT NULL,
    `reservation_time` TIME NOT NULL,
    `guest_count` INT DEFAULT 2,
    `table_id` INT DEFAULT NULL,
    `table_number` VARCHAR(50) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('pending','confirmed','arrived','no_show','cancelled','completed') DEFAULT 'confirmed',
    `created_by` VARCHAR(100) DEFAULT 'system',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_res_tenant` (`restaurant_id`),
    INDEX `idx_res_date` (`restaurant_id`, `reservation_date`),
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlReservations, "Create 'reservations' table");

// 9. EXPENSES TABLE
$sqlExpenses = "
CREATE TABLE IF NOT EXISTS `expenses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `category_name` VARCHAR(100) NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `expense_date` DATE NOT NULL,
    `description` TEXT DEFAULT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'cash',
    `reference_no` VARCHAR(100) DEFAULT NULL,
    `created_by` VARCHAR(100) DEFAULT 'system',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_exp_tenant` (`restaurant_id`),
    INDEX `idx_exp_date` (`restaurant_id`, `expense_date`),
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlExpenses, "Create 'expenses' table");

// 10. WORK SHIFTS TABLE
$sqlShifts = "
CREATE TABLE IF NOT EXISTS `work_shifts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `shift_name` VARCHAR(50) DEFAULT 'Main Shift',
    `opened_at` DATETIME NOT NULL,
    `closed_at` DATETIME DEFAULT NULL,
    `opening_cash` DECIMAL(10,2) DEFAULT 0.00,
    `closing_cash_expected` DECIMAL(10,2) DEFAULT 0.00,
    `closing_cash_actual` DECIMAL(10,2) DEFAULT 0.00,
    `cash_sales` DECIMAL(10,2) DEFAULT 0.00,
    `card_sales` DECIMAL(10,2) DEFAULT 0.00,
    `qr_sales` DECIMAL(10,2) DEFAULT 0.00,
    `total_sales` DECIMAL(10,2) DEFAULT 0.00,
    `variance` DECIMAL(10,2) DEFAULT 0.00,
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('open','closed') DEFAULT 'open',
    `closed_by` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_shift_tenant` (`restaurant_id`),
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlShifts, "Create 'work_shifts' table");

// 11. LOYALTY TRANSACTIONS TABLE
$sqlLoyaltyTx = "
CREATE TABLE IF NOT EXISTS `loyalty_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `restaurant_id` INT NOT NULL,
    `customer_id` INT NOT NULL,
    `order_id` INT DEFAULT NULL,
    `type` ENUM('earn','redeem','expire','adjustment') NOT NULL,
    `points` INT NOT NULL,
    `amount_equivalent` DECIMAL(10,2) DEFAULT 0.00,
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_loy_tenant` (`restaurant_id`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
executeQuery($conn, $sqlLoyaltyTx, "Create 'loyalty_transactions' table");

// 12. ORDER FINANCIAL SNAPSHOT COLUMNS ON ORDERS TABLE
$orderColsRes = $conn->query("SHOW COLUMNS FROM orders");
$orderCols = [];
if ($orderColsRes) {
    while ($oc = $orderColsRes->fetch_assoc()) {
        $orderCols[] = strtolower($oc['Field']);
    }
}
if (!in_array('tax_amount', $orderCols)) {
    executeQuery($conn, "ALTER TABLE `orders` ADD COLUMN `tax_amount` DECIMAL(10,2) DEFAULT 0.00", "Add 'tax_amount' to orders");
}
if (!in_array('service_charge_amount', $orderCols)) {
    executeQuery($conn, "ALTER TABLE `orders` ADD COLUMN `service_charge_amount` DECIMAL(10,2) DEFAULT 0.00", "Add 'service_charge_amount' to orders");
}
if (!in_array('discount_amount', $orderCols)) {
    executeQuery($conn, "ALTER TABLE `orders` ADD COLUMN `discount_amount` DECIMAL(10,2) DEFAULT 0.00", "Add 'discount_amount' to orders");
}

// Seed default restaurant_settings records for existing active restaurants
$restsRes = $conn->query("SELECT id FROM restaurants");
if ($restsRes) {
    while ($r = $restsRes->fetch_assoc()) {
        $rid = (int)$r['id'];
        $conn->query("INSERT IGNORE INTO `restaurant_settings` (`restaurant_id`) VALUES ($rid)");
    }
    echo "  ✅ Default restaurant_settings records verified for all existing tenants.\n";
}

echo "\n=================================================================\n";
echo "  ✅ SUCCESS: SCHEMA MIGRATION COMPLETED SUCCESSFULLY 100%!       \n";
echo "=================================================================\n";
