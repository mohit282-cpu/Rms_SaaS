<?php
// api/inventory.php - Inventory CRUD API Endpoint
require_once '../config.php';
$tenantId = (int)AuthorizationService::requireStaffApi();

header('Content-Type: application/json; charset=UTF-8');
$conn = getDBConnection();
if (!$conn) { echo json_encode(['success'=>false,'message'=>'Database error']); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
switch ($action) {

    // =================== INVENTORY ITEMS ===================
    case 'list_items':
        $category = intval($_GET['category_id'] ?? 0);
        $search = $conn->real_escape_string($_GET['search'] ?? '');
        $status = $conn->real_escape_string($_GET['status'] ?? 'active');
        $where = "i.restaurant_id = $tenantId AND i.status='" . $status . "'";
        if ($category > 0) $where .= " AND i.category_id=$category";
        if ($search) $where .= " AND (i.name LIKE '%$search%' OR i.barcode LIKE '%$search%')";
        $sql = "SELECT i.*, c.name as category_name, c.icon as category_icon, s.company_name as supplier_name, u.abbreviation as unit_abbr
            FROM inventory_items i
            LEFT JOIN inventory_categories c ON i.category_id=c.id AND c.restaurant_id = i.restaurant_id
            LEFT JOIN suppliers s ON i.supplier_id=s.id AND s.restaurant_id = i.restaurant_id
            LEFT JOIN inventory_units u ON i.unit_id=u.id AND u.restaurant_id = i.restaurant_id
            WHERE $where ORDER BY i.name";
        $r = $conn->query($sql);
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'items'=>$items]);
        break;

    case 'get_item':
        $id = intval($_GET['id'] ?? 0);
        $r = $conn->query("SELECT i.*, c.name as category_name, s.company_name as supplier_name, u.abbreviation as unit_abbr
            FROM inventory_items i LEFT JOIN inventory_categories c ON i.category_id=c.id AND c.restaurant_id = i.restaurant_id
            LEFT JOIN suppliers s ON i.supplier_id=s.id AND s.restaurant_id = i.restaurant_id LEFT JOIN inventory_units u ON i.unit_id=u.id AND u.restaurant_id = i.restaurant_id
            WHERE i.restaurant_id = $tenantId AND i.id=$id");
        $item = $r ? $r->fetch_assoc() : null;
        echo json_encode(['success'=>(bool)$item,'item'=>$item]);
        break;

    case 'save_item':
        CSRF::requireValidToken();
        Inventory::requireWrite('inventory');
        $id = intval($_POST['id'] ?? 0);
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $barcode = $conn->real_escape_string(trim($_POST['barcode'] ?? ''));
        $category_id = intval($_POST['category_id'] ?? 0) ?: 'NULL';
        $brand = $conn->real_escape_string(trim($_POST['brand'] ?? ''));
        $supplier_id = intval($_POST['supplier_id'] ?? 0) ?: 'NULL';
        $unit_id = intval($_POST['unit_id'] ?? 0) ?: 'NULL';
        $minimum_stock = floatval($_POST['minimum_stock'] ?? 0);
        $maximum_stock = floatval($_POST['maximum_stock'] ?? 0);
        $purchase_cost = floatval($_POST['purchase_cost'] ?? 0);
        $storage_location = $conn->real_escape_string(trim($_POST['storage_location'] ?? ''));
        $expiry_date = $conn->real_escape_string(trim($_POST['expiry_date'] ?? ''));
        $exp = $expiry_date ? "'$expiry_date'" : 'NULL';
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        $status = $conn->real_escape_string($_POST['item_status'] ?? 'active');

        if (!$name) { echo json_encode(['success'=>false,'message'=>'Item name is required']); break; }

        if ($id > 0) {
            $sql = "UPDATE inventory_items SET name='$name', barcode='$barcode', category_id=$category_id, brand='$brand',
                supplier_id=$supplier_id, unit_id=$unit_id, minimum_stock=$minimum_stock, maximum_stock=$maximum_stock,
                purchase_cost=$purchase_cost, storage_location='$storage_location', expiry_date=$exp, notes='$notes', status='$status'
                WHERE restaurant_id = $tenantId AND id=$id";
        } else {
            $sql = "INSERT INTO inventory_items (restaurant_id,name,barcode,category_id,brand,supplier_id,unit_id,current_stock,minimum_stock,maximum_stock,purchase_cost,average_cost,storage_location,expiry_date,notes,status)
                VALUES ($tenantId,'$name','$barcode',$category_id,'$brand',$supplier_id,$unit_id,0,$minimum_stock,$maximum_stock,$purchase_cost,$purchase_cost,'$storage_location',$exp,'$notes','$status')";
        }
        $ok = $conn->query($sql);
        $newId = $id > 0 ? $id : $conn->insert_id;
        Inventory::ensureItemQR($conn, $newId);
        Inventory::audit('inventory.item_save', ($id > 0 ? "Updated item #$id: $name" : "Created item #$newId: $name"));
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok ? 'Saved successfully' : $conn->error, 'id'=>$newId]);
        break;

    case 'delete_item':
        CSRF::requireValidToken();
        Inventory::requireWrite('inventory');
        $id = intval($_POST['id'] ?? 0);
        $ok = $conn->query("UPDATE inventory_items SET status='inactive' WHERE restaurant_id = $tenantId AND id=$id");
        Inventory::audit('inventory.item_delete', "Deactivated item #$id");
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok ? 'Item deactivated' : 'Failed']);
        break;

    case 'adjust_stock':
        CSRF::requireValidToken();
        $id = intval($_POST['id'] ?? 0);
        $qty = floatval($_POST['quantity'] ?? 0);
        $type = $conn->real_escape_string($_POST['type'] ?? 'adjustment');
        $direction = $conn->real_escape_string($_POST['direction'] ?? 'in');
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));

        $r = $conn->query("SELECT current_stock, average_cost FROM inventory_items WHERE restaurant_id = $tenantId AND id=$id");
        if (!$r || !$row = $r->fetch_assoc()) { echo json_encode(['success'=>false,'message'=>'Item not found']); break; }

        $before = (float)$row['current_stock'];
        $after = ($direction === 'in') ? $before + $qty : $before - $qty;
        if ($after < 0) $after = 0;

        $conn->begin_transaction();
        $conn->query("UPDATE inventory_items SET current_stock=$after WHERE restaurant_id = $tenantId AND id=$id");
        $conn->query("INSERT INTO inventory_transactions (restaurant_id,inventory_item_id,type,quantity,direction,stock_before,stock_after,unit_cost,notes,created_by)
            VALUES ($tenantId,$id,'$type',$qty,'$direction',$before,$after," . (float)$row['average_cost'] . ",'$notes','admin')");
        $conn->commit();
        Inventory::generateAlerts();
        Inventory::audit('inventory.adjust', "Stock adjusted for item #$id: $direction $qty");
        echo json_encode(['success'=>true,'message'=>'Stock adjusted','stock_after'=>$after]);
        break;

    // =================== SUPPLIERS ===================
    case 'list_suppliers':
        $status = $conn->real_escape_string($_GET['status'] ?? 'active');
        $r = $conn->query("SELECT * FROM suppliers WHERE restaurant_id = $tenantId AND status='$status' ORDER BY company_name");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'suppliers'=>$items]);
        break;

    case 'save_supplier':
        CSRF::requireValidToken();
        Inventory::requireWrite('suppliers');
        $id = intval($_POST['id'] ?? 0);
        $company = $conn->real_escape_string(trim($_POST['company_name'] ?? ''));
        $contact = $conn->real_escape_string(trim($_POST['contact_person'] ?? ''));
        $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
        $email = $conn->real_escape_string(trim($_POST['email'] ?? ''));
        $address = $conn->real_escape_string(trim($_POST['address'] ?? ''));
        $vat = $conn->real_escape_string(trim($_POST['vat_pan'] ?? ''));
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        $status = $conn->real_escape_string($_POST['supplier_status'] ?? 'active');

        if (!$company) { echo json_encode(['success'=>false,'message'=>'Company name required']); break; }

        if ($id > 0) {
            $sql = "UPDATE suppliers SET company_name='$company',contact_person='$contact',phone='$phone',email='$email',address='$address',vat_pan='$vat',notes='$notes',status='$status' WHERE restaurant_id = $tenantId AND id=$id";
        } else {
            $sql = "INSERT INTO suppliers (restaurant_id,company_name,contact_person,phone,email,address,vat_pan,notes,status) VALUES ($tenantId,'$company','$contact','$phone','$email','$address','$vat','$notes','$status')";
        }
        $ok = $conn->query($sql);
        Inventory::audit('supplier.save', ($id > 0 ? "Updated supplier #$id: $company" : "Created supplier: $company"));
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok ? 'Supplier saved' : $conn->error]);
        break;

    case 'delete_supplier':
        CSRF::requireValidToken();
        Inventory::requireWrite('suppliers');
        $id = intval($_POST['id'] ?? 0);
        $ok = $conn->query("UPDATE suppliers SET status='inactive' WHERE restaurant_id = $tenantId AND id=$id");
        Inventory::audit('supplier.delete', "Deactivated supplier #$id");
        echo json_encode(['success'=>(bool)$ok]);
        break;

    // =================== PURCHASE ORDERS ===================
    case 'list_pos':
        $status = $conn->real_escape_string($_GET['status'] ?? '');
        $where = "po.restaurant_id = $tenantId";
        if ($status) $where .= " AND po.status='$status'";
        $r = $conn->query("SELECT po.*, s.company_name as supplier_name, (SELECT COUNT(*) FROM purchase_order_items WHERE restaurant_id = po.restaurant_id AND po_id=po.id) as item_count
            FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.id AND s.restaurant_id = po.restaurant_id WHERE $where ORDER BY po.created_at DESC");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'orders'=>$items]);
        break;

    case 'get_po':
        $id = intval($_GET['id'] ?? 0);
        $r = $conn->query("SELECT po.*, s.company_name as supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.id AND s.restaurant_id = po.restaurant_id WHERE po.restaurant_id = $tenantId AND po.id=$id");
        $po = $r ? $r->fetch_assoc() : null;
        $items = [];
        if ($po) {
            $ri = $conn->query("SELECT poi.*, i.name as item_name, u.abbreviation as unit_abbr
                FROM purchase_order_items poi JOIN inventory_items i ON poi.inventory_item_id=i.id AND i.restaurant_id = poi.restaurant_id
                LEFT JOIN inventory_units u ON i.unit_id=u.id AND u.restaurant_id = i.restaurant_id WHERE poi.restaurant_id = $tenantId AND poi.po_id=$id");
            if ($ri) { while ($row = $ri->fetch_assoc()) $items[] = $row; }
        }
        echo json_encode(['success'=>(bool)$po,'order'=>$po,'items'=>$items]);
        break;

    case 'save_po':
        CSRF::requireValidToken();
        Inventory::requireWrite('purchase_orders');
        $id = intval($_POST['id'] ?? 0);
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        $expected_date = $conn->real_escape_string(trim($_POST['expected_date'] ?? ''));
        $exp = $expected_date ? "'$expected_date'" : 'NULL';
        $items = json_decode($_POST['items'] ?? '[]', true);

        if (!$supplier_id) { echo json_encode(['success'=>false,'message'=>'Select a supplier']); break; }

        // Verify the supplier belongs to the active tenant before acting on it.
        $chk = $conn->query("SELECT id FROM suppliers WHERE restaurant_id = $tenantId AND id=$supplier_id LIMIT 1");
        if (!$chk || !$chk->fetch_assoc()) { echo json_encode(['success'=>false,'message'=>'Supplier not found']); break; }

        $conn->begin_transaction();
        if ($id > 0) {
            $conn->query("UPDATE purchase_orders SET supplier_id=$supplier_id,notes='$notes',expected_date=$exp WHERE restaurant_id = $tenantId AND id=$id");
            $conn->query("DELETE FROM purchase_order_items WHERE restaurant_id = $tenantId AND po_id=$id");
        } else {
            $po_number = 'PO-' . date('Ymd') . '-' . str_pad(mt_rand(1,9999),4,'0',STR_PAD_LEFT);
            $conn->query("INSERT INTO purchase_orders (restaurant_id,po_number,supplier_id,notes,expected_date,order_date) VALUES ($tenantId,'$po_number',$supplier_id,'$notes',$exp,CURDATE())");
            $id = $conn->insert_id;
        }

        $subtotal = 0;
        foreach ($items as $item) {
            $item_id = intval($item['inventory_item_id'] ?? 0);
            $qty = floatval($item['quantity'] ?? 0);
            $cost = floatval($item['unit_cost'] ?? 0);
            $total = $qty * $cost;
            $subtotal += $total;
            if ($item_id > 0 && $qty > 0) {
                $conn->query("INSERT INTO purchase_order_items (restaurant_id,po_id,inventory_item_id,quantity,unit_cost,total_cost) VALUES ($tenantId,$id,$item_id,$qty,$cost,$total)");
            }
        }
        $tax = floatval($_POST['tax_amount'] ?? 0);
        $discount = floatval($_POST['discount_amount'] ?? 0);
        $grand = $subtotal + $tax - $discount;
        $conn->query("UPDATE purchase_orders SET subtotal=$subtotal,tax_amount=$tax,discount_amount=$discount,total_amount=$grand WHERE restaurant_id = $tenantId AND id=$id");
        $conn->commit();
        Inventory::audit('purchase_order.save', ($id > 0 ? "Updated PO #$id" : "Created PO #$id") . " for supplier #$supplier_id (total Rs.$grand)");
        echo json_encode(['success'=>true,'message'=>'Purchase Order saved','id'=>$id]);
        break;

    case 'update_po_status':
        CSRF::requireValidToken();
        Inventory::requireWrite('purchase_orders');
        $id = intval($_POST['id'] ?? 0);
        $status = $conn->real_escape_string($_POST['status'] ?? '');
        $allowed = ['draft','approved','ordered','partial','received','cancelled','completed'];
        if (!in_array($status, $allowed)) { echo json_encode(['success'=>false,'message'=>'Invalid status']); break; }
        $ok = $conn->query("UPDATE purchase_orders SET status='$status' WHERE restaurant_id = $tenantId AND id=$id");
        Inventory::audit('purchase_order.status', "PO #$id status -> $status");
        echo json_encode(['success'=>(bool)$ok]);
        break;

    // =================== GOODS RECEIVING ===================
    case 'receive_goods':
        CSRF::requireValidToken();
        Inventory::requireWrite('receiving');
        $po_id = intval($_POST['po_id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (empty($items)) { echo json_encode(['success'=>false,'message'=>'No items to receive']); break; }

        $conn->begin_transaction();
        foreach ($items as $item) {
            $inv_id = intval($item['inventory_item_id'] ?? 0);
            $received = floatval($item['received_qty'] ?? 0);
            $rejected = floatval($item['rejected_qty'] ?? 0);
            $damaged = floatval($item['damaged_qty'] ?? 0);
            $cost = floatval($item['unit_cost'] ?? 0);
            $batch = $conn->real_escape_string($item['batch_number'] ?? '');
            $expiry = $conn->real_escape_string($item['expiry_date'] ?? '');
            $exp = $expiry ? "'$expiry'" : 'NULL';
            $invoice = $conn->real_escape_string($item['invoice_number'] ?? '');

            if ($inv_id <= 0 || $received <= 0) continue;

            // Verify the inventory item belongs to the active tenant.
            $itemChk = $conn->query("SELECT id FROM inventory_items WHERE restaurant_id = $tenantId AND id=$inv_id LIMIT 1");
            if (!$itemChk || !$itemChk->fetch_assoc()) continue;

            // Get supplier from PO (must belong to active tenant)
            $sup = 0;
            if ($po_id > 0) {
                $sr = $conn->query("SELECT supplier_id FROM purchase_orders WHERE restaurant_id = $tenantId AND id=$po_id");
                if ($sr && $srow = $sr->fetch_assoc()) $sup = (int)$srow['supplier_id'];
            }

            // Insert goods receipt
            $conn->query("INSERT INTO goods_receipts (restaurant_id,po_id,supplier_id,inventory_item_id,received_qty,rejected_qty,damaged_qty,unit_cost,batch_number,expiry_date,invoice_number)
                VALUES ($tenantId," . ($po_id ?: 'NULL') . "," . ($sup ?: 'NULL') . ",$inv_id,$received,$rejected,$damaged,$cost,'$batch',$exp,'$invoice')");

            // Update stock
            $r = $conn->query("SELECT current_stock, average_cost FROM inventory_items WHERE restaurant_id = $tenantId AND id=$inv_id");
            if ($r && $row = $r->fetch_assoc()) {
                $before = (float)$row['current_stock'];
                $after = $before + $received;
                // Weighted average cost
                $oldCost = (float)$row['average_cost'];
                $avgCost = ($before * $oldCost + $received * $cost) / max($after, 0.001);
                $conn->query("UPDATE inventory_items SET current_stock=$after, average_cost=$avgCost, purchase_cost=$cost, batch_number='$batch', expiry_date=$exp WHERE restaurant_id = $tenantId AND id=$inv_id");
                $conn->query("INSERT INTO inventory_transactions (restaurant_id,inventory_item_id,type,quantity,direction,stock_before,stock_after,unit_cost,reference_type,reference_id,notes,created_by)
                    VALUES ($tenantId,$inv_id,'purchase',$received,'in',$before,$after,$cost,'purchase_order',$po_id,'Goods received','admin')");
            }

            // Update PO line item received qty
            if ($po_id > 0) {
                $conn->query("UPDATE purchase_order_items SET received_qty=received_qty+$received, rejected_qty=rejected_qty+$rejected WHERE restaurant_id = $tenantId AND po_id=$po_id AND inventory_item_id=$inv_id");
            }
        }

        // Check if PO is fully received
        if ($po_id > 0) {
            $chk = $conn->query("SELECT SUM(quantity) as total_qty, SUM(received_qty) as total_recv FROM purchase_order_items WHERE restaurant_id = $tenantId AND po_id=$po_id");
            if ($chk && $crow = $chk->fetch_assoc()) {
                $newSt = ((float)$crow['total_recv'] >= (float)$crow['total_qty']) ? 'received' : 'partial';
                $conn->query("UPDATE purchase_orders SET status='$newSt' WHERE restaurant_id = $tenantId AND id=$po_id");
            }
        }
        $conn->commit();
        Inventory::generateAlerts();
        Inventory::audit('goods.receive', "Goods received against PO #$po_id");
        echo json_encode(['success'=>true,'message'=>'Goods received successfully']);
        break;

    // =================== WASTE ===================
    case 'save_waste':
        CSRF::requireValidToken();
        Inventory::requireWrite('waste');
        $inv_id = intval($_POST['inventory_item_id'] ?? 0);
        $qty = floatval($_POST['quantity'] ?? 0);
        $reason = $conn->real_escape_string($_POST['reason'] ?? 'kitchen_waste');
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));

        if ($inv_id <= 0 || $qty <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid item or quantity']); break; }

        $r = $conn->query("SELECT current_stock, average_cost FROM inventory_items WHERE restaurant_id = $tenantId AND id=$inv_id");
        if (!$r || !$row = $r->fetch_assoc()) { echo json_encode(['success'=>false,'message'=>'Item not found']); break; }

        $before = (float)$row['current_stock'];
        $cost = (float)$row['average_cost'];
        $after = max(0, $before - $qty);
        $totalCost = $qty * $cost;

        $conn->begin_transaction();
        $conn->query("INSERT INTO inventory_waste (restaurant_id,inventory_item_id,quantity,reason,unit_cost,total_cost,notes) VALUES ($tenantId,$inv_id,$qty,'$reason',$cost,$totalCost,'$notes')");
        $wasteId = $conn->insert_id;
        $conn->query("UPDATE inventory_items SET current_stock=$after WHERE restaurant_id = $tenantId AND id=$inv_id");
        $conn->query("INSERT INTO inventory_transactions (restaurant_id,inventory_item_id,type,quantity,direction,stock_before,stock_after,unit_cost,reference_type,reference_id,notes,created_by)
            VALUES ($tenantId,$inv_id,'waste',$qty,'out',$before,$after,$cost,'waste',$wasteId,'$notes','admin')");
        $conn->commit();
        Inventory::generateAlerts();
        Inventory::audit('waste.save', "Waste #$wasteId recorded: item #$inv_id qty $qty (Rs.$totalCost)");
        echo json_encode(['success'=>true,'message'=>'Waste recorded','stock_after'=>$after]);
        break;

    case 'list_waste':
        Inventory::requireRead('waste');
        $r = $conn->query("SELECT w.*, i.name as item_name, COALESCE(u.abbreviation,'pcs') as unit_abbr
            FROM inventory_waste w JOIN inventory_items i ON w.inventory_item_id=i.id AND i.restaurant_id = w.restaurant_id
            LEFT JOIN inventory_units u ON i.unit_id=u.id AND u.restaurant_id = i.restaurant_id
            WHERE w.restaurant_id = $tenantId ORDER BY w.created_at DESC LIMIT 100");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'waste'=>$items]);
        break;

    // =================== RECIPES ===================
    case 'list_recipes':
        $r = $conn->query("SELECT r.*, m.name as menu_item_name, m.price as menu_price,
            (SELECT COUNT(*) FROM recipe_items WHERE restaurant_id = r.restaurant_id AND recipe_id=r.id) as ingredient_count,
            (SELECT COALESCE(SUM(ri2.quantity * ii.average_cost),0) FROM recipe_items ri2 JOIN inventory_items ii ON ri2.inventory_item_id=ii.id AND ii.restaurant_id = ri2.restaurant_id WHERE ri2.restaurant_id = r.restaurant_id AND ri2.recipe_id=r.id) as recipe_cost
            FROM recipes r JOIN menu_items m ON r.menu_item_id=m.id AND m.restaurant_id = r.restaurant_id
            WHERE r.restaurant_id = $tenantId ORDER BY m.name");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'recipes'=>$items]);
        break;

    case 'get_recipe':
        $id = intval($_GET['id'] ?? 0);
        $r = $conn->query("SELECT r.*, m.name as menu_item_name FROM recipes r JOIN menu_items m ON r.menu_item_id=m.id AND m.restaurant_id = r.restaurant_id WHERE r.restaurant_id = $tenantId AND r.id=$id");
        $recipe = $r ? $r->fetch_assoc() : null;
        $ingredients = [];
        if ($recipe) {
            $ri = $conn->query("SELECT ri.*, i.name as item_name, COALESCE(u.abbreviation,'pcs') as unit_abbr, i.average_cost
                FROM recipe_items ri JOIN inventory_items i ON ri.inventory_item_id=i.id AND i.restaurant_id = ri.restaurant_id
                LEFT JOIN inventory_units u ON COALESCE(ri.unit_id, i.unit_id)=u.id AND u.restaurant_id = ri.restaurant_id WHERE ri.restaurant_id = $tenantId AND ri.recipe_id=$id");
            if ($ri) { while ($row = $ri->fetch_assoc()) $ingredients[] = $row; }
        }
        echo json_encode(['success'=>(bool)$recipe,'recipe'=>$recipe,'ingredients'=>$ingredients]);
        break;

    case 'save_recipe':
        CSRF::requireValidToken();
        Inventory::requireWrite('recipes');
        $id = intval($_POST['id'] ?? 0);
        $menu_item_id = intval($_POST['menu_item_id'] ?? 0);
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $yield = floatval($_POST['yield_qty'] ?? 1);
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        $ingredients = json_decode($_POST['ingredients'] ?? '[]', true);

        if (!$menu_item_id) { echo json_encode(['success'=>false,'message'=>'Select a menu item']); break; }

        // Verify the menu item belongs to the active tenant before acting on it.
        $chk = $conn->query("SELECT id FROM menu_items WHERE restaurant_id = $tenantId AND id=$menu_item_id LIMIT 1");
        if (!$chk || !$chk->fetch_assoc()) { echo json_encode(['success'=>false,'message'=>'Menu item not found']); break; }

        $conn->begin_transaction();
        if ($id > 0) {
            $conn->query("UPDATE recipes SET menu_item_id=$menu_item_id, name='$name', yield_qty=$yield, notes='$notes' WHERE restaurant_id = $tenantId AND id=$id");
            $conn->query("DELETE FROM recipe_items WHERE restaurant_id = $tenantId AND recipe_id=$id");
        } else {
            $conn->query("INSERT INTO recipes (restaurant_id,menu_item_id,name,yield_qty,notes) VALUES ($tenantId,$menu_item_id,'$name',$yield,'$notes')");
            $id = $conn->insert_id;
        }

        foreach ($ingredients as $ing) {
            $iid = intval($ing['inventory_item_id'] ?? 0);
            $qty = floatval($ing['quantity'] ?? 0);
            $uid = intval($ing['unit_id'] ?? 0) ?: 'NULL';
            $inotes = $conn->real_escape_string($ing['notes'] ?? '');
            if ($iid > 0 && $qty > 0) {
                $conn->query("INSERT INTO recipe_items (restaurant_id,recipe_id,inventory_item_id,quantity,unit_id,notes) VALUES ($tenantId,$id,$iid,$qty,$uid,'$inotes')");
            }
        }
        $conn->commit();
        Inventory::audit('recipe.save', ($id > 0 ? "Updated recipe #$id" : "Created recipe #$id") . " for menu item #$menu_item_id");
        echo json_encode(['success'=>true,'message'=>'Recipe saved','id'=>$id]);
        break;

    // =================== WASTE APPROVAL ===================
    case 'approve_waste':
        CSRF::requireValidToken();
        Inventory::requireWrite('waste');
        $id = intval($_POST['id'] ?? 0);
        $action = ($_POST['approve'] ?? '1') === '1' ? 'approved' : 'rejected';
        $by = $conn->real_escape_string($_SESSION['email'] ?? $_SESSION['admin_email'] ?? 'admin');
        $ok = $conn->query("UPDATE inventory_waste SET approval_status='$action', approved_by='$by' WHERE restaurant_id = $tenantId AND id=$id");
        Inventory::audit('waste.approve', "Waste #$id marked as $action by $by");
        echo json_encode(['success'=>(bool)$ok]);
        break;

    // =================== INVENTORY CATEGORIES ===================
    case 'save_category':
        CSRF::requireValidToken();
        Inventory::requireWrite('categories');
        $id = intval($_POST['id'] ?? 0);
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $icon = $conn->real_escape_string(trim($_POST['icon'] ?? '📦'));
        $order = intval($_POST['display_order'] ?? 0);
        $status = $conn->real_escape_string($_POST['cat_status'] ?? 'active');
        if (!$name) { echo json_encode(['success'=>false,'message'=>'Category name required']); break; }
        if ($id > 0) {
            $sql = "UPDATE inventory_categories SET name='$name',description='$desc',icon='$icon',display_order=$order,status='$status' WHERE restaurant_id = $tenantId AND id=$id";
        } else {
            $sql = "INSERT INTO inventory_categories (restaurant_id,name,description,icon,display_order,status) VALUES ($tenantId,'$name','$desc','$icon',$order,'$status')";
        }
        $ok = $conn->query($sql);
        Inventory::audit('inventory.category', ($id > 0 ? "Updated category #$id: $name" : "Created category: $name"));
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok ? 'Category saved' : $conn->error]);
        break;

    case 'delete_category':
        CSRF::requireValidToken();
        Inventory::requireWrite('categories');
        $id = intval($_POST['id'] ?? 0);
        $ok = $conn->query("UPDATE inventory_categories SET status='inactive' WHERE restaurant_id = $tenantId AND id=$id");
        Inventory::audit('inventory.category', "Deactivated category #$id");
        echo json_encode(['success'=>(bool)$ok]);
        break;

    // =================== STOCK MOVEMENTS ===================
    case 'list_movements':
        $item_id = intval($_GET['item_id'] ?? 0);
        $type = $conn->real_escape_string($_GET['type'] ?? '');
        $from = $conn->real_escape_string($_GET['from'] ?? '');
        $to = $conn->real_escape_string($_GET['to'] ?? '');
        $where = "i.restaurant_id = $tenantId";
        if ($item_id > 0) $where .= " AND t.inventory_item_id=$item_id";
        if ($type) $where .= " AND t.type='$type'";
        if ($from) $where .= " AND t.created_at >= '$from 00:00:00'";
        if ($to) $where .= " AND t.created_at <= '$to 23:59:59'";
        $r = $conn->query("SELECT t.*, i.name as item_name, COALESCE(u.abbreviation,'pcs') as unit_abbr
            FROM inventory_transactions t JOIN inventory_items i ON t.inventory_item_id=i.id AND i.restaurant_id = t.restaurant_id
            LEFT JOIN inventory_units u ON i.unit_id=u.id AND u.restaurant_id = i.restaurant_id WHERE $where ORDER BY t.created_at DESC LIMIT 200");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'movements'=>$items]);
        break;

    // =================== STOCK AUDIT ===================
    case 'save_audit':
        CSRF::requireValidToken();
        Inventory::requireWrite('stock_audit');
        $inv_id = intval($_POST['inventory_item_id'] ?? 0);
        $physical = floatval($_POST['physical_qty'] ?? 0);
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        $autoAdjust = ($_POST['auto_adjust'] ?? '0') === '1';

        $r = $conn->query("SELECT current_stock, average_cost FROM inventory_items WHERE restaurant_id = $tenantId AND id=$inv_id");
        if (!$r || !$row = $r->fetch_assoc()) { echo json_encode(['success'=>false,'message'=>'Item not found']); break; }

        $system = (float)$row['current_stock'];
        $variance = $physical - $system;

        $conn->begin_transaction();
        $conn->query("INSERT INTO stock_audits (restaurant_id,inventory_item_id,system_qty,physical_qty,variance,adjustment_made,notes) VALUES ($tenantId,$inv_id,$system,$physical,$variance," . ($autoAdjust?1:0) . ",'$notes')");
        $auditId = $conn->insert_id;

        if ($autoAdjust && abs($variance) > 0.001) {
            $direction = $variance > 0 ? 'in' : 'out';
            $absQty = abs($variance);
            $conn->query("UPDATE inventory_items SET current_stock=$physical WHERE restaurant_id = $tenantId AND id=$inv_id");
            $conn->query("INSERT INTO inventory_transactions (restaurant_id,inventory_item_id,type,quantity,direction,stock_before,stock_after,unit_cost,reference_type,reference_id,notes,created_by)
                VALUES ($tenantId,$inv_id,'adjustment',$absQty,'$direction',$system,$physical," . (float)$row['average_cost'] . ",'audit',$auditId,'Stock audit adjustment','admin')");
        }
        $conn->commit();
        Inventory::generateAlerts();
        Inventory::audit('stock.audit', "Audit #$auditId: item #$inv_id system=$system physical=$physical variance=$variance");
        echo json_encode(['success'=>true,'message'=>'Audit recorded','variance'=>$variance]);
        break;

    // =================== ASSETS ===================
    case 'list_assets':
        Inventory::requireRead('assets');
        $cat = intval($_GET['category_id'] ?? 0);
        $status = $conn->real_escape_string($_GET['status'] ?? '');
        $search = $conn->real_escape_string($_GET['search'] ?? '');
        $where = "a.restaurant_id = $tenantId";
        if ($cat > 0) $where .= " AND a.category_id=$cat";
        if ($status) $where .= " AND a.status='$status'";
        if ($search) $where .= " AND (a.name LIKE '%$search%' OR a.asset_code LIKE '%$search%' OR a.serial_number LIKE '%$search%')";
        $r = $conn->query("SELECT a.*, ac.name as category_name, ac.icon as category_icon, s.company_name as supplier_name
            FROM assets a LEFT JOIN asset_categories ac ON a.category_id=ac.id AND ac.restaurant_id = a.restaurant_id
            LEFT JOIN suppliers s ON a.supplier_id=s.id AND s.restaurant_id = a.restaurant_id WHERE $where ORDER BY a.name");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'assets'=>$items]);
        break;

    case 'save_asset':
        CSRF::requireValidToken();
        Inventory::requireWrite('assets');
        $id = intval($_POST['id'] ?? 0);
        $asset_code = $conn->real_escape_string(trim($_POST['asset_code'] ?? ''));
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $category_id = intval($_POST['category_id'] ?? 0) ?: 'NULL';
        $brand = $conn->real_escape_string(trim($_POST['brand'] ?? ''));
        $model = $conn->real_escape_string(trim($_POST['model'] ?? ''));
        $serial = $conn->real_escape_string(trim($_POST['serial_number'] ?? ''));
        $purchase_date = $conn->real_escape_string(trim($_POST['purchase_date'] ?? ''));
        $pdate = $purchase_date ? "'$purchase_date'" : 'NULL';
        $cost = floatval($_POST['purchase_cost'] ?? 0);
        $supplier_id = intval($_POST['supplier_id'] ?? 0) ?: 'NULL';
        $warranty = $conn->real_escape_string(trim($_POST['warranty_expiry'] ?? ''));
        $warr = $warranty ? "'$warranty'" : 'NULL';
        $location = $conn->real_escape_string(trim($_POST['assigned_location'] ?? ''));
        $employee = $conn->real_escape_string(trim($_POST['assigned_employee'] ?? ''));
        $condition = $conn->real_escape_string($_POST['condition'] ?? 'good');
        $status = $conn->real_escape_string($_POST['asset_status'] ?? 'available');
        $useful_life = intval($_POST['useful_life_months'] ?? 60);
        $residual = floatval($_POST['residual_value'] ?? 0);
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));

        if (!$name) { echo json_encode(['success'=>false,'message'=>'Asset name required']); break; }

        if ($id > 0) {
            $sql = "UPDATE assets SET name='$name',asset_code='$asset_code',category_id=$category_id,brand='$brand',model='$model',serial_number='$serial',
                purchase_date=$pdate,purchase_cost=$cost,supplier_id=$supplier_id,warranty_expiry=$warr,assigned_location='$location',
                assigned_employee='$employee',`condition`='$condition',status='$status',useful_life_months=$useful_life,residual_value=$residual,notes='$notes' WHERE restaurant_id = $tenantId AND id=$id";
        } else {
            if (!$asset_code) $asset_code = 'AST-' . date('Ymd') . '-' . str_pad(mt_rand(1,9999),4,'0',STR_PAD_LEFT);
            $qr = bin2hex(random_bytes(16));
            $sql = "INSERT INTO assets (restaurant_id,asset_code,qr_token,name,category_id,brand,model,serial_number,purchase_date,purchase_cost,supplier_id,warranty_expiry,
                assigned_location,assigned_employee,`condition`,status,useful_life_months,residual_value,current_value,notes)
                VALUES ($tenantId,'$asset_code','$qr','$name',$category_id,'$brand','$model','$serial',$pdate,$cost,$supplier_id,$warr,'$location','$employee','$condition','$status',$useful_life,$residual,$cost,'$notes')";
        }
        $ok = $conn->query($sql);
        $assetId = $id > 0 ? $id : $conn->insert_id;
        Inventory::ensureAssetQR($conn, $assetId);
        // Immutable asset lifecycle log
        $logStmt = $conn->prepare("INSERT INTO asset_logs (restaurant_id, asset_id, event_type, description, changed_by) VALUES (?,?,?,?,?)");
        $actor = $_SESSION['email'] ?? $_SESSION['admin_email'] ?? 'admin';
        $event = $id > 0 ? 'updated' : 'created';
        $desc = ($id > 0 ? "Asset #$assetId updated" : "Asset #$assetId created") . " — $name";
        $logStmt->bind_param("iisss", $tenantId, $assetId, $event, $desc, $actor);
        $logStmt->execute();
        $logStmt->close();
        Inventory::audit('asset.save', $desc);
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok ? 'Asset saved' : $conn->error, 'id'=>$assetId]);
        break;

    case 'delete_asset':
        CSRF::requireValidToken();
        Inventory::requireWrite('assets');
        $id = intval($_POST['id'] ?? 0);
        $r = $conn->query("SELECT name FROM assets WHERE restaurant_id = $tenantId AND id=$id");
        $name = ($r && $row = $r->fetch_assoc()) ? $row['name'] : "#$id";
        $ok = $conn->query("UPDATE assets SET status='disposed' WHERE restaurant_id = $tenantId AND id=$id");
        $logStmt = $conn->prepare("INSERT INTO asset_logs (restaurant_id, asset_id, event_type, description, changed_by) VALUES (?,?,?,?,?)");
        $actor = $_SESSION['email'] ?? $_SESSION['admin_email'] ?? 'admin';
        $desc = "Asset #$id disposed — $name";
        $logStmt->bind_param("iisss", $tenantId, $id, 'disposed', $desc, $actor);
        $logStmt->execute();
        $logStmt->close();
        Inventory::audit('asset.delete', $desc);
        echo json_encode(['success'=>(bool)$ok]);
        break;

    // =================== ASSET LOGS ===================
    case 'list_asset_logs':
        Inventory::requireRead('assets');
        $asset_id = intval($_GET['asset_id'] ?? 0);
        $where = $asset_id ? "AND asset_id=$asset_id" : '';
        $r = $conn->query("SELECT * FROM asset_logs WHERE restaurant_id = $tenantId $where ORDER BY created_at DESC LIMIT 200");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'logs'=>$items]);
        break;

    // =================== ASSET TRANSFERS ===================
    case 'list_transfers':
        Inventory::requireRead('transfers');
        $asset_id = intval($_GET['asset_id'] ?? 0);
        $where = $asset_id ? "AND t.asset_id=$asset_id" : '';
        $r = $conn->query("SELECT t.*, a.name as asset_name, a.asset_code FROM asset_transfers t JOIN assets a ON t.asset_id=a.id AND a.restaurant_id = t.restaurant_id WHERE t.restaurant_id = $tenantId $where ORDER BY t.transfer_date DESC LIMIT 200");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'transfers'=>$items]);
        break;

    case 'save_transfer':
        CSRF::requireValidToken();
        Inventory::requireWrite('transfers');
        $asset_id = intval($_POST['asset_id'] ?? 0);
        $from_loc = $conn->real_escape_string(trim($_POST['from_location'] ?? ''));
        $to_loc = $conn->real_escape_string(trim($_POST['to_location'] ?? ''));
        $from_emp = $conn->real_escape_string(trim($_POST['from_employee'] ?? ''));
        $to_emp = $conn->real_escape_string(trim($_POST['to_employee'] ?? ''));
        $tdate = $conn->real_escape_string(trim($_POST['transfer_date'] ?? date('Y-m-d')));
        $reason = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
        if (!$asset_id) { echo json_encode(['success'=>false,'message'=>'Select an asset']); break; }

        // Verify the asset belongs to the active tenant before acting on it.
        $chk = $conn->query("SELECT id FROM assets WHERE restaurant_id = $tenantId AND id=$asset_id LIMIT 1");
        if (!$chk || !$chk->fetch_assoc()) { echo json_encode(['success'=>false,'message'=>'Asset not found']); break; }

        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO asset_transfers (restaurant_id,asset_id,from_location,to_location,from_employee,to_employee,transfer_date,reason,transferred_by) VALUES (?,?,?,?,?,?,?,?,?)");
        $actor = $_SESSION['email'] ?? $_SESSION['admin_email'] ?? 'admin';
        $stmt->bind_param("iisssssss", $tenantId, $asset_id, $from_loc, $to_loc, $from_emp, $to_emp, $tdate, $reason, $actor);
        $ok = $stmt->execute();
        $stmt->close();

        if ($to_loc) $conn->query("UPDATE assets SET assigned_location='$to_loc' WHERE restaurant_id = $tenantId AND id=$asset_id");
        if ($to_emp) $conn->query("UPDATE assets SET assigned_employee='$to_emp' WHERE restaurant_id = $tenantId AND id=$asset_id");

        $logStmt = $conn->prepare("INSERT INTO asset_logs (restaurant_id, asset_id, event_type, description, changed_by) VALUES (?,?,?,?,?)");
        $desc = "Asset #$asset_id transferred: " . ($from_loc ?: '?') . " -> " . ($to_loc ?: '?');
        $logStmt->bind_param("iisss", $tenantId, $asset_id, 'transfer', $desc, $actor);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();
        Inventory::audit('asset.transfer', $desc);
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok ? 'Transfer recorded' : $conn->error]);
        break;

    // =================== MAINTENANCE ===================
    case 'list_maintenance':
        Inventory::requireRead('maintenance');
        $asset_id = intval($_GET['asset_id'] ?? 0);
        $where = $asset_id ? "AND m.asset_id=$asset_id" : '';
        $r = $conn->query("SELECT m.*, a.name as asset_name, a.asset_code FROM asset_maintenance m JOIN assets a ON m.asset_id=a.id AND a.restaurant_id = m.restaurant_id WHERE m.restaurant_id = $tenantId $where ORDER BY m.service_date DESC LIMIT 100");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'maintenance'=>$items]);
        break;

    case 'save_maintenance':
        CSRF::requireValidToken();
        Inventory::requireWrite('maintenance');
        $id = intval($_POST['id'] ?? 0);
        $asset_id = intval($_POST['asset_id'] ?? 0);
        $type = $conn->real_escape_string($_POST['type'] ?? 'preventive');
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $tech = $conn->real_escape_string(trim($_POST['technician'] ?? ''));
        $cost = floatval($_POST['cost'] ?? 0);
        $parts = $conn->real_escape_string(trim($_POST['parts_used'] ?? ''));
        $sdate = $conn->real_escape_string(trim($_POST['service_date'] ?? date('Y-m-d')));
        $ndate = $conn->real_escape_string(trim($_POST['next_service_date'] ?? ''));
        $ndt = $ndate ? "'$ndate'" : 'NULL';
        $mstatus = $conn->real_escape_string($_POST['maint_status'] ?? 'scheduled');
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));

        if (!$asset_id) { echo json_encode(['success'=>false,'message'=>'Select an asset']); break; }

        // Verify the asset belongs to the active tenant before acting on it.
        $chk = $conn->query("SELECT id FROM assets WHERE restaurant_id = $tenantId AND id=$asset_id LIMIT 1");
        if (!$chk || !$chk->fetch_assoc()) { echo json_encode(['success'=>false,'message'=>'Asset not found']); break; }

        if ($id > 0) {
            $sql = "UPDATE asset_maintenance SET asset_id=$asset_id,type='$type',description='$desc',technician='$tech',cost=$cost,parts_used='$parts',
                service_date='$sdate',next_service_date=$ndt,status='$mstatus',notes='$notes' WHERE restaurant_id = $tenantId AND id=$id";
        } else {
            $sql = "INSERT INTO asset_maintenance (restaurant_id,asset_id,type,description,technician,cost,parts_used,service_date,next_service_date,status,notes)
                VALUES ($tenantId,$asset_id,'$type','$desc','$tech',$cost,'$parts','$sdate',$ndt,'$mstatus','$notes')";
        }
        $ok = $conn->query($sql);
        // Maintenance affects asset status + lifecycle log
        if ($mstatus === 'completed') {
            $conn->query("UPDATE assets SET status='in_use' WHERE restaurant_id = $tenantId AND id=$asset_id");
        } elseif (in_array($mstatus, ['scheduled','in_progress'])) {
            $conn->query("UPDATE assets SET status='maintenance' WHERE restaurant_id = $tenantId AND id=$asset_id AND status='in_use'");
        }
        $logStmt = $conn->prepare("INSERT INTO asset_logs (restaurant_id, asset_id, event_type, description, changed_by) VALUES (?,?,?,?,?)");
        $actor = $_SESSION['email'] ?? $_SESSION['admin_email'] ?? 'admin';
        $desc = ($id > 0 ? "Updated maintenance #$id" : "Scheduled maintenance") . " for asset #$asset_id ($type, Rs.$cost)";
        $logStmt->bind_param("iisss", $tenantId, $asset_id, 'maintenance', $desc, $actor);
        $logStmt->execute();
        $logStmt->close();
        Inventory::audit('asset.maintenance', $desc);
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok ? 'Maintenance record saved' : $conn->error]);
        break;

    // =================== DEPRECIATION ===================
    case 'run_depreciation':
        CSRF::requireValidToken();
        Inventory::requireWrite('depreciation');
        $asset_id = intval($_POST['asset_id'] ?? 0);
        $where = $asset_id ? "AND a.id=$asset_id" : '';
        $r = $conn->query("SELECT a.id, a.purchase_cost, a.residual_value, a.useful_life_months, a.current_value,
                COALESCE(ac.depreciation_method,'straight_line') as method, ac.depreciation_rate
            FROM assets a LEFT JOIN asset_categories ac ON a.category_id=ac.id AND ac.restaurant_id = a.restaurant_id
            WHERE a.restaurant_id = $tenantId AND a.status NOT IN ('disposed','lost') $where");
        if (!$r) { echo json_encode(['success'=>false,'message'=>'Query failed']); break; }

        $period = date('Y-m-01');
        // Skip if depreciation already posted for this period (prevents double-charging)
        $dup = $conn->query("SELECT id FROM asset_depreciation WHERE restaurant_id = $tenantId AND period_date='$period' LIMIT 1");
        if ($dup && $dup->fetch_assoc()) {
            echo json_encode(['success'=>false,'message'=>"Depreciation already posted for period $period. Delete the posting to re-run."]);
            break;
        }

        $conn->begin_transaction();
        $count = 0;
        while ($a = $r->fetch_assoc()) {
            $cost = (float)$a['purchase_cost'];
            $residual = (float)$a['residual_value'];
            $life = max(1, (int)$a['useful_life_months']);
            $currentVal = (float)$a['current_value'];
            if ($currentVal <= $residual) continue;

            $method = $a['method'] ?? 'straight_line';
            if ($method === 'declining_balance') {
                $rate = $a['depreciation_rate'] ? (float)$a['depreciation_rate'] / 100 : (2 / $life);
                $depAmt = max(0, $currentVal * $rate);
            } else {
                $depAmt = ($cost - $residual) / $life;
            }
            $newVal = max($residual, $currentVal - $depAmt);
            $depAmt = $currentVal - $newVal;

            $ar = $conn->query("SELECT COALESCE(SUM(depreciation_amount),0) as acc FROM asset_depreciation WHERE restaurant_id = $tenantId AND asset_id=" . $a['id']);
            $accum = $ar ? (float)$ar->fetch_assoc()['acc'] : 0;

            $conn->query("INSERT INTO asset_depreciation (restaurant_id,asset_id,period_date,method,depreciation_amount,accumulated_depreciation,book_value) VALUES ($tenantId," . $a['id'] . ",'$period','$method',$depAmt," . ($accum+$depAmt) . ",$newVal)");
            $conn->query("UPDATE assets SET current_value=$newVal WHERE restaurant_id = $tenantId AND id=" . $a['id']);
            $count++;
        }
        $conn->commit();
        Inventory::audit('asset.depreciation', "Depreciation posted for $count assets ($period)");
        echo json_encode(['success'=>true,'message'=>"Depreciation calculated for $count assets"]);
        break;

    case 'list_depreciation':
        Inventory::requireRead('depreciation');
        $asset_id = intval($_GET['asset_id'] ?? 0);
        $where = $asset_id ? "AND d.asset_id=$asset_id" : '';
        $r = $conn->query("SELECT d.*, a.name as asset_name, a.asset_code
            FROM asset_depreciation d JOIN assets a ON d.asset_id=a.id AND a.restaurant_id = d.restaurant_id
            WHERE d.restaurant_id = $tenantId $where
            ORDER BY d.period_date DESC LIMIT 300");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'depreciation'=>$items]);
        break;

    // =================== HELPERS ===================
    case 'list_categories':
        $r = $conn->query("SELECT * FROM inventory_categories WHERE restaurant_id = $tenantId AND status='active' ORDER BY display_order");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'categories'=>$items]);
        break;

    case 'list_units':
        $r = $conn->query("SELECT * FROM inventory_units WHERE restaurant_id = $tenantId ORDER BY name");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'units'=>$items]);
        break;

    case 'list_menu_items':
        $r = $conn->query("SELECT id, name, price FROM menu_items WHERE restaurant_id = $tenantId AND status='available' ORDER BY name");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'menu_items'=>$items]);
        break;

    case 'list_asset_categories':
        $r = $conn->query("SELECT * FROM asset_categories WHERE restaurant_id = $tenantId ORDER BY name");
        $items = [];
        if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
        echo json_encode(['success'=>true,'categories'=>$items]);
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Invalid action: ' . htmlspecialchars($action)]);
}
} catch (Exception $e) {
    if ($conn->connect_errno === 0) @$conn->rollback();
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
