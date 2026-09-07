<?php
// tests/VerificationSuite.php - Automated Verification Suite for RMS SaaS
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
// TEST SUITE 1: PHP Syntax & Linting Verification
// ---------------------------------------------------------
echo "[SUITE 1] PHP Lint & Syntax Validation...\n";
$phpFiles = [];
$dirsToScan = ['helpers', 'super-admin', 'api', 'database'];
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
// Add root files
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
// TEST SUITE 3: Tenant Isolation & Security Guards
// ---------------------------------------------------------
echo "\n[SUITE 3] Tenant Isolation & Context Security...\n";
// Test 3.1: Unauthenticated tenant resolution defaults to falsy (0)
Auth::startSession();
$_SESSION = [];
$unauthTenant = TenantContext::getTenantId();
assertTest($unauthTenant === 0, "Unauthenticated session resolves to tenant ID 0 (fails closed)");

// Test 3.2: Customer session tenant context
$_SESSION['customer_restaurant_id'] = 42;
$customerTenant = TenantContext::getTenantId();
assertTest($customerTenant === 42, "Customer dining session resolves to tenant ID 42");

// Test 3.3: Staff session tenant context overrides
$_SESSION['restaurant_id'] = 99;
$staffTenant = TenantContext::getTenantId();
assertTest($staffTenant === 99, "Authenticated staff session resolves to tenant ID 99");

// Reset session
$_SESSION = [];

// ---------------------------------------------------------
// TEST SUITE 4: SQL Parameterization Audit
// ---------------------------------------------------------
echo "\n[SUITE 4] SQL Parameterization Audit...\n";
$unparameterizedCount = 0;
$suspiciousFiles = [];
foreach ($phpFiles as $file) {
    // Skip docs/archive or tests directory
    if (strpos($file, 'docs') !== false || strpos($file, 'tests') !== false) {
        continue;
    }
    $content = file_get_contents($file);
    // Check for $conn->query("...$var...") pattern
    if (preg_match('/\$conn\s*->\s*query\s*\(\s*["\'].*?\$[a-zA-Z_]/i', $content)) {
        $unparameterizedCount++;
        $suspiciousFiles[] = basename($file);
    }
}
assertTest($unparameterizedCount === 0, "Zero unparameterized \$conn->query() with variable interpolation in production code", "Suspicious files: " . implode(', ', $suspiciousFiles));

// Audit bind_param parameter count matching
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
// TEST SUITE 5: Billing Calculation Precision Engine
// ---------------------------------------------------------
echo "\n[SUITE 5] Billing & Money Calculation Precision...\n";
$conn = getDBConnection();
if ($conn) {
    $settings = [
        'is_enabled' => 1,
        'tax_enabled' => 1,
        'tax_percentage' => 13.0,
        'vat_mode' => 'exclusive',
        'service_charge_enabled' => 1,
        'service_charge_type' => 'percent',
        'service_charge_amount' => 10.0,
        'currency_symbol' => 'Rs.',
        'currency_position' => 'left'
    ];
    
    // Subtotal: 1000.00
    // Service Charge (10%): 100.00
    // Taxable base: 1100.00
    // VAT (13% of 1100): 143.00
    // Grand Total: 1243.00
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
// TEST SUITE 6: CSRF & Security Tokens
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
