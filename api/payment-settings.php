<?php
// api/payment-settings.php - Payment QR Settings API (tenant-scoped)
header('Content-Type: application/json');
require_once '../config.php';

$conn = getDBConnection();

if ($conn === null) {
    echo json_encode(['success' => false, 'message' => 'Database not connected']);
    exit;
}

// Get payment settings (tenant-scoped)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tenantId = (int)TenantContext::getTenantId();
    if ($tenantId <= 0) {
        echo json_encode(['success' => false, 'message' => 'No tenant context']);
        exit;
    }
    $stmt = $conn->prepare("SELECT restaurant_name, payment_note, qr_code_image, is_active FROM payment_settings WHERE restaurant_id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        echo json_encode(['success' => true, 'settings' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No payment settings found']);
    }
}

// Update payment settings (admin only, tenant-scoped - Fixes Phase 14 & Phase 15)
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenantId = (int)AuthorizationService::requireStaffApi();
    CSRF::requireValidToken();

    $restaurant_name = Security::sanitize($_POST['restaurant_name'] ?? 'QR Restaurant');
    $payment_note = Security::sanitize($_POST['payment_note'] ?? 'Scan QR to pay');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Handle QR code image upload using secure file upload helper (Fixes Phase 15 & RMS-014)
    $qr_code_image = '';
    if (isset($_FILES['qr_code_image']) && $_FILES['qr_code_image']['error'] === UPLOAD_ERR_OK) {
        try {
            $new_filename = Security::uploadFile($_FILES['qr_code_image'], __DIR__ . '/../images/payment');
            if ($new_filename) {
                $qr_code_image = 'payment/' . $new_filename;
            }
        } catch (Exception $e) {
            Response::error('File upload failed: ' . $e->getMessage(), 400);
        }
    }

    if (!empty($qr_code_image)) {
        $stmt = $conn->prepare("UPDATE payment_settings SET restaurant_name = ?, payment_note = ?, qr_code_image = ?, is_active = ? WHERE restaurant_id = ?");
        $stmt->bind_param("sssii", $restaurant_name, $payment_note, $qr_code_image, $is_active, $tenantId);
    } else {
        $stmt = $conn->prepare("UPDATE payment_settings SET restaurant_name = ?, payment_note = ?, is_active = ? WHERE restaurant_id = ?");
        $stmt->bind_param("ssii", $restaurant_name, $payment_note, $is_active, $tenantId);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Payment settings updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating settings']);
    }

    $stmt->close();
}

$conn->close();
