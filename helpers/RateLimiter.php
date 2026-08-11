<?php
// helpers/RateLimiter.php - Server-Side Rate Limiting (DB-backed store)

class RateLimiter {
    private static $tableReady = null;

    /**
     * Ensure the rate_limits table exists. Runs at most once per request.
     */
    private static function ensureTable(mysqli $conn): void {
        if (self::$tableReady === true) return;

        $found = $conn->query("SHOW TABLES LIKE 'rate_limits'");
        $exists = $found && $found->num_rows > 0;
        if (!$exists) {
            $conn->query("CREATE TABLE IF NOT EXISTS rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rate_key VARCHAR(190) NOT NULL,
                window_start BIGINT NOT NULL,
                hits INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_rate_key_window (rate_key, window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $exists = ($conn->error === '');
        }
        self::$tableReady = $exists;
    }

    /**
     * Record one hit for a rate-limited action.
     */
    public static function hit(string $key, int $windowOrLimit = 300, ?int $windowSeconds = null): void {
        $actualWindow = ($windowSeconds !== null) ? $windowSeconds : $windowOrLimit;
        $conn = getDBConnection();
        if ($conn) {
            self::ensureTable($conn);
            if (self::$tableReady === true) {
                $now = time();
                $window = (int)floor($now / max(1, $actualWindow));
                $stmt = $conn->prepare("INSERT INTO rate_limits (rate_key, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1");
                if ($stmt) {
                    $stmt->bind_param("si", $key, $window);
                    $stmt->execute();
                    $stmt->close();
                }
                // Opportunistic pruning (best-effort, not on the critical path).
                $pruneWindow = (int)floor(($now - 172800) / max(1, $actualWindow));
                $conn->query("DELETE FROM rate_limits WHERE window_start < " . $pruneWindow);
                return;
            }
        }

        // Best-effort fallback when the DB store is unavailable (never a total bypass).
        Auth::startSession();
        $sk = 'rl_' . md5($key);
        $w = (int)floor(time() / max(1, $actualWindow));
        if (!isset($_SESSION[$sk]['w']) || $_SESSION[$sk]['w'] !== $w) {
            $_SESSION[$sk] = ['w' => $w, 'h' => 0];
        }
        $_SESSION[$sk]['h']++;
    }

    /**
     * Check whether a rate-limited action is currently allowed.
     * Returns true when the action may proceed, false when the limit is exceeded.
     */
    public static function check(string $key, int $limit, int $windowSeconds): bool {
        $conn = getDBConnection();
        if ($conn) {
            self::ensureTable($conn);
            if (self::$tableReady === true) {
                $window = (int)floor(time() / max(1, $windowSeconds));
                $stmt = $conn->prepare("SELECT hits FROM rate_limits WHERE rate_key = ? AND window_start = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("si", $key, $window);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res->fetch_assoc();
                    $stmt->close();
                    $hits = $row ? (int)$row['hits'] : 0;
                    return $hits < $limit;
                }
            }
        }

        Auth::startSession();
        $sk = 'rl_' . md5($key);
        $w = (int)floor(time() / max(1, $windowSeconds));
        $hits = (isset($_SESSION[$sk]['w']) && $_SESSION[$sk]['w'] === $w) ? (int)$_SESSION[$sk]['h'] : 0;
        return $hits < $limit;
    }

    /**
     * Convenience wrapper: enforce a limit or exit with 429.
     */
    public static function limit(string $key, int $limit, int $windowSeconds): void {
        if (!self::check($key, $limit, $windowSeconds)) {
            http_response_code(429);
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
                exit;
            }
            die('Too many requests. Please wait a few minutes before trying again.');
        }
    }

    /**
     * Backward-compatible alias for limit().
     */
    public static function enforce(string $key, int $limit, int $windowSeconds): void {
        self::limit($key, $limit, $windowSeconds);
    }

    /**
     * Clear rate limit hits for a specific key (e.g. after a successful login)
     */
    public static function clear(string $key): void {
        $conn = getDBConnection();
        if ($conn) {
            self::ensureTable($conn);
            if (self::$tableReady === true) {
                $stmt = $conn->prepare("DELETE FROM rate_limits WHERE rate_key = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $key);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        Auth::startSession();
        $sk = 'rl_' . md5($key);
        if (isset($_SESSION[$sk])) {
            unset($_SESSION[$sk]);
        }
    }
}
