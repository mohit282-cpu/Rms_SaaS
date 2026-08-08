<?php
// place-order.php - Hardened Order Processing Engine (Server-Side Price Validation & Transaction Protection)
require_once 'config.php';

// Rate Limit: Max 10 order attempts per minute per client
RateLimiter::enforce('place_order', 10, 60);

// Enforce CSRF verification for POST submissions
CSRF::requireValidToken();

$conn = getDBConnection();

if ($conn === null) {
    Response::error('Database connection failed', 500);
}

$tenantId = TenantContext::getTenantId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: menu.php');
    exit;
}

$is_ajax = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) || 
           (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
           isset($_POST['ajax']);

// ENFORCE SESSION LOCKING FOR TABLE NUMBER (IDOR & Spoofing Fix)
if (!isset($_SESSION['customer_table_id']) || empty($_SESSION['customer_table_id'])) {
    if ($is_ajax) {
        Response::error('Session expired. Please scan table QR code again.', 403);
    }
    http_response_code(403);
    die('<!DOCTYPE html><html lang="en" class="h-full bg-zinc-950 text-white"><head><meta charset="UTF-8"><title>403 Session Expired</title><script src="https://cdn.tailwindcss.com"></script></head><body class="h-full flex items-center justify-center p-4 text-center"><div class="max-w-md bg-zinc-900 border border-zinc-800 p-8 rounded-3xl space-y-4"><div class="text-5xl">🔒</div><h1 class="text-xl font-black text-white">Session Expired</h1><p class="text-xs text-zinc-400">Please scan the table QR code again to place an order.</p></div></body></html>');
}

// Tenant context is mandatory (fail closed): never place an order for restaurant_id 0
if ($tenantId <= 0) {
    if ($is_ajax) {
        Response::error('Session expired. Please scan table QR code again.', 403);
    }
    http_response_code(403);
    die('<!DOCTYPE html><html lang="en" class="h-full bg-zinc-950 text-white"><head><meta charset="UTF-8"><title>403 Session Expired</title><script src="https://cdn.tailwindcss.com"></script></head><body class="h-full flex items-center justify-center p-4 text-center"><div class="max-w-md bg-zinc-900 border border-zinc-800 p-8 rounded-3xl space-y-4"><div class="text-5xl">🔒</div><h1 class="text-xl font-black text-white">Session Expired</h1><p class="text-xs text-zinc-400">Please scan the table QR code again to place an order.</p></div></body></html>');
}

$table_number = strval($_SESSION['customer_table_id']);

// Parse Payload
if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $raw_input = file_get_contents('php://input');
    $input_data = json_decode($raw_input, true) ?: [];
    $customer_name = Security::sanitize($input_data['customer_name'] ?? '');
    $notes = Security::sanitize($input_data['notes'] ?? '');
    $cart = $input_data['cart'] ?? $input_data['items'] ?? [];
} else {
    $customer_name = Security::sanitize($_POST['customer_name'] ?? '');
    $notes = Security::sanitize($_POST['notes'] ?? '');
    $cart_json = $_POST['cart_data'] ?? $_POST['cart'] ?? '[]';
    $cart = json_decode($cart_json, true) ?: [];
}

if (empty($cart) || !is_array($cart)) {
    if ($is_ajax) {
        Response::error('Your cart is empty', 400);
    }
    $_SESSION['error'] = 'Your cart is empty';
    header('Location: menu.php');
    exit;
}

// Idempotency Key Verification (Fixes RMS-021)
$idempotency_key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_POST['idempotency_key'] ?? ($input_data['idempotency_key'] ?? '');
if (!empty($idempotency_key)) {
    $idempotency_key = Security::sanitize($idempotency_key);
    $idem_stmt = $conn->prepare("SELECT id, table_number, total_amount FROM orders WHERE idempotency_key = ? AND restaurant_id = {$tenantId} LIMIT 1");
    $idem_stmt->bind_param("s", $idempotency_key);
    $idem_stmt->execute();
    $existing_order = $idem_stmt->get_result()->fetch_assoc();
    $idem_stmt->close();

    if ($existing_order) {
        if ($is_ajax) {
            Response::success('Duplicate request handled via Idempotency-Key', [
                'order_id' => $existing_order['id'],
                'order' => $existing_order
            ]);
        }
        header('Location: order-success.php?order_id=' . $existing_order['id']);
        exit;
    }
}

