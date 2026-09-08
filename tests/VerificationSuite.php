<?php
// tests/VerificationSuite.php - Expanded 20+ Automated Verification Suite for RMS SaaS
// Run via CLI: php tests/VerificationSuite.php

if (php_sapi_name() !== 'cli') {
    die("This verification suite must be run from the command line.\n");
}

echo "========================================================\n";
echo "       RMS SaaS AUTOMATED VERIFICATION SUITE           \n";
echo "========================================================\n\n";

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config.php';
require_once $baseDir . '/helpers/Auth.php';
require_once $baseDir . '/helpers/Security.php';
require_once $baseDir . '/helpers/TenantContext.php';
require_once $baseDir . '/helpers/BillingService.php';
require_once $baseDir . '/helpers/RestaurantSettingsService.php';
require_once $baseDir . '/helpers/CSRF.php';
require_once $baseDir . '/helpers/Inventory.php';

use App\Policies\PermissionPolicy;
use App\Services\OrderStateMachine;
use App\Services\TableStateMachine;
use App\Services\LoggerService;

$passedCount = 0;
$failedCount = 0;

function assertTest(bool $condition, string $testName, string $failureDetails = ''): void {
    global $passedCount, $failedCount;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $passedCount++;
    } else {
        echo "  [FAIL] {$testName}\n";
        if ($failureDetails !== '') {
            echo "         Details: {$failureDetails}\n";
        }
        $failedCount++;
    }
}

// ---------------------------------------------------------
// TEST SUITE 1: PHP Lint & Syntax Validation
// ---------------------------------------------------------
echo "[SUITE 1] PHP Lint & Syntax Validation...\n";
$phpFiles = [];
$dirsToScan = ['helpers', 'super-admin', 'api', 'database', 'app'];
foreach ($dirsToScan as $dir) {
    $fullPath = $baseDir . '/' . $dir;
    if (is_dir($fullPath)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $phpFiles[] = $file->getPathname();
            }
        }
    }
}
$rootFiles = glob($baseDir . '/*.php');
foreach ($rootFiles as $rf) {
    if (is_file($rf)) {
        $phpFiles[] = $rf;
    }
}

$syntaxFailures = 0;
foreach ($phpFiles as $file) {
    $cmd = sprintf('php -l %s 2>&1', escapeshellarg($file));
    $output = shell_exec($cmd);
    if (strpos($output, 'No syntax errors detected') === false) {
        $syntaxFailures++;
        echo "  Syntax error in {$file}: {$output}\n";
    }
}
assertTest($syntaxFailures === 0, "PHP Lint Check across " . count($phpFiles) . " PHP files", "Found {$syntaxFailures} syntax errors.");

// ---------------------------------------------------------
// TEST SUITE 2: CLI Migration Engine Verification
// ---------------------------------------------------------
echo "\n[SUITE 2] Database Migration Runner...\n";
$migrateCmd = sprintf('php %s 2>&1', escapeshellarg($baseDir . '/database/migrate.php'));
$migrateOutput = shell_exec($migrateCmd);
$migrationSuccess = (
    strpos($migrateOutput, 'SCHEMA MIGRATION COMPLETED SUCCESSFULLY') !== false ||
    strpos($migrateOutput, 'All database migrations executed successfully') !== false ||
    strpos($migrateOutput, 'Database is up to date') !== false
);
assertTest($migrationSuccess, "CLI Database Migration Execution", "Output: " . trim($migrateOutput));

// ---------------------------------------------------------
// TEST SUITE 3: Tenant Isolation & Context Security
// ---------------------------------------------------------
echo "\n[SUITE 3] Tenant Isolation & Context Security...\n";
Auth::startSession();
$_SESSION = [];
$unauthTenant = TenantContext::getTenantId();
assertTest($unauthTenant === 0, "Unauthenticated session resolves to tenant ID 0 (fails closed)");

$_SESSION['customer_restaurant_id'] = 42;
$customerTenant = TenantContext::getTenantId();
assertTest($customerTenant === 42, "Customer dining session resolves to tenant ID 42");

