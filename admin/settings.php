<?php
/**
 * YBT Management Software — Super Admin Platform Settings & Telemetry
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';

$pageTitle = 'Platform Settings & Infrastructure';

$config = require YBT_ROOT . '/config/config.php';
$driver = Database::getDriver();

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;">Platform Settings & Infrastructure</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
            Global SaaS configuration, active database drivers, and server runtime environment.
        </p>
    </div>
</div>

<div class="grid grid-cols-2 gap-4 mb-4">
    <!-- Platform Configuration -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="ti ti-server mr-2 text-warning"></i> Runtime Environment</h3>
        </div>
        <div class="card-body">
            <div class="info-list">
                <div class="mb-3">
                    <span class="text-muted text-xs d-block">Application Name</span>
                    <div class="font-bold"><?= e(APP_NAME) ?> (v<?= e(APP_VERSION) ?>)</div>
                </div>
                <div class="mb-3">
                    <span class="text-muted text-xs d-block">PHP Version</span>
                    <div class="font-medium"><?= phpversion() ?> (SAPI: <?= php_sapi_name() ?>)</div>
                </div>
                <div class="mb-3">
                    <span class="text-muted text-xs d-block">Active Database Engine</span>
                    <div>
                        <span class="badge badge-<?= $driver === 'sqlite' ? 'warning' : 'primary' ?>" style="font-size: 0.85rem;">
                            <?= strtoupper($driver) ?> Engine
                        </span>
                        <?php if ($driver === 'sqlite'): ?>
                            <span class="text-xs text-muted ml-2">Offline Embedded Mode</span>
                        <?php else: ?>
                            <span class="text-xs text-muted ml-2">Cloud MySQL Mode</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <span class="text-muted text-xs d-block">Session Save Path</span>
                    <code class="text-xs"><?= e(session_save_path()) ?></code>
                </div>
                <div>
                    <span class="text-muted text-xs d-block">Platform Timezone</span>
                    <div class="font-medium"><?= date_default_timezone_get() ?> (Current: <?= date('Y-m-d H:i:s') ?>)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Security & Super Admin Policy -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="ti ti-shield-check mr-2 text-success"></i> Security & Access Policies</h3>
        </div>
        <div class="card-body">
            <div class="info-list">
                <div class="mb-3">
                    <span class="text-muted text-xs d-block">Super Admin Access Guard</span>
                    <div class="text-sm font-medium text-success">
                        <i class="ti ti-check mr-1"></i> Active (Route isolation via <code>Auth::requireSuperAdmin()</code>)
                    </div>
                    <div class="text-xs text-muted mt-1">Ordinary shop Owners, Managers, and Staff are strictly blocked from `/admin/*`.</div>
                </div>
                <div class="mb-3">
                    <span class="text-muted text-xs d-block">Tenant Suspension Enforcement</span>
                    <div class="text-sm font-medium text-success">
                        <i class="ti ti-check mr-1"></i> Active (Zero-access lock on suspended shops)
                    </div>
                    <div class="text-xs text-muted mt-1">Users of suspended shops are denied login and active sessions terminate instantly.</div>
                </div>
                <div class="mb-3">
                    <span class="text-muted text-xs d-block">Anti-Privilege Escalation</span>
                    <div class="text-sm font-medium text-success">
                        <i class="ti ti-check mr-1"></i> Active (Registration cannot assign Super Admin)
                    </div>
                    <div class="text-xs text-muted mt-1"><code>is_super_admin</code> is hardcoded to 0 in public registration and staff invitations.</div>
                </div>
                <div>
                    <span class="text-muted text-xs d-block">CSRF & Session Hijacking Protection</span>
                    <div class="text-sm font-medium text-success">
                        <i class="ti ti-check mr-1"></i> Active (Cryptographic single-use tokens & HTTP-only cookies)
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
