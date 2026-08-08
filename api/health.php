<?php
// api/health.php - System Health Check & Status Endpoint
require_once __DIR__ . '/../config.php';

use App\Services\DatabaseService;

// api/health.php - Public System Health Endpoint (Hardened - Fixes Phase 28)
require_once __DIR__ . '/../config.php';

$isHealthy = true;
$conn = getDBConnection();

if (!$conn) {
    $isHealthy = false;
} else {
    try {
        $res = $conn->query("SELECT 1");
        if (!$res) $isHealthy = false;
    } catch (Throwable $e) {
        $isHealthy = false;
    }
}

if ($isHealthy) {
    Response::json(['status' => 'healthy', 'timestamp' => date('c')], 200);
} else {
    Response::json(['status' => 'unhealthy', 'timestamp' => date('c')], 500);
}
