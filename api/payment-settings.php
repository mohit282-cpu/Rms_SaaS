<?php
// Payment Settings API
header('Content-Type: application/json');
require_once '../config.php';

$conn = getDBConnection();

if ($conn === null) {
    echo json_encode(['success' => false, 'message' => 'Database not connected']);
    exit;
}

// Check if payment_settings table exists
$result = $conn->query("SHOW TABLES LIKE 'payment_settings'");
if ($result->num_rows == 0) {
    $createTable = "CREATE TABLE IF NOT EXISTS payment_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_name VARCHAR(200) DEFAULT 'QR Restaurant',
        payment_note VARCHAR(500),
        qr_code_image VARCHAR(255),
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $conn->query($createTable);
    
    // Insert default
    $conn->query("INSERT INTO payment_settings (restaurant_name, payment_note) VALUES ('QR Restaurant', 'Scan QR to pay')");
}

// Get payment settings
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $conn->query("SELECT * FROM payment_settings WHERE is_active = 1 LIMIT 1");
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'settings' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No payment settings found']);
    }
}

// Update payment settings (admin only - Fixes Phase 14 & Phase 15)
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireAdmin();
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
        $stmt = $conn->prepare("UPDATE payment_settings SET restaurant_name = ?, payment_note = ?, qr_code_image = ?, is_active = ? WHERE id = 1");
        $stmt->bind_param("sssi", $restaurant_name, $payment_note, $qr_code_image, $is_active);
    } else {
        $stmt = $conn->prepare("UPDATE payment_settings SET restaurant_name = ?, payment_note = ?, is_active = ? WHERE id = 1");
        $stmt->bind_param("ssi", $restaurant_name, $payment_note, $is_active);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Payment settings updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating settings']);
    }
    
    $stmt->close();
}

$conn->close();
?>
