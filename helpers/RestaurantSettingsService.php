<?php
// helpers/RestaurantSettingsService.php
// Single source of truth for every tenant configuration consumed by billing,
// payments, loyalty, receipts and the settings page.
//
// - Every read is tenant-scoped and prepared-statement based (no IDOR / SQLi).
// - Rows are provisioned lazily for any tenant that does not have them yet.
// - saveSettings() validates strictly, persists to BOTH payment_settings and
//   restaurant_loyalty_settings, syncs the super-admin `restaurants` row, and
//   writes an audit trail of exactly which fields changed (old -> new).
// - Currency formatting is centralized here so receipts and the POS always
//   agree on symbol/position.

class RestaurantSettingsService {

    private static $cache = [];

    /**
     * Guarantee the columns/keys this service relies on exist (idempotent).
     * Calls config.php's provisioning guard when available; otherwise the
     * defensive SHOW COLUMNS path below runs.
     */
    private static function ensureSchema($conn): void {
        if (!$conn) return;
        if (function_exists('ensureCriticalTenantColumns')) {
            ensureCriticalTenantColumns($conn);
            return;
        }
    }

    /**
     * Fetch the tenant's billing/payment settings row (provisioned lazily).
     */
    public static function getPaymentSettings($conn, int $tenantId): array {
        $tenantId = (int)$tenantId;
        if ($tenantId <= 0 || !$conn) {
            return self::paymentDefaults($tenantId);
        }
        $cacheKey = 'pay_' . $tenantId;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        self::ensureSchema($conn);

        $defaults = self::paymentDefaults($tenantId);
        $row = null;

        $stmt = $conn->prepare("SELECT * FROM payment_settings WHERE restaurant_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $tenantId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $row = self::normalizePaymentRow($row);
            }
            $stmt->close();
        }

        if (!$row) {
            $row = self::provisionPaymentRow($conn, $tenantId);
        }

        $result = array_merge($defaults, $row);
        self::$cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Fetch the tenant's loyalty program settings row (provisioned lazily).
     */
    public static function getLoyaltySettings($conn, int $tenantId): array {
        $tenantId = (int)$tenantId;
        if ($tenantId <= 0 || !$conn) {
            return self::loyaltyDefaults($tenantId);
        }
        $cacheKey = 'loy_' . $tenantId;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        self::ensureSchema($conn);

        $defaults = self::loyaltyDefaults($tenantId);
        $row = null;

        $stmt = $conn->prepare("SELECT * FROM restaurant_loyalty_settings WHERE restaurant_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $tenantId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $row = self::normalizeLoyaltyRow($row);
            }
            $stmt->close();
        }

        if (!$row) {
            $row = self::provisionLoyaltyRow($conn, $tenantId);
        }

