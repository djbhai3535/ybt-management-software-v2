<?php
/**
 * DJ CRM Software 5.2.2 — Super Admin Platform WhatsApp Support Manager
 * Controls the central, platform-wide WhatsApp support hotline for the software owner.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';

$pageTitle = 'Support & WhatsApp Hotline';

// Handle Save POST action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired. Please refresh and try again.');
    } else {
        $rawNumber = trim($_POST['whatsapp_support_number'] ?? '');
        $cleanNumber = preg_replace('/[^0-9]/', '', $rawNumber);

        if (empty($cleanNumber)) {
            Flash::error('Please enter a valid WhatsApp phone number with country calling code.');
        } elseif (strlen($cleanNumber) < 8 || strlen($cleanNumber) > 16) {
            Flash::error('WhatsApp number must be between 8 and 16 digits including country code.');
        } else {
            PlatformSettings::set('whatsapp_support_number', $cleanNumber, 'Central WhatsApp Support hotline');
            
            // Log action in audit log
            ActivityLog::log(
                0,
                Auth::id(),
                'whatsapp_support_updated',
                'platform_settings',
                0,
                "Super Admin updated central WhatsApp support hotline to +{$cleanNumber}"
            );

            Flash::success("Global WhatsApp Support hotline successfully updated to +{$cleanNumber}. All public, shopkeeper, and invoice pages will now direct users to this number.");
            header('Location: ' . url('admin/whatsapp-support.php'));
            exit;
        }
    }
}

$currentRaw = PlatformSettings::getWhatsAppSupport();
$cleanCurrent = preg_replace('/[^0-9]/', '', $currentRaw);

// Friendly display format e.g. +92 300 1234567
$formattedDisplay = '+' . $cleanCurrent;
if (strlen($cleanCurrent) === 12 && substr($cleanCurrent, 0, 2) === '92') {
    $formattedDisplay = '+92 ' . substr($cleanCurrent, 2, 3) . ' ' . substr($cleanCurrent, 5);
}

$sampleTestUrl = "https://wa.me/{$cleanCurrent}?text=" . urlencode("Hello DJ CRM Support, I am contacting you from apkashow.com.");

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4 flex-wrap gap-2">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
            <i class="ti ti-brand-whatsapp text-success" style="font-size: 2rem;"></i>
            Global WhatsApp Support Hotline
        </h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.88rem;">
            Central customer service hotline for software owner technical assistance across all shops and public pages.
        </p>
    </div>
    <div>
        <a href="<?= e($sampleTestUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-sm" style="background-color: #25d366; border-color: #25d366;">
            <i class="ti ti-external-link mr-1"></i> Test Active Destination
        </a>
    </div>
</div>

<div class="grid grid-cols-3 gap-4 mb-4">
    <!-- Active Status Card -->
    <div class="card p-3">
        <div class="d-flex justify-between align-center mb-2">
            <span class="text-xs text-muted text-uppercase fw-bold">Current Active Hotline</span>
            <span class="badge" style="background: rgba(37, 211, 102, 0.2); color: #25d366; font-size: 0.75rem;">
                <i class="ti ti-circle-check mr-1"></i> Live Platform-Wide
            </span>
        </div>
        <div style="font-size: 1.4rem; font-weight: 800; font-family: monospace; color: #25d366;">
            <?= e($formattedDisplay) ?>
        </div>
        <div class="text-xs text-muted mt-2">
            Target URL: <code style="color: #94a3b8; font-size: 0.75rem;">https://wa.me/<?= e($cleanCurrent) ?></code>
        </div>
    </div>

    <!-- Placement Coverage Card -->
    <div class="card p-3">
        <div class="text-xs text-muted text-uppercase fw-bold mb-2">Active Button Locations</div>
        <div style="font-size: 0.88rem; line-height: 1.6;">
            <div><i class="ti ti-check text-success mr-1"></i> Global Floating Circle Button (All CRM Pages)</div>
            <div><i class="ti ti-check text-success mr-1"></i> Top Navigation Bar Support Pill</div>
            <div><i class="ti ti-check text-success mr-1"></i> Public Auth Pages (Login & Registration)</div>
        </div>
    </div>

    <!-- Security & Tenant Isolation Card -->
    <div class="card p-3">
        <div class="text-xs text-muted text-uppercase fw-bold mb-2">Tenant Privacy Protection</div>
        <div class="text-xs text-muted" style="line-height: 1.5;">
            Individual shopkeepers cannot edit or see this setting. Their personal shop WhatsApp numbers used for receipts and billing are strictly isolated and remain untouched.
        </div>
    </div>
</div>

<div class="grid grid-cols-3 gap-4">
    <!-- Update Hotline Form -->
    <div class="card col-span-2">
        <div class="card-header">
            <h3 class="card-title text-success"><i class="ti ti-edit mr-2"></i> Update Global Support Number</h3>
            <p class="text-xs text-muted mb-0">Changes take effect immediately across all client sessions without server restarts.</p>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= url('admin/whatsapp-support.php') ?>" id="wa-form">
                <?= Csrf::field() ?>

                <div class="form-group mb-3">
                    <label class="form-label required">Support WhatsApp Mobile Number</label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <span style="position: absolute; left: 12px; font-weight: 700; color: #25d366; font-size: 1.1rem; pointer-events: none;">+</span>
                        <input type="text" 
                               name="whatsapp_support_number" 
                               id="wa-input"
                               class="form-input font-mono" 
                               style="padding-left: 28px; font-size: 1.05rem; font-weight: 600;"
                               value="<?= e($cleanCurrent) ?>" 
                               placeholder="923001234567" 
                               required>
                    </div>
                    <div class="text-xs text-muted mt-2" style="line-height: 1.4;">
                        <strong>Format Instructions:</strong> Enter international calling code followed by number without <code>+</code>, spaces, or hyphens.<br>
                        Examples: Pakistan: <code>923001234567</code> | India: <code>919876543210</code> | UAE: <code>971501234567</code> | UK: <code>447123456789</code>
                    </div>
                </div>

                <!-- Live URL Preview -->
                <div class="p-3 mb-4 rounded" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(148, 163, 184, 0.2);">
                    <div class="text-xs text-muted text-uppercase fw-bold mb-1">Live Click-to-Chat Link Preview:</div>
                    <div id="wa-preview" style="font-family: monospace; font-size: 0.85rem; color: #38bdf8; word-break: break-all;">
                        https://wa.me/<?= e($cleanCurrent) ?>?text=<?= urlencode('Hello DJ CRM Support, I need assistance with my software.') ?>
                    </div>
                </div>

                <div class="d-flex align-center gap-3">
                    <button type="submit" class="btn btn-success" style="background-color: #25d366; border-color: #25d366; padding: 0.65rem 1.5rem; font-weight: 700;">
                        <i class="ti ti-device-floppy mr-1"></i> Save Global Support Number
                    </button>
                    <a id="btn-test-preview" href="<?= e($sampleTestUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">
                        <i class="ti ti-brand-whatsapp mr-1 text-success"></i> Test Chat Now
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Architectural Documentation -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="ti ti-info-circle mr-2 text-primary"></i> Dual WhatsApp Architecture</h3>
        </div>
        <div class="card-body text-xs" style="line-height: 1.6; color: #94a3b8;">
            <div class="mb-3">
                <strong style="color: #f8fafc; display: block; margin-bottom: 0.25rem;">
                    <i class="ti ti-shield-lock text-warning mr-1"></i> Platform Support Hotline (This Setting)
                </strong>
                Controlled strictly by the Super Administrator. Every shopkeeper, staff member, and website visitor contacts YOU via this number when clicking the bottom-right WhatsApp button.
            </div>
            <hr style="border: 0; border-top: 1px solid rgba(148, 163, 184, 0.15); margin: 0.75rem 0;">
            <div>
                <strong style="color: #f8fafc; display: block; margin-bottom: 0.25rem;">
                    <i class="ti ti-receipt text-info mr-1"></i> Individual Shop WhatsApp (Tenant-Isolated)
                </strong>
                Each registered shopkeeper configures their own business WhatsApp in their Business Profile. That number is used exclusively for dispatching PDF customer invoices and thermal receipts to their own buyers.
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('wa-input');
    const preview = document.getElementById('wa-preview');
    const testBtn = document.getElementById('btn-test-preview');

    function updatePreview() {
        const raw = input.value.trim().replace(/[^0-9]/g, '');
        const targetUrl = 'https://wa.me/' + (raw || '923000000000') + '?text=' + encodeURIComponent('Hello DJ CRM Support, I need assistance with my software.');
        preview.textContent = targetUrl;
        testBtn.href = targetUrl;
    }

    input.addEventListener('input', updatePreview);
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
