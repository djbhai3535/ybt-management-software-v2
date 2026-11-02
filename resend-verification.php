<?php
/**
 * YBT Management Software — Resend Verification Code
 * Safe against user enumeration, enforces 60-second cooldown per email,
 * and uses 6-digit numeric OTP codes.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/mailer-service.php';

if (Auth::check()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$message = '';
$messageType = '';
$prefillEmail = trim($_GET['email'] ?? '');

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
            // Check for user
            $user = Database::fetch(
                "SELECT u.id, u.name, u.email, u.business_id, u.verification_status, u.is_active 
                 FROM users u 
                 WHERE u.email = ? LIMIT 1",
                [$email]
            );

            // Generic message to prevent enumeration
            $genericSuccess = "If an unverified account exists for this email address, a new 6-digit verification code has been dispatched. Please check your inbox and spam folder.";

            if (!$user || ($user['verification_status'] === 'verified' && (int)$user['is_active'] === 1)) {
                $message = $genericSuccess;
                $messageType = 'success';
            } elseif ($user['verification_status'] === 'suspended') {
                $message = 'This account has been suspended. Please contact support.';
                $messageType = 'error';
            } else {
                // Check 60-second cooldown in portable PHP
                $recentToken = Database::fetch(
                    "SELECT created_at FROM email_verifications 
                     WHERE user_id = ? AND used_at IS NULL 
                     ORDER BY id DESC LIMIT 1",
                    [$user['id']]
                );

                $cooldownSeconds = 60;
                $canSend = true;

                if ($recentToken && !empty($recentToken['created_at'])) {
                    $elapsed = time() - strtotime($recentToken['created_at']);
                    if ($elapsed < $cooldownSeconds) {
                        $remaining = $cooldownSeconds - $elapsed;
                        $message = "Please wait {$remaining} second(s) before requesting another verification code.";
                        $messageType = 'error';
                        $canSend = false;
                    }
                }

                if ($canSend) {
                    // Invalidate older unused tokens for this user
                    Database::query(
                        "DELETE FROM email_verifications WHERE user_id = ? AND used_at IS NULL",
                        [$user['id']]
                    );

                    // Generate 6-digit numeric OTP (15-min expiry)
                    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $tokenHash = hash('sha256', $otp);
                    $expiresAt = date('Y-m-d H:i:s', time() + 900);

                    Database::insert('email_verifications', [
                        'user_id' => $user['id'],
                        'business_id' => $user['business_id'],
                        'email' => $email,
                        'token_hash' => $tokenHash,
                        'token_type' => 'register',
                        'expires_at' => $expiresAt
                    ]);

                    MailerService::sendVerificationOtp($email, $user['name'], $otp);

                    ActivityLog::log($user['business_id'], $user['id'], 'resend_verification', 'user', $user['id'], "Verification code resent to {$email}");

                    Flash::success("A new 6-digit verification code has been sent. Please enter it below.");
                    header('Location: ' . url('verify-email.php?email=' . urlencode($email)));
                    exit;
                }
            }
        }
    }
}

$pageTitle = 'Resend Verification Code — ' . APP_NAME;
$isPublicPage = true;
require YBT_ROOT . '/includes/header.php';
?>

<style>
body.public-layout {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    background-color: var(--color-bg);
    padding: 1.5rem 1rem;
}

.clean-verify-card {
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

<div class="clean-verify-card">
    <div class="text-center mb-4">
        <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(14, 165, 233, 0.12); color: var(--color-primary); display: inline-flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 0.75rem;">
            <i class="ti ti-mail-forward"></i>
        </div>
        <h1 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 0.25rem;">Resend Verification Code</h1>
        <p class="text-muted text-xs">Enter your registered email address to receive a fresh 6-digit code</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> mb-3" style="font-size: 0.85rem; border-radius: 8px;">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <form action="<?= url('resend-verification.php') ?>" method="POST">
        <?= Csrf::field() ?>

        <div class="clean-form-group mb-3">
            <label for="email" class="clean-label">Email Address</label>
            <div class="clean-input-box">
                <i class="ti ti-mail input-icon"></i>
                <input type="email" id="email" name="email" class="clean-input" 
                       placeholder="owner@example.com" required autofocus 
                       value="<?= e($prefillEmail) ?>" autocomplete="email">
            </div>
        </div>

        <button type="submit" class="clean-btn-submit mb-3">
            <span>Send New Verification Code</span>
            <i class="ti ti-send"></i>
        </button>

        <div class="text-center">
            <a href="<?= url('verify-email.php?email=' . urlencode($prefillEmail)) ?>" class="text-xs text-muted">
                <i class="ti ti-arrow-left"></i> Enter existing code
            </a>
            <span class="mx-2 text-muted">&bull;</span>
            <a href="<?= url('login.php') ?>" class="text-xs text-muted">Sign In</a>
        </div>
    </form>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
