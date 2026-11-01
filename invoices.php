<?php
/**
 * YBT Management Software — Invoices & Billing Directory
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/invoice-service.php';
require_once __DIR__ . '/services/whatsapp-service.php';

Auth::requireLogin();
Permissions::require('invoices.view');

$businessId = Auth::businessId();
$userId = Auth::id();

$business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);
if (!$business) {
    $business = ['name' => 'Mobile Shop', 'default_currency' => 'PKR', 'phone' => ''];
}

// Handle Void Invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'void_invoice') {
    Permissions::require('invoices.void');
    if (Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $invId = (int)$_POST['invoice_id'];
        InvoiceService::void($invId, $businessId, $userId);
        Flash::success('Invoice marked as void.');
        header('Location: ' . url('invoices.php'));
        exit;
    }
}

// Filters
$status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = ["i.business_id = ?"];
$params = [$businessId];

if ($status !== 'all') {
    if ($status === 'overdue') {
        $where[] = "(i.status = 'overdue' OR (i.status IN ('sent', 'partial') AND i.due_date < CURDATE()))";
    } else {
        $where[] = "i.status = ?";
        $params[] = $status;
    }
}

if ($search !== '') {
    $term = '%' . $search . '%';
    $where[] = "(i.invoice_number LIKE ? OR c.name LIKE ? OR c.company_name LIKE ?)";
    $params = array_merge($params, [$term, $term, $term]);
}

$whereClause = implode(' AND ', $where);
$invoices = Database::fetchAll(
    "SELECT i.*, c.name as customer_name, c.company_name, c.phone as customer_phone 
     FROM invoices i
     JOIN customers c ON i.customer_id = c.id
     WHERE {$whereClause}
     ORDER BY i.invoice_date DESC",
    $params
);

$pageTitle = 'Billing & Invoices';
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <h1 class="page-title">Billing & Invoices</h1>
        <p class="page-subtitle">Generate GST-compliant tax invoices, track payment status, and monitor receivables</p>
    </div>
    <div class="page-header-actions">
        <?php if (Permissions::has('invoices.create')): ?>
        <a href="<?= url('invoice-create.php') ?>" class="btn btn-primary">
            <i class="ti ti-plus mr-1"></i> New Invoice
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<div class="tabs-nav mb-4">
    <a href="<?= url('invoices.php') ?>" class="tab-link <?= $status === 'all' ? 'active' : '' ?>">All Invoices</a>
    <a href="<?= url('invoices.php?status=sent') ?>" class="tab-link <?= $status === 'sent' ? 'active' : '' ?>">Unpaid / Sent</a>
    <a href="<?= url('invoices.php?status=overdue') ?>" class="tab-link <?= $status === 'overdue' ? 'active' : '' ?>">Overdue</a>
    <a href="<?= url('invoices.php?status=partial') ?>" class="tab-link <?= $status === 'partial' ? 'active' : '' ?>">Partially Paid</a>
    <a href="<?= url('invoices.php?status=paid') ?>" class="tab-link <?= $status === 'paid' ? 'active' : '' ?>">Paid</a>
</div>

<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title">Invoices (<?= count($invoices) ?>)</h3>
        <div style="width: 250px;">
            <input type="text" class="form-input form-input-sm" placeholder="Filter invoices..." data-table-filter="#invoices-table">
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($invoices)): ?>
            <div class="empty-state p-5 text-center">
                <i class="ti ti-receipt text-4xl text-muted mb-2"></i>
                <h3>No Invoices Found</h3>
                <p class="text-muted text-sm mb-3">No invoices match your selected filter.</p>
                <?php if (Permissions::has('invoices.create')): ?>
                <a href="<?= url('invoice-create.php') ?>" class="btn btn-primary btn-sm">
                    <i class="ti ti-plus mr-1"></i> Create First Invoice
                </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table" id="invoices-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Invoice Date</th>
                            <th>Due Date</th>
                            <th class="text-right">Total Amount</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Outstanding</th>
                            <th>Status</th>
                            <th class="text-center" style="min-width: 170px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $inv): 
                            $isOverdue = (!empty($inv['due_date']) && $inv['status'] !== 'paid' && $inv['status'] !== 'void' && $inv['status'] !== 'cancelled' && strtotime($inv['due_date']) < strtotime(date('Y-m-d')));
                            $rowWaUrl = WhatsAppService::getClickToChatUrl($inv, $business, url('invoice-pdf.php?id=' . $inv['id']));
                        ?>
                        <tr>
                            <td>
                                <a href="<?= url('invoice-view.php?id=' . $inv['id']) ?>" class="font-bold text-primary">
                                    <?= e($inv['invoice_number']) ?>
                                </a>
                            </td>
                            <td>
                                <strong><?= e($inv['customer_name']) ?></strong>
                                <?php if (!empty($inv['company_name'])): ?>
                                    <div class="text-xs text-muted"><?= e($inv['company_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDate($inv['invoice_date']) ?></td>
                            <td>
                                <span class="<?= $isOverdue ? 'text-danger font-bold' : '' ?>">
                                    <?= formatDate($inv['due_date']) ?>
                                    <?= $isOverdue ? ' (Overdue)' : '' ?>
                                </span>
                            </td>
                            <td class="text-right font-medium"><?= formatCurrency($inv['grand_total']) ?></td>
                            <td class="text-right text-success"><?= formatCurrency($inv['amount_paid']) ?></td>
                            <td class="text-right font-bold <?= $inv['outstanding'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= formatCurrency($inv['outstanding']) ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $inv['status'] === 'paid' ? 'success' : ($isOverdue || $inv['status'] === 'overdue' || $inv['status'] === 'cancelled' ? 'danger' : ($inv['status'] === 'partial' ? 'warning' : 'primary')) ?>">
                                    <?= ucfirst($inv['status']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-center gap-1">
                                    <!-- Download PDF -->
                                    <a href="<?= url('invoice-pdf.php?id=' . $inv['id'] . '&download=1') ?>" class="btn btn-icon btn-ghost text-primary" title="Download PDF">
                                        <i class="ti ti-download"></i>
                                    </a>
                                    <!-- Thermal 80mm -->
                                    <a href="<?= url('invoice-thermal-print.php?id=' . $inv['id'] . '&size=80mm') ?>" class="btn btn-icon btn-ghost" title="Thermal POS Receipt">
                                        <i class="ti ti-receipt"></i>
                                    </a>
                                    <!-- WhatsApp Share -->
                                    <a href="<?= $rowWaUrl ?>" target="_blank" class="btn btn-icon btn-ghost text-success" title="Share via WhatsApp">
                                        <i class="ti ti-brand-whatsapp"></i>
                                    </a>
                                    <!-- View & Full Print -->
                                    <a href="<?= url('invoice-view.php?id=' . $inv['id']) ?>" class="btn btn-icon btn-ghost" title="View & Print">
                                        <i class="ti ti-printer"></i>
                                    </a>
                                    <!-- Record Payment -->
                                    <?php if ($inv['outstanding'] > 0 && $inv['status'] !== 'cancelled' && Permissions::has('payments.record')): ?>
                                    <a href="<?= url('payments.php?invoice_id=' . $inv['id'] . '&action=record') ?>" class="btn btn-icon btn-ghost text-success" title="Record Payment">
                                        <i class="ti ti-cash"></i>
                                    </a>
                                    <?php endif; ?>
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

<?php require YBT_ROOT . '/includes/footer.php'; ?>
