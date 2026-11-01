<?php
/**
 * YBT Management Software — Application Configuration
 * Environment-specific settings. Keep production secrets out of version control.
 */

// Prevent direct access
if (!defined('YBT_ROOT')) {
    define('YBT_ROOT', dirname(__DIR__));
}

return [
    // Application
    'app_name'    => 'YBT Management Software',
    'app_version' => '1.0.0',
    'app_url'     => (function() {
        if ($env = getenv('APP_URL')) {
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
    'environment' => getenv('APP_ENV') ?: 'development', // development | production
    'debug'       => getenv('APP_DEBUG') !== false ? filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN) : true,
    'timezone'    => 'Asia/Kolkata',

    // Database
    'db_host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port'     => (int)(getenv('DB_PORT') ?: 3306),
    'db_name'     => getenv('DB_NAME') ?: 'ybt_management',
    'db_user'     => getenv('DB_USER') ?: 'root',
    'db_pass'     => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'db_charset'  => 'utf8mb4',

    // Session
    'session_name'     => 'YBT_SESSION',
    'session_lifetime' => 7200, // 2 hours
    'session_secure'   => (getenv('APP_ENV') === 'production'), // set true under HTTPS in production
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
    'encryption_key' => getenv('YBT_ENCRYPTION_KEY') ?: getenv('APP_KEY') ?: 'CHANGE_THIS_IN_PRODUCTION_32_CHAR_KEY!',
];
