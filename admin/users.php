<?php
/**
 * YBT Management Software — Super Admin Platform User Management
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';

$pageTitle = 'Platform Users & Staff';

// Handle User Status Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_user_status') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired. Please refresh and try again.');
    } else {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newActive = (int)($_POST['is_active'] ?? 1);

        $targetUser = Database::fetch("SELECT id, name, is_super_admin FROM users WHERE id = ?", [$targetUserId]);
        if (!$targetUser) {
            Flash::error('User not found.');
        } elseif (!empty($targetUser['is_super_admin'])) {
            Flash::error('Super Admin accounts cannot be deactivated through user management.');
        } else {
            Database::query("UPDATE users SET is_active = ?, verification_status = ? WHERE id = ?", [$newActive, $newActive ? 'verified' : 'suspended', $targetUserId]);
            Flash::success("User '{$targetUser['name']}' status updated successfully.");
            header('Location: ' . url('admin/users.php'));
            exit;
        }
    }
}

// Filters & Search
$filterRole = $_GET['role'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = ["1=1"];
$params = [];

if ($filterRole === 'super_admin') {
    $where[] = "u.is_super_admin = 1";
} elseif ($filterRole === 'owner') {
    $where[] = "(u.is_owner = 1 AND (u.is_super_admin = 0 OR u.is_super_admin IS NULL))";
} elseif ($filterRole === 'manager') {
    $where[] = "(r.slug = 'manager' AND (u.is_super_admin = 0 OR u.is_super_admin IS NULL))";
} elseif ($filterRole === 'staff') {
    $where[] = "(r.slug = 'staff' AND (u.is_super_admin = 0 OR u.is_super_admin IS NULL))";
}

if ($search !== '') {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR b.name LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereClause = implode(' AND ', $where);
$users = Database::fetchAll(
    "SELECT u.*, b.name as business_name, b.status as business_status, r.name as role_name, r.slug as role_slug 
     FROM users u 
     LEFT JOIN businesses b ON u.business_id = b.id 
     LEFT JOIN roles r ON u.role_id = r.id 
     WHERE {$whereClause} 
     ORDER BY u.is_super_admin DESC, u.is_owner DESC, u.id DESC",
    $params
);

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;">Platform Users & Staff</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
            Total <?= count($users) ?> user account(s) registered across all shops.
        </p>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card p-3 mb-4">
    <form method="GET" action="<?= url('admin/users.php') ?>" class="d-flex gap-3 align-center">
        <div style="flex: 1;">
            <input type="text" name="search" class="form-input" placeholder="Search by user name, email, phone, or shop name..." value="<?= e($search) ?>">
        </div>
        <div style="width: 180px;">
            <select name="role" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $filterRole === 'all' ? 'selected' : '' ?>>All Roles</option>
                <option value="owner" <?= $filterRole === 'owner' ? 'selected' : '' ?>>Shop Owners</option>
                <option value="manager" <?= $filterRole === 'manager' ? 'selected' : '' ?>>Managers</option>
                <option value="staff" <?= $filterRole === 'staff' ? 'selected' : '' ?>>Staff</option>
                <option value="super_admin" <?= $filterRole === 'super_admin' ? 'selected' : '' ?>>Super Admins</option>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary">
            <i class="ti ti-search mr-1"></i> Filter
        </button>
        <?php if ($search !== '' || $filterRole !== 'all'): ?>
            <a href="<?= url('admin/users.php') ?>" class="btn btn-secondary btn-outline" title="Reset Filters">
                <i class="ti ti-rotate-clockwise"></i>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Users Table -->
<div class="card p-0">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User Details</th>
                    <th>Role</th>
                    <th>Associated Shop</th>
                    <th>Phone</th>
                    <th>Account Status</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="6" class="text-center p-4 text-muted">
                        No users found matching your search criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): 
                        $isSuper = !empty($u['is_super_admin']);
                        $isOwner = !empty($u['is_owner']);
                        $isActive = (int)$u['is_active'] === 1;
                    ?>
                    <tr>
                        <td>
                            <div class="font-bold"><?= e($u['name']) ?></div>
                            <div class="text-xs text-muted"><?= e($u['email']) ?></div>
                        </td>
                        <td>
                            <?php if ($isSuper): ?>
                                <span class="badge badge-warning font-semibold">SUPER ADMIN</span>
                            <?php elseif ($isOwner): ?>
                                <span class="badge badge-primary font-semibold">Shop Owner</span>
                            <?php else: ?>
                                <span class="badge badge-subtle"><?= e($u['role_name'] ?: ucfirst($u['role_slug'] ?: 'Staff')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($u['business_name'])): ?>
                                <a href="<?= url('admin/tenant-view.php?id=' . $u['business_id']) ?>" class="font-medium text-warning">
                                    <?= e($u['business_name']) ?>
                                </a>
                                <?php if ($u['business_status'] === 'suspended'): ?>
                                    <span class="badge badge-danger text-xs ml-1">Shop Suspended</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($u['phone'] ?: '—') ?></td>
                        <td>
                            <span class="badge badge-<?= $isActive ? 'success' : 'danger' ?>">
                                <?= $isActive ? 'Active' : 'Suspended' ?>
                            </span>
                        </td>
                        <td class="text-right">
                            <?php if (!$isSuper): ?>
                                <form method="POST" action="<?= url('admin/users.php') ?>" style="display: inline;" onsubmit="return confirm('Are you sure you want to <?= $isActive ? 'SUSPEND' : 'ACTIVATE' ?> this user?');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="toggle_user_status">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $isActive ? 0 : 1 ?>">
                                    
                                    <?php if ($isActive): ?>
                                        <button type="submit" class="btn btn-secondary btn-sm" title="Suspend User">
                                            <i class="ti ti-ban text-danger"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-secondary btn-sm" title="Activate User">
                                            <i class="ti ti-check text-success"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php else: ?>
                                <span class="text-xs text-muted">Protected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
