<?php
/**
 * YBT Management Software — Forgot Password Request
 * Safe against user enumeration, enforces 6-digit OTP code hashing,
 * 15-minute expiration, and 60-second resend cooldown.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/mailer-service.php';

if (Auth::check()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'error';
        } else {
            // Find user
            $user = Database::fetch(
                "SELECT id, business_id, name, email, is_active, verification_status 
                 FROM users WHERE email = ? LIMIT 1",
                [$email]
            );

            // Generic message to protect user privacy
            $genericSuccess = "If an account matches that email address, a 6-digit password reset code has been dispatched. Please check your inbox and spam folder.";

            if (!$user || (int)$user['is_active'] === 0 || $user['verification_status'] === 'suspended') {
                $message = $genericSuccess;
                $messageType = 'success';
            } else {
                // Cooldown check (60 seconds) in portable PHP
                $recent = Database::fetch(
                    "SELECT created_at FROM password_resets 
                     WHERE user_id = ? AND used_at IS NULL 
                     ORDER BY id DESC LIMIT 1",
                    [$user['id']]
                );

                $canSend = true;
                if ($recent && !empty($recent['created_at'])) {
                    $elapsed = time() - strtotime($recent['created_at']);
                    if ($elapsed < 60) {
                        $remaining = 60 - $elapsed;
                        $message = "Please wait {$remaining} second(s) before requesting another reset code.";
                        $messageType = 'error';
                        $canSend = false;
                    }
                }

                if ($canSend) {
                    // Invalidate existing unused tokens for this user
                    Database::query(
                        "DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL",
                        [$user['id']]
                    );

                    // Generate 6-digit numeric OTP (15-min expiry)
                    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $tokenHash = hash('sha256', $otp);
                    $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 minutes

                    Database::insert('password_resets', [
                        'user_id' => $user['id'],
                        'email' => $email,
                        'token_hash' => $tokenHash,
                        'expires_at' => $expiresAt
                    ]);

                    MailerService::sendPasswordResetOtp($email, $user['name'], $otp);

                    ActivityLog::log($user['business_id'], $user['id'], 'password_reset_requested', 'user', $user['id'], "Password reset OTP requested for {$email}");

                    Flash::success("A 6-digit password reset code has been sent to your email. Enter it below to reset your password.");
                    header('Location: ' . url('reset-password.php?email=' . urlencode($email) . '&notice=sent'));
                    exit;
                }
            }
        }
    }
}

$pageTitle = 'Forgot Password — YBT Management';
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

.clean-pwd-card {
    width: 100%;
    max-width: 440px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 2.25rem 2rem;
    box-shadow: var(--shadow-lg, 0 10px 30px rgba(0, 0, 0, 0.06));
    box-sizing: border-box;
}
</style>

<div class="clean-pwd-card">
    <div class="text-center mb-4">
        <div style="width: 54px; height: 54px; border-radius: 12px; background: rgba(14, 165, 233, 0.12); color: var(--color-primary); display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 0.75rem;">
            <i class="ti ti-key"></i>
        </div>
        <h1 style="font-size: 1.4rem; font-weight: 700; margin-bottom: 0.35rem;">Forgot Password</h1>
        <p class="text-muted text-xs">Enter your registered email address to receive a 6-digit password reset code</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> mb-3" style="font-size: 0.85rem; border-radius: 8px;">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php Flash::render(); ?>

    <form action="<?= url('forgot-password.php') ?>" method="POST">
        <?= Csrf::field() ?>

        <div class="clean-form-group mb-3">
            <label for="email" class="clean-label">Email Address</label>
            <div class="clean-input-box">
                <i class="ti ti-mail input-icon"></i>
                <input type="email" id="email" name="email" class="clean-input" 
                       placeholder="owner@example.com" required autofocus 
                       value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
            </div>
        </div>

        <button type="submit" class="clean-btn-submit mb-3">
            <span>Send Reset Code</span>
            <i class="ti ti-arrow-right"></i>
        </button>

        <div class="text-center">
            <a href="<?= url('reset-password.php') ?>" class="text-xs text-muted">
                Already have a 6-digit code? Reset password
            </a>
            <div class="mt-2">
                <a href="<?= url('login.php') ?>" class="text-xs text-muted">
                    <i class="ti ti-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
    </form>
</div>

<div class="clean-login-footer mt-4 text-center">
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &bull; Multi-Business MIS
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
