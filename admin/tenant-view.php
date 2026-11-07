<?php
/**
 * YBT Management Software — Super Admin Tenant Detailed Profile
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';

$shopId = (int)($_GET['id'] ?? 0);
$shop = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$shopId]);

if (!$shop) {
    Flash::error('Requested shop profile does not exist.');
    header('Location: ' . url('admin/tenants.php'));
    exit;
}

$pageTitle = 'Shop: ' . $shop['name'];

// Users under this business
$users = Database::fetchAll(
    "SELECT u.*, r.name as role_name, r.slug as role_slug 
     FROM users u 
     LEFT JOIN roles r ON u.role_id = r.id 
     WHERE u.business_id = ? 
     ORDER BY u.is_owner DESC, u.id ASC",
    [$shopId]
);

// Financial & Activity Telemetry
$customerCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM customers WHERE business_id = ?", [$shopId]);
$invoiceCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM invoices WHERE business_id = ?", [$shopId]);
$totalInvoiced = (float)Database::fetchColumn("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE business_id = ?", [$shopId]);
$totalPaid = (float)Database::fetchColumn("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE business_id = ?", [$shopId]);
$productCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM products WHERE business_id = ?", [$shopId]);

$isSuspended = ($shop['status'] === 'suspended');
$subInfo = SubscriptionService::getBusinessPlan($shopId);
$allPlans = SubscriptionService::getAllPlansForAdmin();

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <div class="d-flex align-center gap-2 mb-1">
            <a href="<?= url('admin/tenants.php') ?>" class="text-muted"><i class="ti ti-arrow-left"></i> All Shops</a>
            <span class="text-muted">/</span>
            <span>Tenant #<?= $shop['id'] ?></span>
        </div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;"><?= e($shop['name']) ?></h1>
    </div>
    <div>
        <!-- Suspend / Activate Form -->
        <form method="POST" action="<?= url('admin/tenants.php') ?>" style="display: inline;" onsubmit="return confirm('Are you sure you want to <?= $isSuspended ? 'ACTIVATE' : 'SUSPEND' ?> this shop?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="shop_id" value="<?= $shop['id'] ?>">
            <input type="hidden" name="status" value="<?= $isSuspended ? 'active' : 'suspended' ?>">
            
            <?php if ($isSuspended): ?>
                <button type="submit" class="btn btn-success">
                    <i class="ti ti-check mr-1"></i> Activate Tenant Account
                </button>
            <?php else: ?>
                <button type="submit" class="btn btn-danger">
                    <i class="ti ti-ban mr-1"></i> Suspend Tenant Account
                </button>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($isSuspended): ?>
<div class="alert alert-danger mb-4 d-flex align-center gap-3 p-3 rounded">
    <i class="ti ti-alert-octagon fs-3"></i>
    <div>
        <strong>THIS SHOP ACCOUNT IS CURRENTLY SUSPENDED</strong>
        <div class="text-sm">
            Suspended on: <?= formatDate($shop['suspended_at']) ?> 
            <?= !empty($shop['suspended_reason']) ? '— Reason: ' . e($shop['suspended_reason']) : '' ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Quick Telemetry Cards -->
<div class="grid grid-cols-5 gap-3 mb-4">
    <div class="card p-3">
        <span class="text-muted text-xs uppercase font-semibold">Account Status</span>
        <div class="mt-1">
            <span class="badge badge-<?= $isSuspended ? 'danger' : 'success' ?>" style="font-size: 0.9rem;">
                <?= $isSuspended ? 'SUSPENDED' : 'ACTIVE' ?>
            </span>
        </div>
    </div>
    <div class="card p-3">
        <span class="text-muted text-xs uppercase font-semibold">Registered Users</span>
        <div style="font-size: 1.6rem; font-weight: 700;"><?= count($users) ?></div>
    </div>
    <div class="card p-3">
        <span class="text-muted text-xs uppercase font-semibold">Customers</span>
        <div style="font-size: 1.6rem; font-weight: 700;"><?= $customerCount ?></div>
    </div>
    <div class="card p-3">
        <span class="text-muted text-xs uppercase font-semibold">Invoices / Billed</span>
        <div style="font-size: 1.6rem; font-weight: 700;"><?= $invoiceCount ?></div>
        <div class="text-xs text-muted"><?= formatCurrency($totalInvoiced) ?></div>
    </div>
    <div class="card p-3">
        <span class="text-muted text-xs uppercase font-semibold">Payments Received</span>
        <div style="font-size: 1.6rem; font-weight: 700; color: #10b981;"><?= formatCurrency($totalPaid) ?></div>
    </div>
</div>

<div class="grid grid-cols-3 gap-4 mb-4">
    <!-- Left: Shop Details (col-span-1) -->
    <div class="col-span-1">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-info-circle mr-2 text-warning"></i> Shop Particulars</h3>
            </div>
            <div class="card-body">
                <div class="info-list">
                    <div class="mb-3">
                        <span class="text-muted text-xs d-block">Legal Name</span>
                        <div class="font-bold"><?= e($shop['legal_name'] ?: '—') ?></div>
                    </div>
                    <div class="mb-3">
                        <span class="text-muted text-xs d-block">Phone & WhatsApp</span>
                        <div class="font-medium"><?= e($shop['phone'] ?: '—') ?></div>
                    </div>
                    <div class="mb-3">
                        <span class="text-muted text-xs d-block">Location</span>
                        <div><?= e($shop['address_line1'] ?: '—') ?></div>
                        <div class="text-xs text-muted"><?= e($shop['city'] ?: '—') ?>, <?= e($shop['state'] ?: '—') ?> <?= e($shop['postal_code'] ?: '') ?></div>
                        <div class="text-xs font-semibold mt-1">Country: <?= e($shop['country'] ?: 'PK') ?></div>
                    </div>
                    <div class="mb-3">
                        <span class="text-muted text-xs d-block">Currency & Tax</span>
                        <div class="font-medium"><?= e($shop['default_currency'] ?: $shop['currency'] ?: 'PKR') ?></div>
                        <?php if (!empty($shop['tax_number'])): ?>
                            <div class="text-xs text-muted"><?= e($shop['tax_label'] ?: 'Tax ID') ?>: <?= e($shop['tax_number']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <span class="text-muted text-xs d-block">Tenant UUID</span>
                        <code class="text-xs"><?= e($shop['tenant_uuid'] ?: 'Not assigned') ?></code>
                    </div>
                    <div>
                        <span class="text-muted text-xs d-block">Banking & Wallets</span>
                        <div class="text-sm font-medium"><?= e($shop['bank_name'] ?: 'Not configured') ?></div>
                        <?php if (!empty($shop['wallet_id'])): ?>
                            <div class="text-xs text-muted"><?= e($shop['wallet_type'] ?: 'Wallet') ?>: <?= e($shop['wallet_id']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- SaaS Subscription Card -->
        <div class="card mb-4 card-glow-primary">
            <div class="card-header d-flex justify-between align-center">
                <h3 class="card-title text-primary"><i class="ti ti-crown mr-2"></i> SaaS Subscription</h3>
                <span class="badge badge-<?= $subInfo['is_expired'] ? 'danger' : 'success' ?>">
                    <?= strtoupper(e($subInfo['status'])) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <span class="text-muted text-xs d-block">Active Plan Tier</span>
                    <div class="font-bold text-lg text-primary"><?= e($subInfo['plan_name']) ?></div>
                </div>

                <div class="mb-3 text-xs">
                    <div>Staff Accounts: <strong><?= $subInfo['usage']['users'] ?> / <?= $subInfo['usage']['max_users'] ?></strong></div>
                    <div>Customer Limit: <strong><?= $subInfo['usage']['customers'] ?> / <?= $subInfo['usage']['max_customers'] ?></strong></div>
                    <div>Invoices / Mo: <strong><?= $subInfo['usage']['invoices_this_month'] ?> / <?= $subInfo['usage']['max_invoices'] ?></strong></div>
                    <div>AI Manager: <strong><?= $subInfo['ai_enabled'] ? 'Active' : 'Disabled' ?></strong></div>
                </div>

                <div class="mb-3">
                    <span class="text-muted text-xs d-block">Validity / Expiration Date</span>
                    <div class="font-medium text-sm">
                        <?= !empty($subInfo['expires_at']) ? date('d M Y, h:i A', strtotime($subInfo['expires_at'])) : 'Unlimited / Ongoing' ?>
                    </div>
                    <?php if (!empty($subInfo['expires_at'])): ?>
                        <div class="text-xs text-muted"><?= $subInfo['is_expired'] ? 'Expired' : ($subInfo['days_remaining'] . ' days remaining') ?></div>
                    <?php endif; ?>
                </div>

                <form method="POST" action="<?= url('admin/subscriptions.php') ?>" class="border-t pt-3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="assign_plan">
                    <input type="hidden" name="business_id" value="<?= $shopId ?>">

                    <label class="form-label text-xs font-bold mb-1">Modify Plan / Extend Expiry</label>
                    <div class="d-flex gap-2 mb-2">
                        <select name="plan_slug" class="form-select form-select-sm flex-1">
                            <?php foreach ($allPlans as $pl): ?>
                                <option value="<?= $pl['slug'] ?>" <?= ($pl['slug'] === $subInfo['plan_slug']) ? 'selected' : '' ?>>
                                    <?= e($pl['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="duration_months" class="form-select form-select-sm" style="width: 110px;">
                            <option value="1">+1 Month</option>
                            <option value="3">+3 Months</option>
                            <option value="6">+6 Months</option>
                            <option value="12" selected>+1 Year</option>
                            <option value="24">+2 Years</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="ti ti-check mr-1"></i> Update & Extend Plan
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: Users & Staff under this Shop (col-span-2) -->
    <div class="col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-users mr-2 text-primary"></i> Team & Staff Members (<?= count($users) ?>)</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name & Email</th>
                                <th>Role</th>
                                <th>Phone</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="4" class="text-center p-3 text-muted">No users found under this shop.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                <tr>
                                    <td>
                                        <div class="font-bold"><?= e($u['name']) ?></div>
                                        <div class="text-xs text-muted"><?= e($u['email']) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($u['is_owner'])): ?>
                                            <span class="badge badge-primary font-semibold">Shop Owner</span>
                                        <?php else: ?>
                                            <span class="badge badge-subtle"><?= e($u['role_name'] ?: ucfirst($u['role_slug'] ?: 'Staff')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($u['phone'] ?: '—') ?></td>
                                    <td>
                                        <span class="badge badge-<?= $u['is_active'] ? 'success' : 'danger' ?>">
                                            <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
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
</div>

<?php require __DIR__ . '/footer.php'; ?>
