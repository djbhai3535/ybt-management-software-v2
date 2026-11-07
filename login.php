<?php
/**
 * DJ CRM Software — Clean Enterprise Authentication Portal
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error = '';
$pendingVerificationEmail = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both your email address and password.';
        } else {
            $result = Auth::attempt($email, $password);
            if ($result['success']) {
                Flash::success('Welcome back, ' . $result['user']['name'] . '!');
                if (!empty($result['user']['is_super_admin'])) {
                    header('Location: ' . url('admin/index.php'));
                    exit;
                }
                if (empty($result['user']['onboarding_completed'])) {
                    header('Location: ' . url('onboarding.php'));
                    exit;
                }
                header('Location: ' . url('dashboard.php'));
                exit;
            } else {
                $error = $result['error'];
                if (!empty($result['pending_verification'])) {
                    $pendingVerificationEmail = $result['email'] ?? $email;
                }
            }
        }
    }
}

$pageTitle = 'Sign In — ' . APP_NAME;
$isPublicPage = true;
require YBT_ROOT . '/includes/header.php';
?>

<style>
/* Clean, Modern Commercial Login Layout */
body.public-layout {
    min-height: 100vh;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    background-color: var(--color-bg, #0f172a);
    background-image: 
        radial-gradient(circle at 50% 0%, rgba(37, 99, 235, 0.12) 0%, transparent 60%),
        radial-gradient(circle at 50% 100%, rgba(14, 165, 233, 0.08) 0%, transparent 60%);
    font-family: var(--font-family, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif);
    color: var(--color-text, #f8fafc);
    position: relative;
    box-sizing: border-box;
    padding: 2rem 1rem;
}

/* Floating Corner Theme Toggle */
.login-theme-bar {
    position: absolute;
    top: 1.25rem;
    right: 1.25rem;
    z-index: 10;
}

.btn-theme-minimal {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    border: 1px solid var(--color-border, #334155);
    background: var(--color-surface, #1e293b);
    color: var(--color-text-muted, #94a3b8);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 1.15rem;
    transition: all 0.2s ease;
}

.btn-theme-minimal:hover {
    color: #2563EB;
    border-color: #2563EB;
    background: var(--color-surface-hover, #334155);
}

/* Main Card */
.clean-login-card {
    width: 100%;
    max-width: 440px;
    background: var(--color-surface, #1e293b);
    border: 1px solid var(--color-border, #334155);
    border-radius: 20px;
    padding: 2.5rem 2.25rem;
    box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.04);
    box-sizing: border-box;
    transition: border-color 0.2s ease;
}

/* Card Header */
.clean-login-header {
    text-align: center;
    margin-bottom: 2rem;
}

.clean-logo-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
}

.clean-logo-badge img {
    height: 52px;
    width: auto;
    object-fit: contain;
}

.clean-login-title {
    font-size: 1.6rem;
    font-weight: 700;
    letter-spacing: -0.025em;
    color: var(--color-text, #f8fafc);
    margin: 0 0 0.4rem 0;
}

.clean-login-subtitle {
    font-size: 0.9rem;
    color: var(--color-text-muted, #94a3b8);
    margin: 0;
}

/* Form Controls */
.clean-form-group {
    margin-bottom: 1.25rem;
}

.clean-label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--color-text, #e2e8f0);
    margin-bottom: 0.45rem;
}

.clean-input-box {
    position: relative;
    display: flex;
    align-items: center;
}

.clean-input-box i.input-icon {
    position: absolute;
    left: 0.95rem;
    color: var(--color-text-muted, #94a3b8);
    font-size: 1.15rem;
    pointer-events: none;
}

.clean-input {
    width: 100%;
    height: 46px;
    padding: 0 2.5rem 0 2.65rem;
    border: 1px solid var(--color-border, #334155);
    border-radius: 10px;
    background: var(--color-bg, #0f172a);
    color: var(--color-text, #f8fafc);
    font-family: inherit;
    font-size: 0.925rem;
    transition: all 0.2s ease;
    box-sizing: border-box;
}

.clean-input:focus {
    outline: none;
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25);
    background: var(--color-surface, #1e293b);
}

.clean-pwd-toggle {
    position: absolute;
    right: 0.75rem;
    background: none;
    border: none;
    color: var(--color-text-muted, #94a3b8);
    font-size: 1.2rem;
    padding: 0.35rem;
    cursor: pointer;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.15s ease;
}

.clean-pwd-toggle:hover {
    color: #2563EB;
}

.clean-form-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    font-size: 0.85rem;
}

.clean-remember {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    color: var(--color-text-secondary, #cbd5e1);
    cursor: pointer;
    user-select: none;
}

.clean-remember input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #2563EB;
    cursor: pointer;
}

/* Solid Colorful Bootstrap-Style Primary Button */
.btn-solid-primary {
    width: 100%;
    height: 46px;
    border-radius: 10px;
    border: 1px solid #1D4ED8;
    background: linear-gradient(180deg, #2563EB 0%, #1D4ED8 100%);
    color: #ffffff !important;
    font-family: inherit;
    font-size: 0.95rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.btn-solid-primary:hover {
    background: linear-gradient(180deg, #1D4ED8 0%, #1E40AF 100%);
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.55);
    transform: translateY(-1px);
}

.btn-solid-primary:active {
    transform: translateY(0);
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.4);
}

/* Styled Prominent Register CTA Button */
.btn-register-cta {
    width: 100%;
    height: 44px;
    border-radius: 10px;
    border: 1.5px solid #2563EB;
    background: transparent;
    color: #38BDF8 !important;
    font-family: inherit;
    font-size: 0.9rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
    box-sizing: border-box;
    margin-top: 0.85rem;
}

[data-theme="light"] .btn-register-cta {
    color: #2563EB !important;
}

.btn-register-cta:hover {
    background: #2563EB;
    color: #ffffff !important;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
    transform: translateY(-1px);
}

.btn-register-cta:active {
    transform: translateY(0);
}

.divider-with-text {
    display: flex;
    align-items: center;
    margin: 1.5rem 0 0.85rem 0;
    color: var(--color-text-muted, #94a3b8);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.divider-with-text::before,
.divider-with-text::after {
    content: "";
    flex: 1;
    border-bottom: 1px solid var(--color-border, #334155);
}

.divider-with-text span {
    padding: 0 0.75rem;
}

/* Alert */
.clean-alert {
    padding: 0.85rem 1rem;
    border-radius: 10px;
    margin-bottom: 1.35rem;
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    font-size: 0.85rem;
    background: rgba(220, 38, 38, 0.12);
    border: 1px solid rgba(220, 38, 38, 0.3);
    color: #F87171;
}

[data-theme="light"] .clean-alert {
    background: #FEF2F2;
    border-color: #FCA5A5;
    color: #DC2626;
}

/* Minimal Footer */
.clean-login-footer {
    margin-top: 1.75rem;
    font-size: 0.8rem;
    color: var(--color-text-muted, #94a3b8);
    text-align: center;
}
</style>

<!-- Theme Toggle in Corner -->
<div class="login-theme-bar">
    <button type="button" class="btn-theme-minimal" data-action="toggle-theme" title="Toggle theme" aria-label="Toggle theme">
        <i class="theme-toggle-icon ti ti-moon"></i>
    </button>
</div>

<!-- Clean Centered Login Card -->
<div class="clean-login-card">
    <div class="clean-login-header">
        <div class="clean-logo-badge">
            <img src="<?= url('assets/images/logo.svg') ?>" alt="DJ CRM Software" onerror="this.src='<?= url('assets/images/logo-icon.svg') ?>'">
        </div>
        <h1 class="clean-login-title">DJ CRM Software</h1>
        <p class="clean-login-subtitle">Sign in to your business workspace</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="clean-alert">
            <i class="ti ti-alert-circle" style="font-size: 1.2rem; margin-top: 0.05rem;"></i>
            <div style="flex: 1;">
                <div><?= e($error) ?></div>
                <?php if (!empty($pendingVerificationEmail)): ?>
                    <div style="margin-top: 0.6rem;">
                        <a href="<?= url('verify-email.php?email=' . urlencode($pendingVerificationEmail)) ?>" class="btn-solid-primary" style="height: 34px; font-size: 0.8rem; padding: 0 0.85rem; display: inline-flex; width: auto;">
                            <i class="ti ti-shield-check"></i> Enter 6-Digit Verification Code
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php Flash::render(); ?>

    <form action="<?= url('login.php') ?>" method="POST" id="login-form">
        <?= Csrf::field() ?>

        <div class="clean-form-group">
            <label for="email" class="clean-label">Email Address</label>
            <div class="clean-input-box">
                <i class="ti ti-mail input-icon"></i>
                <input type="email" id="email" name="email" class="clean-input" 
                       placeholder="name@company.com" required autofocus 
                       value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
            </div>
        </div>

        <div class="clean-form-group">
            <label for="password" class="clean-label">Password</label>
            <div class="clean-input-box">
                <i class="ti ti-lock input-icon"></i>
                <input type="password" id="password" name="password" class="clean-input" 
                       placeholder="••••••••" required autocomplete="current-password">
                <button type="button" class="clean-pwd-toggle" id="btn-toggle-pwd" aria-label="Toggle password">
                    <i class="ti ti-eye" id="pwd-icon"></i>
                </button>
            </div>
        </div>

        <div class="clean-form-meta">
            <label class="clean-remember">
                <input type="checkbox" name="remember" checked>
                <span>Remember me</span>
            </label>
            <a href="<?= url('forgot-password.php') ?>" style="color: #38BDF8; text-decoration: none; font-size: 0.85rem; font-weight: 500;">Forgot password?</a>
        </div>

        <button type="submit" class="btn-solid-primary" id="btn-submit">
            <span>Sign In</span>
            <i class="ti ti-arrow-right"></i>
        </button>
    </form>

    <div class="divider-with-text">
        <span>New to DJ CRM?</span>
    </div>

    <a href="<?= url('register.php') ?>" class="btn-register-cta" id="btn-register-cta">
        <i class="ti ti-building-store"></i>
        <span>Register New Business Account</span>
    </a>
</div>

<div class="clean-login-footer">
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &bull; Multi-Business CRM & MIS
</div>

<script>
// Password Visibility Toggle
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('btn-toggle-pwd');
    const pwdInput = document.getElementById('password');
    const pwdIcon = document.getElementById('pwd-icon');

    if (toggleBtn && pwdInput && pwdIcon) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const isPassword = pwdInput.type === 'password';
            pwdInput.type = isPassword ? 'text' : 'password';
            pwdIcon.className = isPassword ? 'ti ti-eye-off' : 'ti ti-eye';
        });
    }

    const form = document.getElementById('login-form');
    const submitBtn = document.getElementById('btn-submit');
    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.75';
            submitBtn.innerHTML = '<i class="ti ti-loader rotate mr-1"></i> Signing in...';
        });
    }
});
</script>

<?php require YBT_ROOT . '/includes/footer.php'; ?>