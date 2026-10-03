<?php
/**
 * config/env.php
 * -----------------------------------------------------------
 * Production Environment Configuration Loader.
 * Safely loads key-value pairs from .env file into getenv(),
 * $_ENV, and $_SERVER without overwriting existing environment variables.
 */

if (!function_exists('load_env')) {
    function load_env(string $path = __DIR__ . '/../.env'): void
    {
        if (!file_exists($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments and empty lines
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip enclosing quotes if present
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            // Only set if not already set in OS environment
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

// Automatically load .env if present
load_env();

// Production error reporting and display policy
$app_env = getenv('APP_ENV') ?: 'production';
$app_debug = filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);

if ($app_env === 'production' && !$app_debug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

ini_set('log_errors', '1');
if (!ini_get('error_log')) {
    ini_set('error_log', __DIR__ . '/../logs/php_error.log');
}
