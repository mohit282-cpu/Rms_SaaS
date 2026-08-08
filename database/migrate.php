<?php
// database/migrate.php - SaaS Schema Migration & Database Constraint Upgrade Runner
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

echo "=================================================================\n";
echo "              SCHEMA MIGRATION COMPLETED SUCCESSFULLY!           \n";
echo "=================================================================\n";
