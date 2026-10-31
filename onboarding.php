<?php
/**
 * YBT Management Software — Shop Setup Wizard (Onboarding)
 * Guides newly registered shopkeepers through configuring their shop details.
 */

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();

$user = Database::fetch("SELECT * FROM users WHERE id = ?", [Auth::id()]);
$businessId = TenantContext::id();
$business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);

// If user already completed onboarding, redirect to dashboard
if ($user && $user['onboarding_completed'] == 1 && $business && $business['onboarding_step'] >= 3) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error = '';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1 || $step > 3) $step = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? 'save_all';

        $legalName = trim($_POST['legal_name'] ?? $business['legal_name'] ?? '');
        $type = trim($_POST['type'] ?? $business['type'] ?? 'Mobile Shop');
        $phone = trim($_POST['phone'] ?? $business['phone'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? $business['whatsapp'] ?? '');
        $address = trim($_POST['address_line1'] ?? $business['address_line1'] ?? '');
        $city = trim($_POST['city'] ?? $business['city'] ?? '');
        $state = trim($_POST['state'] ?? $business['state'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? $business['postal_code'] ?? '');
        $country = trim($_POST['country'] ?? $business['country'] ?? 'India');
        $currency = trim($_POST['default_currency'] ?? $business['default_currency'] ?? 'INR');
        $invoicePrefix = trim($_POST['invoice_prefix'] ?? $business['invoice_prefix'] ?? 'INV-');
        $gstin = trim($_POST['gstin'] ?? $business['gstin'] ?? '');

        if (empty($legalName) || empty($phone)) {
            $error = 'Please fill in shop name and phone number.';
        } else {
            $db = Database::getInstance();
            $db->beginTransaction();

            try {
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
                        invoice_prefix = ?,
                        gstin = ?,
                        gst_registered = ?,
                        onboarding_step = 3,
                        status = 'active',
                        updated_at = NOW()
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
                        $invoicePrefix,
                        $gstin,
                        !empty($gstin) ? 1 : 0,
                        $businessId
                    ]
                );

                // Mark user onboarding completed
                Database::query(
                    "UPDATE users SET onboarding_completed = 1 WHERE id = ?",
                    [Auth::id()]
                );

                $db->commit();

                // Update session
                $_SESSION['business_name'] = $legalName;

                ActivityLog::log($businessId, Auth::id(), 'onboarding_completed', 'business', $businessId, 'Shop setup wizard completed');

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

$pageTitle = 'Setup Your Shop — ' . APP_NAME;
require YBT_ROOT . '/includes/header.php';
?>

<div class="content-wrapper" style="max-width: 860px; margin: 2rem auto; padding: 0 1rem;">
    <!-- Wizard Header -->
    <div class="text-center mb-4">
        <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-primary-subtle text-primary mb-2" style="width: 56px; height: 56px; font-size: 1.75rem;">
            <i class="ti ti-building-store"></i>
        </div>
        <h2 class="fw-bold mb-1">Welcome to <?= e(APP_NAME) ?>!</h2>
        <p class="text-muted">Let's configure your shop details so you can start billing, managing inventory, and handling repairs.</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4">
            <i class="ti ti-alert-circle fs-5 me-2"></i>
            <div><?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <!-- Onboarding Card Form -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-4 p-md-5">
            <form method="POST" action="<?= url('onboarding.php') ?>">
                <?= Csrf::field() ?>

                <!-- Section 1: Shop Information -->
                <div class="mb-4">
                    <h5 class="fw-bold text-primary mb-3 d-flex align-items-center">
                        <i class="ti ti-id me-2"></i> Shop Information
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Shop / Business Name <span class="text-danger">*</span></label>
                            <input type="text" name="legal_name" class="form-control" 
                                   value="<?= e($business['legal_name'] ?? $business['name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Shop Category / Type</label>
                            <select name="type" class="form-select">
                                <?php
                                $types = [
                                    'Mobile Shop',
                                    'Mobile Repair Shop',
                                    'Mobile Accessories Shop',
                                    'Electronics Shop',
                                    'General Retail Shop',
                                    'Computer & Laptop Repair',
                                    'Other'
                                ];
                                $curType = $business['type'] ?? 'Mobile Shop';
                                foreach ($types as $t):
                                ?>
                                    <option value="<?= e($t) ?>" <?= $curType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Primary Phone Number <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" 
                                   value="<?= e($business['phone'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">WhatsApp Number (For receipts)</label>
                            <input type="text" name="whatsapp" class="form-control" 
                                   placeholder="e.g. +91 9876543210" value="<?= e($business['whatsapp'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Section 2: Location & Address -->
                <div class="mb-4">
                    <h5 class="fw-bold text-primary mb-3 d-flex align-items-center">
                        <i class="ti ti-map-pin me-2"></i> Shop Address & Location
                    </h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Shop Address / Street</label>
                            <input type="text" name="address_line1" class="form-control" 
                                   placeholder="Shop No., Market, Street Name" value="<?= e($business['address_line1'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">City</label>
                            <input type="text" name="city" class="form-control" 
                                   value="<?= e($business['city'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">State / Province</label>
                            <input type="text" name="state" class="form-control" 
                                   value="<?= e($business['state'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Postal / PIN Code</label>
                            <input type="text" name="postal_code" class="form-control" 
                                   value="<?= e($business['postal_code'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Country</label>
                            <input type="text" name="country" class="form-control" 
                                   value="<?= e($business['country'] ?? 'India') ?>">
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Section 3: Billing Preferences -->
                <div class="mb-4">
                    <h5 class="fw-bold text-primary mb-3 d-flex align-items-center">
                        <i class="ti ti-receipt me-2"></i> Billing & Currency Preferences
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Currency Code</label>
                            <select name="default_currency" class="form-select">
                                <?php
                                $currencies = [
                                    'INR' => 'INR (₹ - Indian Rupee)',
                                    'USD' => 'USD ($ - US Dollar)',
                                    'PKR' => 'PKR (₨ - Pakistani Rupee)',
                                    'EUR' => 'EUR (€ - Euro)',
                                    'GBP' => 'GBP (£ - British Pound)',
                                    'AED' => 'AED (د.إ - UAE Dirham)',
                                    'SAR' => 'SAR (﷼ - Saudi Riyal)'
                                ];
                                $curCurrency = $business['default_currency'] ?? 'INR';
                                foreach ($currencies as $code => $label):
                                ?>
                                    <option value="<?= e($code) ?>" <?= $curCurrency === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Invoice Number Prefix</label>
                            <input type="text" name="invoice_prefix" class="form-control" 
                                   value="<?= e($business['invoice_prefix'] ?? 'INV-') ?>" placeholder="e.g. INV-">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">GSTIN / Tax ID (Optional)</label>
                            <input type="text" name="gstin" class="form-control" 
                                   value="<?= e($business['gstin'] ?? '') ?>" placeholder="e.g. 27AAAAA0000A1Z5">
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

<?php require YBT_ROOT . '/includes/footer.php'; ?>
