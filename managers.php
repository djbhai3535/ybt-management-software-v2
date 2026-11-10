<?php
/**
 * YBT Management Software — Managers & Team Permissions
 * Multi-tenant safe staff invitation, user onboarding, role management, and status control.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/staff-service.php';
require_once __DIR__ . '/services/subscription-service.php';

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
        } elseif (!SubscriptionService::canAddUser($businessId)) {
            $subInfo = SubscriptionService::getBusinessPlan($businessId);
            $u = $subInfo['usage']['users'];
            $m = $subInfo['usage']['max_users'];
            Flash::error("You have reached the user limit of your current plan ({$u} / {$m} users). Please upgrade your subscription to add more team members.");
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
                    'is_active' => 1,
                    'email_verified_at' => date('Y-m-d H:i:s'),
                    'verification_status' => 'verified',
                    'onboarding_completed' => 1
                ]);

                // Also insert into local_sessions for immediate offline access
                if (Database::getDriver() === 'sqlite') {
                    $sessionTableExists = Database::fetchColumn("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='local_sessions'");
                    if ($sessionTableExists) {
                        Database::insert('local_sessions', [
                            'device_id' => 'desktop-local',
                            'business_id' => $businessId,
                            'tenant_uuid' => $_SESSION['tenant_uuid'] ?? '',
                            'user_id' => $newId,
                            'user_email' => $email,
                            'user_name' => $name,
                            'role_slug' => 'manager',
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'encrypted_api_token' => 'offline_mgr_token_' . bin2hex(random_bytes(16)),
                            'status' => 'active',
                            'last_offline_login_at' => date('Y-m-d H:i:s'),
                            'expires_at' => date('Y-m-d H:i:s', time() + (30 * 86400))
                        ]);
                    }
                }

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

        // Anti-escalation check: forbid assigning owner role
        $role = Database::fetch("SELECT slug FROM roles WHERE id = ? AND (business_id = ? OR is_system = 1)", [$roleId, $businessId]);
        if (!$role || $role['slug'] === 'owner') {
            Flash::error('Cannot assign Owner role to invited staff.');
            header('Location: ' . url('managers.php'));
            exit;
        }

        if (!SubscriptionService::canAddUser($businessId)) {
            $subInfo = SubscriptionService::getBusinessPlan($businessId);
            $u = $subInfo['usage']['users'];
            $m = $subInfo['usage']['max_users'];
            Flash::error("You have reached the user limit of your current plan ({$u} / {$m} users). Please upgrade your subscription to add more team members.");
            header('Location: ' . url('managers.php'));
            exit;
        }

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

// Handle Cancel Staff Invitation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_invitation') {
    Permissions::requireOwner();
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $invId = (int)($_POST['invitation_id'] ?? 0);
        $res = StaffService::cancelInvitation($businessId, $invId);
        if ($res['success']) {
            Flash::success("Invitation cancelled successfully.");
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
// Exclude owner role from invitation choices
$availableRoles = Database::fetchAll("SELECT id, name, slug FROM roles WHERE (business_id = ? OR is_system = 1) AND slug != 'owner' ORDER BY id ASC", [$businessId]);

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
$subPlan = SubscriptionService::getBusinessPlan($businessId);
$seatsUsed = (int)$subPlan['usage']['users'];
$seatsTotal = (int)$subPlan['usage']['max_users'];
$canAdd = SubscriptionService::canAddUser($businessId);

require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="page-title">Managers & Team Access</h1>
        <p class="page-subtitle">Configure operator accounts, invite employees, and fine-tune Role-Based Access Control (RBAC)</p>
    </div>
    <div class="page-header-actions d-flex align-center gap-2 flex-wrap">
        <span class="badge <?= $canAdd ? 'badge-primary' : 'badge-danger' ?>" style="font-size: 0.85rem; padding: 0.45rem 0.75rem;">
            <i class="ti ti-users mr-1"></i> Team Seats: <?= $seatsUsed ?> / <?= $seatsTotal ?> Used
        </span>
        <?php if (!$canAdd): ?>
            <a href="<?= url('subscription.php') ?>" class="btn btn-warning btn-sm font-bold">
                <i class="ti ti-crown mr-1"></i> Upgrade Plan
            </a>
        <?php endif; ?>
        <?php if (Auth::isOwner()): ?>
            <?php if ($canAdd): ?>
                <button type="button" class="btn btn-outline" data-modal-target="invite-staff-modal">
                    <i class="ti ti-mail-forward mr-1"></i> Invite Employee
                </button>
                <button type="button" class="btn btn-primary" data-modal-target="add-manager-modal">
                    <i class="ti ti-user-plus mr-1"></i> Add Manager Directly
                </button>
            <?php else: ?>
                <a href="<?= url('subscription.php') ?>" class="btn btn-outline-danger btn-sm" title="User limit reached">
                    <i class="ti ti-lock mr-1"></i> Seats Full (Upgrade Plan)
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!$canAdd): ?>
<div class="alert alert-warning mb-4 d-flex justify-between align-center p-3 rounded" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 8px;">
    <div class="d-flex align-center gap-2">
        <i class="ti ti-alert-triangle fs-4 text-warning"></i>
        <div>
            <strong>Team Seat Limit Reached (<?= $seatsUsed ?> / <?= $seatsTotal ?> Seats Used)</strong>
            <div class="text-xs text-muted">You have reached the maximum allowed team members for your current <?= e($subPlan['plan_name']) ?> plan. Please upgrade your subscription to add more staff.</div>
        </div>
    </div>
    <a href="<?= url('subscription.php') ?>" class="btn btn-warning btn-sm font-bold">
        <i class="ti ti-crown mr-1"></i> View Upgrade Plans
    </a>
</div>
<?php endif; ?>

<?php Flash::render(); ?>

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
                        <th>Created</th>
                        <th class="text-right">Actions</th>
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
                            <span class="badge badge-<?= $u['is_active'] ? 'success' : 'danger' ?>">
                                <?= $u['is_active'] ? 'Active' : 'Suspended' ?>
                            </span>
                        </td>
                        <td><?= !empty($u['created_at']) ? date('d M Y', strtotime($u['created_at'])) : '—' ?></td>
                        <td class="text-right">
                            <?php if ($u['is_owner']): ?>
                                <span class="badge badge-primary text-xs"><i class="ti ti-shield-check mr-1"></i> Protected Owner</span>
                            <?php elseif (Auth::isOwner()): ?>
                                <form method="POST" action="<?= url('managers.php') ?>" style="display:inline;">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="toggle_staff_status">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $u['is_active'] ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-xs btn-<?= $u['is_active'] ? 'outline-danger' : 'outline-success' ?>">
                                        <?= $u['is_active'] ? 'Suspend' : 'Activate' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
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
        <h3 class="card-title"><i class="ti ti-mail-opened mr-1 text-warning"></i> Staff Invitations</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invited Member</th>
                        <th>Email</th>
                        <th>Assigned Role</th>
                        <th>Status</th>
                        <th>Expires At</th>
                        <th class="text-right">Action</th>
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
                        <td><?= date('M d, Y H:i', strtotime($inv['expires_at'])) ?></td>
                        <td class="text-right">
                            <?php if ($inv['status'] === 'pending' && Auth::isOwner()): ?>
                                <form method="POST" action="<?= url('managers.php') ?>" style="display:inline;">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="cancel_invitation">
                                    <input type="hidden" name="invitation_id" value="<?= (int)$inv['id'] ?>">
                                    <button type="submit" class="btn btn-xs btn-outline-danger" onclick="return confirm('Cancel this invitation?');">
                                        Cancel / Revoke
                                    </button>
                                </form>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
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
            <h3 class="modal-title"><i class="ti ti-mail-forward mr-1 text-primary"></i> Invite Employee via Email</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form action="<?= url('managers.php') ?>" method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="invite_staff">

            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label required">Employee Full Name</label>
                    <input type="text" name="name" class="form-input" placeholder="e.g. Bilal Ahmed" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label required">Email Address</label>
                    <input type="email" name="email" class="form-input" placeholder="bilal@mobileshop.com" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label required">Select Assigned Role</label>
                    <select name="role_id" class="form-select" required>
                        <?php foreach ($availableRoles as $r): ?>
                            <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-xs text-muted mt-1">Managers have operational management rights. Staff members are limited to POS billing.</div>
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
                    <input type="text" name="name" class="form-input" placeholder="e.g. Asim Raza" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label required">Email Address (Login)</label>
                    <input type="email" name="email" class="form-input" placeholder="asim@mobileshop.com" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone" class="form-input" placeholder="+92 300 1234567">
                </div>
                <div class="form-group">
                    <label class="form-label required">Initial Password</label>
                    <input type="password" name="password" class="form-input" value="password123" required minlength="8">
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
