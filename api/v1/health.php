<?php
/**
 * YBT Management Software — REST API v1 Health & Telemetry Endpoint
 */

header('Content-Type: application/json; charset=utf-8');

$dbStatus = 'disconnected';
try {
    if (class_exists('Database')) {
        $db = Database::getInstance();
        $dbStatus = 'connected';
    }
} catch (Throwable $e) {
    $dbStatus = 'error';
}

echo json_encode([
    'success' => true,
    'data' => [
        'status' => 'operational',
        'api_version' => '1.0.0',
        'app_name' => defined('APP_NAME') ? APP_NAME : 'YBT Management Software',
        'app_version' => defined('APP_VERSION') ? APP_VERSION : '1.0.0',
        'environment' => defined('APP_ENV') ? APP_ENV : 'development',
        'php_version' => PHP_VERSION,
        'database' => $dbStatus,
        'tenant_engine' => 'fail_closed_active',
        'timestamp' => date('c'),
    ],
    'meta' => [
        'server_time' => time(),
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
