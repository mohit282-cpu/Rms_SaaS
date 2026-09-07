<?php
// database/restore.php - Database Restoration & Disaster Recovery Tool
// Run via CLI: php database/restore.php [backup_file_path] [--force]

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "This restore tool can only be run from the CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';

echo "=================================================================\n";
echo "            RMS SaaS DATABASE RESTORE ENGINE                    \n";
echo "=================================================================\n";

if (empty($argv[1])) {
    echo "Usage: php database/restore.php <backup_file_json_path> [--force]\n";
    echo "Example: php database/restore.php storage/backups/backup_full_saas_20260907_104456.json --force\n";
    exit(1);
}

$backupPath = $argv[1];
if (!file_exists($backupPath)) {
    // Try relative to workspace storage/backups
    $altPath = __DIR__ . '/../' . $backupPath;
    if (file_exists($altPath)) {
        $backupPath = $altPath;
    } else {
        echo "❌ [ERROR] Backup file not found at: {$backupPath}\n";
        exit(1);
    }
}

$isForce = in_array('--force', $argv, true);
if (!$isForce) {
    echo "⚠️ WARNING: Restoring a database snapshot will overwrite matching table records!\n";
    echo "To proceed, run with --force flag.\n";
    exit(1);
}

$content = file_get_contents($backupPath);
$data = json_decode($content, true);

if (!is_array($data) || !isset($data['tables']) || !is_array($data['tables'])) {
    echo "❌ [ERROR] Invalid or corrupt JSON backup format.\n";
    exit(1);
}

$conn = getDBConnection();
if (!$conn) {
    echo "❌ [ERROR] Could not connect to database.\n";
    exit(1);
}

$conn->query("SET FOREIGN_KEY_CHECKS = 0");

$tablesRestored = 0;
$recordsInserted = 0;

foreach ($data['tables'] as $table => $rows) {
    if (!is_array($rows) || empty($rows)) {
        continue;
    }

    // Verify table exists in destination database
    $checkTbl = $conn->query("SHOW TABLES LIKE " . $conn->real_escape_string("'$table'"));
    if (!$checkTbl || $checkTbl->num_rows === 0) {
        echo "  ⚠️ Skipping unknown table: {$table}\n";
        continue;
    }

    // Truncate table before restoring records
    $conn->query("TRUNCATE TABLE `{$table}`");
    
    $firstRow = $rows[0];
    $cols = array_keys($firstRow);
    $escapedCols = array_map(function($c) { return "`{$c}`"; }, $cols);
    $colList = implode(', ', $escapedCols);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));

    $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        echo "  ❌ Prepare error for table {$table}: " . $conn->error . "\n";
        continue;
    }

    foreach ($rows as $row) {
        $types = '';
        $values = [];
        foreach ($cols as $c) {
            $val = $row[$c];
            if (is_null($val)) {
                $types .= 's';
                $values[] = null;
            } elseif (is_int($val)) {
                $types .= 'i';
                $values[] = $val;
            } elseif (is_float($val)) {
                $types .= 'd';
                $values[] = $val;
            } else {
                $types .= 's';
                $values[] = (string)$val;
            }
        }

        $stmt->bind_param($types, ...$values);
        if ($stmt->execute()) {
            $recordsInserted++;
        }
    }
    $stmt->close();
    $tablesRestored++;
}

$conn->query("SET FOREIGN_KEY_CHECKS = 1");

echo "  ✅ Restore completed successfully! Tables restored: {$tablesRestored}, Records inserted: {$recordsInserted}\n";
echo "=================================================================\n";
exit(0);
