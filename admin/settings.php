<?php
/**
 * DJ CRM Software — Central Super Admin Platform Settings & Owner Controls
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';
require_once __DIR__ . '/../services/platform-settings-service.php';
require_once __DIR__ . '/../services/ai-provider-service.php';
require_once __DIR__ . '/../services/backup-service.php';

$pageTitle = 'Platform Settings & Central AI';

// Handle Action: Save Central AI Configuration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_central_ai') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired.');
    } else {
        $provider = trim($_POST['provider'] ?? 'google_gemini');
        $model = trim($_POST['model'] ?? 'gemini-1.5-flash');
        $baseUrl = trim($_POST['base_url'] ?? '');
        $rawKey = trim($_POST['api_key'] ?? '');

        PlatformSettings::set('central_ai_provider', $provider, 'Central platform AI provider');
        PlatformSettings::set('central_ai_model', $model, 'Default central AI model');
        if (!empty($baseUrl)) {
            PlatformSettings::set('central_ai_base_url', $baseUrl, 'Central AI API endpoint');
        }

        if (!empty($rawKey)) {
            $enc = function_exists('encryptString') ? encryptString($rawKey) : $rawKey;
            PlatformSettings::set('central_ai_key_encrypted', $enc, 'Encrypted central AI API key', true);
        }

        Flash::success('Central AI provider configuration saved securely.');
        header('Location: ' . url('admin/settings.php#central-ai'));
        exit;
    }
}

// Handle Action: Save WhatsApp Support & Platform Identity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_support_settings') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired.');
    } else {
        $whatsapp = trim($_POST['whatsapp_support_number'] ?? '');
        $platformName = trim($_POST['platform_name'] ?? 'DJ CRM Software');

        if (!empty($whatsapp)) {
            $cleanWa = preg_replace('/[^0-9]/', '', $whatsapp);
            PlatformSettings::set('whatsapp_support_number', $cleanWa, 'Central WhatsApp Support hotline');
        }
        if (!empty($platformName)) {
            PlatformSettings::set('platform_name', $platformName, 'Official platform name');
        }

        Flash::success('Platform identity and WhatsApp support hotline updated.');
        header('Location: ' . url('admin/settings.php#support'));
        exit;
    }
}

// Handle Action: Save Owner Subscription Payment Methods
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_owner_payments') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired.');
    } else {
        PlatformSettings::set('owner_bank_name', trim($_POST['bank_name'] ?? ''), 'Owner Bank Name');
        PlatformSettings::set('owner_account_title', trim($_POST['account_title'] ?? ''), 'Owner Account Title');
        PlatformSettings::set('owner_account_number', trim($_POST['account_number'] ?? ''), 'Owner Account Number');
        PlatformSettings::set('owner_iban', trim($_POST['iban'] ?? ''), 'Owner IBAN');
        PlatformSettings::set('owner_easypaisa', trim($_POST['easypaisa'] ?? ''), 'Owner EasyPaisa');
        PlatformSettings::set('owner_jazzcash', trim($_POST['jazzcash'] ?? ''), 'Owner JazzCash');
        PlatformSettings::set('owner_raast_id', trim($_POST['raast_id'] ?? ''), 'Owner Raast ID');
        PlatformSettings::set('owner_upi_id', trim($_POST['upi_id'] ?? ''), 'Owner UPI ID');

        Flash::success('Owner subscription payment accounts updated successfully.');
        header('Location: ' . url('admin/settings.php#billing'));
        exit;
    }
}

// Handle Action: Administrative Backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_admin_backup') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired.');
    } else {
        try {
            $backup = BackupService::createBackup(1, Auth::id());
            Flash::success("Administrative backup generated: " . $backup['filename']);
        } catch (\Throwable $e) {
            Flash::error("Backup failed: " . $e->getMessage());
        }
        header('Location: ' . url('admin/settings.php#infrastructure'));
        exit;
    }
}

// Data for rendering
$centralAi = PlatformSettings::getCentralAI();
$ownerPayments = PlatformSettings::getOwnerPaymentMethods();
$whatsappNumber = PlatformSettings::getWhatsAppSupport();
$driver = Database::getDriver();
$aiPresets = AIProviderService::getPresets();

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4 flex-wrap gap-2">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;">Platform Settings & Central AI</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
            Configure software owner settings: central AI keys, WhatsApp support hotline, and SaaS subscription billing accounts.
        </p>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="tabs-nav mb-4">
    <a href="#central-ai" class="tab-link settings-tab-btn active" data-target="#section-central-ai">
        <i class="ti ti-sparkles mr-1 text-primary"></i> Central Platform AI
    </a>
    <a href="#support" class="tab-link settings-tab-btn" data-target="#section-support">
        <i class="ti ti-brand-whatsapp mr-1 text-success"></i> Central WhatsApp Support
    </a>
    <a href="#billing" class="tab-link settings-tab-btn" data-target="#section-billing">
        <i class="ti ti-cash mr-1 text-warning"></i> Owner Subscription Accounts
    </a>
    <a href="#infrastructure" class="tab-link settings-tab-btn" data-target="#section-infrastructure">
        <i class="ti ti-server mr-1"></i> Infrastructure & Security
    </a>
</div>

<!-- TAB 1: Central Platform AI -->
<div class="settings-section active" id="section-central-ai">
    <div class="card mb-4 card-glow-primary">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h3 class="card-title text-primary"><i class="ti ti-cpu mr-2"></i> Central AI Engine (Software Owner Managed)</h3>
                <p class="text-xs text-muted mb-0">Powers the AI Digital Manager for all authorized shopkeepers (Premium & Enterprise plans) via secure server-side requests.</p>
            </div>
            <div>
                <?php if ($centralAi['has_key']): ?>
                    <span class="badge badge-success"><i class="ti ti-circle-check mr-1"></i> Central Key Configured</span>
                <?php else: ?>
                    <span class="badge badge-warning"><i class="ti ti-alert-triangle mr-1"></i> No Central Key Set</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div class="p-3 bg-subtle rounded border mb-4">
                <div class="d-flex align-center gap-2 text-xs font-semibold text-warning mb-1">
                    <i class="ti ti-shield-lock text-sm"></i> STRICT SECURITY ENFORCEMENT
                </div>
                <p class="text-xs text-muted mb-0">
                    This central API key is <strong>never exposed</strong> to shopkeepers or sent to client-side scripts.
                    It is encrypted at rest on the server and utilized only when an authorized shopkeeper sends an analytical prompt to the AI Digital Manager.
                </p>
            </div>

            <form method="POST" action="<?= url('admin/settings.php') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save_central_ai">

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Central AI Provider</label>
                        <select name="provider" id="central-provider" class="form-select" onchange="updateProviderDefaults(this.value)" required>
                            <?php foreach ($aiPresets as $pKey => $pData): ?>
                                <option value="<?= e($pKey) ?>" <?= ($centralAi['provider'] === $pKey) ? 'selected' : '' ?> data-model="<?= e($pData['default_model']) ?>" data-url="<?= e($pData['default_base_url']) ?>">
                                    <?= e($pData['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Default Model ID</label>
                        <input type="text" name="model" id="central-model" class="form-input" value="<?= e($centralAi['model']) ?>" placeholder="e.g. gemini-1.5-flash" required>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label required">API Base URL</label>
                    <input type="text" name="base_url" id="central-base-url" class="form-input" value="<?= e($centralAi['base_url']) ?>" placeholder="https://generativelanguage.googleapis.com/v1beta" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Central API Secret Key</label>
                    <div class="d-flex gap-2 align-center">
                        <input type="password" name="api_key" id="central-api-key" class="form-input font-mono flex-1" placeholder="<?= $centralAi['has_key'] ? 'Key configured: ' . e($centralAi['masked_key']) . ' (Leave empty to keep existing)' : 'Paste API Key (e.g. AIzaSy...)' ?>">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleKeyVisibility()">
                            <i class="ti ti-eye" id="toggle-key-icon"></i>
                        </button>
                    </div>
                    <div class="text-xs text-muted mt-1">Current status: <strong><?= e($centralAi['masked_key']) ?></strong></div>
                </div>

                <button type="submit" class="btn btn-primary font-bold">
                    <i class="ti ti-device-floppy mr-1"></i> Save Central AI Configuration
                </button>
            </form>
        </div>
    </div>
</div>

<!-- TAB 2: Central WhatsApp Support & Platform Identity -->
<div class="settings-section" id="section-support" style="display: none;">
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title text-success"><i class="ti ti-brand-whatsapp mr-2"></i> Global WhatsApp Support Hotline</h3>
            <p class="text-xs text-muted mb-0">The official customer service WhatsApp number shown across all shopkeeper interfaces.</p>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= url('admin/settings.php') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save_support_settings">

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">WhatsApp Support Hotline Number</label>
                        <div class="input-prefix-wrapper">
                            <span class="input-prefix">+</span>
                            <input type="text" name="whatsapp_support_number" class="form-input font-mono" value="<?= e($whatsappNumber) ?>" placeholder="923000000000" required>
                        </div>
                        <div class="text-xs text-muted mt-1">Include country calling code without '+' or spaces (e.g. <code>923001234567</code> for Pakistan).</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Official Platform Brand Name</label>
                        <input type="text" name="platform_name" class="form-input" value="<?= e(PlatformSettings::get('platform_name', 'DJ CRM Software')) ?>" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-success font-bold" style="background-color: #25d366; border-color: #25d366;">
                    <i class="ti ti-device-floppy mr-1"></i> Save Support & Branding
                </button>
            </form>
        </div>
    </div>
</div>

<!-- TAB 3: Software Owner Subscription Payment Accounts -->
<div class="settings-section" id="section-billing" style="display: none;">
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title text-warning"><i class="ti ti-cash mr-2"></i> Software Owner Subscription Payment Accounts</h3>
            <p class="text-xs text-muted mb-0">These payment receiving details are presented to shopkeepers when purchasing, renewing, or upgrading their DJ CRM SaaS plans.</p>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= url('admin/settings.php') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save_owner_payments">

                <h4 class="text-xs font-bold uppercase text-primary mb-2">1. Official Bank Account (IBFT)</h4>
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Bank Name</label>
                        <input type="text" name="bank_name" class="form-input" value="<?= e($ownerPayments['bank_name']) ?>" placeholder="e.g. Meezan Bank / HBL" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Account Title / Beneficiary Name</label>
                        <input type="text" name="account_title" class="form-input" value="<?= e($ownerPayments['account_title']) ?>" placeholder="e.g. DJ CRM Software Services" required>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="form-group">
                        <label class="form-label required">Account Number</label>
                        <input type="text" name="account_number" class="form-input font-mono" value="<?= e($ownerPayments['account_number']) ?>" placeholder="01020304050607" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">IBAN Number</label>
                        <input type="text" name="iban" class="form-input font-mono" value="<?= e($ownerPayments['iban']) ?>" placeholder="PK00MEZN01020304050607">
                    </div>
                </div>

                <h4 class="text-xs font-bold uppercase text-success mb-2">2. Mobile Wallets & Instant Rails</h4>
                <div class="grid grid-cols-3 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">EasyPaisa Account #</label>
                        <input type="text" name="easypaisa" class="form-input font-mono" value="<?= e($ownerPayments['easypaisa']) ?>" placeholder="03001234567">
                    </div>
                    <div class="form-group">
                        <label class="form-label">JazzCash Account #</label>
                        <input type="text" name="jazzcash" class="form-input font-mono" value="<?= e($ownerPayments['jazzcash']) ?>" placeholder="03001234567">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Raast ID (Instant Transfer)</label>
                        <input type="text" name="raast_id" class="form-input font-mono" value="<?= e($ownerPayments['raast_id']) ?>" placeholder="03001234567">
                    </div>
                </div>

                <div class="form-group mb-4">
                    <label class="form-label">UPI ID / International VPA</label>
                    <input type="text" name="upi_id" class="form-input" value="<?= e($ownerPayments['upi_id']) ?>" placeholder="djcrm@bank">
                </div>

                <button type="submit" class="btn btn-warning font-bold">
                    <i class="ti ti-device-floppy mr-1"></i> Save Subscription Payment Accounts
                </button>
            </form>
        </div>
    </div>
</div>

<!-- TAB 4: Infrastructure & Security -->
<div class="settings-section" id="section-infrastructure" style="display: none;">
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-server mr-2 text-warning"></i> Runtime Environment</h3>
            </div>
            <div class="card-body">
                <div class="info-list text-sm">
                    <div class="mb-2">
                        <span class="text-muted text-xs d-block">Platform Identity</span>
                        <div class="font-bold"><?= e(APP_NAME) ?> (v<?= e(APP_VERSION) ?>)</div>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted text-xs d-block">PHP Version & SAPI</span>
                        <div class="font-medium"><?= phpversion() ?> (<?= php_sapi_name() ?>)</div>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted text-xs d-block">Database Engine</span>
                        <span class="badge badge-warning"><?= strtoupper($driver) ?></span>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted text-xs d-block">Active Session Path</span>
                        <code class="text-xs"><?= e(session_save_path()) ?></code>
                    </div>
                </div>

                <form method="POST" action="<?= url('admin/settings.php') ?>" class="mt-4" onsubmit="return confirm('Generate an administrative snapshot backup?');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="create_admin_backup">
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <i class="ti ti-database mr-1"></i> Generate Administrative Backup
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-shield-check mr-2 text-success"></i> Security & Isolation Matrix</h3>
            </div>
            <div class="card-body text-xs text-muted space-y-2">
                <div class="p-2 bg-subtle rounded border">
                    <div class="font-bold text-success mb-1"><i class="ti ti-check mr-1"></i> Strict Route Isolation</div>
                    <div>`/admin/*` is strictly guarded by <code>Auth::requireSuperAdmin()</code>. Shopkeepers receive 403 Forbidden.</div>
                </div>
                <div class="p-2 bg-subtle rounded border">
                    <div class="font-bold text-success mb-1"><i class="ti ti-check mr-1"></i> Multi-Tenant Separation</div>
                    <div>Shopkeepers can only query and mutate records matching their authenticated <code>business_id</code>.</div>
                </div>
                <div class="p-2 bg-subtle rounded border">
                    <div class="font-bold text-success mb-1"><i class="ti ti-check mr-1"></i> Central Secret Protection</div>
                    <div>Owner AI keys and platform settings are not queried in shopkeeper scripts or exposed to client JavaScript.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Tab Switching
document.addEventListener('DOMContentLoaded', function() {
    var tabs = document.querySelectorAll('.settings-tab-btn');
    var sections = document.querySelectorAll('.settings-section');

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            var target = this.getAttribute('data-target');
            tabs.forEach(function(t) { t.classList.remove('active'); });
            sections.forEach(function(s) { s.style.display = 'none'; s.classList.remove('active'); });

            this.classList.add('active');
            var targetEl = document.querySelector(target);
            if (targetEl) {
                targetEl.style.display = 'block';
                targetEl.classList.add('active');
            }
            window.location.hash = target.replace('#section-', '#');
        });
    });

    if (window.location.hash) {
        var hash = window.location.hash;
        var activeTab = document.querySelector('.settings-tab-btn[data-target="#section-' + hash.substring(1) + '"]');
        if (activeTab) activeTab.click();
    }
});

function updateProviderDefaults(providerKey) {
    var select = document.getElementById('central-provider');
    var selectedOption = select.options[select.selectedIndex];
    var defModel = selectedOption.getAttribute('data-model');
    var defUrl = selectedOption.getAttribute('data-url');

    if (defModel) document.getElementById('central-model').value = defModel;
    if (defUrl) document.getElementById('central-base-url').value = defUrl;
}

function toggleKeyVisibility() {
    var keyInput = document.getElementById('central-api-key');
    var icon = document.getElementById('toggle-key-icon');
    if (keyInput.type === 'password') {
        keyInput.type = 'text';
        icon.className = 'ti ti-eye-off';
    } else {
        keyInput.type = 'password';
        icon.className = 'ti ti-eye';
    }
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
