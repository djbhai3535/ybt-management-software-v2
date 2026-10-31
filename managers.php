<?php
/**
 * YBT Management Software — Managers & Team Permissions
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/staff-service.php';

Auth::requireLogin();
Permissions::require('managers.view');

$businessId = Auth::businessId();
$userId = Auth::id();

// Handle Add Manager POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_manager') {
    Permissions::requireOwner();
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? 'password123';

        if (empty($name) || empty($email)) {
            Flash::error('Name and email are required.');
        } else {
            // Check existing
            $existing = Database::fetch("SELECT id FROM users WHERE email = ?", [$email]);
            if ($existing) {
                Flash::error('A user with this email address already exists.');
            } else {
                $mgrRole = Database::fetch("SELECT id FROM roles WHERE business_id = ? AND slug = 'manager'", [$businessId]);
                $roleId = $mgrRole['id'] ?? 2;

                $newId = Database::insert('users', [
                    'business_id' => $businessId,
                    'role_id' => $roleId,
                    'name' => $name,
                    'email' => $email,
                    'phone' => trim($_POST['phone'] ?? ''),
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'is_owner' => 0,
                    'is_active' => 1
                ]);

                ActivityLog::log($businessId, $userId, 'manager_created', 'user', $newId, "Added manager {$name} ({$email})");
                Flash::success("Manager account for '{$name}' created successfully.");
                header('Location: ' . url('managers.php'));
                exit;
            }
        }
    }
}


// Handle Invite Staff POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'invite_staff') {
    Permissions::requireOwner();
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $roleId = (int)($_POST['role_id'] ?? 0);

        $res = StaffService::invite($businessId, $userId, $email, $name, $roleId);
        if ($res['success']) {
            Flash::success("Staff invitation dispatched successfully to {$email}.");
        } else {
            Flash::error($res['error']);
        }
        header('Location: ' . url('managers.php'));
        exit;
    }
}

// Handle Toggle Staff Status POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_staff_status') {
    Permissions::requireOwner();
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newStatus = (int)($_POST['is_active'] ?? 0);

        $res = StaffService::updateStatus($businessId, $targetUserId, $newStatus);
        if ($res['success']) {
            Flash::success("Staff member status updated successfully.");
        } else {
            Flash::error($res['error']);
        }
        header('Location: ' . url('managers.php'));
        exit;
    }
}

// Handle Update Role Permissions Matrix
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_permissions') {
    Permissions::requireOwner();
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $mgrRole = Database::fetch("SELECT id FROM roles WHERE business_id = ? AND slug = 'manager'", [$businessId]);
        $mgrRoleId = $mgrRole['id'] ?? 2;

        $selectedPerms = $_POST['permissions'] ?? [];

        // Clear existing manager permissions
        Database::query("DELETE FROM role_permissions WHERE role_id = ?", [$mgrRoleId]);

        // Insert new selected permissions
        foreach ($selectedPerms as $permId) {
            Database::insert('role_permissions', [
                'role_id' => $mgrRoleId,
                'permission_id' => (int)$permId
            ]);
        }

        ActivityLog::log($businessId, $userId, 'permissions_updated', 'role', $mgrRoleId, "Owner updated Manager role permission matrix");
        Flash::success('Manager permissions matrix updated successfully.');
        header('Location: ' . url('managers.php'));
        exit;
    }
}


$pendingInvitations = StaffService::listInvitations($businessId);
$availableRoles = Database::fetchAll("SELECT id, name, slug FROM roles WHERE business_id = ? OR is_system = 1 ORDER BY id ASC", [$businessId]);

// Fetch users
$users = Database::fetchAll(
    "SELECT u.*, r.name as role_name 
     FROM users u 
     JOIN roles r ON u.role_id = r.id 
     WHERE u.business_id = ? 
     ORDER BY u.is_owner DESC, u.name ASC",
    [$businessId]
);

// Fetch all permissions grouped
$allPerms = Database::fetchAll("SELECT * FROM permissions ORDER BY group_name ASC, label ASC");
$groupedPerms = [];
foreach ($allPerms as $p) {
    $groupedPerms[$p['group_name']][] = $p;
}

// Fetch currently assigned manager permissions
$mgrRole = Database::fetch("SELECT id FROM roles WHERE business_id = ? AND slug = 'manager'", [$businessId]);
$mgrRoleId = $mgrRole['id'] ?? 2;
$assignedPerms = Database::fetchAll("SELECT permission_id FROM role_permissions WHERE role_id = ?", [$mgrRoleId]);
$activePermIds = array_column($assignedPerms, 'permission_id');

$pageTitle = 'Managers & Role Permissions';
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <h1 class="page-title">Managers & Team Access</h1>
        <p class="page-subtitle">Configure operator accounts, delegate responsibilities, and control access permissions</p>
    </div>
    <div class="page-header-actions">
        <?php if (Auth::isOwner()): ?>
        <button type="button" class="btn btn-primary" data-modal-target="add-manager-modal">
            <i class="ti ti-user-plus mr-1"></i> Add Manager
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Team Accounts Table -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title"><i class="ti ti-users mr-1 text-primary"></i> Team Accounts</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Last Login</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-center gap-2">
                                <div class="avatar avatar-sm avatar-circle bg-primary text-white">
                                    <?= strtoupper(substr($u['name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <strong><?= e($u['name']) ?></strong>
                                    <div class="text-xs text-muted"><?= e($u['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-<?= $u['is_owner'] ? 'primary' : 'secondary' ?>">
                                <?= e($u['role_name']) ?>
                            </span>
                        </td>
                        <td><?= e($u['phone'] ?: '—') ?></td>
                        <td>
                            <span class="badge badge-<?= $u['is_active'] ? 'success' : 'secondary' ?>">
                                <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td><?= !empty($u['last_login_at']) ? timeAgo($u['last_login_at']) : 'Never' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Pending Invitations Card -->
<?php if (!empty($pendingInvitations)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title"><i class="ti ti-mail-opened mr-1 text-warning"></i> Pending Staff Invitations</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invited Name</th>
                        <th>Email</th>
                        <th>Assigned Role</th>
                        <th>Status</th>
                        <th>Invited By</th>
                        <th>Expires At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingInvitations as $inv): ?>
                    <tr>
                        <td><strong><?= e($inv['name']) ?></strong></td>
                        <td><?= e($inv['email']) ?></td>
                        <td><span class="badge badge-secondary"><?= e($inv['role_name']) ?></span></td>
                        <td>
                            <span class="badge badge-<?= $inv['status'] === 'accepted' ? 'success' : ($inv['status'] === 'pending' ? 'warning' : 'danger') ?>">
                                <?= ucfirst($inv['status']) ?>
                            </span>
                        </td>
                        <td><?= e($inv['invited_by_name'] ?? 'System') ?></td>
                        <td><?= date('M d, Y H:i', strtotime($inv['expires_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Manager Role Permission Matrix (Owner Only) -->
<?php if (Auth::isOwner()): ?>
<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <div>
            <h3 class="card-title"><i class="ti ti-lock-access mr-1 text-warning"></i> Manager Role Permission Matrix</h3>
            <p class="text-xs text-muted mb-0">Fine-tune the exact operational capabilities granted to the Operations Manager role.</p>
        </div>
        <button type="submit" form="permissions-matrix-form" class="btn btn-primary btn-sm">
            <i class="ti ti-device-floppy mr-1"></i> Save Permissions
        </button>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= url('managers.php') ?>" id="permissions-matrix-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="update_permissions">

            <div class="grid grid-cols-3 gap-4">
                <?php foreach ($groupedPerms as $groupName => $perms): ?>
                <div class="card p-3 border">
                    <h4 class="text-sm font-bold text-primary mb-2 border-bottom pb-1"><?= e($groupName) ?></h4>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($perms as $p): 
                            $isChecked = in_array((int)$p['id'], $activePermIds, true);
                        ?>
                        <label class="d-flex align-center gap-2 text-xs cursor-pointer">
                            <input type="checkbox" name="permissions[]" value="<?= $p['id'] ?>" <?= $isChecked ? 'checked' : '' ?>>
                            <div>
                                <span class="font-medium"><?= e($p['label']) ?></span>
                                <?php if ($p['is_sensitive']): ?>
                                    <span class="badge badge-danger text-2xs ml-1">Sensitive</span>
                                <?php endif; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>


<!-- Invite Staff Modal -->
<div class="modal-backdrop" id="invite-staff-modal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title"><i class="ti ti-mail-forward mr-1 text-primary"></i> Invite Staff via Email</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form action="<?= url('managers.php') ?>" method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="invite_staff">

            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label required">Full Name</label>
                    <input type="text" name="name" class="form-input" placeholder="e.g. Ali Khan" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label required">Email Address</label>
                    <input type="email" name="email" class="form-input" placeholder="staff@company.com" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label required">Select Role</label>
                    <select name="role_id" class="form-select" required>
                        <?php foreach ($availableRoles as $r): ?>
                            <?php if ($r['slug'] !== 'owner'): ?>
                                <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="modal-footer d-flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-send mr-1"></i> Send Invitation</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Manager Modal -->
<div class="modal-backdrop" id="add-manager-modal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title"><i class="ti ti-user-plus mr-1 text-primary"></i> Create Manager Account</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form action="<?= url('managers.php') ?>" method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="add_manager">

            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label required">Full Name</label>
                    <input type="text" name="name" class="form-input" placeholder="e.g. Rohan Verma" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label required">Email Address (Login)</label>
                    <input type="email" name="email" class="form-input" placeholder="manager@company.com" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone" class="form-input" placeholder="+91 98765 43210">
                </div>
                <div class="form-group">
                    <label class="form-label required">Initial Password</label>
                    <input type="password" name="password" class="form-input" value="password123" required>
                    <span class="text-xs text-muted">Default is 'password123'. The manager can change it in their profile.</span>
                </div>
            </div>

            <div class="modal-footer d-flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-check mr-1"></i> Create Manager</button>
            </div>
        </form>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