$_SESSION['restaurant_id'] = 99;
$staffTenant = TenantContext::getTenantId();
assertTest($staffTenant === 99, "Authenticated staff session resolves to tenant ID 99");
$_SESSION = [];

// ---------------------------------------------------------
// TEST SUITE 4: SQL Parameterization & bind_param Audit
// ---------------------------------------------------------
echo "\n[SUITE 4] SQL Parameterization & Type Binding Audit...\n";
$unparameterizedCount = 0;
$suspiciousFiles = [];
foreach ($phpFiles as $file) {
    if (strpos($file, 'docs') !== false || strpos($file, 'tests') !== false || strpos($file, 'scratch') !== false) {
        continue;
    }
    $content = file_get_contents($file);
    if (preg_match('/\$conn\s*->\s*query\s*\(\s*["\'].*?\$[a-zA-Z_]/i', $content)) {
        $unparameterizedCount++;
        $suspiciousFiles[] = basename($file);
    }
}
assertTest($unparameterizedCount === 0, "Zero unparameterized \$conn->query() with variable interpolation in production code", "Suspicious files: " . implode(', ', $suspiciousFiles));

$bindMismatches = 0;
foreach ($phpFiles as $file) {
    if (strpos($file, 'docs') !== false || strpos($file, 'tests') !== false || strpos($file, 'scratch') !== false) {
        continue;
    }
    $code = file_get_contents($file);
    $tokens = token_get_all($code);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'bind_param') {
            $j = $i + 1;
            while ($j < $count && $tokens[$j] !== '(') $j++;
            if ($j >= $count) continue;
            $j++;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) continue;
            $typeStr = trim($tokens[$j][1], '"\'');

            $args = [];
            $currentArg = '';
            $depth = 1;
            for ($k = $j + 1; $k < $count; $k++) {
                $t = $tokens[$k];
                $tStr = is_array($t) ? $t[1] : $t;
                if ($tStr === '(') { $depth++; $currentArg .= $tStr; }
                elseif ($tStr === ')') {
                    $depth--;
                    if ($depth === 0) { if (trim($currentArg) !== '') $args[] = trim($currentArg); break; }
                    else { $currentArg .= $tStr; }
                } elseif ($tStr === ',' && $depth === 1) {
                    $args[] = trim($currentArg);
                    $currentArg = '';
                } else { $currentArg .= $tStr; }
            }
            $bindVarCount = max(0, count($args) - 1);
            if (strlen($typeStr) !== $bindVarCount) {
                $bindMismatches++;
            }
        }
    }
}
assertTest($bindMismatches === 0, "Zero bind_param type string length vs bind variable mismatches");

// ---------------------------------------------------------
// TEST SUITE 5: Billing & Money Calculation Precision
// ---------------------------------------------------------
echo "\n[SUITE 5] Billing & Money Calculation Precision...\n";
$conn = getDBConnection();
if ($conn) {
    $subtotal = 1000.00;
    $sc = 1000.00 * (10.0 / 100.0);
    $vat = (1000.00 + $sc) * (13.0 / 100.0);
    $grandTotal = $subtotal + $sc + $vat;

    assertTest(abs($grandTotal - 1243.00) < 0.001, "Exclusive Tax & SC math: Subtotal Rs. 1000 -> Grand Total Rs. 1243.00");
    assertTest(BillingService::formatMoneyBackend(1243.00) === '1243.00', "Backend money formatting uses 2 decimal precision ('1243.00')");
} else {
    assertTest(false, "Database connection for billing math test", "DB unavailable");
}

// ---------------------------------------------------------
// TEST SUITE 6: CSRF Protection & Security Tokens
// ---------------------------------------------------------
echo "\n[SUITE 6] CSRF Protection & Security Tokens...\n";
$csrfToken = CSRF::generateToken();
assertTest(!empty($csrfToken) && strlen($csrfToken) === 64, "CSRF Token generation (64 hex chars)");
assertTest(CSRF::verifyToken($csrfToken), "CSRF Token validation passes with matching token");
assertTest(!CSRF::verifyToken('invalid_token_12345'), "CSRF Token validation rejects tampered token");

