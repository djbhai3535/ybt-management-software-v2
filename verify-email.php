<?php
/**
 * YBT Management Software — Email Verification Handler & Success Screen
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/mailer-service.php';

$pageTitle = 'Email Verification — YBT Management';
$isPublicPage = true;

$token = trim($_GET['token'] ?? '');
$email = strtolower(trim($_GET['email'] ?? ''));
$notice = trim($_GET['notice'] ?? '');
$inviteToken = trim($_GET['invite_token'] ?? '');

$state = 'notice'; // 'notice', 'verified', 'expired', 'invalid'
$errorMessage = '';

// 1. Process Verification Token
if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $record = Database::fetch(
        "SELECT ev.*, u.id as user_id, u.name as user_name, u.business_id, u.role_id, r.slug as role_slug, b.name as business_name, b.onboarding_step
         FROM email_verifications ev
         JOIN users u ON ev.user_id = u.id
         JOIN roles r ON u.role_id = r.id
         JOIN businesses b ON u.business_id = b.id
         WHERE ev.token_hash = ? AND ev.used_at IS NULL
         LIMIT 1",
        [$tokenHash]
    );

    if (!$record) {
        // Check if token was already used
        $alreadyUsed = Database::fetch("SELECT id FROM email_verifications WHERE token_hash = ? AND used_at IS NOT NULL", [$tokenHash]);
        if ($alreadyUsed) {
            $state = 'already_verified';
        } else {
            $state = 'invalid';
            $errorMessage = 'Invalid or expired verification token.';
        }
    } elseif (strtotime($record['expires_at']) < time()) {
        $state = 'expired';
        $email = $record['email'];
        $errorMessage = 'This verification link has expired. Please request a new one.';
    } else {
        // Token is valid! Activate account in transaction
        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            // Mark token used
            Database::query("UPDATE email_verifications SET used_at = NOW() WHERE id = ?", [$record['id']]);

            // Update user status
            Database::query(
                "UPDATE users SET email_verified_at = NOW(), verification_status = 'verified', is_active = 1 WHERE id = ?",
                [$record['user_id']]
            );

            // Update business status
            Database::query(
                "UPDATE businesses SET status = 'active' WHERE id = ?",
                [$record['business_id']]
            );

            $db->commit();

            // Auto-login verified user
            $_SESSION['user_id'] = $record['user_id'];
            $_SESSION['business_id'] = $record['business_id'];
            $_SESSION['is_owner'] = true;
            $_SESSION['role_slug'] = $record['role_slug'];
            $_SESSION['user_name'] = $record['user_name'];
            $_SESSION['user_email'] = $record['email'];
            $_SESSION['business_name'] = $record['business_name'];
            $_SESSION['login_time'] = time();

            TenantContext::set((int)$record['business_id'], [
                'name' => $record['business_name'],
                'user_id' => $record['user_id'],
                'role_slug' => $record['role_slug']
            ]);

            ActivityLog::log($record['business_id'], $record['user_id'], 'email_verified', 'user', $record['user_id'], 'Email verified successfully');

            Flash::success('Your email has been verified! Welcome to ' . APP_NAME . '.');
            header('Location: ' . url('onboarding.php'));
            exit;

        } catch (Throwable $t) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Verification activation error: ' . $t->getMessage());
            $state = 'invalid';
            $errorMessage = 'An error occurred during account activation.';
        }
    }
}

// 2. Process Staff Invitation Token
if ($inviteToken !== '') {
    $inviteHash = hash('sha256', $inviteToken);
    $invitation = Database::fetch(
        "SELECT si.*, b.name as business_name, r.slug as role_slug 
         FROM staff_invitations si
         JOIN businesses b ON si.business_id = b.id
         JOIN roles r ON si.role_id = r.id
         WHERE si.token_hash = ? AND si.status = 'pending'
         LIMIT 1",
        [$inviteHash]
    );

    if (!$invitation || strtotime($invitation['expires_at']) < time()) {
        $state = 'invalid';
        $errorMessage = 'This staff invitation link is invalid or has expired.';
    } else {
        // Redirect to accept invitation screen
        header('Location: ' . url('accept-invitation.php?token=' . urlencode($inviteToken) . '&email=' . urlencode($invitation['email'])));
        exit;
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
    max-width: 480px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 2.5rem 2rem;
    box-shadow: var(--shadow-lg, 0 10px 30px rgba(0, 0, 0, 0.06));
    text-align: center;
}

.verify-icon-box {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.25rem;
    margin: 0 auto 1.5rem auto;
}
.verify-icon-info {
    background: rgba(14, 165, 233, 0.12);
    color: var(--color-primary, #0ea5e9);
}
.verify-icon-error {
    background: rgba(239, 68, 68, 0.12);
    color: var(--color-danger, #ef4444);
}
.verify-icon-success {
    background: rgba(16, 185, 129, 0.12);
    color: var(--color-success, #10b981);
}
</style>

<div class="clean-verify-card">
    <?php if ($state === 'notice'): ?>
        <div class="verify-icon-box verify-icon-info">
            <i class="ti ti-mail-fast"></i>
        </div>
        <h2 class="mb-2">Verify Your Email</h2>
        <p class="text-muted text-sm mb-4">
            We have sent a verification link to<br>
            <strong class="text-primary"><?= e($email ?: 'your email address') ?></strong>.
        </p>
        <p class="text-xs text-muted mb-4">
            Click the link in the email to activate your account and start setting up your shop. If you don't see it in your inbox, please check your spam folder.
        </p>
        
        <div class="d-flex flex-column gap-2">
            <a href="<?= url('resend-verification.php?email=' . urlencode($email)) ?>" class="btn btn-primary w-100">
                <i class="ti ti-send mr-1"></i> Resend Verification Email
            </a>
            <a href="<?= url('login.php') ?>" class="btn btn-outline w-100">
                <i class="ti ti-arrow-left mr-1"></i> Back to Login
            </a>
        </div>

    <?php elseif ($state === 'already_verified'): ?>
        <div class="verify-icon-box verify-icon-success">
            <i class="ti ti-circle-check"></i>
        </div>
        <h2 class="mb-2">Already Verified</h2>
        <p class="text-muted text-sm mb-4">
            Your email address has already been verified. You can log in and access your shopkeeper dashboard.
        </p>
        <a href="<?= url('login.php') ?>" class="btn btn-primary w-100">
            <i class="ti ti-login mr-1"></i> Proceed to Login
        </a>

    <?php else: ?>
        <div class="verify-icon-box verify-icon-error">
            <i class="ti ti-alert-triangle"></i>
        </div>
        <h2 class="mb-2">Verification Failed</h2>
        <p class="text-danger text-sm mb-4">
            <?= e($errorMessage) ?>
        </p>
        <div class="d-flex flex-column gap-2">
            <a href="<?= url('resend-verification.php?email=' . urlencode($email)) ?>" class="btn btn-primary w-100">
                <i class="ti ti-refresh mr-1"></i> Request New Verification Link
            </a>
            <a href="<?= url('login.php') ?>" class="btn btn-outline w-100">
                <i class="ti ti-arrow-left mr-1"></i> Back to Login
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="clean-login-footer mt-4 text-center">
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &bull; Enterprise SaaS Edition
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
