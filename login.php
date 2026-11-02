<?php
/**
 * YBT Management Software — Clean Enterprise Authentication Portal
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

$pageTitle = 'Sign In — YBT Management';
$isPublicPage = true;
require YBT_ROOT . '/includes/header.php';
?>

<style>
/* Clean, Minimalist Login Layout */
body.public-layout {
    min-height: 100vh;
    margin: 0;
    padding: 0;
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
    position: relative;
    box-sizing: border-box;
    padding: 1.5rem 1rem;
}

/* Floating Corner Theme Toggle */
.login-theme-bar {
    position: absolute;
    top: 1.25rem;
    right: 1.25rem;
    z-index: 10;
}

.btn-theme-minimal {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    color: var(--color-text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 1.1rem;
    transition: all 0.2s ease;
}

.btn-theme-minimal:hover {
    color: var(--color-primary);
    border-color: var(--color-primary);
    background: var(--color-surface-hover);
}

/* Main Card */
.clean-login-card {
    width: 100%;
    max-width: 420px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 2.25rem 2rem;
    box-shadow: var(--shadow-lg, 0 10px 30px rgba(0, 0, 0, 0.06));
    box-sizing: border-box;
    transition: border-color 0.2s ease;
}

/* Card Header */
.clean-login-header {
    text-align: center;
    margin-bottom: 1.75rem;
}

.clean-logo-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 50px;
    height: 50px;
    border-radius: 12px;
    background: linear-gradient(135deg, #0EA5E9 0%, #0284C7 100%);
    color: #ffffff;
    font-size: 1.6rem;
    margin-bottom: 0.85rem;
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
}

.clean-login-title {
    font-size: 1.45rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--color-text);
    margin: 0 0 0.35rem 0;
}

.clean-login-subtitle {
    font-size: 0.85rem;
    color: var(--color-text-muted);
    margin: 0;
}

/* Form Controls */
.clean-form-group {
    margin-bottom: 1.15rem;
}

.clean-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text);
    margin-bottom: 0.4rem;
}

.clean-input-box {
    position: relative;
    display: flex;
    align-items: center;
}

.clean-input-box i.input-icon {
    position: absolute;
    left: 0.85rem;
    color: var(--color-text-muted);
    font-size: 1.1rem;
    pointer-events: none;
}

.clean-input {
    width: 100%;
    height: 42px;
    padding: 0 2.5rem 0 2.45rem;
    border: 1px solid var(--color-border);
    border-radius: 8px;
    background: var(--color-bg);
    color: var(--color-text);
    font-family: var(--font-family);
    font-size: 0.875rem;
    transition: all 0.2s ease;
    box-sizing: border-box;
}

.clean-input:focus {
    outline: none;
    background: var(--color-surface);
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
}

.clean-pwd-toggle {
    position: absolute;
    right: 0.65rem;
    background: none;
    border: none;
    color: var(--color-text-muted);
    font-size: 1.15rem;
    padding: 0.25rem;
    cursor: pointer;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.15s ease;
}

.clean-pwd-toggle:hover {
    color: var(--color-text);
}

.clean-form-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.25rem;
    font-size: 0.8125rem;
}

.clean-remember {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: var(--color-text-secondary);
    cursor: pointer;
    user-select: none;
}

.clean-remember input[type="checkbox"] {
    width: 15px;
    height: 15px;
    accent-color: var(--color-primary);
    cursor: pointer;
}

.clean-btn-submit {
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
    box-shadow: 0 2px 6px rgba(14, 165, 233, 0.25);
}

.clean-btn-submit:hover {
    background: var(--color-primary-hover);
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(14, 165, 233, 0.35);
}

.clean-btn-submit:active {
    transform: translateY(0);
}

/* Alert */
.clean-alert {
    padding: 0.75rem 0.9rem;
    border-radius: 8px;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    font-size: 0.8125rem;
    background: rgba(220, 38, 38, 0.08);
    border: 1px solid rgba(220, 38, 38, 0.2);
    color: #DC2626;
}

[data-theme="dark"] .clean-alert {
    color: #F87171;
    background: rgba(248, 113, 113, 0.1);
    border-color: rgba(248, 113, 113, 0.25);
}

/* Minimal Footer */
.clean-login-footer {
    margin-top: 1.5rem;
    font-size: 0.75rem;
    color: var(--color-text-muted);
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
            <i class="ti ti-chart-dots-3"></i>
        </div>
        <h1 class="clean-login-title">YBT Management</h1>
        <p class="clean-login-subtitle">Sign in to your account</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="clean-alert">
            <i class="ti ti-alert-circle" style="font-size: 1.1rem; margin-top: 0.1rem;"></i>
            <div style="flex: 1;">
                <div><?= e($error) ?></div>
                <?php if (!empty($pendingVerificationEmail)): ?>
                    <div style="margin-top: 0.6rem;">
                        <a href="<?= url('verify-email.php?email=' . urlencode($pendingVerificationEmail)) ?>" class="btn btn-sm btn-primary" style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.8rem; padding: 0.3rem 0.75rem;">
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
            <a href="<?= url('forgot-password.php') ?>" style="color: var(--color-primary); text-decoration: none; font-size: 0.8125rem;">Forgot password?</a>
        </div>

        <button type="submit" class="clean-btn-submit" id="btn-submit">
            <span>Sign In</span>
            <i class="ti ti-arrow-right"></i>
        </button>
    </form>

    <div style="text-align: center; margin-top: 1.5rem; font-size: 0.85rem; color: var(--color-text-muted);">
        Don't have an account? <a href="<?= url('register.php') ?>" style="color: var(--color-primary); text-decoration: none; font-weight: 600;">Register your business</a>
    </div>
</div>

<div class="clean-login-footer">
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &bull; Multi-Business MIS
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
