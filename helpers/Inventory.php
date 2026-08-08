<?php
// helpers/Inventory.php - Central Inventory & Asset Service
// Handles: RBAC permissions, immutable transactions, audit trail, low-stock alerts,
//          automatic recipe-based stock deduction on order completion, restocking on cancel/refund.
// All SQL uses prepared statements only.

class Inventory {

    // =============================================================
    // ROLE BASED ACCESS CONTROL
    // =============================================================
    const ROLES = ['admin', 'manager', 'store_keeper', 'kitchen', 'cashier', 'auditor'];

    // Permission map: module => roles allowed to perform WRITE actions (auditor = read only)
    private static $writePerms = [
        'inventory'        => ['admin', 'manager', 'store_keeper'],
        'categories'       => ['admin', 'manager', 'store_keeper'],
        'suppliers'        => ['admin', 'manager', 'store_keeper'],
        'purchase_orders'  => ['admin', 'manager', 'store_keeper'],
        'receiving'        => ['admin', 'manager', 'store_keeper'],
        'movements'        => ['admin', 'manager', 'store_keeper'],
        'recipes'          => ['admin', 'manager', 'store_keeper', 'kitchen'],
        'waste'            => ['admin', 'manager', 'store_keeper', 'kitchen'],
        'stock_audit'      => ['admin', 'manager', 'store_keeper'],
        'alerts'           => ['admin', 'manager', 'store_keeper'],
        'assets'           => ['admin', 'manager', 'store_keeper'],
        'maintenance'      => ['admin', 'manager'],
        'transfers'        => ['admin', 'manager'],
        'depreciation'     => ['admin', 'manager'],
        'reports'          => ['admin', 'manager', 'store_keeper'],
        'users'            => ['admin'],
    ];

    // Modules a role may even VIEW (read). Auditor sees everything read-only.
    private static $readPerms = [
        'admin'        => ['*'],
        'manager'      => ['*'],
        'store_keeper' => ['*'],
        'kitchen'      => ['inventory', 'recipes', 'waste', 'movements', 'reports'],
        'cashier'      => ['dashboard'],
        'auditor'      => ['*'],
    ];

    public static function role() {
        return $_SESSION['admin_role'] ?? 'admin';
    }

    public static function roleLabel() {
        $labels = [
            'admin' => 'Administrator', 'manager' => 'Manager', 'store_keeper' => 'Store Keeper',
            'kitchen' => 'Kitchen', 'cashier' => 'Cashier', 'auditor' => 'Read-only Auditor'
        ];
        return $labels[self::role()] ?? ucfirst(self::role());
    }

    /** Check whether current role may WRITE to a module (auditor never writes). */
    public static function canWrite($module) {
        $role = self::role();
        if ($role === 'auditor') return false;
        $allowed = self::$writePerms[$module] ?? [];
        return in_array($role, $allowed, true);
    }

    /** Check whether current role may VIEW a module. */
    public static function canRead($module) {
        $role = self::role();
        $allowed = self::$readPerms[$role] ?? [];
        return in_array('*', $allowed, true) || in_array($module, $allowed, true);
    }

    public static function requireWrite($module) {
        self::requireAuth();
        if (!self::canWrite($module)) {
            self::deny('Write access denied for module: ' . $module);
        }
    }

    public static function requireRead($module) {
        self::requireAuth();
        if (!self::canRead($module)) {
            self::deny('Read access denied for module: ' . $module);
        }
    }

    private static function requireAuth() {
        if (!Auth::isAdminLoggedIn()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized admin access required.']);
            exit;
        }
    }

