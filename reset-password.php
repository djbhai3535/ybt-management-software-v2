<?php
/**
 * YBT Management Software — Reset Password Processor
 * Validates SHA-256 hashed token, enforces expiration, updates password and revokes existing tokens.
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$email = strtolower(trim($_GET['email'] ?? $_POST['email'] ?? ''));

$tokenValid = false;
$resetRecord = null;
$error = '';
$tokenHash = '';

if ($token === '') {
    $error = 'Missing password reset token.';
} else {
    $tokenHash = hash('sha256', $token);
    $resetRecord = Database::fetch(
        "SELECT pr.*, u.name, u.business_id, u.is_active 
         FROM password_resets pr
         JOIN users u ON pr.user_id = u.id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL
         LIMIT 1",
        [$tokenHash]
    );

    if (!$resetRecord) {
        $error = 'This password reset link is invalid or has already been used.';
    } elseif (strtotime($resetRecord['expires_at']) < time()) {
        $error = 'This password reset link has expired. Please request a new one.';
    } elseif (!$resetRecord['is_active']) {
        $error = 'The associated account has been deactivated.';
    } else {
        $tokenValid = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($password)) {
            $error = 'Please enter a new password.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Password and confirmation password do not match.';
        } else {
            $db = Database::getInstance();
            $db->beginTransaction();

            try {
                // 1. Mark token as used
                Database::query("UPDATE password_resets SET used_at = NOW() WHERE id = ?", [$resetRecord['id']]);

                // 2. Update user's password
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                Database::query(
                    "UPDATE users SET password = ?, failed_login_count = 0, locked_until = NULL WHERE id = ?",
                    [$newHash, $resetRecord['user_id']]
                );

                // 3. Security: Invalidate all existing API tokens for this user
                Database::query("DELETE FROM api_tokens WHERE user_id = ?", [$resetRecord['user_id']]);

                $db->commit();

                ActivityLog::log(
                    $resetRecord['business_id'],
                    $resetRecord['user_id'],
                    'password_changed',
                    'user',
                    $resetRecord['user_id'],
                    'Password reset completed successfully'
                );

                Flash::success('Your password has been reset successfully. You can now sign in with your new password.');
                header('Location: ' . url('login.php'));
                exit;

            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Password reset error: ' . $e->getMessage());
                $error = 'An error occurred while resetting your password. Please try again.';
            }
        }
    }
}

$pageTitle = 'Set New Password — YBT Management';
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
    max-width: 440px;
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

.form-group {
    margin-bottom: 1.25rem;
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

.auth-nav a:hover {
    text-decoration: underline;
}
</style>

<div class="auth-card">
    <div class="auth-header">
        <div class="auth-badge">
            <i class="ti ti-lock-cog"></i>
        </div>
        <h1 class="auth-title">Set New Password</h1>
        <p class="auth-subtitle"><?= $tokenValid ? 'Create a secure password for your account' : 'Password Reset Verification' ?></p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert-box">
            <i class="ti ti-alert-circle mt-0.5"></i>
            <div><?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($tokenValid): ?>
        <form method="POST" action="<?= url('reset-password.php') ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="email" value="<?= e($email) ?>">

            <div class="form-group">
                <label for="password" class="form-label">New Password</label>
                <div class="input-box">
                    <i class="ti ti-lock"></i>
                    <input type="password" id="password" name="password" class="form-input" 
                           placeholder="At least 8 characters" required minlength="8" autofocus>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <div class="input-box">
                    <i class="ti ti-lock-check"></i>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" 
                           placeholder="Re-enter new password" required minlength="8">
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <span>Save New Password</span>
                <i class="ti ti-check"></i>
            </button>
        </form>
    <?php else: ?>
        <div class="text-center py-2">
            <a href="<?= url('forgot-password.php') ?>" class="btn btn-outline-primary btn-sm">
                <i class="ti ti-arrow-back-up mr-1"></i> Request a New Link
            </a>
        </div>
    <?php endif; ?>

    <div class="auth-nav">
        Remember your password? <a href="<?= url('login.php') ?>">Back to Login</a>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