// SERVER-SIDE PRICE & STOCK VALIDATION (Fixes RMS-001, RMS-024, RMS-025, RMS-026, RMS-037)
$calculated_total = 0.0;
$validated_items = [];

// Constants for limits
$MAX_ITEM_QTY = 50;
$MAX_ORDER_VAL = 50000.00;

$price_stmt = $conn->prepare("SELECT id, name, price, stock_quantity, status FROM menu_items WHERE id = ? AND restaurant_id = ? FOR UPDATE");

foreach ($cart as $item) {
    $item_id = intval($item['id'] ?? 0);
    $item_qty = intval($item['quantity'] ?? 1);

    if ($item_id <= 0 || $item_qty <= 0) continue;

    if ($item_qty > $MAX_ITEM_QTY) {
        Response::error("Maximum quantity per item is limited to $MAX_ITEM_QTY", 400);
    }

    $price_stmt->bind_param("ii", $item_id, $tenantId);
    $price_stmt->execute();
    $res = $price_stmt->get_result();
    $db_item = $res->fetch_assoc();

    if (!$db_item) {
        $price_stmt->close();
        Response::error("Invalid menu item in cart (ID: $item_id)", 400);
    }

    if ($db_item['status'] === 'sold_out' || $db_item['status'] === 'inactive') {
        $price_stmt->close();
        Response::error("Item '" . $db_item['name'] . "' is currently Sold Out.", 400);
    }

    // Check available stock (Fixes RMS-025 & RMS-026)
    if (isset($db_item['stock_quantity']) && $db_item['stock_quantity'] < $item_qty) {
        $price_stmt->close();
        Response::error("Insufficient stock for '" . $db_item['name'] . "'. Available: " . $db_item['stock_quantity'], 400);
    }

    // STRICT SERVER-SIDE PRICE CALCULATION (Prevents Client Price Modification Attacks - RMS-001)
    $server_unit_price = floatval($db_item['price']);
    
    // Addon Price calculation - Validated against DB menu_addons only (client prices are NEVER trusted - RMS-001)
    $addon_extra_cost = 0.0;
    $custom_text = '';
    if (!empty($item['customizations'])) {
        $c = $item['customizations'];
        if (!empty($c['spice_level'])) {
            $custom_text .= ' (' . ucfirst(Security::sanitize($c['spice_level'])) . ')';
        }
        if (!empty($c['extras']) && is_array($c['extras'])) {
            $extra_names = [];
            foreach ($c['extras'] as $ex) {
                // Support both object payloads ({id, name}) and plain name strings
                if (is_array($ex)) {
                    $e_id = intval($ex['id'] ?? 0);
                    $e_name = trim(Security::sanitize($ex['name'] ?? ''));
                } else {
                    $e_id = 0;
                    $e_name = trim(Security::sanitize($ex));
                }
                if ($e_name === '') continue;

                // Resolve the extra price strictly from the DB catalog by ID or name (tenant-scoped).
                $e_price = 0.0;
                $addon_stmt = $conn->prepare("SELECT price FROM menu_addons WHERE status = 'active' AND restaurant_id = ? AND (id = ? OR name = ?) LIMIT 1");
                if ($addon_stmt) {
                    $addon_stmt->bind_param("iis", $tenantId, $e_id, $e_name);
                    $addon_stmt->execute();
                    $a_row = $addon_stmt->get_result()->fetch_assoc();
                    $addon_stmt->close();
                    if ($a_row) $e_price = floatval($a_row['price']);
                }

                $extra_names[] = $e_name;
                $addon_extra_cost += $e_price;
            }
            if (!empty($extra_names)) {
                $custom_text .= ' [+' . implode(', ', $extra_names) . ']';
            }
        }
    }

    $final_unit_price = $server_unit_price + $addon_extra_cost;
    $item_subtotal = $final_unit_price * $item_qty;
    $calculated_total += $item_subtotal;

    $validated_items[] = [
        'id' => $item_id,
        'name' => $db_item['name'],
        'displayName' => $db_item['name'] . $custom_text,
        'quantity' => $item_qty,
        'price' => $final_unit_price,
        'subtotal' => $item_subtotal
    ];
}
$price_stmt->close();

if (empty($validated_items)) {
    Response::error('No valid items found in cart', 400);
}

if ($calculated_total > $MAX_ORDER_VAL) {
    Response::error("Maximum total order value cannot exceed Rs. " . number_format($MAX_ORDER_VAL, 2), 400);
}

