<?php
// api/reservations.php - Table Reservation Management API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// GET Request: Fetch reservations
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    RBAC::requirePermission('manage_reservations');

    $date = Security::sanitize($_GET['date'] ?? date('Y-m-d'));
    $stmt = $conn->prepare("SELECT * FROM reservations WHERE restaurant_id = ? AND reservation_date = ? ORDER BY reservation_time ASC");
    $stmt->bind_param("is", $tenantId, $date);
    $stmt->execute();
    $reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    Response::json(['success' => true, 'reservations' => $reservations, 'date' => $date]);
}

// POST Request: Create / Update Reservation Status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RBAC::requirePermission('manage_reservations');
    CSRF::requireValidToken();

    $action = Security::sanitize($_POST['action'] ?? 'create');

    if ($action === 'create') {
        $name = Security::sanitize($_POST['customer_name'] ?? '');
        $phone = Security::sanitize($_POST['phone'] ?? '');
        $resDate = Security::sanitize($_POST['reservation_date'] ?? date('Y-m-d'));
        $resTime = Security::sanitize($_POST['reservation_time'] ?? '18:00:00');
        $guests = max(1, intval($_POST['guest_count'] ?? 2));
        $tblNum = Security::sanitize($_POST['table_number'] ?? '');
        $notes = Security::sanitize($_POST['notes'] ?? '');

        if (empty($name) || empty($phone)) Response::error('Customer Name and Phone are required', 400);

        // Check Table Conflict if table specified
        if (!empty($tblNum)) {
            $cStmt = $conn->prepare("
                SELECT id FROM reservations 
                WHERE restaurant_id = ? AND table_number = ? AND reservation_date = ? 
                AND status IN ('pending','confirmed') LIMIT 1
            ");
            $cStmt->bind_param("iss", $tenantId, $tblNum, $resDate);
            $cStmt->execute();
            if ($cStmt->get_result()->num_rows > 0) {
                $cStmt->close();
                Response::error("Table {$tblNum} is already reserved for {$resDate}", 400);
            }
            $cStmt->close();
        }

        $stmt = $conn->prepare("
            INSERT INTO reservations (restaurant_id, customer_name, phone, reservation_date, reservation_time, guest_count, table_number, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssiis", $tenantId, $name, $phone, $resDate, $resTime, $guests, $tblNum, $notes);
        if ($stmt->execute()) {
            $rid = $stmt->insert_id;
            $stmt->close();
            Security::logAudit("RESERVATION_CREATE", "Created reservation for {$name} on {$resDate} at {$resTime}");
            Response::success('Reservation created successfully', ['id' => $rid]);
        } else {
            $err = $stmt->error; $stmt->close();
            Response::error('Failed to create reservation: ' . $err, 500);
        }
    }

    elseif ($action === 'update_status') {
        $resId = intval($_POST['reservation_id'] ?? 0);
        $status = Security::sanitize($_POST['status'] ?? '');
        $allowed = ['pending','confirmed','arrived','no_show','cancelled','completed'];

        if ($resId <= 0 || !in_array($status, $allowed, true)) Response::error('Invalid reservation ID or status', 400);

        TenantContext::assertOwnership($conn, 'reservations', $resId);

        $stmt = $conn->prepare("UPDATE reservations SET status = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("sii", $status, $resId, $tenantId);
        if ($stmt->execute()) {
            $stmt->close();
            Security::logAudit("RESERVATION_STATUS_UPDATE", "Updated Reservation #{$resId} status to {$status}");
            Response::success('Reservation status updated');
        } else {
            $err = $stmt->error; $stmt->close();
            Response::error('Failed to update reservation: ' . $err, 500);
        }
    }

    else {
        Response::error('Invalid action specified', 400);
    }
}
