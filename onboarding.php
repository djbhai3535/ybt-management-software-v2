<?php
/**
 * YBT Management Software — Shop Setup Wizard (Onboarding)
 * Guides newly registered shopkeepers through configuring their shop details.
 * Supports Multi-Country (Pakistan, India, International) with localized tax,
 * address, banking, and digital wallet presets.
 */

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();

$user = Database::fetch("SELECT * FROM users WHERE id = ?", [Auth::id()]);
$businessId = TenantContext::id();
$business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);

// If user already completed onboarding and business is active, redirect to dashboard
if ($user && $user['onboarding_completed'] == 1 && $business && (int)($business['onboarding_step'] ?? 0) >= 3 && ($business['status'] ?? '') === 'active') {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$defaults = require YBT_ROOT . '/config/defaults.php';
$supportedCountries = $defaults['supported_countries'] ?? [];
$supportedCurrencies = $defaults['supported_currencies'] ?? [];

$curCountry = $business['country'] ?? 'Pakistan';
$countryPreset = $supportedCountries[$curCountry] ?? $supportedCountries['Pakistan'] ?? [];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $legalName = trim($_POST['legal_name'] ?? $business['legal_name'] ?? $business['name'] ?? '');
        $type = trim($_POST['type'] ?? $business['type'] ?? 'Mobile Shop & Service Center');
        $phone = trim($_POST['phone'] ?? $business['phone'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? $business['whatsapp'] ?? '');
        $address = trim($_POST['address_line1'] ?? $business['address_line1'] ?? '');
        $city = trim($_POST['city'] ?? $business['city'] ?? '');
        $state = trim($_POST['state'] ?? $business['state'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? $business['postal_code'] ?? '');
        $country = trim($_POST['country'] ?? $business['country'] ?? 'Pakistan');
        $currency = strtoupper(trim($_POST['default_currency'] ?? $business['default_currency'] ?? 'PKR'));
        $invoicePrefix = trim($_POST['invoice_prefix'] ?? $business['invoice_prefix'] ?? 'INV-');
        $invoiceNextNumber = max(1, (int)($_POST['invoice_next_number'] ?? $business['invoice_next_number'] ?? 1001));
        $taxNumber = trim($_POST['tax_number'] ?? $_POST['gstin'] ?? $business['tax_number'] ?? $business['gstin'] ?? '');
        $taxLabel = trim($_POST['tax_label'] ?? $countryPreset['tax_label'] ?? 'NTN / STRN');

        // Banking & Digital Wallets
        $bankName = trim($_POST['bank_name'] ?? $business['bank_name'] ?? '');
        $accountHolder = trim($_POST['account_holder'] ?? $business['account_holder'] ?? $legalName);
        $accountNumber = trim($_POST['account_number'] ?? $business['account_number'] ?? '');
        $ifscOrBranch = trim($_POST['ifsc_code'] ?? $business['ifsc_code'] ?? '');
        $walletId = trim($_POST['wallet_id'] ?? $_POST['upi_id'] ?? $business['wallet_id'] ?? $business['upi_id'] ?? '');
        $walletType = trim($_POST['wallet_type'] ?? $countryPreset['wallet_label'] ?? 'Raast / Digital Wallet');

        if (empty($legalName) || empty($phone)) {
            $error = 'Please fill in shop name and primary phone number.';
        } else {
            $db = Database::getInstance();
            $db->beginTransaction();

            try {
                $now = date('Y-m-d H:i:s');

                // Update business profile
                Database::query(
                    "UPDATE businesses SET 
                        name = ?,
                        legal_name = ?,
                        type = ?,
                        industry = ?,
                        phone = ?,
                        whatsapp = ?,
                        address_line1 = ?,
                        city = ?,
                        state = ?,
                        postal_code = ?,
                        country = ?,
                        default_currency = ?,
                        currency = ?,
                        invoice_prefix = ?,
                        invoice_next_number = ?,
                        tax_number = ?,
                        gstin = ?,
                        tax_label = ?,
                        gst_registered = ?,
                        bank_name = ?,
                        account_holder = ?,
                        account_number = ?,
                        ifsc_code = ?,
                        upi_id = ?,
                        wallet_id = ?,
                        wallet_type = ?,
                        onboarding_step = 3,
                        status = 'active',
                        updated_at = ?
                     WHERE id = ?",
                    [
                        $legalName,
                        $legalName,
                        $type,
                        $type,
                        $phone,
                        $whatsapp,
                        $address,
                        $city,
                        $state,
                        $postalCode,
                        $country,
                        $currency,
                        $currency,
                        $invoicePrefix,
                        $invoiceNextNumber,
                        $taxNumber,
                        $taxNumber,
                        $taxLabel,
                        !empty($taxNumber) ? 1 : 0,
                        $bankName,
                        $accountHolder,
                        $accountNumber,
                        $ifscOrBranch,
                        $walletId,
                        $walletId,
                        $walletType,
                        $now,
                        $businessId
                    ]
                );

                // Mark user onboarding completed
                Database::query(
                    "UPDATE users SET onboarding_completed = 1, updated_at = ? WHERE id = ?",
                    [$now, Auth::id()]
                );

                $db->commit();

                // Update session state
                $_SESSION['business_name'] = $legalName;
                $_SESSION['default_currency'] = $currency;
                $_SESSION['onboarding_step'] = 3;

                TenantContext::set($businessId, [
                    'name' => $legalName,
                    'default_currency' => $currency,
                    'country' => $country,
                    'is_owner' => Auth::isOwner(),
                    'role_slug' => $_SESSION['role_slug'] ?? 'owner'
                ]);

                // Log without exposing raw account numbers (masked for audit)
                $maskedAcc = !empty($accountNumber) 
                    ? (strlen($accountNumber) > 4 ? substr($accountNumber, 0, 2) . str_repeat('*', strlen($accountNumber) - 6) . substr($accountNumber, -4) : '****')
                    : 'none';

                ActivityLog::log($businessId, Auth::id(), 'onboarding_completed', 'business', $businessId, "Shop setup completed: {$legalName} (Country: {$country}, Currency: {$currency}, Bank: {$bankName}, Account: {$maskedAcc})");

                Flash::success('Shop setup completed successfully! Welcome to ' . APP_NAME . '.');
                header('Location: ' . url('dashboard.php'));
                exit;

            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Onboarding error: ' . $e->getMessage());
                $error = 'Failed to save shop preferences: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Setup Your Shop Profile — ' . APP_NAME;
require YBT_ROOT . '/includes/header.php';
?>

<div class="content-wrapper" style="max-width: 900px; margin: 2rem auto; padding: 0 1rem;">
    <!-- Wizard Header -->
    <div class="text-center mb-4">
        <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-primary-subtle text-primary mb-2" style="width: 56px; height: 56px; font-size: 1.75rem;">
            <i class="ti ti-building-store"></i>
        </div>
        <h2 class="fw-bold mb-1">Welcome to <?= e(APP_NAME) ?>!</h2>
        <p class="text-muted">Configure your shop identity, billing, and payment settings to begin Khata, sales billing, and inventory tracking.</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4">
            <i class="ti ti-alert-circle fs-5 me-2"></i>
            <div><?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <!-- Onboarding Card Form -->
    <div class="card shadow-sm border-0" style="border-radius: 14px;">
        <div class="card-body p-4 p-md-5">
            <form method="POST" action="<?= url('onboarding.php') ?>" id="onboarding-form">
                <?= Csrf::field() ?>

                <!-- Section 1: Shop Information -->
                <div class="mb-4">
                    <h5 class="fw-bold text-primary mb-3 d-flex align-items-center">
                        <i class="ti ti-id me-2"></i> 1. Shop Identity & Contact
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Shop / Business Legal Name <span class="text-danger">*</span></label>
                            <input type="text" name="legal_name" class="form-control" 
                                   value="<?= e($business['legal_name'] ?? $business['name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Shop Category / Type</label>
                            <select name="type" class="form-select">
                                <?php
                                $types = [
                                    'Mobile Shop & Service Center',
                                    'Mobile Phone Retail',
                                    'Mobile Repair & Spare Parts',
                                    'Electronics & Accessories',
                                    'Wholesale & Distribution',
                                    'Computer & Laptop Repair',
                                    'General Retail'
                                ];
                                $curType = $business['type'] ?? 'Mobile Shop & Service Center';
                                foreach ($types as $t):
                                ?>
                                    <option value="<?= e($t) ?>" <?= $curType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Primary Contact Phone <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" 
                                   placeholder="+92 300 1234567" value="<?= e($business['phone'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">WhatsApp Number (For receipts & invoices)</label>
                            <input type="text" name="whatsapp" class="form-control" 
                                   placeholder="+92 300 1234567" value="<?= e($business['whatsapp'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Section 2: Location & Address -->
                <div class="mb-4">
                    <h5 class="fw-bold text-primary mb-3 d-flex align-items-center">
                        <i class="ti ti-map-pin me-2"></i> 2. Shop Location & Address
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Country <span class="text-danger">*</span></label>
                            <select name="country" id="country" class="form-select" onchange="onCountryChange(this.value)">
                                <?php foreach ($supportedCountries as $cKey => $cData): ?>
                                    <option value="<?= e($cKey) ?>" <?= $curCountry === $cKey ? 'selected' : '' ?>
                                            data-currency="<?= e($cData['currency']) ?>"
                                            data-tax-label="<?= e($cData['tax_label']) ?>"
                                            data-tax-placeholder="<?= e($cData['tax_placeholder']) ?>"
                                            data-banking-label="<?= e($cData['banking_label']) ?>"
                                            data-wallet-label="<?= e($cData['wallet_label']) ?>">
                                        <?= e($cData['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">State / Province</label>
                            <input type="text" name="state" id="state" class="form-control" 
                                   placeholder="e.g. Punjab / Sindh / Maharashtra" value="<?= e($business['state'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">City</label>
                            <input type="text" name="city" class="form-control" 
                                   placeholder="e.g. Lahore / Karachi / Mumbai" value="<?= e($business['city'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Postal / PIN Code</label>
                            <input type="text" name="postal_code" class="form-control" 
                                   placeholder="e.g. 54000" value="<?= e($business['postal_code'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Shop Address / Street / Market</label>
                            <input type="text" name="address_line1" class="form-control" 
                                   placeholder="Shop No. 12, Hafeez Centre, Main Boulevard" value="<?= e($business['address_line1'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Section 3: Billing & Tax Settings -->
                <div class="mb-4">
                    <h5 class="fw-bold text-primary mb-3 d-flex align-items-center">
                        <i class="ti ti-receipt me-2"></i> 3. Billing & Tax Configuration
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Default Currency</label>
                            <select name="default_currency" id="currency" class="form-select">
                                <?php foreach ($supportedCurrencies as $cCode => $cItem): ?>
                                    <option value="<?= e($cCode) ?>" <?= ($business['default_currency'] ?? 'PKR') === $cCode ? 'selected' : '' ?>>
                                        <?= e($cCode) ?> (<?= e($cItem['symbol']) ?> - <?= e($cItem['name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Invoice Number Prefix</label>
                            <input type="text" name="invoice_prefix" class="form-control" 
                                   value="<?= e($business['invoice_prefix'] ?? 'INV-') ?>" placeholder="e.g. INV-">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Starting Invoice Number</label>
                            <input type="number" name="invoice_next_number" class="form-control" 
                                   value="<?= e((string)($business['invoice_next_number'] ?? 1001)) ?>" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" id="tax-label-text"><?= e($countryPreset['tax_label'] ?? 'NTN / STRN') ?> (Optional)</label>
                            <input type="hidden" name="tax_label" id="tax_label_input" value="<?= e($countryPreset['tax_label'] ?? 'NTN / STRN') ?>">
                            <input type="text" name="tax_number" id="tax_number" class="form-control" 
                                   value="<?= e($business['tax_number'] ?? $business['gstin'] ?? '') ?>" 
                                   placeholder="<?= e($countryPreset['tax_placeholder'] ?? 'e.g. 1234567-8') ?>">
                            <div class="form-text">Leave blank if non-filer or composition scheme.</div>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Section 4: Bank Details & Digital Payment Handles -->
                <div class="mb-4">
                    <h5 class="fw-bold text-primary mb-3 d-flex align-items-center">
                        <i class="ti ti-credit-card me-2"></i> 4. Banking & Digital Payments (Printed on Invoices / QR)
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" 
                                   placeholder="e.g. Meezan Bank / Bank Alfalah / HDFC" value="<?= e($business['bank_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Account Title / Holder Name</label>
                            <input type="text" name="account_holder" class="form-control" 
                                   placeholder="e.g. Tariq Mehmood" value="<?= e($business['account_holder'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" id="account-label">Bank Account Number / IBAN</label>
                            <input type="text" name="account_number" class="form-control" 
                                   placeholder="e.g. PK36MEZN0001234567890123" value="<?= e($business['account_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" id="branch-label">Branch Code / IFSC / Swift</label>
                            <input type="text" name="ifsc_code" class="form-control" 
                                   placeholder="e.g. 0102 / HDFC0001234" value="<?= e($business['ifsc_code'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" id="wallet-label-text"><?= e($countryPreset['wallet_label'] ?? 'Raast ID / Easypaisa / JazzCash') ?></label>
                            <input type="hidden" name="wallet_type" id="wallet_type_input" value="<?= e($countryPreset['wallet_label'] ?? 'Raast / Digital Wallet') ?>">
                            <input type="text" name="wallet_id" id="wallet_id" class="form-control" 
                                   placeholder="e.g. 03001234567 / shop@upi" value="<?= e($business['wallet_id'] ?? $business['upi_id'] ?? '') ?>">
                            <div class="form-text">Used to print payment handles and QR codes on customer invoices.</div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="d-flex justify-content-end mt-4 pt-2">
                    <button type="submit" class="btn btn-primary px-4 py-2 fs-6 fw-semibold d-inline-flex align-items-center">
                        <span>Save & Complete Setup</span>
                        <i class="ti ti-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function onCountryChange(country) {
    const sel = document.getElementById('country');
    const opt = sel.options[sel.selectedIndex];
    
    const curr = opt.getAttribute('data-currency');
    const taxLabel = opt.getAttribute('data-tax-label') || 'Tax ID / NTN / GSTIN';
    const taxPlaceholder = opt.getAttribute('data-tax-placeholder') || 'Tax ID';
    const walletLabel = opt.getAttribute('data-wallet-label') || 'Digital Wallet Handle';

    if (curr) {
        document.getElementById('currency').value = curr;
    }
    document.getElementById('tax-label-text').innerText = taxLabel + ' (Optional)';
    document.getElementById('tax_label_input').value = taxLabel;
    document.getElementById('tax_number').placeholder = taxPlaceholder;

    document.getElementById('wallet-label-text').innerText = walletLabel;
    document.getElementById('wallet_type_input').value = walletLabel;
}
</script>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