try {
    // Transactional Database Insertion & Dining Session Management (Fixes RMS-022, RMS-023)
    $conn->begin_transaction();

    // 1. Get or create active dining session for this table with ROW LOCKING
    $tbl_safe = $conn->real_escape_string($table_number);
    $session_id = null;
    $batch_num = 1;

    $ds_res = $conn->query("SELECT id, running_total FROM dining_sessions WHERE table_number = '$tbl_safe' AND restaurant_id = {$tenantId} AND status = 'active' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    if ($ds_res && $ds_row = $ds_res->fetch_assoc()) {
        $session_id = intval($ds_row['id']);
        // Calculate next batch number atomically
        $b_res = $conn->query("SELECT COUNT(*) as b_cnt FROM orders WHERE dining_session_id = $session_id AND restaurant_id = {$tenantId} FOR UPDATE");
        if ($b_res && $b_row = $b_res->fetch_assoc()) {
            $batch_num = intval($b_row['b_cnt']) + 1;
        }
    } else {
        // Create new active dining session
        $sess_token = bin2hex(random_bytes(16));
        $ds_stmt = $conn->prepare("INSERT INTO dining_sessions (restaurant_id, session_token, table_number, customer_name, status, running_total) VALUES (?, ?, ?, ?, 'active', 0.00)");
        if ($ds_stmt) {
            $c_name_ds = !empty($customer_name) ? $customer_name : 'Guest';
            $ds_stmt->bind_param("isss", $tenantId, $sess_token, $table_number, $c_name_ds);
            $ds_stmt->execute();
            $session_id = $conn->insert_id;
            $ds_stmt->close();
        }
    }

    // Insert order batch
    $stmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_number, customer_name, notes, status, total_amount, payment_status, dining_session_id, batch_number, idempotency_key) VALUES (?, ?, ?, ?, 'new', ?, 'pending', ?, ?, ?)");
    // Insert NULL (not '') when no idempotency key is supplied so the UNIQUE index
    // on idempotency_key does not reject repeat orders.
    $insert_idem_key = ($idempotency_key !== '') ? $idempotency_key : null;
    $stmt->bind_param("isssdiis", $tenantId, $table_number, $customer_name, $notes, $calculated_total, $session_id, $batch_num, $insert_idem_key);
    
    if (!$stmt->execute()) {
        throw new Exception("Order insertion failed: " . $stmt->error);
    }
    
    $order_id = $conn->insert_id;
    $stmt->close();

    // Update running total of dining session
    if ($session_id) {
        $conn->query("UPDATE dining_sessions SET running_total = running_total + $calculated_total, status = 'active' WHERE id = $session_id AND restaurant_id = {$tenantId}");
    }

    // Update table status to occupied
    $conn->query("UPDATE tables SET status = 'occupied', guest_count = GREATEST(guest_count, 1) WHERE table_number = '$tbl_safe' AND restaurant_id = {$tenantId}");

    $item_stmt = $conn->prepare("INSERT INTO order_items (restaurant_id, order_id, menu_item_id, quantity, price) VALUES (?, ?, ?, ?, ?)");
    
    foreach ($validated_items as $v_item) {
        $item_stmt->bind_param("iiiid", $tenantId, $order_id, $v_item['id'], $v_item['quantity'], $v_item['price']);
        if (!$item_stmt->execute()) {
            throw new Exception("Item insertion failed: " . $item_stmt->error);
        }
    }
    $item_stmt->close();
    
    $conn->commit();
    
    // Save Session Tracker
    $_SESSION['last_order'] = [
        'id' => $order_id,
        'table_number' => $table_number,
        'customer_name' => $customer_name,
        'total' => $calculated_total,
        'status' => 'new',
        'items' => $validated_items
    ];
    
    if ($is_ajax) {
        Response::success('Order placed successfully', [
            'order_id' => $order_id,
            'order' => [
                'id' => $order_id,
                'table_number' => $table_number,
                'total' => $calculated_total
            ]
        ]);
    }
    
    header('Location: order-success.php?order_id=' . $order_id);
    exit;
    
} catch (Throwable $e) {
    if (isset($conn) && $conn->in_transaction()) {
        $conn->rollback();
    }
    if ($is_ajax) {
        Response::error('Failed to place order: ' . $e->getMessage(), 500);
    }
    $_SESSION['error'] = 'Failed to place order: ' . $e->getMessage();
    header('Location: checkout.php');
    exit;
}
