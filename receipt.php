<?php
// receipt.php - Print-friendly receipt for completed orders
require_once 'config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) {
    die("Database connection error");
}

$tenantId = (int)$_SESSION['restaurant_id'] ?? 0;
$orderId = intval($_GET['order_id'] ?? 0);

if (!$orderId) {
    die("Order ID required");
}

// Fetch restaurant/payment settings
$settings_res = $conn->query("SELECT * FROM payment_settings WHERE restaurant_id = $tenantId LIMIT 1");
$settings = $settings_res ? $settings_res->fetch_assoc() : [];
$restaurantName = $settings['restaurant_name'] ?? 'QR Restaurant';
$restaurantAddress = $settings['payment_note'] ?? '';
$vatPercent = floatval($settings['tax_percentage'] ?? 13.00);
$scPercent = !empty($settings['service_charge_enabled']) ? floatval($settings['service_charge_amount'] ?? 10.00) : 0.00;
$scType = $settings['service_charge_type'] ?? 'percent';
$taxEnabled = !empty($settings['tax_enabled']);
$scEnabled = !empty($settings['service_charge_enabled']);

// Fetch order with items and payment info
$stmt = $conn->prepare("
    SELECT o.*, t.id as table_id, t.table_number, t.zone,
           pt.transaction_id, pt.gateway_name, pt.amount as paid_amount, pt.reference_id, pt.created_at as paid_at
    FROM orders o
    LEFT JOIN tables t ON o.table_number = t.table_number AND t.restaurant_id = o.restaurant_id
    LEFT JOIN payment_transactions pt ON pt.order_id = o.id AND pt.restaurant_id = o.restaurant_id AND pt.status = 'paid'
    WHERE o.id = ? AND o.restaurant_id = ?
    ORDER BY pt.created_at DESC LIMIT 1
");
$stmt->bind_param("ii", $orderId, $tenantId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    die("Order not found");
}

// Fetch order items
$stmt = $conn->prepare("
    SELECT oi.*, mi.name as item_name
    FROM order_items oi
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    WHERE oi.order_id = ?
");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch customer info if linked
$customer = null;
if (!empty($order['customer_id'])) {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? AND restaurant_id = ? LIMIT 1");
    $stmt->bind_param("ii", $order['customer_id'], $tenantId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Calculate bill breakdown using BillingService logic
$subtotal = 0;
foreach ($items as $item) {
    $subtotal += (float)$item['price'] * (int)$item['quantity'];
}
$subtotal = round($subtotal, 2);

$serviceCharge = 0;
if ($scEnabled && $subtotal > 0) {
    if ($scType === 'percent') {
        $serviceCharge = round(($subtotal * $scPercent) / 100.0, 2);
    } else {
        $serviceCharge = round($scPercent, 2);
    }
}

$tax = 0;
if ($taxEnabled && $subtotal > 0) {
    $taxableBase = $subtotal + $serviceCharge;
    $tax = round(($taxableBase * $vatPercent) / 100.0, 2);
}

// Loyalty discount: re-derive from the redeemed points ledger + any manual order discount
$loyaltyDiscount = 0.0;
$redeemedPoints = 0;
if (!empty($order['customer_id'])) {
    $stmt = $conn->prepare("SELECT points FROM loyalty_transactions WHERE order_id = ? AND restaurant_id = ? AND type = 'redeem' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ii", $orderId, $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $redeemedPoints = (int)abs((int)($row['points'] ?? 0));
        $stmt->close();
    }
}
if ($redeemedPoints > 0) {
    $loyaltyBill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, $redeemedPoints, false);
    if ($loyaltyBill) {
        $loyaltyDiscount = (float)$loyaltyBill['loyalty_discount'];
    }
}
$manualDiscount = (float)($order['discount_amount'] ?? 0);
$totalDiscount = round($loyaltyDiscount + $manualDiscount, 2);

$grandTotal = round($subtotal + $serviceCharge + $tax - $totalDiscount, 2);
$paidAmount = (float)($order['paid_amount'] ?? $order['total_amount'] ?? $grandTotal);
$cashReceived = (float)($_GET['cash_received'] ?? $paidAmount);
$change = max(0, $cashReceived - $paidAmount);

// Format currency
function fmt($amt) {
    return 'Rs. ' . number_format((float)$amt, 2);
}

$paidAt = $order['paid_at'] ?? $order['updated_at'] ?? date('Y-m-d H:i:s');
$dateTime = date('d M Y, h:i A', strtotime($paidAt));
$billNumber = $order['transaction_id'] ?? 'ORD-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
$paymentMethod = strtoupper($order['payment_method'] ?? 'CASH');
$methodLabel = [
    'cash' => 'CASH',
    'card' => 'CARD',
    'digital' => 'DIGITAL QR',
    'digital_qr' => 'DIGITAL QR'
];
$paymentMethodLabel = $methodLabel[strtolower($paymentMethod)] ?? $paymentMethod;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo htmlspecialchars($billNumber); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .receipt-container { box-shadow: none !important; border: none !important; max-width: 100% !important; }
            .thermal { width: 80mm; max-width: 80mm; padding: 10px; }
        }
        .thermal-receipt { width: 80mm; max-width: 320px; margin: 0 auto; font-family: 'Courier New', monospace; font-size: 11px; line-height: 1.4; }
        .receipt-container { max-width: 400px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .divider { border-top: 1px dashed #ccc; margin: 8px 0; }
        .thick-divider { border-top: 2px solid #333; margin: 8px 0; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .flex-between { display: flex; justify-content: space-between; }
        .bold { font-weight: bold; }
        .small { font-size: 10px; }
        .logo { width: 60px; height: 60px; margin: 0 auto 10px; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen py-8 px-4">
    
    <!-- Print Button (hidden on print) -->
    <div class="no-print text-center mb-4">
        <button onclick="window.print()" class="bg-blue-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-blue-700 transition-colors">
            🖨️ Print Receipt
        </button>
        <a href="admin/tables.php" class="no-print ml-4 text-blue-600 hover:underline">← Back to Tables</a>
    </div>

    <!-- Standard A4 Receipt -->
    <div class="receipt-container">
        <!-- Header -->
        <div class="text-center mb-4">
            <div class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($restaurantName); ?></div>
            <?php if ($restaurantAddress): ?>
                <div class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($restaurantAddress); ?></div>
            <?php endif; ?>
            <div class="text-xs text-gray-500 mt-1">Table: <?php echo htmlspecialchars($order['table_number']); ?> (<?php echo htmlspecialchars($order['zone'] ?? 'Ground Floor'); ?>)</div>
        </div>
        <div class="thick-divider"></div>

        <!-- Bill Info -->
        <div class="flex-between text-sm mb-2">
            <span>Bill #: <span class="bold"><?php echo htmlspecialchars($billNumber); ?></span></span>
            <span>Date: <?php echo $dateTime; ?></span>
        </div>
        <div class="flex-between text-sm mb-2">
            <span>Order #: <span class="bold">#<?php echo $orderId; ?></span></span>
            <span>Cashier: <?php echo htmlspecialchars($_SESSION['username'] ?? 'Staff'); ?></span>
        </div>
        <?php if ($customer): ?>
        <div class="flex-between text-sm mb-2">
            <span>Customer: <span class="bold"><?php echo htmlspecialchars($customer['name']); ?></span></span>
            <span>Phone: <?php echo htmlspecialchars($customer['phone']); ?></span>
        </div>
        <?php endif; ?>
        <div class="divider"></div>

        <!-- Items -->
        <div class="text-sm">
            <div class="bold text-gray-900 mb-1">ITEMS</div>
            <?php foreach ($items as $item): ?>
                <div class="flex-between mb-1">
                    <span><?php echo (int)$item['quantity']; ?>x <?php echo htmlspecialchars($item['item_name']); ?></span>
                    <span class="bold"><?php echo fmt($item['price'] * $item['quantity']); ?></span>
                </div>
                <div class="text-xs text-gray-500 text-right">@ <?php echo fmt($item['price']); ?> each</div>
            <?php endforeach; ?>
        </div>
        <div class="divider"></div>

        <!-- Financial Summary -->
        <div class="text-sm space-y-1">
            <div class="flex-between">
                <span>Subtotal</span>
                <span><?php echo fmt($subtotal); ?></span>
            </div>
            <?php if ($serviceCharge > 0): ?>
            <div class="flex-between">
                <span>Service Charge (<?php echo $scPercent; ?>%)</span>
                <span><?php echo fmt($serviceCharge); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($tax > 0): ?>
            <div class="flex-between">
                <span>VAT (<?php echo $vatPercent; ?>%)</span>
                <span><?php echo fmt($tax); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($totalDiscount > 0): ?>
            <div class="flex-between" style="color:#059669;">
                <span>Discount<?php if ($loyaltyDiscount > 0 && $manualDiscount > 0): ?> (Loyalty + Other)<?php elseif ($loyaltyDiscount > 0): ?> (Loyalty)<?php endif; ?></span>
                <span>-<?php echo fmt($totalDiscount); ?></span>
            </div>
            <?php endif; ?>
            <div class="thick-divider"></div>
            <div class="flex-between text-lg bold">
                <span>Grand Total</span>
                <span><?php echo fmt($grandTotal); ?></span>
            </div>
        </div>
        <div class="divider"></div>

        <!-- Payment Info -->
        <div class="text-sm space-y-1">
            <div class="flex-between bold">
                <span>Payment Method</span>
                <span><?php echo $paymentMethodLabel; ?></span>
            </div>
            <div class="flex-between">
                <span>Amount Paid</span>
                <span class="bold"><?php echo fmt($paidAmount); ?></span>
            </div>
            <?php if (strtolower($paymentMethod) === 'cash'): ?>
            <div class="flex-between">
                <span>Cash Received</span>
                <span><?php echo fmt($cashReceived); ?></span>
            </div>
            <div class="flex-between">
                <span>Change Due</span>
                <span class="bold text-green-600"><?php echo fmt($change); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="thick-divider"></div>

        <!-- Status -->
        <div class="text-center text-lg bold text-green-600 mt-2 mb-2">✓ PAID</div>

        <!-- Footer -->
        <div class="text-center text-xs text-gray-500 mt-4 space-y-1">
            <div>Thank you for dining with us!</div>
            <div>Please visit again</div>
        </div>
    </div>

    <!-- Thermal Receipt (80mm) - Hidden by default, can be used for thermal printers -->
    <div class="thermal-receipt no-print hidden" id="thermalReceipt">
        <div class="text-center mb-2">
            <div class="text-lg bold"><?php echo htmlspecialchars($restaurantName); ?></div>
            <?php if ($restaurantAddress): ?>
                <div class="small"><?php echo htmlspecialchars($restaurantAddress); ?></div>
            <?php endif; ?>
            <div class="small">Table: <?php echo htmlspecialchars($order['table_number']); ?></div>
        </div>
        <div class="thick-divider"></div>
        <div class="flex-between small mb-1">
            <span>Bill: <?php echo htmlspecialchars($billNumber); ?></span>
            <span><?php echo $dateTime; ?></span>
        </div>
        <div class="flex-between small mb-1">
            <span>Order: #<?php echo $orderId; ?></span>
            <span><?php echo $paymentMethodLabel; ?></span>
        </div>
        <?php if ($customer): ?>
        <div class="small mb-1">Customer: <?php echo htmlspecialchars($customer['name']); ?></div>
        <?php endif; ?>
        <div class="divider"></div>
        <div class="small bold mb-1">ITEMS</div>
        <?php foreach ($items as $item): ?>
            <div class="flex-between small mb-1">
                <span><?php echo (int)$item['quantity']; ?>x <?php echo htmlspecialchars($item['item_name']); ?></span>
                <span><?php echo fmt($item['price'] * $item['quantity']); ?></span>
            </div>
        <?php endforeach; ?>
        <div class="divider"></div>
        <div class="flex-between small">
            <span>Subtotal</span>
            <span><?php echo fmt($subtotal); ?></span>
        </div>
        <?php if ($serviceCharge > 0): ?>
        <div class="flex-between small">
            <span>SC (<?php echo $scPercent; ?>%)</span>
            <span><?php echo fmt($serviceCharge); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($tax > 0): ?>
        <div class="flex-between small">
            <span>VAT (<?php echo $vatPercent; ?>%)</span>
            <span><?php echo fmt($tax); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($totalDiscount > 0): ?>
        <div class="flex-between small" style="color:#059669;">
            <span>Discount</span>
            <span>-<?php echo fmt($totalDiscount); ?></span>
        </div>
        <?php endif; ?>
        <div class="thick-divider"></div>
        <div class="flex-between bold">
            <span>TOTAL</span>
            <span><?php echo fmt($grandTotal); ?></span>
        </div>
        <div class="divider"></div>
        <div class="flex-between small">
            <span>Paid</span>
            <span><?php echo fmt($paidAmount); ?></span>
        </div>
        <?php if (strtolower($paymentMethod) === 'cash'): ?>
        <div class="flex-between small">
            <span>Received</span>
            <span><?php echo fmt($cashReceived); ?></span>
        </div>
        <div class="flex-between small bold text-green-600">
            <span>Change</span>
            <span><?php echo fmt($change); ?></span>
        </div>
        <?php endif; ?>
        <div class="thick-divider"></div>
        <div class="text-center bold text-green-600">✓ PAID</div>
        <div class="text-center small text-gray-500 mt-1">Thank you! Visit again</div>
    </div>

    <script>
        // Auto-print on load if print parameter is set
        if (new URLSearchParams(window.location.search).has('print')) {
            window.onload = function() {
                window.print();
            };
        }
    </script>
</body>
</html>