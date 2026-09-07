<?php
// database/backup.php - Database Backup Snapshot & Disaster Recovery Tool
// Run via CLI: php database/backup.php [optional: tenant_id]

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "This backup tool can only be run from the CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';

echo "=================================================================\n";
echo "            RMS SaaS DATABASE BACKUP & SNAPSHOT TOOL             \n";
echo "=================================================================\n";

$conn = getDBConnection();
if (!$conn) {
    echo "❌ [ERROR] Could not connect to database.\n";
    exit(1);
}

$targetTenantId = isset($argv[1]) && is_numeric($argv[1]) ? (int)$argv[1] : 0;

$backupDir = __DIR__ . '/../storage/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0750, true);
}

$timestamp = date('Ymd_His');
$filename = $targetTenantId > 0 
    ? "backup_tenant_{$targetTenantId}_{$timestamp}.json" 
    : "backup_full_saas_{$timestamp}.json";
$backupFile = $backupDir . '/' . $filename;

echo "--> Exporting database snapshot to storage/backups/{$filename}...\n";

// Fetch table names
$tablesRes = $conn->query("SHOW TABLES");
$tables = [];
if ($tablesRes) {
    while ($row = $tablesRes->fetch_array()) {
        $tables[] = $row[0];
    }
}

$backupData = [
    'metadata' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'database_name' => DB_NAME,
        'tenant_id_filter' => $targetTenantId > 0 ? $targetTenantId : 'all_tenants',
        'table_count' => count($tables)
    ],
    'tables' => []
];

$totalRecords = 0;
foreach ($tables as $table) {
    // Skip tracker tables if filtering tenant
    if ($targetTenantId > 0 && in_array($table, ['schema_migrations', 'subscription_plans'], true)) {
        continue;
    }

    $colCheck = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'restaurant_id'");
    $hasTenantCol = ($colCheck && $colCheck->num_rows > 0);

    if ($targetTenantId > 0 && $hasTenantCol) {
        $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE restaurant_id = ?");
        $stmt->bind_param("i", $targetTenantId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query("SELECT * FROM `{$table}`");
    }

    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
            $totalRecords++;
        }
    }
    $backupData['tables'][$table] = $rows;
}

$json = json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if (file_put_contents($backupFile, $json, LOCK_EX)) {
    $fileSize = round(filesize($backupFile) / 1024, 2);
    echo "  ✅ Backup created successfully! Total tables: " . count($tables) . ", Total records: {$totalRecords}, File size: {$fileSize} KB\n";
    echo "=================================================================\n";
    exit(0);
} else {
    echo "❌ [ERROR] Failed to write backup file to storage/backups/\n";
    exit(1);
}
