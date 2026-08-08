<?php
// api/categories-stream.php - Realtime POS Category Management Stream API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();
// Release session lock so multiple browser tabs can poll concurrently.
session_write_close();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

$today = date('Y-m-d');

// 1. Fetch KPI Metrics (tenant-scoped)
$cat_kpi_stmt = $conn->prepare("
    SELECT
        COUNT(*) as total_categories,
        SUM(CASE WHEN parent_id IS NOT NULL THEN 1 ELSE 0 END) as subcategories,
        SUM(CASE WHEN status = 'active' OR status IS NULL THEN 1 ELSE 0 END) as active_categories,
        SUM(CASE WHEN status = 'hidden' THEN 1 ELSE 0 END) as hidden_categories
    FROM categories
    WHERE restaurant_id = ?
");
$cat_kpi_stmt->bind_param("i", $tenantId);
$cat_kpi_stmt->execute();
$kpi = $cat_kpi_stmt->get_result()->fetch_assoc() ?: [];
$cat_kpi_stmt->close();

$items_stmt = $conn->prepare("SELECT COUNT(*) as total_items FROM menu_items WHERE restaurant_id = ?");
$items_stmt->bind_param("i", $tenantId);
$items_stmt->execute();
$total_items = intval($items_stmt->get_result()->fetch_assoc()['total_items'] ?? 0);
$items_stmt->close();

// Empty Categories Count (tenant-scoped)
$empty_stmt = $conn->prepare("
    SELECT COUNT(*) as cnt FROM categories c
    LEFT JOIN menu_items m ON c.id = m.category_id AND m.restaurant_id = c.restaurant_id
    WHERE c.restaurant_id = ? AND m.id IS NULL
");
$empty_stmt->bind_param("i", $tenantId);
$empty_stmt->execute();
$empty_categories = intval($empty_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$empty_stmt->close();

// Highest Revenue Category Today (tenant-scoped)
$top_stmt = $conn->prepare("
    SELECT c.name, SUM(oi.quantity * oi.price) as rev
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    JOIN categories c ON mi.category_id = c.id AND c.restaurant_id = mi.restaurant_id
    WHERE o.restaurant_id = ? AND DATE(o.created_at) = ?
    GROUP BY c.id
    ORDER BY rev DESC LIMIT 1
");
$top_stmt->bind_param("is", $tenantId, $today);
$top_stmt->execute();
$top_rev_row = $top_stmt->get_result()->fetch_assoc() ?: null;
$top_stmt->close();
$highest_rev_cat = $top_rev_row ? $top_rev_row['name'] . " (Rs. " . number_format($top_rev_row['rev'], 2) . ")" : 'N/A';

// 2. Fetch Detailed Categories List (tenant-scoped)
$cat_stmt = $conn->prepare("
    SELECT c.*,
           p.name as parent_name,
           (SELECT COUNT(*) FROM menu_items WHERE category_id = c.id AND restaurant_id = c.restaurant_id) as item_count,
           (SELECT COALESCE(AVG(price), 0) FROM menu_items WHERE category_id = c.id AND restaurant_id = c.restaurant_id) as avg_price,
           (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE mi.category_id = c.id AND o.restaurant_id = c.restaurant_id AND DATE(o.created_at) = ?) as orders_today,
           (SELECT COALESCE(SUM(oi.quantity * oi.price), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE mi.category_id = c.id AND o.restaurant_id = c.restaurant_id AND DATE(o.created_at) = ?) as revenue_today
    FROM categories c
    LEFT JOIN categories p ON c.parent_id = p.id AND p.restaurant_id = c.restaurant_id
    WHERE c.restaurant_id = ?
    ORDER BY c.display_order ASC, c.name ASC
");
$cat_stmt->bind_param("ssi", $today, $today, $tenantId);
$cat_stmt->execute();
$categories_res = $cat_stmt->get_result();

$categories_list = [];
if ($categories_res) {
    while ($cat = $categories_res->fetch_assoc()) {
        $cat['items'] = [];
        $cat_id = intval($cat['id']);

        // Fetch Menu Items assigned to this Category (tenant-scoped)
        $items_stmt = $conn->prepare("SELECT id, name, price, status, image FROM menu_items WHERE category_id = ? AND restaurant_id = ? ORDER BY name ASC LIMIT 10");
        $items_stmt->bind_param("ii", $cat_id, $tenantId);
        $items_stmt->execute();
        $items_res = $items_stmt->get_result();
        if ($items_res) {
            while ($itm = $items_res->fetch_assoc()) {
                $cat['items'][] = $itm;
            }
        }
        $items_stmt->close();
        $categories_list[] = $cat;
    }
}
$cat_stmt->close();

Response::json([
    'success' => true,
    'timestamp' => date('c'),
    'kpi' => [
        'total_categories' => intval($kpi['total_categories'] ?? 0),
        'subcategories' => intval($kpi['subcategories'] ?? 0),
        'total_items' => $total_items,
        'active_categories' => intval($kpi['active_categories'] ?? 0),
        'hidden_categories' => intval($kpi['hidden_categories'] ?? 0),
        'highest_revenue_category' => $highest_rev_cat,
        'empty_categories' => $empty_categories
    ],
    'categories' => $categories_list
]);
