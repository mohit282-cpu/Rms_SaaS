<?php
// api/modifiers.php - Multi-Tenant Product Modifiers & Add-ons API
require_once __DIR__ . '/../config.php';

$tenantId = (int)AuthorizationService::requireStaffApi();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// GET Request: Fetch modifier groups & modifiers
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $groups = ModifierService::getModifierGroups($tenantId);
    Response::json(['success' => true, 'groups' => $groups]);
}

// POST Request: Create/Edit/Delete Modifier Groups and Modifiers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RBAC::requirePermission('manage_modifiers');
    CSRF::requireValidToken();

    $action = Security::sanitize($_POST['action'] ?? '');

    if ($action === 'create_group') {
        $name = Security::sanitize($_POST['name'] ?? '');
        $type = in_array($_POST['selection_type'] ?? '', ['single', 'multiple'], true) ? $_POST['selection_type'] : 'single';
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $minSel = max(0, intval($_POST['min_selections'] ?? 0));
        $maxSel = max(1, intval($_POST['max_selections'] ?? 1));

        if (empty($name)) Response::error('Modifier group name is required', 400);

        $stmt = $conn->prepare("
            INSERT INTO modifier_groups (restaurant_id, name, selection_type, is_required, min_selections, max_selections) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issiii", $tenantId, $name, $type, $isRequired, $minSel, $maxSel);
        if ($stmt->execute()) {
            $gid = $stmt->insert_id;
            $stmt->close();
            Security::logAudit("MODIFIER_GROUP_CREATE", "Created modifier group '{$name}' (Tenant ID: {$tenantId})");
            Response::success('Modifier group created successfully', ['id' => $gid]);
        } else {
            $err = $stmt->error;
            $stmt->close();
            Response::error('Failed to create modifier group: ' . $err, 500);
        }
    }

    elseif ($action === 'add_modifier') {
        $groupId = intval($_POST['group_id'] ?? 0);
        $name = Security::sanitize($_POST['name'] ?? '');
        $price = max(0.00, floatval($_POST['price'] ?? 0.00));
        $invItemId = intval($_POST['inventory_item_id'] ?? 0);

        if ($groupId <= 0 || empty($name)) Response::error('Group ID and Modifier Name are required', 400);

        TenantContext::assertOwnership($conn, 'modifier_groups', $groupId);

        $invRef = $invItemId > 0 ? $invItemId : null;
        $stmt = $conn->prepare("
            INSERT INTO modifiers (restaurant_id, group_id, name, price, inventory_item_id) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iisdi", $tenantId, $groupId, $name, $price, $invRef);
        if ($stmt->execute()) {
            $mid = $stmt->insert_id;
            $stmt->close();
            Security::logAudit("MODIFIER_CREATE", "Added modifier '{$name}' (+NPR {$price}) to Group #{$groupId}");
            Response::success('Modifier added successfully', ['id' => $mid]);
        } else {
            $err = $stmt->error;
            $stmt->close();
            Response::error('Failed to add modifier: ' . $err, 500);
        }
    }

    elseif ($action === 'delete_group') {
        $groupId = intval($_POST['group_id'] ?? 0);
        if ($groupId <= 0) Response::error('Invalid Group ID', 400);

        TenantContext::assertOwnership($conn, 'modifier_groups', $groupId);

        $stmt = $conn->prepare("DELETE FROM modifier_groups WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("ii", $groupId, $tenantId);
        if ($stmt->execute()) {
            $stmt->close();
            Security::logAudit("MODIFIER_GROUP_DELETE", "Deleted modifier group #{$groupId}");
            Response::success('Modifier group deleted successfully');
        } else {
            $err = $stmt->error;
            $stmt->close();
            Response::error('Failed to delete modifier group: ' . $err, 500);
        }
    }

    elseif ($action === 'delete_modifier') {
        $modId = intval($_POST['modifier_id'] ?? 0);
        if ($modId <= 0) Response::error('Invalid Modifier ID', 400);

        TenantContext::assertOwnership($conn, 'modifiers', $modId);

        $stmt = $conn->prepare("DELETE FROM modifiers WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("ii", $modId, $tenantId);
        if ($stmt->execute()) {
            $stmt->close();
            Security::logAudit("MODIFIER_DELETE", "Deleted modifier #{$modId}");
            Response::success('Modifier deleted successfully');
        } else {
            $err = $stmt->error;
            $stmt->close();
            Response::error('Failed to delete modifier: ' . $err, 500);
        }
    }

    else {
        Response::error('Invalid action specified', 400);
    }
}
