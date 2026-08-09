<?php
// api/customers.php - Multi-Tenant Customer CRM API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// GET Request: List customers or single customer metrics
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    RBAC::requirePermission('manage_customers');

    $search = Security::sanitize($_GET['search'] ?? '');
    $query = "SELECT * FROM customers WHERE restaurant_id = ?";
    $params = [$tenantId];
    $types = "i";

    if (!empty($search)) {
        $query .= " AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)";
        $sTerm = "%{$search}%";
        $params[] = $sTerm; $params[] = $sTerm; $params[] = $sTerm;
        $types .= "sss";
    }

    $query .= " ORDER BY id DESC LIMIT 200";
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Summary Metrics
    $mRes = $conn->query("
        SELECT 
            COUNT(*) as total_customers,
            COALESCE(SUM(total_spent), 0.00) as total_revenue,
            COALESCE(AVG(total_spent), 0.00) as avg_clv
        FROM customers WHERE restaurant_id = $tenantId
    ");
    $metrics = $mRes ? $mRes->fetch_assoc() : [];

    Response::json([
        'success' => true,
        'customers' => $customers,
        'metrics' => [
            'total_customers' => intval($metrics['total_customers'] ?? 0),
            'total_revenue' => floatval($metrics['total_revenue'] ?? 0.00),
            'avg_clv' => round(floatval($metrics['avg_clv'] ?? 0.00), 2)
        ]
    ]);
}

// POST Request: Create / Edit Customer Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RBAC::requirePermission('manage_customers');
    CSRF::requireValidToken();

    $action = Security::sanitize($_POST['action'] ?? 'create');

    if ($action === 'create' || $action === 'edit') {
        $name = Security::sanitize($_POST['name'] ?? '');
        $phone = Security::sanitize($_POST['phone'] ?? '');
        $email = Security::sanitize($_POST['email'] ?? '');
        $address = Security::sanitize($_POST['address'] ?? '');
        $notes = Security::sanitize($_POST['notes'] ?? '');

        if (empty($name) || empty($phone)) {
            Response::error('Customer Name and Phone Number are required', 400);
        }

        if ($action === 'create') {
            // Check unique phone per tenant
            $chk = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1");
            $chk->bind_param("is", $tenantId, $phone);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                Response::error('Customer with this phone number already exists', 400);
            }
            $chk->close();

            $stmt = $conn->prepare("
                INSERT INTO customers (restaurant_id, name, phone, email, address, notes) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssss", $tenantId, $name, $phone, $email, $address, $notes);
            if ($stmt->execute()) {
                $cid = $stmt->insert_id;
                $stmt->close();
                Security::logAudit("CUSTOMER_CREATE", "Created customer profile '{$name}' ({$phone})");
                Response::success('Customer created successfully', ['id' => $cid]);
            } else {
                $err = $stmt->error; $stmt->close();
                Response::error('Failed to create customer: ' . $err, 500);
            }
        } else {
            $cid = intval($_POST['customer_id'] ?? 0);
            TenantContext::assertOwnership($conn, 'customers', $cid);

            $stmt = $conn->prepare("
                UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, notes = ? 
                WHERE id = ? AND restaurant_id = ?
            ");
            $stmt->bind_param("sssssii", $name, $phone, $email, $address, $notes, $cid, $tenantId);
            if ($stmt->execute()) {
                $stmt->close();
                Security::logAudit("CUSTOMER_UPDATE", "Updated customer profile #{$cid}");
                Response::success('Customer updated successfully');
            } else {
                $err = $stmt->error; $stmt->close();
                Response::error('Failed to update customer: ' . $err, 500);
            }
        }
    } else {
        Response::error('Invalid action specified', 400);
    }
}
