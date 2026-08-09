<?php
// api/expenses.php - Multi-Tenant Operating Expense & P&L API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// GET Request: Fetch expenses & P&L summary
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    RBAC::requirePermission('manage_expenses');

    $month = Security::sanitize($_GET['month'] ?? date('Y-m'));
    $startDate = "{$month}-01";
    $endDate = date('Y-m-t', strtotime($startDate));

    $eStmt = $conn->prepare("SELECT * FROM expenses WHERE restaurant_id = ? AND expense_date BETWEEN ? AND ? ORDER BY expense_date DESC");
    $eStmt->bind_param("iss", $tenantId, $startDate, $endDate);
    $eStmt->execute();
    $expenses = $eStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $eStmt->close();

    // Calculate total expenses for month
    $expSumRes = $conn->query("SELECT COALESCE(SUM(amount), 0.00) as total_exp FROM expenses WHERE restaurant_id = $tenantId AND expense_date BETWEEN '$startDate' AND '$endDate'");
    $totalExpenses = floatval($expSumRes->fetch_assoc()['total_exp'] ?? 0.00);

    // Calculate total revenue for month
    $revSumRes = $conn->query("SELECT COALESCE(SUM(total_amount), 0.00) as total_rev FROM orders WHERE restaurant_id = $tenantId AND status = 'completed' AND DATE(created_at) BETWEEN '$startDate' AND '$endDate'");
    $totalRevenue = floatval($revSumRes->fetch_assoc()['total_rev'] ?? 0.00);

    $operatingProfit = round($totalRevenue - $totalExpenses, 2);

    Response::json([
        'success' => true,
        'expenses' => $expenses,
        'month' => $month,
        'pnl' => [
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'operating_profit' => $operatingProfit
        ]
    ]);
}

// POST Request: Create Expense
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RBAC::requirePermission('manage_expenses');
    CSRF::requireValidToken();

    $category = Security::sanitize($_POST['category_name'] ?? 'Other');
    $amount = max(0.01, floatval($_POST['amount'] ?? 0.00));
    $expDate = Security::sanitize($_POST['expense_date'] ?? date('Y-m-d'));
    $desc = Security::sanitize($_POST['description'] ?? '');
    $method = Security::sanitize($_POST['payment_method'] ?? 'cash');
    $user = $_SESSION['admin_username'] ?? 'staff';

    if ($amount <= 0) Response::error('Valid expense amount is required', 400);

    $stmt = $conn->prepare("
        INSERT INTO expenses (restaurant_id, category_name, amount, expense_date, description, payment_method, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isdssss", $tenantId, $category, $amount, $expDate, $desc, $method, $user);
    if ($stmt->execute()) {
        $eid = $stmt->insert_id;
        $stmt->close();
        Security::logAudit("EXPENSE_CREATE", "Recorded expense of NPR {$amount} under '{$category}' for {$expDate}");
        Response::success('Expense recorded successfully', ['id' => $eid]);
    } else {
        $err = $stmt->error; $stmt->close();
        Response::error('Failed to record expense: ' . $err, 500);
    }
}
