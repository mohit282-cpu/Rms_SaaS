<?php
// api/assets.php - Complete Asset Management CRUD & Process API Endpoint
require_once '../config.php';
$tenantId = (int)AuthorizationService::requireStaffApi();

header('Content-Type: application/json; charset=UTF-8');
$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection unavailable']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ==========================================
        // ASSETS REGISTER CRUD
        // ==========================================
        case 'list_assets':
            Inventory::requireRead('assets');
            $cat = intval($_GET['category_id'] ?? 0);
            $status = $conn->real_escape_string($_GET['status'] ?? '');
            $search = $conn->real_escape_string($_GET['search'] ?? '');
            $where = "a.restaurant_id = $tenantId";
            if ($cat > 0) $where .= " AND a.category_id=$cat";
            if ($status) $where .= " AND a.status='$status'";
            if ($search) $where .= " AND (a.name LIKE '%$search%' OR a.asset_code LIKE '%$search%' OR a.serial_number LIKE '%$search%' OR a.assigned_location LIKE '%$search%')";
            
            $sql = "SELECT a.*, ac.name as category_name, ac.icon as category_icon, s.company_name as supplier_name
                    FROM assets a 
                    LEFT JOIN asset_categories ac ON a.category_id=ac.id AND ac.restaurant_id = a.restaurant_id
                    LEFT JOIN suppliers s ON a.supplier_id=s.id AND s.restaurant_id = a.restaurant_id
                    WHERE $where ORDER BY a.id DESC";
            $r = $conn->query($sql);
            $items = [];
            if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
            echo json_encode(['success' => true, 'assets' => $items]);
            break;

        case 'get_asset':
            Inventory::requireRead('assets');
            $id = intval($_GET['id'] ?? 0);
            $qr = $conn->real_escape_string($_GET['qr_token'] ?? '');
            $where = $id > 0 ? "a.id=$id" : "a.qr_token='$qr'";
            
            $r = $conn->query("SELECT a.*, ac.name as category_name, ac.icon as category_icon, s.company_name as supplier_name 
                FROM assets a 
                LEFT JOIN asset_categories ac ON a.category_id=ac.id AND ac.restaurant_id = a.restaurant_id
                LEFT JOIN suppliers s ON a.supplier_id=s.id AND s.restaurant_id = a.restaurant_id
                WHERE a.restaurant_id = $tenantId AND $where LIMIT 1");
            $asset = $r ? $r->fetch_assoc() : null;

            if ($asset) {
                // Fetch recent maintenance log for asset
                $m_res = $conn->query("SELECT * FROM asset_maintenance WHERE asset_id=" . intval($asset['id']) . " AND restaurant_id = $tenantId ORDER BY service_date DESC LIMIT 5");
                $maint = [];
                if ($m_res) { while ($m_row = $m_res->fetch_assoc()) $maint[] = $m_row; }
                $asset['maintenance_history'] = $maint;

                // Fetch recent transfer log
                $t_res = $conn->query("SELECT * FROM asset_transfers WHERE asset_id=" . intval($asset['id']) . " AND restaurant_id = $tenantId ORDER BY transfer_date DESC LIMIT 5");
                $trans = [];
                if ($t_res) { while ($t_row = $t_res->fetch_assoc()) $trans[] = $t_row; }
                $asset['transfer_history'] = $trans;

                // Fetch warranty info
                $w_res = $conn->query("SELECT * FROM asset_warranties WHERE asset_id=" . intval($asset['id']) . " AND restaurant_id = $tenantId ORDER BY expiry_date DESC LIMIT 1");
                $asset['warranty'] = $w_res ? $w_res->fetch_assoc() : null;
            }

            echo json_encode(['success' => (bool)$asset, 'asset' => $asset]);
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
            $branch = $conn->real_escape_string(trim($_POST['assigned_branch'] ?? 'Main Branch'));
            $location = $conn->real_escape_string(trim($_POST['assigned_location'] ?? ''));
            $employee = $conn->real_escape_string(trim($_POST['assigned_employee'] ?? ''));
            $condition = $conn->real_escape_string($_POST['condition'] ?? 'good');
            $status = $conn->real_escape_string($_POST['asset_status'] ?? 'available');
            $useful_life = intval($_POST['useful_life_months'] ?? 60);
            $residual = floatval($_POST['residual_value'] ?? 0);
            $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));

            if (!$name) { echo json_encode(['success' => false, 'message' => 'Asset name is required']); break; }

            if ($id > 0) {
                $sql = "UPDATE assets SET name='$name', asset_code='$asset_code', category_id=$category_id, brand='$brand', model='$model', serial_number='$serial',
                    purchase_date=$pdate, purchase_cost=$cost, supplier_id=$supplier_id, warranty_expiry=$warr, assigned_branch='$branch', assigned_location='$location',
                    assigned_employee='$employee', `condition`='$condition', status='$status', useful_life_months=$useful_life, residual_value=$residual, notes='$notes' WHERE restaurant_id = $tenantId AND id=$id";
            } else {
                if (!$asset_code) $asset_code = 'AST-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $qr = bin2hex(random_bytes(16));
                $sql = "INSERT INTO assets (restaurant_id, asset_code, qr_token, name, category_id, brand, model, serial_number, purchase_date, purchase_cost, supplier_id, warranty_expiry,
                    assigned_branch, assigned_location, assigned_employee, `condition`, status, useful_life_months, residual_value, current_value, notes)
                    VALUES ($tenantId, '$asset_code', '$qr', '$name', $category_id, '$brand', '$model', '$serial', $pdate, $cost, $supplier_id, $warr,
                    '$branch', '$location', '$employee', '$condition', '$status', $useful_life, $residual, $cost, '$notes')";
            }

            $ok = $conn->query($sql);
            $assetId = $id > 0 ? $id : $conn->insert_id;
            Inventory::ensureAssetQR($conn, $assetId);

            // Record audit log
            $actor = $_SESSION['admin_username'] ?? 'admin';
            $event = $id > 0 ? 'updated' : 'created';
            $desc = ($id > 0 ? "Asset #$assetId updated" : "Asset #$assetId created") . " — $name ($asset_code)";
            
            $logStmt = $conn->prepare("INSERT INTO asset_logs (restaurant_id, asset_id, event_type, description, changed_by) VALUES (?,?,?,?,?)");
            $logStmt->bind_param("iisss", $tenantId, $assetId, $event, $desc, $actor);
            $logStmt->execute();
            $logStmt->close();

            Inventory::audit('asset.save', $desc);
            echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Asset saved successfully' : $conn->error, 'id' => $assetId]);
            break;

        case 'delete_asset':
            CSRF::requireValidToken();
            Inventory::requireWrite('assets');
            $id = intval($_POST['id'] ?? 0);
            $r = $conn->query("SELECT name FROM assets WHERE restaurant_id = $tenantId AND id=$id");
            $name = ($r && $row = $r->fetch_assoc()) ? $row['name'] : "#$id";
            $ok = $conn->query("UPDATE assets SET status='disposed' WHERE restaurant_id = $tenantId AND id=$id");

            $actor = $_SESSION['admin_username'] ?? 'admin';
            $desc = "Asset #$id marked as disposed — $name";
            $logStmt = $conn->prepare("INSERT INTO asset_logs (restaurant_id, asset_id, event_type, description, changed_by) VALUES (?,?,?,?,?)");
            $logStmt->bind_param("iisss", $tenantId, $id, 'disposed', $desc, $actor);
            $logStmt->execute();
            $logStmt->close();

            Inventory::audit('asset.delete', $desc);
            echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Asset marked as disposed' : 'Operation failed']);
            break;

        // ==========================================
        // ASSET CATEGORIES CRUD
        // ==========================================
        case 'list_asset_categories':
            Inventory::requireRead('assets');
            $r = $conn->query("SELECT ac.*, COUNT(a.id) as asset_count FROM asset_categories ac LEFT JOIN assets a ON ac.id=a.category_id AND a.restaurant_id = ac.restaurant_id AND a.status!='disposed' WHERE ac.restaurant_id = $tenantId GROUP BY ac.id ORDER BY ac.name");
            $items = [];
            if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
            echo json_encode(['success' => true, 'categories' => $items]);
            break;

        case 'save_asset_category':
            CSRF::requireValidToken();
            Inventory::requireWrite('assets');
            $id = intval($_POST['id'] ?? 0);
            $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
            $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
            $icon = $conn->real_escape_string(trim($_POST['icon'] ?? '🏗️'));
            $method = $conn->real_escape_string($_POST['depreciation_method'] ?? 'straight_line');
            $rate = floatval($_POST['depreciation_rate'] ?? 0);
            $life = intval($_POST['default_useful_life'] ?? 60);

            if (!$name) { echo json_encode(['success' => false, 'message' => 'Category name required']); break; }

            if ($id > 0) {
                $sql = "UPDATE asset_categories SET name='$name', description='$desc', icon='$icon', depreciation_method='$method', depreciation_rate=$rate, default_useful_life=$life WHERE restaurant_id = $tenantId AND id=$id";
            } else {
                $sql = "INSERT INTO asset_categories (restaurant_id, name, description, icon, depreciation_method, depreciation_rate, default_useful_life) VALUES ($tenantId, '$name', '$desc', '$icon', '$method', $rate, $life)";
            }
            $ok = $conn->query($sql);
            Inventory::audit('asset.category', ($id > 0 ? "Updated asset category #$id: $name" : "Created asset category: $name"));
            echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Category saved' : $conn->error]);
            break;

        // ==========================================
        // MAINTENANCE LOGS
        // ==========================================
        case 'list_maintenance':
            Inventory::requireRead('maintenance');
            $asset_id = intval($_GET['asset_id'] ?? 0);
            $status = $conn->real_escape_string($_GET['status'] ?? '');
            $where = "a.restaurant_id = $tenantId";
            if ($asset_id > 0) $where .= " AND m.asset_id=$asset_id";
            if ($status) $where .= " AND m.status='$status'";

            $r = $conn->query("SELECT m.*, a.name as asset_name, a.asset_code, a.assigned_location
                FROM asset_maintenance m JOIN assets a ON m.asset_id=a.id AND a.restaurant_id = m.restaurant_id WHERE $where ORDER BY m.service_date DESC");
            $items = [];
            if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
            echo json_encode(['success' => true, 'maintenance' => $items]);
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

            if (!$asset_id) { echo json_encode(['success' => false, 'message' => 'Select an asset']); break; }

            // Verify the asset belongs to the active tenant before acting on it.
            $chk = $conn->query("SELECT id FROM assets WHERE restaurant_id = $tenantId AND id=$asset_id LIMIT 1");
            if (!$chk || !$chk->fetch_assoc()) { echo json_encode(['success' => false, 'message' => 'Asset not found']); break; }

            if ($id > 0) {
                $sql = "UPDATE asset_maintenance SET asset_id=$asset_id, type='$type', description='$desc', technician='$tech', cost=$cost, parts_used='$parts',
                    service_date='$sdate', next_service_date=$ndt, status='$mstatus', notes='$notes' WHERE restaurant_id = $tenantId AND id=$id";
            } else {
                $sql = "INSERT INTO asset_maintenance (restaurant_id, asset_id, type, description, technician, cost, parts_used, service_date, next_service_date, status, notes)
                    VALUES ($tenantId, $asset_id, '$type', '$desc', '$tech', $cost, '$parts', '$sdate', $ndt, '$mstatus', '$notes')";
            }
            $ok = $conn->query($sql);

            // Update asset status
            if ($mstatus === 'completed') {
                $conn->query("UPDATE assets SET status='in_use' WHERE restaurant_id = $tenantId AND id=$asset_id");
            } elseif (in_array($mstatus, ['scheduled', 'in_progress'])) {
                $conn->query("UPDATE assets SET status='maintenance' WHERE restaurant_id = $tenantId AND id=$asset_id AND status='in_use'");
            }

            $actor = $_SESSION['admin_username'] ?? 'admin';
            $logDesc = ($id > 0 ? "Updated maintenance #$id" : "Scheduled maintenance") . " for asset #$asset_id ($type, cost Rs.$cost)";
            $logStmt = $conn->prepare("INSERT INTO asset_logs (restaurant_id, asset_id, event_type, description, changed_by) VALUES (?,?,?,?,?)");
            $logStmt->bind_param("iisss", $tenantId, $asset_id, 'maintenance', $logDesc, $actor);
            $logStmt->execute();
            $logStmt->close();

            Inventory::audit('asset.maintenance', $logDesc);
            echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Maintenance record saved' : $conn->error]);
            break;

        // ==========================================
        // WARRANTY MANAGEMENT
        // ==========================================
        case 'list_warranties':
            Inventory::requireRead('assets');
            $r = $conn->query("SELECT w.*, a.name as asset_name, a.asset_code, s.company_name as supplier_name
                FROM asset_warranties w 
                JOIN assets a ON w.asset_id=a.id AND a.restaurant_id = w.restaurant_id
                LEFT JOIN suppliers s ON a.supplier_id=s.id AND s.restaurant_id = a.restaurant_id
                WHERE w.restaurant_id = $tenantId
                ORDER BY w.expiry_date ASC");
            $items = [];
            if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
            echo json_encode(['success' => true, 'warranties' => $items]);
            break;

        case 'save_warranty':
            CSRF::requireValidToken();
            Inventory::requireWrite('assets');
            $id = intval($_POST['id'] ?? 0);
            $asset_id = intval($_POST['asset_id'] ?? 0);
            $provider = $conn->real_escape_string(trim($_POST['provider_name'] ?? ''));
            $policy = $conn->real_escape_string(trim($_POST['policy_number'] ?? ''));
            $sdate = $conn->real_escape_string(trim($_POST['start_date'] ?? ''));
            $sdt = $sdate ? "'$sdate'" : 'NULL';
            $edate = $conn->real_escape_string(trim($_POST['expiry_date'] ?? ''));
            $details = $conn->real_escape_string(trim($_POST['coverage_details'] ?? ''));
            $cstatus = $conn->real_escape_string($_POST['claim_status'] ?? 'active');
            $cnotes = $conn->real_escape_string(trim($_POST['claim_notes'] ?? ''));

            if (!$asset_id || !$edate) { echo json_encode(['success' => false, 'message' => 'Asset and Expiry Date are required']); break; }

            // Verify the asset belongs to the active tenant before acting on it.
            $chk = $conn->query("SELECT id FROM assets WHERE restaurant_id = $tenantId AND id=$asset_id LIMIT 1");
            if (!$chk || !$chk->fetch_assoc()) { echo json_encode(['success' => false, 'message' => 'Asset not found']); break; }

            if ($id > 0) {
                $sql = "UPDATE asset_warranties SET asset_id=$asset_id, provider_name='$provider', policy_number='$policy', start_date=$sdt, expiry_date='$edate', coverage_details='$details', claim_status='$cstatus', claim_notes='$cnotes' WHERE restaurant_id = $tenantId AND id=$id";
            } else {
                $sql = "INSERT INTO asset_warranties (restaurant_id, asset_id, provider_name, policy_number, start_date, expiry_date, coverage_details, claim_status, claim_notes) VALUES ($tenantId, $asset_id, '$provider', '$policy', $sdt, '$edate', '$details', '$cstatus', '$cnotes')";
            }
            $ok = $conn->query($sql);
            $conn->query("UPDATE assets SET warranty_expiry='$edate' WHERE restaurant_id = $tenantId AND id=$asset_id");
            Inventory::audit('asset.warranty', "Warranty record saved for asset #$asset_id");
            echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Warranty saved' : $conn->error]);
            break;

        // ==========================================
        // TRANSFERS
        // ==========================================
        case 'list_transfers':
            Inventory::requireRead('transfers');
            $asset_id = intval($_GET['asset_id'] ?? 0);
            $where = $asset_id ? "AND t.asset_id=$asset_id" : '';
            $r = $conn->query("SELECT t.*, a.name as asset_name, a.asset_code FROM asset_transfers t JOIN assets a ON t.asset_id=a.id AND a.restaurant_id = t.restaurant_id WHERE t.restaurant_id = $tenantId $where ORDER BY t.transfer_date DESC");
            $items = [];
            if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
            echo json_encode(['success' => true, 'transfers' => $items]);
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

            if (!$asset_id) { echo json_encode(['success' => false, 'message' => 'Select an asset']); break; }

            // Verify the asset belongs to the active tenant before acting on it.
            $chk = $conn->query("SELECT id FROM assets WHERE restaurant_id = $tenantId AND id=$asset_id LIMIT 1");
            if (!$chk || !$chk->fetch_assoc()) { echo json_encode(['success' => false, 'message' => 'Asset not found']); break; }

            $conn->begin_transaction();
            $actor = $_SESSION['admin_username'] ?? 'admin';
            $stmt = $conn->prepare("INSERT INTO asset_transfers (restaurant_id, asset_id, from_location, to_location, from_employee, to_employee, transfer_date, reason, transferred_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("iisssssss", $tenantId, $asset_id, $from_loc, $to_loc, $from_emp, $to_emp, $tdate, $reason, $actor);
            $ok = $stmt->execute();
            $stmt->close();

            if ($to_loc) $conn->query("UPDATE assets SET assigned_location='$to_loc' WHERE restaurant_id = $tenantId AND id=$asset_id");
            if ($to_emp) $conn->query("UPDATE assets SET assigned_employee='$to_emp' WHERE restaurant_id = $tenantId AND id=$asset_id");

            $logDesc = "Asset #$asset_id transferred: location '$from_loc' -> '$to_loc', assignee '$from_emp' -> '$to_emp'";
            $logStmt = $conn->prepare("INSERT INTO asset_logs (restaurant_id, asset_id, event_type, description, changed_by) VALUES (?,?,?,?,?)");
            $logStmt->bind_param("iisss", $tenantId, $asset_id, 'transfer', $logDesc, $actor);
            $logStmt->execute();
            $logStmt->close();

            $conn->commit();
            Inventory::audit('asset.transfer', $logDesc);
            echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Asset transfer recorded' : $conn->error]);
            break;

        // ==========================================
        // DEPRECIATION ENGINE
        // ==========================================
        case 'run_depreciation':
            CSRF::requireValidToken();
            Inventory::requireWrite('depreciation');
            $asset_id = intval($_POST['asset_id'] ?? 0);
            $where = $asset_id ? "AND a.id=$asset_id" : '';

            $r = $conn->query("SELECT a.id, a.purchase_cost, a.residual_value, a.useful_life_months, a.current_value,
                    COALESCE(ac.depreciation_method,'straight_line') as method, COALESCE(ac.depreciation_rate,0) as rate
                FROM assets a LEFT JOIN asset_categories ac ON a.category_id=ac.id AND ac.restaurant_id = a.restaurant_id
                WHERE a.restaurant_id = $tenantId AND a.status NOT IN ('disposed','lost') $where");
            if (!$r) { echo json_encode(['success' => false, 'message' => 'Database error']); break; }

            $period = date('Y-m-01');
            $conn->begin_transaction();
            $count = 0;
            while ($a = $r->fetch_assoc()) {
                $aid = intval($a['id']);
                // Check if already posted for this period
                $chk = $conn->query("SELECT id FROM asset_depreciation WHERE restaurant_id = $tenantId AND asset_id=$aid AND period_date='$period' LIMIT 1");
                if ($chk && $chk->fetch_assoc()) continue;

                $cost = (float)$a['purchase_cost'];
                $residual = (float)$a['residual_value'];
                $life = max(1, (int)$a['useful_life_months']);
                $currentVal = (float)$a['current_value'];
                if ($currentVal <= $residual) continue;

                $method = $a['method'] ?? 'straight_line';
                if ($method === 'declining_balance') {
                    $ratePercent = $a['rate'] > 0 ? (float)$a['rate'] / 100 : (2 / ($life / 12));
                    $depAmt = max(0, ($currentVal * $ratePercent) / 12);
                } else {
                    $depAmt = ($cost - $residual) / $life;
                }
                $newVal = max($residual, $currentVal - $depAmt);
                $depAmt = $currentVal - $newVal;

                $ar = $conn->query("SELECT COALESCE(SUM(depreciation_amount),0) as acc FROM asset_depreciation WHERE restaurant_id = $tenantId AND asset_id=$aid");
                $accum = $ar ? (float)$ar->fetch_assoc()['acc'] : 0;
                $accumTotal = $accum + $depAmt;

                $conn->query("INSERT INTO asset_depreciation (restaurant_id, asset_id, period_date, method, depreciation_amount, accumulated_depreciation, book_value) VALUES ($tenantId, $aid, '$period', '$method', $depAmt, $accumTotal, $newVal)");
                $conn->query("UPDATE assets SET current_value=$newVal WHERE restaurant_id = $tenantId AND id=$aid");
                $count++;
            }
            $conn->commit();
            Inventory::audit('asset.depreciation', "Depreciation batch executed for $count assets for period $period");
            echo json_encode(['success' => true, 'message' => "Depreciation processed for $count assets"]);
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
            echo json_encode(['success' => true, 'depreciation' => $items]);
            break;

        // ==========================================
        // ASSET AUDIT LOGS
        // ==========================================
        case 'list_asset_logs':
            Inventory::requireRead('assets');
            $asset_id = intval($_GET['asset_id'] ?? 0);
            $where = $asset_id ? "AND al.asset_id=$asset_id" : '';
            $r = $conn->query("SELECT al.*, a.name as asset_name, a.asset_code FROM asset_logs al JOIN assets a ON al.asset_id=a.id AND a.restaurant_id = al.restaurant_id WHERE al.restaurant_id = $tenantId $where ORDER BY al.created_at DESC LIMIT 200");
            $items = [];
            if ($r) { while ($row = $r->fetch_assoc()) $items[] = $row; }
            echo json_encode(['success' => true, 'logs' => $items]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action parameter']);
    }
} catch (Exception $e) {
    if ($conn->connect_errno === 0) @$conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
