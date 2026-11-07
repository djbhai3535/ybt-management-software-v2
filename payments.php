<?php
declare(strict_types=1);

/**
 * YBT Management Software — Payments Directory & Recording
 * Integrated with Customer Khata Ledger (You Gave / You Got)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/payment-service.php';
require_once __DIR__ . '/services/khata-service.php';

Auth::requireLogin();
Permissions::require('payments.view');

$businessId = Auth::businessId();
$userId = Auth::id();
$business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);
$currency = $business['default_currency'] ?? 'PKR';
$paymentMethods = KhataService::getPaymentMethods();

// Handle Record Payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    Permissions::require('payments.record');
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);

        if ($customerId <= 0) {
            Flash::error('Please select a customer.');
        } elseif ($amount <= 0) {
            Flash::error('Please enter a valid payment amount.');
        } else {
            try {
                $payId = PaymentService::record($businessId, $_POST, $userId);
                Flash::success('Payment recorded successfully and updated Customer Khata.');
                header('Location: ' . url('payments.php'));
                exit;
            } catch (Exception $e) {
                Flash::error('Failed to record payment: ' . $e->getMessage());
            }
        }
    }
}

// Fetch active customers and unpaid invoices for recording
$customers = Database::fetchAll("SELECT id, name, company_name, current_balance FROM customers WHERE business_id = ? ORDER BY name ASC", [$businessId]);
$unpaidInvoices = Database::fetchAll(
    "SELECT id, customer_id, invoice_number, 
            balance_amount as outstanding, 
            issue_date as invoice_date 
     FROM invoices 
     WHERE business_id = ? AND status IN ('issued', 'partially_paid', 'sent', 'partial', 'overdue', 'unpaid') AND balance_amount > 0 
     ORDER BY issue_date DESC",
    [$businessId]
);

// Fetch Payments List
$payments = Database::fetchAll(
    "SELECT p.*, 
            p.payment_method as method, 
            p.reference_number as reference, 
            c.name as customer_name, c.company_name, i.invoice_number, i.id as inv_id
     FROM payments p
     JOIN customers c ON p.customer_id = c.id
     LEFT JOIN payment_allocations pa ON p.id = pa.payment_id
     LEFT JOIN invoices i ON pa.invoice_id = i.id
     WHERE p.business_id = ?
     ORDER BY p.payment_date DESC, p.id DESC",
    [$businessId]
);

$pageTitle = 'Payments & Receipts (وصولیاں)';
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <h1 class="page-title">Payments & Receipts (وصولیاں)</h1>
        <p class="page-subtitle">Track collections, bank transfers, mobile wallets (JazzCash/Easypaisa), Raast, and cash receipts</p>
    </div>
    <div class="page-header-actions">
        <?php if (Permissions::has('payments.record')): ?>
        <button type="button" class="btn btn-primary" data-modal-target="record-payment-modal">
            <i class="ti ti-cash mr-1"></i> Record Payment (رقم وصول کریں)
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title">Payment Transactions (<?= count($payments) ?>)</h3>
        <div style="width: 250px;">
            <input type="text" class="form-input form-input-sm" placeholder="Filter payments..." data-table-filter="#payments-table">
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($payments)): ?>
            <div class="empty-state p-5 text-center">
                <i class="ti ti-cash text-4xl text-muted mb-2"></i>
                <h3>No Payments Recorded Yet</h3>
                <p class="text-muted text-sm mb-3">Incoming payments will appear here with instant customer ledger updates.</p>
                <?php if (Permissions::has('payments.record')): ?>
                <button type="button" class="btn btn-primary btn-sm" data-modal-target="record-payment-modal">
                    <i class="ti ti-plus mr-1"></i> Record Your First Payment
                </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table" id="payments-table">
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Customer Name</th>
                            <th>Allocated Invoice</th>
                            <th>Date</th>
                            <th>Method (طریقہ)</th>
                            <th>Reference / TID</th>
                            <th class="text-right">Amount Received (وصولی)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><strong><?= e($p['payment_number'] ?? 'PAY-#' . $p['id']) ?></strong></td>
                            <td>
                                <a href="<?= url('customer-view.php?id=' . $p['customer_id'] . '&tab=khata') ?>" class="font-bold text-primary">
                                    <?= e($p['customer_name']) ?>
                                </a>
                                <?php if (!empty($p['company_name'])): ?>
                                    <div class="text-xs text-muted"><?= e($p['company_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['invoice_number'])): ?>
                                    <a href="<?= url('invoice-view.php?id=' . $p['inv_id']) ?>" class="badge badge-primary">
                                        <?= e($p['invoice_number']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="badge badge-subtle">Advance / Unallocated</span>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDate($p['payment_date']) ?></td>
                            <td>
                                <?php 
                                $meth = $p['payment_method'] ?? $p['method'] ?? 'cash';
                                $mMeta = $paymentMethods[$meth] ?? null;
                                ?>
                                <span class="badge badge-subtle">
                                    <i class="<?= e($mMeta['icon'] ?? 'ti ti-cash') ?> mr-1"></i>
                                    <?= e($mMeta['label'] ?? ucfirst($meth)) ?>
                                </span>
                            </td>
                            <td><code><?= e($p['reference'] ?? $p['transaction_reference'] ?: '—') ?></code></td>
                            <td class="text-right font-bold text-success">
                                <?= formatCurrency((float)$p['amount']) ?>
                            </td>
                            <td>
                                <span class="badge badge-success">Completed</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal-backdrop <?= (isset($_GET['action']) && $_GET['action'] === 'record') ? 'active' : '' ?>" id="record-payment-modal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title"><i class="ti ti-cash mr-1 text-success"></i> Record Incoming Payment (رقم وصول کریں)</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form action="<?= url('payments.php') ?>" method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="idempotency_key" value="pay_modal_<?= $businessId ?>_<?= time() ?>_<?= rand(100, 999) ?>">

            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label required">Customer</label>
                    <select name="customer_id" class="form-select" id="pay-customer-select" required>
                        <option value="">— Select Customer —</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= e($c['name']) ?> 
                                <?php if (!empty($c['current_balance']) && (float)$c['current_balance'] > 0): ?>
                                    (Dues: <?= formatCurrency((float)$c['current_balance']) ?>)
                                <?php elseif (!empty($c['current_balance']) && (float)$c['current_balance'] < 0): ?>
                                    (Advance: <?= formatCurrency(abs((float)$c['current_balance'])) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Apply to Invoice (Optional)</label>
                    <select name="invoice_id" class="form-select">
                        <option value="">— Auto-Apply (FIFO Across Unpaid Invoices or Advance) —</option>
                        <?php foreach ($unpaidInvoices as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= ((int)($_GET['invoice_id'] ?? 0) === $u['id']) ? 'selected' : '' ?>>
                                <?= e($u['invoice_number']) ?> (Due: <?= formatCurrency((float)$u['outstanding']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Amount Received (<?= e($currency) ?>)</label>
                        <input type="number" name="amount" class="form-input" placeholder="5000" min="0.01" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Payment Date</label>
                        <input type="date" name="payment_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Payment Method (ادائیگی کا ذریعہ)</label>
                        <select name="method" class="form-select" required>
                            <?php foreach ($paymentMethods as $code => $m): ?>
                                <option value="<?= e($code) ?>" <?= $code === 'cash' ? 'selected' : '' ?>>
                                    <?= e($m['label']) ?> (<?= e($m['label_ur']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reference / TID #</label>
                        <input type="text" name="reference" class="form-input" placeholder="e.g. JazzCash TID, Bank UTR">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes / Remarks</label>
                    <textarea name="notes" class="form-input" rows="2" placeholder="e.g. Handed cash in shop, or received via Raast transfer"></textarea>
                </div>
            </div>

            <div class="modal-footer d-flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-success"><i class="ti ti-check mr-1"></i> Save Payment & Update Khata</button>
            </div>
        </form>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
