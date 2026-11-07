<?php
/**
 * DJ CRM Software — Desktop Sync Agent Endpoint
 * Provides real-time synchronization status, network reachability probing,
 * and handles background or on-demand push/pull cycles.
 */

define('YBT_API_REQUEST', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (file_exists(YBT_ROOT . '/desktop/src/SqliteClient.php')) {
    require_once YBT_ROOT . '/desktop/src/SqliteClient.php';
}
if (file_exists(YBT_ROOT . '/desktop/src/SyncEngine.php')) {
    require_once YBT_ROOT . '/desktop/src/SyncEngine.php';
}

header('Content-Type: application/json; charset=utf-8');

// Authenticate via session, tenant context, or local desktop loopback
$businessId = null;
if (Auth::check()) {
    $businessId = Auth::businessId();
    $userId = Auth::id() ?: 1;
} elseif (TenantContext::has()) {
    $businessId = TenantContext::id();
    $userId = 1;
} else {
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']) || php_sapi_name() === 'cli';
    if ($isLocal) {
        $businessId = (int)($_REQUEST['business_id'] ?? 1);
        $userId = 1;
        TenantContext::set($businessId);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
}

$cloudUrl = rtrim(defined('CLOUD_SYNC_URL') ? CLOUD_SYNC_URL : (getenv('CLOUD_SYNC_URL') ?: 'https://apkashow.com'), '/');
$db = Database::getInstance();
$sqlitePath = getenv('YBT_SQLITE_PATH') ?: (getenv('APPDATA') . '/YBT Management Software/storage/desktop/ybt_local.sqlite');

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

function checkCloudReachability(string $url, int $timeoutMs = 1500): bool {
    $healthUrl = rtrim($url, '/') . '/api/v1/health.php';
    $ch = curl_init($healthUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $timeoutMs);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        return true;
    }

    $ch2 = curl_init(rtrim($url, '/') . '/api/v1/health');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT_MS, $timeoutMs);
    curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT_MS, $timeoutMs);
    curl_setopt($ch2, CURLOPT_NOBODY, false);
    $res2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    return ($httpCode2 === 200);
}

if ($action === 'status') {
    try {
        $pendingCount = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM pending_operations WHERE business_id = ? AND status = 'pending'",
            [$businessId]
        );
        $failedCount = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM pending_operations WHERE business_id = ? AND status = 'failed'",
            [$businessId]
        );
        $lastSyncedRow = Database::fetch(
            "SELECT MAX(synced_at) as last_synced FROM pending_operations WHERE business_id = ? AND status = 'synced'",
            [$businessId]
        );
        $lastVersionRow = Database::fetch(
            "SELECT value, updated_at FROM sync_metadata WHERE key = 'last_sync_version'"
        );

        $currentPort = (int)($_SERVER['SERVER_PORT'] ?? 80);
        $cloudPort = (int)(parse_url($cloudUrl, PHP_URL_PORT) ?? 80);
        $cloudHost = parse_url($cloudUrl, PHP_URL_HOST) ?? '';
        $isSelf = in_array($cloudHost, ['127.0.0.1', 'localhost']) && ($currentPort === $cloudPort);

        $isOnline = $isSelf ? true : checkCloudReachability($cloudUrl);

        $statusStr = 'synced';
        if (!$isOnline) {
            $statusStr = 'offline';
        } elseif ($failedCount > 0) {
            $statusStr = 'error';
        } elseif ($pendingCount > 0) {
            $statusStr = 'pending';
        }

        echo json_encode([
            'success' => true,
            'online' => $isOnline,
            'cloud_reachable' => $isOnline,
            'status' => $statusStr,
            'pending_count' => $pendingCount,
            'failed_count' => $failedCount,
            'last_synced_at' => $lastSyncedRow['last_synced'] ?? ($lastVersionRow['updated_at'] ?? null),
            'last_sync_time' => !empty($lastSyncedRow['last_synced']) ? date('h:i A', strtotime($lastSyncedRow['last_synced'])) : 'Never',
            'last_version' => (int)($lastVersionRow['value'] ?? 0),
            'cloud_url' => $cloudUrl,
            'device_id' => gethostname()
        ]);
        exit;
    } catch (\Throwable $t) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $t->getMessage()]);
        exit;
    }
}

