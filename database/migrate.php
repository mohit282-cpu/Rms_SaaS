<?php
// database/migrate.php - SaaS Schema Migration & Database Constraint Upgrade Runner

// Enforce CLI-only execution (Prevent web-accessible database migrations)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>';
    exit;
}

require_once __DIR__ . '/../config.php';

echo "=================================================================\n";
echo "       RMS SaaS SCHEMA & TENANT CONSTRAINT MIGRATION RUNNER       \n";
echo "=================================================================\n";

$conn = getDBConnection();
if (!$conn) {
    echo "❌ [ERROR] Could not connect to database.\n";
    exit(1);
}

// 1. Upgrade `tables` uniqueness constraint to (restaurant_id, table_number)
echo "--> Auditing 'tables' index constraints...\n";
$indexes = [];
$res = $conn->query("SHOW INDEX FROM tables");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $indexes[$row['Key_name']] = true;
    }
}

if (isset($indexes['table_number'])) {
    echo "    Dropping legacy global 'table_number' unique index...\n";
    @$conn->query("ALTER TABLE tables DROP INDEX table_number");
}

if (!isset($indexes['uq_tenant_table'])) {
    echo "    Adding tenant-scoped UNIQUE KEY (restaurant_id, table_number)...\n";
    $ok = @$conn->query("ALTER TABLE tables ADD UNIQUE KEY uq_tenant_table (restaurant_id, table_number)");
    if ($ok) {
        echo "  ✅ 'tables' unique constraint upgraded to (restaurant_id, table_number).\n";
    } else {
        echo "  ℹ️ Notice: " . $conn->error . "\n";
    }
} else {
    echo "  ✅ 'tables' already has tenant-scoped unique key.\n";
}

// 2. Upgrade `categories` uniqueness constraint to (restaurant_id, name)
echo "--> Auditing 'categories' index constraints...\n";
$cat_indexes = [];
$cres = $conn->query("SHOW INDEX FROM categories");
if ($cres) {
    while ($crow = $cres->fetch_assoc()) {
        $cat_indexes[$crow['Key_name']] = true;
    }
}

if (isset($cat_indexes['name'])) {
    echo "    Dropping legacy global 'name' unique index...\n";
    @$conn->query("ALTER TABLE categories DROP INDEX name");
}

if (!isset($cat_indexes['uq_tenant_cat'])) {
    echo "    Adding tenant-scoped UNIQUE KEY (restaurant_id, name)...\n";
    $ok = @$conn->query("ALTER TABLE categories ADD UNIQUE KEY uq_tenant_cat (restaurant_id, name)");
    if ($ok) {
        echo "  ✅ 'categories' unique constraint upgraded to (restaurant_id, name).\n";
    } else {
        echo "  ℹ️ Notice: " . $conn->error . "\n";
    }
} else {
    echo "  ✅ 'categories' already has tenant-scoped unique key.\n";
}

// 3. Upgrade `inventory_categories` uniqueness constraint to (restaurant_id, name)
echo "--> Auditing 'inventory_categories' index constraints...\n";
$inv_cat_indexes = [];
$icres = $conn->query("SHOW INDEX FROM inventory_categories");
if ($icres) {
    while ($icrow = $icres->fetch_assoc()) {
        $inv_cat_indexes[$icrow['Key_name']] = true;
    }
}

if (isset($inv_cat_indexes['name'])) {
    echo "    Dropping legacy global 'name' unique index...\n";
    @$conn->query("ALTER TABLE inventory_categories DROP INDEX name");
}

if (!isset($inv_cat_indexes['uq_tenant_inv_cat'])) {
    echo "    Adding tenant-scoped UNIQUE KEY (restaurant_id, name)...\n";
    $ok = @$conn->query("ALTER TABLE inventory_categories ADD UNIQUE KEY uq_tenant_inv_cat (restaurant_id, name)");
    if ($ok) {
        echo "  ✅ 'inventory_categories' unique constraint upgraded to (restaurant_id, name).\n";
    } else {
        echo "  ℹ️ Notice: " . $conn->error . "\n";
    }
} else {
    echo "  ✅ 'inventory_categories' already has tenant-scoped unique key.\n";
}

