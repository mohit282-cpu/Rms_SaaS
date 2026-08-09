<?php
// receipt.php - Printable Receipt for Settled Orders
require_once 'config.php';

$orderId = intval($_GET['order_id'] ?? 0);
if (!$orderId) {
    die('Invalid order ID');
}

$conn = getDBConnection();
if (!$conn) {
    die('Database connection error');
}

$tenantId = (int)TenantContext::getTenantId();
if ($tenantId <= 0) {
    die('No tenant context');
}

// Fetch order with items
$stmt = $conn->prepare("
    SELECT o.*, ds.session_token 
    FROM orders o
    LEFT JOIN dining_sessions ds ON o.dining_session_id = ds.id
    WHERE o.id = ? AND o.restaurant_id = ?
");
$stmt->bind_param("ii", $orderId, $tenantId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    die('Order not found');
}

// Fetch order items
$itemsStmt = $conn->prepare("
    SELECT oi.*, mi.name as item_name 
    FROM order_items oi
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    WHERE oi.order_id = ? AND oi.restaurant_id = ?
");
$itemsStmt->bind_param("ii", $orderId, $tenantId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

// Fetch payment info
$payStmt = $conn->prepare("
    SELECT * FROM payment_transactions 
    WHERE order_id = ? AND restaurant_id = ? AND status = 'paid'
    ORDER BY created_at DESC LIMIT 1
");
$payStmt->bind_param("ii", $orderId, $tenantId);
$payStmt->execute();
$payment = $payStmt->get_result()->fetch_assoc();
$payStmt->close();

// Fetch restaurant settings
$settingsStmt = $conn->prepare("
    SELECT restaurant_name, tax_enabled, tax_percentage, service_charge_enabled, service_charge_type, service_charge_amount
    FROM payment_settings WHERE restaurant_id = ? LIMIT 1
");
$settingsStmt->bind_param("i", $tenantId);
$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();

// Calculate breakdown
$subtotal = 0;
foreach ($items as $item) {
    $subtotal += $item['quantity'] * $item['price'];
}

$serviceCharge = 0;
if (!empty($settings['service_charge_enabled'])) {
    if ($settings['service_charge_type'] === 'percent') {
        $serviceCharge = round(($subtotal * $settings['service_charge_amount']) / 100, 2);
    } else {
        $serviceCharge = $settings['service_charge_amount'];
    }
}

$tax = 0;
if (!empty($settings['tax_enabled'])) {
    $taxableBase = $subtotal + $serviceCharge;
    $tax = round(($taxableBase * $settings['tax_percentage']) / 100, 2);
}

$grandTotal = $order['total_amount'];
$paymentMethod = $payment['gateway_name'] ?? $order['payment_method'] ?? 'cash';
$paymentMethodLabel = [
    'cash' => 'Cash',
    'card' => 'Card',
    'digital_qr' => 'Digital QR',
    'esewa' => 'eSewa',
    'khalti' => 'Khalti',
    'fonepay' => 'Fonepay',
    'connectips' => 'ConnectIPS',
    'imepay' => 'IME Pay'
][$paymentMethod] ?? ucfirst($paymentMethod);

$cashChange = 0;
if ($paymentMethod === 'cash' && !empty($payment['reference_id']) && strpos($payment['reference_id'], 'CASH-') === 0) {
    // Extract cash received from reference or use amount
    $cashChange = 0; // Would need to store cash_received separately
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - Order #<?= $orderId ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; margin: 0; }
            .receipt-container { box-shadow: none; border: none; }
        }
        body { font-family: 'Courier New', monospace; background: #f5f5f5; padding: 20px; }
        .receipt-container { max-width: 320px; margin: 0 auto; background: white; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .text-center { text-align: center; }
        .font-bold { font-weight: bold; }
        .border-t { border-top: 1px dashed #999; padding-top: 10px; margin-top: 10px; }
        .border-b { border-bottom: 1px dashed #999; padding-bottom: 10px; margin-bottom: 10px; }
        .flex { display: flex; }
        .justify-between { justify-content: space-between; }
        .text-sm { font-size: 12px; }
        .text-xs { font-size: 10px; }
        .text-lg { font-size: 14px; }
        .mb-2 { margin-bottom: 8px; }
        .mt-2 { margin-top: 8px; }
        .gap-2 { gap: 8px; }
        .items-center { align-items: center; }
    </style>
</head>
<body>
    <div class="no-print text-center mb-4">
        <button onclick="window.print()" class="bg-amber-500 text-white px-4 py-2 rounded hover:bg-amber-600">🖨️ Print Receipt</button>
        <a href="../admin/tables.php" class="ml-2 bg-zinc-500 text-white px-4 py-2 rounded hover:bg-zinc-600">← Back to Tables</a>
    </div>

    <div class="receipt-container">
        <!-- Header -->
        <div class="text-center border-b mb-4">
            <div class="font-bold text-lg"><?= htmlspecialchars($settings['restaurant_name'] ?? 'QR Restaurant') ?></div>
            <div class="text-xs text-gray-600">Official Receipt</div>
        </div>

        <!-- Order Info -->
        <div class="text-xs mb-3">
            <div class="flex justify-between"><span>Order #:</span><span class="font-bold">#<?= $orderId ?></span></div>
            <div class="flex justify-between"><span>Table:</span><span class="font-bold">T-<?= htmlspecialchars($order['table_number']) ?></span></div>
            <div class="flex justify-between"><span>Date:</span><span><?= date('M d, Y H:i', strtotime($order['created_at'])) ?></span></div>
            <div class="flex justify-between"><span>Cashier:</span><span><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Staff') ?></span></div>
            <?php if (!empty($order['customer_name'])): ?>
            <div class="flex justify-between"><span>Customer:</span><span class="font-bold"><?= htmlspecialchars($order['customer_name']) ?></span></div>
            <?php endif; ?>
        </div>

        <!-- Items -->
        <div class="border-t border-b my-3">
            <div class="font-bold text-sm mb-2">Items</div>
            <?php foreach ($items as $item): ?>
            <div class="text-xs mb-1">
                <div class="flex justify-between">
                    <span><?= $item['quantity'] ?>x <?= htmlspecialchars($item['item_name']) ?></span>
                    <span class="font-bold"><?= number_format($item['price'] * $item['quantity'], 2) ?></span>
                </div>
                <div class="text-gray-600">@ <?= number_format($item['price'], 2) ?> each</div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Totals -->
        <div class="text-xs mt-3">
            <div class="flex justify-between mb-1"><span>Subtotal</span><span><?= number_format($subtotal, 2) ?></span></div>
            <?php if ($serviceCharge > 0): ?>
            <div class="flex justify-between mb-1"><span>Service Charge (<?= $settings['service_charge_type'] === 'percent' ? $settings['service_charge_amount'] . '%' : number_format($settings['service_charge_amount'], 2) ?>)</span><span><?= number_format($serviceCharge, 2) ?></span></div>
            <?php endif; ?>
            <?php if ($tax > 0): ?>
            <div class="flex justify-between mb-1"><span>VAT (<?= $settings['tax_percentage'] ?>%)</span><span><?= number_format($tax, 2) ?></span></div>
            <?php endif; ?>
            <div class="border-t pt-2 mt-2">
                <div class="flex justify-between font-bold text-lg"><span>TOTAL</span><span><?= number_format($grandTotal, 2) ?></span></div>
            </div>
        </div>

        <!-- Payment Info -->
        <div class="border-t mt-3 pt-3 text-xs">
            <div class="font-bold mb-2">Payment</div>
            <div class="flex justify-between mb-1"><span>Method:</span><span class="font-bold"><?= $paymentMethodLabel ?></span></div>
            <div class="flex justify-between mb-1"><span>Status:</span><span class="font-bold text-green-600">PAID</span></div>
            <div class="flex justify-between"><span>Ref:</span><span class="font-mono"><?= htmlspecialchars($payment['transaction_id'] ?? 'N/A') ?></span></div>
        </div>

        <!-- Footer -->
        <div class="text-center text-xs text-gray-500 mt-4 border-t pt-3">
            <div>Thank you for dining with us!</div>
            <div class="mt-1">Visit us again</div>
        </div>
    </div>

    <script>
        // Auto-print on load if requested
        if (window.location.search.includes('auto_print=1')) {
            window.onload = function() { window.print(); };
        }
    </script>
</body>
</html>