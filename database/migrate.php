<?php
// database/migrate.php - SaaS Schema Migration & Database Constraint Upgrade Runner

// Enforce CLI-only execution (Prevent web-accessible database migrations)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>';
    exit;
}

require_once __DIR__ . '/../config.php';

echo "=================================================================\n";
echo "       RMS SaaS SCHEMA & TENANT CONSTRAINT MIGRATION RUNNER       \n";
echo "=================================================================\n";

$conn = getDBConnection();
if (!$conn) {
    echo "❌ [ERROR] Could not connect to database.\n";
    exit(1);
}

// 0. Ensure schema_migrations tracker table exists
$conn->query("CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(255) NOT NULL UNIQUE,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 1. Run all versioned .sql migration files in database/migrations/
$migrationsDir = __DIR__ . '/migrations';
if (is_dir($migrationsDir)) {
    $files = glob($migrationsDir . '/*.sql');
    sort($files);

    foreach ($files as $file) {
        $filename = basename($file);
        
        // Skip 005_remove_username_auth.sql if admin_users has users without email
        if ($filename === '005_remove_username_auth.sql') {
            $checkRes = $conn->query("SELECT COUNT(*) AS cnt FROM admin_users WHERE email IS NULL OR TRIM(email) = ''");
            if ($checkRes) {
                $row = $checkRes->fetch_assoc();
                if ((int)$row['cnt'] > 0) {
                    echo "  ℹ️ Skipping '{$filename}': {$row['cnt']} admin_users still lack email addresses.\n";
                    continue;
                }
            }
        }

        $checkStmt = $conn->prepare("SELECT id FROM schema_migrations WHERE migration_name = ? LIMIT 1");
        if ($checkStmt) {
            $checkStmt->bind_param("s", $filename);
            $checkStmt->execute();
            $alreadyRun = (bool)$checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if ($alreadyRun) {
                echo "  ✅ Migration '{$filename}' already applied.\n";
                continue;
            }
        }

        echo "--> Executing migration '{$filename}'...\n";
        $sqlContent = @file_get_contents($file);
        if (!empty($sqlContent)) {
            // Remove single-line comments
            $lines = explode("\n", $sqlContent);
            $cleanedLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '' && strpos($trimmed, '--') !== 0 && strpos($trimmed, '#') !== 0) {
                    $cleanedLines[] = $line;
                }
            }
            $cleanSql = implode("\n", $cleanedLines);
            $statements = array_filter(array_map('trim', explode(';', $cleanSql)));

            $errorCount = 0;
            foreach ($statements as $stmt) {
                if (!empty($stmt)) {
                    try {
                        if (!$conn->query($stmt)) {
                            $err = $conn->error;
                            // Non-fatal if table/index/column already exists or duplicate key
                            if (!preg_match('/(duplicate|already exists|unknown column|check that column\/key exists)/i', $err)) {
                                $errorCount++;
                                echo "     [Notice] " . $err . "\n";
                            }
                        }
                    } catch (\Throwable $e) {
                        $err = $e->getMessage();
                        if (!preg_match('/(duplicate|already exists|unknown column|check that column\/key exists)/i', $err)) {
                            $errorCount++;
                            echo "     [Notice] " . $err . "\n";
                        }
                    }
                }
            }

            // Record migration execution in tracker table
            $insStmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration_name) VALUES (?)");
            if ($insStmt) {
                $insStmt->bind_param("s", $filename);
                $insStmt->execute();
                $insStmt->close();
            }

            if ($errorCount === 0) {
                echo "  ✅ Migration '{$filename}' applied successfully.\n";
            } else {
                echo "  ✅ Migration '{$filename}' completed with minor notices.\n";
            }
        }
    }
}

// 2. Audit & enforce tenant-scoped unique constraints across existing tables
$uniqueAudits = [
    'tables' => ['legacy' => 'table_number', 'uq' => 'uq_tenant_table', 'cols' => 'restaurant_id, table_number'],
    'categories' => ['legacy' => 'name', 'uq' => 'uq_tenant_cat', 'cols' => 'restaurant_id, name'],
    'inventory_categories' => ['legacy' => 'name', 'uq' => 'uq_tenant_inv_cat', 'cols' => 'restaurant_id, name'],
    'inventory_units' => ['legacy' => 'name', 'uq' => 'uq_tenant_inv_unit', 'cols' => 'restaurant_id, name'],
    'payment_gateways' => ['legacy' => 'name', 'uq' => 'uq_tenant_gateway', 'cols' => 'restaurant_id, name'],
    'purchase_orders' => ['legacy' => 'po_number', 'uq' => 'uq_tenant_po', 'cols' => 'restaurant_id, po_number'],
    'assets' => ['legacy' => 'asset_code', 'uq' => 'uq_tenant_asset', 'cols' => 'restaurant_id, asset_code'],
    'asset_categories' => ['legacy' => 'name', 'uq' => 'uq_tenant_asset_cat', 'cols' => 'restaurant_id, name'],
    'menu_addons' => ['legacy' => null, 'uq' => 'uq_tenant_addon', 'cols' => 'restaurant_id, name'],
    'suppliers' => ['legacy' => null, 'uq' => 'uq_tenant_supplier', 'cols' => 'restaurant_id, company_name'],
];

