<?php
/**
 * YBT Management Software — Main Analytics Dashboard
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/report-service.php';

Auth::requireLogin();
Permissions::require('dashboard.view');

$businessId = Auth::businessId();
$exec = ReportService::getExecutiveSummary($businessId);
$monthlyTrends = ReportService::getMonthlyTrends($businessId, 6);

// 1. Core Shop & Customer Statistics (Strictly Tenant-Isolated)
$totalCustomers = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM customers WHERE business_id = ?",
    [$businessId]
);

$totalProducts = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM products WHERE business_id = ?",
    [$businessId]
);

$prodCols = Database::getTableColumns('products');
$stockCol = isset($prodCols['stock_quantity']) ? 'stock_quantity' : (isset($prodCols['current_stock']) ? 'current_stock' : (isset($prodCols['quantity']) ? 'quantity' : null));
$totalStockQty = $stockCol ? (float)Database::fetchColumn(
    "SELECT COALESCE(SUM(`{$stockCol}`), 0) FROM products WHERE business_id = ?",
    [$businessId]
) : 0.0;

// 2. Real Customer Ledger Financials (Strictly respects debit/credit accounting)
// Customer Receivables: Sum of money customers owe the shop (current_balance > 0)
$totalReceivables = (float)Database::fetchColumn(
    "SELECT COALESCE(SUM(current_balance), 0) FROM customers WHERE business_id = ? AND current_balance > 0",
    [$businessId]
);
$debtorCustomersCount = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM customers WHERE business_id = ? AND current_balance > 0",
    [$businessId]
);

// Customer Payables: Sum of customer advances/credits (current_balance < 0)
$totalPayables = (float)Database::fetchColumn(
    "SELECT COALESCE(ABS(SUM(current_balance)), 0) FROM customers WHERE business_id = ? AND current_balance < 0",
    [$businessId]
);
$advanceCustomersCount = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM customers WHERE business_id = ? AND current_balance < 0",
    [$businessId]
);

// Net Customer Balance Position
$netKhataBalance = $totalReceivables - $totalPayables;

// 3. Khata Direct Entry Totals (You Gave & You Got)
$youGaveStats = Database::fetch(
    "SELECT COUNT(*) as count, COALESCE(SUM(debit), 0) as total 
     FROM customer_ledger 
     WHERE business_id = ? AND entry_type = 'you_gave'",
    [$businessId]
) ?: ['count' => 0, 'total' => 0.0];

$youGotStats = Database::fetch(
    "SELECT COUNT(*) as count, COALESCE(SUM(credit), 0) as total 
     FROM customer_ledger 
     WHERE business_id = ? AND entry_type = 'you_got'",
    [$businessId]
) ?: ['count' => 0, 'total' => 0.0];

// 4. Recent Live Khata Transactions
$recentKhataEntries = Database::fetchAll(
    "SELECT cl.*, c.name as customer_name, c.phone as customer_phone 
     FROM customer_ledger cl
     JOIN customers c ON cl.customer_id = c.id
     WHERE cl.business_id = ?
     ORDER BY cl.entry_date DESC, cl.id DESC LIMIT 6",
    [$businessId]
);

// Overdue Invoices
$overdueInvoices = Database::fetchAll(
    "SELECT i.*, 
            i.total_amount as grand_total, 
            i.paid_amount as amount_paid, 
            i.balance_amount as outstanding, 
            i.issue_date as invoice_date, 
            c.name as customer_name, c.company_name
     FROM invoices i
     JOIN customers c ON i.customer_id = c.id
     WHERE i.business_id = ? AND (i.status = 'overdue' OR (i.status IN ('issued', 'partially_paid', 'sent', 'partial') AND i.due_date < CURDATE()))
     ORDER BY i.due_date ASC LIMIT 5",
    [$businessId]
);

// Pending Tasks
$tasks = Database::fetchAll(
    "SELECT * FROM tasks 
     WHERE business_id = ? AND status != 'completed' 
     ORDER BY (priority = 'urgent') DESC, (priority = 'high') DESC, due_date ASC LIMIT 5",
    [$businessId]
);

// Recent Invoices
$recentInvoices = Database::fetchAll(
    "SELECT i.*, 
            i.total_amount as grand_total, 
            i.paid_amount as amount_paid, 
            i.balance_amount as outstanding, 
            i.issue_date as invoice_date, 
            c.name as customer_name, c.company_name
     FROM invoices i
     JOIN customers c ON i.customer_id = c.id
     WHERE i.business_id = ?
     ORDER BY i.created_at DESC LIMIT 5",
    [$businessId]
);

// Invoice status counts for doughnut chart
$invStatuses = Database::fetchAll(
    "SELECT status, COUNT(*) as cnt FROM invoices WHERE business_id = ? GROUP BY status",
    [$businessId]
);
$statusDist = [];
foreach ($invStatuses as $st) {
    $statusDist[$st['status']] = (int)$st['cnt'];
}

// Recent activities
$activities = Database::fetchAll(
    "SELECT a.*, u.name as user_name 
     FROM activity_logs a 
     LEFT JOIN users u ON a.user_id = u.id 
     WHERE a.business_id = ? 
     ORDER BY a.created_at DESC LIMIT 6",
    [$businessId]
);

$pageTitle = 'Executive Dashboard';
$pageScripts = ['dashboard.js'];
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <h1 class="page-title">Executive Dashboard</h1>
        <p class="page-subtitle">Welcome back, <?= e(Auth::name()) ?>! Here is your business performance summary.</p>
    </div>
    <div class="page-header-actions d-flex gap-2">
        <a href="<?= url('ai-assistant.php') ?>" class="btn btn-primary btn-outline">
            <i class="ti ti-sparkles mr-1"></i> Ask AI Manager
        </a>
        <a href="<?= url('invoice-create.php') ?>" class="btn btn-primary">
            <i class="ti ti-plus mr-1"></i> New Invoice
        </a>
    </div>
</div>

<?php
// Query active platform announcements
$activeAnnouncements = [];
try {
    $activeAnnouncements = Database::fetchAll(
        "SELECT * FROM platform_announcements WHERE is_active = 1 AND (starts_at IS NULL OR starts_at = '' OR starts_at <= CURDATE()) AND (expires_at IS NULL OR expires_at = '' OR expires_at >= CURDATE()) ORDER BY id DESC LIMIT 3"
    );
} catch (\Throwable $e) {}
?>
<?php if (!empty($activeAnnouncements)): ?>
<div class="announcements-container mb-4">
    <?php foreach ($activeAnnouncements as $ann): 
        $alertType = $ann['type'] ?? 'info';
        $alertClass = match($alertType) {
            'warning' => 'alert-warning',
            'danger' => 'alert-danger',
            'success' => 'alert-success',
            default => 'alert-info'
        };
        $iconClass = match($alertType) {
            'warning' => 'ti-alert-triangle',
            'danger' => 'ti-alert-octagon',
            'success' => 'ti-circle-check',
            default => 'ti-info-circle'
        };
    ?>
    <div class="alert <?= $alertClass ?> d-flex align-start gap-3 p-3 rounded mb-2 shadow-sm" style="border-left: 4px solid var(--color-primary);">
        <i class="ti <?= $iconClass ?> fs-4 mt-1"></i>
        <div class="flex-1">
            <strong class="d-block mb-1 font-semibold"><?= e($ann['title']) ?></strong>
            <div class="text-sm text-secondary"><?= nl2br(e($ann['content'])) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Section: Customer Khata Financial Overview -->
<div class="mb-4">
    <div class="d-flex justify-between align-center mb-2">
        <h3 class="text-sm font-bold text-muted uppercase tracking-wider mb-0">
            <i class="ti ti-scale mr-1 text-primary"></i> Customer Khata & Balances
        </h3>
        <span class="text-xs text-muted">Real-time debit & credit balances across all customers</span>
    </div>
    <div class="kpi-grid">
        <!-- Khata 1: Receivables -->
        <div class="kpi-card card-glow-danger" style="border-top: 3px solid #ef4444;">
            <div class="kpi-card-header">
                <span class="kpi-title font-semibold text-danger">Customers Owe You (Receivable)</span>
                <div class="kpi-icon-badge" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;">
                    <i class="ti ti-arrow-up-right"></i>
                </div>
            </div>
            <div class="kpi-value text-danger"><?= formatCurrency($totalReceivables) ?></div>
            <div class="kpi-footer text-xs">
                <span class="text-muted"><?= $debtorCustomersCount ?> customer(s) with outstanding dues</span>
            </div>
        </div>

        <!-- Khata 2: Payables (Customer Advance) -->
        <div class="kpi-card card-glow-success" style="border-top: 3px solid #10b981;">
            <div class="kpi-card-header">
                <span class="kpi-title font-semibold text-success">Customer Advance (Payable)</span>
                <div class="kpi-icon-badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                    <i class="ti ti-arrow-down-left"></i>
                </div>
            </div>
            <div class="kpi-value text-success"><?= formatCurrency($totalPayables) ?></div>
            <div class="kpi-footer text-xs">
                <span class="text-muted"><?= $advanceCustomersCount ?> customer(s) with credit advances</span>
            </div>
        </div>

        <!-- Khata 3: Total You Gave -->
        <div class="kpi-card card-glow-warning" style="border-top: 3px solid #f59e0b;">
            <div class="kpi-card-header">
                <span class="kpi-title font-semibold">Total You Gave</span>
                <div class="kpi-icon-badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                    <i class="ti ti-receipt-2"></i>
                </div>
            </div>
            <div class="kpi-value"><?= formatCurrency($youGaveStats['total']) ?></div>
            <div class="kpi-footer text-xs">
                <span class="text-muted"><?= $youGaveStats['count'] ?> direct debit transaction(s)</span>
            </div>
        </div>

        <!-- Khata 4: Total You Got -->
        <div class="kpi-card card-glow-info" style="border-top: 3px solid #3b82f6;">
            <div class="kpi-card-header">
                <span class="kpi-title font-semibold">Total You Got</span>
                <div class="kpi-icon-badge" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">
                    <i class="ti ti-cash"></i>
                </div>
            </div>
            <div class="kpi-value text-info"><?= formatCurrency($youGotStats['total']) ?></div>
            <div class="kpi-footer text-xs">
                <span class="text-muted"><?= $youGotStats['count'] ?> direct collection(s) recorded</span>
            </div>
        </div>
    </div>
</div>

<!-- Section: Operations & Invoicing Financials -->
<div class="kpi-grid mb-4">
    <!-- Card 1: Revenue -->
    <div class="kpi-card card-glow-primary">
        <div class="kpi-card-header">
            <span class="kpi-title">Invoiced Collections</span>
            <div class="kpi-icon-badge bg-primary-subtle text-primary">
                <i class="ti ti-receipt"></i>
            </div>
        </div>
        <div class="kpi-value"><?= formatCurrency($exec['total_collected']) ?></div>
        <div class="kpi-footer text-xs">
            <span class="text-success"><i class="ti ti-trending-up"></i> Invoiced: <?= formatCurrency($exec['total_invoiced']) ?></span>
        </div>
    </div>

    <!-- Card 2: Receivables -->
    <div class="kpi-card card-glow-warning">
        <div class="kpi-card-header">
            <span class="kpi-title">Invoice Receivables</span>
            <div class="kpi-icon-badge bg-warning-subtle text-warning">
                <i class="ti ti-clock-dollar"></i>
            </div>
        </div>
        <div class="kpi-value text-warning"><?= formatCurrency($exec['total_outstanding']) ?></div>
        <div class="kpi-footer text-xs">
            <span class="text-muted"><?= count($overdueInvoices) ?> invoice(s) need attention</span>
        </div>
    </div>

    <!-- Card 3: Customers -->
    <div class="kpi-card card-glow-secondary">
        <div class="kpi-card-header">
            <span class="kpi-title">Total Customers</span>
            <div class="kpi-icon-badge bg-secondary-subtle text-secondary">
                <i class="ti ti-users"></i>
            </div>
        </div>
        <div class="kpi-value text-secondary"><?= number_format($totalCustomers) ?></div>
        <div class="kpi-footer text-xs">
            <a href="<?= url('customers.php') ?>" class="text-primary font-medium">Manage Customer Khatas &rarr;</a>
        </div>
    </div>

    <!-- Card 4: Inventory Items -->
    <div class="kpi-card card-glow-success">
        <div class="kpi-card-header">
            <span class="kpi-title">Products & Inventory</span>
            <div class="kpi-icon-badge bg-success-subtle text-success">
                <i class="ti ti-package"></i>
            </div>
        </div>
        <div class="kpi-value text-success"><?= number_format($totalProducts) ?></div>
        <div class="kpi-footer text-xs">
            <span class="text-muted"><?= number_format($totalStockQty) ?> total units in stock</span>
        </div>
    </div>
</div>

<!-- Charts Section -->
<div class="grid grid-cols-3 gap-4 mb-4">
    <div class="card col-span-2">
        <div class="card-header d-flex justify-between align-center">
            <h3 class="card-title"><i class="ti ti-chart-line mr-1 text-primary"></i> 6-Month Revenue & Expense Trend</h3>
            <span class="badge badge-subtle">Monthly Financials</span>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height: 280px; position: relative;">
                <canvas id="revenueTrendChart"></canvas>
            </div>
        </div>
    </div>

    <div class="card col-span-1">
        <div class="card-header d-flex justify-between align-center">
            <h3 class="card-title"><i class="ti ti-chart-donut mr-1 text-info"></i> Invoice Status</h3>
            <span class="badge badge-subtle">Distribution</span>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height: 280px; position: relative;">
                <canvas id="invoiceStatusChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Tables & Activity Feed Grid -->
<div class="grid grid-cols-3 gap-4">
    <!-- Left Column: Overdue Invoices & Pending Tasks (col-span-2) -->
    <div class="col-span-2 d-flex flex-column gap-4">
                <!-- Live Customer Khata Activity Stream -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-between align-center">
                <h3 class="card-title">
                    <i class="ti ti-notebook mr-1 text-primary"></i> Recent Customer Khata Transactions
                </h3>
                <a href="<?= url('customers.php') ?>" class="text-sm">View All Customers &rarr;</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentKhataEntries)): ?>
                    <div class="empty-state p-4 text-center">
                        <i class="ti ti-book text-muted text-2xl mb-1"></i>
                        <p class="text-muted text-sm">No Khata transactions recorded yet. Use "You Gave" or "You Got" on any customer profile to initiate direct ledger entries.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Transaction Type</th>
                                    <th>Description</th>
                                    <th class="text-right">Amount</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentKhataEntries as $entry): 
                                    $isDebit = (float)$entry['debit'] > 0;
                                    $amount = $isDebit ? (float)$entry['debit'] : (float)$entry['credit'];
                                    $typeLabel = match($entry['entry_type']) {
                                        'you_gave' => 'You Gave',
                                        'you_got' => 'You Got',
                                        'invoice' => 'Invoice',
                                        'payment' => 'Payment',
                                        'opening_balance' => 'Opening Bal',
                                        default => ucfirst(str_replace('_', ' ', $entry['entry_type']))
                                    };
                                    $typeBadge = $isDebit ? 'badge-danger' : 'badge-success';
                                ?>
                                <tr>
                                    <td class="text-sm"><?= formatDate($entry['entry_date']) ?></td>
                                    <td>
                                        <a href="<?= url('customer-view.php?id=' . $entry['customer_id'] . '&tab=khata') ?>" class="font-bold">
                                            <?= e($entry['customer_name']) ?>
                                        </a>
                                        <?php if (!empty($entry['customer_phone'])): ?>
                                            <div class="text-xs text-muted"><?= e($entry['customer_phone']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $typeBadge ?>">
                                            <i class="ti <?= $isDebit ? 'ti-arrow-up-right' : 'ti-arrow-down-left' ?> mr-1"></i>
                                            <?= $typeLabel ?>
                                        </span>
                                    </td>
                                    <td class="text-sm">
                                        <?= e($entry['description'] ?: 'Khata Entry') ?>
                                    </td>
                                    <td class="text-right font-bold <?= $isDebit ? 'text-danger' : 'text-success' ?>">
                                        <?= $isDebit ? '+ ' : '- ' ?><?= formatCurrency($amount) ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="<?= url('customer-view.php?id=' . $entry['customer_id'] . '&tab=khata') ?>" class="btn btn-sm btn-ghost" title="View Customer Khata">
                                            <i class="ti ti-arrow-right"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Overdue Invoices Alert Table -->
        <div class="card">
            <div class="card-header d-flex justify-between align-center">
                <h3 class="card-title text-danger">
                    <i class="ti ti-alert-triangle mr-1"></i> Invoices Requiring Follow-Up
                </h3>
                <a href="<?= url('invoices.php?status=overdue') ?>" class="text-sm">View All Invoices &rarr;</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($overdueInvoices)): ?>
                    <div class="empty-state p-4 text-center">
                        <i class="ti ti-circle-check text-success text-2xl mb-1"></i>
                        <p class="text-muted text-sm">No overdue invoices found! All payments are up to date.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Customer</th>
                                    <th>Due Date</th>
                                    <th class="text-right">Balance Due</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($overdueInvoices as $inv): ?>
                                <tr>
                                    <td>
                                        <a href="<?= url('invoice-view.php?id=' . $inv['id']) ?>" class="font-bold">
                                            <?= e($inv['invoice_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div><strong><?= e($inv['customer_name']) ?></strong></div>
                                        <div class="text-xs text-muted"><?= e($inv['company_name'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <span class="text-danger font-medium"><?= formatDate($inv['due_date']) ?></span>
                                    </td>
                                    <td class="text-right font-bold text-danger">
                                        <?= formatCurrency($inv['outstanding'] ?? $inv['balance_amount'] ?? 0) ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="<?= url('invoice-view.php?id=' . $inv['id']) ?>" class="btn btn-sm btn-ghost" title="View details">
                                            <i class="ti ti-arrow-right"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pending Tasks Table -->
        <div class="card">
            <div class="card-header d-flex justify-between align-center">
                <h3 class="card-title"><i class="ti ti-checklist mr-1 text-purple"></i> High Priority Tasks</h3>
                <a href="<?= url('tasks.php') ?>" class="text-sm">Manage All Tasks &rarr;</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($tasks)): ?>
                    <div class="empty-state p-4 text-center">
                        <p class="text-muted text-sm">No pending tasks. You're all caught up!</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Task</th>
                                    <th>Priority</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $t): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($t['title']) ?></strong>
                                        <div class="text-xs text-muted"><?= e(truncate($t['description'] ?? '', 50)) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $t['priority'] === 'urgent' ? 'danger' : ($t['priority'] === 'high' ? 'warning' : 'info') ?>">
                                            <?= ucfirst($t['priority']) ?>
                                        </span>
                                    </td>
                                    <td><?= formatDate($t['due_date']) ?></td>
                                    <td>
                                        <span class="badge badge-subtle"><?= ucfirst(str_replace('_', ' ', $t['status'])) ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Recent Activities (col-span-1) -->
    <div class="col-span-1">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-activity mr-1 text-info"></i> Audit Trail & Activity</h3>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <?php if (empty($activities)): ?>
                        <p class="text-muted text-sm text-center">No recent activity logged.</p>
                    <?php else: ?>
                        <?php foreach ($activities as $act): ?>
                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <div class="timeline-content">
                                <div class="timeline-text text-sm">
                                    <?= e($act['details'] ?? $act['action']) ?>
                                </div>
                                <div class="timeline-time text-xs text-muted">
                                    <?= e($act['user_name'] ?: 'System') ?> &bull; <?= timeAgo($act['created_at']) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.DashboardCharts) {
        DashboardCharts.init({
            monthlyRevenue: <?= json_encode($monthlyTrends) ?>,
            invoiceDistribution: <?= json_encode($statusDist) ?>
        });
    }
});
</script>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
