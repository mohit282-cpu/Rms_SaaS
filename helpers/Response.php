<?php
// helpers/Response.php - Standardized HTTP & JSON Response Helper

class Response {

    /**
     * Send JSON Response with HTTP status code
     */
    public static function json($data, $statusCode = 200) {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=UTF-8');
            // Realtime API responses must never be cached by the browser/proxy
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send JSON Success Response
     */
    public static function success($message = 'Success', $data = [], $statusCode = 200) {
        self::json(array_merge(['success' => true, 'message' => $message], $data), $statusCode);
    }

    /**
     * Send JSON Error Response (consistent machine-readable error contract)
     */
    public static function error($message = 'An error occurred', $statusCode = 400, $code = 'ERROR') {
        self::json([
            'success' => false,
            'message' => $message,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ], $statusCode);
    }
}
