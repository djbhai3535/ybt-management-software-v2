<?php
/**
 * DJ CRM Software — System Health & Diagnostics Panel
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';

$pageTitle = 'System Health & Diagnostics';

// Handle Clear Log action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired.');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'clear_log') {
            $logFile = YBT_ROOT . '/storage/logs/error.log';
            if (file_exists($logFile)) {
                file_put_contents($logFile, '');
                Flash::success('Error log has been cleared.');
            } else {
                Flash::info('Error log file was already empty or not present.');
            }
            header('Location: ' . url('admin/system-health.php'));
            exit;
        }
    }
}

// 1. PHP Checks
$phpVersion = PHP_VERSION;
$phpVersionOk = version_compare($phpVersion, '8.2.0', '>=');
$requiredExtensions = [
    'pdo' => 'PDO Data Objects',
    'pdo_mysql' => 'MySQL Driver',
    'pdo_sqlite' => 'SQLite Driver (Offline/Desktop)',
    'mbstring' => 'Multibyte String',
    'openssl' => 'OpenSSL Encryption',
    'curl' => 'cURL HTTP Client (Gmail / APIs)',
    'gd' => 'GD Image Processing',
    'zip' => 'ZipArchive Compression',
    'json' => 'JSON Parser'
];
$extensionStatus = [];
foreach ($requiredExtensions as $ext => $label) {
    $extensionStatus[$ext] = [
        'label' => $label,
        'loaded' => extension_loaded($ext)
    ];
}

// 2. Database Checks
$dbDriver = Database::driver();
$dbConnected = false;
$dbVersion = 'Unknown';
$totalTables = 0;
$tableList = [];
$missingTables = [];

$expected48Tables = [
    'businesses', 'roles', 'permissions', 'role_permissions', 'users', 'user_preferences',
    'business_settings', 'email_verifications', 'password_resets', 'staff_invitations',
    'api_tokens', 'sync_devices', 'api_idempotency_keys', 'sync_operations_queue',
    'sync_tombstones', 'customers', 'customer_ledger', 'leads', 'products',
    'stock_movements', 'tasks', 'quotations', 'quotation_items', 'invoices',
    'invoice_items', 'payments', 'payment_allocations', 'expense_categories',
    'expenses', 'subscription_plans', 'business_subscriptions', 'platform_settings',
    'platform_announcements', 'files', 'notifications', 'activity_logs',
    'backup_records', 'schema_migrations', 'ai_provider_configs', 'ai_access_policies',
    'ai_conversations', 'ai_messages', 'ai_memory_entries', 'ai_prompt_versions',
    'ai_action_proposals', 'ai_approval_events', 'ai_usage_logs', 'advertisements'
];

try {
    $db = Database::getInstance();
    $dbConnected = true;
    if ($dbDriver === 'sqlite') {
        $dbVersion = $db->query("SELECT sqlite_version()")->fetchColumn();
        $tables = Database::fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tableList = array_column($tables, 'name');
    } else {
        $dbVersion = $db->query("SELECT VERSION()")->fetchColumn();
        $tables = Database::fetchAll("SHOW TABLES");
        foreach ($tables as $t) {
            $tableList[] = reset($t);
        }
    }
    $totalTables = count($tableList);
    foreach ($expected48Tables as $exp) {
        if (!in_array($exp, $tableList, true)) {
            $missingTables[] = $exp;
        }
    }
} catch (\Throwable $e) {
    $dbConnected = false;
}

// 3. Writable Directories Check
$directories = [
    'storage' => YBT_ROOT . '/storage',
    'storage/logs' => YBT_ROOT . '/storage/logs',
    'storage/backups' => YBT_ROOT . '/storage/backups',
    'assets/uploads' => YBT_ROOT . '/assets/uploads'
];
$dirStatus = [];
foreach ($directories as $name => $path) {
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $dirStatus[$name] = [
        'path' => $path,
        'exists' => $exists,
        'writable' => $writable
    ];
}

// 4. Security Check
$envFile = YBT_ROOT . '/.env';
$hasEnv = file_exists($envFile);
$appKeySet = defined('APP_KEY') && strlen(APP_KEY) >= 32;
$httpsActive = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

// 5. Error Log Inspection
$logFile = YBT_ROOT . '/storage/logs/error.log';
$logLines = [];
$logSize = 0;
if (file_exists($logFile)) {
    $logSize = filesize($logFile);
    if ($logSize > 0) {
        $content = file_get_contents($logFile);
        $allLines = explode("\n", trim($content));
        $logLines = array_slice($allLines, -60);
    }
}

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;">System Health & Diagnostics</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
            Real-time server telemetry, PHP runtime compatibility, database integrity, and production log viewer.
        </p>
    </div>
    <div>
        <a href="<?= url('admin/system-health.php') ?>" class="btn btn-secondary btn-sm">
            <i class="ti ti-refresh mr-1"></i> Refresh Status
        </a>
    </div>
</div>

<!-- Diagnostics Grid -->
<div class="grid grid-cols-2 gap-4 mb-4">
    <!-- PHP Runtime Card -->
    <div class="card p-4">
        <div class="d-flex align-center justify-between mb-3 border-bottom pb-2">
            <h3 style="font-size: 1.1rem; font-weight: 700; margin: 0;">
                <i class="ti ti-brand-php text-primary mr-1"></i> PHP Runtime Environment
            </h3>
            <span class="badge badge-<?= $phpVersionOk ? 'success' : 'danger' ?>">
                PHP <?= $phpVersion ?>
            </span>
        </div>
        <table class="data-table">
            <tbody>
                <tr>
                    <td>PHP Version Target</td>
                    <td><strong>PHP 8.2 or 8.3 Recommended</strong></td>
                    <td class="text-right">
                        <?php if ($phpVersionOk): ?>
                            <span class="badge badge-success"><i class="ti ti-check"></i> Compatible</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="ti ti-x"></i> Needs Upgrade</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Memory Limit</td>
                    <td><strong><?= ini_get('memory_limit') ?></strong></td>
                    <td class="text-right"><span class="badge badge-info">Standard</span></td>
                </tr>
                <tr>
                    <td>Max Execution Time</td>
                    <td><strong><?= ini_get('max_execution_time') ?>s</strong></td>
                    <td class="text-right"><span class="badge badge-info">CLI/CGI</span></td>
                </tr>
                <tr>
                    <td>Upload Max Filesize</td>
                    <td><strong><?= ini_get('upload_max_filesize') ?></strong></td>
                    <td class="text-right"><span class="badge badge-info">Files/Media</span></td>
                </tr>
                <tr>
                    <td>OPcache Accelerator</td>
                    <td><strong><?= extension_loaded('Zend OPcache') ? 'Enabled' : 'Disabled' ?></strong></td>
                    <td class="text-right">
                        <span class="badge badge-<?= extension_loaded('Zend OPcache') ? 'success' : 'secondary' ?>">
                            <?= extension_loaded('Zend OPcache') ? 'Accelerated' : 'Off' ?>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="mt-3">
            <div class="font-bold text-xs uppercase text-muted mb-2">Required PHP Extensions</div>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($extensionStatus as $ext => $info): ?>
                    <span class="badge badge-<?= $info['loaded'] ? 'success' : 'danger' ?>" title="<?= e($info['label']) ?>">
                        <?= $info['loaded'] ? '✓' : '✗' ?> <?= $ext ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Database Integrity Card -->
    <div class="card p-4">
        <div class="d-flex align-center justify-between mb-3 border-bottom pb-2">
            <h3 style="font-size: 1.1rem; font-weight: 700; margin: 0;">
                <i class="ti ti-database text-warning mr-1"></i> Database Engine & Schema
            </h3>
            <span class="badge badge-<?= $dbConnected ? 'success' : 'danger' ?>">
                <?= strtoupper($dbDriver) ?> <?= $dbConnected ? 'ONLINE' : 'OFFLINE' ?>
            </span>
        </div>
        <table class="data-table">
            <tbody>
                <tr>
                    <td>Database Version</td>
                    <td><strong><?= e($dbVersion) ?></strong></td>
                    <td class="text-right"><span class="badge badge-secondary"><?= strtoupper($dbDriver) ?></span></td>
                </tr>
                <tr>
                    <td>Active Tables Detected</td>
                    <td><strong><?= $totalTables ?> Tables</strong></td>
                    <td class="text-right">
                        <?php if (count($missingTables) === 0): ?>
                            <span class="badge badge-success"><i class="ti ti-check"></i> Complete (48/48)</span>
                        <?php else: ?>
                            <span class="badge badge-warning"><?= count($missingTables) ?> Missing</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Schema Health Status</td>
                    <td colspan="2">
                        <?php if (empty($missingTables)): ?>
                            <span class="text-success font-bold">✓ All 48 Core Tables Verified</span>
                        <?php else: ?>
                            <span class="text-danger font-bold">⚠️ Missing: <?= implode(', ', array_slice($missingTables, 0, 5)) ?>...</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="mt-3">
            <div class="font-bold text-xs uppercase text-muted mb-2">Filesystem Write Permissions</div>
            <table class="data-table">
                <tbody>
                    <?php foreach ($dirStatus as $dName => $st): ?>
                    <tr>
                        <td style="font-family: monospace; font-size: 12px;"><?= $dName ?></td>
                        <td>
                            <?php if ($st['writable']): ?>
                                <span class="badge badge-success">Writable</span>
                            <?php elseif ($st['exists']): ?>
                                <span class="badge badge-warning">Read Only</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Not Created</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Security Status -->
<div class="card p-4 mb-4">
    <h3 style="font-size: 1.1rem; font-weight: 700; margin: 0 0 1rem 0;">
        <i class="ti ti-shield-check text-success mr-1"></i> Security & Environmental Isolation
    </h3>
    <div class="grid grid-cols-3 gap-3">
        <div class="card p-3" style="background: var(--color-bg);">
            <div class="text-muted text-xs uppercase font-bold mb-1">Environment File (.env)</div>
            <div class="d-flex align-center gap-2">
                <?php if ($hasEnv): ?>
                    <span class="badge badge-success">✓ Isolated & Present</span>
                <?php else: ?>
                    <span class="badge badge-warning">⚠️ Using Hostinger/Defaults</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card p-3" style="background: var(--color-bg);">
            <div class="text-muted text-xs uppercase font-bold mb-1">Application Master Key</div>
            <div class="d-flex align-center gap-2">
                <?php if ($appKeySet): ?>
                    <span class="badge badge-success">✓ High-Entropy Key Set</span>
                <?php else: ?>
                    <span class="badge badge-danger">✗ Default / Insecure Key</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card p-3" style="background: var(--color-bg);">
            <div class="text-muted text-xs uppercase font-bold mb-1">HTTPS Encryption</div>
            <div class="d-flex align-center gap-2">
                <?php if ($httpsActive): ?>
                    <span class="badge badge-success">✓ SSL / HTTPS Active</span>
                <?php else: ?>
                    <span class="badge badge-secondary">HTTP / Localhost</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Production Error Log Viewer -->
<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <div>
            <h3 class="card-title"><i class="ti ti-file-text mr-1"></i> Production Error Log</h3>
            <p class="text-muted text-xs mb-0">storage/logs/error.log (Size: <?= number_format($logSize / 1024, 1) ?> KB)</p>
        </div>
        <div>
            <form method="POST" action="<?= url('admin/system-health.php') ?>" style="display:inline;" onsubmit="return confirm('Clear all error log entries?');">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="clear_log">
                <button type="submit" class="btn btn-secondary btn-sm" <?= empty($logLines) ? 'disabled' : '' ?>>
                    <i class="ti ti-eraser mr-1"></i> Clear Log
                </button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logLines)): ?>
            <div class="p-4 text-center text-muted">
                <i class="ti ti-check-circle text-2xl text-success mb-1"></i>
                <div>Error log is completely clean. Zero unhandled PHP exceptions recorded.</div>
            </div>
        <?php else: ?>
            <div style="background: #0f172a; color: #f8fafc; font-family: 'JetBrains Mono', Consolas, Monaco, monospace; font-size: 12px; padding: 14px; max-height: 400px; overflow-y: auto; line-height: 1.6;">
                <?php foreach ($logLines as $line): ?>
                    <div style="<?= stripos($line, 'error') !== false || stripos($line, 'fatal') !== false ? 'color: #f87171;' : (stripos($line, 'warning') !== false ? 'color: #fcd34d;' : 'color: #94a3b8;') ?>">
                        <?= e($line) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
