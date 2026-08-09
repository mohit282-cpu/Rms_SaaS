<?php
// Global Output Buffering to prevent headers already sent errors on redirects
if (ob_get_level() === 0) {
    ob_start();
}

// Load PSR-4 Autoloader & Environment Configurations
require_once __DIR__ . '/app/Helpers/Autoloader.php';
Autoloader::register();
Autoloader::loadEnv(__DIR__ . '/.env');

// Database Configuration & Core Autoloader
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USERNAME') ?: 'root');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');
define('DB_NAME', getenv('DB_DATABASE') ?: 'qr_restaurant');

// HMAC SHA-256 Secret Key for Signed Table URLs & Session Pinning
// SECURITY: Never falls back to a hardcoded constant. Uses the JWT_SECRET env var,
// or a per-install random secret persisted outside the web root.
$__secretFromEnv = (string)getenv('JWT_SECRET');
$__weakSecret = 'RMS_SECURE_HMAC_SECRET_KEY_2026_CHANGE_IF_NEEDED';
if ($__secretFromEnv === '' || $__secretFromEnv === $__weakSecret) {
    $__secretFile = __DIR__ . '/storage/.app_secret';
    $__secretKey = is_readable($__secretFile) ? trim((string)@file_get_contents($__secretFile)) : '';
    if ($__secretKey === '') {
        $__secretKey = bin2hex(random_bytes(32));
        @file_put_contents($__secretFile, $__secretKey, LOCK_EX);
        @chmod($__secretFile, 0600);
        $__secretKey = is_readable($__secretFile) ? trim((string)@file_get_contents($__secretFile)) : '';
    }
} else {
    $__secretKey = $__secretFromEnv;
}
define('QR_SECRET_KEY', $__secretKey);
unset($__secretFromEnv, $__weakSecret, $__secretFile, $__secretKey);

// Load Helper Classes
require_once __DIR__ . '/helpers/Security.php';
require_once __DIR__ . '/helpers/CSRF.php';
require_once __DIR__ . '/helpers/Auth.php';
require_once __DIR__ . '/helpers/TenantContext.php';
require_once __DIR__ . '/helpers/SubscriptionService.php';
require_once __DIR__ . '/helpers/RateLimiter.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/Inventory.php';
require_once __DIR__ . '/helpers/PermissionService.php';
require_once __DIR__ . '/helpers/AuthorizationService.php';
require_once __DIR__ . '/helpers/RBAC.php';
require_once __DIR__ . '/helpers/CalculationEngine.php';
require_once __DIR__ . '/helpers/ModifierService.php';
require_once __DIR__ . '/helpers/BillService.php';
require_once __DIR__ . '/helpers/RefundService.php';
require_once __DIR__ . '/helpers/ShiftService.php';
require_once __DIR__ . '/helpers/LoyaltyService.php';

// Set Global Production Security Headers
Security::setSecurityHeaders();

// Initialize Secure Session
Auth::startSession();

// Cryptographic Token Helpers
function getOrCreateTableToken($table_number) {
    $conn = getDBConnection();
    if (!$conn) return '';

    // Fail closed: tokens are tenant-scoped and require a valid tenant context.
    $restaurantId = (int)TenantContext::getTenantId();
    if ($restaurantId <= 0) return '';

    $tbl_safe = $conn->real_escape_string(trim($table_number));
    $stmt = $conn->prepare("SELECT qr_token FROM tables WHERE table_number = ? AND restaurant_id = ? LIMIT 1");
    if (!$stmt) return '';
    $stmt->bind_param("si", $tbl_safe, $restaurantId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        if (!empty($row['qr_token'])) {
            $stmt->close();
            return $row['qr_token'];
        }
    }
    $stmt->close();

    // Generate new 32-char cryptographic hex token
    $newToken = bin2hex(random_bytes(16));
    $uStmt = $conn->prepare("UPDATE tables SET qr_token = ? WHERE table_number = ? AND restaurant_id = ?");
    if ($uStmt) {
        $uStmt->bind_param("ssi", $newToken, $tbl_safe, $restaurantId);
        $uStmt->execute();
        $uStmt->close();
    }
    return $newToken;
}

function generateSignedTableUrl($table_id) {
    $token = getOrCreateTableToken($table_id);
    return 'menu.php?token=' . $token;
}

function generateTableSignatureToken($table_id) {
    return getOrCreateTableToken($table_id);
}

function verifyTableSignature($table_id, $sig) {
    if (empty($table_id) || empty($sig)) {
        return false;
    }
    if (QR_SECRET_KEY === '') {
        // Fail closed when no secret is configured.
        return false;
    }
    $expected_sig = hash_hmac('sha256', 'table_' . trim($table_id), QR_SECRET_KEY);
    return hash_equals($expected_sig, trim($sig));
}

// Create database connection with error handling
// Schema provisioning is a one-time setup step (database/migrate.php), NOT run per-request.
function getDBConnection() {
    static $conn = null;
    if ($conn !== null && @$conn->ping()) {
        return $conn;
    }

    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS);

    if ($conn->connect_error) {
        return null;
    }

    if (!$conn->select_db(DB_NAME)) {
        // Database must exist. Run `php database/migrate.php` once to provision it.
        return null;
    }

    $conn->set_charset("utf8mb4");

    return $conn;
}