        $result = array_merge($defaults, $row);
        self::$cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Validate + persist the full settings form (payment + loyalty + info).
     *
     * @return array{success:bool, message:string, errors:string[], changes:array}
     */
    public static function saveSettings($conn, int $tenantId, array $input, array $files = []): array {
        $tenantId = (int)$tenantId;
        if ($tenantId <= 0 || !$conn) {
            return ['success' => false, 'message' => 'Invalid tenant context', 'errors' => ['Tenant context is required'], 'changes' => []];
        }

        self::ensureSchema($conn);

        $errors = [];
        $beforePay = self::getPaymentSettings($conn, $tenantId);
        $beforeLoy = self::getLoyaltySettings($conn, $tenantId);

        // ---- Payment / billing settings ----
        $pay = [];
        $pay['restaurant_name'] = self::text($input['restaurant_name'] ?? '', 1, 150, 'Restaurant name', $errors);
        $pay['payment_note'] = self::text($input['payment_note'] ?? '', 0, 500, 'Payment note', $errors);

        $pay['tax_enabled'] = isset($input['tax_enabled']) ? 1 : 0;
        $pay['tax_percentage'] = self::num($input['tax_percentage'] ?? 0, 0, 100, 'Tax percentage', $errors);
        $pay['vat_mode'] = ($input['vat_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';

        $pay['service_charge_enabled'] = isset($input['service_charge_enabled']) ? 1 : 0;
        $pay['service_charge_type'] = ($input['service_charge_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $pay['service_charge_amount'] = self::num($input['service_charge_amount'] ?? 0, 0, 100, 'Service charge amount', $errors);

        $pay['address'] = self::text($input['address'] ?? '', 0, 255, 'Address', $errors);
        $pay['phone'] = self::text($input['phone'] ?? '', 0, 50, 'Phone', $errors);
        $pay['email'] = self::text($input['email'] ?? '', 0, 100, 'Email', $errors);
        if ($pay['email'] !== '' && !filter_var($pay['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is not valid';
        }
        $pay['pan_vat'] = self::text($input['pan_vat'] ?? '', 0, 50, 'PAN/VAT number', $errors);
        $pay['currency'] = self::text($input['currency'] ?? 'NPR', 1, 10, 'Currency code', $errors);
        $pay['currency_symbol'] = self::text($input['currency_symbol'] ?? 'Rs.', 1, 10, 'Currency symbol', $errors);
        $pay['currency_position'] = ($input['currency_position'] ?? 'left') === 'right' ? 'right' : 'left';
        $pay['timezone'] = self::text($input['timezone'] ?? 'Asia/Kathmandu', 0, 64, 'Timezone', $errors);
        if ($pay['timezone'] !== '') {
            try {
                new DateTimeZone($pay['timezone']);
            } catch (Exception $e) {
                $errors[] = 'Timezone is not valid';
            }
        }

        // Optional logo upload
        $pay['logo'] = $beforePay['logo'] ?? '';
        if (isset($files['logo']) && is_array($files['logo'])) {
            if (($files['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $newName = Security::uploadFile($files['logo'], __DIR__ . '/../uploads');
                    if ($newName) {
                        $pay['logo'] = 'uploads/' . $newName;
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Logo upload failed: ' . $e->getMessage();
                }
            }
        }

        // ---- Loyalty settings ----
        $loy = [];
        $loy['is_enabled'] = isset($input['loyalty_enabled']) ? 1 : 0;
        $loy['earning_points'] = (int)self::num($input['earning_points'] ?? 1, 1, 100000, 'Points per earning threshold', $errors);
        $loy['earn_spend_amount'] = self::num($input['earn_spend_amount'] ?? 100, 0.01, 10000000, 'Spend threshold', $errors);
        $loy['point_value'] = self::num($input['point_value'] ?? 1, 0.01, 100000, 'Point value', $errors);
        $loy['min_redemption_points'] = (int)self::num($input['min_redemption_points'] ?? 0, 0, 10000000, 'Minimum redemption points', $errors);
        $loy['max_redemption_points'] = (int)self::num($input['max_redemption_points'] ?? 0, 0, 10000000, 'Maximum redemption points', $errors);
        $loy['max_discount_percent'] = self::num($input['max_discount_percent'] ?? 0, 0, 100, 'Maximum discount percent', $errors);
        $loy['min_bill_amount'] = self::num($input['min_bill_amount'] ?? 0, 0, 100000000, 'Minimum bill amount', $errors);
        $loy['expiration_enabled'] = isset($input['expiration_enabled']) ? 1 : 0;
        $loy['expiration_days'] = (int)self::num($input['expiration_days'] ?? 365, 1, 36500, 'Expiration days', $errors);
        $loy['earning_basis'] = self::text($input['earning_basis'] ?? 'subtotal_after_discounts', 1, 50, 'Earning basis', $errors);
        $allowedBasis = ['subtotal_after_discounts', 'subtotal_plus_service_charge', 'grand_total_before_tax'];
        if (!in_array($loy['earning_basis'], $allowedBasis, true)) {
            $errors[] = 'Earning basis is not valid';
        }

        if ($loy['is_enabled'] === 1 && $loy['point_value'] <= 0) {
            $errors[] = 'Point value must be greater than zero';
        }
        if ($loy['expiration_enabled'] === 1 && $loy['expiration_days'] < 1) {
            $errors[] = 'Expiration days must be at least 1';
        }

        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Settings could not be saved', 'errors' => $errors, 'changes' => []];
        }

        // ---- Persist payment_settings (upsert, tenant-scoped) ----
        $payStmt = $conn->prepare(
            "INSERT INTO payment_settings
                (restaurant_id, restaurant_name, payment_note, tax_enabled, tax_percentage, vat_mode,
                 service_charge_enabled, service_charge_type, service_charge_amount,
                 address, phone, email, pan_vat, currency, currency_symbol, currency_position, timezone, logo, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                restaurant_name = VALUES(restaurant_name),
                payment_note = VALUES(payment_note),
                tax_enabled = VALUES(tax_enabled),
                tax_percentage = VALUES(tax_percentage),
                vat_mode = VALUES(vat_mode),
                service_charge_enabled = VALUES(service_charge_enabled),
                service_charge_type = VALUES(service_charge_type),
                service_charge_amount = VALUES(service_charge_amount),
                address = VALUES(address),
                phone = VALUES(phone),
                email = VALUES(email),
                pan_vat = VALUES(pan_vat),
                currency = VALUES(currency),
                currency_symbol = VALUES(currency_symbol),
                currency_position = VALUES(currency_position),
                timezone = VALUES(timezone),
                logo = VALUES(logo),
                updated_at = NOW()"
        );
        if (!$payStmt) {
            return ['success' => false, 'message' => 'Database error', 'errors' => ['Failed to prepare settings update'], 'changes' => []];
        }
        $payStmt->bind_param(
            "issidsisdsssssssss",
            $tenantId,
            $pay['restaurant_name'],
            $pay['payment_note'],
            $pay['tax_enabled'],
            $pay['tax_percentage'],
            $pay['vat_mode'],
            $pay['service_charge_enabled'],
            $pay['service_charge_type'],
            $pay['service_charge_amount'],
            $pay['address'],
            $pay['phone'],
            $pay['email'],
            $pay['pan_vat'],
            $pay['currency'],
            $pay['currency_symbol'],
            $pay['currency_position'],
            $pay['timezone'],
            $pay['logo']
        );
        $payStmt->execute();
        $payStmt->close();

        // ---- Persist loyalty settings (upsert, tenant-scoped) ----
        $loyStmt = $conn->prepare(
            "INSERT INTO restaurant_loyalty_settings
                (restaurant_id, is_enabled, earning_points, earn_spend_amount, point_value,
                 min_redemption_points, max_redemption_points, max_discount_percent, min_bill_amount,
                 expiration_enabled, expiration_days, earning_basis)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                earning_points = VALUES(earning_points),
                earn_spend_amount = VALUES(earn_spend_amount),
                point_value = VALUES(point_value),
                min_redemption_points = VALUES(min_redemption_points),
                max_redemption_points = VALUES(max_redemption_points),
                max_discount_percent = VALUES(max_discount_percent),
                min_bill_amount = VALUES(min_bill_amount),
                expiration_enabled = VALUES(expiration_enabled),
                expiration_days = VALUES(expiration_days),
                earning_basis = VALUES(earning_basis),
                updated_at = NOW()"
        );
        if (!$loyStmt) {
            return ['success' => false, 'message' => 'Database error', 'errors' => ['Failed to prepare loyalty settings update'], 'changes' => []];
        }
        $loyStmt->bind_param(
            "iiiddiiddiis",
            $tenantId,
            $loy['is_enabled'],
            $loy['earning_points'],
            $loy['earn_spend_amount'],
            $loy['point_value'],
            $loy['min_redemption_points'],
            $loy['max_redemption_points'],
            $loy['max_discount_percent'],
            $loy['min_bill_amount'],
            $loy['expiration_enabled'],
            $loy['expiration_days'],
            $loy['earning_basis']
        );
        $loyStmt->execute();
        $loyStmt->close();

        // ---- Sync super-admin `restaurants` row (name/contact only when provided) ----
        $rName = ($pay['restaurant_name'] !== '') ? $pay['restaurant_name'] : null;
        $rStmt = $conn->prepare("UPDATE restaurants SET restaurant_name = COALESCE(?, restaurant_name), address = ?, phone = ?, email = ?, pan_number = ?, logo = ?, updated_at = NOW() WHERE id = ?");
        if ($rStmt) {
            $rStmt->bind_param("ssssssi", $rName, $pay['address'], $pay['phone'], $pay['email'], $pay['pan_vat'], $pay['logo'], $tenantId);
            $rStmt->execute();
            $rStmt->close();
        }

        // ---- Audit trail: exactly which fields changed (old -> new) ----
        $changes = self::diffSettings($beforePay, $pay, [
            'restaurant_name', 'payment_note', 'tax_enabled', 'tax_percentage', 'vat_mode',
            'service_charge_enabled', 'service_charge_type', 'service_charge_amount',
            'address', 'phone', 'email', 'pan_vat', 'currency', 'currency_symbol', 'currency_position', 'timezone', 'logo'
        ]);
        $loyChanges = self::diffSettings($beforeLoy, $loy, [
            'is_enabled', 'earning_points', 'earn_spend_amount', 'point_value',
            'min_redemption_points', 'max_redemption_points', 'max_discount_percent', 'min_bill_amount',
            'expiration_enabled', 'expiration_days', 'earning_basis'
        ]);
        $changes = array_merge($changes, $loyChanges);

        $summary = empty($changes) ? 'no field changes' : implode('; ', array_slice($changes, 0, 12)) . (count($changes) > 12 ? '; +' . (count($changes) - 12) . ' more' : '');
        if (class_exists('Security')) {
            Security::logAudit('SETTINGS_UPDATED', 'Settings saved for restaurant #' . $tenantId . ' | ' . $summary);
        }

        // Invalidate per-request cache so further reads in the same request are fresh
        unset(self::$cache['pay_' . $tenantId], self::$cache['loy_' . $tenantId]);

        return ['success' => true, 'message' => 'Restaurant settings saved successfully!', 'errors' => [], 'changes' => $changes];
    }

    /**
     * Tenant-aware currency formatting used by receipts and the POS.
     */
    public static function formatMoneyFor($conn, int $tenantId, float $amount): string {
        $s = self::getPaymentSettings($conn, $tenantId);
        $symbol = (string)($s['currency_symbol'] ?? 'Rs.');
        $position = (string)($s['currency_position'] ?? 'left');
        $value = number_format($amount, 2);
        return $position === 'right' ? $value . ' ' . $symbol : $symbol . ' ' . $value;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private static function paymentDefaults(int $tenantId): array {
        return [
            'restaurant_id' => $tenantId,
            'restaurant_name' => 'QR Restaurant',
            'payment_note' => 'Scan QR to pay via Esewa / Khalti',
            'tax_enabled' => 0,
            'tax_percentage' => 13.00,
            'vat_mode' => 'exclusive',
            'service_charge_enabled' => 0,
            'service_charge_type' => 'percent',
            'service_charge_amount' => 10.00,
            'address' => '',
            'phone' => '',
            'email' => '',
            'pan_vat' => '',
            'currency' => 'NPR',
            'currency_symbol' => 'Rs.',
            'currency_position' => 'left',
            'timezone' => 'Asia/Kathmandu',
            'logo' => '',
            'is_active' => 1,
        ];
    }

    private static function loyaltyDefaults(int $tenantId): array {
        return [
            'restaurant_id' => $tenantId,
            'is_enabled' => 1,
            'earning_points' => 1,
            'earn_spend_amount' => 100.00,
            'point_value' => 1.00,
            'min_redemption_points' => 10,
            'max_redemption_points' => 500,
            'max_discount_percent' => 20.00,
            'min_bill_amount' => 0.00,
            'expiration_enabled' => 0,
            'expiration_days' => 365,
            'earning_basis' => 'subtotal_after_discounts',
        ];
    }

    private static function normalizePaymentRow(array $row): array {
        return [
            'restaurant_id' => (int)($row['restaurant_id'] ?? 0),
            'restaurant_name' => (string)($row['restaurant_name'] ?? ''),
            'payment_note' => (string)($row['payment_note'] ?? ''),
            'tax_enabled' => (int)($row['tax_enabled'] ?? 0),
            'tax_percentage' => (float)($row['tax_percentage'] ?? 0),
            'vat_mode' => ($row['vat_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive',
            'service_charge_enabled' => (int)($row['service_charge_enabled'] ?? 0),
            'service_charge_type' => ($row['service_charge_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent',
            'service_charge_amount' => (float)($row['service_charge_amount'] ?? 0),
            'address' => (string)($row['address'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'pan_vat' => (string)($row['pan_vat'] ?? ''),
            'currency' => (string)($row['currency'] ?? 'NPR'),
            'currency_symbol' => (string)($row['currency_symbol'] ?? 'Rs.'),
            'currency_position' => ($row['currency_position'] ?? 'left') === 'right' ? 'right' : 'left',
            'timezone' => (string)($row['timezone'] ?? 'Asia/Kathmandu'),
            'logo' => (string)($row['logo'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 1),
        ];
    }

    private static function normalizeLoyaltyRow(array $row): array {
        return [
            'restaurant_id' => (int)($row['restaurant_id'] ?? 0),
            'is_enabled' => (int)($row['is_enabled'] ?? 0),
            'earning_points' => (int)($row['earning_points'] ?? 1),
            'earn_spend_amount' => (float)($row['earn_spend_amount'] ?? 100),
            'point_value' => (float)($row['point_value'] ?? 1),
            'min_redemption_points' => (int)($row['min_redemption_points'] ?? 0),
            'max_redemption_points' => (int)($row['max_redemption_points'] ?? 0),
            'max_discount_percent' => (float)($row['max_discount_percent'] ?? 0),
            'min_bill_amount' => (float)($row['min_bill_amount'] ?? 0),
            'expiration_enabled' => (int)($row['expiration_enabled'] ?? 0),
            'expiration_days' => (int)($row['expiration_days'] ?? 365),
            'earning_basis' => (string)($row['earning_basis'] ?? 'subtotal_after_discounts'),
        ];
    }

    private static function provisionPaymentRow($conn, int $tenantId): array {
        $name = 'QR Restaurant';
        $logo = '';
        $rStmt = $conn->prepare("SELECT restaurant_name, logo, address, phone, email, pan_number FROM restaurants WHERE id = ? LIMIT 1");
        if ($rStmt) {
            $rStmt->bind_param("i", $tenantId);
            $rStmt->execute();
            $res = $rStmt->get_result();
            if ($res && $rRow = $res->fetch_assoc()) {
                $name = $rRow['restaurant_name'] ?: $name;
                $logo = $rRow['logo'] ?? '';
                $logo = $logo && strpos($logo, 'uploads/') === 0 ? $logo : ($logo ? 'uploads/' . $logo : '');
            }
            $rStmt->close();
        }

        $stmt = $conn->prepare(
            "INSERT IGNORE INTO payment_settings
                (restaurant_id, restaurant_name, payment_note, tax_enabled, tax_percentage, vat_mode,
                 service_charge_enabled, service_charge_type, service_charge_amount, currency, currency_symbol,
                 currency_position, timezone, logo, is_active)
             VALUES (?, ?, 'Scan QR to pay via Esewa / Khalti', 0, 13.00, 'exclusive', 0, 'percent', 10.00, 'NPR', 'Rs.', 'left', 'Asia/Kathmandu', ?, 1)"
        );
        if ($stmt) {
            $stmt->bind_param("iss", $tenantId, $name, $logo);
            $stmt->execute();
            $stmt->close();
        }
        return self::normalizePaymentRow([
            'restaurant_id' => $tenantId,
            'restaurant_name' => $name,
            'logo' => $logo,
        ]);
    }

    private static function provisionLoyaltyRow($conn, int $tenantId): array {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO restaurant_loyalty_settings
                (restaurant_id, is_enabled, earning_points, earn_spend_amount, point_value,
                 min_redemption_points, max_redemption_points, max_discount_percent, min_bill_amount,
                 expiration_enabled, expiration_days, earning_basis)
             VALUES (?, 1, 1, 100.00, 1.00, 10, 500, 20.00, 0.00, 0, 365, 'subtotal_after_discounts')"
        );
        if ($stmt) {
            $stmt->bind_param("i", $tenantId);
            $stmt->execute();
            $stmt->close();
        }
        return self::normalizeLoyaltyRow([
            'restaurant_id' => $tenantId,
        ]);
    }

    private static function text($value, int $min, int $max, string $label, array &$errors): string {
        $value = trim((string)$value);
        $len = mb_strlen($value);
        if ($len < $min) {
            $errors[] = "$label is required";
        } elseif ($len > $max) {
            $errors[] = "$label cannot exceed $max characters";
        }
        return $value;
    }

    private static function num($value, float $min, float $max, string $label, array &$errors): float {
        if (!is_numeric($value)) {
            $errors[] = "$label must be a number";
            return $min;
        }
        $value = (float)$value;
        if ($value < $min || $value > $max) {
            $errors[] = "$label must be between $min and $max";
        }
        return $value;
    }

    private static function diffSettings(array $before, array $after, array $fields): array {
        $changes = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $before) || !array_key_exists($field, $after)) {
                continue;
            }
            $old = (string)$before[$field];
            $new = (string)$after[$field];
            if ($old !== $new) {
                $changes[] = $field . ': ' . $old . ' -> ' . $new;
            }
        }
        return $changes;
    }
}
