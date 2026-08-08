<?php
// api/menu-stream.php - Realtime POS Menu Management Stream API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();
// Release session lock so multiple browser tabs can poll concurrently.
session_write_close();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

$today = date('Y-m-d');
$cat_filter = intval($_GET['category_id'] ?? 0);
$status_filter = Security::sanitize($_GET['status'] ?? 'all');

// 1. Fetch KPI Metrics (tenant-scoped)
$kpi_stmt = $conn->prepare("
    SELECT
        COUNT(*) as total_items,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as available_items,
        SUM(CASE WHEN status = 'sold_out' THEN 1 ELSE 0 END) as sold_out_items,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_items,
        SUM(CASE WHEN stock_quantity <= min_stock_level AND status = 'active' THEN 1 ELSE 0 END) as low_stock_items,
        AVG(price) as avg_price,
        SUM(CASE WHEN image IS NULL OR image = '' THEN 1 ELSE 0 END) as missing_images
    FROM menu_items
    WHERE restaurant_id = ?
");
$kpi_stmt->bind_param("i", $tenantId);
$kpi_stmt->execute();
$kpi = $kpi_stmt->get_result()->fetch_assoc() ?: [];
$kpi_stmt->close();

$cat_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM categories WHERE restaurant_id = ?");
$cat_stmt->bind_param("i", $tenantId);
$cat_stmt->execute();
$total_categories = intval($cat_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$cat_stmt->close();

// Best Selling Item Today (tenant-scoped)
$top_stmt = $conn->prepare("
    SELECT mi.name, SUM(oi.quantity) as total_qty
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    WHERE o.restaurant_id = ? AND DATE(o.created_at) = ?
    GROUP BY mi.id
    ORDER BY total_qty DESC LIMIT 1
");
$top_stmt->bind_param("is", $tenantId, $today);
$top_stmt->execute();
$top_item_row = $top_stmt->get_result()->fetch_assoc() ?: null;
$top_stmt->close();
$best_selling_item = $top_item_row ? $top_item_row['name'] . " (" . $top_item_row['total_qty'] . " sold)" : 'N/A';

// Today's Items Sold Total (tenant-scoped)
$sold_stmt = $conn->prepare("SELECT SUM(oi.quantity) as total_qty FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.restaurant_id = ? AND DATE(o.created_at) = ?");
$sold_stmt->bind_param("is", $tenantId, $today);
$sold_stmt->execute();
$today_sold_qty = intval($sold_stmt->get_result()->fetch_assoc()['total_qty'] ?? 0);
$sold_stmt->close();

// 2. Fetch Menu Items Catalog Grid (tenant-scoped)
$sql = "
    SELECT m.*, c.name as category_name,
           (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.menu_item_id = m.id AND o.restaurant_id = " . (int)$tenantId . " AND DATE(o.created_at) = '" . $conn->real_escape_string($today) . "') as orders_today
    FROM menu_items m
    JOIN categories c ON m.category_id = c.id AND c.restaurant_id = m.restaurant_id
";

$where_clauses = ["m.restaurant_id = " . (int)$tenantId];
if ($cat_filter > 0) $where_clauses[] = "m.category_id = " . (int)$cat_filter;

if ($status_filter === 'available') $where_clauses[] = "m.status = 'active'";
else if ($status_filter === 'sold_out') $where_clauses[] = "m.status = 'sold_out'";
else if ($status_filter === 'low_stock') $where_clauses[] = "m.stock_quantity <= m.min_stock_level AND m.status = 'active'";
else if ($status_filter === 'veg') $where_clauses[] = "m.dietary_type = 'veg'";
else if ($status_filter === 'non-veg') $where_clauses[] = "m.dietary_type = 'non-veg'";
else if ($status_filter === 'popular') $where_clauses[] = "m.is_popular = 1";

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY c.name ASC, m.name ASC";

$result = $conn->query($sql);
$items = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $price = floatval($row['price']);
        $cost = floatval($row['cost_price'] ?? 0);
        $profit_margin = ($price > 0 && $cost > 0) ? round((($price - $cost) / $price) * 100, 1) : 0;
        $row['profit_margin'] = $profit_margin;
        $items[] = $row;
    }
}

Response::json([
    'success' => true,
    'timestamp' => date('c'),
    'kpi' => [
        'total_items' => intval($kpi['total_items'] ?? 0),
        'categories' => $total_categories,
        'available_items' => intval($kpi['available_items'] ?? 0),
        'sold_out_items' => intval($kpi['sold_out_items'] ?? 0),
        'low_stock_items' => intval($kpi['low_stock_items'] ?? 0),
        'best_selling' => $best_selling_item,
        'avg_price' => floatval($kpi['avg_price'] ?? 0),
        'missing_images' => intval($kpi['missing_images'] ?? 0),
        'today_sold_qty' => $today_sold_qty
    ],
    'items' => $items
]);
