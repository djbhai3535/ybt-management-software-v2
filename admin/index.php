<?php
/**
 * YBT Management Software — Central Super Admin Dashboard
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

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;">Platform Overview</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
            Central SaaS telemetry, tenant lifecycle management, and platform control.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('admin/subscriptions.php') ?>" class="btn btn-warning btn-sm"><i class="ti ti-crown mr-1"></i> SaaS Subscriptions</a> <a href="<?= url('admin/announcements.php') ?>" class="btn btn-secondary btn-sm">
            <i class="ti ti-speakerphone mr-1"></i> New Announcement
        </a>
        <a href="<?= url('admin/tenants.php') ?>" class="btn btn-primary btn-sm">
            <i class="ti ti-building-store mr-1"></i> Manage All Shops
        </a>
    </div>
</div>

<?php if ($pendingSubs > 0): ?>
<div class="alert alert-warning mb-4 d-flex justify-between align-center p-3 rounded" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3);">
    <div class="d-flex align-center gap-2">
        <i class="ti ti-clock-pause fs-4 text-warning"></i>
        <div>
            <strong><?= $pendingSubs ?> Subscription Payment Proof(s) Awaiting Owner Approval</strong>
            <div class="text-xs text-muted">Shopkeepers have submitted payment receipts for plan activations.</div>
        </div>
    </div>
    <a href="<?= url('admin/subscriptions.php#pending') ?>" class="btn btn-warning btn-sm font-bold">
        <i class="ti ti-arrow-right mr-1"></i> Review & Approve Payments
    </a>
</div>
<?php endif; ?>

<!-- Platform KPI Cards -->
<div class="grid grid-cols-5 gap-3 mb-4">
    <div class="card p-3" style="border-left: 4px solid #f59e0b;">
        <div class="d-flex justify-between align-center mb-2">
            <span class="text-muted text-xs font-semibold uppercase">Total Shops</span>
            <i class="ti ti-building-store fs-5 text-warning"></i>
        </div>
        <div style="font-size: 1.8rem; font-weight: 700;"><?= $totalShops ?></div>
        <div class="text-xs text-muted mt-1">Registered businesses</div>
    </div>

    <div class="card p-3" style="border-left: 4px solid #10b981;">
        <div class="d-flex justify-between align-center mb-2">
            <span class="text-muted text-xs font-semibold uppercase">Active Shops</span>
            <i class="ti ti-circle-check fs-5 text-success"></i>
        </div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #10b981;"><?= $activeShops ?></div>
        <div class="text-xs text-muted mt-1">Operational tenants</div>
    </div>

    <div class="card p-3" style="border-left: 4px solid #ef4444;">
        <div class="d-flex justify-between align-center mb-2">
            <span class="text-muted text-xs font-semibold uppercase">Suspended</span>
            <i class="ti ti-circle-x fs-5 text-danger"></i>
        </div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #ef4444;"><?= $suspendedShops ?></div>
        <div class="text-xs text-muted mt-1">Access blocked</div>
    </div>

    <div class="card p-3" style="border-left: 4px solid #6366f1;">
        <div class="d-flex justify-between align-center mb-2">
            <span class="text-muted text-xs font-semibold uppercase">Platform Users</span>
            <i class="ti ti-users fs-5 text-primary"></i>
        </div>
        <div style="font-size: 1.8rem; font-weight: 700;"><?= $totalUsers ?></div>
        <div class="text-xs text-muted mt-1">Owners, managers, staff</div>
    </div>

    <div class="card p-3" style="border-left: 4px solid #06b6d4;">
        <div class="d-flex justify-between align-center mb-2">
            <span class="text-muted text-xs font-semibold uppercase">Announcements</span>
            <i class="ti ti-speakerphone fs-5 text-info"></i>
        </div>
        <div style="font-size: 1.8rem; font-weight: 700;"><?= $activeAnnouncements ?></div>
        <div class="text-xs text-muted mt-1">Active broadcast banners</div>
    </div>
</div>

<div class="grid grid-cols-3 gap-4 mb-4">
    <!-- Left: Recent Shops (col-span-2) -->
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
                                <th>Country / Currency</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentShops as $shop): 
                                $isSuspended = ($shop['status'] === 'suspended');
                            ?>
                            <tr>
                                <td>
                                    <div class="font-bold"><?= e($shop['name']) ?></div>
                                    <div class="text-xs text-muted"><?= e($shop['legal_name'] ?: 'No legal name') ?></div>
                                </td>
                                <td>
                                    <div><?= e($shop['phone'] ?: '—') ?></div>
                                    <div class="text-xs text-muted"><?= e($shop['city'] ?: '—') ?><?= !empty($shop['state']) ? ', ' . e($shop['state']) : '' ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-subtle"><?= e($shop['country'] ?: 'PK') ?></span>
                                    <span class="text-xs font-semibold ml-1"><?= e($shop['default_currency'] ?: $shop['currency'] ?: 'PKR') ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $isSuspended ? 'danger' : 'success' ?>">
                                        <?= $isSuspended ? 'Suspended' : 'Active' ?>
                                    </span>
                                </td>
                                <td class="text-right">
                                    <a href="<?= url('admin/tenant-view.php?id=' . $shop['id']) ?>" class="btn btn-secondary btn-sm" title="View Profile">
                                        <i class="ti ti-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Recent Platform Users (col-span-1) -->
    <div class="col-span-1">
        <div class="card">
            <div class="card-header d-flex justify-between align-center">
                <h3 class="card-title"><i class="ti ti-users mr-2 text-primary"></i> Recent Users</h3>
                <a href="<?= url('admin/users.php') ?>" class="text-xs text-primary font-semibold">View All &rarr;</a>
            </div>
            <div class="card-body p-0">
                <div class="info-list p-3">
                    <?php foreach ($recentUsers as $u): ?>
                    <div class="d-flex justify-between align-center mb-3 pb-2" style="border-bottom: 1px solid var(--color-border);">
                        <div>
                            <div class="font-bold text-sm">
                                <?= e($u['name']) ?>
                                <?php if (!empty($u['is_super_admin'])): ?>
                                    <span class="badge badge-warning text-xs ml-1">ADMIN</span>
                                <?php elseif (!empty($u['is_owner'])): ?>
                                    <span class="badge badge-primary text-xs ml-1">Owner</span>
                                <?php else: ?>
                                    <span class="badge badge-subtle text-xs ml-1"><?= e($u['role_slug'] ?: 'Staff') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-muted"><?= e($u['email']) ?></div>
                            <div class="text-xs text-muted">Shop: <?= e($u['business_name'] ?: 'None') ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
