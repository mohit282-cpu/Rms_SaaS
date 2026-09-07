<?php
// Global Output Buffering to prevent headers already sent errors on redirects
if (ob_get_level() === 0) {
    ob_start();
}

// Load PSR-4 Autoloader & Environment Configurations
require_once __DIR__ . '/app/Helpers/Autoloader.php';
Autoloader::register();
Autoloader::loadEnv(__DIR__ . '/.env');

// Production Error Display & Server Logging Configuration
$appEnv = strtolower(getenv('APP_ENV') ?: 'production');
$appDebug = filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);

if ($appEnv === 'production' || !$appDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
ini_set('log_errors', '1');
$logDir = __DIR__ . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

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

// Set System Timezone to Asia/Kathmandu
date_default_timezone_set('Asia/Kathmandu');

// Load Helper Classes
require_once __DIR__ . '/helpers/Security.php';
require_once __DIR__ . '/helpers/CSRF.php';
require_once __DIR__ . '/helpers/Auth.php';
require_once __DIR__ . '/helpers/CustomerSessionService.php';
require_once __DIR__ . '/helpers/TenantContext.php';
require_once __DIR__ . '/helpers/SubscriptionService.php';
require_once __DIR__ . '/helpers/RateLimiter.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/Inventory.php';
require_once __DIR__ . '/helpers/PermissionService.php';
require_once __DIR__ . '/helpers/AuthorizationService.php';
require_once __DIR__ . '/helpers/BillingService.php';
require_once __DIR__ . '/helpers/RestaurantSettingsService.php';
require_once __DIR__ . '/helpers/HrService.php';
require_once __DIR__ . '/helpers/RegisterShiftService.php';
require_once __DIR__ . '/helpers/TenantDeletionService.php';
require_once __DIR__ . '/helpers/QRCodeService.php';
require_once __DIR__ . '/helpers/LoyaltyService.php';
require_once __DIR__ . '/helpers/OrderService.php';

// Initialize PHP session automatically for all requests
Auth::startSession();

// Set Global Production Security Headers
Security::setSecurityHeaders();

// Global Money Formatter Helper
function formatPrice($amount) {
    return 'Rs. ' . number_format((float)$amount, 2);
}

/**
 * Fetch Tenant-Specific Loyalty Settings with Fallback Defaults
 */
function getLoyaltySettings($conn, $tenantId) {
    return RestaurantSettingsService::getLoyaltySettings($conn, (int)$tenantId);
}

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

    try {
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS);

        if ($conn->connect_error) {
            return null;
        }

        if (!$conn->select_db(DB_NAME)) {
            // Database must exist. Run `php database/migrate.php` once to provision it.
            return null;
        }
    } catch (\Throwable $e) {
        error_log('Database Connection Error: ' . $e->getMessage());
        return null;
    }

    $conn->set_charset("utf8mb4");

    return $conn;
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
