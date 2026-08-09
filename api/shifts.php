<?php
// api/shifts.php - Multi-Tenant Work Shift Management API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

$userId = (int)($_SESSION['admin_id'] ?? 0);
$userName = $_SESSION['admin_username'] ?? 'staff';

// GET Request: Get active shift & shift history
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    RBAC::requirePermission('manage_shifts');

    $active = ShiftService::getActiveShift($conn, $userId, $tenantId);

    $sStmt = $conn->prepare("SELECT * FROM work_shifts WHERE restaurant_id = ? ORDER BY id DESC LIMIT 50");
    $sStmt->bind_param("i", $tenantId);
    $sStmt->execute();
    $history = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sStmt->close();

    Response::json([
        'success' => true,
        'active_shift' => $active,
        'history' => $history
    ]);
}

// POST Request: Open or Close Shift
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RBAC::requirePermission('manage_shifts');
    CSRF::requireValidToken();

    $action = Security::sanitize($_POST['action'] ?? '');

    if ($action === 'open') {
        $shiftName = Security::sanitize($_POST['shift_name'] ?? 'Morning Shift');
        $openingCash = max(0.00, floatval($_POST['opening_cash'] ?? 0.00));

        $res = ShiftService::openShift($conn, $userId, $userName, $shiftName, $openingCash, $tenantId);
        if ($res['success']) Response::success($res['message'], $res);
        else Response::error($res['message'], 400);
    }

    elseif ($action === 'close') {
        $shiftId = intval($_POST['shift_id'] ?? 0);
        $actualCash = max(0.00, floatval($_POST['actual_cash'] ?? 0.00));
        $notes = Security::sanitize($_POST['notes'] ?? '');

        if ($shiftId <= 0) Response::error('Invalid shift ID', 400);

        $res = ShiftService::closeShift($conn, $shiftId, $actualCash, $notes, $userName, $tenantId);
        if ($res['success']) Response::success($res['message'], $res);
        else Response::error($res['message'], 400);
    }

    else {
        Response::error('Invalid action specified', 400);
    }
}
