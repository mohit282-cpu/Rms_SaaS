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
            $stmt = $conn->prepare("SELECT loyalty_points, name, lifetime_points_earned, lifetime_points_redeemed FROM customers WHERE id = ? AND restaurant_id = ? LIMIT 1");
            $stmt->bind_param("ii", $customerId, $tenantId);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$customer) {
                Response::error('Customer not found', 404);
            }
            
            // Get loyalty settings for point value
            $loyaltySettings = BillingService::getLoyaltySettings($conn, $tenantId);
            $pointValue = $loyaltySettings['point_value'];
            $pointsValue = round($customer['loyalty_points'] * $pointValue, 2);
            
            Response::success('Loyalty info', [
                'customer_id' => $customerId,
                'customer_name' => $customer['name'],
                'loyalty_points' => (int)$customer['loyalty_points'],
                'lifetime_points_earned' => (int)$customer['lifetime_points_earned'],
                'lifetime_points_redeemed' => (int)$customer['lifetime_points_redeemed'],
                'points_value' => $pointsValue,
                'point_value' => $pointValue,
                'conversion_rate' => $pointValue
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
            
            // Get loyalty settings
            $loyaltySettings = BillingService::getLoyaltySettings($conn, $tenantId);
            $pointValue = $loyaltySettings['point_value'];
            $minRedemption = $loyaltySettings['min_redemption_points'];
            $maxRedemption = $loyaltySettings['max_redemption_points'];
            $maxDiscountPercent = $loyaltySettings['max_discount_percent'];
            
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
            
            if ($pointsToRedeem < $minRedemption) {
                Response::error("Minimum $minRedemption points required for redemption", 400);
            }
            
            if ($maxRedemption > 0 && $pointsToRedeem > $maxRedemption) {
                Response::error("Maximum $maxRedemption points can be redeemed per bill", 400);
            }
            
            $discountValue = round($pointsToRedeem * $pointValue, 2);
            
            // Check max discount percentage
            $maxDiscountAmount = round($billTotal * $maxDiscountPercent / 100.0, 2);
            if ($discountValue > $maxDiscountAmount) {
                $discountValue = $maxDiscountAmount;
                $pointsToRedeem = (int)($maxDiscountAmount / $pointValue);
            }
            
            if ($discountValue > $billTotal) {
                $discountValue = $billTotal;
                $pointsToRedeem = (int)($billTotal / $pointValue);
            }
            
            Response::success('Loyalty applied', [
                'points_redeemed' => $pointsToRedeem,
                'discount_value' => $discountValue,
                'remaining_points' => $availablePoints - $pointsToRedeem,
                'point_value' => $pointValue,
                'max_discount_percent' => $maxDiscountPercent,
                'min_redemption_points' => $minRedemption,
                'max_redemption_points' => $maxRedemption
            ]);
            break;

        case 'calculate_bill':
            // Calculate authoritative bill totals for order
            $orderId = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
            $pointsToRedeem = intval($_POST['loyalty_points'] ?? $_GET['loyalty_points'] ?? 0);
            $isNCR = !empty($_POST['is_ncr']) || !empty($_GET['is_ncr']);

            if (!$orderId) {
                Response::error('Order ID is required', 400);
            }

            $bill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, $pointsToRedeem, $isNCR);
            Response::success('Bill calculated successfully', ['bill' => $bill]);
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
                
                // 2. Authoritative server-side bill calculation via BillingService
                $bill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, $loyaltyPointsRedeemed, false);
                $grandTotal = $bill['grand_total'];
                $loyaltyDiscount = $bill['loyalty_discount'];
                $loyaltyPointsRedeemed = $bill['loyalty_points_redeemed'];
                
                // 3. Validate payment amount
                if ($paymentMethod === 'cash') {
                    if ($cashReceived + 0.001 < $grandTotal) {
                        throw new Exception('Cash received (Rs. ' . number_format($cashReceived, 2) . ') is less than amount due (Rs. ' . number_format($grandTotal, 2) . ')');
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
                $paymentStmt->bind_param("isisds", $tenantId, $transactionId, $orderId, $gatewayName, $grandTotal, $referenceId);
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
                
                // Get loyalty settings for earning calculation
                $loyaltySettings = BillingService::getLoyaltySettings($conn, $tenantId);
                $isLoyaltyEnabled = $loyaltySettings['is_enabled'];
                $earnSpendAmount = $loyaltySettings['earn_spend_amount'];
                $pointValue = $loyaltySettings['point_value'];
                $expirationEnabled = $loyaltySettings['expiration_enabled'];
                $expirationDays = $loyaltySettings['expiration_days'];
                
                if ($effectiveCustomerId > 0 && $isLoyaltyEnabled) {
                    // Update customer stats
                    $conn->query("UPDATE customers SET total_visits = total_visits + 1, total_spent = total_spent + $grandTotal, last_visit_at = NOW() WHERE id = $effectiveCustomerId AND restaurant_id = $tenantId");
                    
                    // Earn loyalty points based on configured earn_spend_amount
                    // Only calculate on eligible amount (grand_total minus loyalty discount)
                    $eligibleAmount = max(0, $grandTotal - $loyaltyDiscount);
                    $pointsEarned = (int)($eligibleAmount / $earnSpendAmount);
                    $expirationDate = null;
                    if ($expirationEnabled && $expirationDays > 0) {
                        $expirationDate = date('Y-m-d', strtotime("+$expirationDays days"));
                    }
                    if ($pointsEarned > 0) {
                        $conn->query("UPDATE customers SET loyalty_points = loyalty_points + $pointsEarned, lifetime_points_earned = lifetime_points_earned + $pointsEarned WHERE id = $effectiveCustomerId AND restaurant_id = $tenantId");
                        $conn->query("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, notes, expiration_date, created_at) VALUES ($tenantId, $effectiveCustomerId, $orderId, 'earn', $pointsEarned, " . round($pointsEarned * $pointValue, 2) . ", 'Points earned from order #$orderId', " . ($expirationDate ? "'$expirationDate'" : "NULL") . ", NOW())");
                    }
                    
                    // Redeem loyalty points if applied
                    if ($loyaltyPointsRedeemed > 0) {
                        $conn->query("UPDATE customers SET loyalty_points = loyalty_points - $loyaltyPointsRedeemed, lifetime_points_redeemed = lifetime_points_redeemed + $loyaltyPointsRedeemed WHERE id = $effectiveCustomerId AND restaurant_id = $tenantId");
                        $conn->query("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, notes, created_at) VALUES ($tenantId, $effectiveCustomerId, $orderId, 'redeem', -$loyaltyPointsRedeemed, -" . round($loyaltyPointsRedeemed * $pointValue, 2) . ", 'Points redeemed for order #$orderId', NOW())");
                    }
                }
                
                // 7. Update table status to cleaning (workflow: paid -> cleaning -> vacant)
                $conn->query("UPDATE tables SET status = 'cleaning', guest_count = 0, reserved_by = '' WHERE table_number = '$tableNumber' AND restaurant_id = $tenantId");
                
                // 8. Update dining session if exists
                if (!empty($order['dining_session_id'])) {
                    $conn->query("UPDATE dining_sessions SET status = 'closed', running_total = 0 WHERE id = " . (int)$order['dining_session_id'] . " AND restaurant_id = $tenantId");
                }
                
                // 9. Create audit log
                Security::logAudit('BILL_SETTLED', "Order #$orderId (Table $tableNumber) settled for " . BillingService::formatMoney($grandTotal) . " via " . strtoupper($paymentMethod) . ($customerId ? " Customer #$customerId" : ""));
                
                // 10. Fetch order items for receipt
                $itemsStmt = $conn->prepare("
                    SELECT oi.quantity, oi.price, mi.name as item_name
                    FROM order_items oi
                    JOIN menu_items mi ON oi.menu_item_id = mi.id
                    WHERE oi.order_id = ?
                ");
                $itemsStmt->bind_param("i", $orderId);
                $itemsStmt->execute();
                $orderItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $itemsStmt->close();
                
                $conn->commit();
                
                Response::success('Payment processed successfully', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'payment_method' => $paymentMethod,
                    'grand_total' => $grandTotal,
                    'cash_change' => $paymentMethod === 'cash' ? round($cashReceived - $grandTotal, 2) : 0,
                    'customer_id' => $effectiveCustomerId,
                    'loyalty_points_redeemed' => $loyaltyPointsRedeemed,
                    'loyalty_discount' => $loyaltyDiscount,
                    'items' => $orderItems
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

        case 'split_bill':
            // Split Bill Processing (Equal, Item-based, Quantity, or Custom amount)
            $orderId = intval($_POST['order_id'] ?? 0);
            $tableNumber = Security::sanitize(trim($_POST['table_number'] ?? ''));
            $splitType = Security::sanitize(trim($_POST['split_type'] ?? 'custom'));
            $paymentMethod = Security::sanitize($_POST['payment_method'] ?? 'cash');
            $splitAmount = floatval($_POST['split_amount'] ?? 0);
            $customerId = intval($_POST['customer_id'] ?? 0);

            if (!$orderId || !$tableNumber || $splitAmount <= 0) {
                Response::error('Order ID, table number, and valid positive split amount required', 400);
            }

            $allowedMethods = ['cash', 'card', 'digital'];
            if (!in_array($paymentMethod, $allowedMethods)) {
                Response::error('Invalid payment method', 400);
            }

            $conn->begin_transaction();

            try {
                // Lock order row
                $stmt = $conn->prepare("SELECT id, total_amount, payment_status, status FROM orders WHERE id = ? AND restaurant_id = ? AND table_number = ? FOR UPDATE");
                $stmt->bind_param("iis", $orderId, $tenantId, $tableNumber);
                $stmt->execute();
                $order = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$order) {
                    throw new Exception('Order not found or does not belong to this table');
                }

                if ($order['payment_status'] === 'paid') {
                    throw new Exception('This bill is already fully settled');
                }

                if ($order['status'] === 'cancelled') {
                    throw new Exception('Cannot split payment on a cancelled order');
                }

                $grandTotal = floatval($order['total_amount']);

                // Calculate existing settled amount from payment_transactions
                $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as paid_sum FROM payment_transactions WHERE order_id = ? AND restaurant_id = ? AND status = 'paid'");
                $paidStmt->bind_param("ii", $orderId, $tenantId);
                $paidStmt->execute();
                $alreadyPaid = floatval($paidStmt->get_result()->fetch_assoc()['paid_sum'] ?? 0);
                $paidStmt->close();

                $remainingBalance = max(0, round($grandTotal - $alreadyPaid, 2));

                if ($splitAmount > $remainingBalance + 0.01) {
                    throw new Exception("Split amount (" . BillingService::formatMoney($splitAmount) . ") exceeds remaining balance (" . BillingService::formatMoney($remainingBalance) . ")");
                }

                // Create partial payment transaction
                $txnId = 'SPLIT-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $gatewayName = ($paymentMethod === 'digital') ? 'digital_qr' : $paymentMethod;
                $refId = strtoupper($paymentMethod) . '-' . $txnId;

                $paymentStmt = $conn->prepare("INSERT INTO payment_transactions (restaurant_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES (?, ?, ?, ?, ?, 'paid', ?, NOW())");
                $paymentStmt->bind_param("isisds", $tenantId, $txnId, $orderId, $gatewayName, $splitAmount, $refId);
                $paymentStmt->execute();
                $paymentStmt->close();

                $newTotalPaid = round($alreadyPaid + $splitAmount, 2);
                $newRemaining = max(0, round($grandTotal - $newTotalPaid, 2));

                if ($newRemaining <= 0.01) {
                    // Fully settled!
                    $uStmt = $conn->prepare("UPDATE orders SET payment_status = 'paid', payment_method = ?, status = 'completed', updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                    $uStmt->bind_param("sii", $paymentMethod, $orderId, $tenantId);
                    $uStmt->execute();
                    $uStmt->close();

                    // Free table
$conn->query("UPDATE tables SET status = 'cleaning', guest_count = 0, reserved_by = '' WHERE table_number = '$tableNumber' AND restaurant_id = $tenantId");

                    // Get loyalty settings
                    $loyaltySettings = BillingService::getLoyaltySettings($conn, $tenantId);
                    $isLoyaltyEnabled = $loyaltySettings['is_enabled'];
                    $earnSpendAmount = $loyaltySettings['earn_spend_amount'];
                    $pointValue = $loyaltySettings['point_value'];
                    $expirationEnabled = $loyaltySettings['expiration_enabled'];
                    $expirationDays = $loyaltySettings['expiration_days'];
                    
                    // Handle customer loyalty points earn if customer attached
                    if ($customerId > 0 && $isLoyaltyEnabled) {
                        $eligibleAmount = $grandTotal; // For split bill, use full grand total
                        $pointsEarned = (int)($eligibleAmount / $earnSpendAmount);
                        $expirationDate = null;
                        if ($expirationEnabled && $expirationDays > 0) {
                            $expirationDate = date('Y-m-d', strtotime("+$expirationDays days"));
                        }
                        if ($pointsEarned > 0) {
                            $conn->query("UPDATE customers SET loyalty_points = loyalty_points + $pointsEarned, total_spent = total_spent + $grandTotal, total_visits = total_visits + 1, lifetime_points_earned = lifetime_points_earned + $pointsEarned WHERE id = $customerId AND restaurant_id = $tenantId");
                            $conn->query("INSERT INTO loyalty_transactions (restaurant_id, customer_id, order_id, type, points, amount_equivalent, notes, expiration_date, created_at) VALUES ($tenantId, $customerId, $orderId, 'earn', $pointsEarned, " . round($pointsEarned * $pointValue, 2) . ", 'Points earned from split-settled order #$orderId', " . ($expirationDate ? "'$expirationDate'" : "NULL") . ", NOW())");
                        }
                    }

                    Security::logAudit('SPLIT_BILL_COMPLETED', "Order #$orderId fully settled via split payments. Final payment: " . BillingService::formatMoney($splitAmount));
                    $conn->commit();

                    Response::success('Split payment successful - Bill fully settled!', [
                        'order_id' => $orderId,
                        'split_amount' => $splitAmount,
                        'total_paid' => $grandTotal,
                        'remaining_balance' => 0.00,
                        'is_fully_paid' => true,
                        'status' => 'paid'
                    ]);
                } else {
                    // Partially paid
                    $uStmt = $conn->prepare("UPDATE orders SET payment_status = 'partially_paid', updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                    $uStmt->bind_param("ii", $orderId, $tenantId);
                    $uStmt->execute();
                    $uStmt->close();

                    Security::logAudit('SPLIT_BILL_PARTIAL', "Partial payment of " . BillingService::formatMoney($splitAmount) . " received for Order #$orderId. Remaining: " . BillingService::formatMoney($newRemaining));
                    $conn->commit();

                    Response::success('Partial split payment recorded', [
                        'order_id' => $orderId,
                        'split_amount' => $splitAmount,
                        'total_paid' => $newTotalPaid,
                        'remaining_balance' => $newRemaining,
                        'is_fully_paid' => false,
                        'status' => 'partially_paid'
                    ]);
                }
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'merge_bills':
            // Merge Source Table Order into Target Table Order
            $sourceOrderId = intval($_POST['source_order_id'] ?? 0);
            $targetOrderId = intval($_POST['target_order_id'] ?? 0);
            $sourceTableNum = Security::sanitize(trim($_POST['source_table_number'] ?? ''));
            $targetTableNum = Security::sanitize(trim($_POST['target_table_number'] ?? ''));

            if ((!$sourceOrderId && !$sourceTableNum) || (!$targetOrderId && !$targetTableNum)) {
                Response::error('Source and Target order details required for merging', 400);
            }

            $conn->begin_transaction();

            try {
                // Find source order
                if ($sourceOrderId > 0) {
                    $sStmt = $conn->prepare("SELECT id, table_number, total_amount, status, payment_status FROM orders WHERE id = ? AND restaurant_id = ? FOR UPDATE");
                    $sStmt->bind_param("ii", $sourceOrderId, $tenantId);
                } else {
                    $sStmt = $conn->prepare("SELECT id, table_number, total_amount, status, payment_status FROM orders WHERE table_number = ? AND restaurant_id = ? AND payment_status != 'paid' AND status != 'cancelled' ORDER BY id DESC LIMIT 1 FOR UPDATE");
                    $sStmt->bind_param("si", $sourceTableNum, $tenantId);
                }
                $sStmt->execute();
                $sourceOrder = $sStmt->get_result()->fetch_assoc();
                $sStmt->close();

                // Find target order
                if ($targetOrderId > 0) {
                    $tStmt = $conn->prepare("SELECT id, table_number, total_amount, status, payment_status FROM orders WHERE id = ? AND restaurant_id = ? FOR UPDATE");
                    $tStmt->bind_param("ii", $targetOrderId, $tenantId);
                } else {
                    $tStmt = $conn->prepare("SELECT id, table_number, total_amount, status, payment_status FROM orders WHERE table_number = ? AND restaurant_id = ? AND payment_status != 'paid' AND status != 'cancelled' ORDER BY id DESC LIMIT 1 FOR UPDATE");
                    $tStmt->bind_param("si", $targetTableNum, $tenantId);
                }
                $tStmt->execute();
                $targetOrder = $tStmt->get_result()->fetch_assoc();
                $tStmt->close();

                if (!$sourceOrder || !$targetOrder) {
                    throw new Exception('Source or target order not found for merge operation');
                }

                if ($sourceOrder['id'] === $targetOrder['id']) {
                    throw new Exception('Cannot merge an order into itself');
                }

                if ($sourceOrder['payment_status'] === 'paid' || $targetOrder['payment_status'] === 'paid') {
                    throw new Exception('Cannot merge orders that have already been fully paid');
                }

                $sId = $sourceOrder['id'];
                $tId = $targetOrder['id'];
                $sTable = $sourceOrder['table_number'];

                // Transfer order_items from source to target order
                $moveStmt = $conn->prepare("UPDATE order_items SET order_id = ? WHERE order_id = ? AND restaurant_id = ?");
                $moveStmt->bind_param("iii", $tId, $sId, $tenantId);
                $moveStmt->execute();
                $moveStmt->close();

                // Recalculate subtotal for target order
                $recalcStmt = $conn->prepare("SELECT COALESCE(SUM(quantity * price), 0) as new_subtotal FROM order_items WHERE order_id = ? AND restaurant_id = ?");
                $recalcStmt->bind_param("ii", $tId, $tenantId);
                $recalcStmt->execute();
                $newSubtotal = floatval($recalcStmt->get_result()->fetch_assoc()['new_subtotal'] ?? 0);
                $recalcStmt->close();

                // Recalculate target order total_amount
                $updTargetStmt = $conn->prepare("UPDATE orders SET total_amount = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $updTargetStmt->bind_param("dii", $newSubtotal, $tId, $tenantId);
                $updTargetStmt->execute();
                $updTargetStmt->close();

                // Mark source order as cancelled / merged
                $note = "Merged into Order #$tId";
                $updSourceStmt = $conn->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'merged', notes = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $updSourceStmt->bind_param("sii", $note, $sId, $tenantId);
                $updSourceStmt->execute();
                $updSourceStmt->close();

                // Free source table
                $conn->query("UPDATE tables SET status = 'cleaning', guest_count = 0, reserved_by = '' WHERE table_number = '$sTable' AND restaurant_id = $tenantId");

                Security::logAudit('MERGE_BILLS', "Merged Order #$sId (Table $sTable) into Order #$tId (Table {$targetOrder['table_number']}). New total: " . BillingService::formatMoney($newSubtotal));
                $conn->commit();

                Response::success("Orders merged successfully! Order #$sId items consolidated into Order #$tId.", [
                    'source_order_id' => $sId,
                    'target_order_id' => $tId,
                    'merged_total' => $newSubtotal
                ]);

            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'apply_ncr':
            // Non-Chargeable / Complimentary Billing (RBAC Protected)
            $userRole = strtolower($_SESSION['user_role'] ?? $_SESSION['role'] ?? 'cashier');
            $hasNcrPerm = PermissionService::hasPermission($userRole, 'payments.ncr') || in_array($userRole, ['owner', 'manager', 'admin'], true);

            if (!$hasNcrPerm) {
                Response::error('Permission denied: Non-Chargeable / Complimentary billing requires Manager authorization.', 403);
            }

            $orderId = intval($_POST['order_id'] ?? 0);
            $tableNumber = Security::sanitize(trim($_POST['table_number'] ?? ''));
            $reason = Security::sanitize(trim($_POST['reason'] ?? 'manager_approval'));
            $notes = Security::sanitize(trim($_POST['notes'] ?? ''));

            if (!$orderId || !$tableNumber || !$reason) {
                Response::error('Order ID, table number, and approval reason required for NCR billing', 400);
            }

            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare("SELECT id, total_amount, payment_status, status FROM orders WHERE id = ? AND restaurant_id = ? AND table_number = ? FOR UPDATE");
                $stmt->bind_param("iis", $orderId, $tenantId, $tableNumber);
                $stmt->execute();
                $order = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$order) {
                    throw new Exception('Order not found or does not belong to this table');
                }

                if ($order['payment_status'] === 'paid') {
                    throw new Exception('Cannot apply NCR to an order that is already paid');
                }

                $waivedAmount = floatval($order['total_amount']);

                // Record NCR transaction in payment_transactions (amount = 0.00 so non-revenue)
                $txnId = 'NCR-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $refId = "NCR-REASON:" . strtoupper($reason) . "-" . $txnId;

                $paymentStmt = $conn->prepare("INSERT INTO payment_transactions (restaurant_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES (?, ?, ?, 'ncr', 0.00, 'ncr', ?, NOW())");
                $paymentStmt->bind_param("isis", $tenantId, $txnId, $orderId, $refId);
                $paymentStmt->execute();
                $paymentStmt->close();

                // Update order to ncr status & completed
                $ncrNote = "NCR Complimetary Waiver (" . strtoupper($reason) . "): " . $notes;
                $updStmt = $conn->prepare("UPDATE orders SET payment_status = 'ncr', total_amount = 0.00, status = 'completed', notes = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $updStmt->bind_param("sii", $ncrNote, $orderId, $tenantId);
                $updStmt->execute();
                $updStmt->close();

                // Free table
                $conn->query("UPDATE tables SET status = 'cleaning', guest_count = 0, reserved_by = '' WHERE table_number = '$tableNumber' AND restaurant_id = $tenantId");

                Security::logAudit('NCR_BILL_APPLIED', "NCR Complimentary waiver applied to Order #$orderId (Table $tableNumber). Waived amount: " . BillingService::formatMoney($waivedAmount) . ". Reason: $reason. Authorizer: " . ($_SESSION['username'] ?? 'admin'));
                $conn->commit();

                Response::success("NCR Complimentary billing applied successfully. Order #$orderId cleared.", [
                    'order_id' => $orderId,
                    'waived_amount' => $waivedAmount,
                    'reason' => $reason,
                    'status' => 'ncr'
                ]);

            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'process_refund':
            // Process Order Refund with inventory restock and loyalty reversal (RBAC Protected)
            $userRole = strtolower($_SESSION['user_role'] ?? $_SESSION['role'] ?? 'cashier');
            $hasRefundPerm = PermissionService::hasPermission($userRole, 'payments.refund') || in_array($userRole, ['owner', 'manager', 'admin'], true);

            if (!$hasRefundPerm) {
                Response::error('Permission denied: Refunding orders requires Manager authorization.', 403);
            }

            $orderId = intval($_POST['order_id'] ?? 0);
            $reason = Security::sanitize(trim($_POST['reason'] ?? 'customer_request'));

            if (!$orderId || !$reason) {
                Response::error('Order ID and refund reason required', 400);
            }

            // Perform transition through OrderService
            $resReq = OrderService::transitionStatus($conn, $orderId, 'refund_requested', $userRole, $reason);
            if (!$resReq['success']) {
                Response::error($resReq['message'], 400);
            }

            $resRef = OrderService::transitionStatus($conn, $orderId, 'refunded', $userRole, $reason);
            if (!$resRef['success']) {
                Response::error($resRef['message'], 400);
            }

            Security::logAudit('ORDER_REFUNDED', "Order #$orderId refunded. Reason: $reason. Authorizer: " . ($_SESSION['username'] ?? 'admin'));

            Response::success("Order #$orderId successfully refunded and inventory/loyalty restored.", [
                'order_id' => $orderId,
                'status' => 'refunded',
                'reason' => $reason
            ]);
            break;

        default:
            Response::error('Invalid action', 400);
    }
} catch (Exception $e) {
    if ($conn->connect_errno === 0) @$conn->rollback();
    Response::error($e->getMessage(), 500);
}