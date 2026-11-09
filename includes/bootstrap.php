<?php
/**
 * YBT Management Software — Bootstrap
 * Include this at the top of every page.
 */

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

// Load environment variables from .env (check web root first, then parent directory if allowed)
$envCandidates = [
    YBT_ROOT . '/.env',           // Standard application root (e.g. public_html/.env)
    dirname(YBT_ROOT) . '/.env', // 1 level above public_html (if permitted by open_basedir)
];
$envFile = null;
foreach ($envCandidates as $candidate) {
    if (@file_exists($candidate) && @is_readable($candidate)) {
        $envFile = $candidate;
        break;
    }
}
if ($envFile !== null) {
    $envLines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($envLines !== false) {
        foreach ($envLines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (strpos($line, '=') !== false) {
                list($envKey, $envVal) = explode('=', $line, 2);
                $envKey = trim($envKey);
                $envVal = trim($envVal, " \t\n\r\0\x0B\"'");
                // Always populate $_ENV, $_SERVER, and putenv
                $_ENV[$envKey] = $envVal;
                $_SERVER[$envKey] = $envVal;
                @putenv("{$envKey}={$envVal}");
            }
        }
    }
}

// Load configuration
$config = require YBT_ROOT . '/config/config.php';

// Set timezone
date_default_timezone_set($config['timezone']);

// App URL constant
define('APP_URL', rtrim($config['app_url'], '/'));
define('APP_NAME', $config['app_name']);
define('APP_VERSION', $config['app_version']);
define('APP_DEBUG', $config['debug']);
if (!defined('CLOUD_SYNC_URL')) {
    define('CLOUD_SYNC_URL', rtrim($config['cloud_sync_url'] ?? (getenv('CLOUD_SYNC_URL') ?: 'https://apkashow.com'), '/'));
}
define('WHATSAPP_SUPPORT_NUMBER', class_exists('PlatformSettings') ? PlatformSettings::getWhatsAppSupport() : ($config['whatsapp_support'] ?? '923000000000'));

// Error reporting
if ($config['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', YBT_ROOT . '/storage/logs/php-errors.log');

// Clear OPcache if requested via trigger or deployment refresh
if (isset($_GET['opcache_reset']) || isset($_GET['clear_cache'])) {
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

// Session configuration & persistent save path
$sessionSavePath = YBT_ROOT . '/storage/sessions';
if (getenv('APPDATA')) {
    $sessionSavePath = str_replace('\\', '/', getenv('APPDATA')) . '/YBT Management Software/storage/sessions';
}
if (!is_dir($sessionSavePath)) {
    @mkdir($sessionSavePath, 0755, true);
}
if (is_dir($sessionSavePath) && is_writable($sessionSavePath)) {
    session_save_path($sessionSavePath);
}

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_path', '/');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_secure', '0'); // Loopback 127.0.0.1 is HTTP
session_name($config['session_name']);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load includes
require_once YBT_ROOT . '/includes/tenant-context.php';
require_once YBT_ROOT . '/includes/db.php';
require_once YBT_ROOT . '/includes/functions.php';
require_once YBT_ROOT . '/includes/formatting.php';
require_once YBT_ROOT . '/includes/flash.php';
require_once YBT_ROOT . '/includes/csrf.php';
require_once YBT_ROOT . '/includes/validation.php';
require_once YBT_ROOT . '/includes/phone-validator.php';
require_once YBT_ROOT . '/includes/activity-log.php';
require_once YBT_ROOT . '/includes/auth.php';
require_once YBT_ROOT . '/includes/permissions.php';
require_once YBT_ROOT . '/services/platform-settings-service.php';
require_once YBT_ROOT . '/services/subscription-service.php';

// Auto-bind TenantContext if user session is active
if (!empty($_SESSION['business_id']) && (int)$_SESSION['business_id'] > 0) {
    TenantContext::set((int)$_SESSION['business_id'], [
        'name' => $_SESSION['business_name'] ?? '',
        'user_id' => $_SESSION['user_id'] ?? null,
        'role_slug' => $_SESSION['role_slug'] ?? '',
    ]);
}

// Ensure storage directories exist
$storageDirs = ['invoices', 'uploads', 'exports', 'backups', 'logs', 'ai-cache', 'temp'];
foreach ($storageDirs as $dir) {
    $path = YBT_ROOT . '/storage/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
}

// Load defaults
$defaults = require YBT_ROOT . '/config/defaults.php';
