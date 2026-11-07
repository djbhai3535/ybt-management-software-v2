<?php
/**
 * DJ CRM Software — Central Super Admin SaaS Subscriptions & Plan Management
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';
require_once __DIR__ . '/../services/subscription-service.php';
require_once __DIR__ . '/../services/platform-settings-service.php';

$pageTitle = 'SaaS Plans & Subscription Billing';

// Handle Action: Update Plan Configuration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_plan') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired. Please refresh.');
    } else {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $features = !empty($_POST['features']) ? explode("\n", str_replace("\r", "", $_POST['features'])) : [];
        $features = array_values(array_filter(array_map('trim', $features)));

        $res = SubscriptionService::updatePlan($planId, [
            'name' => trim($_POST['name'] ?? ''),
            'badge' => trim($_POST['badge'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'price_monthly' => (float)($_POST['price_monthly'] ?? 0),
            'price_annual' => (float)($_POST['price_annual'] ?? 0),
            'max_users' => (int)($_POST['max_users'] ?? 1),
            'max_customers' => (int)($_POST['max_customers'] ?? 500),
            'max_invoices_per_month' => (int)($_POST['max_invoices_per_month'] ?? 100),
            'ai_enabled' => !empty($_POST['ai_enabled']) ? 1 : 0,
            'ai_monthly_limit' => (int)($_POST['ai_monthly_limit'] ?? 0),
            'offline_sync_enabled' => !empty($_POST['offline_sync_enabled']) ? 1 : 0,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'features' => $features
        ]);

        if ($res) {
            Flash::success('Subscription plan configuration updated successfully.');
        } else {
            Flash::error('Failed to update plan.');
        }
        header('Location: ' . url('admin/subscriptions.php#plans'));
        exit;
    }
}

// Handle Action: Approve Subscription Payment Proof
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_subscription') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired.');
    } else {
        $subId = (int)($_POST['subscription_id'] ?? 0);
        if (SubscriptionService::approveSubscription($subId, Auth::id())) {
            Flash::success("Subscription #{$subId} approved and activated successfully!");
        } else {
            Flash::error("Failed to approve subscription #{$subId}.");
        }
        header('Location: ' . url('admin/subscriptions.php#pending'));
        exit;
    }
}

// Handle Action: Reject Subscription Payment Proof
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_subscription') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired.');
    } else {
        $subId = (int)($_POST['subscription_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? 'Payment verification failed');
        Database::query("UPDATE business_subscriptions SET status = 'rejected', notes = ? WHERE id = ?", [$reason, $subId]);
        Flash::warning("Subscription #{$subId} marked as rejected.");
        header('Location: ' . url('admin/subscriptions.php#pending'));
        exit;
    }
}

// Handle Action: Assign Plan Directly to Business
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_plan') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired.');
    } else {
        $bizId = (int)($_POST['business_id'] ?? 0);
        $planSlug = trim($_POST['plan_slug'] ?? 'standard');
        $months = max(1, (int)($_POST['duration_months'] ?? 1));

        if (SubscriptionService::assignPlanToBusiness($bizId, $planSlug, $months, Auth::id())) {
            Flash::success("Plan successfully assigned to business #{$bizId} for {$months} month(s).");
        } else {
            Flash::error("Failed to assign plan to business.");
        }
        header('Location: ' . url('admin/subscriptions.php#businesses'));
        exit;
    }
}

// Fetch Data for Admin Views
$plans = SubscriptionService::getAllPlansForAdmin();
$pendingApprovals = SubscriptionService::getPendingApprovals();

// Telemetry & KPI calculations
$activeSubsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM businesses WHERE subscription_status = 'active'");
$pendingCount = count($pendingApprovals);
$totalMrr = (float)Database::fetchColumn(
    "SELECT COALESCE(SUM(p.price_monthly), 0) 
     FROM businesses b 
     JOIN subscription_plans p ON b.current_plan_slug = p.slug 
     WHERE b.subscription_status = 'active'"
);

// Search & filter businesses
$search = trim($_GET['search'] ?? '');
$filterPlan = trim($_GET['plan'] ?? 'all');
$filterStatus = trim($_GET['status'] ?? 'all');

$where = ["1=1"];
$params = [];
if (!empty($search)) {
    $where[] = "(b.name LIKE ? OR b.phone LIKE ? OR b.city LIKE ?)";
    $t = "%{$search}%";
    $params = array_merge($params, [$t, $t, $t]);
}
if ($filterPlan !== 'all' && !empty($filterPlan)) {
    $where[] = "b.current_plan_slug = ?";
    $params[] = $filterPlan;
}
if ($filterStatus !== 'all' && !empty($filterStatus)) {
    $where[] = "b.subscription_status = ?";
    $params[] = $filterStatus;
}
$whereClause = implode(' AND ', $where);

$businesses = Database::fetchAll(
    "SELECT b.id, b.name, b.phone, b.city, b.current_plan_slug, b.subscription_status, b.subscription_expires_at,
            p.name as plan_name, p.price_monthly,
            (SELECT COUNT(*) FROM users u WHERE u.business_id = b.id) as user_count,
            (SELECT COUNT(*) FROM customers c WHERE c.business_id = b.id) as customer_count,
            (SELECT COUNT(*) FROM invoices i WHERE i.business_id = b.id) as invoice_count
     FROM businesses b
     LEFT JOIN subscription_plans p ON b.current_plan_slug = p.slug
     WHERE {$whereClause}
     ORDER BY b.id DESC",
    $params
);

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4 flex-wrap gap-2">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;">SaaS Plans & Subscription Billing</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
            Configure tiered SaaS plans, approve shopkeeper subscription payments, and enforce platform usage quotas.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="#plans" class="btn btn-secondary btn-sm">
            <i class="ti ti-adjustments mr-1"></i> Plan Tiers
        </a>
        <a href="#pending" class="btn btn-warning btn-sm">
            <i class="ti ti-clock-check mr-1"></i> Pending Approvals (<?= $pendingCount ?>)
        </a>
    </div>
</div>

<!-- SaaS Overview KPI Cards -->
<div class="grid grid-cols-4 gap-3 mb-4">
    <div class="card p-3" style="border-left: 4px solid #10b981;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold uppercase">Active Subscriptions</span>
            <i class="ti ti-crown fs-5 text-success"></i>
        </div>
        <div style="font-size: 1.75rem; font-weight: 700; color: #10b981;"><?= $activeSubsCount ?></div>
        <div class="text-xs text-muted">Paid & active shop tenants</div>
    </div>

    <div class="card p-3" style="border-left: 4px solid #f59e0b;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold uppercase">Pending Approvals</span>
            <i class="ti ti-clock-pause fs-5 text-warning"></i>
        </div>
        <div style="font-size: 1.75rem; font-weight: 700; color: #f59e0b;"><?= $pendingCount ?></div>
        <div class="text-xs text-muted">Transfers awaiting verification</div>
    </div>

    <div class="card p-3" style="border-left: 4px solid #0284c7;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold uppercase">Estimated MRR</span>
            <i class="ti ti-cash fs-5 text-primary"></i>
        </div>
        <div style="font-size: 1.75rem; font-weight: 700; color: #0284c7;">Rs. <?= number_format($totalMrr, 0) ?></div>
        <div class="text-xs text-muted">Monthly recurring revenue</div>
    </div>

    <div class="card p-3" style="border-left: 4px solid #8b5cf6;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold uppercase">Plan Tiers</span>
            <i class="ti ti-layers-intersect fs-5 text-info"></i>
        </div>
        <div style="font-size: 1.75rem; font-weight: 700; color: #8b5cf6;"><?= count($plans) ?> Tiers</div>
        <div class="text-xs text-muted">Basic, Standard, Premium, Enterprise</div>
    </div>
</div>

<!-- SECTION 1: Pending Payment Approvals -->
<div class="card mb-4" id="pending">
    <div class="card-header d-flex justify-between align-center">
        <div>
            <h3 class="card-title text-warning"><i class="ti ti-clock-check mr-2"></i> Pending Subscription Payment Approvals</h3>
            <p class="text-xs text-muted mb-0">Shopkeepers who transferred fees to the owner account and submitted transaction proof.</p>
        </div>
        <span class="badge badge-warning"><?= $pendingCount ?> Pending</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pendingApprovals)): ?>
            <div class="p-4 text-center text-muted text-sm">
                <i class="ti ti-circle-check fs-2 text-success d-block mb-2"></i>
                All subscription payments are up to date! No pending approvals.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Shop / Business</th>
                            <th>Plan Requested</th>
                            <th>Cycle</th>
                            <th>Amount Paid</th>
                            <th>Payment Method & TID</th>
                            <th>Receipt Proof</th>
                            <th>Submitted At</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingApprovals as $sub): ?>
                        <tr>
                            <td>#<?= $sub['id'] ?></td>
                            <td>
                                <strong><?= e($sub['business_name']) ?></strong>
                                <div class="text-xs text-muted"><?= e($sub['business_phone']) ?></div>
                            </td>
                            <td>
                                <span class="badge badge-primary"><?= e($sub['plan_name']) ?></span>
                            </td>
                            <td><span class="badge badge-subtle"><?= ucfirst(e($sub['billing_cycle'])) ?></span></td>
                            <td class="font-bold text-success">Rs. <?= number_format((float)$sub['amount_paid'], 0) ?></td>
                            <td>
                                <div><strong><?= e($sub['payment_method']) ?></strong></div>
                                <code class="text-xs"><?= e($sub['payment_reference'] ?: 'None') ?></code>
                            </td>
                            <td>
                                <?php if (!empty($sub['proof_attachment'])): ?>
                                    <a href="<?= url($sub['proof_attachment']) ?>" target="_blank" class="btn btn-xs btn-outline">
                                        <i class="ti ti-file-search mr-1"></i> View Proof
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted text-xs">No Slip Attached</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs"><?= date('d M Y, h:i A', strtotime($sub['created_at'])) ?></td>
                            <td class="text-right">
                                <div class="d-inline-flex gap-1">
                                    <form method="POST" action="<?= url('admin/subscriptions.php') ?>" onsubmit="return confirm('Approve payment of Rs. <?= number_format((float)$sub['amount_paid'], 0) ?> and activate <?= e($sub['plan_name']) ?> for this shop?');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="approve_subscription">
                                        <input type="hidden" name="subscription_id" value="<?= $sub['id'] ?>">
                                        <button type="submit" class="btn btn-xs btn-success font-bold">
                                            <i class="ti ti-check mr-1"></i> Approve & Activate
                                        </button>
                                    </form>

                                    <form method="POST" action="<?= url('admin/subscriptions.php') ?>" onsubmit="return confirm('Reject this subscription payment?');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="reject_subscription">
                                        <input type="hidden" name="subscription_id" value="<?= $sub['id'] ?>">
                                        <button type="submit" class="btn btn-xs btn-danger">
                                            <i class="ti ti-x"></i> Reject
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- SECTION 2: Configurable SaaS Plan Tiers -->
<div class="card mb-4" id="plans">
    <div class="card-header d-flex justify-between align-center">
        <div>
            <h3 class="card-title text-primary"><i class="ti ti-adjustments mr-2"></i> Configurable SaaS Plan Tiers</h3>
            <p class="text-xs text-muted mb-0">Set monthly/annual pricing, user quotas, invoice limits, and AI feature authorization.</p>
        </div>
    </div>
    <div class="card-body">
        <div class="grid grid-cols-4 gap-3">
            <?php foreach ($plans as $pl): 
                $feats = !empty($pl['features_json']) ? json_decode($pl['features_json'], true) : [];
            ?>
            <div class="p-3 bg-subtle rounded border d-flex flex-column justify-between" style="position: relative;">
                <div>
                    <div class="d-flex justify-between align-center mb-1">
                        <span class="badge badge-primary"><?= e($pl['badge'] ?: strtoupper($pl['slug'])) ?></span>
                        <span class="badge badge-<?= $pl['is_active'] ? 'success' : 'secondary' ?>"><?= $pl['is_active'] ? 'Active' : 'Disabled' ?></span>
                    </div>
                    <h3 class="font-bold fs-5 mb-1"><?= e($pl['name']) ?></h3>
                    <p class="text-xs text-muted mb-3" style="min-height: 36px;"><?= e($pl['description']) ?></p>

                    <div class="mb-3 p-2 bg-surface rounded border">
                        <div class="font-bold text-lg text-primary">Rs. <?= number_format($pl['price_monthly'], 0) ?> <span class="text-xs text-muted">/ month</span></div>
                        <div class="text-xs text-muted">Annual: Rs. <?= number_format($pl['price_annual'], 0) ?> / yr</div>
                    </div>

                    <div class="text-xs mb-3 space-y-1">
                        <div><i class="ti ti-users mr-1 text-primary"></i> Staff Accounts: <strong><?= $pl['max_users'] ?> Max</strong></div>
                        <div><i class="ti ti-user-check mr-1 text-success"></i> Customer Limit: <strong><?= number_format($pl['max_customers']) ?></strong></div>
                        <div><i class="ti ti-receipt mr-1 text-warning"></i> Invoices / Month: <strong><?= number_format($pl['max_invoices_per_month']) ?></strong></div>
                        <div><i class="ti ti-sparkles mr-1 <?= $pl['ai_enabled'] ? 'text-primary' : 'text-muted' ?>"></i> AI Manager: <strong><?= $pl['ai_enabled'] ? ('Enabled (' . $pl['ai_monthly_limit'] . ' req/mo)') : 'Disabled' ?></strong></div>
                        <div><i class="ti ti-refresh mr-1 text-info"></i> Offline Sync: <strong><?= $pl['offline_sync_enabled'] ? 'Included' : 'No' ?></strong></div>
                    </div>

                    <?php if (!empty($feats)): ?>
                    <div class="border-t pt-2 mb-3">
                        <div class="text-xs font-bold text-muted mb-1">Features:</div>
                        <ul class="text-xs text-muted pl-3 mb-0" style="list-style-type: disc;">
                            <?php foreach (array_slice($feats, 0, 4) as $f): ?>
                                <li><?= e($f) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>

                <button type="button" class="btn btn-secondary btn-sm w-100" data-modal-target="edit-plan-modal-<?= $pl['id'] ?>">
                    <i class="ti ti-pencil mr-1"></i> Edit Plan Settings
                </button>
            </div>

            <!-- MODAL: Edit Plan -->
            <div class="modal-backdrop" id="edit-plan-modal-<?= $pl['id'] ?>" style="display: none;">
                <div class="modal-card" style="max-width: 580px;">
                    <div class="modal-header">
                        <h3 class="modal-title"><i class="ti ti-adjustments mr-2 text-primary"></i> Edit Plan: <?= e($pl['name']) ?></h3>
                        <button type="button" class="modal-close" data-modal-close>&times;</button>
                    </div>
                    <form method="POST" action="<?= url('admin/subscriptions.php') ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="update_plan">
                        <input type="hidden" name="plan_id" value="<?= $pl['id'] ?>">

                        <div class="modal-body">
                            <div class="grid grid-cols-2 gap-3 mb-3">
                                <div class="form-group">
                                    <label class="form-label required">Plan Display Name</label>
                                    <input type="text" name="name" class="form-input" value="<?= e($pl['name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Badge Tag (e.g. Popular, Pro)</label>
                                    <input type="text" name="badge" class="form-input" value="<?= e($pl['badge'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label">Plan Description</label>
                                <input type="text" name="description" class="form-input" value="<?= e($pl['description'] ?? '') ?>">
                            </div>

                            <div class="grid grid-cols-2 gap-3 mb-3">
                                <div class="form-group">
                                    <label class="form-label required">Monthly Price (PKR)</label>
                                    <input type="number" step="1" min="0" name="price_monthly" class="form-input" value="<?= (float)$pl['price_monthly'] ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label required">Annual Price (PKR)</label>
                                    <input type="number" step="1" min="0" name="price_annual" class="form-input" value="<?= (float)$pl['price_annual'] ?>" required>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-3 mb-3">
                                <div class="form-group">
                                    <label class="form-label required">Max Users</label>
                                    <input type="number" min="1" name="max_users" class="form-input" value="<?= (int)$pl['max_users'] ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label required">Max Customers</label>
                                    <input type="number" min="50" name="max_customers" class="form-input" value="<?= (int)$pl['max_customers'] ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label required">Invoices / Mo</label>
                                    <input type="number" min="10" name="max_invoices_per_month" class="form-input" value="<?= (int)$pl['max_invoices_per_month'] ?>" required>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 mb-3">
                                <div class="form-group">
                                    <label class="form-label">AI Digital Manager</label>
                                    <label class="d-flex align-center gap-2 cursor-pointer mt-1">
                                        <input type="checkbox" name="ai_enabled" value="1" <?= $pl['ai_enabled'] ? 'checked' : '' ?>>
                                        <span>Enable AI features</span>
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Monthly AI Request Quota</label>
                                    <input type="number" min="0" name="ai_monthly_limit" class="form-input" value="<?= (int)$pl['ai_monthly_limit'] ?>">
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label">Feature Bullet Points (One per line)</label>
                                <textarea name="features" class="form-textarea" rows="4"><?= implode("\n", $feats ?: []) ?></textarea>
                            </div>

                            <div class="d-flex gap-4">
                                <label class="d-flex align-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="offline_sync_enabled" value="1" <?= $pl['offline_sync_enabled'] ? 'checked' : '' ?>>
                                    <span>Enable Offline Desktop Sync</span>
                                </label>
                                <label class="d-flex align-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="is_active" value="1" <?= $pl['is_active'] ? 'checked' : '' ?>>
                                    <span>Plan is Active for Purchase</span>
                                </label>
                            </div>
                        </div>

                        <div class="modal-footer d-flex justify-between align-center">
                            <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                            <button type="submit" class="btn btn-primary font-bold">
                                <i class="ti ti-device-floppy mr-1"></i> Save Plan Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- SECTION 3: All Business Subscriptions Directory -->
<div class="card mb-4" id="businesses">
    <div class="card-header d-flex justify-between align-center flex-wrap gap-2">
        <div>
            <h3 class="card-title"><i class="ti ti-building-store mr-2 text-primary"></i> All Business Subscriptions Directory</h3>
            <p class="text-xs text-muted mb-0">Monitor active subscription statuses, expiry dates, and assign plans directly.</p>
        </div>
        <form method="GET" action="<?= url('admin/subscriptions.php') ?>" class="d-flex gap-2">
            <input type="text" name="search" class="form-input form-input-sm" placeholder="Search shop name, phone..." value="<?= e($search) ?>">
            <select name="plan" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all">All Plans</option>
                <?php foreach ($plans as $pl): ?>
                    <option value="<?= $pl['slug'] ?>" <?= $filterPlan === $pl['slug'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all">All Statuses</option>
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="trial" <?= $filterStatus === 'trial' ? 'selected' : '' ?>>Trial</option>
                <option value="expired" <?= $filterStatus === 'expired' ? 'selected' : '' ?>>Expired</option>
                <option value="suspended" <?= $filterStatus === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            </select>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Business / Shop</th>
                        <th>Current Plan</th>
                        <th>Status</th>
                        <th>Subscription Expiry</th>
                        <th>Usage (Users / Custs / Invs)</th>
                        <th class="text-right">Assign Plan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($businesses as $b): 
                        $isExp = !empty($b['subscription_expires_at']) && strtotime($b['subscription_expires_at']) < time();
                    ?>
                    <tr>
                        <td>#<?= $b['id'] ?></td>
                        <td>
                            <strong><a href="<?= url('admin/tenant-view.php?id=' . $b['id']) ?>" class="text-decoration-none"><?= e($b['name']) ?></a></strong>
                            <div class="text-xs text-muted"><?= e($b['city'] ?: 'No city') ?> &bull; <?= e($b['phone'] ?: 'No phone') ?></div>
                        </td>
                        <td>
                            <span class="badge badge-primary font-bold"><?= e($b['plan_name'] ?: ucfirst($b['current_plan_slug'])) ?></span>
                        </td>
                        <td>
                            <span class="badge badge-<?= $isExp ? 'danger' : ($b['subscription_status'] === 'active' ? 'success' : 'warning') ?>">
                                <?= $isExp ? 'EXPIRED' : strtoupper(e($b['subscription_status'])) ?>
                            </span>
                        </td>
                        <td class="text-xs">
                            <?php if (!empty($b['subscription_expires_at'])): ?>
                                <div><?= date('d M Y', strtotime($b['subscription_expires_at'])) ?></div>
                                <span class="text-muted"><?= $isExp ? 'Expired' : (ceil((strtotime($b['subscription_expires_at']) - time()) / 86400) . ' days left') ?></span>
                            <?php else: ?>
                                <span class="text-muted">Ongoing / Unlimited</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs">
                            <span title="Users"><i class="ti ti-users mr-1"></i><?= $b['user_count'] ?></span> &bull; 
                            <span title="Customers"><i class="ti ti-user-check mr-1"></i><?= $b['customer_count'] ?></span> &bull; 
                            <span title="Invoices"><i class="ti ti-receipt mr-1"></i><?= $b['invoice_count'] ?></span>
                        </td>
                        <td class="text-right">
                            <button type="button" class="btn btn-xs btn-outline" data-modal-target="assign-modal-<?= $b['id'] ?>">
                                <i class="ti ti-switch-horizontal mr-1"></i> Change Plan
                            </button>
                        </td>
                    </tr>

                    <!-- MODAL: Assign Plan to Shop -->
                    <div class="modal-backdrop" id="assign-modal-<?= $b['id'] ?>" style="display: none;">
                        <div class="modal-card" style="max-width: 480px;">
                            <div class="modal-header">
                                <h3 class="modal-title"><i class="ti ti-crown mr-2 text-warning"></i> Assign Plan: <?= e($b['name']) ?></h3>
                                <button type="button" class="modal-close" data-modal-close>&times;</button>
                            </div>
                            <form method="POST" action="<?= url('admin/subscriptions.php') ?>">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="assign_plan">
                                <input type="hidden" name="business_id" value="<?= $b['id'] ?>">

                                <div class="modal-body">
                                    <div class="form-group mb-3">
                                        <label class="form-label required">Select Plan Tier</label>
                                        <select name="plan_slug" class="form-select" required>
                                            <?php foreach ($plans as $pl): ?>
                                                <option value="<?= $pl['slug'] ?>" <?= ($pl['slug'] === $b['current_plan_slug']) ? 'selected' : '' ?>>
                                                    <?= e($pl['name']) ?> (Rs. <?= number_format($pl['price_monthly'], 0) ?>/mo)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group mb-3">
                                        <label class="form-label required">Duration (Months)</label>
                                        <select name="duration_months" class="form-select" required>
                                            <option value="1">1 Month</option>
                                            <option value="3">3 Months (Quarterly)</option>
                                            <option value="6">6 Months (Half-Yearly)</option>
                                            <option value="12" selected>12 Months (1 Year)</option>
                                            <option value="24">24 Months (2 Years)</option>
                                            <option value="60">60 Months (5 Years)</option>
                                        </select>
                                    </div>

                                    <div class="text-xs text-muted p-2 bg-subtle rounded">
                                        Assigning a plan updates the business subscription status to <strong>ACTIVE</strong> and extends the expiration date automatically.
                                    </div>
                                </div>

                                <div class="modal-footer d-flex justify-between align-center">
                                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                                    <button type="submit" class="btn btn-primary font-bold">
                                        <i class="ti ti-check mr-1"></i> Confirm & Activate Plan
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Modal handler
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-modal-target]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var targetId = this.getAttribute('data-modal-target');
            var el = document.getElementById(targetId);
            if (el) el.style.display = 'flex';
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var modal = this.closest('.modal-backdrop');
            if (modal) modal.style.display = 'none';
        });
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