// 4. Upgrade `inventory_units` uniqueness constraint to (restaurant_id, name)
echo "--> Auditing 'inventory_units' index constraints...\n";
$iu_indexes = [];
$iures = $conn->query("SHOW INDEX FROM inventory_units");
if ($iures) {
    while ($iur = $iures->fetch_assoc()) {
        $iu_indexes[$iur['Key_name']] = true;
    }
}

if (isset($iu_indexes['name'])) {
    echo "    Dropping legacy global 'name' unique index...\n";
    @$conn->query("ALTER TABLE inventory_units DROP INDEX name");
}

if (!isset($iu_indexes['uq_tenant_inv_unit'])) {
    echo "    Adding tenant-scoped UNIQUE KEY (restaurant_id, name)...\n";
    $ok = @$conn->query("ALTER TABLE inventory_units ADD UNIQUE KEY uq_tenant_inv_unit (restaurant_id, name)");
    if ($ok) {
        echo "  ✅ 'inventory_units' unique constraint upgraded to (restaurant_id, name).\n";
    } else {
        echo "  ℹ️ Notice: " . $conn->error . "\n";
    }
} else {
    echo "  ✅ 'inventory_units' already has tenant-scoped unique key.\n";
}

// 5. Upgrade `payment_gateways` uniqueness constraint to (restaurant_id, name)
echo "--> Auditing 'payment_gateways' index constraints...\n";
$pg_indexes = [];
$pgres = $conn->query("SHOW INDEX FROM payment_gateways");
if ($pgres) {
    while ($pgr = $pgres->fetch_assoc()) {
        $pg_indexes[$pgr['Key_name']] = true;
    }
}

if (isset($pg_indexes['name'])) {
    echo "    Dropping legacy global 'name' unique index...\n";
    @$conn->query("ALTER TABLE payment_gateways DROP INDEX name");
}

if (!isset($pg_indexes['uq_tenant_gateway'])) {
    echo "    Adding tenant-scoped UNIQUE KEY (restaurant_id, name)...\n";
    $ok = @$conn->query("ALTER TABLE payment_gateways ADD UNIQUE KEY uq_tenant_gateway (restaurant_id, name)");
    if ($ok) {
        echo "  ✅ 'payment_gateways' unique constraint upgraded to (restaurant_id, name).\n";
    } else {
        echo "  ℹ️ Notice: " . $conn->error . "\n";
    }
} else {
    echo "  ✅ 'payment_gateways' already has tenant-scoped unique key.\n";
}

// 6. Upgrade `purchase_orders` uniqueness constraint to (restaurant_id, po_number)
echo "--> Auditing 'purchase_orders' index constraints...\n";
$po_indexes = [];
$pores = $conn->query("SHOW INDEX FROM purchase_orders");
if ($pores) {
    while ($por = $pores->fetch_assoc()) {
        $po_indexes[$por['Key_name']] = true;
    }
}

if (isset($po_indexes['po_number'])) {
    echo "    Dropping legacy global 'po_number' unique index...\n";
    @$conn->query("ALTER TABLE purchase_orders DROP INDEX po_number");
}

if (!isset($po_indexes['uq_tenant_po'])) {
    echo "    Adding tenant-scoped UNIQUE KEY (restaurant_id, po_number)...\n";
    $ok = @$conn->query("ALTER TABLE purchase_orders ADD UNIQUE KEY uq_tenant_po (restaurant_id, po_number)");
    if ($ok) {
        echo "  ✅ 'purchase_orders' unique constraint upgraded to (restaurant_id, po_number).\n";
    } else {
        echo "  ℹ️ Notice: " . $conn->error . "\n";
    }
} else {
    echo "  ✅ 'purchase_orders' already has tenant-scoped unique key.\n";
}

// 7. Upgrade `assets` uniqueness constraint to (restaurant_id, asset_code)
echo "--> Auditing 'assets' index constraints...\n";
$as_indexes = [];
$asres = $conn->query("SHOW INDEX FROM assets");
if ($asres) {
    while ($asr = $asres->fetch_assoc()) {
        $as_indexes[$asr['Key_name']] = true;
    }
}

