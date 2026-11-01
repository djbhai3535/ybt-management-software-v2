<?php
/**
 * YBT Management Software — Super Admin Tenant Management
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';

$pageTitle = 'Shops & Tenants Directory';

// Handle Tenant Status Toggle (Activate / Suspend)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired. Please refresh and try again.');
    } else {
        $shopId = (int)($_POST['shop_id'] ?? 0);
        $newStatus = $_POST['status'] === 'suspended' ? 'suspended' : 'active';
        $reason = trim($_POST['reason'] ?? '');

        $shop = Database::fetch("SELECT id, name, status FROM businesses WHERE id = ?", [$shopId]);
        if (!$shop) {
            Flash::error('Shop not found.');
        } else {
            $now = date('Y-m-d H:i:s');
            Database::query(
                "UPDATE businesses SET status = ?, suspended_at = ?, suspended_reason = ? WHERE id = ?",
                [$newStatus, $newStatus === 'suspended' ? $now : null, $newStatus === 'suspended' ? $reason : null, $shopId]
            );

            // Log action
            ActivityLog::log(
                $shopId,
                Auth::id(),
                $newStatus === 'suspended' ? 'tenant_suspended' : 'tenant_activated',
                'business',
                $shopId,
                "Super Admin changed status of '{$shop['name']}' to {$newStatus}" . ($reason ? " (Reason: {$reason})" : '')
            );

            Flash::success("Shop '{$shop['name']}' has been successfully " . ($newStatus === 'suspended' ? 'SUSPENDED' : 'ACTIVATED') . ".");
            header('Location: ' . url('admin/tenants.php'));
            exit;
        }
    }
}

// Search and Filter
$filterStatus = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = ["1=1"];
$params = [];

if ($filterStatus === 'active') {
    $where[] = "(status = 'active' OR status IS NULL OR status = '')";
} elseif ($filterStatus === 'suspended') {
    $where[] = "status = 'suspended'";
}

if ($search !== '') {
    $where[] = "(name LIKE ? OR legal_name LIKE ? OR phone LIKE ? OR city LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereClause = implode(' AND ', $where);
$shops = Database::fetchAll(
    "SELECT b.*, 
            (SELECT COUNT(*) FROM users u WHERE u.business_id = b.id) as user_count,
            (SELECT COUNT(*) FROM customers c WHERE c.business_id = b.id) as customer_count,
            (SELECT COUNT(*) FROM invoices i WHERE i.business_id = b.id) as invoice_count
     FROM businesses b 
     WHERE {$whereClause} 
     ORDER BY b.id DESC",
    $params
);

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;">Registered Shops & Tenants</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
            Total <?= count($shops) ?> shop(s) matching your criteria.
        </p>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card p-3 mb-4">
    <form method="GET" action="<?= url('admin/tenants.php') ?>" class="d-flex gap-3 align-center">
        <div style="flex: 1;">
            <input type="text" name="search" class="form-input" placeholder="Search by shop name, legal name, phone, or city..." value="<?= e($search) ?>">
        </div>
        <div style="width: 180px;">
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="suspended" <?= $filterStatus === 'suspended' ? 'selected' : '' ?>>Suspended Only</option>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary">
            <i class="ti ti-search mr-1"></i> Filter
        </button>
        <?php if ($search !== '' || $filterStatus !== 'all'): ?>
            <a href="<?= url('admin/tenants.php') ?>" class="btn btn-secondary btn-outline" title="Reset Filters">
                <i class="ti ti-rotate-clockwise"></i>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Tenants Table -->
<div class="card p-0">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Shop / Business</th>
                    <th>Location & Tax</th>
                    <th>Currency</th>
                    <th class="text-center">Users</th>
                    <th class="text-center">Customers</th>
                    <th class="text-center">Invoices</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($shops)): ?>
                <tr>
                    <td colspan="8" class="text-center p-4 text-muted">
                        No shops found matching your search criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($shops as $shop): 
                        $isSuspended = ($shop['status'] === 'suspended');
                    ?>
                    <tr>
                        <td>
                            <div class="font-bold text-base"><?= e($shop['name']) ?></div>
                            <div class="text-xs text-muted"><?= e($shop['legal_name'] ?: 'No legal name specified') ?></div>
                            <div class="text-xs text-muted">Phone: <?= e($shop['phone'] ?: '—') ?></div>
                        </td>
                        <td>
                            <div><?= e($shop['city'] ?: '—') ?><?= !empty($shop['state']) ? ', ' . e($shop['state']) : '' ?></div>
                            <div class="text-xs text-muted">Country: <?= e($shop['country'] ?: 'PK') ?></div>
                            <?php if (!empty($shop['tax_number'])): ?>
                                <div class="text-xs text-muted"><?= e($shop['tax_label'] ?: 'Tax') ?>: <?= e($shop['tax_number']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-subtle"><?= e($shop['default_currency'] ?: $shop['currency'] ?: 'PKR') ?></span>
                        </td>
                        <td class="text-center font-semibold"><?= (int)$shop['user_count'] ?></td>
                        <td class="text-center font-semibold"><?= (int)$shop['customer_count'] ?></td>
                        <td class="text-center font-semibold"><?= (int)$shop['invoice_count'] ?></td>
                        <td>
                            <span class="badge badge-<?= $isSuspended ? 'danger' : 'success' ?>">
                                <?= $isSuspended ? 'SUSPENDED' : 'ACTIVE' ?>
                            </span>
                            <?php if ($isSuspended && !empty($shop['suspended_at'])): ?>
                                <div class="text-xs text-danger mt-1"><?= formatDate($shop['suspended_at']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <div class="d-inline-flex gap-1">
                                <a href="<?= url('admin/tenant-view.php?id=' . $shop['id']) ?>" class="btn btn-secondary btn-sm" title="View Shop Profile">
                                    <i class="ti ti-eye"></i>
                                </a>

                                <!-- Status Toggle Form -->
                                <form method="POST" action="<?= url('admin/tenants.php') ?>" style="display: inline;" onsubmit="return confirm('Are you sure you want to <?= $isSuspended ? 'ACTIVATE' : 'SUSPEND' ?> this shop? <?= $isSuspended ? 'Users will regain access.' : 'All users of this shop will immediately be blocked from logging in.' ?>');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="shop_id" value="<?= $shop['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $isSuspended ? 'active' : 'suspended' ?>">
                                    
                                    <?php if ($isSuspended): ?>
                                        <button type="submit" class="btn btn-success btn-sm" title="Activate Shop">
                                            <i class="ti ti-check"></i> Activate
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-danger btn-sm" title="Suspend Shop">
                                            <i class="ti ti-ban"></i> Suspend
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
