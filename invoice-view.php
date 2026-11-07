<?php
/**
 * YBT Management Software — Invoice Printable Document & Details
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/invoice-service.php';
require_once __DIR__ . '/services/pdf-service.php';
require_once __DIR__ . '/services/whatsapp-service.php';

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
$whatsAppUrl = WhatsAppService::getClickToChatUrl($invoice, $business, url('invoice-pdf.php?id=' . $invoiceId));

$pageTitle = $invoice['invoice_number'] . ' — Invoice';
require YBT_ROOT . '/includes/header.php';
?>

<!-- Document Actions Toolbar (hidden during print) -->
<div class="actions-bar d-flex justify-between align-center mb-4 no-print flex-wrap gap-2">
    <div class="d-flex align-center gap-2">
        <a href="<?= url('invoices.php') ?>" class="btn btn-secondary">
            <i class="ti ti-arrow-left mr-1"></i> Back to Invoices
        </a>
        <span class="badge badge-<?= $invoice['status'] === 'paid' ? 'success' : ($invoice['status'] === 'overdue' || $invoice['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
            <?= strtoupper(e($invoice['status'])) ?>
        </span>
    </div>
    <div class="d-flex gap-2 flex-wrap align-center">
        <!-- Direct Binary PDF Download -->
        <a href="<?= url('invoice-pdf.php?id=' . $invoice['id'] . '&download=1') ?>" class="btn btn-primary" title="Download standard PDF file">
            <i class="ti ti-download mr-1"></i> Download PDF
        </a>

        <!-- Print A4 View -->
        <a href="<?= url('invoice-pdf.php?id=' . $invoice['id']) ?>" class="btn btn-secondary" title="View Full A4 Document">
            <i class="ti ti-printer mr-1"></i> Print A4
        </a>

        <!-- Thermal Receipt Dropdown / Buttons -->
        <div class="d-inline-flex gap-1">
            <a href="<?= url('invoice-thermal-print.php?id=' . $invoice['id'] . '&size=80mm') ?>" class="btn btn-secondary" title="Thermal POS Receipt (80mm)">
                <i class="ti ti-receipt mr-1"></i> Thermal 80mm
            </a>
            <a href="<?= url('invoice-thermal-print.php?id=' . $invoice['id'] . '&size=58mm') ?>" class="btn btn-secondary" title="Thermal POS Receipt (58mm)">
                <i class="ti ti-receipt-2 mr-1"></i> 58mm
            </a>
        </div>

        <!-- WhatsApp Sharing -->
        <a href="<?= $whatsAppUrl ?>" target="_blank" class="btn btn-success" style="background-color: #25d366; border-color: #25d366;" title="Share Invoice on WhatsApp">
            <i class="ti ti-brand-whatsapp mr-1"></i> WhatsApp
        </a>

        <?php if (($invoice['outstanding'] ?? $invoice['balance_amount'] ?? 0) > 0 && $invoice['status'] !== 'cancelled' && Permissions::has('payments.record')): ?>
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
