<?php
/**
 * DJ CRM Software — Desktop Background Sync Worker (CLI / Supervisor)
 * Runs as a lightweight process to monitor pending queue and sync periodically.
 */

define('YBT_CLI_WORKER', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/src/SqliteClient.php';
require_once __DIR__ . '/src/SyncEngine.php';

$opts = getopt('', ['business-id::', 'cloud-url::', 'interval::', 'once', 'daemon']);
$businessId = isset($opts['business-id']) ? (int)$opts['business-id'] : 1;
$cloudUrl = isset($opts['cloud-url']) ? rtrim($opts['cloud-url'], '/') : (defined('CLOUD_SYNC_URL') ? CLOUD_SYNC_URL : (getenv('CLOUD_SYNC_URL') ?: 'https://apkashow.com'));
$interval = isset($opts['interval']) ? max(5, (int)$opts['interval']) : 30;
$runOnce = isset($opts['once']) || !isset($opts['daemon']);

$sqlitePath = getenv('YBT_SQLITE_PATH') ?: (getenv('APPDATA') . '/YBT Management Software/storage/desktop/ybt_local.sqlite');
$sqlite = new SqliteClient($sqlitePath);
$syncEngine = new SyncEngine($sqlite);
$deviceId = 'worker-' . substr(md5(gethostname()), 0, 8);

echo "DJ CRM Desktop Background Sync Worker started.\n";
echo "Business ID: {$businessId} | Cloud URL: {$cloudUrl} | Mode: " . ($runOnce ? "Single Run" : "Daemon ({$interval}s)") . "\n";

do {
    // Check pending count
    $pending = (int)$sqlite->fetchColumn("SELECT COUNT(*) FROM pending_operations WHERE business_id = ? AND status = 'pending'", [$businessId]);
    if ($pending > 0) {
        echo date('[Y-m-d H:i:s]') . " Found {$pending} pending operation(s). Attempting sync...\n";
        
        $token = 'worker_' . bin2hex(random_bytes(16));
        $sqlite->insert('api_tokens', [
            'business_id' => $businessId,
            'user_id' => 1,
            'token_hash' => hash('sha256', $token),
            'name' => 'CLI Sync Worker',
            'device_id' => $deviceId,
            'abilities' => json_encode(['*']),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400)
        ]);

        $pushRes = $syncEngine->pushPending($cloudUrl, $token, $deviceId);
        echo date('[Y-m-d H:i:s]') . " Push result: " . ($pushRes['success'] ? "Pushed {$pushRes['pushed']}" : "Failed: " . ($pushRes['error'] ?? 'Unknown error')) . "\n";

        $pullRes = $syncEngine->pullUpdates($cloudUrl, $token, $businessId);
        echo date('[Y-m-d H:i:s]') . " Pull result: " . ($pullRes['success'] ? "Pulled {$pullRes['pulled']}" : "Failed: " . ($pullRes['error'] ?? 'Unknown error')) . "\n";
    } else {
        echo date('[Y-m-d H:i:s]') . " Sync status: Clean (0 pending operations).\n";
    }

    if ($runOnce) {
        break;
    }

    sleep($interval);
} while (true);
