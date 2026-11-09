<?php
/**
 * DJ CRM Software — Platform Advertisements & Sponsorships Manager
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';

$pageTitle = 'Platform Advertisements & Monetization';

// Ensure advertisements table exists
try {
    Database::query("
        CREATE TABLE IF NOT EXISTS `advertisements` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `title` VARCHAR(255) NOT NULL,
          `placement` ENUM('dashboard_top', 'sidebar_bottom', 'login_banner') NOT NULL DEFAULT 'dashboard_top',
          `image_url` VARCHAR(500) DEFAULT NULL,
          `target_url` VARCHAR(500) DEFAULT NULL,
          `start_date` DATE DEFAULT NULL,
          `end_date` DATE DEFAULT NULL,
          `is_active` TINYINT(1) DEFAULT 1,
          `impressions_count` INT UNSIGNED DEFAULT 0,
          `clicks_count` INT UNSIGNED DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_ads_placement_active` (`placement`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (\Throwable $e) {}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired. Please refresh and try again.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_ad') {
            $title = trim($_POST['title'] ?? '');
            $placement = in_array($_POST['placement'] ?? '', ['dashboard_top', 'sidebar_bottom', 'login_banner'], true) ? $_POST['placement'] : 'dashboard_top';
            $imageUrl = trim($_POST['image_url'] ?? '');
            $targetUrl = trim($_POST['target_url'] ?? '');
            $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
            $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if (empty($title)) {
                Flash::error('Advertisement title is required.');
            } else {
                Database::insert('advertisements', [
                    'title' => $title,
                    'placement' => $placement,
                    'image_url' => $imageUrl ?: null,
                    'target_url' => $targetUrl ?: null,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_active' => $isActive,
                    'impressions_count' => 0,
                    'clicks_count' => 0
                ]);
                Flash::success("Advertisement '{$title}' created successfully.");
                header('Location: ' . url('admin/advertisements.php'));
                exit;
            }
        } elseif ($action === 'toggle_active') {
            $adId = (int)($_POST['ad_id'] ?? 0);
            $newActive = (int)($_POST['is_active'] ?? 1);
            Database::query("UPDATE advertisements SET is_active = ? WHERE id = ?", [$newActive, $adId]);
            Flash::success("Advertisement status updated.");
            header('Location: ' . url('admin/advertisements.php'));
            exit;
        } elseif ($action === 'delete') {
            $adId = (int)($_POST['ad_id'] ?? 0);
            Database::query("DELETE FROM advertisements WHERE id = ?", [$adId]);
            Flash::success("Advertisement removed permanently.");
            header('Location: ' . url('admin/advertisements.php'));
            exit;
        }
    }
}

// Fetch all advertisements
$ads = Database::fetchAll("SELECT * FROM advertisements ORDER BY id DESC");

// Metrics summary
$totalAds = count($ads);
$activeAds = 0;
$totalImpressions = 0;
$totalClicks = 0;
$today = date('Y-m-d');

foreach ($ads as $a) {
    if ((int)$a['is_active'] === 1 && (empty($a['end_date']) || $a['end_date'] >= $today)) {
        $activeAds++;
    }
    $totalImpressions += (int)$a['impressions_count'];
    $totalClicks += (int)$a['clicks_count'];
}
$avgCtr = $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0;

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;">Platform Advertisements</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
            Configure system sponsorships, promotional banners, and monetized placements across all tenant shop accounts.
        </p>
    </div>
    <div>
        <button type="button" class="btn btn-warning" data-modal-target="create-ad-modal">
            <i class="ti ti-plus mr-1"></i> New Advertisement
        </button>
    </div>
</div>

<!-- Metrics Cards -->
<div class="grid grid-cols-4 gap-4 mb-4">
    <div class="card p-3">
        <div class="text-muted text-xs font-bold uppercase mb-1">Total Campaigns</div>
        <div class="text-2xl font-bold"><?= $totalAds ?></div>
    </div>
    <div class="card p-3">
        <div class="text-muted text-xs font-bold uppercase mb-1">Active Placements</div>
        <div class="text-2xl font-bold text-success"><?= $activeAds ?></div>
    </div>
    <div class="card p-3">
        <div class="text-muted text-xs font-bold uppercase mb-1">Total Impressions</div>
        <div class="text-2xl font-bold text-primary"><?= number_format($totalImpressions) ?></div>
    </div>
    <div class="card p-3">
        <div class="text-muted text-xs font-bold uppercase mb-1">Total Clicks (CTR)</div>
        <div class="text-2xl font-bold text-warning"><?= number_format($totalClicks) ?> <span style="font-size: 0.85rem; color: var(--color-text-muted);">(<?= $avgCtr ?>%)</span></div>
    </div>
</div>

<!-- Ads Table -->
<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title">All Advertisements (<?= count($ads) ?>)</h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($ads)): ?>
            <div class="empty-state p-5 text-center">
                <i class="ti ti-speakerphone text-4xl text-muted mb-2"></i>
                <h3>No Advertisements Created Yet</h3>
                <p class="text-muted text-sm mb-3">Add your first promotional banner to display to shopkeepers on their dashboard.</p>
                <button type="button" class="btn btn-warning btn-sm" data-modal-target="create-ad-modal">
                    <i class="ti ti-plus mr-1"></i> Create First Advertisement
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title & Media</th>
                            <th>Placement</th>
                            <th>Target URL</th>
                            <th>Duration</th>
                            <th>Impressions</th>
                            <th>Clicks</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ads as $ad): ?>
                        <?php
                            $isExpired = !empty($ad['end_date']) && $ad['end_date'] < $today;
                            $isActive = (int)$ad['is_active'] === 1 && !$isExpired;
                        ?>
                        <tr>
                            <td>#<?= $ad['id'] ?></td>
                            <td>
                                <div class="d-flex align-center gap-2">
                                    <?php if (!empty($ad['image_url'])): ?>
                                        <img src="<?= e($ad['image_url']) ?>" alt="Preview" style="width: 44px; height: 32px; object-fit: cover; border-radius: 4px; border: 1px solid var(--color-border);">
                                    <?php endif; ?>
                                    <div class="font-bold"><?= e($ad['title']) ?></div>
                                </div>
                            </td>
                            <td>
                                <?php if ($ad['placement'] === 'dashboard_top'): ?>
                                    <span class="badge badge-primary">Dashboard Top</span>
                                <?php elseif ($ad['placement'] === 'sidebar_bottom'): ?>
                                    <span class="badge badge-info">Sidebar Bottom</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Login Banner</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($ad['target_url'])): ?>
                                    <a href="<?= e($ad['target_url']) ?>" target="_blank" rel="noopener noreferrer" class="text-primary text-xs" style="text-decoration: underline;">
                                        <?= e(substr($ad['target_url'], 0, 30)) ?>... <i class="ti ti-external-link"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs">
                                <div>From: <?= !empty($ad['start_date']) ? e($ad['start_date']) : 'Anytime' ?></div>
                                <div>To: <?= !empty($ad['end_date']) ? e($ad['end_date']) : 'Permanent' ?></div>
                            </td>
                            <td><strong><?= number_format((int)$ad['impressions_count']) ?></strong></td>
                            <td>
                                <strong><?= number_format((int)$ad['clicks_count']) ?></strong>
                                <?php if ((int)$ad['impressions_count'] > 0): ?>
                                    <span class="text-muted text-xs">(<?= round(((int)$ad['clicks_count'] / (int)$ad['impressions_count']) * 100, 1) ?>%)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isExpired): ?>
                                    <span class="badge badge-danger">Expired</span>
                                <?php elseif ($isActive): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Paused</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <div class="d-flex justify-end gap-1">
                                    <form method="POST" action="<?= url('admin/advertisements.php') ?>" style="display:inline;">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="ad_id" value="<?= $ad['id'] ?>">
                                        <input type="hidden" name="is_active" value="<?= (int)$ad['is_active'] === 1 ? 0 : 1 ?>">
                                        <button type="submit" class="btn btn-secondary btn-xs" title="<?= (int)$ad['is_active'] === 1 ? 'Pause Campaign' : 'Activate Campaign' ?>">
                                            <i class="ti ti-<?= (int)$ad['is_active'] === 1 ? 'player-pause' : 'player-play' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" action="<?= url('admin/advertisements.php') ?>" style="display:inline;" onsubmit="return confirm('Permanently delete this advertisement?');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="ad_id" value="<?= $ad['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-xs" title="Delete">
                                            <i class="ti ti-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create Advertisement Modal -->
<div class="modal-backdrop" id="create-ad-modal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title"><i class="ti ti-speakerphone mr-1 text-warning"></i> New Advertisement Campaign</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form action="<?= url('admin/advertisements.php') ?>" method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_ad">

            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label required">Campaign Title / Header</label>
                    <input type="text" name="title" class="form-input" placeholder="e.g. Special Offer: Thermal Receipt Printers 20% Off" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label required">Target Placement</label>
                    <select name="placement" class="form-select" required>
                        <option value="dashboard_top">Shopkeeper Dashboard (Top Banner)</option>
                        <option value="sidebar_bottom">Shopkeeper Navigation (Sidebar Bottom)</option>
                        <option value="login_banner">Login / Authentication Screen</option>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Banner Image URL (Optional)</label>
                    <input type="url" name="image_url" class="form-input" placeholder="https://example.com/banner.png or assets/img/banner.png">
                    <p class="form-help text-xs text-muted">Recommended dimensions: 728x90 for Top Banner, 300x150 for Sidebar.</p>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Target Link URL (Optional)</label>
                    <input type="url" name="target_url" class="form-input" placeholder="https://partnersite.com/deals">
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-input" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Date (Leave blank for permanent)</label>
                        <input type="date" name="end_date" class="form-input">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>Activate this campaign immediately upon publishing</span>
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-warning font-bold">Publish Advertisement</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
