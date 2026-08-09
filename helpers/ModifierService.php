<?php
// helpers/ModifierService.php - Product Modifiers & Add-ons Service for RMS SaaS
// Handles required/optional modifier groups, selection limits, add-on pricing, and recipe deductions.

class ModifierService {

    /**
     * Get modifier groups and modifiers for a specific tenant or menu item
     */
    public static function getModifierGroups(int $tenantId): array {
        $conn = getDBConnection();
        if (!$conn || $tenantId <= 0) return [];

        $stmt = $conn->prepare("
            SELECT mg.*, 
                (SELECT COUNT(*) FROM modifiers m WHERE m.group_id = mg.id AND m.status = 'active') as modifier_count
            FROM modifier_groups mg 
            WHERE mg.restaurant_id = ? AND mg.status = 'active'
            ORDER BY mg.id DESC
        ");
        $stmt->bind_param("i", $tenantId);
        $stmt->execute();
        $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($groups as &$g) {
            $mStmt = $conn->prepare("SELECT * FROM modifiers WHERE group_id = ? AND restaurant_id = ? AND status = 'active' ORDER BY id ASC");
            $mStmt->bind_param("ii", $g['id'], $tenantId);
            $mStmt->execute();
            $g['modifiers'] = $mStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $mStmt->close();
        }

        return $groups;
    }

    /**
     * Validate modifier selections against group constraints (min/max/required)
     */
    public static function validateSelections(int $groupId, array $selectedModifierIds, int $tenantId): array {
        $conn = getDBConnection();
        if (!$conn) return ['valid' => false, 'message' => 'Database connection failed'];

        $stmt = $conn->prepare("SELECT * FROM modifier_groups WHERE id = ? AND restaurant_id = ? LIMIT 1");
        $stmt->bind_param("ii", $groupId, $tenantId);
        $stmt->execute();
        $group = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$group) {
            return ['valid' => false, 'message' => 'Modifier group not found'];
        }

        $count = count($selectedModifierIds);

        if (!empty($group['is_required']) && $count === 0) {
            return ['valid' => false, 'message' => "Selection is required for '{$group['name']}'"];
        }

        if ($count < intval($group['min_selections'])) {
            return ['valid' => false, 'message' => "Please select at least {$group['min_selections']} options for '{$group['name']}'"];
        }

        if (intval($group['max_selections']) > 0 && $count > intval($group['max_selections'])) {
            return ['valid' => false, 'message' => "You can select at most {$group['max_selections']} options for '{$group['name']}'"];
        }

        return ['valid' => true];
    }

    /**
     * Attach modifiers to an order item record and return total modifier add-on price
     */
    public static function attachModifiersToOrderItem($conn, int $orderItemId, array $modifierSelections, int $tenantId): float {
        $totalAddonPrice = 0.00;

        foreach ($modifierSelections as $mod) {
            $modId = intval($mod['id'] ?? $mod);
            $qty = max(1, intval($mod['quantity'] ?? 1));

            $mStmt = $conn->prepare("SELECT id, name, price, inventory_item_id FROM modifiers WHERE id = ? AND restaurant_id = ? LIMIT 1");
            $mStmt->bind_param("ii", $modId, $tenantId);
            $mStmt->execute();
            $modifier = $mStmt->get_result()->fetch_assoc();
            $mStmt->close();

            if ($modifier) {
                $unitPrice = floatval($modifier['price']);
                $totalAddonPrice += ($unitPrice * $qty);

                $insStmt = $conn->prepare("
                    INSERT INTO order_item_modifiers (restaurant_id, order_item_id, modifier_id, modifier_name, unit_price, quantity) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insStmt->bind_param("iiisdi", $tenantId, $orderItemId, $modId, $modifier['name'], $unitPrice, $qty);
                $insStmt->execute();
                $insStmt->close();
            }
        }

        return $totalAddonPrice;
    }
}
