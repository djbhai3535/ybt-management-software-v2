<?php
/**
 * YBT Management Software — Forgot Password Request
 * Safe against user enumeration, enforces token hashing and 1-hour expiration.
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
            $genericSuccess = "If an account matches that email address, password reset instructions have been dispatched. Please check your inbox and spam folder.";

            if (!$user || !$user['is_active'] || $user['verification_status'] === 'suspended') {
                $message = $genericSuccess;
                $messageType = 'success';
            } else {
                // Cooldown check (60 seconds)
                $recent = Database::fetch(
                    "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds 
                     FROM password_resets 
                     WHERE user_id = ? AND used_at IS NULL 
                     ORDER BY id DESC LIMIT 1",
                    [$user['id']]
                );

                $canSend = true;
                if ($recent && $recent['elapsed_seconds'] !== null) {
                    $elapsed = (int)$recent['elapsed_seconds'];
                    if ($elapsed < 60) {
                        $remaining = 60 - $elapsed;
                        $message = "Please wait {$remaining} second(s) before requesting another reset email.";
                        $messageType = 'error';
                        $canSend = false;
                    }
                }

                if ($canSend) {
                    // Invalidate existing unused tokens
                    Database::query(
                        "DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL",
                        [$user['id']]
                    );

                    // Generate secure token
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                    Database::insert('password_resets', [
                        'user_id' => $user['id'],
                        'email' => $email,
                        'token_hash' => $tokenHash,
                        'expires_at' => $expiresAt
                    ]);

                    MailerService::sendPasswordResetEmail($email, $user['name'], $rawToken);

                    ActivityLog::log($user['business_id'], $user['id'], 'password_reset_requested', 'user', $user['id'], "Password reset requested for {$email}");

                    $message = $genericSuccess;
                    $messageType = 'success';
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
}

.alert-error {
    background: rgba(220, 38, 38, 0.08);
    border: 1px solid rgba(220, 38, 38, 0.2);
    color: #DC2626;
}

.alert-success {
    background: rgba(16, 185, 129, 0.08);
    border: 1px solid rgba(16, 185, 129, 0.2);
    color: #059669;
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
            <i class="ti ti-key"></i>
        </div>
        <h1 class="auth-title">Reset Password</h1>
        <p class="auth-subtitle">Enter your account email to receive a password recovery link</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert-box <?= $messageType === 'success' ? 'alert-success' : 'alert-error' ?>">
            <i class="ti <?= $messageType === 'success' ? 'ti-check' : 'ti-alert-circle' ?> mt-0.5"></i>
            <div><?= e($message) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('forgot-password.php') ?>">
        <?= Csrf::field() ?>

        <div class="form-group">
            <label for="email" class="form-label">Email Address</label>
            <div class="input-box">
                <i class="ti ti-mail"></i>
                <input type="email" id="email" name="email" class="form-input" 
                       placeholder="you@company.com" required autofocus 
                       value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
            </div>
        </div>

        <button type="submit" class="btn-submit">
            <span>Send Recovery Link</span>
            <i class="ti ti-arrow-right"></i>
        </button>
    </form>

    <div class="auth-nav">
        Remember your password? <a href="<?= url('login.php') ?>">Back to Login</a>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