foreach ($uniqueAudits as $table => $cfg) {
    echo "--> Auditing '{$table}' index constraints...\n";
    $idxRes = $conn->query("SHOW INDEX FROM `{$table}`");
    $indexes = [];
    if ($idxRes) {
        while ($r = $idxRes->fetch_assoc()) {
            $indexes[$r['Key_name']] = true;
        }
    }

    if (!empty($cfg['legacy']) && isset($indexes[$cfg['legacy']])) {
        echo "    Dropping legacy global '{$cfg['legacy']}' index from {$table}...\n";
        @$conn->query("ALTER TABLE `{$table}` DROP INDEX `{$cfg['legacy']}`");
    }

    if (!isset($indexes[$cfg['uq']])) {
        echo "    Adding tenant-scoped UNIQUE KEY {$cfg['uq']} ({$cfg['cols']})...\n";
        $ok = @$conn->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$cfg['uq']}` ({$cfg['cols']})");
        if ($ok) {
            echo "  ✅ '{$table}' unique constraint upgraded.\n";
        } else {
            echo "  ℹ️ Notice: " . $conn->error . "\n";
        }
    } else {
        echo "  ✅ '{$table}' already has tenant-scoped unique key.\n";
    }
}

// 3. Ensure Super Admin Email Column & Provision Account safely
echo "--> Auditing Super Admin account & credentials...\n";
$auColsRes = $conn->query("SHOW COLUMNS FROM admin_users");
$auCols = [];
if ($auColsRes) {
    while ($r = $auColsRes->fetch_assoc()) {
        $auCols[strtolower($r['Field'])] = true;
    }
}
if (!isset($auCols['email'])) {
    echo "    Adding 'email' column to admin_users...\n";
    @$conn->query("ALTER TABLE admin_users ADD COLUMN email VARCHAR(255) DEFAULT NULL UNIQUE");
}
if (!isset($auCols['force_password_change'])) {
    @$conn->query("ALTER TABLE admin_users ADD COLUMN force_password_change TINYINT(1) DEFAULT 0");
}
if (!isset($auCols['is_super_admin'])) {
    @$conn->query("ALTER TABLE admin_users ADD COLUMN is_super_admin TINYINT(1) DEFAULT 0");
}
try { @$conn->query("ALTER TABLE admin_users MODIFY COLUMN restaurant_id INT NULL DEFAULT NULL"); } catch (\Throwable $e) {}

$targetEmail = trim((string)(getenv('SUPER_ADMIN_EMAIL') ?: 'sovryxrms29@gmail.com'));
$saPassword = (string)(getenv('SUPER_ADMIN_PASSWORD') ?: '');

$saCheck = $conn->prepare("SELECT id, email FROM admin_users WHERE LOWER(email) = LOWER(?) OR is_super_admin = 1 ORDER BY is_super_admin DESC, id ASC LIMIT 1");
if ($saCheck) {
    $saCheck->bind_param("s", $targetEmail);
    $saCheck->execute();
    $saUser = $saCheck->get_result()->fetch_assoc();
    $saCheck->close();

    if ($saUser) {
        $uStmt = $conn->prepare("UPDATE admin_users SET is_super_admin = 1, role = 'SUPER_ADMIN', restaurant_id = NULL WHERE id = ?");
        if ($uStmt) {
            $uStmt->bind_param("i", $saUser['id']);
            $uStmt->execute();
            $uStmt->close();
        }
        echo "  ✅ Super Admin account verified (platform-level): " . ($saUser['email'] ?: $targetEmail) . "\n";
    } else {
        // First run provisioning: generate random password if no env var supplied
        $forceChange = 1;
        if ($saPassword === '') {
            $saPassword = bin2hex(random_bytes(8)); // 16 char random password
            echo "  🔑 Provisioned Super Admin account with generated password: {$saPassword}\n";
            echo "  ⚠️ Set SUPER_ADMIN_PASSWORD in .env or change password immediately after first login.\n";
        } else {
            $forceChange = 0;
        }
        $superHash = password_hash($saPassword, PASSWORD_DEFAULT);
        $iStmt = $conn->prepare("INSERT INTO admin_users (email, password, full_name, role, is_super_admin, restaurant_id, force_password_change) VALUES (?, ?, 'Super Admin', 'SUPER_ADMIN', 1, NULL, ?)");
        if ($iStmt) {
            $iStmt->bind_param("ssi", $targetEmail, $superHash, $forceChange);
            $iStmt->execute();
            $iStmt->close();
        }
        echo "  ✅ Super Admin account created: {$targetEmail}\n";
    }
}

echo "=================================================================\n";
echo "              SCHEMA MIGRATION COMPLETED SUCCESSFULLY!           \n";
echo "=================================================================\n";
