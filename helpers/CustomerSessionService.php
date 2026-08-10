<?php
// helpers/CustomerSessionService.php - Customer Table Dining Session Validation & Management

class CustomerSessionService {

    /**
     * Establish a customer table session upon valid QR scan or signed URL.
     */
    public static function establishSession($tableNumber, $qrToken, $restaurantId) {
        Auth::startSession();
        $_SESSION['customer_table_id'] = strval($tableNumber);
        $_SESSION['customer_table_token'] = strval($qrToken);
        $_SESSION['customer_restaurant_id'] = (int)$restaurantId;
        $_SESSION['restaurant_id'] = (int)$restaurantId;
        $_SESSION['customer_session_created'] = time();
        $_SESSION['customer_session_expires'] = time() + 7200; // 2-hour rolling idle window
        $_SESSION['customer_last_activity'] = time();
    }

    /**
     * Validate the active customer table session server-side.
     * Enforces session presence, expiration, table status, and tenant isolation.
     * Returns array: ['valid' => bool, 'code' => int, 'title' => string, 'message' => string, 'table' => array|null]
     */
    public static function validateSession($conn = null) {
        Auth::startSession();

        // 1. Session variables check
        if (empty($_SESSION['customer_table_id']) || empty($_SESSION['customer_table_token']) || empty($_SESSION['customer_restaurant_id'])) {
            return [
                'valid' => false,
                'code' => 403,
                'title' => '🔒 Session Expired',
                'message' => 'Please scan the table QR code again to access checkout.'
            ];
        }

        // 2. Server-side expiration check (Asia/Kathmandu timezone aligned)
        $now = time();
        $expires = (int)($_SESSION['customer_session_expires'] ?? 0);
        if ($expires > 0 && $now > $expires) {
            self::destroySession();
            return [
                'valid' => false,
                'code' => 403,
                'title' => '🔒 Session Expired',
                'message' => 'Your ordering session has expired. Please scan the table QR code again.'
            ];
        }

        // 3. Database verification of table & tenant
        if (!$conn) {
            $conn = getDBConnection();
        }
        if (!$conn) {
            return [
                'valid' => false,
                'code' => 500,
                'title' => '⚠️ Service Error',
                'message' => 'Database connection unavailable.'
            ];
        }

        $tableNum = strval($_SESSION['customer_table_id']);
        $qrToken = strval($_SESSION['customer_table_token']);
        $restaurantId = (int)$_SESSION['customer_restaurant_id'];

        $stmt = $conn->prepare("
            SELECT t.id, t.table_number, t.status as table_status, t.qr_token,
                   r.id as rest_id, r.status as restaurant_status, r.restaurant_name
            FROM tables t
            JOIN restaurants r ON t.restaurant_id = r.id
            WHERE t.qr_token = ? AND t.table_number = ? AND t.restaurant_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return [
                'valid' => false,
                'code' => 500,
                'title' => '⚠️ System Error',
                'message' => 'Unable to verify table session.'
            ];
        }

        $stmt->bind_param("ssi", $qrToken, $tableNum, $restaurantId);
        $stmt->execute();
        $res = $stmt->get_result();
        $tableData = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$tableData) {
            self::destroySession();
            return [
                'valid' => false,
                'code' => 403,
                'title' => '❌ Invalid Table Session',
                'message' => 'The table QR token could not be verified. Please scan the official table QR code again.'
            ];
        }

        if (strtolower($tableData['restaurant_status']) !== 'active') {
            return [
                'valid' => false,
                'code' => 403,
                'title' => '🏢 Restaurant Unavailable',
                'message' => 'This restaurant is currently unavailable.'
            ];
        }

        if (strtolower($tableData['table_status']) === 'disabled') {
            return [
                'valid' => false,
                'code' => 403,
                'title' => '❌ Table Currently Unavailable',
                'message' => 'This table is no longer available.'
            ];
        }

        // 4. Session continuation / rolling expiration renewal on activity
        $_SESSION['customer_session_expires'] = time() + 7200;
        $_SESSION['customer_last_activity'] = time();

        return [
            'valid' => true,
            'code' => 200,
            'title' => 'OK',
            'message' => 'Session valid',
            'table' => $tableData
        ];
    }

    /**
     * Destroy customer session variables
     */
    public static function destroySession() {
        Auth::startSession();
        unset(
            $_SESSION['customer_table_id'],
            $_SESSION['customer_table_token'],
            $_SESSION['customer_restaurant_id'],
            $_SESSION['customer_session_created'],
            $_SESSION['customer_session_expires'],
            $_SESSION['customer_last_activity']
        );
    }
}
