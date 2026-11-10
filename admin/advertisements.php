<?php
/**
 * DJ CRM Software 5.2.2 — Super Admin Central Advertisements & Monetization Engine
 * Safe, multi-tenant advertisement management, HTML/JS code embedding, and impression tracking.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';
require_once __DIR__ . '/../services/ad-service.php';
require_once __DIR__ . '/../services/subscription-service.php';

Database::ensureSchemaCompatibility();

$pageTitle = 'Advertisements & Monetization';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired. Please refresh and try again.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_ad') {
            $title = trim($_POST['title'] ?? '');
            $companyName = trim($_POST['company_name'] ?? '');
            $placement = trim($_POST['placement'] ?? 'dashboard_top');
            $imageUrl = trim($_POST['image_url'] ?? '');
            $targetUrl = trim($_POST['target_url'] ?? '');
            $adCode = trim($_POST['ad_code'] ?? '');
            $targetPlans = trim($_POST['target_plans'] ?? 'all');
            $priority = max(0, (int)($_POST['priority'] ?? 0));
            $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
            $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if (empty($title)) {
                Flash::error('Advertisement title is required.');
            } else {
                AdService::create([
                    'title' => $title,
                    'company_name' => $companyName,
                    'placement' => $placement,
                    'image_url' => $imageUrl,
                    'target_url' => $targetUrl,
                    'ad_code' => $adCode,
                    'target_plans' => $targetPlans,
                    'priority' => $priority,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_active' => $isActive
                ]);

                ActivityLog::log(0, Auth::id(), 'ad_created', 'advertisements', 0, "Super Admin created ad campaign: {$title}");
                Flash::success("Advertisement '{$title}' created successfully.");
                header('Location: ' . url('admin/advertisements.php'));
                exit;
            }
        } elseif ($action === 'update_ad') {
            $id = (int)($_POST['ad_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $companyName = trim($_POST['company_name'] ?? '');
            $placement = trim($_POST['placement'] ?? 'dashboard_top');
            $imageUrl = trim($_POST['image_url'] ?? '');
            $targetUrl = trim($_POST['target_url'] ?? '');
            $adCode = trim($_POST['ad_code'] ?? '');
            $targetPlans = trim($_POST['target_plans'] ?? 'all');
            $priority = max(0, (int)($_POST['priority'] ?? 0));
            $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if (empty($title) || $id <= 0) {
                Flash::error('Valid ad ID and title are required.');
            } else {
                AdService::update($id, [
                    'title' => $title,
                    'company_name' => $companyName,
                    'placement' => $placement,
                    'image_url' => $imageUrl,
                    'target_url' => $targetUrl,
                    'ad_code' => $adCode,
                    'target_plans' => $targetPlans,
                    'priority' => $priority,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_active' => $isActive
                ]);

                ActivityLog::log(0, Auth::id(), 'ad_updated', 'advertisements', $id, "Super Admin updated ad campaign #{$id}: {$title}");
                Flash::success("Advertisement '{$title}' updated successfully.");
                header('Location: ' . url('admin/advertisements.php'));
                exit;
            }
        } elseif ($action === 'toggle_status') {
            $id = (int)($_POST['ad_id'] ?? 0);
            if ($id > 0) {
                AdService::toggle($id);
                Flash::success("Advertisement status updated.");
            }
            header('Location: ' . url('admin/advertisements.php'));
            exit;
        } elseif ($action === 'delete_ad') {
            $id = (int)($_POST['ad_id'] ?? 0);
            if ($id > 0) {
                AdService::delete($id);
                ActivityLog::log(0, Auth::id(), 'ad_deleted', 'advertisements', $id, "Super Admin deleted ad #{$id}");
                Flash::success("Advertisement deleted successfully.");
            }
            header('Location: ' . url('admin/advertisements.php'));
            exit;
        }
    }
}

// Fetch all ads
$ads = AdService::getAll();
$totalImpressions = (int)Database::fetchColumn("SELECT COALESCE(SUM(impressions_count), 0) FROM advertisements");
$totalClicks = (int)Database::fetchColumn("SELECT COALESCE(SUM(clicks_count), 0) FROM advertisements");
$ctr = $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0.00;
$activeCount = 0;
foreach ($ads as $a) {
    if (!empty($a['is_active'])) $activeCount++;
}

// Available plans for targeting dropdown
$plans = SubscriptionService::getAllPlansForAdmin();

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4 flex-wrap gap-2">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 800; margin: 0; color: #f8fafc; display: flex; align-items: center; gap: 0.5rem;">
            <i class="ti ti-ad text-pink"></i>
            Platform Advertisements & Monetization
        </h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.85rem;">
            Display targeted sponsor banners, promotional announcements, and third-party advertising scripts.
        </p>
    </div>
    <div>
        <button type="button" class="btn btn-primary btn-sm" id="btn-new-ad" data-modal-target="ad-modal" onclick="openCreateModal()" style="font-weight: 600;">
            <i class="ti ti-plus mr-1"></i> New Advertisement
        </button>
    </div>
</div>

<!-- Telemetry KPIs -->
<div class="grid grid-cols-5 gap-3 mb-4">
    <div class="card p-3" style="border-top: 3px solid #f472b6;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">Total Campaigns</span>
            <i class="ti ti-ad text-pink"></i>
        </div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #f8fafc;"><?= count($ads) ?></div>
        <div class="text-xs text-muted"><?= $activeCount ?> Currently Active</div>
    </div>

    <div class="card p-3" style="border-top: 3px solid #10b981;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">Active Slots</span>
            <i class="ti ti-circle-check text-success"></i>
        </div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #10b981;"><?= $activeCount ?></div>
        <div class="text-xs text-muted">Running on platform</div>
    </div>

    <div class="card p-3" style="border-top: 3px solid #38bdf8;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">Total Impressions</span>
            <i class="ti ti-eye text-primary"></i>
        </div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #38bdf8;"><?= number_format($totalImpressions) ?></div>
        <div class="text-xs text-muted">Ad views served</div>
    </div>

    <div class="card p-3" style="border-top: 3px solid #f59e0b;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">Total Clicks</span>
            <i class="ti ti-cursor-text text-warning"></i>
        </div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #f59e0b;"><?= number_format($totalClicks) ?></div>
        <div class="text-xs text-muted">User interactions</div>
    </div>

    <div class="card p-3" style="border-top: 3px solid #a855f7;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold text-uppercase">CTR Performance</span>
            <i class="ti ti-chart-arrows text-purple"></i>
        </div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #c084fc;"><?= $ctr ?>%</div>
        <div class="text-xs text-muted">Click-through rate</div>
    </div>
</div>

<!-- Advertisements Table -->
<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title"><i class="ti ti-list mr-2 text-warning"></i> All Platform Campaigns</h3>
        <span class="text-xs text-muted">Placement priorities dictate display order</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title / Advertiser</th>
                        <th>Placement Slot</th>
                        <th>Type</th>
                        <th>Target Plans</th>
                        <th>Priority</th>
                        <th>Telemetry</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ads)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="ti ti-ad-off fs-3 d-block mb-2"></i>
                            No advertisements configured yet. Click "New Advertisement" to create one.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($ads as $ad): 
                            $hasCode = !empty($ad['ad_code']);
                            $isActive = !empty($ad['is_active']);
                            $adImpressions = (int)($ad['impressions_count'] ?? 0);
                            $adClicks = (int)($ad['clicks_count'] ?? 0);
                            $adCtr = $adImpressions > 0 ? round(($adClicks / $adImpressions) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td>
                                <div class="font-bold text-sm text-light"><?= e($ad['title']) ?></div>
                                <div class="text-xs text-muted"><?= e($ad['company_name'] ?: 'Direct Platform Campaign') ?></div>
                            </td>
                            <td>
                                <span class="badge badge-subtle font-mono text-xs"><?= e($ad['placement']) ?></span>
                            </td>
                            <td>
                                <?php if ($hasCode): ?>
                                    <span class="badge" style="background: rgba(168, 85, 247, 0.2); color: #c084fc; font-size: 0.7rem;">
                                        <i class="ti ti-code mr-1"></i> HTML/JS Code
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; font-size: 0.7rem;">
                                        <i class="ti ti-photo mr-1"></i> Banner Image
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-xs text-muted text-uppercase"><?= e($ad['target_plans'] ?: 'all') ?></span>
                            </td>
                            <td>
                                <span class="badge badge-subtle font-mono"><?= (int)($ad['priority'] ?? 0) ?></span>
                            </td>
                            <td>
                                <div class="text-xs font-mono">
                                    <span title="Impressions"><i class="ti ti-eye text-primary"></i> <?= number_format($adImpressions) ?></span>
                                    <span class="mx-1">/</span>
                                    <span title="Clicks"><i class="ti ti-cursor-text text-warning"></i> <?= number_format($adClicks) ?></span>
                                    <span class="text-muted ml-1">(<?= $adCtr ?>%)</span>
                                </div>
                            </td>
                            <td>
                                <form method="POST" action="<?= url('admin/advertisements.php') ?>" style="display: inline;">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="ad_id" value="<?= $ad['id'] ?>">
                                    <button type="submit" class="btn btn-xs badge badge-<?= $isActive ? 'success' : 'danger' ?>" style="cursor: pointer; border: none;">
                                        <?= $isActive ? 'Active' : 'Inactive' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-right">
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="btn btn-secondary btn-xs" onclick="previewAd(<?= (int)$ad['id'] ?>)" title="Preview Ad">
                                        <i class="ti ti-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-xs" onclick="editAd(<?= (int)$ad['id'] ?>)" title="Edit Campaign">
                                        <i class="ti ti-pencil"></i>
                                    </button>
                                    <form method="POST" action="<?= url('admin/advertisements.php') ?>" style="display: inline;" onsubmit="return confirm('Are you sure you want to permanently delete this advertisement campaign?');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete_ad">
                                        <input type="hidden" name="ad_id" value="<?= $ad['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-xs" title="Delete">
                                            <i class="ti ti-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Create / Edit Advertisement -->
<div class="modal-backdrop" id="ad-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-card" style="background: #1e293b; border: 1px solid #334155; border-radius: 12px; width: 100%; max-width: 650px; max-height: 90vh; overflow-y: auto; padding: 1.5rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);">
        <div class="d-flex justify-between align-center mb-3 pb-2" style="border-bottom: 1px solid #334155;">
            <h3 class="modal-title" id="modal-title" style="margin: 0; font-size: 1.2rem; font-weight: 700; color: #f8fafc;">
                <i class="ti ti-ad mr-2 text-warning"></i> Create Advertisement
            </h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close onclick="closeAdModal()">&times;</button>
        </div>
        <form method="POST" action="<?= url('admin/advertisements.php') ?>" id="ad-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" id="form-action" value="create_ad">
            <input type="hidden" name="ad_id" id="ad_id" value="0">

            <div class="grid grid-cols-2 gap-3 mb-3">
                <div class="form-group">
                    <label class="form-label required">Campaign Title / Name</label>
                    <input type="text" name="title" id="title" class="form-input" placeholder="e.g. Mobile Spare Parts Mega Sale" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Advertiser / Company Name</label>
                    <input type="text" name="company_name" id="company_name" class="form-input" placeholder="e.g. Apex Tech Ltd.">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 mb-3">
                <div class="form-group">
                    <label class="form-label required">Placement Slot</label>
                    <select name="placement" id="placement" class="form-input" required>
                        <option value="dashboard_top">Dashboard Top Banner (dashboard_top)</option>
                        <option value="dashboard_content">Dashboard Content Section (dashboard_content)</option>
                        <option value="footer">Footer Banner (footer)</option>
                        <option value="sidebar_bottom">Sidebar Bottom Box (sidebar_bottom)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Target Subscription Plans</label>
                    <select name="target_plans" id="target_plans" class="form-input">
                        <option value="all">All Plans (Universal)</option>
                        <option value="free">Free / Trial Only (Ad-Supported)</option>
                        <option value="free,standard">Free & Standard Plans</option>
                        <option value="standard,professional">Paid Plans Only</option>
                    </select>
                </div>
            </div>

            <!-- Ad Type Selection -->
            <div class="mb-3 p-3 rounded" style="background: rgba(15, 23, 42, 0.5); border: 1px solid #334155;">
                <label class="form-label fw-bold mb-2">Campaign Content Mode</label>
                <div class="d-flex gap-4 mb-2">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 0.4rem;">
                        <input type="radio" name="ad_mode" value="banner" id="mode-banner" checked onchange="toggleAdMode()">
                        <span>Banner Image + Target Link</span>
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 0.4rem;">
                        <input type="radio" name="ad_mode" value="code" id="mode-code" onchange="toggleAdMode()">
                        <span>HTML / JavaScript Ad Network Code</span>
                    </label>
                </div>

                <!-- Banner Fields -->
                <div id="fields-banner">
                    <div class="form-group mb-2">
                        <label class="form-label">Banner Image URL</label>
                        <input type="url" name="image_url" id="image_url" class="form-input font-mono" placeholder="https://example.com/banner.png">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Destination URL</label>
                        <input type="url" name="target_url" id="target_url" class="form-input font-mono" placeholder="https://advertiser-website.com">
                    </div>
                </div>

                <!-- Code Fields -->
                <div id="fields-code" style="display: none;">
                    <div class="form-group">
                        <label class="form-label">HTML / JavaScript Snippet</label>
                        <textarea name="ad_code" id="ad_code" class="form-input font-mono text-xs" rows="5" placeholder="Paste trusted ad network code (e.g. Google AdSense, ad network tag, custom HTML/JS banner)"></textarea>
                        <div class="text-xs text-muted mt-1">Super Admin trusted code only. Third-party scripts are executed only within designated frontend ad slots.</div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3 mb-3">
                <div class="form-group">
                    <label class="form-label">Display Priority (Higher = First)</label>
                    <input type="number" name="priority" id="priority" class="form-input font-mono" value="0" min="0" max="999">
                </div>
                <div class="form-group">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" id="start_date" class="form-input" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">End Date (Optional)</label>
                    <input type="date" name="end_date" id="end_date" class="form-input">
                </div>
            </div>

            <div class="form-group mb-4">
                <label style="cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
                    <input type="checkbox" name="is_active" id="is_active" value="1" checked>
                    <strong>Activate Campaign Immediately</strong>
                </label>
            </div>

            <div class="d-flex justify-end gap-2 pt-3" style="border-top: 1px solid #334155;">
                <button type="button" class="btn btn-secondary" data-modal-close onclick="closeAdModal()">Cancel</button>
                <button type="submit" class="btn btn-success" style="font-weight: 700;">
                    <i class="ti ti-check mr-1"></i> Save Advertisement
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Preview Advertisement -->
<div class="modal-backdrop" id="preview-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-card" style="background: #1e293b; border: 1px solid #334155; border-radius: 12px; width: 100%; max-width: 600px; padding: 1.5rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);">
        <div class="d-flex justify-between align-center mb-3 pb-2" style="border-bottom: 1px solid #334155;">
            <h3 class="modal-title" style="margin: 0; font-size: 1.15rem; font-weight: 700; color: #f8fafc;">
                <i class="ti ti-eye mr-2 text-primary"></i> Campaign Preview
            </h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close onclick="closePreviewModal()">&times;</button>
        </div>
        <div id="preview-render-box" style="padding: 1rem; background: #0f172a; border-radius: 8px; border: 1px dashed #334155; text-align: center; margin-bottom: 1rem;">
            <!-- Dynamic preview content -->
        </div>
        <div class="d-flex justify-end">
            <button type="button" class="btn btn-secondary" data-modal-close onclick="closePreviewModal()">Close Preview</button>
        </div>
    </div>
</div>

<!-- Embedded JSON Payload for Safe Client-Side Lookup (Zero Quoting Issues) -->
<script id="ads-data-payload" type="application/json">
<?= json_encode($ads, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>

<script>
const adsPayloadEl = document.getElementById('ads-data-payload');
const allAds = adsPayloadEl ? JSON.parse(adsPayloadEl.textContent || '[]') : [];

function getAdById(id) {
    return allAds.find(function(a) { return parseInt(a.id, 10) === parseInt(id, 10); }) || null;
}

function toggleAdMode() {
    const isCode = document.getElementById('mode-code').checked;
    document.getElementById('fields-banner').style.display = isCode ? 'none' : 'block';
    document.getElementById('fields-code').style.display = isCode ? 'block' : 'none';
}

function openCreateModal() {
    var modal = document.getElementById('ad-modal');
    if (!modal) return;
    document.getElementById('form-action').value = 'create_ad';
    document.getElementById('modal-title').innerHTML = '<i class="ti ti-ad mr-2 text-warning"></i> Create Advertisement';
    document.getElementById('ad-form').reset();
    document.getElementById('ad_id').value = '0';
    document.getElementById('mode-banner').checked = true;
    document.getElementById('is_active').checked = true;
    toggleAdMode();
    modal.classList.add('active');
    modal.style.display = 'flex';
}

function editAd(idOrAd) {
    const ad = (typeof idOrAd === 'object' && idOrAd !== null) ? idOrAd : getAdById(idOrAd);
    if (!ad) return;

    var modal = document.getElementById('ad-modal');
    if (!modal) return;

    document.getElementById('form-action').value = 'update_ad';
    document.getElementById('modal-title').innerHTML = '<i class="ti ti-pencil mr-2 text-warning"></i> Edit Advertisement #' + ad.id;
    document.getElementById('ad_id').value = ad.id;
    document.getElementById('title').value = ad.title || '';
    document.getElementById('company_name').value = ad.company_name || '';
    document.getElementById('placement').value = ad.placement || 'dashboard_top';
    document.getElementById('target_plans').value = ad.target_plans || 'all';
    document.getElementById('image_url').value = ad.image_url || '';
    document.getElementById('target_url').value = ad.target_url || '';
    document.getElementById('ad_code').value = ad.ad_code || '';
    document.getElementById('priority').value = ad.priority || 0;
    document.getElementById('start_date').value = ad.start_date || '';
    document.getElementById('end_date').value = ad.end_date || '';
    document.getElementById('is_active').checked = (parseInt(ad.is_active, 10) === 1);

    if (ad.ad_code && ad.ad_code.trim() !== '') {
        document.getElementById('mode-code').checked = true;
    } else {
        document.getElementById('mode-banner').checked = true;
    }
    toggleAdMode();
    modal.classList.add('active');
    modal.style.display = 'flex';
}

function closeAdModal() {
    var modal = document.getElementById('ad-modal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function previewAd(idOrAd) {
    const ad = (typeof idOrAd === 'object' && idOrAd !== null) ? idOrAd : getAdById(idOrAd);
    if (!ad) return;

    const box = document.getElementById('preview-render-box');
    box.innerHTML = '';

    if (ad.ad_code && ad.ad_code.trim() !== '') {
        box.innerHTML = '<div style="font-size:10px;color:#f59e0b;font-weight:bold;margin-bottom:6px;text-transform:uppercase;">Custom Script Code Ad Preview</div>' + ad.ad_code;
    } else if (ad.image_url) {
        box.innerHTML = '<div style="font-size:10px;color:#f59e0b;font-weight:bold;margin-bottom:6px;text-transform:uppercase;">Banner Preview (' + (ad.company_name || 'Sponsored') + ')</div>' +
                        '<a href="' + (ad.target_url || '#') + '" target="_blank" rel="noopener noreferrer" style="display:inline-block;"><img src="' + ad.image_url + '" style="max-width:100%;border-radius:6px;" alt="' + (ad.title || '') + '"></a>';
    } else {
        box.innerHTML = '<div class="text-muted">Text-only Ad: <strong>' + (ad.title || '') + '</strong></div>';
    }

    var prevModal = document.getElementById('preview-modal');
    if (prevModal) {
        prevModal.classList.add('active');
        prevModal.style.display = 'flex';
    }
}

function closePreviewModal() {
    var prevModal = document.getElementById('preview-modal');
    if (prevModal) {
        prevModal.classList.remove('active');
        prevModal.style.display = 'none';
    }
}

// Bind DOM event handlers
document.addEventListener('DOMContentLoaded', function() {
    var newAdBtn = document.getElementById('btn-new-ad');
    if (newAdBtn) {
        newAdBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openCreateModal();
        });
    }

    document.querySelectorAll('[data-modal-target="ad-modal"]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            openCreateModal();
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            closeAdModal();
            closePreviewModal();
        });
    });

    var adModal = document.getElementById('ad-modal');
    if (adModal) {
        adModal.addEventListener('click', function(e) {
            if (e.target === adModal) closeAdModal();
        });
    }

    var prevModal = document.getElementById('preview-modal');
    if (prevModal) {
        prevModal.addEventListener('click', function(e) {
            if (e.target === prevModal) closePreviewModal();
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAdModal();
            closePreviewModal();
        }
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
