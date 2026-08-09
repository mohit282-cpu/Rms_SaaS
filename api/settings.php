<?php
// api/settings.php - Multi-Tenant Restaurant Settings Controller API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// GET Request: Fetch active restaurant settings
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = CalculationEngine::getSettings($tenantId);
    Response::json(['success' => true, 'settings' => $settings]);
}

// POST Request: Update restaurant settings (Requires manage_settings permission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RBAC::requirePermission('manage_settings');
    CSRF::requireValidToken();

    // Ensure settings row exists
    $conn->query("INSERT IGNORE INTO restaurant_settings (restaurant_id) VALUES ($tenantId)");

    // Handle Logo Image Upload
    $logoUrl = null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['logo'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (in_array($file['type'], $allowedTypes, true) && $file['size'] <= 5 * 1024 * 1024) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $uploadDir = __DIR__ . '/../uploads/logos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename = 'logo_tenant_' . $tenantId . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $logoUrl = 'uploads/logos/' . $filename;
            }
        }
    }

    // Extract & sanitize input fields
    $address = Security::sanitize($_POST['address'] ?? '');
    $phone = Security::sanitize($_POST['phone'] ?? '');
    $email = Security::sanitize($_POST['email'] ?? '');
    $panVat = Security::sanitize($_POST['pan_vat_number'] ?? '');
    $currency = Security::sanitize($_POST['currency'] ?? 'NPR');
    $timezone = Security::sanitize($_POST['timezone'] ?? 'Asia/Kathmandu');

    $taxEnabled = isset($_POST['tax_enabled']) ? 1 : 0;
    $taxName = Security::sanitize($_POST['tax_name'] ?? 'VAT');
    $taxPercentage = floatval($_POST['tax_percentage'] ?? 13.00);

    $serviceChargeEnabled = isset($_POST['service_charge_enabled']) ? 1 : 0;
    $serviceChargeType = in_array($_POST['service_charge_type'] ?? '', ['percent', 'fixed'], true) ? $_POST['service_charge_type'] : 'percent';
    $serviceChargeAmount = floatval($_POST['service_charge_amount'] ?? 10.00);

    $discountMaxPercent = floatval($_POST['discount_max_percent'] ?? 20.00);
    $discountRequirePerm = isset($_POST['discount_require_permission']) ? 1 : 0;

    $receiptFooter = Security::sanitize($_POST['receipt_footer_msg'] ?? '');
    $receiptPaperSize = in_array($_POST['receipt_paper_size'] ?? '', ['58mm', '80mm'], true) ? $_POST['receipt_paper_size'] : '80mm';
    $orderPrefix = Security::sanitize($_POST['order_prefix'] ?? 'ORD-');
    $orderStartingNumber = intval($_POST['order_starting_number'] ?? 1001);

    $kdsEnabled = isset($_POST['kds_enabled']) ? 1 : 0;
    $kdsAutoRefreshSec = max(1, intval($_POST['kds_auto_refresh_sec'] ?? 2));
    $kdsPrepTimeMins = max(1, intval($_POST['kds_prep_time_mins'] ?? 15));
    $kdsDelayedThresholdMins = max(1, intval($_POST['kds_delayed_threshold_mins'] ?? 15));

    $qrOrderingEnabled = isset($_POST['qr_ordering_enabled']) ? 1 : 0;
    $qrMinOrderAmount = max(0.00, floatval($_POST['qr_min_order_amount'] ?? 0.00));
    $qrInstructions = Security::sanitize($_POST['qr_instructions'] ?? '');
    $qrOpeningTime = Security::sanitize($_POST['qr_opening_time'] ?? '08:00:00');
    $qrClosingTime = Security::sanitize($_POST['qr_closing_time'] ?? '22:00:00');

    // Also update restaurant table for name/phone/email if provided
    $restaurantName = Security::sanitize($_POST['restaurant_name'] ?? '');
    if (!empty($restaurantName)) {
        $rStmt = $conn->prepare("UPDATE restaurants SET restaurant_name = ?, phone = ?, email = ? WHERE id = ?");
        $rStmt->bind_param("sssi", $restaurantName, $phone, $email, $tenantId);
        $rStmt->execute();
        $rStmt->close();
    }

    if ($logoUrl) {
        $stmt = $conn->prepare("
            UPDATE restaurant_settings SET 
                logo_url = ?, address = ?, phone = ?, email = ?, pan_vat_number = ?, currency = ?, timezone = ?,
                tax_enabled = ?, tax_name = ?, tax_percentage = ?, service_charge_enabled = ?, service_charge_type = ?, service_charge_amount = ?,
                discount_max_percent = ?, discount_require_permission = ?, receipt_footer_msg = ?, receipt_paper_size = ?, order_prefix = ?, order_starting_number = ?,
                kds_enabled = ?, kds_auto_refresh_sec = ?, kds_prep_time_mins = ?, kds_delayed_threshold_mins = ?,
                qr_ordering_enabled = ?, qr_min_order_amount = ?, qr_instructions = ?, qr_opening_time = ?, qr_closing_time = ?
            WHERE restaurant_id = ?
        ");
        $stmt->bind_param(
            "sssssssissdsddisssiiiiiidsssi",
            $logoUrl, $address, $phone, $email, $panVat, $currency, $timezone,
            $taxEnabled, $taxName, $taxPercentage, $serviceChargeEnabled, $serviceChargeType, $serviceChargeAmount,
            $discountMaxPercent, $discountRequirePerm, $receiptFooter, $receiptPaperSize, $orderPrefix, $orderStartingNumber,
            $kdsEnabled, $kdsAutoRefreshSec, $kdsPrepTimeMins, $kdsDelayedThresholdMins,
            $qrOrderingEnabled, $qrMinOrderAmount, $qrInstructions, $qrOpeningTime, $qrClosingTime,
            $tenantId
        );
    } else {
        $stmt = $conn->prepare("
            UPDATE restaurant_settings SET 
                address = ?, phone = ?, email = ?, pan_vat_number = ?, currency = ?, timezone = ?,
                tax_enabled = ?, tax_name = ?, tax_percentage = ?, service_charge_enabled = ?, service_charge_type = ?, service_charge_amount = ?,
                discount_max_percent = ?, discount_require_permission = ?, receipt_footer_msg = ?, receipt_paper_size = ?, order_prefix = ?, order_starting_number = ?,
                kds_enabled = ?, kds_auto_refresh_sec = ?, kds_prep_time_mins = ?, kds_delayed_threshold_mins = ?,
                qr_ordering_enabled = ?, qr_min_order_amount = ?, qr_instructions = ?, qr_opening_time = ?, qr_closing_time = ?
            WHERE restaurant_id = ?
        ");
        $stmt->bind_param(
            "ssssssissdsddisssiiiiiidsssi",
            $address, $phone, $email, $panVat, $currency, $timezone,
            $taxEnabled, $taxName, $taxPercentage, $serviceChargeEnabled, $serviceChargeType, $serviceChargeAmount,
            $discountMaxPercent, $discountRequirePerm, $receiptFooter, $receiptPaperSize, $orderPrefix, $orderStartingNumber,
            $kdsEnabled, $kdsAutoRefreshSec, $kdsPrepTimeMins, $kdsDelayedThresholdMins,
            $qrOrderingEnabled, $qrMinOrderAmount, $qrInstructions, $qrOpeningTime, $qrClosingTime,
            $tenantId
        );
    }

    if ($stmt->execute()) {
        $stmt->close();
        Security::logAudit("SETTINGS_UPDATE", "Restaurant settings updated for Tenant ID {$tenantId}");
        Response::success('Restaurant settings saved successfully');
    } else {
        $err = $stmt->error;
        $stmt->close();
        Response::error('Failed to update settings: ' . $err, 500);
    }
}
