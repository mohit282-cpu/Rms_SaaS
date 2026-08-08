<?php
// helpers/CSRF.php - Cross-Site Request Forgery Protection Middleware

class CSRF {
    
    /**
     * Generate or return existing CSRF token for the session
     */
    public static function generateToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Get HTML input field containing CSRF Token
     */
    public static function getField() {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Verify CSRF token from request
     */
    public static function verifyToken($token = null) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        }

        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Enforce CSRF verification or exit with 403 Forbidden
     */
    public static function requireValidToken() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::verifyToken()) {
                http_response_code(403);
                $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                          (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
                
                if ($isJson) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'CSRF verification failed. Request denied.']);
                    exit;
                }

                die('<!DOCTYPE html><html lang="en" class="h-full bg-zinc-950 text-white"><head><meta charset="UTF-8"><title>403 CSRF Error</title><script src="https://cdn.tailwindcss.com"></script></head><body class="h-full flex items-center justify-center p-4 text-center"><div class="max-w-md bg-zinc-900 border border-zinc-800 p-8 rounded-3xl space-y-4"><div class="text-5xl">🛑</div><h1 class="text-xl font-black text-white">403 Security Check Failed</h1><p class="text-xs text-zinc-400">Invalid or missing CSRF token. Please refresh the page and try again.</p></div></body></html>');
            }
        }
    }
}
