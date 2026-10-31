<?php
/**
 * YBT Management Software — Bootstrap
 * Include this at the top of every page.
 */

if (!defined('YBT_ROOT')) {
    define('YBT_ROOT', dirname(__DIR__));
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

// Session configuration
ini_set('session.cookie_httponly', $config['session_httponly'] ? '1' : '0');
ini_set('session.cookie_samesite', $config['session_samesite']);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if ($config['session_secure']) {
    ini_set('session.cookie_secure', '1');
}
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
require_once YBT_ROOT . '/includes/activity-log.php';
require_once YBT_ROOT . '/includes/auth.php';
require_once YBT_ROOT . '/includes/permissions.php';

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