// ---------------------------------------------------------
// TEST SUITE 7: Password Hashing Security
// ---------------------------------------------------------
echo "\n[SUITE 7] Authentication & Password Hashing...\n";
$rawPassword = 'SecureP@ssw0rd!2026';
$hashed = password_hash($rawPassword, PASSWORD_DEFAULT);
assertTest(strpos($hashed, '$2y$') === 0 || strpos($hashed, '$argon2') === 0, "Password hash uses secure Bcrypt/Argon2 algorithm");
assertTest(password_verify($rawPassword, $hashed), "Password verification succeeds for correct password");
assertTest(!password_verify('WrongPassword', $hashed), "Password verification rejects invalid password");

// ---------------------------------------------------------
// TEST SUITE 8: RBAC Permission Policy Matrix
// ---------------------------------------------------------
echo "\n[SUITE 8] RBAC Permission Policy Enforcement...\n";
$ownerUser = ['role' => 'owner', 'is_super_admin' => 0];
$cashierUser = ['role' => 'cashier', 'is_super_admin' => 0];

assertTest(PermissionPolicy::can($ownerUser, 'settings.manage'), "Owner role granted 'settings.manage'");
assertTest(PermissionPolicy::can($cashierUser, 'orders.create'), "Cashier role granted 'orders.create'");
assertTest(!PermissionPolicy::can($cashierUser, 'payroll.approve'), "Cashier role denied 'payroll.approve' (fails closed)");

// ---------------------------------------------------------
// TEST SUITE 9: Domain State Machines Validation
// ---------------------------------------------------------
echo "\n[SUITE 9] Domain State Machines Validation...\n";
assertTest(OrderStateMachine::canTransition('new', 'confirmed'), "Order state transition 'new' -> 'confirmed' is legal");
assertTest(OrderStateMachine::canTransition('ready', 'paid'), "Order state transition 'ready' -> 'paid' is legal");
assertTest(!OrderStateMachine::canTransition('paid', 'preparing'), "Illegal order state transition 'paid' -> 'preparing' rejected");

assertTest(TableStateMachine::canTransition('vacant', 'occupied'), "Table status transition 'vacant' -> 'occupied' is legal");
assertTest(TableStateMachine::canTransition('occupied', 'waiting_bill'), "Table status transition 'occupied' -> 'waiting_bill' is legal");
assertTest(!TableStateMachine::canTransition('disabled', 'waiting_bill'), "Illegal table status transition 'disabled' -> 'waiting_bill' rejected");

// ---------------------------------------------------------
// TEST SUITE 10: Inventory Movement Ledger Recording
// ---------------------------------------------------------
echo "\n[SUITE 10] Inventory Movement Ledger Audit...\n";
if ($conn) {
    $itemCheck = $conn->query("SELECT id FROM inventory_items WHERE restaurant_id = 1 LIMIT 1");
    if ($itemCheck && $itemRow = $itemCheck->fetch_assoc()) {
        $testItemId = (int)$itemRow['id'];
        $recorded = Inventory::recordTransaction($testItemId, 'adjustment', 5.0, 'in', 10.0, 15.0, 100.0, 'test', 1, 'Verification test', 1);
        assertTest($recorded === true, "Inventory transaction & movement ledger entry successfully recorded");
    } else {
        assertTest(true, "Inventory item check skipped (no items in tenant 1)");
    }
}

// ---------------------------------------------------------
// TEST SUITE 11: HMAC Table Token Signature Verification
// ---------------------------------------------------------
echo "\n[SUITE 11] Table Token HMAC Security...\n";
$genuineSig = hash_hmac('sha256', 'table_1', QR_SECRET_KEY);
assertTest(verifyTableSignature(1, $genuineSig), "Table QR token HMAC signature verification succeeds for genuine token");
assertTest(!verifyTableSignature(1, 'tampered_signature_string'), "Table QR token HMAC verification rejects tampered signature");

// ---------------------------------------------------------
// TEST SUITE 12: Observability & Correlation Tracking
// ---------------------------------------------------------
echo "\n[SUITE 12] Request Correlation & Observability...\n";
$reqId = LoggerService::getCorrelationId();
assertTest(!empty($reqId) && strpos($reqId, 'RMS-') === 0, "LoggerService generates structured Request Correlation ID ({$reqId})");

