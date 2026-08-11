<?php
// api/table-payment.php - Table Payment Processing (Billing Settlement Inside Floor & Tables)
// Handles: Customer lookup/creation, Loyalty redemption validation, Payment processing, Order settlement
// All loyalty rules are enforced server-side through LoyaltyService (single source of truth).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/LoyaltyService.php';
require_once __DIR__ . '/../helpers/OrderService.php';

// Release session lock for concurrent polling
session_write_close();

// Staff authentication with tenant context
$tenantId = (int)AuthorizationService::requireStaffApi();

// Enforce CSRF token verification on financial/state-changing POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();
}

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$inTx = false;

// Action-level Financial Authorization Guards (Authentication IS NOT Authorization)
if (in_array($action, ['process_payment', 'split_bill'], true)) {
    AuthorizationService::requirePermissionApi('payments.settle');
} elseif ($action === 'refund') {
    AuthorizationService::requirePermissionApi('payments.refund');
} elseif ($action === 'ncr' || $action === 'apply_ncr') {
    AuthorizationService::requirePermissionApi('payments.ncr');
}

try {
    switch ($action) {

        case 'search_customer':
            // Search customer by phone within tenant
            $phone = Security::sanitize(trim($_POST['phone'] ?? $_GET['phone'] ?? ''));
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
            }
            Response::success('Customer not found - can create new', ['exists' => false, 'phone' => $phone]);
            break;

        case 'create_customer':
            // Create new customer within tenant
            $phone = Security::sanitize(trim($_POST['phone'] ?? ''));
            $name = Security::sanitize(trim($_POST['name'] ?? ''));
            $email = Security::sanitize(trim($_POST['email'] ?? ''));
            if (!$phone || !$name) {
                Response::error('Name and phone are required', 400);
            }
            $stmt = $conn->prepare("SELECT id FROM customers WHERE restaurant_id = ? AND phone = ? LIMIT 1");
            $stmt->bind_param("is", $tenantId, $phone);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $stmt->close();
                Response::error('Customer already exists with this phone number', 409);
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO customers (restaurant_id, name, phone, email) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $tenantId, $name, $phone, $email);
            if (!$stmt->execute()) {
                $stmt->close();
                Response::error('Failed to create customer: ' . $conn->error, 500);
            }
            $customerId = $stmt->insert_id;
            $stmt->close();

            Security::logAudit('CUSTOMER_CREATED', "Created customer #$customerId: $name ($phone) from table billing");

            Response::success('Customer created', [
                'customer' => ['id' => $customerId, 'name' => $name, 'phone' => $phone, 'email' => $email, 'total_visits' => 0, 'total_spent' => '0.00', 'loyalty_points' => 0, 'tier' => 'Bronze'],
                'exists' => false
            ], 201);
            break;

        case 'get_loyalty':
            // Fetch customer loyalty balance with settings
            $customerId = (int)($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);
            if ($customerId <= 0) {
                Response::error('Customer ID required', 400);
            }
            $customer = LoyaltyService::customer($conn, $tenantId, $customerId);
            if (!$customer) {
                Response::error('Customer not found', 404);
            }
            // Expire past lots first so the returned balance is current
            LoyaltyService::sweepExpiredPoints($conn, $tenantId, $customerId);
            $customer = LoyaltyService::customer($conn, $tenantId, $customerId);
            $settings = LoyaltyService::settings($conn, $tenantId);
            $points = (int)$customer['loyalty_points'];

            Response::success('Loyalty info', [
                'customer_id' => (int)$customer['id'],
                'customer_name' => $customer['name'],
                'loyalty_points' => $points,
                'points_value' => LoyaltyService::pointsValue($settings, $points),
                'point_value' => (float)$settings['point_value'],
                'conversion_rate' => (float)$settings['point_value'],
                'loyalty_enabled' => (int)$settings['is_enabled'],
                'settings' => loyaltySettingsPayload($settings)
            ]);
            break;

        case 'apply_loyalty':
            // Authoritative redemption validation + capping (preview only, actual deduction on payment)
            $customerId = (int)($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);
            $points = (int)($_POST['points'] ?? $_GET['points'] ?? 0);
            $orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
            if ($customerId <= 0) {
                Response::error('Customer is required', 400);
            }
            if ($points <= 0) {
                Response::error('Enter points to redeem', 400);
            }

            $preDiscountTotal = max(0.0, round(floatval($_POST['bill_total'] ?? $_GET['bill_total'] ?? 0), 2));
            if ($orderId > 0) {
                $preBill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, 0, false);
                $preDiscountTotal = (float)$preBill['grand_total'];
            }

            $settings = LoyaltyService::settings($conn, $tenantId);
            $redemption = LoyaltyService::calculateRedemption($conn, $tenantId, $customerId, $points, $preDiscountTotal);

            Response::success($redemption['ok'] ? 'Redemption valid' : 'Redemption validation failed', [
                'valid' => $redemption['ok'],
                'requested_points' => $points,
                'points_redeemed' => $redemption['points'],
                'points' => $redemption['points'],
                'discount_value' => $redemption['discount'],
                'discount' => $redemption['discount'],
                'available_points' => $redemption['available_points'],
                'max_allowed_points' => $redemption['max_allowed_points'],
                'remaining_points' => max(0, $redemption['available_points'] - $redemption['points']),
                'point_value' => $redemption['point_value'],
                'pre_discount_total' => $preDiscountTotal,
                'message' => $redemption['message'],
                'settings' => loyaltySettingsPayload($settings)
            ]);
            break;

        case 'calculate_bill':
            // Calculate authoritative bill totals for order (server-capped loyalty redemption)
            $orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
            $pointsToRedeem = (int)($_POST['loyalty_points'] ?? $_GET['loyalty_points'] ?? 0);
            $customerId = (int)($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);
            $isNCR = !empty($_POST['is_ncr']) || !empty($_GET['is_ncr']);

            if ($orderId <= 0) {
                Response::error('Order ID is required', 400);
            }

            $orderStmt = $conn->prepare("SELECT o.customer_id, o.ncr_amount FROM orders o WHERE o.id = ? AND o.restaurant_id = ? LIMIT 1");
            $orderStmt->bind_param("ii", $orderId, $tenantId);
            $orderStmt->execute();
            $orderRow = $orderStmt->get_result()->fetch_assoc();
            $orderStmt->close();
            if (!$orderRow) {
                Response::error('Order not found', 404);
            }

            // If the caller does not supply a customer, fall back to the order's linked customer
            if ($customerId <= 0) {
                $customerId = (int)($orderRow['customer_id'] ?? 0);
            }
            if (!$isNCR && (float)$orderRow['ncr_amount'] > 0) {
                $isNCR = true;
            }

            $redemptionInfo = null;
            $cappedPoints = 0;
            if ($pointsToRedeem > 0) {
                if ($customerId <= 0) {
                    $redemptionInfo = ['ok' => false, 'message' => 'A customer must be linked to redeem loyalty points'];
                    $cappedPoints = 0;
                } else {
                    $preBill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, 0, $isNCR);
                    $redemptionInfo = LoyaltyService::calculateRedemption($conn, $tenantId, $customerId, $pointsToRedeem, (float)$preBill['grand_total']);
                    $cappedPoints = $redemptionInfo['ok'] ? $redemptionInfo['points'] : 0;
                }
            }

            $bill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, $cappedPoints, $isNCR);

            Response::success('Bill calculated successfully', [
                'bill' => $bill,
                'redemption' => $redemptionInfo
            ]);
            break;

        case 'get_payment_qr':
            // Generate payment QR data for digital payment
            $orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
            $pointsToRedeem = (int)($_POST['loyalty_points'] ?? $_GET['loyalty_points'] ?? 0);
            if ($orderId <= 0) {
                Response::error('Order ID required', 400);
            }
            $orderStmt = $conn->prepare("SELECT o.* FROM orders o WHERE o.id = ? AND o.restaurant_id = ? AND o.payment_status = 'pending' LIMIT 1");
            $orderStmt->bind_param("ii", $orderId, $tenantId);
            $orderStmt->execute();
            $order = $orderStmt->get_result()->fetch_assoc();
            $orderStmt->close();
            if (!$order) {
                Response::error('Order not found or already paid', 404);
            }

            $bill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, $pointsToRedeem, false);
            $amount = (float)$bill['grand_total'];

            $qrSettings = RestaurantSettingsService::getPaymentSettings($conn, $tenantId);

            $paymentUrl = 'https://pay.example.com/pay?order=' . $orderId . '&amount=' . urlencode(number_format($amount, 2, '.', '')) . '&tenant=' . $tenantId;

            Response::success('Payment QR data', [
                'payment_url' => $paymentUrl,
                'amount' => $amount,
                'order_id' => $orderId,
                'settings' => [
                    'restaurant_name' => $qrSettings['restaurant_name'],
                    'payment_note' => $qrSettings['payment_note'],
                    'qr_code_image' => $qrSettings['qr_code_image'] ?? ''
                ]
            ]);
            break;

        case 'process_payment':
            // Main payment processing - atomic, idempotent, server-authoritative
            $tableNumber = Security::sanitize(trim($_POST['table_number'] ?? ''));
            $orderId = (int)($_POST['order_id'] ?? 0);
            $paymentMethod = Security::sanitize($_POST['payment_method'] ?? '');
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $loyaltyPointsRequested = (int)($_POST['loyalty_points_redeemed'] ?? $_POST['loyalty_points'] ?? 0);
            $cashReceived = max(0.0, round(floatval($_POST['cash_received'] ?? 0), 2));

            if ($orderId <= 0 || !$paymentMethod) {
                Response::error('Missing required parameters', 400);
            }
            $allowedMethods = ['cash', 'card', 'digital', 'digital_qr'];
            if (!in_array($paymentMethod, $allowedMethods, true)) {
                Response::error('Invalid payment method', 400);
            }
            $gatewayName = ($paymentMethod === 'digital') ? 'digital_qr' : $paymentMethod;

            $conn->begin_transaction();
            $inTx = true;
            try {
                // 1. Lock the order row (tenant-scoped) to serialize concurrent payment attempts
                $orderStmt = $conn->prepare("
                    SELECT o.*, t.id AS table_rid
                    FROM orders o
                    LEFT JOIN tables t ON t.table_number = o.table_number AND t.restaurant_id = o.restaurant_id
                    WHERE o.id = ? AND o.restaurant_id = ?
                    FOR UPDATE
                ");
                $orderStmt->bind_param("ii", $orderId, $tenantId);
                $orderStmt->execute();
                $order = $orderStmt->get_result()->fetch_assoc();
                $orderStmt->close();

                if (!$order) {
                    throw new Exception('Order not found or does not belong to this restaurant');
                }

                // 2. Idempotent retry: if already paid, return the existing settlement (no double charge)
                if (strtolower($order['payment_status']) === 'paid') {
                    $txStmt = $conn->prepare("SELECT transaction_id, amount FROM payment_transactions WHERE order_id = ? AND restaurant_id = ? AND status = 'paid' ORDER BY id DESC LIMIT 1");
                    $txStmt->bind_param("ii", $orderId, $tenantId);
                    $txStmt->execute();
                    $existingTx = $txStmt->get_result()->fetch_assoc();
                    $txStmt->close();
                    $conn->commit();
                    $inTx = false;
                    Response::success('This bill has already been settled', [
                        'idempotent' => true,
                        'transaction_id' => $existingTx['transaction_id'] ?? '',
                        'order_id' => $orderId,
                        'table_number' => $order['table_number'],
                        'grand_total' => floatval($existingTx['amount'] ?? 0),
                        'payment_status' => 'paid'
                    ]);
                }

                if (strtolower($order['payment_status']) !== 'pending') {
                    throw new Exception('Order is not in a payable state');
                }
                if (strtolower($order['status']) === 'cancelled') {
                    throw new Exception('Cannot pay for a cancelled order');
                }

                // 3. Resolve customer (POST wins, fall back to order link) - tenant scoped
                $effectiveCustomerId = $customerId > 0 ? $customerId : (int)($order['customer_id'] ?? 0);
                $loyaltyCustomer = null;
                if ($effectiveCustomerId > 0) {
                    $loyaltyCustomer = LoyaltyService::customer($conn, $tenantId, $effectiveCustomerId);
                    if (!$loyaltyCustomer) {
                        throw new Exception('Customer not found in this restaurant');
                    }
                    $effectiveCustomerId = (int)$loyaltyCustomer['id'];
                }

                $isNCR = (float)$order['ncr_amount'] > 0;

                // 4. Pre-loyalty bill (server authoritative) to derive the redemption base
                $preBill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, 0, $isNCR);
                $preDiscountTotal = (float)$preBill['grand_total'];

                // 5. Validate loyalty redemption strictly
                $redeemPoints = 0;
                $loyaltyDiscount = 0.0;
                if ($loyaltyPointsRequested > 0) {
                    if ($effectiveCustomerId <= 0) {
                        throw new Exception('A customer must be linked to redeem loyalty points');
                    }
                    $redemption = LoyaltyService::calculateRedemption($conn, $tenantId, $effectiveCustomerId, $loyaltyPointsRequested, $preDiscountTotal);
                    if (!$redemption['ok']) {
                        throw new Exception($redemption['message']);
                    }
                    $redeemPoints = $redemption['points'];
                    $loyaltyDiscount = $redemption['discount'];
                }

                // 6. Final authoritative bill with validated redemption
                $bill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, $redeemPoints, $isNCR);
                $grandTotal = round((float)$bill['grand_total'], 2);
                if ($grandTotal < 0) {
                    throw new Exception('Invalid bill total');
                }

                // 7. Validate cash received (exact-change check on server)
                if ($paymentMethod === 'cash' && $cashReceived + 0.001 < $grandTotal) {
                    throw new Exception('Cash received (Rs. ' . number_format($cashReceived, 2) . ') is less than amount due (Rs. ' . number_format($grandTotal, 2) . ')');
                }

                $activeShift = RegisterShiftService::getActiveShift($conn, $tenantId);
                $activeShiftId = $activeShift ? (int)$activeShift['id'] : null;

                $transactionId = 'PAY-' . strtoupper(bin2hex(random_bytes(6)));
                $referenceId = strtoupper($paymentMethod) . '-' . $transactionId . ($cashReceived > 0 ? ':CASH' . number_format($cashReceived, 2, '.', '') : '');
                $payStmt = $conn->prepare("INSERT INTO payment_transactions (restaurant_id, shift_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES (?, ?, ?, ?, ?, ?, 'paid', ?, NOW())");
                $payStmt->bind_param("iiisisds", $tenantId, $activeShiftId, $transactionId, $orderId, $gatewayName, $grandTotal, $referenceId);
                $payStmt->execute();
                $payStmt->close();

                // 9. Settle the order (guarded update prevents concurrent double settlement)
                $idemKey = 'PAY_' . $tenantId . '_' . $orderId . '_' . $transactionId;
                $updStmt = $conn->prepare("UPDATE orders SET payment_status = 'paid', payment_method = ?, status = 'completed', customer_id = ?, tax_amount = ?, service_charge_amount = ?, discount_amount = ?, ncr_amount = ?, idempotency_key = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ? AND payment_status = 'pending'");
                $updStmt->bind_param("siddddsii", $gatewayName, $effectiveCustomerId, $bill['vat'], $bill['service_charge'], $bill['discount'], $bill['ncr_amount'], $idemKey, $orderId, $tenantId);
                $updStmt->execute();
                $affected = $conn->affected_rows;
                $updStmt->close();
                if ($affected !== 1) {
                    throw new Exception('Order could not be settled (concurrent modification). Please refresh.');
                }

                $settings = LoyaltyService::settings($conn, $tenantId);

                // 10. Loyalty redemption ledger + balance update (idempotency-protected)
                if ($redeemPoints > 0) {
                    LoyaltyService::recordRedemption($conn, $tenantId, $effectiveCustomerId, $orderId, $redeemPoints, $loyaltyDiscount, "Points redeemed for order #$orderId");
                }

                // 11. Loyalty earning ledger + balance update (idempotency-protected, never duplicates)
                $pointsEarned = 0;
                if ($effectiveCustomerId > 0 && (int)$settings['is_enabled'] === 1) {
                    $pointsEarned = LoyaltyService::pointsForEligibleAmount($settings, (float)$bill['earning_eligible']);
                    if ($pointsEarned > 0) {
                        LoyaltyService::recordEarning($conn, $tenantId, $effectiveCustomerId, $orderId, $pointsEarned, "Points earned from order #$orderId");
                    }
                }

                // 12. Customer visit stats (single increment at settlement time)
                if ($effectiveCustomerId > 0) {
                    $statStmt = $conn->prepare("UPDATE customers SET total_visits = total_visits + 1, total_spent = total_spent + ?, last_visit_at = NOW() WHERE id = ? AND restaurant_id = ?");
                    $statStmt->bind_param("dii", $grandTotal, $effectiveCustomerId, $tenantId);
                    $statStmt->execute();
                    $statStmt->close();
                }

                $loyaltyBalanceAfter = 0;
                if ($effectiveCustomerId > 0) {
                    $balStmt = $conn->prepare("SELECT loyalty_points FROM customers WHERE id = ? AND restaurant_id = ?");
                    $balStmt->bind_param("ii", $effectiveCustomerId, $tenantId);
                    $balStmt->execute();
                    $balRow = $balStmt->get_result()->fetch_assoc();
                    $balStmt->close();
                    $loyaltyBalanceAfter = (int)($balRow['loyalty_points'] ?? 0);
                }

                // 13. Table workflow: paid -> cleaning -> vacant
                if (!empty($order['table_rid'])) {
                    $tblStmt = $conn->prepare("UPDATE tables SET status = 'cleaning', guest_count = 0, reserved_by = '' WHERE id = ? AND restaurant_id = ?");
                    $tblStmt->bind_param("ii", $order['table_rid'], $tenantId);
                    $tblStmt->execute();
                    $tblStmt->close();
                }

                // 14. Close dining session if present
                if (!empty($order['dining_session_id'])) {
                    $conn->query("UPDATE dining_sessions SET status = 'closed', running_total = 0 WHERE id = " . (int)$order['dining_session_id'] . " AND restaurant_id = " . (int)$tenantId);
                }

                Security::logAudit('BILL_SETTLED', "Order #$orderId settled for " . BillingService::formatMoney($grandTotal) . " via " . strtoupper($paymentMethod) . ($effectiveCustomerId ? " Customer #$effectiveCustomerId" : "") . ($redeemPoints > 0 ? " (redeemed $redeemPoints pts)" : ""));

                // 15. Order items for the success receipt
                $itemsStmt = $conn->prepare("SELECT oi.quantity, oi.price, mi.name AS item_name FROM order_items oi JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE oi.order_id = ?");
                $itemsStmt->bind_param("i", $orderId);
                $itemsStmt->execute();
                $orderItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $itemsStmt->close();

                $conn->commit();
                $inTx = false;

                Response::success('Payment processed successfully', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'payment_transaction_id' => $transactionId,
                    'table_number' => $order['table_number'],
                    'payment_method' => $paymentMethod,
                    'grand_total' => $grandTotal,
                    'cash_change' => $paymentMethod === 'cash' ? round($cashReceived - $grandTotal, 2) : 0,
                    'customer_id' => $effectiveCustomerId,
                    'loyalty_points_redeemed' => $redeemPoints,
                    'loyalty_discount' => $loyaltyDiscount,
                    'loyalty_points_earned' => $pointsEarned,
                    'loyalty_balance_after' => $loyaltyBalanceAfter,
                    'items' => $orderItems,
                    'bill' => $bill
                ]);

            } catch (Throwable $e) {
                if ($inTx) {
                    $conn->rollback();
                }
                Response::error($e->getMessage(), 400);
            }
            break;

        case 'split_bill':
            // Split Bill Processing (partial or full settlement)
            $orderId = (int)($_POST['order_id'] ?? 0);
            $tableNumber = Security::sanitize(trim($_POST['table_number'] ?? ''));
            $paymentMethod = Security::sanitize($_POST['payment_method'] ?? 'cash');
            $splitAmount = round(floatval($_POST['split_amount'] ?? 0), 2);
            $customerId = (int)($_POST['customer_id'] ?? 0);

            if ($orderId <= 0 || $splitAmount <= 0) {
                Response::error('Order ID and valid positive split amount required', 400);
            }
            $allowedMethods = ['cash', 'card', 'digital', 'digital_qr'];
            if (!in_array($paymentMethod, $allowedMethods, true)) {
                Response::error('Invalid payment method', 400);
            }

            $conn->begin_transaction();
            $inTx = true;
            try {
                $orderStmt = $conn->prepare("SELECT o.*, t.id AS table_rid FROM orders o LEFT JOIN tables t ON t.table_number = o.table_number AND t.restaurant_id = o.restaurant_id WHERE o.id = ? AND o.restaurant_id = ? FOR UPDATE");
                $orderStmt->bind_param("ii", $orderId, $tenantId);
                $orderStmt->execute();
                $order = $orderStmt->get_result()->fetch_assoc();
                $orderStmt->close();

                if (!$order) {
                    throw new Exception('Order not found or does not belong to this restaurant');
                }
                if (strtolower($order['payment_status']) === 'paid') {
                    throw new Exception('This bill is already fully settled');
                }
                if (strtolower($order['status']) === 'cancelled') {
                    throw new Exception('Cannot split payment on a cancelled order');
                }

                $grandTotal = (float)BillingService::calculateOrderBill($conn, $tenantId, $orderId, 0, (float)$order['ncr_amount'] > 0)['grand_total'];

                $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS paid_sum FROM payment_transactions WHERE order_id = ? AND restaurant_id = ? AND status = 'paid'");
                $paidStmt->bind_param("ii", $orderId, $tenantId);
                $paidStmt->execute();
                $alreadyPaid = round(floatval($paidStmt->get_result()->fetch_assoc()['paid_sum'] ?? 0), 2);
                $paidStmt->close();

                $remainingBalance = max(0.0, round($grandTotal - $alreadyPaid, 2));
                if ($splitAmount > $remainingBalance + 0.01) {
                    throw new Exception("Split amount (" . BillingService::formatMoney($splitAmount) . ") exceeds remaining balance (" . BillingService::formatMoney($remainingBalance) . ")");
                }

                $activeShift = RegisterShiftService::getActiveShift($conn, $tenantId);
                $activeShiftId = $activeShift ? (int)$activeShift['id'] : null;

                $txnId = 'SPLIT-' . strtoupper(bin2hex(random_bytes(6)));
                $gatewayName = ($paymentMethod === 'digital') ? 'digital_qr' : $paymentMethod;
                $payStmt = $conn->prepare("INSERT INTO payment_transactions (restaurant_id, shift_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES (?, ?, ?, ?, ?, ?, 'paid', ?, NOW())");
                $refId = strtoupper($paymentMethod) . '-' . $txnId;
                $payStmt->bind_param("iiisisds", $tenantId, $activeShiftId, $txnId, $orderId, $gatewayName, $splitAmount, $refId);
                $payStmt->execute();
                $payStmt->close();

                $newTotalPaid = round($alreadyPaid + $splitAmount, 2);
                $newRemaining = max(0.0, round($grandTotal - $newTotalPaid, 2));

                if ($newRemaining <= 0.01) {
                    $updStmt = $conn->prepare("UPDATE orders SET payment_status = 'paid', payment_method = ?, status = 'completed', customer_id = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                    $updStmt->bind_param("sii", $gatewayName, $customerId, $orderId, $tenantId);
                    $updStmt->execute();
                    $updStmt->close();

                    if (!empty($order['table_rid'])) {
                        $tblStmt = $conn->prepare("UPDATE tables SET status = 'cleaning', guest_count = 0, reserved_by = '' WHERE id = ? AND restaurant_id = ?");
                        $tblStmt->bind_param("ii", $order['table_rid'], $tenantId);
                        $tblStmt->execute();
                        $tblStmt->close();
                    }

                    $settings = LoyaltyService::settings($conn, $tenantId);
                    if ($customerId > 0 && (int)$settings['is_enabled'] === 1) {
                        $splitBill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, 0, false);
                        $pointsEarned = LoyaltyService::pointsForEligibleAmount($settings, (float)$splitBill['earning_eligible']);
                        if ($pointsEarned > 0) {
                            LoyaltyService::recordEarning($conn, $tenantId, $customerId, $orderId, $pointsEarned, "Points earned from split-settled order #$orderId");
                        }
                        $statStmt = $conn->prepare("UPDATE customers SET total_visits = total_visits + 1, total_spent = total_spent + ?, last_visit_at = NOW() WHERE id = ? AND restaurant_id = ?");
                        $statStmt->bind_param("dii", $grandTotal, $customerId, $tenantId);
                        $statStmt->execute();
                        $statStmt->close();
                    }

                    Security::logAudit('SPLIT_BILL_COMPLETED', "Order #$orderId fully settled via split payments. Final payment: " . BillingService::formatMoney($splitAmount));
                    $conn->commit();
                    $inTx = false;
                    Response::success('Split payment successful - Bill fully settled!', [
                        'order_id' => $orderId,
                        'split_amount' => $splitAmount,
                        'total_paid' => $grandTotal,
                        'remaining_balance' => 0.00,
                        'is_fully_paid' => true,
                        'status' => 'paid'
                    ]);
                }

                $updStmt = $conn->prepare("UPDATE orders SET payment_status = 'partially_paid', updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $updStmt->bind_param("ii", $orderId, $tenantId);
                $updStmt->execute();
                $updStmt->close();

                Security::logAudit('SPLIT_BILL_PARTIAL', "Partial payment of " . BillingService::formatMoney($splitAmount) . " received for Order #$orderId. Remaining: " . BillingService::formatMoney($newRemaining));
                $conn->commit();
                $inTx = false;
                Response::success('Partial split payment recorded', [
                    'order_id' => $orderId,
                    'split_amount' => $splitAmount,
                    'total_paid' => $newTotalPaid,
                    'remaining_balance' => $newRemaining,
                    'is_fully_paid' => false,
                    'status' => 'partially_paid'
                ]);

            } catch (Throwable $e) {
                if ($inTx) {
                    $conn->rollback();
                }
                Response::error($e->getMessage(), 400);
            }
            break;

        case 'merge_bills':
            // Merge source order items into the target (open) order
            $sourceOrderId = (int)($_POST['source_order_id'] ?? 0);
            $targetOrderId = (int)($_POST['target_order_id'] ?? 0);

            if ($sourceOrderId <= 0 || $targetOrderId <= 0 || $sourceOrderId === $targetOrderId) {
                Response::error('Valid distinct source and target orders required', 400);
            }

            $conn->begin_transaction();
            $inTx = true;
            try {
                $sStmt = $conn->prepare("SELECT id, table_number, payment_status, status FROM orders WHERE id = ? AND restaurant_id = ? FOR UPDATE");
                $sStmt->bind_param("ii", $sourceOrderId, $tenantId);
                $sStmt->execute();
                $source = $sStmt->get_result()->fetch_assoc();
                $sStmt->close();

                $tStmt = $conn->prepare("SELECT id, table_number, payment_status, status FROM orders WHERE id = ? AND restaurant_id = ? FOR UPDATE");
                $tStmt->bind_param("ii", $targetOrderId, $tenantId);
                $tStmt->execute();
                $target = $tStmt->get_result()->fetch_assoc();
                $tStmt->close();

                if (!$source || !$target) {
                    throw new Exception('Source or target order not found');
                }
                if ($source['payment_status'] === 'paid' || $target['payment_status'] === 'paid') {
                    throw new Exception('Cannot merge settled orders');
                }
                if ($source['status'] === 'cancelled' || $target['status'] === 'cancelled') {
                    throw new Exception('Cannot merge cancelled orders');
                }

                $moveStmt = $conn->prepare("UPDATE order_items SET order_id = ? WHERE order_id = ?");
                $moveStmt->bind_param("ii", $targetOrderId, $sourceOrderId);
                $moveStmt->execute();
                $moveStmt->close();

                $cancelStmt = $conn->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'pending', updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $cancelStmt->bind_param("ii", $sourceOrderId, $tenantId);
                $cancelStmt->execute();
                $cancelStmt->close();

                Security::logAudit('BILLS_MERGED', "Order #$sourceOrderId merged into order #$targetOrderId");
                $conn->commit();
                $inTx = false;

                $bill = BillingService::calculateOrderBill($conn, $tenantId, $targetOrderId, 0, false);
                Response::success('Bills merged successfully', ['target_order_id' => $targetOrderId, 'bill' => $bill]);
            } catch (Throwable $e) {
                if ($inTx) {
                    $conn->rollback();
                }
                Response::error($e->getMessage(), 400);
            }
            break;

        case 'transfer_table':
            // Transfer active order(s) from source_table to target_table
            $sourceTable = Security::sanitize(trim($_POST['source_table'] ?? $_GET['source_table'] ?? ''));
            $targetTable = Security::sanitize(trim($_POST['target_table'] ?? $_GET['target_table'] ?? ''));

            if (empty($sourceTable) || empty($targetTable) || $sourceTable === $targetTable) {
                Response::error('Valid distinct source and target table numbers required', 400);
            }

            $conn->begin_transaction();
            $inTx = true;
            try {
                // Verify source table has active orders
                $sCheck = $conn->prepare("SELECT id FROM orders WHERE restaurant_id = ? AND table_number = ? AND payment_status = 'pending' AND status != 'cancelled' FOR UPDATE");
                $sCheck->bind_param("is", $tenantId, $sourceTable);
                $sCheck->execute();
                $sRes = $sCheck->get_result();
                if ($sRes->num_rows === 0) {
                    $sCheck->close();
                    throw new Exception("No active pending orders found on Table $sourceTable to transfer");
                }
                $sCheck->close();

                // Reassign orders
                $uOrders = $conn->prepare("UPDATE orders SET table_number = ?, updated_at = NOW() WHERE restaurant_id = ? AND table_number = ? AND payment_status = 'pending' AND status != 'cancelled'");
                $uOrders->bind_param("sis", $targetTable, $tenantId, $sourceTable);
                $uOrders->execute();
                $transferredCount = $uOrders->affected_rows;
                $uOrders->close();

                // Update source table to vacant
                $uSrc = $conn->prepare("UPDATE tables SET status = 'vacant', reserved_by = NULL, guest_count = 0 WHERE restaurant_id = ? AND table_number = ?");
                $uSrc->bind_param("is", $tenantId, $sourceTable);
                $uSrc->execute();
                $uSrc->close();

                // Update target table to occupied
                $uTgt = $conn->prepare("UPDATE tables SET status = 'occupied' WHERE restaurant_id = ? AND table_number = ?");
                $uTgt->bind_param("is", $tenantId, $targetTable);
                $uTgt->execute();
                $uTgt->close();

                Security::logAudit('TABLE_TRANSFERRED', "Transferred $transferredCount active order(s) from Table $sourceTable to Table $targetTable");
                $conn->commit();
                $inTx = false;

                Response::success("Table $sourceTable orders transferred to Table $targetTable successfully", [
                    'source_table' => $sourceTable,
                    'target_table' => $targetTable,
                    'transferred_orders_count' => $transferredCount
                ]);
            } catch (Throwable $e) {
                if ($inTx) {
                    $conn->rollback();
                }
                Response::error($e->getMessage(), 400);
            }
            break;

        case 'apply_ncr':
            // Apply NCR (no charge / complimentary) waiver to an order
            $orderId = (int)($_POST['order_id'] ?? 0);
            $reason = Security::sanitize(trim($_POST['reason'] ?? ''));
            $authorizedBy = Security::sanitize(trim($_POST['authorized_by'] ?? $_SESSION['username'] ?? 'Cashier'));

            if ($orderId <= 0) {
                Response::error('Order ID required', 400);
            }
            $orderStmt = $conn->prepare("SELECT o.* FROM orders o WHERE o.id = ? AND o.restaurant_id = ? AND o.payment_status = 'pending' LIMIT 1");
            $orderStmt->bind_param("ii", $orderId, $tenantId);
            $orderStmt->execute();
            $order = $orderStmt->get_result()->fetch_assoc();
            $orderStmt->close();
            if (!$order) {
                Response::error('Order not found or already settled', 404);
            }

            $preTotal = (float)BillingService::calculateOrderBill($conn, $tenantId, $orderId, 0, false)['grand_total'];

            $updStmt = $conn->prepare("UPDATE orders SET ncr_amount = ?, ncr_reason = ?, ncr_authorized_by = ?, updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
            $updStmt->bind_param("dssii", $preTotal, $reason, $authorizedBy, $orderId, $tenantId);
            $updStmt->execute();
            $updStmt->close();

            Security::logAudit('NCR_APPLIED', "NCR applied to Order #$orderId (" . BillingService::formatMoney($preTotal) . ") by $authorizedBy: $reason");

            $bill = BillingService::calculateOrderBill($conn, $tenantId, $orderId, 0, true);
            Response::success('NCR applied successfully', ['bill' => $bill]);
            break;

        case 'process_refund':
            // Refund a paid order: reverse loyalty ledger, create refund record, update order
            $orderId = (int)($_POST['order_id'] ?? 0);
            $reason = Security::sanitize(trim($_POST['reason'] ?? 'Refund requested'));

            if ($orderId <= 0) {
                Response::error('Order ID required', 400);
            }

            $conn->begin_transaction();
            $inTx = true;
            try {
                $orderStmt = $conn->prepare("SELECT o.* FROM orders o WHERE o.id = ? AND o.restaurant_id = ? FOR UPDATE");
                $orderStmt->bind_param("ii", $orderId, $tenantId);
                $orderStmt->execute();
                $order = $orderStmt->get_result()->fetch_assoc();
                $orderStmt->close();

                if (!$order) {
                    throw new Exception('Order not found');
                }
                if (strtolower($order['payment_status']) !== 'paid') {
                    throw new Exception('Only paid orders can be refunded');
                }

                $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS paid_sum FROM payment_transactions WHERE order_id = ? AND restaurant_id = ? AND status = 'paid'");
                $paidStmt->bind_param("ii", $orderId, $tenantId);
                $paidStmt->execute();
                $paidTotal = round(floatval($paidStmt->get_result()->fetch_assoc()['paid_sum'] ?? 0), 2);
                $paidStmt->close();

                if ($paidTotal <= 0) {
                    throw new Exception('No paid payment found for this order');
                }

                // 1. Reverse loyalty earn/redeem and restore customer balances (never deletes ledger rows)
                OrderService::processOrderLoyaltyReversal($conn, $orderId, $tenantId);

                // 2. Create refund payment record
                $activeShift = RegisterShiftService::getActiveShift($conn, $tenantId);
                $activeShiftId = $activeShift ? (int)$activeShift['id'] : null;

                $refundTxn = 'RFND-' . strtoupper(bin2hex(random_bytes(6)));
                $originalMethod = strtolower($order['payment_method'] ?? 'cash');
                $refReason = strtoupper($originalMethod) . ':Refund-' . substr($reason, 0, 80);
                $rfStmt = $conn->prepare("INSERT INTO payment_transactions (restaurant_id, shift_id, transaction_id, order_id, gateway_name, amount, status, reference_id, created_at) VALUES (?, ?, ?, ?, ?, ?, 'refunded', ?, NOW())");
                $rfStmt->bind_param("iiisds", $tenantId, $activeShiftId, $refundTxn, $orderId, $originalMethod, $paidTotal, $refReason);
                $rfStmt->execute();
                $rfStmt->close();

                // 3. Mark order refunded
                $updStmt = $conn->prepare("UPDATE orders SET payment_status = 'refunded', updated_at = NOW() WHERE id = ? AND restaurant_id = ?");
                $updStmt->bind_param("ii", $orderId, $tenantId);
                $updStmt->execute();
                $updStmt->close();

                // 4. Reverse customer spend/visit stats
                if (!empty($order['customer_id'])) {
                    $refundCustId = (int)$order['customer_id'];
                    $statStmt = $conn->prepare("UPDATE customers SET total_visits = GREATEST(0, total_visits - 1), total_spent = GREATEST(0, total_spent - ?) WHERE id = ? AND restaurant_id = ?");
                    $statStmt->bind_param("dii", $paidTotal, $refundCustId, $tenantId);
                    $statStmt->execute();
                    $statStmt->close();
                }

                Security::logAudit('BILL_REFUNDED', "Order #$orderId refunded for " . BillingService::formatMoney($paidTotal) . ": " . substr($reason, 0, 120));
                $conn->commit();
                $inTx = false;

                Response::success('Refund processed successfully', [
                    'refund_transaction_id' => $refundTxn,
                    'order_id' => $orderId,
                    'amount_refunded' => $paidTotal,
                    'payment_status' => 'refunded'
                ]);
            } catch (Throwable $e) {
                if ($inTx) {
                    $conn->rollback();
                }
                Response::error($e->getMessage(), 400);
            }
            break;

        default:
            Response::error('Invalid action', 400);
    }
} catch (Throwable $e) {
    if ($conn && $inTx) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    Response::error('Server error: ' . $e->getMessage(), 500);
}

/**
 * Compact settings payload shared across loyalty responses.
 */
function loyaltySettingsPayload(array $settings): array {
    return [
        'is_enabled' => (int)$settings['is_enabled'],
        'point_value' => (float)$settings['point_value'],
        'earning_points' => (int)($settings['earning_points'] ?? 1),
        'earn_spend_amount' => (float)$settings['earn_spend_amount'],
        'min_redemption_points' => (int)$settings['min_redemption_points'],
        'max_redemption_points' => (int)$settings['max_redemption_points'],
        'max_discount_percent' => (float)$settings['max_discount_percent'],
        'min_bill_amount' => (float)($settings['min_bill_amount'] ?? 0),
        'expiration_enabled' => (int)$settings['expiration_enabled'],
        'expiration_days' => (int)$settings['expiration_days'],
        'earning_basis' => $settings['earning_basis'] ?? 'subtotal_after_discounts'
    ];
}
