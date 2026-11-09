<?php
/**
 * YBT Management Software — System & Business Settings
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/backup-service.php';
require_once __DIR__ . '/services/restore-service.php';
require_once __DIR__ . '/services/ai-provider-service.php';

Auth::requireLogin();
Permissions::require('settings.access');

$businessId = Auth::businessId();
$userId = Auth::id();
$business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);

// Handle Business Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_business') {
    Permissions::require('settings.edit');
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        Database::update('businesses', [
            'name' => trim($_POST['name'] ?? ''),
            'legal_name' => trim($_POST['legal_name'] ?? ''),
            'tagline' => trim($_POST['tagline'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'gstin' => strtoupper(trim($_POST['gstin'] ?? '')),
            'pan' => strtoupper(trim($_POST['pan'] ?? '')),
            'address_line1' => trim($_POST['address_line1'] ?? ''),
            'address_line2' => trim($_POST['address_line2'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'postal_code' => trim($_POST['postal_code'] ?? ''),
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'account_number' => trim($_POST['account_number'] ?? ''),
            'ifsc_code' => strtoupper(trim($_POST['ifsc_code'] ?? '')),
            'upi_id' => trim($_POST['upi_id'] ?? ''),
            'invoice_prefix' => trim($_POST['invoice_prefix'] ?? 'INV-'),
            'quotation_prefix' => trim($_POST['quotation_prefix'] ?? 'QT-'),
            'default_due_days' => max(1, (int)($_POST['default_due_days'] ?? 15)),
            'default_terms' => trim($_POST['default_terms'] ?? ''),
            'default_notes' => trim($_POST['default_notes'] ?? '')
        ], 'id = ?', [$businessId]);

        $_SESSION['business_name'] = trim($_POST['name'] ?? '');
        ActivityLog::log($businessId, $userId, 'settings_updated', 'business', $businessId, "Updated business profile & settings");
        Flash::success('Business settings saved successfully.');
        header('Location: ' . url('settings.php#business'));
        exit;
    }
}

// Handle AI Provider Configuration Save (Super Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_ai_provider') {
    if (!Auth::isSuperAdmin()) {
        Flash::error('Central AI Provider keys can only be configured by the Software Owner in the Super Admin Portal.');
        header('Location: ' . url('settings.php#section-subscription'));
        exit;
    }
    Permissions::require('settings.edit');
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        try {
            $provId = AIProviderService::save($businessId, $_POST);
            ActivityLog::log($businessId, $userId, 'ai_provider_configured', 'ai_provider', $provId, "Configured AI Provider: " . ($_POST['display_name'] ?? 'AI Provider'));
            Flash::success('AI Provider configuration saved successfully.');
        } catch (Exception $e) {
            Flash::error('Failed to save AI Provider: ' . $e->getMessage());
        }
        header('Location: ' . url('settings.php#section-ai-providers'));
        exit;
    }
}

// Handle AI Provider Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_ai_provider') {
    Permissions::require('settings.edit');
    if (Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $delId = (int)($_POST['provider_id'] ?? 0);
        AIProviderService::delete($delId, $businessId);
        ActivityLog::log($businessId, $userId, 'ai_provider_deleted', 'ai_provider', $delId, "Deleted AI Provider #{$delId}");
        Flash::success('AI Provider configuration removed.');
        header('Location: ' . url('settings.php#section-ai-providers'));
        exit;
    }
}

// Handle Set Default AI Provider
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_default_ai_provider') {
    Permissions::require('settings.edit');
    if (Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $defId = (int)($_POST['provider_id'] ?? 0);
        $cols = Database::getTableColumns('ai_provider_configs');
        if (isset($cols['is_default'])) {
            Database::query("UPDATE ai_provider_configs SET is_default = 0 WHERE business_id = ?", [$businessId]);
            Database::query("UPDATE ai_provider_configs SET is_default = 1, is_active = 1 WHERE id = ? AND business_id = ?", [$defId, $businessId]);
        } else {
            Database::query("UPDATE ai_provider_configs SET is_active = 0 WHERE business_id = ?", [$businessId]);
            Database::query("UPDATE ai_provider_configs SET is_active = 1 WHERE id = ? AND business_id = ?", [$defId, $businessId]);
        }
        Flash::success('Default active AI Provider updated.');
        header('Location: ' . url('settings.php#section-ai-providers'));
        exit;
    }
}

// Handle Backup Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_backup') {
    Permissions::requireOwner();
    if (Csrf::validate($_POST['_csrf_token'] ?? '')) {
        try {
            $backup = BackupService::createBackup($businessId, $userId);
            Flash::success("Backup created successfully: " . $backup['filename']);
            header('Location: ' . url('settings.php#backup'));
            exit;
        } catch (Exception $e) {
            Flash::error('Backup failed: ' . $e->getMessage());
        }
    }
}

// Handle Reset Demo Data (Super Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_demo') {
    if (!Auth::isSuperAdmin()) {
        Flash::error('Database factory reset is restricted to Super Admin administrators only.');
        header('Location: ' . url('settings.php#backup'));
        exit;
    }
    Permissions::requireOwner();
    if (Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $res = RestoreService::resetDemoData($businessId, $userId);
        if ($res['success']) {
            Flash::success('Demo data reset to initial baseline successfully.');
        } else {
            Flash::error('Failed to reset demo data: ' . $res['error']);
        }
        header('Location: ' . url('settings.php#backup'));
        exit;
    }
}


// Handle Subscription Payment Proof Submission (Shopkeeper -> Super Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_subscription_payment') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $proofPath = null;
        if (!empty($_FILES['proof_file']['name']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['proof_file'];
            if ($f['size'] <= 4 * 1024 * 1024) {
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                if (in_array($ext, $allowed, true)) {
                    $destDir = YBT_ROOT . '/storage/uploads/payments';
                    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
                    $destName = 'sub_proof_' . $businessId . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($f['tmp_name'], $destDir . '/' . $destName)) {
                        $proofPath = 'storage/uploads/payments/' . $destName;
                    }
                }
            }
        }

        SubscriptionService::submitPaymentProof($businessId, [
            'plan_id' => (int)($_POST['plan_id'] ?? 2),
            'billing_cycle' => $_POST['billing_cycle'] ?? 'monthly',
            'amount_paid' => (float)($_POST['amount_paid'] ?? 0),
            'payment_method' => trim($_POST['payment_method'] ?? 'Bank Transfer'),
            'payment_reference' => trim($_POST['payment_reference'] ?? ''),
            'proof_attachment' => $proofPath,
            'notes' => trim($_POST['notes'] ?? '')
        ]);

        Flash::success('Subscription payment proof submitted successfully! The software owner will verify your transaction and activate your plan.');
        header('Location: ' . url('settings.php#section-subscription'));
        exit;
    }
}
// Fetch existing backups
$backups = BackupService::listBackups($businessId);

// Fetch AI Provider data
$aiPresets = AIProviderService::getPresets();
$aiProviders = AIProviderService::getAll($businessId);
$activeAiProvider = AIProviderService::getActive($businessId);
$subInfo = SubscriptionService::getBusinessPlan($businessId);
$availablePlans = SubscriptionService::getPlans();
$ownerPaymentMethods = PlatformSettings::getOwnerPaymentMethods();


$editProvider = null;
if (isset($_GET['edit_provider'])) {
    $editId = (int)$_GET['edit_provider'];
    $editProvider = Database::fetch("SELECT * FROM ai_provider_configs WHERE id = ? AND business_id = ?", [$editId, $businessId]);
    if ($editProvider && !empty($editProvider['api_key_encrypted'])) {
        $editProvider['api_key'] = decryptString($editProvider['api_key_encrypted']);
    }
}

$pageTitle = 'Business Settings';
$pageScripts = ['settings.js'];
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header mb-4">
    <h1 class="page-title">Business & Application Settings</h1>
    <p class="page-subtitle">Configure organization profile, tax rules, banking details, invoices, AI providers, and system backups</p>
</div>

<!-- Tabs Nav -->
<div class="tabs-nav mb-4">
    <a href="#business" class="tab-link settings-tab-btn active" data-target="#section-business">
        <i class="ti ti-building mr-1"></i> Business Profile
    </a>
    <a href="#banking" class="tab-link settings-tab-btn" data-target="#section-banking">
        <i class="ti ti-building-bank mr-1"></i> Banking & UPI
    </a>
    <a href="#invoicing" class="tab-link settings-tab-btn" data-target="#section-invoicing">
        <i class="ti ti-receipt mr-1"></i> Invoice Defaults
    </a>
    <a href="#subscription" class="tab-link settings-tab-btn" data-target="#section-subscription">
        <i class="ti ti-crown mr-1 text-warning"></i> My Subscription Plan
    </a>
    <?php if (Auth::isOwner()): ?>
    <a href="#backup" class="tab-link settings-tab-btn" data-target="#section-backup">
        <i class="ti ti-database mr-1"></i> Backup & Data
    </a>
    <?php endif; ?>
</div>

<form method="POST" action="<?= url('settings.php') ?>" id="settings-form" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="update_business">

    <!-- Section 1: Business Profile -->
    <div class="settings-section active" id="section-business">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Company Identity & Tax Details</h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Business Trade Name</label>
                        <input type="text" name="name" class="form-input" value="<?= e($business['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Legal Registered Entity Name</label>
                        <input type="text" name="legal_name" class="form-input" value="<?= e($business['legal_name']) ?>">
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Tagline</label>
                    <input type="text" name="tagline" class="form-input" value="<?= e($business['tagline']) ?>">
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">Official Email</label>
                        <input type="email" name="email" class="form-input" value="<?= e($business['email']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Official Phone</label>
                        <input type="text" name="phone" class="form-input" value="<?= e($business['phone']) ?>">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">GSTIN (Goods and Services Tax Number)</label>
                        <input type="text" name="gstin" class="form-input" value="<?= e($business['gstin']) ?>" maxlength="15">
                    </div>
                    <div class="form-group">
                        <label class="form-label">PAN (Permanent Account Number)</label>
                        <input type="text" name="pan" class="form-input" value="<?= e($business['pan']) ?>" maxlength="10">
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Registered Office Address</label>
                    <input type="text" name="address_line1" class="form-input mb-2" placeholder="Street / Tower / Suite" value="<?= e($business['address_line1']) ?>">
                    <input type="text" name="address_line2" class="form-input mb-2" placeholder="Area / Landmark" value="<?= e($business['address_line2']) ?>">
                    <div class="grid grid-cols-3 gap-2">
                        <input type="text" name="city" class="form-input" placeholder="City" value="<?= e($business['city']) ?>">
                        <input type="text" name="state" class="form-input" placeholder="State" value="<?= e($business['state']) ?>">
                        <input type="text" name="postal_code" class="form-input" placeholder="PIN Code" value="<?= e($business['postal_code']) ?>">
                    </div>
                </div>

                <?php if (Permissions::has('settings.edit')): ?>
                <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy mr-1"></i> Save Business Profile</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Section 2: Banking & UPI -->
    <div class="settings-section" id="section-banking">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Bank Accounts & Remittance Info</h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">Bank Name</label>
                        <input type="text" name="bank_name" class="form-input" placeholder="e.g. HDFC Bank" value="<?= e($business['bank_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bank Account Number</label>
                        <input type="text" name="account_number" class="form-input" placeholder="502000..." value="<?= e($business['account_number']) ?>">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">IFSC Code</label>
                        <input type="text" name="ifsc_code" class="form-input" placeholder="HDFC0000240" value="<?= e($business['ifsc_code']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">UPI ID / VPA</label>
                        <input type="text" name="upi_id" class="form-input" placeholder="business@bank" value="<?= e($business['upi_id']) ?>">
                    </div>
                </div>

                <?php if (Permissions::has('settings.edit')): ?>
                <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy mr-1"></i> Save Banking Details</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Section 3: Invoicing Defaults -->
    <div class="settings-section" id="section-invoicing">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Invoice Numbering & Default Terms</h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-3 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">Invoice Prefix</label>
                        <input type="text" name="invoice_prefix" class="form-input" value="<?= e($business['invoice_prefix']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quotation Prefix</label>
                        <input type="text" name="quotation_prefix" class="form-input" value="<?= e($business['quotation_prefix']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Default Payment Due Days</label>
                        <input type="number" name="default_due_days" class="form-input" value="<?= e($business['default_due_days']) ?>" min="1">
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Default Invoice Terms & Conditions</label>
                    <textarea name="default_terms" class="form-input" rows="3"><?= e($business['default_terms']) ?></textarea>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Default Customer Notes</label>
                    <textarea name="default_notes" class="form-input" rows="2"><?= e($business['default_notes']) ?></textarea>
                </div>

                <?php if (Permissions::has('settings.edit')): ?>
                <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy mr-1"></i> Save Invoicing Preferences</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<!-- Section: My Subscription Plan -->
<div class="settings-section" id="section-subscription">
    <!-- Active Subscription Overview -->
    <div class="card mb-4 card-glow-primary">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h3 class="card-title">
                    <i class="ti ti-crown mr-2 text-warning"></i> Current SaaS Subscription Plan
                </h3>
                <p class="text-xs text-muted mb-0">Your active software license, usage capacity, and renewal status.</p>
            </div>
            <div>
                <?php if (empty($subInfo['is_expired'])): ?>
                    <span class="badge badge-success text-sm py-1 px-3">
                        <i class="ti ti-circle-check mr-1"></i> <?= e($subInfo['plan_name']) ?> Active
                    </span>
                <?php else: ?>
                    <span class="badge badge-danger text-sm py-1 px-3">
                        <i class="ti ti-alert-triangle mr-1"></i> Plan Expired
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div class="d-flex align-center gap-3 mb-4">
                <div class="avatar avatar-lg bg-warning-light text-warning avatar-circle">
                    <i class="ti ti-diamond text-2xl"></i>
                </div>
                <div class="flex-1">
                    <div class="d-flex align-center gap-2 mb-1">
                        <h4 class="mb-0 font-bold"><?= e($subInfo['plan_name']) ?></h4>
                        <span class="badge badge-primary"><?= e($subInfo['plan']['badge'] ?? 'Active') ?></span>
                    </div>
                    <div class="text-xs text-muted">
                        Expires on: <strong><?= formatDate($subInfo['expires_at']) ?></strong> &bull; 
                        Remaining: <span class="badge badge-subtle font-semibold"><?= (int)$subInfo['days_remaining'] ?> days remaining</span>
                    </div>
                </div>
            </div>

            <!-- Quota Utilization Metrics -->
            <h5 class="text-xs font-bold text-uppercase text-muted tracking-wider mb-3">Live Resource Capacity & Quotas</h5>
            <div class="grid grid-cols-4 gap-3">
                <div class="p-3 rounded bg-surface border">
                    <div class="text-xs text-muted mb-1 d-flex justify-between">
                        <span>Staff User Accounts</span>
                        <span><?= $subInfo['usage']['users'] ?> / <?= $subInfo['plan']['max_users'] ?></span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <?php $uPct = min(100, round(($subInfo['usage']['users'] / max(1, $subInfo['plan']['max_users'])) * 100)); ?>
                        <div class="progress-bar bg-primary" style="width: <?= $uPct ?>%"></div>
                    </div>
                </div>

                <div class="p-3 rounded bg-surface border">
                    <div class="text-xs text-muted mb-1 d-flex justify-between">
                        <span>Customer Limit</span>
                        <span><?= $subInfo['usage']['customers'] ?> / <?= $subInfo['plan']['max_customers'] ?></span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <?php $cPct = min(100, round(($subInfo['usage']['customers'] / max(1, $subInfo['plan']['max_customers'])) * 100)); ?>
                        <div class="progress-bar bg-info" style="width: <?= $cPct ?>%"></div>
                    </div>
                </div>

                <div class="p-3 rounded bg-surface border">
                    <div class="text-xs text-muted mb-1 d-flex justify-between">
                        <span>Monthly Invoices</span>
                        <span><?= $subInfo['usage']['invoices_this_month'] ?> / <?= $subInfo['plan']['max_invoices_per_month'] ?></span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <?php $iPct = min(100, round(($subInfo['usage']['invoices_this_month'] / max(1, $subInfo['plan']['max_invoices_per_month'])) * 100)); ?>
                        <div class="progress-bar bg-success" style="width: <?= $iPct ?>%"></div>
                    </div>
                </div>

                <div class="p-3 rounded bg-surface border">
                    <div class="text-xs text-muted mb-1 d-flex justify-between">
                        <span>AI Assistant</span>
                        <span><?= !empty($subInfo['plan']['ai_enabled']) ? 'Enabled' : 'Disabled' ?></span>
                    </div>
                    <div>
                        <?php if (!empty($subInfo['plan']['ai_enabled'])): ?>
                            <span class="badge badge-success text-xs"><i class="ti ti-check mr-1"></i> Included</span>
                        <?php else: ?>
                            <span class="badge badge-secondary text-xs"><i class="ti ti-lock mr-1"></i> Upgrade Required</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Official Platform Payment Accounts (Owner Accounts) -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">
                <i class="ti ti-building-bank mr-2 text-primary"></i> Software Platform Payment Accounts
            </h3>
            <p class="text-xs text-muted mb-0">Use these official accounts to remit subscription and renewal payments to DJ CRM Software platform management.</p>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 gap-4">
                <div class="p-3 rounded bg-surface border">
                    <div class="font-bold text-sm text-primary mb-2"><i class="ti ti-building mr-1"></i> Bank Wire / IBFT (Official)</div>
                    <table class="table-compact text-xs w-full">
                        <tr><td class="text-muted py-1" style="width:110px;">Bank:</td><td><strong><?= e($ownerPaymentMethods['bank_name'] ?? 'Meezan Bank Limited') ?></strong></td></tr>
                        <tr><td class="text-muted py-1">Account Title:</td><td><strong><?= e($ownerPaymentMethods['account_title'] ?? 'DJ CRM Software Services') ?></strong></td></tr>
                        <tr><td class="text-muted py-1">Account No:</td><td><strong class="font-mono"><?= e($ownerPaymentMethods['account_number'] ?? '01020304050607') ?></strong></td></tr>
                        <tr><td class="text-muted py-1">IBAN:</td><td><strong class="font-mono"><?= e($ownerPaymentMethods['iban'] ?? 'PK00MEZN01020304050607') ?></strong></td></tr>
                    </table>
                </div>

                <div class="p-3 rounded bg-surface border">
                    <div class="font-bold text-sm text-success mb-2"><i class="ti ti-device-mobile mr-1"></i> Mobile Wallets & UPI</div>
                    <table class="table-compact text-xs w-full">
                        <tr><td class="text-muted py-1" style="width:110px;">EasyPaisa:</td><td><strong class="font-mono"><?= e($ownerPaymentMethods['easypaisa'] ?? '03001234567') ?></strong></td></tr>
                        <tr><td class="text-muted py-1">JazzCash:</td><td><strong class="font-mono"><?= e($ownerPaymentMethods['jazzcash'] ?? '03001234567') ?></strong></td></tr>
                        <tr><td class="text-muted py-1">Raast ID:</td><td><strong class="font-mono"><?= e($ownerPaymentMethods['raast_id'] ?? '03001234567') ?></strong></td></tr>
                        <tr><td class="text-muted py-1">UPI ID:</td><td><strong class="font-mono"><?= e($ownerPaymentMethods['upi_id'] ?? 'djcrm@bank') ?></strong></td></tr>
                    </table>
                </div>
            </div>
            <div class="alert alert-info mt-3 mb-0 text-xs">
                <i class="ti ti-info-circle mr-1"></i> <strong>Note:</strong> These payment channels belong strictly to platform management. To configure your own shop banking details for customer invoices, navigate to the <strong>Banking & UPI</strong> tab above.
            </div>
        </div>
    </div>

    <!-- Submit Payment Proof Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">
                <i class="ti ti-upload mr-2 text-primary"></i> Renew or Upgrade Subscription
            </h3>
            <p class="text-xs text-muted mb-0">Submit your transaction receipt or reference number after transferring your subscription fee.</p>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= url('settings.php') ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="submit_subscription_payment">

                <div class="grid grid-cols-3 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Select SaaS Plan</label>
                        <select name="plan_id" class="form-select" required>
                            <?php foreach ($availablePlans as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= ($subInfo['plan']['id'] ?? 2) == $p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['name']) ?> — <?= formatCurrency($p['price_monthly'], $p['currency']) ?> / mo
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Billing Cycle</label>
                        <select name="billing_cycle" class="form-select" required>
                            <option value="monthly">Monthly Subscription</option>
                            <option value="annual">Annual License (Save 15%)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Amount Paid (PKR)</label>
                        <input type="number" step="0.01" name="amount_paid" class="form-input" placeholder="e.g. 3000" required>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Payment Method Used</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="Bank Transfer">Bank Transfer (IBFT)</option>
                            <option value="EasyPaisa">EasyPaisa</option>
                            <option value="JazzCash">JazzCash</option>
                            <option value="Raast">Raast Instant Payment</option>
                            <option value="UPI">UPI</option>
                            <option value="Cash / Cheque">Cash / Authorized Agent</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Transaction Reference / ID</label>
                        <input type="text" name="payment_reference" class="form-input" placeholder="e.g. TXN-98765432" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Payment Receipt / Screenshot</label>
                        <input type="file" name="proof_file" class="form-input" accept="image/*,.pdf">
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Additional Remarks / Note to Super Admin</label>
                    <textarea name="notes" class="form-textarea" rows="2" placeholder="Optional transaction details or renewal requests..."></textarea>
                </div>

                <div class="d-flex justify-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-send mr-1"></i> Submit Payment Proof for Verification
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Central AI Digital Manager Information -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h3 class="card-title">
                    <i class="ti ti-sparkles mr-2 text-primary"></i> Central AI Digital Manager
                </h3>
                <p class="text-xs text-muted mb-0">Intelligent financial queries, conversational summaries, and profit analytics.</p>
            </div>
            <div>
                <a href="<?= url('ai-assistant.php') ?>" class="btn btn-sm btn-primary">
                    <i class="ti ti-messages mr-1"></i> Open AI Assistant
                </a>
            </div>
        </div>
        <div class="card-body">
            <p class="text-sm text-muted mb-0">
                DJ CRM Software includes a cloud AI Digital Manager configured centrally by platform administration.
                Shopkeepers on <strong>Standard, Premium, and Enterprise</strong> plans can use AI business intelligence directly without needing to provide or configure their own API keys.
            </p>
            <?php if (Auth::isSuperAdmin()): ?>
                <div class="mt-3 p-3 rounded bg-warning-light border border-warning text-xs">
                    <strong><i class="ti ti-shield-check mr-1"></i> Platform Owner Notice:</strong>
                    As Platform Super Admin, you can configure platform-wide AI API keys and models in the
                    <a href="<?= url('admin/settings.php') ?>" class="font-bold underline text-primary">Super Admin Portal Settings</a>.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Section 4: Backup, Restore & Reset (Owner only) -->
<?php if (Auth::isOwner()): ?>
<div class="settings-section" id="section-backup">
    <div class="card mb-4">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h3 class="card-title"><i class="ti ti-database mr-1 text-primary"></i> Data Backup & Safety Restores</h3>
                <p class="text-xs text-muted mb-0">Generate standalone SQL archives of all business records and ledgers.</p>
            </div>
            <form method="POST" action="<?= url('settings.php') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="create_backup">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="ti ti-download mr-1"></i> Create Backup Now
                </button>
            </form>
        </div>
        <div class="card-body p-0">
            <?php if (empty($backups)): ?>
                <div class="p-4 text-center text-muted text-sm">No backup files created yet. Click above to generate your first backup.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Backup Filename</th>
                                <th>Generated At</th>
                                <th>Size</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $b): ?>
                            <tr>
                                <td><code><?= e($b['filename']) ?></code></td>
                                <td><?= formatDate($b['created_at']) ?> <?= date('H:i', strtotime($b['created_at'])) ?></td>
                                <td><?= formatFileSize($b['size_bytes']) ?></td>
                                <td><span class="badge badge-success">Completed</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Factory Reset Demo Data (Super Admin Only) -->
    <?php if (Auth::isSuperAdmin()): ?>
    <div class="card border-danger">
        <div class="card-header">
            <h3 class="card-title text-danger"><i class="ti ti-alert-triangle mr-1"></i> Reset Demo Data</h3>
        </div>
        <div class="card-body">
            <p class="text-sm text-muted mb-3">
                This operation resets the demo customers, invoices, leads, products, and tasks to their baseline demonstration dataset.
                Existing custom credentials will revert to <code>password123</code>.
            </p>
            <form method="POST" action="<?= url('settings.php') ?>" onsubmit="return confirm('Are you sure you want to restore the demo factory state?');">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="reset_demo">
                <button type="submit" class="btn btn-danger btn-sm">
                    <i class="ti ti-refresh mr-1"></i> Reset to Factory Demo State
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
