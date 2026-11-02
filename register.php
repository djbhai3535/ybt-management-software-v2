<?php
/**
 * YBT Management Software — Clean Enterprise Multi-Business Registration Portal
 * Supports Multi-Business Types (Mobile & electronics, Medical store & pharmacy, General store, Wholesale & retail, Other configurable),
 * Multi-Country (Pakistan, India, UAE, Saudi Arabia, International),
 * and email OTP verification.
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
$businessTypes = $defaults['business_types'] ?? [
    'mobile_electronics' => ['name' => 'Mobile and electronics shops', 'label' => 'Mobile & Electronics Store'],
    'medical_pharmacy' => ['name' => 'Medical stores and pharmacies', 'label' => 'Medical Store & Pharmacy'],
    'general_store' => ['name' => 'General stores', 'label' => 'General & Grocery Store'],
    'wholesale_retail' => ['name' => 'Wholesale and retail businesses', 'label' => 'Wholesale & Retail Business'],
    'other_business' => ['name' => 'Other configurable businesses', 'label' => 'Other Configurable Business'],
];

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
        $businessName = trim($_POST['business_name'] ?? $_POST['shop_name'] ?? '');
        $businessType = trim($_POST['business_type'] ?? $_POST['shop_type'] ?? 'mobile_electronics');
        $phone = trim($_POST['phone'] ?? '');
        $country = trim($_POST['country'] ?? 'Pakistan');
        $countryPreset = $supportedCountries[$country] ?? $supportedCountries['Other'] ?? [];
        $currency = strtoupper(trim($_POST['currency'] ?? $countryPreset['currency'] ?? 'PKR'));
        $terms = !empty($_POST['terms']);

        if (empty($name) || empty($email) || empty($password) || empty($businessName) || empty($businessType)) {
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

                    // 1. Create Business Tenant (Pending Verification)
                    $businessId = Database::insert('businesses', [
                        'name' => $businessName,
                        'legal_name' => $businessName,
                        'owner_name' => $name,
                        'type' => $businessType,
                        'industry' => $businessType,
                        'email' => $email,
                        'phone' => !empty($phone) ? $phone : null,
                        'country' => $country,
                        'default_currency' => $currency,
                        'tenant_uuid' => $tenantUuid,
                        'status' => 'pending_verification',
                        'onboarding_step' => 1,
                        'is_demo' => 0
                    ]);

                    // 2. Create Roles for Tenant
                    $ownerRoleId = Database::insert('roles', [
                        'business_id' => $businessId,
                        'name' => 'Business Owner',
                        'slug' => 'owner',
                        'description' => 'Full administrative access and financial control',
                        'is_system' => 1
                    ]);

                    $managerRoleId = Database::insert('roles', [
                        'business_id' => $businessId,
                        'name' => 'Business Manager',
                        'slug' => 'manager',
                        'description' => 'Operational management and daily billing',
                        'is_system' => 1
                    ]);

                    $staffRoleId = Database::insert('roles', [
                        'business_id' => $businessId,
                        'name' => 'Business Staff',
                        'slug' => 'staff',
                        'description' => 'Point of sale, customer queries, and sales',
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

                    // 3. Create User Account (Owner) — Inactive & Pending Verification
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $userId = Database::insert('users', [
                        'business_id' => $businessId,
                        'role_id' => $ownerRoleId,
                        'name' => $name,
                        'email' => $email,
                        'phone' => !empty($phone) ? $phone : null,
                        'password' => $passwordHash,
                        'is_owner' => 1,
                        'is_super_admin' => 0,
                        'is_active' => 0, // Unverified until OTP confirmation
                        'email_verified_at' => null,
                        'verification_status' => 'pending',
                        'onboarding_completed' => 0
                    ]);

                    // 4. Generate 6-digit numeric OTP and 15-minute expiration
                    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $tokenHash = hash('sha256', $otp);
                    $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 minutes

                    // Clear any previous pending verifications for this email
                    Database::query("DELETE FROM email_verifications WHERE email = ? AND used_at IS NULL", [$email]);

                    Database::insert('email_verifications', [
                        'user_id' => $userId,
                        'business_id' => $businessId,
                        'email' => $email,
                        'token_hash' => $tokenHash,
                        'token_type' => 'register',
                        'expires_at' => $expiresAt
                    ]);

                    $db->commit();

                    ActivityLog::log($businessId, $userId, 'registration_initiated', 'user', $userId, "Business registered: {$name} ({$businessName}) - Type: {$businessType}, Country: {$country}");

                    // Dispatch 6-digit OTP email
                    MailerService::sendVerificationOtp($email, $name, $otp);

                    Flash::success('Registration successful! Please enter the 6-digit verification code sent to your email.');
                    header('Location: ' . url('verify-email.php?email=' . urlencode($email) . '&notice=sent'));
                    exit;

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

$pageTitle = 'Register Your Business — YBT Management';
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
    max-width: 620px;
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
        <h1 class="clean-login-title">Register Your Business</h1>
        <p class="clean-login-subtitle">Create your business account and tenant profile</p>
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
                <label for="email" class="clean-label">Email Address *</label>
                <div class="clean-input-box">
                    <i class="ti ti-mail input-icon"></i>
                    <input type="email" id="email" name="email" class="clean-input" 
                           placeholder="owner@example.com" required 
                           value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
                </div>
            </div>
        </div>

        <div class="clean-form-row">
            <div class="clean-form-group">
                <label for="business_name" class="clean-label">Business Name *</label>
                <div class="clean-input-box">
                    <i class="ti ti-building-store input-icon"></i>
                    <input type="text" id="business_name" name="business_name" class="clean-input" 
                           placeholder="e.g. Apex Traders & Distribution" required 
                           value="<?= e($_POST['business_name'] ?? $_POST['shop_name'] ?? '') ?>">
                </div>
            </div>

            <div class="clean-form-group">
                <label for="business_type" class="clean-label">Business Type *</label>
                <div class="clean-input-box">
                    <select id="business_type" name="business_type" class="clean-input" style="padding-left: 1rem;" required>
                        <?php
                        $selectedType = $_POST['business_type'] ?? $_POST['shop_type'] ?? 'mobile_electronics';
                        foreach ($businessTypes as $bKey => $bData):
                            $bLabel = $bData['label'] ?? $bData['name'] ?? $bKey;
                        ?>
                        <option value="<?= e($bKey) ?>" <?= ($selectedType === $bKey) ? 'selected' : '' ?>>
                            <?= e($bLabel) ?>
                        </option>
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
                <label for="phone" class="clean-label">Phone Number <span class="text-muted">(Optional)</span></label>
                <div class="clean-input-box">
                    <i class="ti ti-phone input-icon"></i>
                    <input type="tel" id="phone" name="phone" class="clean-input" 
                           placeholder="+92 300 1234567" 
                           value="<?= e($_POST['phone'] ?? '') ?>" autocomplete="tel">
                </div>
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
            <span>Create Business Account</span>
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
