<?php
// helpers/RateLimiter.php - Rate Limiting & Brute Force Protection

class RateLimiter {

    /**
     * Check if client IP / key exceeded rate limit
     * @param string $key Unique identifier for key/action (e.g. "login_ip")
     * @param int $maxAttempts Allowed attempts
     * @param int $decaySeconds Lockout duration in seconds
     */
    public static function isExceeded($key, $maxAttempts = 5, $decaySeconds = 300) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $sessionKey = 'rate_limit_' . md5($key . '_' . $ip);

        $now = time();
        $data = $_SESSION[$sessionKey] ?? ['attempts' => 0, 'first_attempt' => $now, 'locked_until' => 0];

        // Check if currently locked
        if (!empty($data['locked_until']) && $now < $data['locked_until']) {
            return true;
        }

        // Reset if decay period passed since first attempt
        if ($now - $data['first_attempt'] > $decaySeconds) {
            $data = ['attempts' => 0, 'first_attempt' => $now, 'locked_until' => 0];
        }

        return false;
    }

    /**
     * Hit / Record an attempt for a key
     */
    public static function hit($key, $maxAttempts = 5, $decaySeconds = 300) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $sessionKey = 'rate_limit_' . md5($key . '_' . $ip);

        $now = time();
        $data = $_SESSION[$sessionKey] ?? ['attempts' => 0, 'first_attempt' => $now, 'locked_until' => 0];

        if ($now - $data['first_attempt'] > $decaySeconds) {
            $data = ['attempts' => 0, 'first_attempt' => $now, 'locked_until' => 0];
        }

        $data['attempts'] += 1;

        if ($data['attempts'] >= $maxAttempts) {
            $data['locked_until'] = $now + $decaySeconds;
        }

        $_SESSION[$sessionKey] = $data;
    }

    /**
     * Clear / Reset attempts on successful action
     */
    public static function clear($key) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $sessionKey = 'rate_limit_' . md5($key . '_' . $ip);
        unset($_SESSION[$sessionKey]);
    }

    /**
     * Require rate limit check or respond with 429 Too Many Requests.
     * Automatically increments counter (hit) when called (Fixes RMS-002 & RMS-003).
     */
    public static function enforce($key, $maxAttempts = 5, $decaySeconds = 300) {
        // Automatically record hit on enforce call
        self::hit($key, $maxAttempts, $decaySeconds);

        if (self::isExceeded($key, $maxAttempts, $decaySeconds)) {
            http_response_code(429);
            $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                      (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
                      (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
                exit;
            }

            die('<!DOCTYPE html><html lang="en" class="h-full bg-zinc-950 text-white"><head><meta charset="UTF-8"><title>429 Too Many Requests</title><script src="https://cdn.tailwindcss.com"></script></head><body class="h-full flex items-center justify-center p-4 text-center"><div class="max-w-md bg-zinc-900 border border-zinc-800 p-8 rounded-3xl space-y-4"><div class="text-5xl">⏳</div><h1 class="text-xl font-black text-white">429 Too Many Requests</h1><p class="text-xs text-zinc-400">Rate limit exceeded. Please wait a few minutes before trying again.</p></div></body></html>');
        }
    }
}
