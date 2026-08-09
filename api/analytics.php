<?php
// api/analytics.php - Multi-Tenant Executive Analytics & Business Intelligence API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    RBAC::requirePermission('view_analytics');

    $days = max(7, min(90, intval($_GET['days'] ?? 30)));
    $startDate = date('Y-m-d', strtotime("-{$days} days"));

    // 1. Daily Revenue Trend
    $revStmt = $conn->prepare("
        SELECT DATE(created_at) as date_val, COUNT(*) as order_count, COALESCE(SUM(total_amount), 0.00) as revenue 
        FROM orders 
        WHERE restaurant_id = ? AND status = 'completed' AND DATE(created_at) >= ?
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) ASC
    ");
    $revStmt->bind_param("is", $tenantId, $startDate);
    $revStmt->execute();
    $dailyRevenue = $revStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $revStmt->close();

    // 2. Top 10 Selling Items
    $topStmt = $conn->prepare("
        SELECT mi.name, SUM(oi.quantity) as total_qty, SUM(oi.quantity * oi.price) as total_sales 
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN menu_items mi ON oi.menu_item_id = mi.id
        WHERE o.restaurant_id = ? AND o.status = 'completed' AND DATE(o.created_at) >= ?
        GROUP BY oi.menu_item_id
        ORDER BY total_qty DESC LIMIT 10
    ");
    $topStmt->bind_param("is", $tenantId, $startDate);
    $topStmt->execute();
    $topItems = $topStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $topStmt->close();

    // 3. Category Sales Breakdown
    $catStmt = $conn->prepare("
        SELECT c.name as category_name, SUM(oi.quantity * oi.price) as total_sales 
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN menu_items mi ON oi.menu_item_id = mi.id
        JOIN categories c ON mi.category_id = c.id
        WHERE o.restaurant_id = ? AND o.status = 'completed' AND DATE(o.created_at) >= ?
        GROUP BY c.id
        ORDER BY total_sales DESC
    ");
    $catStmt->bind_param("is", $tenantId, $startDate);
    $catStmt->execute();
    $categories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $catStmt->close();

    // 4. Executive KPI Totals
    $kpiStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(total_amount), 0.00) as total_gross_sales,
            COALESCE(AVG(total_amount), 0.00) as aov
        FROM orders 
        WHERE restaurant_id = ? AND status = 'completed' AND DATE(created_at) >= ?
    ");
    $kpiStmt->bind_param("is", $tenantId, $startDate);
    $kpiStmt->execute();
    $kpi = $kpiStmt->get_result()->fetch_assoc();
    $kpiStmt->close();

    Response::json([
        'success' => true,
        'days' => $days,
        'kpi' => [
            'total_orders' => intval($kpi['total_orders'] ?? 0),
            'total_sales' => floatval($kpi['total_gross_sales'] ?? 0.00),
            'aov' => round(floatval($kpi['aov'] ?? 0.00), 2)
        ],
        'daily_revenue' => $dailyRevenue,
        'top_items' => $topItems,
        'categories' => $categories
    ]);
}
