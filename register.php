<?php
/**
 * YBT Management Software — Clean Enterprise Shopkeeper Registration Portal
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/mailer-service.php';

if (Auth::check()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $shopName = trim($_POST['shop_name'] ?? '');
        $shopType = trim($_POST['shop_type'] ?? 'Mobile Shop');
        $phone = trim($_POST['phone'] ?? '');
        $country = trim($_POST['country'] ?? 'India');
        $terms = !empty($_POST['terms']);

        if (empty($name) || empty($email) || empty($password) || empty($shopName) || empty($phone)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Password and confirmation password do not match.';
        } elseif (!$terms) {
            $error = 'You must agree to the Terms of Service to create an account.';
        } else {
            // Check for existing user account
            $existing = Database::fetch("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
            if ($existing) {
                // Prevent user enumeration: Show safe generic message or notify
                $error = 'An account with this email address already exists or is pending verification.';
            } else {
                $db = Database::getInstance();
                $db->beginTransaction();

                try {
                    $tenantUuid = generateUuidV4();

                    // 1. Create Business
                    $businessId = Database::insert('businesses', [
                        'name' => $shopName,
                        'legal_name' => $shopName,
                        'owner_name' => $name,
                        'type' => $shopType,
                        'industry' => $shopType,
                        'email' => $email,
                        'phone' => $phone,
                        'country' => $country,
                        'tenant_uuid' => $tenantUuid,
                        'status' => 'pending_verification',
                        'onboarding_step' => 1,
                        'is_demo' => 0
                    ]);

                    // 2. Create Roles for Business
                    $ownerRoleId = Database::insert('roles', [
                        'business_id' => $businessId,
                        'name' => 'Shop Owner',
                        'slug' => 'owner',
                        'description' => 'Full administrative access and financial control',
                        'is_system' => 1
                    ]);

                    $managerRoleId = Database::insert('roles', [
                        'business_id' => $businessId,
                        'name' => 'Shop Manager',
                        'slug' => 'manager',
                        'description' => 'Operational management and daily billing',
                        'is_system' => 1
                    ]);

                    $staffRoleId = Database::insert('roles', [
                        'business_id' => $businessId,
                        'name' => 'Shop Staff',
                        'slug' => 'staff',
                        'description' => 'Point of sale, customer queries, and repair intake',
                        'is_system' => 1
                    ]);

                    // Grant all permissions to Owner Role
                    $allPermissions = Database::fetchAll("SELECT id FROM permissions");
                    foreach ($allPermissions as $perm) {
                        Database::insert('role_permissions', [
                            'role_id' => $ownerRoleId,
                            'permission_id' => $perm['id']
                        ]);
                    }

                    // 3. Create User Account
                    $userId = Database::insert('users', [
                        'business_id' => $businessId,
                        'role_id' => $ownerRoleId,
                        'name' => $name,
                        'email' => $email,
                        'phone' => $phone,
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'is_owner' => 1,
                        'is_active' => 1,
                        'verification_status' => 'pending',
                        'onboarding_completed' => 0
                    ]);

                    // 4. Create Verification Token
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $expiresAt = date('Y-m-d H:i:s', time() + (24 * 3600)); // 24 hours

                    Database::insert('email_verifications', [
                        'user_id' => $userId,
                        'business_id' => $businessId,
                        'email' => $email,
                        'token_hash' => $tokenHash,
                        'token_type' => 'register',
                        'expires_at' => $expiresAt
                    ]);

                    $db->commit();

                    // 5. Send Verification Email
                    MailerService::sendVerificationEmail($email, $name, $rawToken);

                    ActivityLog::log($businessId, $userId, 'registration_initiated', 'user', $userId, "Shopkeeper registered: {$name} ({$shopName})");

                    Flash::success('Registration successful! Please check your email to verify your account.');
                    header('Location: ' . url('verify-email.php?notice=sent&email=' . urlencode($email)));
                    exit;

                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('Registration error: ' . $e->getMessage());
                    $error = 'A system error occurred during registration. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Register Your Shop — YBT Management';
$isPublicPage = true;
require YBT_ROOT . '/includes/header.php';
?>

<style>
body.public-layout {
    min-height: 100vh;
    margin: 0;
    padding: 2rem 1rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    background-color: var(--color-bg);
    background-image: 
        radial-gradient(circle at 50% 0%, rgba(14, 165, 233, 0.08) 0%, transparent 60%),
        radial-gradient(circle at 50% 100%, rgba(99, 102, 241, 0.05) 0%, transparent 60%);
    font-family: var(--font-family);
    color: var(--color-text);
    box-sizing: border-box;
}

.clean-register-card {
    width: 100%;
    max-width: 580px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 2.25rem 2rem;
    box-shadow: var(--shadow-lg, 0 10px 30px rgba(0, 0, 0, 0.06));
    box-sizing: border-box;
}

.clean-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
@media (max-width: 640px) {
    .clean-form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="clean-register-card">
    <div class="clean-login-header">
        <div class="clean-logo-badge">
            <i class="ti ti-building-store"></i>
        </div>
        <h1 class="clean-login-title">Register Your Shop</h1>
        <p class="clean-login-subtitle">Create your commercial account & shop profile</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="clean-alert">
            <i class="ti ti-alert-circle"></i>
            <div><?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <?php Flash::render(); ?>

    <form action="<?= url('register.php') ?>" method="POST" id="register-form">
        <?= Csrf::field() ?>

        <div class="clean-form-row">
            <div class="clean-form-group">
                <label for="name" class="clean-label">Full Name *</label>
                <div class="clean-input-box">
                    <i class="ti ti-user input-icon"></i>
                    <input type="text" id="name" name="name" class="clean-input" 
                           placeholder="e.g. Ramesh Sharma" required 
                           value="<?= e($_POST['name'] ?? '') ?>" autocomplete="name">
                </div>
            </div>

            <div class="clean-form-group">
                <label for="phone" class="clean-label">Phone Number *</label>
                <div class="clean-input-box">
                    <i class="ti ti-phone input-icon"></i>
                    <input type="tel" id="phone" name="phone" class="clean-input" 
                           placeholder="+91 98765 43210" required 
                           value="<?= e($_POST['phone'] ?? '') ?>" autocomplete="tel">
                </div>
            </div>
        </div>

        <div class="clean-form-group">
            <label for="email" class="clean-label">Email Address *</label>
            <div class="clean-input-box">
                <i class="ti ti-mail input-icon"></i>
                <input type="email" id="email" name="email" class="clean-input" 
                       placeholder="shopkeeper@gmail.com" required 
                       value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
            </div>
        </div>

        <div class="clean-form-row">
            <div class="clean-form-group">
                <label for="shop_name" class="clean-label">Shop / Business Name *</label>
                <div class="clean-input-box">
                    <i class="ti ti-building-store input-icon"></i>
                    <input type="text" id="shop_name" name="shop_name" class="clean-input" 
                           placeholder="Star Mobile & Repair Hub" required 
                           value="<?= e($_POST['shop_name'] ?? '') ?>">
                </div>
            </div>

            <div class="clean-form-group">
                <label for="shop_type" class="clean-label">Shop Type *</label>
                <div class="clean-input-box">
                    <select id="shop_type" name="shop_type" class="clean-input" style="padding-left: 1rem;">
                        <?php
                        $types = [
                            'Mobile Shop',
                            'Mobile Repair Shop',
                            'Mobile Accessories Shop',
                            'Electronics Shop',
                            'General Retail Shop',
                            'Other'
                        ];
                        foreach ($types as $t):
                        ?>
                        <option value="<?= e($t) ?>" <?= (($_POST['shop_type'] ?? '') === $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="clean-form-row">
            <div class="clean-form-group">
                <label for="password" class="clean-label">Password * (min. 8 chars)</label>
                <div class="clean-input-box">
                    <i class="ti ti-lock input-icon"></i>
                    <input type="password" id="password" name="password" class="clean-input" 
                           placeholder="••••••••" required minlength="8" autocomplete="new-password">
                </div>
            </div>

            <div class="clean-form-group">
                <label for="confirm_password" class="clean-label">Confirm Password *</label>
                <div class="clean-input-box">
                    <i class="ti ti-lock-check input-icon"></i>
                    <input type="password" id="confirm_password" name="confirm_password" class="clean-input" 
                           placeholder="••••••••" required minlength="8" autocomplete="new-password">
                </div>
            </div>
        </div>

        <div class="clean-form-group">
            <label for="country" class="clean-label">Country</label>
            <div class="clean-input-box">
                <i class="ti ti-world input-icon"></i>
                <input type="text" id="country" name="country" class="clean-input" 
                       value="<?= e($_POST['country'] ?? 'India') ?>">
            </div>
        </div>

        <div class="clean-form-meta mb-3">
            <label class="clean-remember">
                <input type="checkbox" name="terms" required <?= !empty($_POST['terms']) ? 'checked' : '' ?>>
                <span>I agree to the <a href="#" class="text-primary">Terms of Service</a> & <a href="#" class="text-primary">Privacy Policy</a></span>
            </label>
        </div>

        <button type="submit" class="clean-btn-submit" id="btn-submit">
            <span>Create Shopkeeper Account</span>
            <i class="ti ti-arrow-right"></i>
        </button>
    </form>

    <div class="clean-demo-divider">
        <span>Already have an account?</span>
    </div>

    <div class="text-center mt-3">
        <a href="<?= url('login.php') ?>" class="btn btn-outline w-100" style="padding: 0.65rem 1rem; border-radius: 8px;">
            <i class="ti ti-login mr-1"></i> Sign In to Existing Account
        </a>
    </div>
</div>

<div class="clean-login-footer mt-4">
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &bull; Enterprise SaaS Edition
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
