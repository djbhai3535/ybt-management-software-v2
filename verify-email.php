<?php
/**
 * YBT Management Software — Email OTP Verification Handler
 * Supports 6-digit numeric OTP verification, SHA-256 token hashing,
 * 15-minute expiry, and 60-second resend cooldown.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/mailer-service.php';

$pageTitle = 'Verify Your Email — ' . APP_NAME;
$isPublicPage = true;

$email = strtolower(trim($_GET['email'] ?? $_POST['email'] ?? ''));
$error = '';
$success = '';
$state = 'input'; // 'input', 'verified', 'already_verified'

// Calculate resend cooldown
$resendCooldown = 0;
if (!empty($email)) {
    $recentToken = Database::fetch(
        "SELECT created_at FROM email_verifications WHERE email = ? ORDER BY id DESC LIMIT 1",
        [$email]
    );
    if ($recentToken && !empty($recentToken['created_at'])) {
        $elapsed = time() - strtotime($recentToken['created_at']);
        if ($elapsed < 60) {
            $resendCooldown = 60 - $elapsed;
        }
    }
}

// 1. Handle POST actions: verify OTP or resend OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? 'verify';

        if ($action === 'resend') {
            // Resend 6-digit verification code
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                $user = Database::fetch(
                    "SELECT u.id, u.name, u.email, u.business_id, u.verification_status, u.is_active 
                     FROM users u WHERE u.email = ? LIMIT 1",
                    [$email]
                );

                if (!$user) {
                    $error = 'No account found with this email address. Please register.';
                } elseif ($user['verification_status'] === 'verified' && (int)$user['is_active'] === 1) {
                    $state = 'already_verified';
                } elseif ($resendCooldown > 0) {
                    $error = "Please wait {$resendCooldown} second(s) before requesting a new verification code.";
                } else {
                    // Invalidate older unused tokens for this email
                    Database::query("DELETE FROM email_verifications WHERE email = ? AND used_at IS NULL", [$email]);

                    // Generate new 6-digit numeric OTP (15-min expiry)
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

                    $mailSent = MailerService::sendVerificationOtp($email, $user['name'], $otp);
                    if ($mailSent) {
                        $success = 'A new 6-digit verification code has been dispatched to your email.';
                    } else {
                        $error = 'Failed to deliver verification code to your email. Please verify SMTP settings.';
                    }
                    $resendCooldown = 60;
                }
            }

        } else {
            // Verify 6-digit OTP code
            $otp = trim($_POST['otp'] ?? '');

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter your registered email address.';
            } elseif (empty($otp)) {
                $error = 'Please enter the 6-digit verification code.';
            } elseif (!preg_match('/^\d{6}$/', $otp)) {
                $error = 'The verification code must be exactly 6 digits.';
            } else {
                $tokenHash = hash('sha256', $otp);

                $record = Database::fetch(
                    "SELECT ev.*, u.id as user_id, u.name as user_name, u.password as user_password, 
                            u.business_id, u.role_id, r.slug as role_slug, 
                            b.name as business_name, b.default_currency, b.tenant_uuid
                     FROM email_verifications ev
                     JOIN users u ON ev.user_id = u.id
                     JOIN roles r ON u.role_id = r.id
                     JOIN businesses b ON u.business_id = b.id
                     WHERE ev.email = ? AND ev.token_hash = ? AND ev.used_at IS NULL
                     ORDER BY ev.id DESC LIMIT 1",
                    [$email, $tokenHash]
                );

                if (!$record) {
                    $alreadyUsed = Database::fetch(
                        "SELECT id FROM email_verifications WHERE email = ? AND token_hash = ? AND used_at IS NOT NULL LIMIT 1",
                        [$email, $tokenHash]
                    );
                    if ($alreadyUsed) {
                        $error = 'This verification code has already been used. Please log in.';
                    } else {
                        $error = 'Invalid verification code. Please check the 6 digits and try again.';
                    }
                } elseif (strtotime($record['expires_at']) < time()) {
                    $error = 'This verification code has expired. Please request a new code.';
                } else {
                    // Valid OTP! Activate user & business inside transaction
                    $db = Database::getInstance();
                    $db->beginTransaction();

                    try {
                        $now = date('Y-m-d H:i:s');

                        // Mark verification code as used
                        Database::query(
                            "UPDATE email_verifications SET used_at = ? WHERE id = ?",
                            [$now, $record['id']]
                        );

                        // Activate user account
                        Database::query(
                            "UPDATE users SET email_verified_at = ?, verification_status = 'verified', is_active = 1 WHERE id = ?",
                            [$now, $record['user_id']]
                        );

                        // Activate business tenant
                        Database::query(
                            "UPDATE businesses SET status = 'active' WHERE id = ?",
                            [$record['business_id']]
                        );

                        $db->commit();

                        // Provision local_sessions in SQLite if present
                        if (Database::getDriver() === 'sqlite') {
                            try {
                                $sessionTableExists = Database::fetchColumn("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='local_sessions'");
                                if ($sessionTableExists) {
                                    Database::insert('local_sessions', [
                                        'device_id' => 'desktop-local',
                                        'business_id' => $record['business_id'],
                                        'tenant_uuid' => $record['tenant_uuid'],
                                        'user_id' => $record['user_id'],
                                        'user_email' => $record['email'],
                                        'user_name' => $record['user_name'],
                                        'role_slug' => $record['role_slug'],
                                        'password_hash' => $record['user_password'],
                                        'encrypted_api_token' => 'offline_local_token_' . bin2hex(random_bytes(16)),
                                        'status' => 'active',
                                        'last_offline_login_at' => $now,
                                        'expires_at' => date('Y-m-d H:i:s', time() + (30 * 86400))
                                    ]);
                                }
                            } catch (\Throwable $t) {
                                // Non-critical local session sync
                            }
                        }

                        // Authenticate session for verified user
                        if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
                            session_regenerate_id(true);
                        }

                        $_SESSION['user_id'] = (int)$record['user_id'];
                        $_SESSION['business_id'] = (int)$record['business_id'];
                        $_SESSION['tenant_uuid'] = $record['tenant_uuid'];
                        $_SESSION['is_owner'] = true;
                        $_SESSION['role_slug'] = $record['role_slug'];
                        $_SESSION['user_name'] = $record['user_name'];
                        $_SESSION['user_email'] = $record['email'];
                        $_SESSION['business_name'] = $record['business_name'];
                        $_SESSION['default_currency'] = $record['default_currency'] ?? 'PKR';
                        $_SESSION['login_time'] = time();

                        TenantContext::set((int)$record['business_id'], [
                            'name' => $record['business_name'],
                            'default_currency' => $record['default_currency'] ?? 'PKR',
                            'tenant_uuid' => $record['tenant_uuid'],
                            'role_slug' => $record['role_slug'],
                            'is_owner' => true
                        ]);

                        ActivityLog::log($record['business_id'], $record['user_id'], 'email_verified', 'user', $record['user_id'], 'Email OTP verified successfully');

                        Flash::success('Your email and business account have been successfully verified!');
                        header('Location: ' . url('dashboard.php'));
                        exit;

                    } catch (\Throwable $t) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        error_log('Verification activation error: ' . $t->getMessage());
                        $error = 'An error occurred during account activation. Please try again.';
                    }
                }
            }
        }
    }
}

// Check if user is already verified
if (!empty($email) && empty($error)) {
    $chkUser = Database::fetch("SELECT is_active, verification_status FROM users WHERE email = ? LIMIT 1", [$email]);
    if ($chkUser && (int)$chkUser['is_active'] === 1 && $chkUser['verification_status'] === 'verified') {
        $state = 'already_verified';
    }
}

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
    max-width: 460px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 2.25rem 2rem;
    box-shadow: var(--shadow-lg, 0 10px 30px rgba(0, 0, 0, 0.06));
    box-sizing: border-box;
    text-align: center;
}

.verify-icon-box {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    margin: 0 auto 1.25rem auto;
}
.verify-icon-info {
    background: rgba(14, 165, 233, 0.12);
    color: var(--color-primary, #0ea5e9);
}
.verify-icon-success {
    background: rgba(16, 185, 129, 0.12);
    color: var(--color-success, #10b981);
}

.otp-input-field {
    font-size: 1.8rem;
    letter-spacing: 0.5rem;
    text-align: center;
    font-weight: 700;
    font-family: var(--font-mono, monospace);
    height: 54px;
}
</style>

<div class="clean-verify-card">
    <?php if ($state === 'already_verified'): ?>
        <div class="verify-icon-box verify-icon-success">
            <i class="ti ti-circle-check"></i>
        </div>
        <h2 class="mb-2" style="font-size: 1.4rem; font-weight: 700;">Account Already Verified</h2>
        <p class="text-muted text-sm mb-4">
            Your account is verified and ready. You can log in and manage your business.
        </p>
        <a href="<?= url('login.php') ?>" class="btn btn-primary w-100" style="height: 42px; display: flex; align-items: center; justify-content: center;">
            <i class="ti ti-login mr-1"></i> Proceed to Login
        </a>

    <?php else: ?>
        <div class="verify-icon-box verify-icon-info">
            <i class="ti ti-shield-check"></i>
        </div>

        <h1 class="mb-2" style="font-size: 1.45rem; font-weight: 700;">Verify Your Email</h1>
        <p class="text-muted text-sm mb-3">
            Enter the 6-digit verification code sent to:<br>
            <strong class="text-primary"><?= e($email ?: 'your email address') ?></strong>
        </p>

        <?php if (!empty($error)): ?>
            <div class="clean-alert mb-3 text-left">
                <i class="ti ti-alert-circle"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success mb-3 text-left" style="font-size: 0.85rem; border-radius: 8px;">
                <i class="ti ti-check mr-1"></i> <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php Flash::render(); ?>

        <!-- OTP Verification Form -->
        <form action="<?= url('verify-email.php') ?>" method="POST" id="verify-form" class="mb-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="verify">

            <?php if (empty($email)): ?>
                <div class="clean-form-group text-left mb-3">
                    <label for="email" class="clean-label">Email Address *</label>
                    <div class="clean-input-box">
                        <i class="ti ti-mail input-icon"></i>
                        <input type="email" id="email" name="email" class="clean-input" 
                               placeholder="your@email.com" required 
                               value="<?= e($email) ?>">
                    </div>
                </div>
            <?php else: ?>
                <input type="hidden" name="email" value="<?= e($email) ?>">
            <?php endif; ?>

            <div class="clean-form-group mb-3">
                <label for="otp" class="clean-label text-left">6-Digit Verification Code</label>
                <div class="clean-input-box">
                    <input type="text" id="otp" name="otp" class="clean-input otp-input-field" 
                           placeholder="••••••" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" 
                           required autofocus autocomplete="one-time-code">
                </div>
                <small class="text-muted text-xs mt-1 d-block text-left">
                    Code expires in 15 minutes.
                </small>
            </div>

            <button type="submit" class="clean-btn-submit" id="btn-verify">
                <span>Verify & Activate Account</span>
                <i class="ti ti-check"></i>
            </button>
        </form>

        <!-- Resend OTP Form -->
        <form action="<?= url('verify-email.php') ?>" method="POST" id="resend-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="resend">
            <input type="hidden" name="email" value="<?= e($email) ?>">

            <div class="d-flex align-items-center justify-content-between text-xs text-muted pt-2 border-top">
                <span>Didn't receive the code?</span>
                <button type="submit" id="btn-resend" class="btn btn-link p-0 text-xs" style="text-decoration: underline;" <?= ($resendCooldown > 0) ? 'disabled' : '' ?>>
                    <?= ($resendCooldown > 0) ? "Resend in {$resendCooldown}s" : "Resend Code" ?>
                </button>
            </div>
        </form>

        <div class="mt-3 pt-2 text-center">
            <a href="<?= url('login.php') ?>" class="text-xs text-muted">
                <i class="ti ti-arrow-left"></i> Return to Login
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="clean-login-footer mt-4 text-center">
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &bull; Multi-Business MIS
</div>

<?php if ($resendCooldown > 0): ?>
<script>
(function() {
    let seconds = <?= (int)$resendCooldown ?>;
    const btn = document.getElementById('btn-resend');
    if (!btn) return;

    const timer = setInterval(function() {
        seconds--;
        if (seconds <= 0) {
            clearInterval(timer);
            btn.removeAttribute('disabled');
            btn.textContent = 'Resend Code';
        } else {
            btn.textContent = 'Resend in ' + seconds + 's';
        }
    }, 1000);
})();
</script>
<?php endif; ?>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
