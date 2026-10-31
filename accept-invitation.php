<?php
/**
 * YBT Management Software — Accept Staff Invitation Portal
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/staff-service.php';

if (Auth::check()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$invitation = null;
$error = '';
$tokenHash = '';

if ($token === '') {
    $error = 'Missing invitation token.';
} else {
    $tokenHash = hash('sha256', $token);
    $invitation = Database::fetch(
        "SELECT si.*, b.name as business_name, r.name as role_name 
         FROM staff_invitations si
         JOIN businesses b ON si.business_id = b.id
         JOIN roles r ON si.role_id = r.id
         WHERE si.token_hash = ? AND si.status = 'pending'
         LIMIT 1",
        [$tokenHash]
    );

    if (!$invitation) {
        $error = 'This invitation is invalid or has already been accepted.';
    } elseif (strtotime($invitation['expires_at']) < time()) {
        $error = 'This invitation has expired. Please contact your shop administrator.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invitation) {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $phone = trim($_POST['phone'] ?? '');

        if (empty($password)) {
            $error = 'Please create a password for your account.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Password and confirmation password do not match.';
        } else {
            $result = StaffService::acceptInvitation($token, $password, $phone);
            if ($result['success']) {
                // Auto-login or redirect to login
                Flash::success('Account activated successfully! You can now sign in with your password.');
                header('Location: ' . url('login.php'));
                exit;
            } else {
                $error = $result['error'];
            }
        }
    }
}

$pageTitle = 'Accept Staff Invitation — ' . APP_NAME;
$isPublicPage = true;
require YBT_ROOT . '/includes/header.php';
?>

<style>
body.public-layout {
    min-height: 100vh;
    margin: 0;
    padding: 1.5rem 1rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    background-color: var(--color-bg);
    background-image: 
        radial-gradient(circle at 50% 0%, rgba(14, 165, 233, 0.06) 0%, transparent 60%),
        radial-gradient(circle at 50% 100%, rgba(99, 102, 241, 0.04) 0%, transparent 60%);
    font-family: var(--font-family);
    color: var(--color-text);
}

.auth-card {
    width: 100%;
    max-width: 460px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 2.25rem 2rem;
    box-shadow: var(--shadow-lg, 0 10px 30px rgba(0, 0, 0, 0.06));
    box-sizing: border-box;
}

.auth-header {
    text-align: center;
    margin-bottom: 1.75rem;
}

.auth-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #0EA5E9 0%, #0284C7 100%);
    color: #ffffff;
    font-size: 1.5rem;
    margin-bottom: 0.85rem;
}

.auth-title {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--color-text);
    margin: 0 0 0.35rem 0;
}

.auth-subtitle {
    font-size: 0.85rem;
    color: var(--color-text-muted);
    margin: 0;
}

.info-box {
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 0.85rem 1rem;
    margin-bottom: 1.25rem;
    font-size: 0.85rem;
}

.form-group {
    margin-bottom: 1.15rem;
}

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text);
    margin-bottom: 0.4rem;
}

.input-box {
    position: relative;
    display: flex;
    align-items: center;
}

.input-box i {
    position: absolute;
    left: 0.85rem;
    color: var(--color-text-muted);
    font-size: 1.1rem;
    pointer-events: none;
}

.form-input {
    width: 100%;
    height: 42px;
    padding: 0 1rem 0 2.45rem;
    border: 1px solid var(--color-border);
    border-radius: 8px;
    background: var(--color-bg);
    color: var(--color-text);
    font-family: var(--font-family);
    font-size: 0.875rem;
    transition: all 0.2s ease;
    box-sizing: border-box;
}

.form-input:focus {
    outline: none;
    background: var(--color-surface);
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
}

.btn-submit {
    width: 100%;
    height: 42px;
    border-radius: 8px;
    border: none;
    background: var(--color-primary);
    color: #ffffff;
    font-family: var(--font-family);
    font-size: 0.9rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-submit:hover {
    background: var(--color-primary-hover);
}

.alert-box {
    padding: 0.75rem 0.9rem;
    border-radius: 8px;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    font-size: 0.8125rem;
    line-height: 1.4;
    background: rgba(220, 38, 38, 0.08);
    border: 1px solid rgba(220, 38, 38, 0.2);
    color: #DC2626;
}

.auth-nav {
    margin-top: 1.5rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--color-border);
    text-align: center;
    font-size: 0.8125rem;
    color: var(--color-text-muted);
}

.auth-nav a {
    color: var(--color-primary);
    text-decoration: none;
    font-weight: 500;
}
</style>

<div class="auth-card">
    <div class="auth-header">
        <div class="auth-badge">
            <i class="ti ti-user-check"></i>
        </div>
        <h1 class="auth-title">Join Staff Team</h1>
        <p class="auth-subtitle">Activate your account to collaborate on <?= e(APP_NAME) ?></p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert-box">
            <i class="ti ti-alert-circle mt-0.5"></i>
            <div><?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($invitation): ?>
        <div class="info-box">
            <div><strong>Shop:</strong> <?= e($invitation['business_name']) ?></div>
            <div><strong>Role:</strong> <?= e($invitation['role_name']) ?></div>
            <div><strong>Email:</strong> <?= e($invitation['email']) ?></div>
        </div>

        <form method="POST" action="<?= url('accept-invitation.php') ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="form-group">
                <label for="password" class="form-label">Create Password</label>
                <div class="input-box">
                    <i class="ti ti-lock"></i>
                    <input type="password" id="password" name="password" class="form-input" 
                           placeholder="At least 8 characters" required minlength="8" autofocus>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <div class="input-box">
                    <i class="ti ti-lock-check"></i>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" 
                           placeholder="Re-enter password" required minlength="8">
                </div>
            </div>

            <div class="form-group">
                <label for="phone" class="form-label">Phone Number (Optional)</label>
                <div class="input-box">
                    <i class="ti ti-phone"></i>
                    <input type="text" id="phone" name="phone" class="form-input" 
                           placeholder="e.g. +91 9876543210">
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <span>Accept & Activate Account</span>
                <i class="ti ti-check"></i>
            </button>
        </form>
    <?php endif; ?>

    <div class="auth-nav">
        Already have an active account? <a href="<?= url('login.php') ?>">Sign In</a>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
