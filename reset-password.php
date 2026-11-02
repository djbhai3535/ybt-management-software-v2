<?php
/**
 * YBT Management Software — Reset Password Processor
 * Validates 6-digit numeric OTP code with SHA-256 hash, enforces 15-minute expiration,
 * updates user password, and revokes tokens.
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$email = strtolower(trim($_GET['email'] ?? $_POST['email'] ?? ''));
$otp = trim($_GET['otp'] ?? $_GET['token'] ?? $_POST['otp'] ?? $_POST['token'] ?? '');
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (empty($otp)) {
            $error = 'Please enter your 6-digit reset code.';
        } elseif (empty($password)) {
            $error = 'Please enter a new password.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Password and confirmation password do not match.';
        } else {
            $tokenHash = hash('sha256', $otp);

            $resetRecord = Database::fetch(
                "SELECT pr.*, u.id as user_id, u.name, u.business_id, u.is_active 
                 FROM password_resets pr
                 JOIN users u ON pr.user_id = u.id
                 WHERE pr.email = ? AND pr.token_hash = ? AND pr.used_at IS NULL
                 ORDER BY pr.id DESC LIMIT 1",
                [$email, $tokenHash]
            );

            if (!$resetRecord) {
                $alreadyUsed = Database::fetch(
                    "SELECT id FROM password_resets WHERE email = ? AND token_hash = ? AND used_at IS NOT NULL LIMIT 1",
                    [$email, $tokenHash]
                );
                if ($alreadyUsed) {
                    $error = 'This password reset code has already been used. Please request a new one.';
                } else {
                    $error = 'Invalid password reset code. Please check the 6 digits and try again.';
                }
            } elseif (strtotime($resetRecord['expires_at']) < time()) {
                $error = 'This password reset code has expired. Please request a new one.';
            } else {
                // Code is valid! Proceed with secure password reset
                $db = Database::getInstance();
                $db->beginTransaction();

                try {
                    $now = date('Y-m-d H:i:s');

                    // 1. Mark token as used
                    Database::query(
                        "UPDATE password_resets SET used_at = ? WHERE id = ?",
                        [$now, $resetRecord['id']]
                    );

                    // 2. Update user's password
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    Database::query(
                        "UPDATE users SET password = ?, updated_at = ? WHERE id = ?",
                        [$newHash, $now, $resetRecord['user_id']]
                    );

                    // 3. Update local_sessions if on desktop SQLite
                    if (Database::getDriver() === 'sqlite') {
                        try {
                            $hasTable = Database::fetchColumn("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='local_sessions'");
                            if ($hasTable) {
                                Database::query("UPDATE `local_sessions` SET `password_hash` = ? WHERE `user_id` = ?", [$newHash, $resetRecord['user_id']]);
                            }
                        } catch (\Throwable $e) {}
                    }

                    // 4. Invalidate API tokens if present
                    try {
                        Database::query("DELETE FROM api_tokens WHERE user_id = ?", [$resetRecord['user_id']]);
                    } catch (\Throwable $e) {}

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
}

$pageTitle = 'Reset Password — YBT Management';
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

.clean-reset-card {
    width: 100%;
    max-width: 440px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 2.25rem 2rem;
    box-shadow: var(--shadow-lg, 0 10px 30px rgba(0, 0, 0, 0.06));
    box-sizing: border-box;
}

.otp-input-center {
    font-size: 1.5rem;
    letter-spacing: 0.4rem;
    text-align: center;
    font-weight: 700;
    font-family: var(--font-mono, monospace);
    height: 48px;
}
</style>

<div class="clean-reset-card">
    <div class="text-center mb-4">
        <div style="width: 54px; height: 54px; border-radius: 12px; background: rgba(14, 165, 233, 0.12); color: var(--color-primary); display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 0.75rem;">
            <i class="ti ti-lock-open"></i>
        </div>
        <h1 style="font-size: 1.4rem; font-weight: 700; margin-bottom: 0.35rem;">Set New Password</h1>
        <p class="text-muted text-xs">Enter your 6-digit reset code and choose a new password</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="clean-alert mb-3">
            <i class="ti ti-alert-circle"></i>
            <div><?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <?php Flash::render(); ?>

    <form action="<?= url('reset-password.php') ?>" method="POST" id="reset-form">
        <?= Csrf::field() ?>

        <div class="clean-form-group mb-3">
            <label for="email" class="clean-label">Email Address *</label>
            <div class="clean-input-box">
                <i class="ti ti-mail input-icon"></i>
                <input type="email" id="email" name="email" class="clean-input" 
                       placeholder="owner@example.com" required 
                       value="<?= e($email) ?>" autocomplete="email">
            </div>
        </div>

        <div class="clean-form-group mb-3">
            <label for="otp" class="clean-label">6-Digit Reset Code *</label>
            <div class="clean-input-box">
                <input type="text" id="otp" name="otp" class="clean-input otp-input-center" 
                       placeholder="••••••" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" 
                       required value="<?= e($otp) ?>" autofocus autocomplete="one-time-code">
            </div>
            <small class="text-muted text-xs mt-1 d-block">
                Check your email inbox or spam folder for the code.
            </small>
        </div>

        <div class="clean-form-group mb-3">
            <label for="password" class="clean-label">New Password * (min. 8 chars)</label>
            <div class="clean-input-box">
                <i class="ti ti-lock input-icon"></i>
                <input type="password" id="password" name="password" class="clean-input" 
                       placeholder="••••••••" required minlength="8" autocomplete="new-password">
            </div>
        </div>

        <div class="clean-form-group mb-4">
            <label for="confirm_password" class="clean-label">Confirm New Password *</label>
            <div class="clean-input-box">
                <i class="ti ti-lock-check input-icon"></i>
                <input type="password" id="confirm_password" name="confirm_password" class="clean-input" 
                       placeholder="••••••••" required minlength="8" autocomplete="new-password">
            </div>
        </div>

        <button type="submit" class="clean-btn-submit mb-3" id="btn-submit">
            <span>Reset Password & Sign In</span>
            <i class="ti ti-arrow-right"></i>
        </button>

        <div class="text-center">
            <a href="<?= url('forgot-password.php') ?>" class="text-xs text-muted">
                Didn't get the code? Request again
            </a>
            <span class="mx-2 text-muted">&bull;</span>
            <a href="<?= url('login.php') ?>" class="text-xs text-muted">Sign In</a>
        </div>
    </form>
</div>

<div class="clean-login-footer mt-4 text-center">
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &bull; Multi-Business MIS
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
