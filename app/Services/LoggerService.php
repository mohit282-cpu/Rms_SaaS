<?php
namespace App\Services;

class LoggerService {

    private static function getLogFile($channel = 'daily') {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $date = date('Y-m-d');
        return $logDir . '/' . $channel . '-' . $date . '.log';
    }

    public static function getCorrelationId(): string {
        static $correlationId = null;
        if ($correlationId !== null) {
            return $correlationId;
        }

        if (!empty($_SERVER['HTTP_X_CORRELATION_ID'])) {
            $correlationId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_SERVER['HTTP_X_CORRELATION_ID']);
        } else {
            $correlationId = 'RMS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        }

        if (!headers_sent()) {
            header('X-Correlation-ID: ' . $correlationId);
        }

        return $correlationId;
    }

    public static function log($level, $message, array $context = []) {
        $logFile = self::getLogFile('app');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $timestamp = date('Y-m-d H:i:s');
        $reqId = self::getCorrelationId();
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $entry = sprintf("[%s] [%s] [%s] [REQ:%s] %s%s%s", $timestamp, strtoupper($level), $ip, $reqId, $message, $contextStr, PHP_EOL);

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    public static function security($event, array $context = []) {
        $logFile = self::getLogFile('security');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $user = $_SESSION['email'] ?? $_SESSION['admin_email'] ?? 'guest';
        $timestamp = date('Y-m-d H:i:s');
        $reqId = self::getCorrelationId();
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $entry = sprintf("[%s] [SECURITY] [IP:%s] [USER:%s] [REQ:%s] %s%s%s", $timestamp, $ip, $user, $reqId, $event, $contextStr, PHP_EOL);

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    public static function info($message, array $context = []) {
        self::log('info', $message, $context);
    }

    public static function warning($message, array $context = []) {
        self::log('warning', $message, $context);
    }

    public static function error($message, array $context = []) {
        self::log('error', $message, $context);
    }
}