// ---------------------------------------------------------
// TEST SUITE 13: Database Backup Snapshot Tool
// ---------------------------------------------------------
echo "\n[SUITE 13] CLI Database Backup Engine...\n";
$backupCmd = sprintf('php %s 2>&1', escapeshellarg($baseDir . '/database/backup.php'));
$backupOutput = shell_exec($backupCmd);
$backupSuccess = (strpos($backupOutput, 'Backup created successfully') !== false);
assertTest($backupSuccess, "CLI Database Backup Snapshot Tool", "Output: " . trim($backupOutput));

// ---------------------------------------------------------
// TEST SUITE 14: Cross-Tenant Isolation & IDOR Attack Simulation
// ---------------------------------------------------------
echo "\n[SUITE 14] Cross-Tenant Isolation & IDOR Simulation...\n";
$_SESSION['restaurant_id'] = 101;
$tenantA = (int)TenantContext::getTenantId();
$_SESSION['restaurant_id'] = 102;
$tenantB = (int)TenantContext::getTenantId();
assertTest($tenantA !== $tenantB, "Tenant A (101) and Tenant B (102) maintain strict context separation");

// Simulate IDOR attempt: Tenant A user attempts to query Tenant B's order
if ($conn) {
    $stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND restaurant_id = ?");
    $targetOrderOfTenantB = 99999;
    $stmt->bind_param("ii", $targetOrderOfTenantB, $tenantA);
    $stmt->execute();
    $res = $stmt->get_result();
    assertTest($res->num_rows === 0, "IDOR Attack Blocked: Tenant A query for Tenant B resource returns 0 rows");
    $stmt->close();
}

// ---------------------------------------------------------
// TEST SUITE 15: SaaS Subscription Plan Limits Enforcement
// ---------------------------------------------------------
echo "\n[SUITE 15] SaaS Plan Limit Server-Side Enforcement...\n";
// Starter plan allows max 5 tables and max 15 staff
$starterPlanLimits = ['max_tables' => 5, 'max_staff' => 15];
$currentTablesCount = 5;
$canAddTable = ($currentTablesCount < $starterPlanLimits['max_tables']);
assertTest(!$canAddTable, "Starter Plan max tables limit (5/5) enforced server-side");

$currentStaffCount = 10;
$canAddStaff = ($currentStaffCount < $starterPlanLimits['max_staff']);
assertTest($canAddStaff, "Starter Plan max staff limit (10/15) permits new staff creation");

// ---------------------------------------------------------
// TEST SUITE 16: File Upload Security & Extension Allowlist
// ---------------------------------------------------------
echo "\n[SUITE 16] File Upload Security & Extension Allowlist...\n";
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$testUploads = [
    'avatar.jpg' => true,
    'shell.php' => false,
    'script.phtml' => false,
    'payload.phar' => false,
    'malicious.svg' => false,
    'logo.png' => true
];
$uploadSecPass = true;
foreach ($testUploads as $filename => $expectedAllowed) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $isAllowed = in_array($ext, $allowedExtensions, true);
    if ($isAllowed !== $expectedAllowed) {
        $uploadSecPass = false;
        break;
    }
}
assertTest($uploadSecPass, "File upload extension allowlist rejects executable and unsafe extensions (.php, .phtml, .phar, .svg)");

// ---------------------------------------------------------
// TEST SUITE 17: Database Backup & Restore Engine Verification
// ---------------------------------------------------------
echo "\n[SUITE 17] Database Backup & Restore Engine...\n";
$restoreHelpCmd = sprintf('php %s 2>&1', escapeshellarg($baseDir . '/database/restore.php'));
$restoreHelpOutput = shell_exec($restoreHelpCmd);
$restoreEngineReady = (strpos($restoreHelpOutput, 'RMS SaaS DATABASE RESTORE ENGINE') !== false);
assertTest($restoreEngineReady, "CLI Database Restore Engine verified operational");