// Auto Schema Migration & Performance Indexing Helper
function ensureDatabaseSchema($conn) {
    if (!$conn) return;

    // Cache migration flag in static memory to prevent redundant DDL executions per request
    static $schemaChecked = false;
    if ($schemaChecked) return;
    $schemaChecked = true;

    // 0. Admin Users table check
    @$conn->query("CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100),
        role VARCHAR(20) DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $admin_cols = [];
    $a_res = $conn->query("SHOW COLUMNS FROM admin_users");
    if ($a_res) {
        while ($a_row = $a_res->fetch_assoc()) {
            $admin_cols[] = strtolower($a_row['Field']);
        }
    }
    if (!in_array('role', $admin_cols)) {
        try { $conn->query("ALTER TABLE admin_users ADD COLUMN role VARCHAR(20) DEFAULT 'admin'"); } catch (Throwable $e) {}
    }

    $admin_check = $conn->query("SELECT id FROM admin_users LIMIT 1");
    if (!$admin_check || $admin_check->num_rows == 0) {
        // Fixes RMS-020: Use environment variable or random bootstrap secret instead of static 'admin123'
        $initial_pass = getenv('APP_ADMIN_PASSWORD') ?: bin2hex(random_bytes(8));
        $hashed_pass = password_hash($initial_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT IGNORE INTO admin_users (username, password, full_name) VALUES ('admin', ?, 'Administrator')");
        if ($stmt) {
            $stmt->bind_param("s", $hashed_pass);
            $stmt->execute();
            $stmt->close();
            // Log initial bootstrap password securely for setup if generated
            if (!getenv('APP_ADMIN_PASSWORD')) {
                error_log("BOOTSTRAP ADMIN ACCOUNT CREATED. Username: admin | Initial Password: " . $initial_pass);
            }
        }
    }

    // 1. Categories table check
    @$conn->query("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255),
        parent_id INT DEFAULT NULL,
        icon VARCHAR(50) DEFAULT '🍽️',
        image VARCHAR(255) DEFAULT '',
        display_order INT DEFAULT 0,
        status ENUM('active', 'hidden') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Safely add missing columns to existing categories table
    $cat_cols = [];
    $c_res = $conn->query("SHOW COLUMNS FROM categories");
    if ($c_res) {
        while ($c_row = $c_res->fetch_assoc()) {
            $cat_cols[] = strtolower($c_row['Field']);
        }
    }

    if (!in_array('parent_id', $cat_cols)) {
        try { $conn->query("ALTER TABLE categories ADD COLUMN parent_id INT DEFAULT NULL"); } catch (Throwable $e) {}
    }
    if (!in_array('icon', $cat_cols)) {
        try { $conn->query("ALTER TABLE categories ADD COLUMN icon VARCHAR(50) DEFAULT '🍽️'"); } catch (Throwable $e) {}
    }
    if (!in_array('image', $cat_cols)) {
        try { $conn->query("ALTER TABLE categories ADD COLUMN image VARCHAR(255) DEFAULT ''"); } catch (Throwable $e) {}
    }
    if (!in_array('display_order', $cat_cols)) {
        try { $conn->query("ALTER TABLE categories ADD COLUMN display_order INT DEFAULT 0"); } catch (Throwable $e) {}
    }
    if (!in_array('status', $cat_cols)) {
        try { $conn->query("ALTER TABLE categories ADD COLUMN status ENUM('active', 'hidden') DEFAULT 'active'"); } catch (Throwable $e) {}
    }

    $cat_check = $conn->query("SELECT id FROM categories LIMIT 1");
    if (!$cat_check || $cat_check->num_rows == 0) {
        @$conn->query("INSERT IGNORE INTO categories (name, description, icon) VALUES ('Main Dishes', 'Delicious entrees', '🍛'), ('Beverages', 'Refreshing drinks', '☕'), ('Desserts', 'Sweet treats', '🍰')");
    }

    // 2. Menu Items table check
    @$conn->query("CREATE TABLE IF NOT EXISTS menu_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        description TEXT,
        price DECIMAL(10, 2) NOT NULL,
        cost_price DECIMAL(10, 2) DEFAULT 0.00,
        sku VARCHAR(50) DEFAULT '',
        stock_quantity INT DEFAULT 50,
        min_stock_level INT DEFAULT 10,
        allergens VARCHAR(255) DEFAULT '',
        calories INT DEFAULT 0,
        image VARCHAR(255),
        category_id INT NOT NULL,
        status ENUM('active', 'inactive', 'sold_out') DEFAULT 'active',
        is_popular TINYINT(1) DEFAULT 0,
        preparation_time INT DEFAULT 15,
        dietary_type ENUM('veg', 'non-veg') DEFAULT 'veg',
        addons TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
        INDEX idx_cat_status (category_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Safely add missing columns to existing menu_items table
    $menu_cols = [];
    $m_res = $conn->query("SHOW COLUMNS FROM menu_items");
    if ($m_res) {
        while ($m_row = $m_res->fetch_assoc()) {
            $menu_cols[] = strtolower($m_row['Field']);
        }
    }

    if (!in_array('sku', $menu_cols)) {
        try { $conn->query("ALTER TABLE menu_items ADD COLUMN sku VARCHAR(50) DEFAULT ''"); } catch (Throwable $e) {}
    }
    if (!in_array('cost_price', $menu_cols)) {
        try { $conn->query("ALTER TABLE menu_items ADD COLUMN cost_price DECIMAL(10, 2) DEFAULT 0.00"); } catch (Throwable $e) {}
    }
    if (!in_array('stock_quantity', $menu_cols)) {
        try { $conn->query("ALTER TABLE menu_items ADD COLUMN stock_quantity INT DEFAULT 50"); } catch (Throwable $e) {}
    }
    if (!in_array('min_stock_level', $menu_cols)) {
        try { $conn->query("ALTER TABLE menu_items ADD COLUMN min_stock_level INT DEFAULT 10"); } catch (Throwable $e) {}
    }
    if (!in_array('allergens', $menu_cols)) {
        try { $conn->query("ALTER TABLE menu_items ADD COLUMN allergens VARCHAR(255) DEFAULT ''"); } catch (Throwable $e) {}
    }
    if (!in_array('calories', $menu_cols)) {
        try { $conn->query("ALTER TABLE menu_items ADD COLUMN calories INT DEFAULT 0"); } catch (Throwable $e) {}
    }

    // 3. Tables table check
    @$conn->query("CREATE TABLE IF NOT EXISTS tables (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_number VARCHAR(20) NOT NULL,
        qr_code VARCHAR(255),
        zone VARCHAR(50) DEFAULT 'Ground Floor',
        capacity INT DEFAULT 4,
        status ENUM('vacant', 'occupied', 'reserved', 'cleaning', 'disabled') DEFAULT 'vacant',
        assigned_waiter VARCHAR(100) DEFAULT 'Unassigned',
        reserved_by VARCHAR(100) DEFAULT '',
        guest_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Safely add missing columns to existing tables table
    $existing_cols = [];
    $cols_res = $conn->query("SHOW COLUMNS FROM tables");
    if ($cols_res) {
        while ($col_row = $cols_res->fetch_assoc()) {
            $existing_cols[] = strtolower($col_row['Field']);
        }
    }

    if (!in_array('zone', $existing_cols)) {
        try { $conn->query("ALTER TABLE tables ADD COLUMN zone VARCHAR(50) DEFAULT 'Ground Floor'"); } catch (Throwable $e) {}
    }
    if (!in_array('capacity', $existing_cols)) {
        try { $conn->query("ALTER TABLE tables ADD COLUMN capacity INT DEFAULT 4"); } catch (Throwable $e) {}
    }
    if (!in_array('status', $existing_cols)) {
        try { $conn->query("ALTER TABLE tables ADD COLUMN status ENUM('vacant', 'occupied', 'reserved', 'cleaning', 'disabled') DEFAULT 'vacant'"); } catch (Throwable $e) {}
    }
    if (!in_array('assigned_waiter', $existing_cols)) {
        try { $conn->query("ALTER TABLE tables ADD COLUMN assigned_waiter VARCHAR(100) DEFAULT 'Unassigned'"); } catch (Throwable $e) {}
    }
    if (!in_array('reserved_by', $existing_cols)) {
        try { $conn->query("ALTER TABLE tables ADD COLUMN reserved_by VARCHAR(100) DEFAULT ''"); } catch (Throwable $e) {}
    }
    if (!in_array('guest_count', $existing_cols)) {
        try { $conn->query("ALTER TABLE tables ADD COLUMN guest_count INT DEFAULT 0"); } catch (Throwable $e) {}
    }
    if (!in_array('qr_token', $existing_cols)) {
        try { $conn->query("ALTER TABLE tables ADD COLUMN qr_token VARCHAR(64) UNIQUE DEFAULT NULL"); } catch (Throwable $e) {}
    }

    // Auto-populate missing cryptographic tokens for existing tables
    $empty_token_res = $conn->query("SELECT id FROM tables WHERE qr_token IS NULL OR qr_token = ''");
    if ($empty_token_res) {
        while ($row = $empty_token_res->fetch_assoc()) {
            $tbl_id = intval($row['id']);
            $newToken = bin2hex(random_bytes(16));
            $conn->query("UPDATE tables SET qr_token = '$newToken' WHERE id = $tbl_id");
        }
    }

    // 5. Payment Gateways & Transactions tables
    @$conn->query("CREATE TABLE IF NOT EXISTS payment_gateways (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        merchant_code VARCHAR(100) DEFAULT '',
        public_key VARCHAR(255) DEFAULT '',
        secret_key VARCHAR(255) DEFAULT '',
        environment ENUM('sandbox', 'production') DEFAULT 'sandbox',
        status ENUM('enabled', 'disabled') DEFAULT 'enabled',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default gateways if empty
    $gw_check = $conn->query("SELECT id FROM payment_gateways LIMIT 1");
    if (!$gw_check || $gw_check->num_rows == 0) {
        @$conn->query("INSERT IGNORE INTO payment_gateways (name, merchant_code, public_key, secret_key, environment, status) VALUES 
            ('esewa', 'EPAYTEST', 'test_public_esewa', '8gBmUzWo2x0=', 'sandbox', 'enabled'),
            ('khalti', 'test_merchant_khalti', 'test_public_key_khalti', 'test_secret_key_khalti', 'sandbox', 'enabled'),
            ('fonepay', 'TEST_FONEPAY', 'test_public_fonepay', 'test_secret_fonepay', 'sandbox', 'enabled'),
            ('connectips', 'TEST_CONNECTIPS', 'test_app_id_cips', 'test_secret_cips', 'sandbox', 'enabled'),
            ('imepay', 'TEST_IMEPAY', 'test_user_ime', 'test_key_ime', 'sandbox', 'enabled')
        ");
    }

    @$conn->query("CREATE TABLE IF NOT EXISTS payment_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id VARCHAR(100) NOT NULL UNIQUE,
        order_id INT NOT NULL,
        gateway_name VARCHAR(50) NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
        reference_id VARCHAR(100) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 3.1 Dining Sessions table check
    @$conn->query("CREATE TABLE IF NOT EXISTS dining_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_token VARCHAR(64) NOT NULL UNIQUE,
        table_number VARCHAR(20) NOT NULL,
        customer_name VARCHAR(100) DEFAULT 'Guest',
        status ENUM('active', 'payment_pending', 'closed') DEFAULT 'active',
        running_total DECIMAL(10, 2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_session_table_status (table_number, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 4. Orders table check
    @$conn->query("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_number VARCHAR(10) NOT NULL,
        customer_name VARCHAR(100),
        notes TEXT,
        status ENUM('new', 'preparing', 'ready', 'completed', 'cancelled') DEFAULT 'new',
        total_amount DECIMAL(10, 2) DEFAULT 0,
        payment_status ENUM('pending', 'paid') DEFAULT 'pending',
        payment_method VARCHAR(50) DEFAULT 'pending',
        dining_session_id INT DEFAULT NULL,
        batch_number INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_order_status_time (status, created_at),
        INDEX idx_order_table (table_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Check missing columns in orders
    $order_cols = [];
    $oc_res = $conn->query("SHOW COLUMNS FROM orders");
    if ($oc_res) {
        while ($oc_row = $oc_res->fetch_assoc()) {
            $order_cols[] = strtolower($oc_row['Field']);
        }
    }
    if (!in_array('dining_session_id', $order_cols)) {
        try { $conn->query("ALTER TABLE orders ADD COLUMN dining_session_id INT DEFAULT NULL"); } catch (Throwable $e) {}
    }
    if (!in_array('batch_number', $order_cols)) {
        try { $conn->query("ALTER TABLE orders ADD COLUMN batch_number INT DEFAULT 1"); } catch (Throwable $e) {}
    }

    // 5. Order Items table check
    @$conn->query("CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        menu_item_id INT NOT NULL,
        quantity INT NOT NULL,
        price DECIMAL(10, 2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
        INDEX idx_order_item_link (order_id, menu_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 6. Audit Logs & User Sessions tables
    @$conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT 1,
        username VARCHAR(100) DEFAULT 'Admin',
        event_type VARCHAR(50) NOT NULL,
        description TEXT NOT NULL,
        ip_address VARCHAR(50) DEFAULT '',
        user_agent VARCHAR(255) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS user_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(100) NOT NULL UNIQUE,
        user_role VARCHAR(50) NOT NULL,
        ip_address VARCHAR(50) NOT NULL,
        user_agent VARCHAR(255) NOT NULL,
        last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 6. Payment Settings table check
    @$conn->query("CREATE TABLE IF NOT EXISTS payment_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_name VARCHAR(200) DEFAULT 'QR Restaurant',
        payment_note VARCHAR(500),
        qr_code_image VARCHAR(255),
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pay_check = $conn->query("SELECT id FROM payment_settings LIMIT 1");
    if (!$pay_check || $pay_check->num_rows == 0) {
        @$conn->query("INSERT INTO payment_settings (restaurant_name, payment_note) VALUES ('QR Restaurant', 'Scan QR to pay via Esewa/Khalti')");
    }

    // 7. Waiter Calls table check
    @$conn->query("CREATE TABLE IF NOT EXISTS waiter_calls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_number VARCHAR(10) NOT NULL,
        status ENUM('pending', 'served') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_waiter_pending (status, table_number, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 8. Landing Page Settings table check
    @$conn->query("CREATE TABLE IF NOT EXISTS landing_page_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        brand_name VARCHAR(255) DEFAULT 'QR Cafe & Dining',
        brand_logo VARCHAR(255) DEFAULT '☕',
        brand_logo_image VARCHAR(255) DEFAULT '',
        hero_badge VARCHAR(255) DEFAULT '⭐ Gourmet Culinary Experience',
        hero_title VARCHAR(255) DEFAULT 'Artisanal Flavors & Modern Dining',
        hero_subtitle TEXT,
        hero_cta_primary VARCHAR(255) DEFAULT '🍽️ View Signature Menu',
        hero_cta_secondary VARCHAR(255) DEFAULT '📍 Location & Hours',
        qr_notice_text VARCHAR(500) DEFAULT 'Dining in? Scan the QR code on your table for live ordering & waiter service!',
        about_badge VARCHAR(255) DEFAULT 'About Us',
        about_title VARCHAR(255) DEFAULT 'Our Culinary Journey',
        about_text TEXT,
        about_feature1_title VARCHAR(255) DEFAULT '100%',
        about_feature1_desc VARCHAR(255) DEFAULT 'Fresh & Organic',
        about_feature2_title VARCHAR(255) DEFAULT 'Handcrafted',
        about_feature2_desc VARCHAR(255) DEFAULT 'Specialty Coffee',
        dishes_badge VARCHAR(255) DEFAULT 'Chef Recommended',
        dishes_title VARCHAR(255) DEFAULT 'Signature Dishes & Brews',
        dishes_subtitle VARCHAR(255) DEFAULT 'Explore our top culinary creations crafted daily with passion',
        location_title VARCHAR(255) DEFAULT 'Location & Address',
        location_address VARCHAR(255) DEFAULT 'Kathmandu, Nepal',
        contact_phone VARCHAR(50) DEFAULT '+977 9800000000',
        contact_email VARCHAR(100) DEFAULT 'info@qrcafe.com',
        hours_title VARCHAR(255) DEFAULT 'Opening Hours',
        opening_hours VARCHAR(255) DEFAULT 'Mon - Sun: 8:00 AM - 10:00 PM',
        hero_image VARCHAR(255) DEFAULT '',
        footer_copyright VARCHAR(255) DEFAULT '©️ 2026 QRMS · A Product by Sovryx Tech Pvt. Ltd. · All rights reserved.',
        kds_password VARCHAR(255) DEFAULT 'kitchen123',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $landing_check = $conn->query("SELECT id FROM landing_page_settings LIMIT 1");
    if (!$landing_check || $landing_check->num_rows == 0) {
        @$conn->query("INSERT INTO landing_page_settings (brand_name, brand_logo, hero_title, hero_subtitle, about_title, about_text, contact_phone, contact_email, location_address, opening_hours, kds_password) VALUES ('QR Cafe & Dining', '☕', 'Artisanal Flavors & Modern Dining', 'Experience handcrafted gourmet meals, freshly brewed espresso, and seamless digital table ordering.', 'Our Culinary Journey', 'Welcome to QR Cafe, where passion meets culinary perfection.', '+977 9800000000', 'info@qrcafe.com', 'Kathmandu, Nepal', 'Mon - Sun: 8:00 AM - 10:00 PM', 'kitchen123')");
    }

    // 9. Menu Addons table check
    @$conn->query("CREATE TABLE IF NOT EXISTS menu_addons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // =====================================================
    // INVENTORY MANAGEMENT SYSTEM TABLES
    // =====================================================

    // 10. Inventory Categories
    @$conn->query("CREATE TABLE IF NOT EXISTS inventory_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255) DEFAULT '',
        icon VARCHAR(50) DEFAULT '📦',
        display_order INT DEFAULT 0,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $inv_cat_check = $conn->query("SELECT id FROM inventory_categories LIMIT 1");
    if (!$inv_cat_check || $inv_cat_check->num_rows == 0) {
        @$conn->query("INSERT IGNORE INTO inventory_categories (name, icon, display_order) VALUES
            ('Vegetables','🥬',1),('Meat','🥩',2),('Seafood','🦐',3),('Dairy','🧀',4),
            ('Frozen','🧊',5),('Dry Goods','🌾',6),('Bakery','🍞',7),('Beverages','🥤',8),
            ('Packaging','📦',9),('Cleaning','🧹',10),('Spices','🌶️',11),('Other','📋',12)");
    }

    // 11. Inventory Units
    @$conn->query("CREATE TABLE IF NOT EXISTS inventory_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        abbreviation VARCHAR(10) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $unit_check = $conn->query("SELECT id FROM inventory_units LIMIT 1");
    if (!$unit_check || $unit_check->num_rows == 0) {
        @$conn->query("INSERT IGNORE INTO inventory_units (name, abbreviation) VALUES
            ('Kilogram','kg'),('Gram','g'),('Liter','L'),('Milliliter','mL'),
            ('Piece','pcs'),('Dozen','dz'),('Box','box'),('Packet','pkt'),
            ('Bottle','btl'),('Can','can'),('Bag','bag'),('Carton','ctn')");
    }

    // 12. Suppliers
    @$conn->query("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(200) NOT NULL,
        contact_person VARCHAR(150) DEFAULT '',
        phone VARCHAR(30) DEFAULT '',
        email VARCHAR(150) DEFAULT '',
        address TEXT,
        vat_pan VARCHAR(50) DEFAULT '',
        outstanding_balance DECIMAL(12,2) DEFAULT 0.00,
        performance_rating DECIMAL(3,1) DEFAULT 0.0,
        status ENUM('active','inactive','blacklisted') DEFAULT 'active',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_supplier_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 13. Inventory Items
    @$conn->query("CREATE TABLE IF NOT EXISTS inventory_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barcode VARCHAR(100) DEFAULT '',
        qr_token VARCHAR(64) DEFAULT '',
        name VARCHAR(200) NOT NULL,
        category_id INT DEFAULT NULL,
        brand VARCHAR(100) DEFAULT '',
        supplier_id INT DEFAULT NULL,
        unit_id INT DEFAULT NULL,
        current_stock DECIMAL(12,3) DEFAULT 0.000,
        minimum_stock DECIMAL(12,3) DEFAULT 0.000,
        maximum_stock DECIMAL(12,3) DEFAULT 0.000,
        purchase_cost DECIMAL(12,2) DEFAULT 0.00,
        average_cost DECIMAL(12,2) DEFAULT 0.00,
        storage_location VARCHAR(100) DEFAULT '',
        batch_number VARCHAR(100) DEFAULT '',
        expiry_date DATE DEFAULT NULL,
        status ENUM('active','inactive','discontinued') DEFAULT 'active',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_inv_category (category_id),
        INDEX idx_inv_supplier (supplier_id),
        INDEX idx_inv_stock (current_stock, minimum_stock),
        INDEX idx_inv_expiry (expiry_date),
        INDEX idx_inv_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 14. Purchase Orders
    @$conn->query("CREATE TABLE IF NOT EXISTS purchase_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_number VARCHAR(50) NOT NULL,
        supplier_id INT NOT NULL,
        status ENUM('draft','approved','ordered','partial','received','cancelled','completed') DEFAULT 'draft',
        subtotal DECIMAL(12,2) DEFAULT 0.00,
        tax_amount DECIMAL(12,2) DEFAULT 0.00,
        discount_amount DECIMAL(12,2) DEFAULT 0.00,
        total_amount DECIMAL(12,2) DEFAULT 0.00,
        attachments TEXT,
        notes TEXT,
        created_by VARCHAR(100) DEFAULT 'admin',
        approved_by VARCHAR(100) DEFAULT '',
        order_date DATE DEFAULT NULL,
        expected_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_po_supplier (supplier_id),
        INDEX idx_po_status (status),
        INDEX idx_po_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 15. Purchase Order Items
    @$conn->query("CREATE TABLE IF NOT EXISTS purchase_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        inventory_item_id INT NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        received_qty DECIMAL(12,3) DEFAULT 0.000,
        rejected_qty DECIMAL(12,3) DEFAULT 0.000,
        INDEX idx_poi_po (po_id),
        INDEX idx_poi_item (inventory_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 16. Goods Receipts
    @$conn->query("CREATE TABLE IF NOT EXISTS goods_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT DEFAULT NULL,
        supplier_id INT DEFAULT NULL,
        inventory_item_id INT NOT NULL,
        received_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        rejected_qty DECIMAL(12,3) DEFAULT 0.000,
        damaged_qty DECIMAL(12,3) DEFAULT 0.000,
        unit_cost DECIMAL(12,2) DEFAULT 0.00,
        batch_number VARCHAR(100) DEFAULT '',
        expiry_date DATE DEFAULT NULL,
        invoice_number VARCHAR(100) DEFAULT '',
        received_by VARCHAR(100) DEFAULT 'admin',
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        INDEX idx_gr_po (po_id),
        INDEX idx_gr_item (inventory_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 17. Inventory Transactions (Immutable Audit Log)
    @$conn->query("CREATE TABLE IF NOT EXISTS inventory_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inventory_item_id INT NOT NULL,
        type ENUM('purchase','consumption','waste','adjustment','transfer','return','damage','manual') NOT NULL,
        quantity DECIMAL(12,3) NOT NULL,
        direction ENUM('in','out') NOT NULL,
        reference_type VARCHAR(50) DEFAULT '',
        reference_id INT DEFAULT NULL,
        stock_before DECIMAL(12,3) DEFAULT 0.000,
        stock_after DECIMAL(12,3) DEFAULT 0.000,
        unit_cost DECIMAL(12,2) DEFAULT 0.00,
        notes TEXT,
        created_by VARCHAR(100) DEFAULT 'system',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_it_item (inventory_item_id),
        INDEX idx_it_type (type),
        INDEX idx_it_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 18. Recipes (Bill of Materials)
    @$conn->query("CREATE TABLE IF NOT EXISTS recipes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        menu_item_id INT NOT NULL,
        name VARCHAR(200) DEFAULT '',
        yield_qty DECIMAL(10,2) DEFAULT 1.00,
        notes TEXT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_recipe_menu (menu_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 19. Recipe Items (Ingredients per recipe)
    @$conn->query("CREATE TABLE IF NOT EXISTS recipe_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipe_id INT NOT NULL,
        inventory_item_id INT NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        unit_id INT DEFAULT NULL,
        notes VARCHAR(255) DEFAULT '',
        INDEX idx_ri_recipe (recipe_id),
        INDEX idx_ri_item (inventory_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 20. Inventory Waste
    @$conn->query("CREATE TABLE IF NOT EXISTS inventory_waste (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inventory_item_id INT NOT NULL,
        quantity DECIMAL(12,3) NOT NULL,
        reason ENUM('kitchen_waste','expired','customer_return','damaged','spoilage','other') DEFAULT 'kitchen_waste',
        unit_cost DECIMAL(12,2) DEFAULT 0.00,
        total_cost DECIMAL(12,2) DEFAULT 0.00,
        reported_by VARCHAR(100) DEFAULT 'admin',
        approved_by VARCHAR(100) DEFAULT '',
        approval_status ENUM('pending','approved','rejected') DEFAULT 'pending',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_waste_item (inventory_item_id),
        INDEX idx_waste_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 21. Stock Audits
    @$conn->query("CREATE TABLE IF NOT EXISTS stock_audits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inventory_item_id INT NOT NULL,
        system_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        physical_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        variance DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        adjustment_made TINYINT(1) DEFAULT 0,
        audited_by VARCHAR(100) DEFAULT 'admin',
        approved_by VARCHAR(100) DEFAULT '',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_item (inventory_item_id),
        INDEX idx_audit_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 22. Inventory Alerts
    @$conn->query("CREATE TABLE IF NOT EXISTS inventory_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inventory_item_id INT NOT NULL,
        alert_type ENUM('low_stock','out_of_stock','near_expiry','expired','overstock') NOT NULL,
        message VARCHAR(500) DEFAULT '',
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_alert_item (inventory_item_id),
        INDEX idx_alert_type (alert_type),
        INDEX idx_alert_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // =====================================================
    // ASSET MANAGEMENT SYSTEM TABLES
    // =====================================================

    // 23. Asset Categories
    @$conn->query("CREATE TABLE IF NOT EXISTS asset_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255) DEFAULT '',
        icon VARCHAR(50) DEFAULT '🏗️',
        depreciation_method ENUM('straight_line','declining_balance','none') DEFAULT 'straight_line',
        default_useful_life INT DEFAULT 60,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $asset_cat_check = $conn->query("SELECT id FROM asset_categories LIMIT 1");
    if (!$asset_cat_check || $asset_cat_check->num_rows == 0) {
        @$conn->query("INSERT IGNORE INTO asset_categories (name, icon, default_useful_life) VALUES
            ('Kitchen Equipment','🍳',120),('Furniture','🪑',120),('Electronics','💻',60),
            ('Vehicles','🚗',120),('Building','🏢',360),('Utensils','🍴',36),
            ('HVAC Systems','❄️',120),('POS Hardware','🖥️',60),('Other','📋',60)");
    }

    // 24. Assets Register
    @$conn->query("CREATE TABLE IF NOT EXISTS assets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asset_code VARCHAR(50) NOT NULL,
        qr_token VARCHAR(64) DEFAULT '',
        barcode VARCHAR(100) DEFAULT '',
        name VARCHAR(200) NOT NULL,
        category_id INT DEFAULT NULL,
        brand VARCHAR(100) DEFAULT '',
        model VARCHAR(100) DEFAULT '',
        serial_number VARCHAR(100) DEFAULT '',
        purchase_date DATE DEFAULT NULL,
        purchase_cost DECIMAL(12,2) DEFAULT 0.00,
        supplier_id INT DEFAULT NULL,
        warranty_expiry DATE DEFAULT NULL,
        assigned_branch VARCHAR(100) DEFAULT 'Main',
        assigned_location VARCHAR(100) DEFAULT '',
        assigned_employee VARCHAR(100) DEFAULT '',
        `condition` ENUM('excellent','good','fair','poor','damaged') DEFAULT 'good',
        status ENUM('available','in_use','maintenance','repair','retired','disposed','lost') DEFAULT 'available',
        useful_life_months INT DEFAULT 60,
        residual_value DECIMAL(12,2) DEFAULT 0.00,
        current_value DECIMAL(12,2) DEFAULT 0.00,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_asset_category (category_id),
        INDEX idx_asset_status (status),
        INDEX idx_asset_qr (qr_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 25. Asset Maintenance
    @$conn->query("CREATE TABLE IF NOT EXISTS asset_maintenance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asset_id INT NOT NULL,
        type ENUM('preventive','corrective','emergency') DEFAULT 'preventive',
        description TEXT,
        technician VARCHAR(150) DEFAULT '',
        cost DECIMAL(12,2) DEFAULT 0.00,
        parts_used TEXT,
        service_date DATE NOT NULL,
        next_service_date DATE DEFAULT NULL,
        status ENUM('scheduled','in_progress','completed','cancelled') DEFAULT 'scheduled',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_maint_asset (asset_id),
        INDEX idx_maint_date (service_date),
        INDEX idx_maint_next (next_service_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 26. Asset Transfers
    @$conn->query("CREATE TABLE IF NOT EXISTS asset_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asset_id INT NOT NULL,
        from_location VARCHAR(100) DEFAULT '',
        to_location VARCHAR(100) DEFAULT '',
        from_employee VARCHAR(100) DEFAULT '',
        to_employee VARCHAR(100) DEFAULT '',
        transfer_date DATE NOT NULL,
        reason TEXT,
        transferred_by VARCHAR(100) DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_transfer_asset (asset_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 27. Asset Depreciation Log
    @$conn->query("CREATE TABLE IF NOT EXISTS asset_depreciation (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asset_id INT NOT NULL,
        period_date DATE NOT NULL,
        method ENUM('straight_line','declining_balance') DEFAULT 'straight_line',
        depreciation_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        accumulated_depreciation DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        book_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dep_asset (asset_id),
        INDEX idx_dep_period (period_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 28. Asset Logs (Immutable audit trail for asset lifecycle events)
    @$conn->query("CREATE TABLE IF NOT EXISTS asset_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asset_id INT NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        description TEXT,
        changed_by VARCHAR(100) DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_al_asset (asset_id),
        INDEX idx_al_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 29. Asset Warranties
    @$conn->query("CREATE TABLE IF NOT EXISTS asset_warranties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asset_id INT NOT NULL,
        provider_name VARCHAR(150) DEFAULT '',
        policy_number VARCHAR(100) DEFAULT '',
        start_date DATE DEFAULT NULL,
        expiry_date DATE NOT NULL,
        coverage_details TEXT,
        claim_status ENUM('active','claim_pending','repaired','replaced','expired') DEFAULT 'active',
        claim_notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_warr_asset (asset_id),
        INDEX idx_warr_expiry (expiry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Column checks for asset_categories
    $ac_cols = [];
    $ac_res = $conn->query("SHOW COLUMNS FROM asset_categories");
    if ($ac_res) {
        while ($ac_row = $ac_res->fetch_assoc()) {
            $ac_cols[] = strtolower($ac_row['Field']);
        }
    }
    if (!in_array('depreciation_rate', $ac_cols)) {
        try { $conn->query("ALTER TABLE asset_categories ADD COLUMN depreciation_rate DECIMAL(5,2) DEFAULT 0.00"); } catch (Throwable $e) {}
    }

    // Column checks for orders table (Idempotency & Concurrency)
    $ord_cols = [];
    $ord_res = $conn->query("SHOW COLUMNS FROM orders");
    if ($ord_res) {
        while ($ord_row = $ord_res->fetch_assoc()) {
            $ord_cols[] = strtolower($ord_row['Field']);
        }
    }
    if (!in_array('idempotency_key', $ord_cols)) {
        try { $conn->query("ALTER TABLE orders ADD COLUMN idempotency_key VARCHAR(64) UNIQUE DEFAULT NULL"); } catch (Throwable $e) {}
    }

    // =====================================================
    // PERFORMANCE INDEXES FOR REALTIME DASHBOARD POLLING
    // The Operations Center polls aggregated queries every few seconds.
    // These indexes keep those aggregations fast and index-friendly.
    // =====================================================
    ensureIndex($conn, 'orders', 'idx_orders_created', 'created_at');
    ensureIndex($conn, 'orders', 'idx_orders_status_pay', 'status, payment_status');
    ensureIndex($conn, 'orders', 'idx_orders_pay_status', 'payment_status, status');
    ensureIndex($conn, 'orders', 'idx_orders_table_status', 'table_number, status');

    // Run SaaS Multi-Tenancy Migrations and Column Checks
    applySaaSMultiTenancyMigration($conn);
}

/**
 * Execute SaaS Schema Migrations & Multi-Tenant Column Integrity Checks
 */
function applySaaSMultiTenancyMigration($conn) {
    if (!$conn) return;

    // 1. Execute SQL Migration File if tables missing
    $checkSaas = $conn->query("SHOW TABLES LIKE 'restaurants'");
    if (!$checkSaas || $checkSaas->num_rows == 0) {
        $migrationSql = @file_get_contents(__DIR__ . '/database/migrations/004_saas_multi_tenancy.sql');
        if (!empty($migrationSql)) {
            $statements = array_filter(array_map('trim', explode(';', $migrationSql)));
            foreach ($statements as $stmt) {
                if (!empty($stmt)) {
                    @$conn->query($stmt);
                }
            }
        }
    }

    // 2. Tenant-Owned Entities Column Audit (Ensure restaurant_id exists on all tenant tables)
    $tenantTables = [
        'admin_users', 'categories', 'menu_items', 'tables', 'dining_sessions',
        'orders', 'order_items', 'inventory_categories', 'inventory_units',
        'suppliers', 'inventory_items', 'recipes', 'recipe_items',
        'inventory_transactions', 'purchase_orders', 'purchase_order_items',
        'goods_receipts', 'inventory_waste', 'stock_audits', 'inventory_alerts',
        'assets', 'asset_categories', 'asset_maintenance', 'asset_warranties',
        'asset_transfers', 'asset_depreciation', 'asset_logs',
        'payment_gateways', 'payment_settings', 'payment_transactions', 'audit_logs',
        'waiter_calls', 'landing_page_settings', 'menu_addons'
    ];

    foreach ($tenantTables as $table) {
        $tblCheck = $conn->query("SHOW TABLES LIKE '$table'");
        if ($tblCheck && $tblCheck->num_rows > 0) {
            $colsRes = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'restaurant_id'");
            if ($colsRes && $colsRes->num_rows == 0) {
                try {
                    $conn->query("ALTER TABLE `$table` ADD COLUMN restaurant_id INT NOT NULL DEFAULT 1");
                    $conn->query("ALTER TABLE `$table` ADD INDEX idx_tenant_rest (restaurant_id)");
                } catch (Throwable $e) {}
            }
            // Backfill 0 or NULL to 1
            @$conn->query("UPDATE `$table` SET restaurant_id = 1 WHERE restaurant_id IS NULL OR restaurant_id = 0");
        }
    }

    // 3. Admin Users table columns check (force_password_change, is_super_admin)
    $auColsRes = $conn->query("SHOW COLUMNS FROM admin_users");
    $auCols = [];
    if ($auColsRes) {
        while ($r = $auColsRes->fetch_assoc()) {
            $auCols[] = strtolower($r['Field']);
        }
    }
    if (!in_array('force_password_change', $auCols)) {
        try { $conn->query("ALTER TABLE admin_users ADD COLUMN force_password_change TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
    }
    if (!in_array('is_super_admin', $auCols)) {
        try { $conn->query("ALTER TABLE admin_users ADD COLUMN is_super_admin TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
    }

    // NOTE: The Super Admin account is NOT auto-seeded here anymore. It is created
    // deliberately by `php database/migrate.php --create-superadmin` with a password
    // supplied via the APP_SUPER_ADMIN_PASSWORD environment variable (fail-closed).
}


/**
 * Create an index on a table if it does not already exist.
 * Idempotent and safe to run on every request bootstrap.
 */
function ensureIndex($conn, $table, $indexName, $columns) {
    if (!$conn) return;
    $check = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    if ($check && $check->num_rows > 0) {
        return;
    }
    @$conn->query("ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)");
}

// Backward Compatibility Function Aliases
function sanitize($data) {
    return Security::sanitize($data);
}

function isAdminLoggedIn() {
    return Auth::isAdminLoggedIn();
}

function isKitchenLoggedIn() {
    return Auth::isKitchenLoggedIn();
}

function requireAdminLogin() {
    Auth::requireAdmin();
    // Enforce tenant context + account status + subscription on every admin page.
    if (class_exists('TenantContext')) {
        TenantContext::requireTenant();
    }
    // Force password change whenever temporary/default credentials are in use.
    if (!empty($_SESSION['force_password_change'])) {
        $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($currentPage !== 'change-password.php') {
            header('Location: change-password.php');
            exit;
        }
    }
}

function requireKitchenLogin() {
    Auth::requireKitchen();
}
