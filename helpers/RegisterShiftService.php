<?php
// helpers/RegisterShiftService.php - Register/Cashier Shift & Cash Float Reconciliation Engine

require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/TenantContext.php';

class RegisterShiftService {

    /**
     * Auto-provision Register Shift tables (now managed via database/migrations/).
     */
    public static function ensureRegisterShiftSchema(mysqli $conn): void {
        // Schema migrations are managed deterministically via CLI (database/migrate.php)
        return;
    }

    /**
     * Get active open register shift for tenant & register.
     */
    public static function getActiveShift(mysqli $conn, int $tenantId, ?string $registerName = null): ?array {
        self::ensureRegisterShiftSchema($conn);

        if ($registerName) {
            $stmt = $conn->prepare("SELECT * FROM shifts WHERE restaurant_id = ? AND register_name = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("is", $tenantId, $registerName);
        } else {
            $stmt = $conn->prepare("SELECT * FROM shifts WHERE restaurant_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("i", $tenantId);
        }

        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            // Attach live calculated metrics
            $res = self::enrichShiftMetrics($conn, $tenantId, $res);
        }

        return $res ?: null;
    }

    /**
     * Open a new Register Shift.
     */
    public static function openShift(mysqli $conn, int $tenantId, array $data, int $staffId, string $staffName): array {
        self::ensureRegisterShiftSchema($conn);

        $registerName = Security::sanitize(trim($data['register_name'] ?? 'Counter 01'));
        if (empty($registerName)) $registerName = 'Counter 01';

        $openingCash = (float)($data['opening_cash'] ?? 0.0);
        $notes = Security::sanitize(trim($data['notes'] ?? ''));

        if ($openingCash < 0) {
            return ['success' => false, 'error' => "Opening cash float must be a non-negative amount."];
        }

        $conn->begin_transaction();
        try {
            // Acquire exclusive row lock on open shifts for this tenant & register to prevent race conditions
            $checkStmt = $conn->prepare("SELECT id FROM shifts WHERE restaurant_id = ? AND register_name = ? AND status = 'open' FOR UPDATE");
            $checkStmt->bind_param("is", $tenantId, $registerName);
            $checkStmt->execute();
            $checkRes = $checkStmt->get_result();
            if ($checkRes && $checkRes->num_rows > 0) {
                $checkStmt->close();
                $conn->rollback();
                return ['success' => false, 'error' => "An active register shift is already open for $registerName."];
            }
            $checkStmt->close();

            $stmt = $conn->prepare("INSERT INTO shifts (restaurant_id, register_name, staff_id, staff_name, opening_cash, notes, status, open_time) VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())");
            $stmt->bind_param("isisds", $tenantId, $registerName, $staffId, $staffName, $openingCash, $notes);

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                $conn->rollback();
                return ['success' => false, 'error' => "Failed to open register shift: " . $err];
            }

            $shiftId = $stmt->insert_id;
            $stmt->close();

            $conn->commit();

            Security::logAudit('REGISTER_SHIFT_OPENED', "Opened Register Shift #$shiftId ($registerName) with opening float of Rs. " . number_format($openingCash, 2));

            return ['success' => true, 'shift_id' => $shiftId];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['success' => false, 'error' => "Failed to open shift: " . $e->getMessage()];
        }
    }

    /**
     * Record Cash In / Cash Out movement.
     */
    public static function recordCashMovement(mysqli $conn, int $tenantId, int $shiftId, string $type, float $amount, string $reason, int $userId, string $userName, bool $isExpense = false, string $expenseCategory = 'General'): array {
        self::ensureRegisterShiftSchema($conn);

        $type = strtolower(trim($type));
        if (!in_array($type, ['cash_in', 'cash_out'])) {
            return ['success' => false, 'error' => "Invalid cash movement type."];
        }
        if ($amount <= 0) {
            return ['success' => false, 'error' => "Amount must be greater than 0.00."];
        }
        $reason = Security::sanitize(trim($reason));
        if (empty($reason)) {
            return ['success' => false, 'error' => "Reason for cash movement is required."];
        }

        // Verify shift belongs to tenant and is OPEN
        $shift = self::getShiftById($conn, $tenantId, $shiftId);
        if (!$shift) {
            return ['success' => false, 'error' => "Register shift not found."];
        }
        if ($shift['status'] !== 'open') {
            return ['success' => false, 'error' => "Cannot add cash movements to a CLOSED shift."];
        }

        $conn->begin_transaction();
        try {
            $expenseId = null;

            // If cash_out linked to expense -> auto-create expense record for P&L consistency
            if ($type === 'cash_out' && $isExpense) {
                $expStmt = $conn->prepare("INSERT INTO expenses (restaurant_id, category, category_name, title, description, amount, expense_date, vendor, reference_no, payment_method, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE(), ?, ?, 'cash', ?, ?)");
                $vendor = "Register Shift #$shiftId";
                $refNo = "REG-OUT-$shiftId";
                $expStmt->bind_param("issssdssss", $tenantId, $expenseCategory, $expenseCategory, $reason, $reason, $amount, $vendor, $refNo, $reason, $userName);
                $expStmt->execute();
                $expenseId = $expStmt->insert_id;
                $expStmt->close();
            }

            $stmt = $conn->prepare("INSERT INTO register_cash_movements (restaurant_id, shift_id, type, amount, reason, expense_id, user_id, user_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisdsiis", $tenantId, $shiftId, $type, $amount, $reason, $expenseId, $userId, $userName);
            $stmt->execute();
            $stmt->close();

            // Update cached cash_in / cash_out on shift table
            if ($type === 'cash_in') {
                $uStmt = $conn->prepare("UPDATE shifts SET cash_in = cash_in + ? WHERE id = ? AND restaurant_id = ?");
            } else {
                $uStmt = $conn->prepare("UPDATE shifts SET cash_out = cash_out + ? WHERE id = ? AND restaurant_id = ?");
            }
            if ($uStmt) {
                $uStmt->bind_param("dii", $amount, $shiftId, $tenantId);
                $uStmt->execute();
                $uStmt->close();
            }

            $conn->commit();

            Security::logAudit('CASH_MOVEMENT_RECORDED', "Recorded " . strtoupper($type) . " of Rs. " . number_format($amount, 2) . " for Shift #$shiftId ($reason)");

            return ['success' => true];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['success' => false, 'error' => "Failed to record cash movement: " . $e->getMessage()];
        }
    }

