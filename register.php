<?php
/**
 * DJ CRM Software — Clean Enterprise Multi-Business Registration Portal
 * Supports Multi-Business Types (Mobile & electronics, Medical store & pharmacy, General store, Wholesale & retail, Other configurable),
 * Multi-Country with searchable country selector and strict phone validation,
 * and email OTP verification.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/mailer-service.php';
require_once __DIR__ . '/services/subscription-service.php';

if (Auth::check()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$defaults = require YBT_ROOT . '/config/defaults.php';
$supportedCurrencies = $defaults['supported_currencies'] ?? [
    'PKR' => ['symbol' => 'Rs.', 'name' => 'Pakistani Rupee'],
    'AED' => ['symbol' => 'AED', 'name' => 'UAE Dirham'],
    'SAR' => ['symbol' => 'SAR', 'name' => 'Saudi Riyal'],
    'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
    'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
    'EUR' => ['symbol' => '€', 'name' => 'Euro'],
    'QAR' => ['symbol' => 'QAR', 'name' => 'Qatari Riyal'],
    'OMR' => ['symbol' => 'OMR', 'name' => 'Omani Rial'],
    'KWD' => ['symbol' => 'KWD', 'name' => 'Kuwaiti Dinar'],
    'BHD' => ['symbol' => 'BHD', 'name' => 'Bahraini Dinar'],
    'CAD' => ['symbol' => 'CA$', 'name' => 'Canadian Dollar'],
    'AUD' => ['symbol' => 'AU$', 'name' => 'Australian Dollar'],
    'INR' => ['symbol' => '₹', 'name' => 'Indian Rupee'],
    'BDT' => ['symbol' => '৳', 'name' => 'Bangladeshi Taka']
];

$allCountries = PhoneValidator::getCountries();

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
        $countryKey = strtoupper(trim($_POST['country'] ?? 'PK'));
        $phone = trim($_POST['phone'] ?? '');
        $currency = strtoupper(trim($_POST['currency'] ?? 'PKR'));
        $terms = !empty($_POST['terms']);

        // Country name from key
        $countryName = $allCountries[$countryKey]['name'] ?? $countryKey;

        // International Phone Number Validation
        if (!empty($phone)) {
            $phoneResult = PhoneValidator::validate($countryKey, $phone);
            if (!$phoneResult['valid']) {
                $error = $phoneResult['error'];
            } else {
                $phone = $phoneResult['formatted'];
            }
        }

        if (empty($error)) {
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
                    $error = 'An account with this email address already exists. Please log in or reset your password.';
                } else {
                    $db = Database::getInstance();
                    $db->beginTransaction();

                    try {
                        $tenantUuid = generateUuidV4();

                        // Resolve Selected Subscription Plan (Default Trial)
                        $selectedPlanSlug = trim($_POST['plan'] ?? $_GET['plan'] ?? 'free');
                        $targetPlan = SubscriptionService::getPlanBySlug($selectedPlanSlug) ?? SubscriptionService::getPlanBySlug('free') ?? SubscriptionService::getPlanById(1);
                        $assignedSlug = $targetPlan['slug'] ?? 'free';
                        $assignedPlanId = (int)($targetPlan['id'] ?? 1);
                        $trialDays = (int)($targetPlan['trial_days'] ?? 30);
                        if ($trialDays <= 0) $trialDays = 30;
                        $subExpiresAt = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));

                        // 1. Create Business Tenant (Pending Verification)
                        $businessId = Database::insert('businesses', [
                            'name' => $businessName,
                            'legal_name' => $businessName,
                            'owner_name' => $name,
                            'type' => $businessType,
                            'industry' => $businessType,
                            'category' => $businessTypes[$businessType]['label'] ?? $businessType,
                            'email' => $email,
                            'phone' => !empty($phone) ? $phone : null,
                            'country' => $countryName,
                            'currency' => $currency,
                            'default_currency' => $currency,
                            'tenant_uuid' => $tenantUuid,
                            'status' => 'pending_verification',
                            'onboarding_step' => 1,
                            'is_demo' => 0,
                            'current_plan_slug' => $assignedSlug,
                            'subscription_status' => 'active',
                            'subscription_expires_at' => $subExpiresAt
                        ]);

                        // Initial Trial Subscription Record
                        Database::insert('business_subscriptions', [
                            'business_id' => $businessId,
                            'plan_id' => $assignedPlanId,
                            'billing_cycle' => 'monthly',
                            'status' => 'active',
                            'starts_at' => date('Y-m-d H:i:s'),
                            'expires_at' => $subExpiresAt,
                            'amount_paid' => 0.00,
                            'payment_method' => 'trial_auto',
                            'notes' => 'Automatic trial onboarding plan'
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
                            'is_active' => 0,
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

                        ActivityLog::log($businessId, $userId, 'registration_initiated', 'user', $userId, "Business registered: {$name} ({$businessName}) - Type: {$businessType}, Country: {$countryName}");

                        // Dispatch 6-digit OTP email
                        $mailSent = MailerService::sendVerificationOtp($email, $name, $otp);

                        if ($mailSent) {
                            Flash::success('Registration successful! Please enter the 6-digit verification code sent to your email.');
                        } else {
                            Flash::warning('Registration successful, but the verification email could not be delivered. Please check your SMTP settings or click "Resend Code".');
                        }
                        header('Location: ' . url('verify-email.php?email=' . urlencode($email) . '&notice=' . ($mailSent ? 'sent' : 'mail_failed')));
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
}

$pageTitle = 'Register Your Business — ' . APP_NAME;
$isPublicPage = true;
require YBT_ROOT . '/includes/header.php';
?>

<style>
body.public-layout {
    min-height: 100vh;
    margin: 0;
    padding: 2.5rem 1rem;
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
    box-sizing: border-box;
}

.clean-register-card {
    width: 100%;
    max-width: 640px;
    background: var(--color-surface, #1e293b);
    border: 1px solid var(--color-border, #334155);
    border-radius: 20px;
    padding: 2.5rem 2.25rem;
    box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.04);
    box-sizing: border-box;
}

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

.clean-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.15rem;
}
@media (max-width: 640px) {
    .clean-form-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
}

.clean-form-group {
    margin-bottom: 1.15rem;
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
    height: 44px;
    padding: 0 1rem 0 2.65rem;
    border: 1px solid var(--color-border, #334155);
    border-radius: 10px;
    background: var(--color-bg, #0f172a);
    color: var(--color-text, #f8fafc);
    font-family: inherit;
    font-size: 0.9rem;
    transition: all 0.2s ease;
    box-sizing: border-box;
}

.clean-input:focus {
    outline: none;
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25);
    background: var(--color-surface, #1e293b);
}

.dial-badge {
    background: var(--color-border, #334155);
    color: var(--color-text, #f8fafc);
    padding: 0 0.75rem;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-top-left-radius: 10px;
    border-bottom-left-radius: 10px;
    font-weight: 600;
    font-size: 0.875rem;
    border: 1px solid var(--color-border, #334155);
    border-right: none;
    white-space: nowrap;
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
}

.btn-signin-cta {
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

[data-theme="light"] .btn-signin-cta {
    color: #2563EB !important;
}

.btn-signin-cta:hover {
    background: #2563EB;
    color: #ffffff !important;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
    transform: translateY(-1px);
}

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

.clean-remember {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    color: var(--color-text-secondary, #cbd5e1);
    cursor: pointer;
    user-select: none;
    font-size: 0.85rem;
}

.clean-remember input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #2563EB;
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

.phone-field-hint {
    font-size: 0.75rem;
    color: var(--color-text-muted, #94a3b8);
    margin-top: 0.35rem;
}
</style>

<div class="clean-register-card">
    <div class="clean-login-header">
        <div class="clean-logo-badge">
            <img src="<?= url('assets/images/logo.svg') ?>" alt="DJ CRM Software" onerror="this.src='<?= url('assets/images/logo-icon.svg') ?>'">
        </div>
        <h1 class="clean-login-title">Register Your Business</h1>
        <p class="clean-login-subtitle">Create your DJ CRM workspace and commercial profile</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="clean-alert">
            <i class="ti ti-alert-circle" style="font-size: 1.2rem; margin-top: 0.05rem;"></i>
            <div><?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <?php Flash::render(); ?>

    <form action="<?= url('register.php') ?>" method="POST" id="register-form">
        <?= Csrf::field() ?>

        <?php
        $incomingPlan = trim($_POST['plan'] ?? $_GET['plan'] ?? 'free');
        $displayPlan = SubscriptionService::getPlanBySlug($incomingPlan) ?? SubscriptionService::getPlanBySlug('free') ?? ['name' => 'Free Starter Trial', 'max_users' => 3];
        ?>
        <input type="hidden" name="plan" value="<?= e($incomingPlan) ?>">
        <div class="mb-3 p-2 rounded d-flex justify-between align-center" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(148, 163, 184, 0.25);">
            <div class="d-flex align-center gap-2">
                <i class="ti ti-crown text-warning" style="font-size: 1.1rem;"></i>
                <span style="font-size: 0.8rem; color: #cbd5e1;">Selected Plan: <strong style="color: #38bdf8;"><?= e($displayPlan['name']) ?></strong> (<?= (int)($displayPlan['max_users'] ?? 3) ?> Team Seats)</span>
            </div>
            <a href="<?= url('pricing.php') ?>" style="font-size: 0.78rem; color: #38bdf8; text-decoration: none; font-weight: 600;">Compare Plans &rarr;</a>
        </div>

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
                    <i class="ti ti-category input-icon"></i>
                    <select id="business_type" name="business_type" class="clean-input" required>
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
                    <i class="ti ti-world input-icon"></i>
                    <select id="country" name="country" class="clean-input" required onchange="handleCountryChange(this.value)">
                        <?php
                        $selectedCountry = strtoupper($_POST['country'] ?? 'PK');
                        foreach ($allCountries as $cKey => $cData):
                        ?>
                        <option value="<?= e($cKey) ?>" <?= ($selectedCountry === $cKey) ? 'selected' : '' ?> 
                                data-code="<?= e($cData['code']) ?>" 
                                data-placeholder="<?= e($cData['placeholder']) ?>" 
                                data-hint="<?= e($cData['hint']) ?>" 
                                data-maxlen="<?= (int)$cData['max_len'] ?>">
                            <?= e($cData['flag']) ?> <?= e($cData['name']) ?> (<?= e($cData['code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="clean-form-group">
                <label for="phone" class="clean-label">Mobile Phone Number</label>
                <div class="clean-input-box" style="display: flex;">
                    <span class="dial-badge" id="dial-badge">+92</span>
                    <input type="tel" id="phone" name="phone" class="clean-input" 
                           style="border-top-left-radius: 0; border-bottom-left-radius: 0; padding-left: 0.85rem;" 
                           placeholder="03001234567" maxlength="11" 
                           value="<?= e($_POST['phone'] ?? '') ?>" autocomplete="tel">
                </div>
                <div class="phone-field-hint" id="phone-hint">
                    Exclusively 11 digits starting with 03 (e.g. 03001234567)
                </div>
            </div>
        </div>

        <div class="clean-form-group">
            <label for="currency" class="clean-label">Default Currency *</label>
            <div class="clean-input-box">
                <i class="ti ti-cash input-icon"></i>
                <select id="currency" name="currency" class="clean-input" required>
                    <?php
                    $selectedCurr = strtoupper($_POST['currency'] ?? 'PKR');
                    foreach ($supportedCurrencies as $currCode => $currData):
                    ?>
                    <option value="<?= e($currCode) ?>" <?= ($selectedCurr === $currCode) ? 'selected' : '' ?>>
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
                <span>I agree to the <a href="#" style="color: #38BDF8;">Terms of Service</a> & <a href="#" style="color: #38BDF8;">Privacy Policy</a></span>
            </label>
        </div>

        <button type="submit" class="btn-solid-primary" id="btn-submit">
            <span>Create Business Account</span>
            <i class="ti ti-arrow-right"></i>
        </button>
    </form>

    <div class="divider-with-text">
        <span>Already have an account?</span>
    </div>

    <a href="<?= url('login.php') ?>" class="btn-signin-cta" id="btn-login-cta">
        <i class="ti ti-login"></i>
        <span>Sign In to Existing Account</span>
    </a>
</div>

<script>
var countryMetadata = <?= PhoneValidator::getCountriesJson() ?>;

function handleCountryChange(countryKey) {
    var data = countryMetadata[countryKey] || countryMetadata['OTHER'];
    var dialBadge = document.getElementById('dial-badge');
    var phoneInput = document.getElementById('phone');
    var phoneHint = document.getElementById('phone-hint');
    var currencySelect = document.getElementById('currency');

    if (dialBadge && data) {
        dialBadge.textContent = data.code || '+';
    }

    if (phoneInput && data) {
        phoneInput.placeholder = data.placeholder || 'Phone number';
        phoneInput.maxLength = data.max_len || 15;
    }

    if (phoneHint && data) {
        phoneHint.textContent = data.hint || '';
    }

    // Auto-update currency suggestion
    if (currencySelect) {
        var currencyMap = {
            'PK': 'PKR',
            'AE': 'AED',
            'SA': 'SAR',
            'QA': 'QAR',
            'OM': 'OMR',
            'KW': 'KWD',
            'BH': 'BHD',
            'GB': 'GBP',
            'US': 'USD',
            'CA': 'CAD',
            'IN': 'INR',
            'BD': 'BDT',
            'AU': 'AUD',
            'DE': 'EUR',
            'FR': 'EUR'
        };
        var suggestedCurr = currencyMap[countryKey];
        if (suggestedCurr) {
            for (var i = 0; i < currencySelect.options.length; i++) {
                if (currencySelect.options[i].value === suggestedCurr) {
                    currencySelect.selectedIndex = i;
                    break;
                }
            }
        }
    }
}

// Client-side phone restrictions
document.addEventListener('DOMContentLoaded', function() {
    var countrySelect = document.getElementById('country');
    var phoneInput = document.getElementById('phone');

    if (countrySelect) {
        handleCountryChange(countrySelect.value);
    }

    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            var country = countrySelect ? countrySelect.value : 'PK';
            if (country === 'PK') {
                // Strictly allow only digits and max 11 chars
                var clean = this.value.replace(/[^0-9]/g, '');
                if (clean.length > 11) {
                    clean = clean.substring(0, 11);
                }
                this.value = clean;
            }
        });
    }

    var form = document.getElementById('register-form');
    var submitBtn = document.getElementById('btn-submit');
    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.75';
            submitBtn.innerHTML = '<i class="ti ti-loader rotate mr-1"></i> Creating Account...';
        });
    }
});
</script>

<?php require YBT_ROOT . '/includes/footer.php'; ?>