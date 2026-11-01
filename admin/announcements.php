<?php
/**
 * YBT Management Software — Platform Announcements Manager
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';

$pageTitle = 'Platform Announcements & Broadcasts';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Security token expired. Please refresh and try again.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_announcement') {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $type = in_array($_POST['type'] ?? '', ['info', 'warning', 'danger', 'success'], true) ? $_POST['type'] : 'info';
            $startsAt = !empty($_POST['starts_at']) ? $_POST['starts_at'] : date('Y-m-d');
            $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

            if (empty($title) || empty($content)) {
                Flash::error('Title and announcement content are required.');
            } else {
                Database::insert('platform_announcements', [
                    'title' => $title,
                    'content' => $content,
                    'type' => $type,
                    'target' => 'all',
                    'is_active' => 1,
                    'created_by' => Auth::id(),
                    'starts_at' => $startsAt,
                    'expires_at' => $expiresAt
                ]);
                Flash::success("Announcement '{$title}' published successfully.");
                header('Location: ' . url('admin/announcements.php'));
                exit;
            }
        } elseif ($action === 'toggle_active') {
            $annId = (int)($_POST['announcement_id'] ?? 0);
            $newActive = (int)($_POST['is_active'] ?? 1);
            Database::query("UPDATE platform_announcements SET is_active = ? WHERE id = ?", [$newActive, $annId]);
            Flash::success("Announcement status updated.");
            header('Location: ' . url('admin/announcements.php'));
            exit;
        } elseif ($action === 'delete') {
            $annId = (int)($_POST['announcement_id'] ?? 0);
            Database::query("DELETE FROM platform_announcements WHERE id = ?", [$annId]);
            Flash::success("Announcement deleted.");
            header('Location: ' . url('admin/announcements.php'));
            exit;
        }
    }
}

// Fetch all announcements
$announcements = Database::fetchAll("SELECT * FROM platform_announcements ORDER BY id DESC");

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;">Platform Announcements</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
            Broadcast system notices, maintenance schedules, and important alerts across all shopkeeper dashboards.
        </p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('new-announcement-modal').style.display='flex'">
            <i class="ti ti-plus mr-1"></i> New Announcement
        </button>
    </div>
</div>

<!-- Announcements Table -->
<div class="card p-0 mb-4">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title & Content</th>
                    <th>Alert Type</th>
                    <th>Valid Dates</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($announcements)): ?>
                <tr>
                    <td colspan="5" class="text-center p-4 text-muted">
                        No announcements created yet. Click "New Announcement" to publish your first system broadcast.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($announcements as $ann): 
                        $isActive = (int)$ann['is_active'] === 1;
                        $badgeClass = match($ann['type']) {
                            'warning' => 'warning',
                            'danger' => 'danger',
                            'success' => 'success',
                            default => 'info'
                        };
                    ?>
                    <tr>
                        <td style="max-width: 450px;">
                            <div class="font-bold text-base"><?= e($ann['title']) ?></div>
                            <div class="text-sm text-muted mt-1"><?= nl2br(e($ann['content'])) ?></div>
                        </td>
                        <td>
                            <span class="badge badge-<?= $badgeClass ?>">
                                <?= strtoupper($ann['type']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="text-xs">From: <?= formatDate($ann['starts_at']) ?></div>
                            <div class="text-xs text-muted">Until: <?= !empty($ann['expires_at']) ? formatDate($ann['expires_at']) : 'No expiry' ?></div>
                        </td>
                        <td>
                            <span class="badge badge-<?= $isActive ? 'success' : 'subtle' ?>">
                                <?= $isActive ? 'ACTIVE' : 'INACTIVE' ?>
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="d-inline-flex gap-1">
                                <!-- Toggle Active -->
                                <form method="POST" action="<?= url('admin/announcements.php') ?>" style="display: inline;">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $isActive ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" title="<?= $isActive ? 'Deactivate' : 'Activate' ?>">
                                        <i class="ti <?= $isActive ? 'ti-eye-off' : 'ti-eye' ?>"></i>
                                    </button>
                                </form>

                                <!-- Delete -->
                                <form method="POST" action="<?= url('admin/announcements.php') ?>" style="display: inline;" onsubmit="return confirm('Delete this announcement permanently?');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="Delete">
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

<!-- Create Announcement Modal -->
<div id="new-announcement-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="width: 100%; max-width: 600px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);">
        <div class="card-header d-flex justify-between align-center">
            <h3 class="card-title"><i class="ti ti-speakerphone mr-2 text-warning"></i> Create Platform Announcement</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('new-announcement-modal').style.display='none'">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <form method="POST" action="<?= url('admin/announcements.php') ?>" class="card-body">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_announcement">

            <div class="form-group mb-3">
                <label class="form-label required">Announcement Title</label>
                <input type="text" name="title" class="form-input" placeholder="e.g. Scheduled System Maintenance on Sunday" required>
            </div>

            <div class="form-group mb-3">
                <label class="form-label required">Content / Message</label>
                <textarea name="content" class="form-input" rows="4" placeholder="Enter message details visible to all shopkeepers..." required></textarea>
            </div>

            <div class="grid grid-cols-3 gap-3 mb-4">
                <div class="form-group">
                    <label class="form-label">Alert Type</label>
                    <select name="type" class="form-select">
                        <option value="info">Info (Blue)</option>
                        <option value="warning">Warning (Yellow)</option>
                        <option value="danger">Critical (Red)</option>
                        <option value="success">Success (Green)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Starts Date</label>
                    <input type="date" name="starts_at" class="form-input" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Expires Date</label>
                    <input type="date" name="expires_at" class="form-input" placeholder="Optional">
                </div>
            </div>

            <div class="d-flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('new-announcement-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-send mr-1"></i> Publish Announcement
                </button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
