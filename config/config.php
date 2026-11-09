<?php
/**
 * YBT Management Software — Application Configuration
 * Environment-specific settings. Keep production secrets out of version control.
 */

// Prevent direct access
if (!defined('YBT_ROOT')) {
    define('YBT_ROOT', dirname(__DIR__));
}

// Global environment variable accessor (checks $_ENV, $_SERVER, and getenv)
if (!function_exists('app_env')) {
    function app_env(string $key, mixed $default = null): mixed {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return $val;
        }
        return $default;
    }
}

return [
    // Application
    'app_name'    => app_env('APP_NAME', 'DJ CRM Software'),
    'app_version' => '5.2.1',
    'cloud_sync_url' => app_env('CLOUD_SYNC_URL', 'https://apkashow.com'),
    'whatsapp_support' => app_env('WHATSAPP_SUPPORT_NUMBER', '923000000000'), // Configurable support number
    'app_url'     => (function() {
        if ($env = app_env('APP_URL')) {
            return rtrim($env, '/');
        }
        if (isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
            if ($scriptDir !== '/' && $scriptDir !== '.' && !empty($scriptDir)) {
                return $scheme . '://' . $host . $scriptDir;
            }
            return $scheme . '://' . $host;
        }
        return 'http://localhost/YBT%20Management%20Software';
    })(),
    'environment' => app_env('APP_ENV', 'production'), // development | production
    'debug'       => filter_var(app_env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
    'timezone'    => app_env('APP_TIMEZONE', 'Asia/Karachi'),

    // Database
    'db_host'     => app_env('DB_HOST', 'localhost'),
    'db_port'     => (int)app_env('DB_PORT', 3306),
    'db_name'     => app_env('DB_NAME', 'u290903472_djcrm'),
    'db_user'     => app_env('DB_USER', ''),
    'db_pass'     => app_env('DB_PASS', ''),
    'db_charset'  => app_env('DB_CHARSET', 'utf8mb4'),

    // Session
    'session_name'     => 'YBT_SESSION',
    'session_lifetime' => 7200, // 2 hours
    'session_secure'   => (app_env('APP_ENV', 'production') === 'production'), // set true under HTTPS in production
    'session_httponly'  => true,
    'session_samesite'  => 'Lax',

    // Storage
    'storage_path'  => YBT_ROOT . '/storage',
    'upload_max_mb' => 10,

    // Security
    'login_max_attempts'  => 5,
    'login_lockout_mins'  => 15,
    'password_reset_ttl'  => 3600, // 1 hour
    'csrf_token_lifetime' => 3600,

    // Encryption key for API keys at rest (read from environment, with secure fallback)
    'encryption_key' => app_env('YBT_ENCRYPTION_KEY', app_env('APP_KEY', 'CHANGE_THIS_IN_PRODUCTION_32_CHAR_KEY!')),
];
