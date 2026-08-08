<?php
// api/payment-stream.php - Realtime Nepal FinTech Payment Gateway Stream API
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

// 1. Fetch Payment Metrics KPI
$txn_res = $conn->query("
    SELECT 
        COUNT(*) as total_txns,
        SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_rev,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_cnt,
        SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END) as refund_total,
        AVG(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as avg_amount
    FROM payment_transactions
    WHERE DATE(created_at) = '$today'
");
$kpi = $txn_res ? $txn_res->fetch_assoc() : [];

$total_txns = intval($kpi['total_txns'] ?? 0);
$failed_txns = intval($kpi['failed_cnt'] ?? 0);
$success_rate = ($total_txns > 0) ? round((($total_txns - $failed_txns) / $total_txns) * 100, 1) . '%' : '100%';

// Most Used Gateway Today
$most_used_res = $conn->query("
    SELECT gateway_name, COUNT(*) as cnt 
    FROM payment_transactions 
    WHERE DATE(created_at) = '$today' AND status = 'paid' 
    GROUP BY gateway_name 
    ORDER BY cnt DESC LIMIT 1
");
$most_used_row = $most_used_res ? $most_used_res->fetch_assoc() : null;
$most_used_gw = $most_used_row ? strtoupper($most_used_row['gateway_name']) : 'eSewa';

// Revenue Breakdown by Gateway
$rev_by_gw_res = $conn->query("
    SELECT gateway_name, SUM(amount) as rev 
    FROM payment_transactions 
    WHERE DATE(created_at) = '$today' AND status = 'paid' 
    GROUP BY gateway_name
");
$revenue_by_gateway = ['esewa' => 0.0, 'khalti' => 0.0, 'fonepay' => 0.0, 'connectips' => 0.0, 'imepay' => 0.0];
if ($rev_by_gw_res) {
    while ($r = $rev_by_gw_res->fetch_assoc()) {
        $gw_key = strtolower($r['gateway_name']);
        if (isset($revenue_by_gateway[$gw_key])) {
            $revenue_by_gateway[$gw_key] = floatval($r['rev']);
        }
    }
}

// 2. Fetch All 5 Gateways Configuration
$gateways_res = $conn->query("SELECT * FROM payment_gateways ORDER BY name ASC");
$gateways = [];
if ($gateways_res) {
    while ($gw = $gateways_res->fetch_assoc()) {
        $gw['secret_key_masked'] = !empty($gw['secret_key']) ? substr($gw['secret_key'], 0, 4) . '****************' : 'Not Set';
        $gateways[$gw['name']] = $gw;
    }
}

// 3. Fetch Recent Transactions Log
$txns_log_res = $conn->query("
    SELECT pt.*, o.table_number, o.customer_name 
    FROM payment_transactions pt
    LEFT JOIN orders o ON pt.order_id = o.id
    ORDER BY pt.id DESC LIMIT 20
");
$transactions = [];
if ($txns_log_res) {
    while ($t = $txns_log_res->fetch_assoc()) {
        $transactions[] = $t;
    }
}

Response::json([
    'success' => true,
    'timestamp' => date('c'),
    'kpi' => [
        'today_revenue' => floatval($kpi['total_rev'] ?? 0),
        'today_transactions' => $total_txns,
        'pending_payments' => intval($kpi['pending_cnt'] ?? 0),
        'failed_payments' => $failed_txns,
        'refunds_total' => floatval($kpi['refund_total'] ?? 0),
        'avg_transaction' => floatval($kpi['avg_amount'] ?? 0),
        'most_used_gateway' => $most_used_gw,
        'success_rate' => $success_rate
    ],
    'revenue_by_gateway' => $revenue_by_gateway,
    'gateways' => $gateways,
    'transactions' => $transactions
]);
