<?php
// api/categories-stream.php - Realtime POS Category Management Stream API
require_once __DIR__ . '/../config.php';

if (!Auth::isAdminLoggedIn() && !Auth::isKitchenLoggedIn()) {
    Response::error('Unauthorized access. Staff authentication required.', 401);
}
// Release session lock so multiple browser tabs can poll concurrently.
session_write_close();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

$today = date('Y-m-d');

// 1. Fetch KPI Metrics
$cat_kpi_res = $conn->query("
    SELECT 
        COUNT(*) as total_categories,
        SUM(CASE WHEN parent_id IS NOT NULL THEN 1 ELSE 0 END) as subcategories,
        SUM(CASE WHEN status = 'active' OR status IS NULL THEN 1 ELSE 0 END) as active_categories,
        SUM(CASE WHEN status = 'hidden' THEN 1 ELSE 0 END) as hidden_categories
    FROM categories
");
$kpi = $cat_kpi_res ? $cat_kpi_res->fetch_assoc() : [];

$items_cnt_res = $conn->query("SELECT COUNT(*) as total_items FROM menu_items");
$total_items = $items_cnt_res ? intval($items_cnt_res->fetch_assoc()['total_items']) : 0;

// Empty Categories Count
$empty_cat_res = $conn->query("
    SELECT COUNT(*) as cnt FROM categories c 
    LEFT JOIN menu_items m ON c.id = m.category_id 
    WHERE m.id IS NULL
");
$empty_categories = $empty_cat_res ? intval($empty_cat_res->fetch_assoc()['cnt']) : 0;

// Highest Revenue Category Today
$top_rev_res = $conn->query("
    SELECT c.name, SUM(oi.quantity * oi.price) as rev 
    FROM order_items oi 
    JOIN orders o ON oi.order_id = o.id 
    JOIN menu_items mi ON oi.menu_item_id = mi.id 
    JOIN categories c ON mi.category_id = c.id 
    WHERE DATE(o.created_at) = '$today' 
    GROUP BY c.id 
    ORDER BY rev DESC LIMIT 1
");
$top_rev_row = $top_rev_res ? $top_rev_res->fetch_assoc() : null;
$highest_rev_cat = $top_rev_row ? $top_rev_row['name'] . " (Rs. " . number_format($top_rev_row['rev'], 2) . ")" : 'N/A';

// 2. Fetch Detailed Categories List
$categories_res = $conn->query("
    SELECT c.*,
           p.name as parent_name,
           (SELECT COUNT(*) FROM menu_items WHERE category_id = c.id) as item_count,
           (SELECT COALESCE(AVG(price), 0) FROM menu_items WHERE category_id = c.id) as avg_price,
           (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE mi.category_id = c.id AND DATE(o.created_at) = '$today') as orders_today,
           (SELECT COALESCE(SUM(oi.quantity * oi.price), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE mi.category_id = c.id AND DATE(o.created_at) = '$today') as revenue_today
    FROM categories c
    LEFT JOIN categories p ON c.parent_id = p.id
    ORDER BY c.display_order ASC, c.name ASC
");

$categories_list = [];
if ($categories_res) {
    while ($cat = $categories_res->fetch_assoc()) {
        $cat['items'] = [];
        $cat_id = intval($cat['id']);
        
        // Fetch Menu Items assigned to this Category
        $items_res = $conn->query("SELECT id, name, price, status, image FROM menu_items WHERE category_id = $cat_id ORDER BY name ASC LIMIT 10");
        if ($items_res) {
            while ($itm = $items_res->fetch_assoc()) {
                $cat['items'][] = $itm;
            }
        }
        $categories_list[] = $cat;
    }
}

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