    /**
     * Calculate live real-time metrics for a register shift based on database payments.
     */
    public static function enrichShiftMetrics(mysqli $conn, int $tenantId, array $shift): array {
        $shiftId = (int)$shift['id'];
        $openTime = $shift['open_time'];
        $closeTime = $shift['close_time'] ?: date('Y-m-d H:i:s');

        $cashSales = 0.0;
        $cardSales = 0.0;
        $digitalSales = 0.0;
        $totalRefunds = 0.0;
        $cashRefunds = 0.0;

        // Query payment_transactions linked by shift_id OR created within shift time window
        $payStmt = $conn->prepare("
            SELECT gateway_name, amount, status, reference_id 
            FROM payment_transactions 
            WHERE restaurant_id = ? 
              AND (shift_id = ? OR (shift_id IS NULL AND created_at >= ? AND created_at <= ?))
        ");
        $payStmt->bind_param("iiss", $tenantId, $shiftId, $openTime, $closeTime);
        $payStmt->execute();
        $pRes = $payStmt->get_result();

        while ($row = $pRes->fetch_assoc()) {
            $gw = strtolower($row['gateway_name']);
            $status = strtolower($row['status']);
            $amt = (float)$row['amount'];

            if ($status === 'paid') {
                if ($gw === 'cash' || strpos($row['reference_id'] ?? '', 'CASH') !== false) {
                    $cashSales += $amt;
                } elseif ($gw === 'card' || strpos($row['reference_id'] ?? '', 'CARD') !== false) {
                    $cardSales += $amt;
                } else {
                    $digitalSales += $amt;
                }
            } elseif ($status === 'refunded') {
                $totalRefunds += $amt;
                if ($gw === 'cash' || strpos($row['reference_id'] ?? '', 'CASH') !== false) {
                    $cashRefunds += $amt;
                }
            }
        }
        $payStmt->close();

        // Calculate Cash Movements (Cash In / Cash Out)
        $cashIn = 0.0;
        $cashOut = 0.0;
        $moveStmt = $conn->prepare("SELECT type, SUM(amount) AS sum_amt FROM register_cash_movements WHERE restaurant_id = ? AND shift_id = ? GROUP BY type");
        $moveStmt->bind_param("ii", $tenantId, $shiftId);
        $moveStmt->execute();
        $mRes = $moveStmt->get_result();
        while ($mrow = $mRes->fetch_assoc()) {
            if ($mrow['type'] === 'cash_in') $cashIn = (float)$mrow['sum_amt'];
            if ($mrow['type'] === 'cash_out') $cashOut = (float)$mrow['sum_amt'];
        }
        $moveStmt->close();

        // Formula: Opening Float + Cash Sales + Cash In - Cash Refunds - Cash Out = Expected Cash
        $openingFloat = (float)$shift['opening_cash'];
        $expectedCash = $openingFloat + $cashSales + $cashIn - $cashRefunds - $cashOut;
        $totalSales = $cashSales + $cardSales + $digitalSales;

        $shift['cash_sales'] = $cashSales;
        $shift['card_sales'] = $cardSales;
        $shift['digital_sales'] = $digitalSales;
        $shift['total_sales'] = $totalSales;
        $shift['total_refunds'] = $totalRefunds;
        $shift['cash_refunds'] = $cashRefunds;
        $shift['cash_in'] = $cashIn;
        $shift['cash_out'] = $cashOut;
        $shift['expected_cash'] = $expectedCash;

        // If shift is closed, keep saved closing_cash and variance
        if ($shift['status'] === 'closed') {
            $shift['variance'] = ((float)$shift['closing_cash']) - $expectedCash;
        }

        return $shift;
    }

    /**
     * Close Register Shift with server-side validation and immutability lock.
     */
    public static function closeShift(mysqli $conn, int $tenantId, int $shiftId, float $actualCash, array $denominations = [], string $notes = '', string $closingUser = 'Cashier'): array {
        self::ensureRegisterShiftSchema($conn);

        if ($actualCash < 0) {
            return ['success' => false, 'error' => "Actual cash counted must be a non-negative value."];
        }

        $shift = self::getShiftById($conn, $tenantId, $shiftId);
        if (!$shift) {
            return ['success' => false, 'error' => "Register shift not found."];
        }

        // IMMUTABILITY GUARD: Cannot close an already closed shift
        if ($shift['status'] === 'closed') {
            return ['success' => false, 'error' => "Shift #$shiftId is already CLOSED and locked. Closed shifts cannot be re-closed or modified."];
        }

        // Enrich real-time metrics
        $shift = self::enrichShiftMetrics($conn, $tenantId, $shift);

        $expectedCash = (float)$shift['expected_cash'];
        $variance = $actualCash - $expectedCash;
        $notes = Security::sanitize(trim($notes));
        $denominationsJson = !empty($denominations) ? json_encode($denominations) : null;

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                UPDATE shifts 
                SET close_time = NOW(), 
                    closing_cash = ?, 
                    expected_cash = ?, 
                    cash_sales = ?, 
                    card_sales = ?, 
                    digital_sales = ?, 
                    total_refunds = ?, 
                    cash_refunds = ?, 
                    cash_in = ?, 
                    cash_out = ?, 
                    variance = ?, 
                    status = 'closed', 
                    denominations_json = ?, 
                    notes = ?, 
                    closed_by = ? 
                WHERE id = ? AND restaurant_id = ? AND status = 'open'
            ");

            $cSales = (float)$shift['cash_sales'];
            $cardSales = (float)$shift['card_sales'];
            $dSales = (float)$shift['digital_sales'];
            $tRefunds = (float)$shift['total_refunds'];
            $cRefunds = (float)$shift['cash_refunds'];
            $cIn = (float)$shift['cash_in'];
            $cOut = (float)$shift['cash_out'];

            $stmt->bind_param("ddddddddddsssii", 
                $actualCash, $expectedCash, $cSales, $cardSales, $dSales, 
                $tRefunds, $cRefunds, $cIn, $cOut, $variance, 
                $denominationsJson, $notes, $closingUser, $shiftId, $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception("SQL Update Error: " . $stmt->error);
            }
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected !== 1) {
                throw new Exception("Shift could not be closed. It may have already been closed concurrently.");
            }

            $conn->commit();

            $varianceText = $variance == 0 ? 'BALANCED' : ($variance > 0 ? "OVER by +Rs. " . number_format($variance, 2) : "SHORT by -Rs. " . number_format(abs($variance), 2));
            Security::logAudit('REGISTER_SHIFT_CLOSED', "Closed Register Shift #$shiftId. Expected: Rs. " . number_format($expectedCash, 2) . " | Actual: Rs. " . number_format($actualCash, 2) . " | Result: $varianceText");

            return ['success' => true, 'variance' => $variance, 'variance_text' => $varianceText];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['success' => false, 'error' => "Failed to close shift: " . $e->getMessage()];
        }
    }

    /**
     * Get single shift by ID with strict tenant scoping.
     */
    public static function getShiftById(mysqli $conn, int $tenantId, int $shiftId): ?array {
        self::ensureRegisterShiftSchema($conn);

        $stmt = $conn->prepare("SELECT * FROM shifts WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("ii", $shiftId, $tenantId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            $res = self::enrichShiftMetrics($conn, $tenantId, $res);

            // Fetch linked cash movements
            $mStmt = $conn->prepare("SELECT * FROM register_cash_movements WHERE restaurant_id = ? AND shift_id = ? ORDER BY id ASC");
            $mStmt->bind_param("ii", $tenantId, $shiftId);
            $mStmt->execute();
            $res['cash_movements'] = $mStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $mStmt->close();
        }

        return $res ?: null;
    }

    /**
     * Get shift history for tenant.
     */
    public static function getShiftHistory(mysqli $conn, int $tenantId, int $limit = 50): array {
        self::ensureRegisterShiftSchema($conn);

        $stmt = $conn->prepare("SELECT * FROM shifts WHERE restaurant_id = ? ORDER BY id DESC LIMIT ?");
        $stmt->bind_param("ii", $tenantId, $limit);
        $stmt->execute();
        $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($history as &$s) {
            $s = self::enrichShiftMetrics($conn, $tenantId, $s);
        }

        return $history;
    }
}
