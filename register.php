<?php
/**
 * YBT Management Software — Clean Enterprise Shopkeeper Registration Portal
 * Supports Multi-Country (Pakistan, India, UAE, Saudi Arabia, International)
 * and automatic tenant creation with Owner role and offline desktop fallback.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/mailer-service.php';

if (Auth::check()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$defaults = require YBT_ROOT . '/config/defaults.php';
$supportedCountries = $defaults['supported_countries'] ?? [];
$supportedCurrencies = $defaults['supported_currencies'] ?? [];

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
        $country = trim($_POST['country'] ?? 'Pakistan');
        $countryPreset = $supportedCountries[$country] ?? $supportedCountries['Other'] ?? [];
        $currency = strtoupper(trim($_POST['currency'] ?? $countryPreset['currency'] ?? 'PKR'));
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
            // Check for existing active or pending user account
            $existing = Database::fetch("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
            if ($existing) {
                // Prevent duplicate registrations
                $error = 'An account with this email address already exists. Please log in or reset your password.';
            } else {
                $db = Database::getInstance();
                $db->beginTransaction();

                try {
                    $tenantUuid = generateUuidV4();
                    $isDesktopOffline = (Database::getDriver() === 'sqlite');

                    // 1. Create Business Tenant
                    $businessId = Database::insert('businesses', [
                        'name' => $shopName,
                        'legal_name' => $shopName,
                        'owner_name' => $name,
                        'type' => $shopType,
                        'industry' => $shopType,
                        'email' => $email,
                        'phone' => $phone,
                        'country' => $country,
                        'default_currency' => $currency,
                        'tenant_uuid' => $tenantUuid,
                        'status' => $isDesktopOffline ? 'active' : 'pending_verification',
                        'onboarding_step' => 1,
                        'is_demo' => 0
                    ]);

                    // 2. Create Roles for Tenant
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

                    // Grant all 54 permissions to Owner Role
                    $allPermissions = Database::fetchAll("SELECT id FROM permissions");
                    foreach ($allPermissions as $perm) {
                        Database::insert('role_permissions', [
                            'role_id' => $ownerRoleId,
                            'permission_id' => $perm['id']
                        ]);
                    }

                    // Grant standard operational permissions to Manager Role
                    $mgrPermKeys = [
                        'dashboard.view', 'customers.view', 'customers.create', 'customers.edit',
                        'leads.view', 'leads.create', 'leads.edit', 'products.view', 'tasks.view',
                        'tasks.create', 'quotations.view', 'invoices.view', 'invoices.create', 'invoices.edit',
                        'payments.view', 'payments.create', 'expenses.view', 'expenses.create'
                    ];
                    $mgrPerms = Database::fetchAll("SELECT id FROM permissions WHERE `key` IN ('" . implode("','", $mgrPermKeys) . "')");
                    foreach ($mgrPerms as $mp) {
                        Database::insert('role_permissions', [
                            'role_id' => $managerRoleId,
                            'permission_id' => $mp['id']
                        ]);
                    }

                    // Grant POS & billing permissions to Staff Role
                    $staffPermKeys = [
                        'dashboard.view', 'customers.view', 'customers.create',
                        'products.view', 'invoices.view', 'invoices.create', 'payments.view', 'payments.create'
                    ];
                    $staffPerms = Database::fetchAll("SELECT id FROM permissions WHERE `key` IN ('" . implode("','", $staffPermKeys) . "')");
                    foreach ($staffPerms as $sp) {
                        Database::insert('role_permissions', [
                            'role_id' => $staffRoleId,
                            'permission_id' => $sp['id']
                        ]);
                    }

                    // 3. Create User Account (Owner)
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $userId = Database::insert('users', [
                        'business_id' => $businessId,
                        'role_id' => $ownerRoleId,
                        'name' => $name,
                        'email' => $email,
                        'phone' => $phone,
                        'password' => $passwordHash,
                        'is_owner' => 1,
                        'is_super_admin' => 0,
                        'is_active' => 1,
                        'email_verified_at' => $isDesktopOffline ? date('Y-m-d H:i:s') : null,
                        'verification_status' => $isDesktopOffline ? 'verified' : 'pending',
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

                    // 5. Desktop Offline Session Provisioning
                    if ($isDesktopOffline) {
                        $sessionTableExists = Database::fetchColumn("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='local_sessions'");
                        if ($sessionTableExists) {
                            Database::insert('local_sessions', [
                                'device_id' => 'desktop-local',
                                'business_id' => $businessId,
                                'tenant_uuid' => $tenantUuid,
                                'user_id' => $userId,
                                'user_email' => $email,
                                'user_name' => $name,
                                'role_slug' => 'owner',
                                'password_hash' => $passwordHash,
                                'encrypted_api_token' => 'offline_local_token_' . bin2hex(random_bytes(16)),
                                'status' => 'active',
                                'last_offline_login_at' => date('Y-m-d H:i:s'),
                                'expires_at' => date('Y-m-d H:i:s', time() + (30 * 86400))
                            ]);
                        }
                    }

                    $db->commit();

                    // Mask sensitive phone in activity log
                    ActivityLog::log($businessId, $userId, 'registration_initiated', 'user', $userId, "Shopkeeper registered: {$name} ({$shopName}) - Country: {$country}, Currency: {$currency}");

                    if ($isDesktopOffline) {
                        // In desktop offline mode, log the newly registered owner in immediately and direct to onboarding
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['business_id'] = $businessId;
                        $_SESSION['tenant_uuid'] = $tenantUuid;
                        $_SESSION['is_owner'] = true;
                        $_SESSION['role_slug'] = 'owner';
                        $_SESSION['user_name'] = $name;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['business_name'] = $shopName;
                        $_SESSION['default_currency'] = $currency;
                        $_SESSION['auth_mode'] = 'offline';
                        $_SESSION['onboarding_step'] = 1;
                        TenantContext::set($businessId, [
                            'name' => $shopName,
                            'default_currency' => $currency,
                            'country' => $country
                        ]);

                        Flash::success("Welcome to YBT Management Software! Let's set up your shop profile.");
                        header('Location: ' . url('onboarding.php'));
                        exit;
                    } else {
                        // Send verification email in cloud mode
                        MailerService::sendVerificationEmail($email, $name, $rawToken);
                        Flash::success('Registration successful! Please check your email to verify your account.');
                        header('Location: ' . url('verify-email.php?notice=sent&email=' . urlencode($email)));
                        exit;
                    }

                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('Registration error: ' . $e->getMessage());
                    $error = 'A system error occurred during registration: ' . $e->getMessage();
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
    max-width: 600px;
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
        <p class="clean-login-subtitle">Create your mobile shop tenant and business profile</p>
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
                           placeholder="e.g. Tariq Mehmood" required 
                           value="<?= e($_POST['name'] ?? '') ?>" autocomplete="name">
                </div>
            </div>

            <div class="clean-form-group">
                <label for="phone" class="clean-label">Phone Number *</label>
                <div class="clean-input-box">
                    <i class="ti ti-phone input-icon"></i>
                    <input type="tel" id="phone" name="phone" class="clean-input" 
                           placeholder="+92 300 1234567" required 
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
                            'Mobile Shop & Service Center',
                            'Mobile Phone Retail',
                            'Mobile Repair & Spare Parts',
                            'Electronics & Accessories',
                            'Wholesale & Distribution',
                            'General Retail'
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
                <label for="country" class="clean-label">Country *</label>
                <div class="clean-input-box">
                    <select id="country" name="country" class="clean-input" style="padding-left: 1rem;" onchange="updateCountryDefaults(this.value)">
                        <?php foreach ($supportedCountries as $cKey => $cData): ?>
                        <option value="<?= e($cKey) ?>" <?= (($_POST['country'] ?? 'Pakistan') === $cKey) ? 'selected' : '' ?> data-currency="<?= e($cData['currency']) ?>">
                            <?= e($cData['name']) ?> (<?= e($cData['currency']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="clean-form-group">
                <label for="currency" class="clean-label">Default Currency *</label>
                <div class="clean-input-box">
                    <select id="currency" name="currency" class="clean-input" style="padding-left: 1rem;">
                        <?php foreach ($supportedCurrencies as $currCode => $currData): ?>
                        <option value="<?= e($currCode) ?>" <?= (($_POST['currency'] ?? 'PKR') === $currCode) ? 'selected' : '' ?>>
                            <?= e($currCode) ?> — <?= e($currData['symbol']) ?> (<?= e($currData['name']) ?>)
                        </option>
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

<script>
function updateCountryDefaults(country) {
    const countrySelect = document.getElementById('country');
    const currencySelect = document.getElementById('currency');
    const selectedOption = countrySelect.options[countrySelect.selectedIndex];
    const defaultCurr = selectedOption.getAttribute('data-currency');
    if (defaultCurr) {
        currencySelect.value = defaultCurr;
    }
}
</script>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
