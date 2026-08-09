<?php
// api/table-payment.php - Table Payment Processing (Billing Settlement Inside Floor & Tables)
// Handles: Customer lookup/creation, Loyalty redemption, Payment processing, Order settlement
require_once __DIR__ . '/../config.php';

// Release session lock for concurrent polling
session_write_close();

// Staff authentication with tenant context
$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'search_customer':
            // Search customer by phone within tenant
            $phone = Security::sanitize(trim($_POST['phone'] ?? ''));
            if (!$phone) {
                Response::error('Phone number required', 400);
            }
            $stmt = $conn->prepare("SELECT id, name, phone, email, total_visits, total_spent, loyalty_points, tier FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1");
            $stmt->bind_param("is", $tenantId, $phone);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($customer) {
                Response::success('Customer found', ['customer' => $customer, 'exists' => true]);
            } else {
                Response::success('Customer not found - can create new', ['exists' => false, 'phone' => $phone]);
            }
            break;

        case 'create_customer':
            // Create new customer within tenant
            $phone = Security::sanitize(trim($_POST['phone'] ?? ''));
            $name = Security::sanitize(trim($_POST['name'] ?? ''));
            $email = Security::sanitize(trim($_POST['email'] ?? ''));
            
            if (!$phone || !$name) {
                Response::error('Phone and name are required', 400);
            }
            
            // Check if already exists (race condition protection)
            $stmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1");
            $stmt->bind_param("is", $tenantId, $phone);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $stmt->close();
                Response::error('Customer with this phone already exists', 409);
            }
            $stmt->close();
            
            $stmt = $conn->prepare("INSERT INTO customers (restaurant_id, name, phone, email, total_visits, total_spent, loyalty_points, tier, created_at) VALUES (?, ?, ?, ?, 0, 0.00, 0, 'Bronze', NOW())");
            $stmt->bind_param("isss", $tenantId, $name, $phone, $email);
            $stmt->execute();
            $customerId = $conn->insert_id;
            $stmt->close();
            
            Security::logAudit('CUSTOMER_CREATED', "Created customer #$customerId: $name ($phone) from table billing");
            Response::success('Customer created', ['customer' => ['id' => $customerId, 'name' => $name, 'phone' => $phone, 'email' => $email, 'total_visits' => 0, 'total_spent' => '0.00', 'loyalty_points' => 0, 'tier' => 'Bronze']]);
            break;

        case 'get_loyalty':
            // Get loyalty info for customer
            $customerId = intval($_POST['customer_id'] ?? 0);
            if (!$customerId) {
                Response::error('Customer ID required', 400);
            }
            
            // Verify customer belongs to tenant
            $stmt = $conn->prepare("SELECT loyalty_points, name FROM customers WHERE id = ? AND restaurant_id = ? LIMIT 1");
            $stmt->bind_param("ii", $customerId, $tenantId);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$customer) {
                Response::error('Customer not found', 404);
            }
            
            // 1 point = Rs.0.10 (configurable)
            $pointsValue = round($customer['loyalty_points'] * 0.10, 2);
            
            Response::success('Loyalty info', [
                'customer_id' => $customerId,
                'customer_name' => $customer['name'],
                'loyalty_points' => (int)$customer['loyalty_points'],
                'points_value' => $pointsValue,
                'conversion_rate' => 0.10
            ]);
            break;

        case 'apply_loyalty':
            // Calculate loyalty discount (preview only - actual deduction on payment)
            $customerId = intval($_POST['customer_id'] ?? 0);
            $pointsToRedeem = intval($_POST['points'] ?? 0);
            $billTotal = floatval($_POST['bill_total'] ?? 0);
            
            if (!$customerId || !$pointsToRedeem) {
                Response::error('Customer ID and points required', 400);
            }
            
            $stmt = $conn->prepare("SELECT loyalty_points FROM customers WHERE id = ? AND restaurant_id = ? LIMIT 1");
            $stmt->bind_param("ii", $customerId, $tenantId);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$customer) {
                Response::error('Customer not found', 404);
            }
            
            $availablePoints = (int)$customer['loyalty_points'];
            if ($pointsToRedeem > $availablePoints) {
                Response::error("Insufficient points. Available: $availablePoints", 400);
            }
            
            $discountValue = round($pointsToRedeem * 0.10, 2);
            if ($discountValue > $billTotal) {
                $discountValue = $billTotal;
                $pointsToRedeem = (int)($billTotal / 0.10);
            }
            
            Response::success('Loyalty applied', [
                'points_redeemed' => $pointsToRedeem,
                'discount_value' => $discountValue,
                'remaining_points' => $availablePoints - $pointsToRedeem
            ]);
            break;

        case 'process_payment':
            // Main payment processing - atomic transaction
            $tableNumber = Security::sanitize(trim($_POST['table_number'] ?? ''));
            $orderId = intval($_POST['order_id'] ?? 0);
            $paymentMethod = Security::sanitize($_POST['payment_method'] ?? '');
            $customerId = intval($_POST['customer_id'] ?? 0);
            $loyaltyPointsRedeemed = intval($_POST['loyalty_points_redeemed'] ?? 0);
            $cashReceived = floatval($_POST['cash_received'] ?? 0);
            
            if (!$tableNumber || !$orderId || !$paymentMethod) {
                Response::error('Missing required parameters', 400);
            }
            
            $allowedMethods = ['cash', 'card', 'digital'];
            if (!in_array($paymentMethod, $allowedMethods)) {
                Response::error('Invalid payment method', 400);
            }
            
            // For cash, validate cash received
            if ($paymentMethod === 'cash' && $cashReceived <= 0) {
                Response::error('Cash received amount required', 400);
            }
            
            $conn->begin_transaction();
            
            try {
                // 1. Lock and verify order belongs to tenant and table
                $stmt = $conn->prepare("
                    SELECT o.id, o.table_number, o.customer_name, o.total_amount, o.payment_status, o.status, 
                           o.restaurant_id, o.dining_session_id
                    FROM orders o
                    WHERE o.id = ? AND o.restaurant_id = ? AND o.table_number = ?
                    FOR UPDATE
                ");
                $stmt->bind_param("iis", $orderId, $tenantId, $tableNumber);
                $stmt->execute();
                $order = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if (!$order) {
                    throw new Exception('Order not found or does not belong to this table');
                }
                
                if ($order['payment_status'] === 'paid') {
                    throw new Exception('This bill has already been settled');
                }
                
                if ($order['status'] === 'cancelled') {
                    throw new Exception('Cannot pay for a cancelled order');
                }
                
                // 2. Recalculate totals server-side from order items
                $itemsStmt = $conn->prepare("
                    SELECT oi.quantity, oi.price, mi.name 
                    FROM order_items oi
                    JOIN menu_items mi ON oi.menu_item_id = mi.id
                    WHERE oi.order_id = ? AND oi.restaurant_id = ?
                ");
                $itemsStmt->bind_param("ii", $orderId, $tenantId);
                $itemsStmt->execute();
                $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $itemsStmt->close();
                
                $subtotal = 0;
                foreach ($items as $item) {
                    $subtotal += $item['quantity'] * $item['price'];
                }
                
                // Get payment settings for tax/service charge
                $settingsStmt = $conn->prepare("SELECT tax_enabled, tax_percentage, service_charge_enabled, service_charge_type, service_charge_amount FROM payment_settings WHERE restaurant_id = ? LIMIT 1");
                $settingsStmt->bind_param("i", $tenantId);
                $settingsStmt->execute();
                $settings = $settingsStmt->get_result()->fetch_assoc();
                $settingsStmt->close();
                
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
                
                $loyaltyDiscount = round($loyaltyPointsRedeemed * 0.10, 2);
                if ($loyaltyDiscount > $subtotal + $serviceCharge + $tax) {
                    $loyaltyDiscount = $subtotal + $serviceCharge + $tax;
                    $loyaltyPointsRedeemed = (int)($loyaltyDiscount / 0.10);
                }
                
                $grandTotal = max(0, round($subtotal + $serviceCharge + $tax - $loyaltyDiscount, 2));
                
                // 3. Validate payment amount
                if ($paymentMethod === 'cash') {
                    if ($cashReceived + 0.001 < $grandTotal) { // small epsilon for floating point
                        throw new Exception('Cash received is less than amount due');
                    }
                }
                
                // 4. Create payment record
                $transactionId = 'TXN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $gatewayMap = [
                    'cash' => 'cash',
                    'card' => 'card',
                    'digital' => 'digital_qr'
                ];
                $gatewayName = $gatewayMap[$paymentMethod] ?? 'cash';
                
                $paymentStmt = $conn->prepare("
                    INSERT INTO payment_transactions (restaurant_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at)
                    VALUES (?, ?, ?, ?, ?, 'paid', ?, NOW())
                ");
                $referenceId = $paymentMethod === 'cash' ? 'CASH-' . $transactionId : ($paymentMethod === 'card' ? 'CARD-' . $transactionId : 'QR-' . $transactionId);
                $paymentStmt->bind_param("iisdss", $tenantId, $transactionId, $orderId, $gatewayName, $grandTotal, $referenceId);
                $paymentStmt->execute();
                $paymentStmt->close();
                
                // 5. Mark order as paid
                $updateStmt = $conn->prepare("UPDATE orders SET payment_status = 'paid', payment_method = ?, status = 'completed', updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $updateStmt->bind_param("sii", $paymentMethod, $orderId, $tenantId);
                $updateStmt->execute();
                $updateStmt->close();
                
                // 6. Handle customer and loyalty
                $effectiveCustomerId = $customerId;
                if ($customerId > 0) {
                    // Verify customer belongs to tenant
                    $custStmt = $conn->prepare("SELECT id FROM customers WHERE id = ? AND restaurant_id = ? LIMIT 1");
                    $custStmt->bind_param("ii", $customerId, $tenantId);
                    $custStmt->execute();
                    if (!$custStmt->get_result()->fetch_assoc()) {
                        $effectiveCustomerId = 0; // Invalid customer, ignore
                    }
                    $custStmt->close();
                }
                
                if ($effectiveCustomerId > 0) {
                    // Update customer stats
                    $conn->query("UPDATE customers SET total_visits = total_visits + 1, total_spent = total_spent + $grandTotal, last_visit_at = NOW() WHERE id = $effectiveCustomerId AND restaurant_id = $tenantId");
                    
                    // Earn loyalty points (1 point per Rs.10 spent, configurable)
                    $pointsEarned = (int)($grandTotal / 10);
                    if ($pointsEarned > 0) {
                        $conn->query("UPDATE customers SET loyalty_points = loyalty_points + $pointsEarned WHERE id = $effectiveCustomerId AND restaurant_id = $tenantId");
                        $conn->query("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, notes, created_at) VALUES ($tenantId, $effectiveCustomerId, $orderId, 'earn', $pointsEarned, round($pointsEarned * 0.10, 2), 'Points earned from order #$orderId', NOW())");
                    }
                    
                    // Redeem loyalty points if applied
                    if ($loyaltyPointsRedeemed > 0) {
                        $conn->query("UPDATE customers SET loyalty_points = loyalty_points - $loyaltyPointsRedeemed WHERE id = $effectiveCustomerId AND restaurant_id = $tenantId");
                        $conn->query("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, notes, created_at) VALUES ($tenantId, $effectiveCustomerId, $orderId, 'redeem', -$loyaltyPointsRedeemed, -round($loyaltyPointsRedeemed * 0.10, 2), 'Points redeemed for order #$orderId', NOW())");
                    }
                }
                
                // 7. Update table status to vacant
                $conn->query("UPDATE tables SET status = 'vacant', guest_count = 0, reserved_by = '' WHERE table_number = '$tableNumber' AND restaurant_id = $tenantId");
                
                // 8. Update dining session if exists
                if (!empty($order['dining_session_id'])) {
                    $conn->query("UPDATE dining_sessions SET status = 'closed', running_total = 0 WHERE id = " . (int)$order['dining_session_id'] . " AND restaurant_id = $tenantId");
                }
                
                // 9. Create audit log
                Security::logAudit('BILL_SETTLED', "Order #$orderId (Table $tableNumber) settled for " . formatPrice($grandTotal) . " via " . strtoupper($paymentMethod) . ($customerId ? " Customer #$customerId" : ""));
                
                $conn->commit();
                
                Response::success('Payment processed successfully', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'payment_method' => $paymentMethod,
                    'grand_total' => $grandTotal,
                    'cash_change' => $paymentMethod === 'cash' ? round($cashReceived - $grandTotal, 2) : 0,
                    'customer_id' => $effectiveCustomerId,
                    'loyalty_points_redeemed' => $loyaltyPointsRedeemed,
                    'loyalty_discount' => $loyaltyDiscount
                ]);
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'get_payment_qr':
            // Generate payment QR code data for digital payment
            $orderId = intval($_POST['order_id'] ?? 0);
            $tableNumber = Security::sanitize(trim($_POST['table_number'] ?? ''));
            
            if (!$orderId || !$tableNumber) {
                Response::error('Order ID and table number required', 400);
            }
            
            // Verify order
            $stmt = $conn->prepare("SELECT total_amount FROM orders WHERE id = ? AND restaurant_id = ? AND table_number = ? AND payment_status = 'pending' LIMIT 1");
            $stmt->bind_param("iis", $orderId, $tenantId, $tableNumber);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$order) {
                Response::error('Order not found or already paid', 404);
            }
            
            // Generate payment URL (in production, this would integrate with actual payment gateway)
            $paymentUrl = 'https://pay.example.com/pay?order=' . $orderId . '&amount=' . urlencode($order['total_amount']) . '&tenant=' . $tenantId;
            
            Response::success('Payment QR data', [
                'payment_url' => $paymentUrl,
                'amount' => (float)$order['total_amount'],
                'order_id' => $orderId
            ]);
            break;

        default:
            Response::error('Invalid action', 400);
    }
} catch (Exception $e) {
    if ($conn->connect_errno === 0) @$conn->rollback();
    Response::error($e->getMessage(), 500);
}