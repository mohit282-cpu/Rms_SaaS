<?php
// api/staff.php - Multi-Tenant Staff Management & RBAC Controller API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// GET Request: List staff accounts & available RBAC roles
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    RBAC::requirePermission('manage_staff');

    $stmt = $conn->prepare("
        SELECT id, username, full_name, role, is_super_admin, force_password_change, created_at 
        FROM admin_users 
        WHERE restaurant_id = ? 
        ORDER BY id DESC
    ");
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $roles = RBAC::$defaultRolePermissions;

    Response::json([
        'success' => true,
        'staff' => $staff,
        'roles' => array_keys($roles),
        'permissions' => RBAC::$allPermissions
    ]);
}

// POST Request: Staff CRUD & Password Reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RBAC::requirePermission('manage_staff');
    CSRF::requireValidToken();

    $action = Security::sanitize($_POST['action'] ?? 'create');

    if ($action === 'create') {
        // Enforce subscription plan staff limit
        if (!SubscriptionService::canAddStaff($tenantId)) {
            $limits = SubscriptionService::getTenantPlanLimits($tenantId);
            Response::error("Staff limit reached for your plan ({$limits['max_staff']} staff maximum). Please upgrade your subscription.", 403);
        }

        $username = Security::sanitize($_POST['username'] ?? '');
        $fullName = Security::sanitize($_POST['full_name'] ?? '');
        $role = strtoupper(Security::sanitize($_POST['role'] ?? 'CASHIER'));
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($fullName) || empty($password)) {
            Response::error('Username, Full Name, and Password are required.', 400);
        }

        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters long.', 400);
        }

        // Username uniqueness check
        $chkStmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
        $chkStmt->bind_param("s", $username);
        $chkStmt->execute();
        $chkRes = $chkStmt->get_result();
        if ($chkRes && $chkRes->num_rows > 0) {
            $chkStmt->close();
            Response::error('Username already taken. Please choose a different username.', 400);
        }
        $chkStmt->close();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("
            INSERT INTO admin_users (restaurant_id, username, password, full_name, role, is_super_admin, force_password_change) 
            VALUES (?, ?, ?, ?, ?, 0, 0)
        ");
        $stmt->bind_param("issss", $tenantId, $username, $hash, $fullName, $role);

        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            $stmt->close();
            Security::logAudit("STAFF_CREATE", "Created staff account '{$username}' with role {$role} (Tenant ID: {$tenantId})");
            Response::success('Staff account created successfully', ['id' => $newId]);
        } else {
            $err = $stmt->error;
            $stmt->close();
            Response::error('Failed to create staff: ' . $err, 500);
        }
    }

    elseif ($action === 'reset_password') {
        $staffId = intval($_POST['staff_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if ($staffId <= 0 || empty($newPassword)) {
            Response::error('Staff ID and New Password are required.', 400);
        }

        if (strlen($newPassword) < 6) {
            Response::error('New password must be at least 6 characters long.', 400);
        }

        // Tenant ownership check (IDOR protection)
        TenantContext::assertOwnership($conn, 'admin_users', $staffId);

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE admin_users SET password = ?, force_password_change = 0 WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("sii", $hash, $staffId, $tenantId);

        if ($stmt->execute()) {
            $stmt->close();
            Security::logAudit("STAFF_PASSWORD_RESET", "Reset password for Staff ID {$staffId} (Tenant ID: {$tenantId})");
            Response::success('Staff password reset successfully');
        } else {
            $err = $stmt->error;
            $stmt->close();
            Response::error('Failed to reset password: ' . $err, 500);
        }
    }

    elseif ($action === 'update_role') {
        $staffId = intval($_POST['staff_id'] ?? 0);
        $role = strtoupper(Security::sanitize($_POST['role'] ?? 'CASHIER'));

        if ($staffId <= 0 || empty($role)) {
            Response::error('Staff ID and Role are required.', 400);
        }

        TenantContext::assertOwnership($conn, 'admin_users', $staffId);

        $stmt = $conn->prepare("UPDATE admin_users SET role = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("sii", $role, $staffId, $tenantId);

        if ($stmt->execute()) {
            $stmt->close();
            Security::logAudit("STAFF_ROLE_UPDATE", "Updated Staff ID {$staffId} role to {$role} (Tenant ID: {$tenantId})");
            Response::success('Staff role updated successfully');
        } else {
            $err = $stmt->error;
            $stmt->close();
            Response::error('Failed to update role: ' . $err, 500);
        }
    }

    elseif ($action === 'delete') {
        $staffId = intval($_POST['staff_id'] ?? 0);

        if ($staffId <= 0) {
            Response::error('Invalid Staff ID.', 400);
        }

        // Prevent self-deletion
        if ($staffId === (int)($_SESSION['admin_id'] ?? 0)) {
            Response::error('You cannot delete your own logged-in account.', 400);
        }

        TenantContext::assertOwnership($conn, 'admin_users', $staffId);

        $stmt = $conn->prepare("DELETE FROM admin_users WHERE id = ? AND restaurant_id = ? AND is_super_admin = 0");
        $stmt->bind_param("ii", $staffId, $tenantId);

        if ($stmt->execute()) {
            $stmt->close();
            Security::logAudit("STAFF_DELETE", "Deleted Staff ID {$staffId} (Tenant ID: {$tenantId})");
            Response::success('Staff account deleted successfully');
        } else {
            $err = $stmt->error;
            $stmt->close();
            Response::error('Failed to delete staff: ' . $err, 500);
        }
    }

    else {
        Response::error('Invalid action specified', 400);
    }
}
