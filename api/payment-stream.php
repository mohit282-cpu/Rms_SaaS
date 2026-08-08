<?php
// api/payment-stream.php - Realtime Nepal FinTech Payment Gateway Stream API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();
// Release session lock so multiple browser tabs can poll concurrently.
session_write_close();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

$today = date('Y-m-d');

// 1. Fetch Payment Metrics KPI (tenant-scoped)
$kpi_stmt = $conn->prepare("
    SELECT
        COUNT(*) as total_txns,
        SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_rev,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_cnt,
        SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END) as refund_total,
        AVG(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as avg_amount
    FROM payment_transactions
    WHERE restaurant_id = ? AND DATE(created_at) = ?
");
$kpi_stmt->bind_param("is", $tenantId, $today);
$kpi_stmt->execute();
$kpi = $kpi_stmt->get_result()->fetch_assoc() ?: [];
$kpi_stmt->close();

$total_txns = intval($kpi['total_txns'] ?? 0);
$failed_txns = intval($kpi['failed_cnt'] ?? 0);
$success_rate = ($total_txns > 0) ? round((($total_txns - $failed_txns) / $total_txns) * 100, 1) . '%' : '100%';

// Most Used Gateway Today (tenant-scoped)
$most_stmt = $conn->prepare("
    SELECT gateway_name, COUNT(*) as cnt
    FROM payment_transactions
    WHERE restaurant_id = ? AND DATE(created_at) = ? AND status = 'paid'
    GROUP BY gateway_name
    ORDER BY cnt DESC LIMIT 1
");
$most_stmt->bind_param("is", $tenantId, $today);
$most_stmt->execute();
$most_used_row = $most_stmt->get_result()->fetch_assoc() ?: null;
$most_stmt->close();
$most_used_gw = $most_used_row ? strtoupper($most_used_row['gateway_name']) : 'eSewa';

// Revenue Breakdown by Gateway (tenant-scoped)
$rev_stmt = $conn->prepare("
    SELECT gateway_name, SUM(amount) as rev
    FROM payment_transactions
    WHERE restaurant_id = ? AND DATE(created_at) = ? AND status = 'paid'
    GROUP BY gateway_name
");
$rev_stmt->bind_param("is", $tenantId, $today);
$rev_stmt->execute();
$rev_by_gw_res = $rev_stmt->get_result();
$revenue_by_gateway = ['esewa' => 0.0, 'khalti' => 0.0, 'fonepay' => 0.0, 'connectips' => 0.0, 'imepay' => 0.0];
if ($rev_by_gw_res) {
    while ($r = $rev_by_gw_res->fetch_assoc()) {
        $gw_key = strtolower($r['gateway_name']);
        if (isset($revenue_by_gateway[$gw_key])) {
            $revenue_by_gateway[$gw_key] = floatval($r['rev']);
        }
    }
}
$rev_stmt->close();

// 2. Fetch Gateway Configuration (NEVER expose secret keys)
$gw_stmt = $conn->prepare("SELECT id, name, merchant_code, public_key, environment, status, updated_at FROM payment_gateways WHERE restaurant_id = ? ORDER BY name ASC");
$gw_stmt->bind_param("i", $tenantId);
$gw_stmt->execute();
$gateways_res = $gw_stmt->get_result();
$gateways = [];
if ($gateways_res) {
    while ($gw = $gateways_res->fetch_assoc()) {
        $gw['secret_key_masked'] = 'Not Set'; // secret_key is never loaded from the DB here
        $gateways[$gw['name']] = $gw;
    }
}
$gw_stmt->close();

// 3. Fetch Recent Transactions Log (tenant-scoped)
$txns_stmt = $conn->prepare("
    SELECT pt.id, pt.transaction_id, pt.gateway_name, pt.amount, pt.status, pt.reference_id, pt.created_at,
           o.table_number, o.customer_name
    FROM payment_transactions pt
    LEFT JOIN orders o ON pt.order_id = o.id AND o.restaurant_id = ?
    WHERE pt.restaurant_id = ?
    ORDER BY pt.id DESC LIMIT 20
");
$txns_stmt->bind_param("ii", $tenantId, $tenantId);
$txns_stmt->execute();
$txns_log_res = $txns_stmt->get_result();
$transactions = [];
if ($txns_log_res) {
    while ($t = $txns_log_res->fetch_assoc()) {
        $transactions[] = $t;
    }
}
$txns_stmt->close();

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
