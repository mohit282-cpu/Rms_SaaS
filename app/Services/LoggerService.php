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

    public static function log($level, $message, array $context = []) {
        $logFile = self::getLogFile('app');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $entry = sprintf("[%s] [%s] [%s] %s%s%s", $timestamp, strtoupper($level), $ip, $message, $contextStr, PHP_EOL);

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    public static function security($event, array $context = []) {
        $logFile = self::getLogFile('security');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $user = $_SESSION['admin_username'] ?? 'guest';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $entry = sprintf("[%s] [SECURITY] [IP:%s] [USER:%s] %s%s%s", $timestamp, $ip, $user, $event, $contextStr, PHP_EOL);

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
