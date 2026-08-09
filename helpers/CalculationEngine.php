<?php
// helpers/CalculationEngine.php - Centralized Financial Calculation Engine for RMS SaaS
// Handles Subtotal + Tax + Service Charge - Discount = Grand Total across POS, QR, Orders, Billing & Reports.

class CalculationEngine {

    /**
     * Fetch active restaurant settings for financial calculations (tenant-scoped)
     */
    public static function getSettings(int $tenantId): array {
        $conn = getDBConnection();
        if (!$conn || $tenantId <= 0) {
            return self::getDefaultSettings();
        }

        $stmt = $conn->prepare("SELECT * FROM restaurant_settings WHERE restaurant_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $tenantId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return $row;
            }
            $stmt->close();
        }

        // Insert default record if not found
        $conn->query("INSERT IGNORE INTO restaurant_settings (restaurant_id) VALUES ($tenantId)");
        return self::getDefaultSettings();
    }

    private static function getDefaultSettings(): array {
        return [
            'tax_enabled' => 1,
            'tax_name' => 'VAT',
            'tax_percentage' => 13.00,
            'service_charge_enabled' => 1,
            'service_charge_type' => 'percent',
            'service_charge_amount' => 10.00,
            'discount_max_percent' => 20.00,
            'discount_require_permission' => 1,
            'currency' => 'NPR',
            'order_prefix' => 'ORD-'
        ];
    }

    /**
     * Compute full order financial breakdown
     * Calculation Order: Subtotal + Service Charge + Tax - Discount = Grand Total
     */
    public static function calculate(float $subtotal, float $discountInput = 0.00, string $discountType = 'percent', int $tenantId = 0, array $customSettings = []): array {
        $subtotal = max(0.00, $subtotal);
        $settings = !empty($customSettings) ? $customSettings : self::getSettings($tenantId > 0 ? $tenantId : TenantContext::getTenantId());

        // 1. Service Charge Calculation
        $serviceChargeAmount = 0.00;
        if (!empty($settings['service_charge_enabled'])) {
            if (($settings['service_charge_type'] ?? 'percent') === 'percent') {
                $scRate = floatval($settings['service_charge_amount'] ?? 10.00);
                $serviceChargeAmount = round(($subtotal * $scRate) / 100.0, 2);
            } else {
                $serviceChargeAmount = round(floatval($settings['service_charge_amount'] ?? 0.00), 2);
            }
        }

        // 2. Tax Calculation (applied on subtotal + service charge per standard hospitality tax rules)
        $taxableBase = $subtotal + $serviceChargeAmount;
        $taxAmount = 0.00;
        if (!empty($settings['tax_enabled'])) {
            $taxRate = floatval($settings['tax_percentage'] ?? 13.00);
            $taxAmount = round(($taxableBase * $taxRate) / 100.0, 2);
        }

        // 3. Discount Calculation
        $discountAmount = 0.00;
        $maxDiscountPercent = floatval($settings['discount_max_percent'] ?? 20.00);

        if ($discountInput > 0) {
            if ($discountType === 'percent') {
                $effectivePercent = min($discountInput, $maxDiscountPercent);
                $discountAmount = round(($subtotal * $effectivePercent) / 100.0, 2);
            } else {
                // Fixed amount discount cannot exceed subtotal
                $discountAmount = min(round($discountInput, 2), $subtotal);
            }
        }

        // 4. Grand Total Calculation
        $grandTotal = max(0.00, round(($subtotal + $serviceChargeAmount + $taxAmount) - $discountAmount, 2));

        return [
            'subtotal' => round($subtotal, 2),
            'tax_enabled' => (bool)($settings['tax_enabled'] ?? false),
            'tax_name' => $settings['tax_name'] ?? 'VAT',
            'tax_percentage' => floatval($settings['tax_percentage'] ?? 13.00),
            'tax_amount' => $taxAmount,
            'service_charge_enabled' => (bool)($settings['service_charge_enabled'] ?? false),
            'service_charge_amount' => $serviceChargeAmount,
            'discount_amount' => $discountAmount,
            'grand_total' => $grandTotal,
            'currency' => $settings['currency'] ?? 'NPR'
        ];
    }
}
