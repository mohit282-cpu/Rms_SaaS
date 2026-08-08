<?php
// api/call-waiter.php - Waiter Assistance Request Endpoint (Secured)
require_once __DIR__ . '/../config.php';

$conn = getDBConnection();
if ($conn === null) {
    Response::error('Database connection failed', 500);
}

$tenantId = (int)TenantContext::getTenantId();

// 1. Handle Serve / Resolve Action (Staff / Admin Authentication Required - Fixes RMS-007)
if ((isset($_REQUEST['action']) && ($_REQUEST['action'] === 'serve' || $_REQUEST['action'] === 'resolve')) && (isset($_REQUEST['id']) || isset($_REQUEST['call_id']))) {
    $staffTenant = (int)AuthorizationService::requireStaffApi();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::error('POST request method required for state changes.', 405);
    }
    CSRF::requireValidToken();
    $id = intval($_REQUEST['id'] ?? $_REQUEST['call_id']);
    $stmt = $conn->prepare("UPDATE waiter_calls SET status = 'served' WHERE id = ? AND restaurant_id = ?");
    $stmt->bind_param("ii", $id, $staffTenant);

    if ($stmt->execute()) {
        $stmt->close();
        Response::success('Waiter call marked as served');
    } else {
        $stmt->close();
        Response::error('Error updating call status', 500);
    }
}

// 2. Handle POST Request from Customer to Call Waiter
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RateLimiter::enforce('call_waiter_' . $tenantId . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 3, 120);

    // Table identity comes strictly from validated session (Fixes RMS-006)
    $table_number = '';
    if (isset($_SESSION['customer_table_id']) && !empty($_SESSION['customer_table_id'])) {
        $table_number = strval($_SESSION['customer_table_id']);
    }

    if (empty($table_number)) {
        Response::error('Session expired or invalid table number.', 400);
    }
    if ($tenantId <= 0) {
        Response::error('Forbidden. No tenant context.', 403);
    }

    // Check if pending call already exists within last 2 minutes (tenant-scoped)
    $check_stmt = $conn->prepare("SELECT id FROM waiter_calls WHERE restaurant_id = ? AND table_number = ? AND status = 'pending' AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1");
    $check_stmt->bind_param("is", $tenantId, $table_number);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result && $check_result->num_rows > 0) {
        $check_stmt->close();
        Response::error('Waiter already called for Table ' . Security::escape($table_number) . '. Staff notified!', 429);
    }
    $check_stmt->close();

    $stmt = $conn->prepare("INSERT INTO waiter_calls (restaurant_id, table_number) VALUES (?, ?)");
    $stmt->bind_param("is", $tenantId, $table_number);

    if ($stmt->execute()) {
        $stmt->close();
        Response::success('🔔 Waiter call sent for Table ' . Security::escape($table_number) . '! Staff on the way.');
    } else {
        $stmt->close();
        Response::error('Error sending waiter call', 500);
    }
} else {
    // GET pending calls: Requires Kitchen / Admin session (tenant-scoped)
    $staffTenant = (int)AuthorizationService::requireStaffApi();

    $stmt = $conn->prepare("SELECT * FROM waiter_calls WHERE restaurant_id = ? AND status = 'pending' ORDER BY created_at DESC");
    $stmt->bind_param("i", $staffTenant);
    $stmt->execute();
    $result = $stmt->get_result();
    $calls = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $calls[] = $row;
        }
    }
    $stmt->close();
    Response::json(['success' => true, 'calls' => $calls]);
}