if (isset($as_indexes['asset_code'])) {
    echo "    Dropping legacy global 'asset_code' unique index...\n";
    @$conn->query("ALTER TABLE assets DROP INDEX asset_code");
}

if (!isset($as_indexes['uq_tenant_asset'])) {
    echo "    Adding tenant-scoped UNIQUE KEY (restaurant_id, asset_code)...\n";
    $ok = @$conn->query("ALTER TABLE assets ADD UNIQUE KEY uq_tenant_asset (restaurant_id, asset_code)");
    if ($ok) {
        echo "  ✅ 'assets' unique constraint upgraded to (restaurant_id, asset_code).\n";
    } else {
        echo "  ℹ️ Notice: " . $conn->error . "\n";
    }
} else {
    echo "  ✅ 'assets' already has tenant-scoped unique key.\n";
}

// 8. Upgrade `asset_categories` uniqueness constraint to (restaurant_id, name)
echo "--> Auditing 'asset_categories' index constraints...\n";
$ac_indexes = [];
$acres = $conn->query("SHOW INDEX FROM asset_categories");
if ($acres) {
    while ($acr = $acres->fetch_assoc()) {
        $ac_indexes[$acr['Key_name']] = true;
    }
}

if (isset($ac_indexes['name'])) {
    echo "    Dropping legacy global 'name' unique index...\n";
    @$conn->query("ALTER TABLE asset_categories DROP INDEX name");
}

if (!isset($ac_indexes['uq_tenant_asset_cat'])) {
    echo "    Adding tenant-scoped UNIQUE KEY (restaurant_id, name)...\n";
    $ok = @$conn->query("ALTER TABLE asset_categories ADD UNIQUE KEY uq_tenant_asset_cat (restaurant_id, name)");
    if ($ok) {
        echo "  ✅ 'asset_categories' unique constraint upgraded to (restaurant_id, name).\n";
    } else {
        echo "  ℹ️ Notice: " . $conn->error . "\n";
    }
} else {
    echo "  ✅ 'asset_categories' already has tenant-scoped unique key.\n";
}

// 9. Add `menu_addons` tenant-scoped unique constraint (restaurant_id, name)
echo "--> Auditing 'menu_addons' index constraints...\n";
$ma_indexes = [];
$mares = $conn->query("SHOW INDEX FROM menu_addons");
if ($mares) {
    while ($mar = $mares->fetch_assoc()) {
        $ma_indexes[$mar['Key_name']] = true;
    }
}

if (!isset($ma_indexes['uq_tenant_addon'])) {
    echo "    Adding tenant-scoped UNIQUE KEY (restaurant_id, name)...\n";
    $ok = @$conn->query("ALTER TABLE menu_addons ADD UNIQUE KEY uq_tenant_addon (restaurant_id, name)");
    if ($ok) {
        echo "  ✅ 'menu_addons' unique constraint added (restaurant_id, name).\n";
    } else {
        echo "  ℹ️ Notice: " . $conn->error . "\n";
    }
} else {
    echo "  ✅ 'menu_addons' already has tenant-scoped unique key.\n";
}

// 10. Add `suppliers` tenant-scoped unique constraint (restaurant_id, company_name)
echo "--> Auditing 'suppliers' index constraints...\n";
$sp_indexes = [];
$spres = $conn->query("SHOW INDEX FROM suppliers");
if ($spres) {
    while ($spr = $spres->fetch_assoc()) {
        $sp_indexes[$spr['Key_name']] = true;
    }
}

if (!isset($sp_indexes['uq_tenant_supplier'])) {
    echo "    Adding tenant-scoped UNIQUE KEY (restaurant_id, company_name)...\n";
    $ok = @$conn->query("ALTER TABLE suppliers ADD UNIQUE KEY uq_tenant_supplier (restaurant_id, company_name)");
    if ($ok) {
        echo "  ✅ 'suppliers' unique constraint added (restaurant_id, company_name).\n";
    } else {
        echo "  ℹ️ Notice: " . $conn->error . "\n";
    }
} else {
    echo "  ✅ 'suppliers' already has tenant-scoped unique key.\n";
}

