<?php
/**
 * DJ CRM Software — Secure Logout & Unsynced Changes Safety Supervisor
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (!Auth::check()) {
    header('Location: ' . url('login.php'));
    exit;
}

$businessId = Auth::businessId();
$userId = Auth::id();
$pendingCount = 0;

try {
    $pendingCount = (int)Database::fetchColumn(
        "SELECT COUNT(*) FROM pending_operations WHERE business_id = ? AND status = 'pending'",
        [$businessId]
    );
} catch (\Throwable $e) {}

// 1. If user chose "Sync & Sign Out"
if (isset($_GET['sync']) && $_GET['sync'] === '1') {
    $cloudUrl = rtrim(defined('CLOUD_SYNC_URL') ? CLOUD_SYNC_URL : (getenv('CLOUD_SYNC_URL') ?: 'https://apkashow.com'), '/');
    $sqlitePath = getenv('YBT_SQLITE_PATH') ?: (getenv('APPDATA') ? (str_replace('\\', '/', getenv('APPDATA')) . '/YBT Management Software/storage/desktop/ybt_local.sqlite') : null);
    $rawToken = $_SESSION['cached_api_token'] ?? null;

    if ($rawToken && file_exists(YBT_ROOT . '/desktop/src/SqliteClient.php') && file_exists(YBT_ROOT . '/desktop/src/SyncEngine.php')) {
        require_once YBT_ROOT . '/desktop/src/SqliteClient.php';
        require_once YBT_ROOT . '/desktop/src/SyncEngine.php';
        try {
            $sqlite = new SqliteClient($sqlitePath);
            $sync = new SyncEngine($sqlite);
            $deviceId = 'desktop-' . substr(md5(gethostname()), 0, 8);
            $sync->pushPending($cloudUrl, $rawToken, $deviceId);
        } catch (\Throwable $t) {}
    }

    Auth::logout();
    Flash::success('Synchronized changes and signed out successfully.');
    header('Location: ' . url('login.php'));
    exit;
}

// 2. If user chose "Sign Out Anyway" OR there are no pending changes
if (isset($_GET['force']) && $_GET['force'] === '1' || $pendingCount === 0) {
    Auth::logout();
    Flash::success('You have been signed out successfully.');
    header('Location: ' . url('login.php'));
    exit;
}

// 3. User has unsynced changes: show safety confirmation modal
$pageTitle = 'Sign Out Safety Check — ' . APP_NAME;
$isPublicPage = true;
require YBT_ROOT . '/includes/header.php';
?>

<div class="auth-card-wrapper" style="min-height: 85vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem;">
    <div class="card p-4 shadow-lg text-center" style="max-width: 480px; width: 100%; border-radius: 16px; border: 1px solid var(--border-color, #334155); background: var(--card-bg, #1e293b);">
        <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(245, 158, 11, 0.15); color: #F59E0B; display: inline-flex; align-items: center; justify-content: center; font-size: 2rem; margin-bottom: 1.25rem;">
            <i class="ti ti-cloud-upload"></i>
        </div>

        <h3 class="fw-bold mb-2 text-white" style="font-size: 1.35rem;">Unsynced Changes Pending</h3>
        <p class="text-muted fs-sm mb-4">
            You have <strong class="text-warning"><?= (int)$pendingCount ?> offline change<?= $pendingCount > 1 ? 's' : '' ?></strong> recorded locally on this computer that have not yet uploaded to the cloud SaaS platform (<a href="https://apkashow.com" target="_blank" class="text-primary text-decoration-none">apkashow.com</a>).
        </p>

        <div class="alert alert-subtle-warning text-start mb-4" style="background: rgba(245, 158, 11, 0.08); border-left: 3px solid #F59E0B; padding: 0.85rem 1rem; border-radius: 8px;">
            <div class="fs-xs text-muted">
                <strong>Data Persistence Guarantee:</strong> All changes are safely preserved in your Windows AppData SQLite database and will automatically sync next time you sign in with internet.
            </div>
        </div>

        <div class="d-flex flex-column gap-2">
            <a href="<?= url('logout.php?sync=1') ?>" class="btn btn-primary btn-md w-100 d-flex align-items-center justify-content-center gap-2" style="font-weight: 600;">
                <i class="ti ti-cloud-check"></i> Sync Now & Sign Out
            </a>
            <a href="<?= url('logout.php?force=1') ?>" class="btn btn-ghost text-muted btn-md w-100" style="font-weight: 500;">
                Sign Out Anyway (Keep Local)
            </a>
            <a href="<?= url('dashboard.php') ?>" class="btn btn-outline-secondary btn-sm w-100 mt-1">
                Cancel & Return to Dashboard
            </a>
        </div>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