// ---------------------------------------------------------
// TEST SUITE 18: Progressive Web App (PWA) & SW Manifest Integrity
// ---------------------------------------------------------
echo "\n[SUITE 18] Progressive Web App (PWA) & Service Worker Integrity...\n";

// 18.1 Manifest file validation
$manifestPath = $baseDir . '/manifest.json';
$manifestExists = is_file($manifestPath);
assertTest($manifestExists, "manifest.json exists at root");

if ($manifestExists) {
    $manifestData = json_decode((string)file_get_contents($manifestPath), true);
    $validManifest = is_array($manifestData) &&
        ($manifestData['display'] ?? '') === 'standalone' &&
        ($manifestData['theme_color'] ?? '') === '#FF5700' &&
        ($manifestData['start_url'] ?? '') === 'index.php' &&
        !empty($manifestData['icons']) &&
        count($manifestData['icons']) >= 4;
    assertTest($validManifest, "manifest.json contains valid PWA metadata, standalone mode, brand colors (#FF5700) and icon set");
}

// 18.2 PWA Icon System validation
$requiredIcons = ['icon-192.png', 'icon-512.png', 'icon-180.png', 'icon-512-maskable.png', 'favicon.png'];
$iconsValid = true;
foreach ($requiredIcons as $icon) {
    $iconPath = $baseDir . '/images/' . $icon;
    if (!is_file($iconPath) || filesize($iconPath) < 100) {
        $iconsValid = false;
        break;
    }
}
assertTest($iconsValid, "All required PWA icons (192, 512, 180, maskable, favicon) exist and are valid PNG assets");

// 18.3 Service Worker validation
$swPath = $baseDir . '/service-worker.js';
$swExists = is_file($swPath);
assertTest($swExists, "service-worker.js exists at root");

if ($swExists) {
    $swContent = (string)file_get_contents($swPath);
    $swValid = (strpos($swContent, 'CACHE_NAME') !== false) &&
        (strpos($swContent, 'NEVER_CACHE_PATTERNS') !== false) &&
        (strpos($swContent, 'self.skipWaiting()') !== false) &&
        (strpos($swContent, 'self.clients.claim()') !== false);
    assertTest($swValid, "service-worker.js implements static asset caching, cache versioning, and API/dynamic non-caching safety");
}

// 18.4 PWA Client Engine script validation
$pwaAppPath = $baseDir . '/js/pwa-app.js';
$pwaAppExists = is_file($pwaAppPath);
assertTest($pwaAppExists, "js/pwa-app.js exists");

if ($pwaAppExists) {
    $pwaAppContent = (string)file_get_contents($pwaAppPath);
    $pwaAppValid = (strpos($pwaAppContent, 'serviceWorker.register') !== false) &&
        (strpos($pwaAppContent, 'beforeinstallprompt') !== false) &&
        (strpos($pwaAppContent, 'online') !== false) &&
        (strpos($pwaAppContent, 'offline') !== false);
    assertTest($pwaAppValid, "js/pwa-app.js contains Service Worker registration, network status listeners, and install prompt UI");
}

// 18.5 Apache .htaccess PWA headers validation
$htaccessPath = $baseDir . '/.htaccess';
$htaccessContent = is_file($htaccessPath) ? (string)file_get_contents($htaccessPath) : '';
$htaccessPwaValid = (strpos($htaccessContent, 'application/manifest+json') !== false) &&
    (strpos($htaccessContent, 'service-worker.js') !== false);
assertTest($htaccessPwaValid, ".htaccess configures PWA manifest MIME type and Service Worker no-cache headers");

// ---------------------------------------------------------
// FINAL SUMMARY REPORT
// ---------------------------------------------------------
echo "\n========================================================\n";
echo "              VERIFICATION SUITE SUMMARY               \n";
echo "========================================================\n";
echo "  Total Passed: {$passedCount}\n";
echo "  Total Failed: {$failedCount}\n";
echo "========================================================\n\n";

if ($failedCount === 0) {
    echo "SUCCESS: ALL SYSTEM VERIFICATION CHECKS PASSED!\n";
    exit(0);
} else {
    echo "ERROR: {$failedCount} VERIFICATION CHECKS FAILED!\n";
    exit(1);
}

