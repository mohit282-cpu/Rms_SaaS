<?php
// helpers/ShiftService.php - Work Shift Management & Cash Drawer Reconciliation Service

class ShiftService {

    /**
     * Get active shift for a user/tenant
     */
    public static function getActiveShift($conn, int $userId, int $tenantId): ?array {
        $stmt = $conn->prepare("SELECT * FROM work_shifts WHERE restaurant_id = ? AND user_id = ? AND status = 'open' LIMIT 1");
        $stmt->bind_param("ii", $tenantId, $userId);
        $stmt->execute();
        $shift = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $shift ?: null;
    }

    /**
     * Open a new cash drawer shift
     */
    public static function openShift($conn, int $userId, string $userName, string $shiftName, float $openingCash, int $tenantId): array {
        $existing = self::getActiveShift($conn, $userId, $tenantId);
        if ($existing) {
            return ['success' => false, 'message' => 'You already have an active open shift'];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            INSERT INTO work_shifts (restaurant_id, user_id, shift_name, opening_cash, opened_at, status) 
            VALUES (?, ?, ?, ?, ?, 'open')
        ");
        $stmt->bind_param("iisds", $tenantId, $userId, $shiftName, $openingCash, $now);

        if ($stmt->execute()) {
            $sid = $stmt->insert_id;
            $stmt->close();
            Security::logAudit("SHIFT_OPEN", "User {$userName} opened shift #{$sid} ({$shiftName}) with opening cash NPR {$openingCash}");
            return ['success' => true, 'message' => 'Shift opened successfully', 'shift_id' => $sid];
        } else {
            $err = $stmt->error; $stmt->close();
            return ['success' => false, 'message' => 'Failed to open shift: ' . $err];
        }
    }

    /**
     * Close a cash drawer shift and calculate cash variance
     */
    public static function closeShift($conn, int $shiftId, float $actualCash, string $notes, string $userName, int $tenantId): array {
        TenantContext::assertOwnership($conn, 'work_shifts', $shiftId);

        $conn->begin_transaction();
        try {
            // Get shift details
            $sStmt = $conn->prepare("SELECT opening_cash, opened_at FROM work_shifts WHERE id = ? AND restaurant_id = ? LIMIT 1");
            $sStmt->bind_param("ii", $shiftId, $tenantId);
            $sStmt->execute();
            $shift = $sStmt->get_result()->fetch_assoc();
            $sStmt->close();

            if (!$shift) {
                $conn->rollback();
                return ['success' => false, 'message' => 'Shift not found'];
            }

            $openingCash = floatval($shift['opening_cash']);
            $openedAt = $shift['opened_at'];

            // Sum cash sales during this shift window
            $cStmt = $conn->prepare("
                SELECT COALESCE(SUM(total_amount), 0.00) as cash_sales 
                FROM orders 
                WHERE restaurant_id = ? AND status = 'completed' 
                AND payment_method = 'cash' AND created_at >= ?
            ");
            $cStmt->bind_param("is", $tenantId, $openedAt);
            $cStmt->execute();
            $cashSales = floatval($cStmt->get_result()->fetch_assoc()['cash_sales'] ?? 0.00);
            $cStmt->close();

            $expectedCash = round($openingCash + $cashSales, 2);
            $variance = round($actualCash - $expectedCash, 2);
            $closedAt = date('Y-m-d H:i:s');

            $uStmt = $conn->prepare("
                UPDATE work_shifts 
                SET cash_sales = ?, closing_cash_expected = ?, closing_cash_actual = ?, variance = ?, notes = ?, closed_at = ?, closed_by = ?, status = 'closed' 
                WHERE id = ? AND restaurant_id = ?
            ");
            $uStmt->bind_param("ddddsssii", $cashSales, $expectedCash, $actualCash, $variance, $notes, $closedAt, $userName, $shiftId, $tenantId);
            $uStmt->execute();
            $uStmt->close();

            $conn->commit();
            Security::logAudit("SHIFT_CLOSE", "User {$userName} closed shift #{$shiftId}. Expected NPR {$expectedCash}, Actual NPR {$actualCash}, Variance NPR {$variance}");
            return [
                'success' => true, 
                'message' => 'Shift closed and cash reconciled successfully',
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'variance' => $variance
            ];

        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Failed to close shift: ' . $e->getMessage()];
        }
    }
}
