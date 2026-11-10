<?php
/**
 * DJ CRM Software 5.2.2 — Central Super Admin SaaS Dashboard
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';

$pageTitle = 'Super Admin Dashboard';

// Aggregate Platform KPIs
$totalShops = (int)Database::fetchColumn("SELECT COUNT(*) FROM businesses");
$activeShops = (int)Database::fetchColumn("SELECT COUNT(*) FROM businesses WHERE status = 'active' OR status IS NULL OR status = ''");
$suspendedShops = (int)Database::fetchColumn("SELECT COUNT(*) FROM businesses WHERE status = 'suspended'");
$totalUsers = (int)Database::fetchColumn("SELECT COUNT(*) FROM users");
$activeAnnouncements = (int)Database::fetchColumn("SELECT COUNT(*) FROM platform_announcements WHERE is_active = 1");
$pendingSubs = (int)Database::fetchColumn("SELECT COUNT(*) FROM business_subscriptions WHERE status = 'pending_approval'");
$activeAds = (int)Database::fetchColumn("SELECT COUNT(*) FROM advertisements WHERE is_active = 1");
$totalPlans = (int)Database::fetchColumn("SELECT COUNT(*) FROM subscription_plans WHERE is_active = 1");

$waSupport = PlatformSettings::getWhatsAppSupport();

// Recent Registered Shops
$recentShops = Database::fetchAll("SELECT * FROM businesses ORDER BY id DESC LIMIT 8");

// Recent Platform Users
$recentUsers = Database::fetchAll(
    "SELECT u.*, b.name as business_name, r.name as role_name, r.slug as role_slug 
     FROM users u 
     LEFT JOIN businesses b ON u.business_id = b.id 
     LEFT JOIN roles r ON u.role_id = r.id 
     ORDER BY u.id DESC LIMIT 6"
);

require __DIR__ . '/header.php';
?>

<!-- Header & Quick Actions -->
<div class="d-flex justify-between align-center mb-4 flex-wrap gap-2">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 800; margin: 0; color: #f8fafc; display: flex; align-items: center; gap: 0.5rem;">
            <i class="ti ti-layout-dashboard text-warning"></i>
            SaaS Platform Command Center
        </h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.85rem;">
            Central telemetry, multi-tenant lifecycle management, monetization, and support hotline.
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= url('admin/whatsapp-support.php') ?>" class="btn btn-success btn-sm" style="background-color: #25d366; border-color: #25d366; font-weight: 600;">
            <i class="ti ti-brand-whatsapp mr-1"></i> Support & WhatsApp
        </a>
        <a href="<?= url('admin/subscriptions.php') ?>" class="btn btn-warning btn-sm" style="font-weight: 600;">
            <i class="ti ti-crown mr-1"></i> Subscriptions
        </a>
        <a href="<?= url('admin/advertisements.php') ?>" class="btn btn-secondary btn-sm" style="font-weight: 600;">
            <i class="ti ti-ad mr-1"></i> Ads Manager
        </a>
        <a href="<?= url('admin/tenants.php') ?>" class="btn btn-primary btn-sm" style="font-weight: 600;">
            <i class="ti ti-building-store mr-1"></i> Manage Shops
        </a>
    </div>
</div>

<?php if ($pendingSubs > 0): ?>
<div class="alert alert-warning mb-4 d-flex justify-between align-center p-3 rounded" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 8px;">
    <div class="d-flex align-center gap-2">
        <i class="ti ti-clock-pause fs-4 text-warning"></i>
        <div>
            <strong><?= $pendingSubs ?> Subscription Payment Proof(s) Awaiting Approval</strong>
            <div class="text-xs text-muted">Shopkeepers have submitted bank/easypaisa receipts for license renewals.</div>
        </div>
    </div>
    <a href="<?= url('admin/subscriptions.php#pending') ?>" class="btn btn-warning btn-sm font-bold">
        <i class="ti ti-arrow-right mr-1"></i> Review & Approve
    </a>
</div>
<?php endif; ?>

<!-- Primary KPI Metrics Grid -->
<div class="grid grid-cols-6 gap-3 mb-4">
    <!-- Total Shops -->
    <div class="card p-3" style="border-top: 3px solid #f59e0b; background: rgba(30, 41, 59, 0.7);">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">Total Shops</span>
            <i class="ti ti-building-store text-warning"></i>
        </div>
        <div style="font-size: 1.6rem; font-weight: 800; color: #f8fafc;"><?= $totalShops ?></div>
        <div class="text-xs text-muted"><?= $activeShops ?> Active / <?= $suspendedShops ?> Suspended</div>
    </div>

    <!-- Platform Users -->
    <div class="card p-3" style="border-top: 3px solid #38bdf8; background: rgba(30, 41, 59, 0.7);">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">Total Users</span>
            <i class="ti ti-users text-primary"></i>
        </div>
        <div style="font-size: 1.6rem; font-weight: 800; color: #38bdf8;"><?= $totalUsers ?></div>
        <div class="text-xs text-muted">Across all business tenants</div>
    </div>

    <!-- Active Plans -->
    <div class="card p-3" style="border-top: 3px solid #a855f7; background: rgba(30, 41, 59, 0.7);">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">SaaS Plans</span>
            <i class="ti ti-crown text-purple"></i>
        </div>
        <div style="font-size: 1.6rem; font-weight: 800; color: #c084fc;"><?= $totalPlans ?></div>
        <div class="text-xs text-muted">Configured pricing tiers</div>
    </div>

    <!-- Active Ads -->
    <div class="card p-3" style="border-top: 3px solid #ec4899; background: rgba(30, 41, 59, 0.7);">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">Monetization</span>
            <i class="ti ti-ad text-pink"></i>
        </div>
        <div style="font-size: 1.6rem; font-weight: 800; color: #f472b6;"><?= $activeAds ?></div>
        <div class="text-xs text-muted">Active ad campaigns</div>
    </div>

    <!-- WhatsApp Support -->
    <div class="card p-3" style="border-top: 3px solid #25d366; background: rgba(30, 41, 59, 0.7);">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">Support Hotline</span>
            <i class="ti ti-brand-whatsapp text-success"></i>
        </div>
        <div style="font-size: 1.05rem; font-weight: 800; font-family: monospace; color: #25d366; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
            +<?= e($waSupport) ?>
        </div>
        <div class="text-xs text-muted">Live across CRM</div>
    </div>

    <!-- System Status -->
    <div class="card p-3" style="border-top: 3px solid #10b981; background: rgba(30, 41, 59, 0.7);">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">System Health</span>
            <i class="ti ti-heart-rate-monitor text-success"></i>
        </div>
        <div style="font-size: 1.25rem; font-weight: 800; color: #10b981;">100% OK</div>
        <div class="text-xs text-muted">PHP 8.3 & DB Parity</div>
    </div>
</div>

<!-- Main Split Section: Recent Shops & Users -->
<div class="grid grid-cols-3 gap-4 mb-4">
    <!-- Left: Recent Registered Shops (2 cols) -->
    <div class="col-span-2">
        <div class="card">
            <div class="card-header d-flex justify-between align-center">
                <h3 class="card-title"><i class="ti ti-building-store mr-2 text-warning"></i> Recent Shop Registrations</h3>
                <a href="<?= url('admin/tenants.php') ?>" class="text-xs text-warning font-semibold">View All Shops &rarr;</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Shop Name</th>
                                <th>Contact & City</th>
                                <th>Plan / Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentShops)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">No shops registered yet.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($recentShops as $shop): 
                                    $isSuspended = ($shop['status'] === 'suspended');
                                    $planSlug = $shop['current_plan_slug'] ?? 'free';
                                ?>
                                <tr>
                                    <td>
                                        <div class="font-bold text-sm"><?= e($shop['name']) ?></div>
                                        <div class="text-xs text-muted"><?= e($shop['legal_name'] ?: 'Owner: ' . ($shop['owner_name'] ?: 'Unknown')) ?></div>
                                    </td>
                                    <td>
                                        <div class="text-xs"><?= e($shop['phone'] ?: $shop['email'] ?: 'No contact') ?></div>
                                        <div class="text-xs text-muted"><?= e($shop['city'] ?: 'Pakistan') ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-subtle text-uppercase text-xs" style="color: #f59e0b;"><?= e($planSlug) ?></span>
                                        <span class="badge badge-<?= $isSuspended ? 'danger' : 'success' ?> text-xs ml-1">
                                            <?= $isSuspended ? 'Suspended' : 'Active' ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <a href="<?= url('admin/tenant-view.php?id=' . $shop['id']) ?>" class="btn btn-secondary btn-xs" title="Inspect Shop">
                                            <i class="ti ti-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Recent Platform Users (1 col) -->
    <div class="col-span-1">
        <div class="card">
            <div class="card-header d-flex justify-between align-center">
                <h3 class="card-title"><i class="ti ti-users mr-2 text-primary"></i> Recent Users</h3>
                <a href="<?= url('admin/users.php') ?>" class="text-xs text-primary font-semibold">View All &rarr;</a>
            </div>
            <div class="card-body p-0">
                <div class="info-list p-3">
                    <?php if (empty($recentUsers)): ?>
                        <div class="text-center py-3 text-muted text-xs">No users registered yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentUsers as $u): ?>
                        <div class="d-flex justify-between align-center mb-2 pb-2" style="border-bottom: 1px solid rgba(148, 163, 184, 0.1);">
                            <div>
                                <div class="font-bold text-xs" style="color: #f8fafc;">
                                    <?= e($u['name']) ?>
                                    <?php if (!empty($u['is_super_admin'])): ?>
                                        <span class="badge badge-warning text-xs ml-1">SUPER ADMIN</span>
                                    <?php elseif (!empty($u['is_owner'])): ?>
                                        <span class="badge badge-primary text-xs ml-1">Owner</span>
                                    <?php else: ?>
                                        <span class="badge badge-subtle text-xs ml-1"><?= e($u['role_slug'] ?: 'Staff') ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-muted"><?= e($u['email']) ?></div>
                                <div class="text-xs text-muted">Tenant: <?= e($u['business_name'] ?: 'Central') ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