// 11. Ensure Super Admin email column and account
echo "--> Auditing Super Admin account & email column...\n";
$auColsRes = $conn->query("SHOW COLUMNS FROM admin_users");
$auCols = [];
if ($auColsRes) {
    while ($r = $auColsRes->fetch_assoc()) {
        $auCols[] = strtolower($r['Field']);
    }
}
if (!in_array('email', $auCols)) {
    echo "    Adding 'email' column to admin_users...\n";
    @$conn->query("ALTER TABLE admin_users ADD COLUMN email VARCHAR(255) DEFAULT NULL");
}

$targetEmail = 'sovryxrms29@gmail.com';
$superHash = '$2y$10$tDXqmC4kMXNBTfRrrgvjT.9oTaEQKbn2LAPq841OKfXYtP8J3Qdzm';
$saCheck = $conn->query("SELECT id FROM admin_users WHERE LOWER(email) = '$targetEmail' OR is_super_admin = 1 ORDER BY is_super_admin DESC, id ASC LIMIT 1");
if ($saCheck && $saCheck->num_rows > 0) {
    $saUser = $saCheck->fetch_assoc();
    // Super Admin must be platform-level (restaurant_id = NULL). Do NOT update existing password!
    $stmt = $conn->prepare("UPDATE admin_users SET is_super_admin = 1, role = 'SUPER_ADMIN', restaurant_id = NULL WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $saUser['id']);
        $stmt->execute();
        $stmt->close();
    }
    echo "  ✅ Super Admin account verified (platform-level): $targetEmail\n";
} else {
    $stmt = $conn->prepare("INSERT INTO admin_users (username, email, password, full_name, role, is_super_admin, restaurant_id, force_password_change) VALUES ('superadmin', ?, ?, 'Super Admin', 'SUPER_ADMIN', 1, NULL, 0)");
    if ($stmt) {
        $stmt->bind_param("ss", $targetEmail, $superHash);
        $stmt->execute();
        $stmt->close();
    }
    echo "  ✅ Super Admin account created: $targetEmail\n";
}

echo "--> Backfilling missing email addresses for legacy restaurant admin accounts...\n";
$emptyUsersRes = $conn->query("SELECT u.id, u.username, u.restaurant_id, r.email as rest_email FROM admin_users u LEFT JOIN restaurants r ON u.restaurant_id = r.id WHERE u.email IS NULL OR TRIM(u.email) = ''");
if ($emptyUsersRes && $emptyUsersRes->num_rows > 0) {
    while ($uRow = $emptyUsersRes->fetch_assoc()) {
        $uId = (int)$uRow['id'];
        $rEmail = trim($uRow['rest_email'] ?? '');
        if ($uId === 1 && empty($rEmail)) {
            $rEmail = 'admin@qrcafe.com';
        }
        if (empty($rEmail) || !filter_var($rEmail, FILTER_VALIDATE_EMAIL)) {
            $rEmail = strtolower($uRow['username']) . '@restaurant' . $uRow['restaurant_id'] . '.com';
        }
        $cCheck = $conn->query("SELECT id FROM admin_users WHERE LOWER(email) = '" . $conn->real_escape_string(strtolower($rEmail)) . "' AND id != $uId");
        if ($cCheck && $cCheck->num_rows > 0) {
            $rEmail = strtolower($uRow['username']) . '_' . $uId . '@restaurant' . $uRow['restaurant_id'] . '.com';
        }
        $conn->query("UPDATE admin_users SET email = '" . $conn->real_escape_string(strtolower($rEmail)) . "' WHERE id = $uId");
        echo "    Migrated User #$uId ('{$uRow['username']}') -> Email: $rEmail\n";
    }
}
echo "  ✅ Restaurant admin accounts migrated to Email Authentication.\n";

echo "=================================================================\n";
echo "              SCHEMA MIGRATION COMPLETED SUCCESSFULLY!           \n";
echo "=================================================================\n";