    private static function deny($msg) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    // =============================================================
    // IMMUTABLE AUDIT LOGGING
    // =============================================================
    public static function audit($event, $description) {
        $conn = getDBConnection();
        if (!$conn) return;
        $uid = intval($_SESSION['admin_id'] ?? 1);
        $user = $_SESSION['admin_username'] ?? 'Admin';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, username, event_type, description, ip_address, user_agent) VALUES (?,?,?,?,?,?)");
        if ($stmt) {
            $stmt->bind_param("isssss", $uid, $user, $event, $description, $ip, $ua);
            $stmt->execute();
            $stmt->close();
        }
    }

    // =============================================================
    // IMMUTABLE INVENTORY TRANSACTIONS
    // =============================================================
    public static function recordTransaction($itemId, $type, $qty, $direction, $before, $after, $unitCost = 0, $refType = '', $refId = null, $notes = '') {
        $conn = getDBConnection();
        if (!$conn) return false;
        $refIdSql = ($refId !== null) ? intval($refId) : null;
        $creator = $conn->real_escape_string($_SESSION['admin_username'] ?? 'system');
        $refTypeSql = $conn->real_escape_string($refType);
        $notesSql = $conn->real_escape_string(mb_substr($notes, 0, 255));
        $stmt = $conn->prepare(
            "INSERT INTO inventory_transactions
             (inventory_item_id, type, quantity, direction, reference_type, reference_id, stock_before, stock_after, unit_cost, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        if (!$stmt) return false;
        $stmt->bind_param("issdsiddss", $itemId, $type, $qty, $direction, $refTypeSql, $refIdSql, $before, $after, $unitCost, $notesSql, $creator);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    // =============================================================
    // AUTOMATIC LOW STOCK / EXPIRY ALERTS
    // =============================================================
    public static function generateAlerts() {
        $conn = getDBConnection();
        if (!$conn) return 0;
        $created = 0;

        // Low stock / out of stock
        $stmt = $conn->prepare(
            "SELECT id, name, current_stock, minimum_stock FROM inventory_items
             WHERE status='active' AND current_stock <= minimum_stock"
        );
        $stmt->execute();
        $res = $stmt->get_result();
        $type = 'low_stock';
        while ($row = $res->fetch_assoc()) {
            $t = $row['current_stock'] <= 0 ? 'out_of_stock' : 'low_stock';
            $msg = ($t === 'out_of_stock')
                ? "{$row['name']} is out of stock"
                : "{$row['name']} is low on stock ({$row['current_stock']} / min {$row['minimum_stock']})";
            $created += self::insertAlert($conn, $row['id'], $t, $msg);
        }
        $stmt->close();

        // Expired / near expiry
        $stmt = $conn->prepare(
            "SELECT id, name, expiry_date FROM inventory_items
             WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date < DATE_ADD(CURDATE(), INTERVAL 8 DAY)"
        );
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $t = $row['expiry_date'] < date('Y-m-d') ? 'expired' : 'near_expiry';
            $msg = ($t === 'expired')
                ? "{$row['name']} expired on {$row['expiry_date']}"
                : "{$row['name']} expires on {$row['expiry_date']}";
            $created += self::insertAlert($conn, $row['id'], $t, $msg);
        }
        $stmt->close();

        // Overstock
        $stmt = $conn->prepare(
            "SELECT id, name, current_stock, maximum_stock FROM inventory_items
             WHERE status='active' AND maximum_stock > 0 AND current_stock > maximum_stock"
        );
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $msg = "{$row['name']} is overstocked ({$row['current_stock']} / max {$row['maximum_stock']})";
            $created += self::insertAlert($conn, $row['id'], 'overstock', $msg);
        }
        $stmt->close();

        return $created;
    }

    private static function insertAlert($conn, $itemId, $type, $msg) {
        // Avoid duplicates for the same item + type + message within the last 24h
        $check = $conn->prepare(
            "SELECT id FROM inventory_alerts
             WHERE inventory_item_id=? AND alert_type=? AND message=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1"
        );
        $check->bind_param("iss", $itemId, $type, $msg);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if ($exists) return 0;

        $stmt = $conn->prepare("INSERT INTO inventory_alerts (inventory_item_id, alert_type, message) VALUES (?,?,?)");
        $stmt->bind_param("iss", $itemId, $type, $msg);
        $stmt->execute();
        $stmt->close();
        return 1;
    }

    // =============================================================
    // RECIPE-BASED ORDER STOCK INTEGRATION (Realtime)
    // =============================================================
    /**
     * Check whether every menu item in an order has sufficient recipe ingredients.
     * Returns ['ok'=>bool, 'shortages'=>[...]].
     */
    public static function checkOrderAvailability($orderId) {
        $conn = getDBConnection();
        if (!$conn) return ['ok' => false, 'shortages' => ['Database unavailable']];

        $items = self::orderMenuItems($conn, $orderId);
        $shortages = [];
        foreach ($items as $it) {
            $recipe = self::findRecipe($conn, $it['menu_item_id']);
            if (!$recipe) continue;
            $ingredients = self::recipeIngredients($conn, $recipe['id']);
            $times = (float)$it['quantity'];
            foreach ($ingredients as $ing) {
                $need = (float)$ing['quantity'] * $times;
                $stock = self::currentStock($conn, $ing['inventory_item_id']);
                if ($stock === null) continue;
                if ($stock < $need - 0.0001) {
                    $shortages[$ing['item_name']] = ($shortages[$ing['item_name']] ?? 0) + ($need - $stock);
                }
            }
        }
        return ['ok' => empty($shortages), 'shortages' => array_map(fn($v) => round($v, 3), $shortages)];
    }

    /**
     * Deduct recipe ingredients for a completed order.
     * Returns ['success'=>bool, 'message'=>string].
     */
    public static function deductForOrder($orderId) {
        return self::processOrderIngredients($orderId, 'consumption', 'out');
    }

    /**
     * Return (restock) ingredients for a cancelled/refunded order that was previously completed.
     */
    public static function restockForOrder($orderId) {
        return self::processOrderIngredients($orderId, 'return', 'in');
    }

    private static function processOrderIngredients($orderId, $type, $direction) {
        $conn = getDBConnection();
        if (!$conn) return ['success' => false, 'message' => 'Database unavailable'];

        $items = self::orderMenuItems($conn, $orderId);
        if (empty($items)) return ['success' => true, 'message' => 'No line items to process'];

        $conn->begin_transaction();
        try {
            $plan = []; // itemId => [need, unitCost, itemName]
            foreach ($items as $it) {
                $recipe = self::findRecipe($conn, $it['menu_item_id']);
                if (!$recipe) continue;
                $ingredients = self::recipeIngredients($conn, $recipe['id']);
                $times = (float)$it['quantity'];
                foreach ($ingredients as $ing) {
                    $need = (float)$ing['quantity'] * $times;
                    if ($need <= 0) continue;
                    $plan[$ing['inventory_item_id']] = [
                        'need' => ($plan[$ing['inventory_item_id']]['need'] ?? 0) + $need,
                        'cost' => (float)$ing['average_cost'],
                        'name' => $ing['item_name'],
                    ];
                }
            }

            if ($direction === 'out') {
                // Strict availability enforcement (prevents selling without ingredients)
                $shortages = [];
                foreach ($plan as $iid => $p) {
                    $stock = self::currentStock($conn, $iid, true);
                    if ($stock === null) continue;
                    if ($stock < $p['need'] - 0.0001) {
                        $shortages[$p['name']] = round($p['need'] - $stock, 3);
                    }
                }
                if (!empty($shortages)) {
                    $conn->rollback();
                    $list = [];
                    foreach ($shortages as $n => $q) $list[] = $n . ' (short ' . $q . ')';
                    return ['success' => false, 'message' => 'Insufficient ingredients: ' . implode(', ', $list)];
                }
            }

            foreach ($plan as $iid => $p) {
                $before = self::currentStock($conn, $iid, true);
                if ($before === null) continue;
                $after = $direction === 'in' ? $before + $p['need'] : max(0, $before - $p['need']);
                self::updateStock($conn, $iid, $after);
                self::recordTransaction($iid, $type, $p['need'], $direction, $before, $after, $p['cost'], 'order', $orderId, "Order #{$orderId} " . ($direction === 'in' ? 'refund return' : 'kitchen consumption'));
            }

            $conn->commit();
            self::generateAlerts();
            return ['success' => true, 'message' => 'Inventory ' . ($direction === 'in' ? 'restocked' : 'updated') . ' for order #' . $orderId];
        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private static function orderMenuItems($conn, $orderId) {
        $stmt = $conn->prepare("SELECT menu_item_id, quantity FROM order_items WHERE order_id=?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private static function findRecipe($conn, $menuItemId) {
        $stmt = $conn->prepare("SELECT id FROM recipes WHERE menu_item_id=? AND status='active' ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("i", $menuItemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function recipeIngredients($conn, $recipeId) {
        $stmt = $conn->prepare(
            "SELECT ri.inventory_item_id, ri.quantity, COALESCE(i.average_cost,0) as average_cost, i.name as item_name
             FROM recipe_items ri JOIN inventory_items i ON ri.inventory_item_id=i.id
             WHERE ri.recipe_id=? AND i.status='active'"
        );
        $stmt->bind_param("i", $recipeId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private static function currentStock($conn, $itemId, $lock = false) {
        $sql = "SELECT current_stock FROM inventory_items WHERE id=? LIMIT 1";
        if ($lock) $sql .= " FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (float)$row['current_stock'] : null;
    }

    private static function updateStock($conn, $itemId, $newQty) {
        $stmt = $conn->prepare("UPDATE inventory_items SET current_stock=? WHERE id=?");
        $stmt->bind_param("di", $newQty, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    // =============================================================
    // HELPERS USED BY PAGES / APIS
    // =============================================================
    public static function ensureItemQR($conn, $itemId) {
        $stmt = $conn->prepare("SELECT qr_token FROM inventory_items WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && !empty($row['qr_token'])) return $row['qr_token'];
        $token = bin2hex(random_bytes(16));
        $stmt = $conn->prepare("UPDATE inventory_items SET qr_token=? WHERE id=?");
        $stmt->bind_param("si", $token, $itemId);
        $stmt->execute();
        $stmt->close();
        return $token;
    }

    public static function ensureAssetQR($conn, $assetId) {
        $stmt = $conn->prepare("SELECT qr_token FROM assets WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $assetId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && !empty($row['qr_token'])) return $row['qr_token'];
        $token = bin2hex(random_bytes(16));
        $stmt = $conn->prepare("UPDATE assets SET qr_token=? WHERE id=?");
        $stmt->bind_param("si", $token, $assetId);
        $stmt->execute();
        $stmt->close();
        return $token;
    }
}
