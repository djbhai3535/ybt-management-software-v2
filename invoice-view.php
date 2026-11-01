<?php
/**
 * YBT Management Software — Invoice Printable Document & Details
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/invoice-service.php';
require_once __DIR__ . '/services/pdf-service.php';

Auth::requireLogin();
Permissions::require('invoices.view');

$businessId = Auth::businessId();
$invoiceId = (int)($_GET['id'] ?? 0);

if ($invoiceId <= 0) {
    Flash::error('Invoice not found.');
    header('Location: ' . url('invoices.php'));
    exit;
}

$invoice = InvoiceService::getById($invoiceId, $businessId);
if (!$invoice) {
    Flash::error('Invoice not found.');
    header('Location: ' . url('invoices.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_invoice') {
    Permissions::require('invoices.edit');
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        try {
            $cancelRes = InvoiceService::cancel($invoiceId, $businessId, Auth::id() ?? 1);
            Flash::success('Invoice has been cancelled and inventory stock restored.');
            header('Location: ' . url('invoice-view.php?id=' . $invoiceId));
            exit;
        } catch (\Throwable $e) {
            Flash::error('Error cancelling invoice: ' . $e->getMessage());
        }
    }
}

$business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);

$pageTitle = $invoice['invoice_number'] . ' — Invoice';
require YBT_ROOT . '/includes/header.php';
?>

<!-- Document Actions Toolbar (hidden during print) -->
<div class="actions-bar d-flex justify-between align-center mb-4 no-print">
    <div class="d-flex align-center gap-2">
        <a href="<?= url('invoices.php') ?>" class="btn btn-secondary">
            <i class="ti ti-arrow-left mr-1"></i> Back to Invoices
        </a>
        <span class="badge badge-<?= $invoice['status'] === 'paid' ? 'success' : ($invoice['status'] === 'overdue' ? 'danger' : 'warning') ?>">
            <?= strtoupper(e($invoice['status'])) ?>
        </span>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <i class="ti ti-printer mr-1"></i> Print / Save PDF
        </button>
        <?php if ($invoice['outstanding'] > 0 && Permissions::has('payments.record')): ?>
        <a href="<?= url('payments.php?invoice_id=' . $invoice['id'] . '&action=record') ?>" class="btn btn-success">
            <i class="ti ti-cash mr-1"></i> Record Payment
        </a>
        <?php endif; ?>
        <?php if ($invoice['status'] !== 'cancelled' && Permissions::has('invoices.edit')): ?>
        <form method="POST" action="<?= url('invoice-view.php?id=' . $invoice['id']) ?>" style="display:inline;" onsubmit="return confirm('Are you sure you want to cancel this invoice? Stock for all line items will automatically be restored.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cancel_invoice">
            <button type="submit" class="btn btn-danger">
                <i class="ti ti-ban mr-1"></i> Cancel & Restock
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Main Printable Invoice Card -->
<div class="card p-0 mb-4 printable-invoice-card">
    <div class="card-body p-4">
        <?= PdfService::renderInvoiceHtml($invoice, $business) ?>
    </div>
</div>

<!-- Payments Received History (Non-Print) -->
<div class="card no-print">
    <div class="card-header">
        <h3 class="card-title"><i class="ti ti-history mr-1 text-primary"></i> Payments Received on this Invoice</h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($invoice['payments'])): ?>
            <div class="p-4 text-center text-muted text-sm">
                No payments have been recorded for this invoice yet.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Payment #</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th class="text-right">Allocated Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoice['payments'] as $p): ?>
                        <tr>
                            <td><strong><?= e($p['payment_number']) ?></strong></td>
                            <td><?= formatDate($p['payment_date']) ?></td>
                            <td><span class="badge badge-subtle"><?= ucfirst(str_replace('_', ' ', $p['method'])) ?></span></td>
                            <td><?= e($p['reference'] ?: '—') ?></td>
                            <td class="text-right font-bold text-success"><?= formatCurrency($p['allocated_amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
