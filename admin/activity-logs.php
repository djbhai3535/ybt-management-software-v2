<?php
/**
 * DJ CRM Software — Platform Central Activity & Audit Logs
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/auth-check.php';

$pageTitle = 'Platform Activity & Security Audit Logs';

$selectedBiz = isset($_GET['business_id']) && $_GET['business_id'] !== '' ? (int)$_GET['business_id'] : null;
$selectedAction = trim($_GET['action_type'] ?? '');
$searchQuery = trim($_GET['q'] ?? '');

$params = [];
$whereClauses = ["1=1"];

if ($selectedBiz) {
    $whereClauses[] = "a.business_id = ?";
    $params[] = $selectedBiz;
}
if (!empty($selectedAction)) {
    $whereClauses[] = "a.action = ?";
    $params[] = $selectedAction;
}
if (!empty($searchQuery)) {
    $whereClauses[] = "(a.details LIKE ? OR a.ip_address LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $whereClauses);

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$totalCount = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM activity_logs a 
     LEFT JOIN users u ON a.user_id = u.id 
     WHERE {$whereSql}",
    $params
);

$logs = Database::fetchAll(
    "SELECT a.*, b.name as business_name, u.name as user_name, u.email as user_email 
     FROM activity_logs a 
     LEFT JOIN businesses b ON a.business_id = b.id 
     LEFT JOIN users u ON a.user_id = u.id 
     WHERE {$whereSql} 
     ORDER BY a.id DESC 
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$businesses = Database::fetchAll("SELECT id, name FROM businesses ORDER BY name ASC");
$actionTypes = Database::fetchAll("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC");

$totalPages = ceil($totalCount / $perPage);

require __DIR__ . '/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0;">Platform Audit & Activity Logs</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
            Central security audit trail tracking user authentication, financial transactions, settings updates, and system events.
        </p>
    </div>
</div>

<!-- Filters Card -->
<div class="card p-3 mb-4">
    <form method="GET" action="<?= url('admin/activity-logs.php') ?>" class="grid grid-cols-4 gap-3 align-end">
        <div class="form-group">
            <label class="form-label text-xs uppercase font-bold text-muted">Shop / Tenant</label>
            <select name="business_id" class="form-select">
                <option value="">— All Shops & Platform —</option>
                <?php foreach ($businesses as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $selectedBiz === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= e($b['name']) ?> (#<?= $b['id'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label text-xs uppercase font-bold text-muted">Action Type</label>
            <select name="action_type" class="form-select">
                <option value="">— All Actions —</option>
                <?php foreach ($actionTypes as $act): ?>
                    <option value="<?= e($act['action']) ?>" <?= $selectedAction === $act['action'] ? 'selected' : '' ?>>
                        <?= e($act['action']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label text-xs uppercase font-bold text-muted">Search Text / IP / User</label>
            <input type="text" name="q" class="form-input" placeholder="e.g. login, 127.0.0.1, admin..." value="<?= e($searchQuery) ?>">
        </div>

        <div class="form-group d-flex gap-2">
            <button type="submit" class="btn btn-primary" style="flex: 1;">
                <i class="ti ti-filter mr-1"></i> Apply Filters
            </button>
            <a href="<?= url('admin/activity-logs.php') ?>" class="btn btn-secondary" title="Reset">
                <i class="ti ti-x"></i>
            </a>
        </div>
    </form>
</div>

<!-- Logs Table -->
<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title">Activity Trail (<?= number_format($totalCount) ?> Events)</h3>
        <span class="text-muted text-xs">Page <?= $page ?> of <?= max(1, $totalPages) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="empty-state p-5 text-center text-muted">
                <i class="ti ti-list-search text-4xl mb-2"></i>
                <h3>No Activity Logs Found</h3>
                <p class="text-sm">No actions recorded matching the current filter criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Shop / Tenant</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Target Entity</th>
                            <th>IP & Client</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-xs text-muted" style="white-space: nowrap;">
                                <?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?>
                            </td>
                            <td>
                                <strong><?= e($log['business_name'] ?: 'Platform (' . $log['business_id'] . ')') ?></strong>
                            </td>
                            <td>
                                <?php if (!empty($log['user_name'])): ?>
                                    <div><strong><?= e($log['user_name']) ?></strong></div>
                                    <div class="text-muted text-xs"><?= e($log['user_email']) ?></div>
                                <?php else: ?>
                                    <span class="text-muted">System / Guest</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= strpos($log['action'], 'fail') !== false || strpos($log['action'], 'delete') !== false ? 'danger' : (strpos($log['action'], 'login') !== false ? 'info' : 'secondary') ?>">
                                    <?= e($log['action']) ?>
                                </span>
                            </td>
                            <td class="text-xs">
                                <?php if (!empty($log['entity_type'])): ?>
                                    <?= e($log['entity_type']) ?> #<?= e($log['entity_id'] ?? '—') ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs" style="font-family: monospace;">
                                <?= e($log['ip_address'] ?: '—') ?>
                            </td>
                            <td class="text-xs" style="max-width: 300px; word-break: break-all;">
                                <?= e($log['details'] ?: '—') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <?php if ($totalPages > 1): ?>
            <div class="d-flex justify-between align-center p-3 border-top">
                <div>Showing <?= $offset + 1 ?> to <?= min($totalCount, $offset + $perPage) ?> of <?= $totalCount ?> entries</div>
                <div class="d-flex gap-1">
                    <?php if ($page > 1): ?>
                        <a href="<?= url('admin/activity-logs.php?page=' . ($page - 1) . '&business_id=' . $selectedBiz . '&action_type=' . urlencode($selectedAction) . '&q=' . urlencode($searchQuery)) ?>" class="btn btn-secondary btn-xs">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= url('admin/activity-logs.php?page=' . ($page + 1) . '&business_id=' . $selectedBiz . '&action_type=' . urlencode($selectedAction) . '&q=' . urlencode($searchQuery)) ?>" class="btn btn-secondary btn-xs">Next</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
