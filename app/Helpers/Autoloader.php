<?php
// app/Helpers/Autoloader.php - Custom PSR-4 Autoloader & Environment Loader

class Autoloader {
    /**
     * Register PSR-4 Autoloader for 'App\' namespace
     */
    public static function register() {
        spl_autoload_register(function ($class) {
            $prefix = 'App\\';
            $base_dir = __DIR__ . '/../';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });
    }

    /**
     * Load .env environment variables into $_ENV and getenv()
     */
    public static function loadEnv($envPath) {
        if (!file_exists($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value, " \t\n\r\0\x0B\"'");

                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv(sprintf('%s=%s', $name, $value));
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}