if ($action === 'trigger_sync') {
    try {
        $currentPort = (int)($_SERVER['SERVER_PORT'] ?? 80);
        $cloudPort = (int)(parse_url($cloudUrl, PHP_URL_PORT) ?? 80);
        $cloudHost = parse_url($cloudUrl, PHP_URL_HOST) ?? '';
        $isSelf = in_array($cloudHost, ['127.0.0.1', 'localhost']) && ($currentPort === $cloudPort);

        $isOnline = $isSelf ? true : checkCloudReachability($cloudUrl);
        if (!$isOnline) {
            echo json_encode([
                'success' => false,
                'online' => false,
                'status' => 'offline',
                'error' => 'Cloud server is currently unreachable. Changes remain safely queued locally.',
                'pending_count' => (int)Database::fetchColumn("SELECT COUNT(*) FROM pending_operations WHERE business_id = ? AND status = 'pending'", [$businessId])
            ]);
            exit;
        }

        if ($isSelf) {
            // Self-loopback environment (e.g. single-process development/testing):
            // Process pending operations locally without HTTP self-deadlock
            $pendingOps = Database::fetchAll(
                "SELECT * FROM pending_operations WHERE business_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 50",
                [$businessId]
            );
            $pushedCount = 0;
            foreach ($pendingOps as $pop) {
                Database::query("UPDATE pending_operations SET status = 'synced', synced_at = datetime('now') WHERE id = ?", [$pop['id']]);
                $pushedCount++;
            }
            echo json_encode([
                'success' => true,
                'online' => true,
                'status' => 'synced',
                'pushed_count' => $pushedCount,
                'pulled_count' => 0,
                'stats' => [
                    'pushed' => $pushedCount,
                    'pulled' => 0,
                    'pending' => 0,
                    'failed' => 0
                ]
            ]);
            exit;
        }

        // Get or issue an API token for background sync
        $rawToken = null;
        if (!empty($_SESSION['cached_api_token'])) {
            $cached = $_SESSION['cached_api_token'];
            $chk = Database::fetch("SELECT id FROM api_tokens WHERE token_hash = ? AND business_id = ? AND expires_at > NOW()", [hash('sha256', $cached), $businessId]);
            if ($chk) {
                $rawToken = $cached;
            }
        }

        if (!$rawToken) {
            $rawToken = 'desktop_sync_' . bin2hex(random_bytes(20));
            Database::insert('api_tokens', [
                'business_id' => $businessId,
                'user_id' => $userId,
                'token_hash' => hash('sha256', $rawToken),
                'name' => 'Desktop Background Sync (' . gethostname() . ')',
                'device_id' => 'desktop-' . substr(md5(gethostname()), 0, 8),
                'abilities' => json_encode(['*']),
                'expires_at' => date('Y-m-d H:i:s', time() + (30 * 86400))
            ]);
            $_SESSION['cached_api_token'] = $rawToken;
        }

        $sqlite = new SqliteClient($sqlitePath);
        $syncEngine = new SyncEngine($sqlite);
        $deviceId = 'desktop-' . substr(md5(gethostname()), 0, 8);

        // 1. Push pending local operations to cloud
        $pushResult = $syncEngine->pushPending($cloudUrl, $rawToken, $deviceId);

        // 2. Pull remote updates from cloud
        $pullResult = $syncEngine->pullUpdates($cloudUrl, $rawToken, $businessId);

        $pendingRemaining = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM pending_operations WHERE business_id = ? AND status = 'pending'",
            [$businessId]
        );
        $failedRemaining = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM pending_operations WHERE business_id = ? AND status = 'failed'",
            [$businessId]
        );

        $finalStatus = ($failedRemaining > 0) ? 'error' : (($pendingRemaining > 0) ? 'pending' : 'synced');

        echo json_encode([
            'success' => true,
            'online' => true,
            'status' => $finalStatus,
            'pushed_count' => $pushResult['pushed'] ?? 0,
            'pulled_count' => $pullResult['pulled'] ?? 0,
            'pending_count' => $pendingRemaining,
            'failed_count' => $failedRemaining,
            'message' => 'Synchronization completed successfully.',
            'last_synced_at' => date('Y-m-d H:i:s')
        ]);
        exit;
    } catch (\Throwable $t) {
        error_log("Sync trigger failed: " . $t->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'online' => true,
            'status' => 'error',
            'error' => $t->getMessage()
        ]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action.']);
exit;
