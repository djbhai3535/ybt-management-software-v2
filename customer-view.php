<?php
declare(strict_types=1);

/**
 * YBT Management Software — Customer Profile & Financial Khata Ledger
 * Comprehensive Customer Account with You Gave / You Got, Advance Payments,
 * Payment Recording, Invoices, and Printable Statements.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/customer-service.php';
require_once __DIR__ . '/services/khata-service.php';

Auth::requireLogin();
Permissions::require('customers.view');

$businessId = Auth::businessId();
$userId = Auth::id();
$customerId = (int)($_GET['id'] ?? 0);

if ($customerId <= 0) {
    Flash::error('Customer not found.');
    header('Location: ' . url('customers.php'));
    exit;
}

// 1. Handle Receive Khata Payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_khata_payment') {
    Permissions::require('payments.record');
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount <= 0.001) {
            Flash::error('Please enter a valid payment amount.');
        } else {
            try {
                $payRes = KhataService::recordCustomerPayment($businessId, [
                    'customer_id' => $customerId,
                    'amount' => $amount,
                    'method' => trim($_POST['method'] ?? 'cash'),
                    'payment_date' => !empty($_POST['payment_date']) ? trim($_POST['payment_date']) : date('Y-m-d'),
                    'reference' => trim($_POST['reference'] ?? ''),
                    'notes' => trim($_POST['notes'] ?? ''),
                    'invoice_id' => !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null,
                    'idempotency_key' => trim($_POST['idempotency_key'] ?? '')
                ], $userId);

                if (!empty($payRes['already_recorded'])) {
                    Flash::warning('This payment was already recorded previously.');
                } else {
                    $advMsg = ($payRes['advance'] > 0.001) ? " (Advance Credit: " . formatCurrency($payRes['advance']) . ")" : "";
                    Flash::success("Payment of " . formatCurrency($amount) . " recorded successfully!{$advMsg}");
                }
                header('Location: ' . url('customer-view.php?id=' . $customerId . '&tab=khata'));
                exit;
            } catch (Exception $e) {
                Flash::error('Error recording payment: ' . $e->getMessage());
            }
        }
    }
}

// 2. Handle Customer Profile Edit POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_customer') {
    Permissions::require('customers.edit');
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        CustomerService::update($customerId, $businessId, $_POST);
        ActivityLog::log($businessId, $userId, 'customer_updated', 'customer', $customerId, "Updated profile for customer #{$customerId}");
        Flash::success('Customer profile updated successfully.');
        header('Location: ' . url('customer-view.php?id=' . $customerId));
        exit;
    }
}

// Fetch Customer and Khata Statement
$customer = CustomerService::getById($customerId, $businessId);
if (!$customer) {
    Flash::error('Customer not found.');
    header('Location: ' . url('customers.php'));
    exit;
}

$statement = $customer['khata'] ?? KhataService::getStatement($customerId, $businessId);
$paymentMethods = KhataService::getPaymentMethods();
$currency = $statement['currency'] ?? 'PKR';
$currentBalance = (float)$statement['current_balance'];
$activeTab = $_GET['tab'] ?? 'khata';

// Unpaid invoices for the payment modal
$unpaidInvoices = Database::fetchAll(
    "SELECT id, invoice_number, invoice_date, grand_total, outstanding 
     FROM invoices 
     WHERE customer_id = ? AND business_id = ? AND status IN ('sent', 'partial', 'overdue', 'unpaid') AND outstanding > 0 
     ORDER BY invoice_date ASC, id ASC",
    [$customerId, $businessId]
);

$pageTitle = $customer['name'] . ' — Customer Khata & Profile';
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <div class="d-flex align-center gap-2 mb-1">
            <a href="<?= url('customers.php') ?>" class="text-muted"><i class="ti ti-arrow-left"></i> Customers</a>
            <span class="text-muted">/</span>
            <span class="badge badge-<?= $customer['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($customer['status']) ?></span>
            <span class="badge badge-subtle font-mono">ID: #<?= $customer['id'] ?></span>
        </div>
        <h1 class="page-title"><?= e($customer['name']) ?></h1>
        <?php if (!empty($customer['company_name'])): ?>
            <p class="page-subtitle"><?= e($customer['company_name']) ?></p>
        <?php endif; ?>
    </div>
    <div class="page-header-actions d-flex gap-2">
        <?php if (Permissions::has('payments.record')): ?>
        <button type="button" class="btn btn-success" data-modal-target="record-khata-payment-modal">
            <i class="ti ti-cash mr-1"></i> Receive Payment (رقم وصول کریں)
        </button>
        <?php endif; ?>
        
        <a href="<?= url('customer-ledger-print.php?id=' . $customer['id']) ?>" target="_blank" class="btn btn-secondary">
            <i class="ti ti-printer mr-1"></i> Print Statement (کھاتہ پرنٹ)
        </a>

        <?php if (Permissions::has('invoices.create')): ?>
        <a href="<?= url('invoice-create.php?customer_id=' . $customer['id']) ?>" class="btn btn-primary">
            <i class="ti ti-receipt mr-1"></i> Create Invoice
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Bilingual Khata Financial Summary Cards -->
<div class="grid grid-cols-4 gap-4 mb-4">
    <!-- Opening Balance -->
    <div class="kpi-card card-glow-primary">
        <div class="kpi-card-header">
            <span class="kpi-title">Opening Balance (ابتدائی بقایا)</span>
            <i class="ti ti-scale text-primary"></i>
        </div>
        <div class="kpi-value"><?= formatCurrency((float)$customer['opening_balance']) ?></div>
        <div class="kpi-footer text-xs text-muted">
            <span class="badge badge-subtle"><?= strtoupper($customer['opening_balance_type'] ?? 'DEBIT') ?></span>
            <?= ($customer['opening_balance_type'] === 'credit') ? 'Customer Advance' : 'Opening Dues' ?>
        </div>
    </div>

    <!-- You Gave (آپ نے دیے) -->
    <div class="kpi-card card-glow-warning">
        <div class="kpi-card-header">
            <span class="kpi-title text-danger">You Gave (آپ نے دیے)</span>
            <i class="ti ti-arrow-up-right text-danger"></i>
        </div>
        <div class="kpi-value text-danger"><?= formatCurrency($statement['total_gave']) ?></div>
        <div class="kpi-footer text-xs text-muted">Total Invoiced Debits (مال دیا)</div>
    </div>

    <!-- You Got (آپ کو ملے) -->
    <div class="kpi-card card-glow-success">
        <div class="kpi-card-header">
            <span class="kpi-title text-success">You Got (آپ کو ملے)</span>
            <i class="ti ti-arrow-down-left text-success"></i>
        </div>
        <div class="kpi-value text-success"><?= formatCurrency($statement['total_got']) ?></div>
        <div class="kpi-footer text-xs text-muted">Total Collections Received (وصولی)</div>
    </div>

    <!-- Net Khata Balance (بقیہ رقم) -->
    <div class="kpi-card <?= $currentBalance > 0 ? 'card-glow-danger' : ($currentBalance < 0 ? 'card-glow-success' : '') ?>" style="background: var(--bg-surface);">
        <div class="kpi-card-header">
            <span class="kpi-title font-bold">Net Balance (بقیہ رقم)</span>
            <i class="ti ti-wallet <?= $currentBalance > 0 ? 'text-danger' : 'text-success' ?>"></i>
        </div>
        <div class="kpi-value <?= $currentBalance > 0 ? 'text-danger' : ($currentBalance < 0 ? 'text-success' : 'text-muted') ?>">
            <?= formatCurrency(abs($currentBalance)) ?>
        </div>
        <div class="kpi-footer text-xs font-medium">
            <?php if ($currentBalance > 0): ?>
                <span class="text-danger"><i class="ti ti-alert-circle mr-1"></i> Customer Owes (آپ نے لینے ہیں)</span>
            <?php elseif ($currentBalance < 0): ?>
                <span class="text-success"><i class="ti ti-circle-check mr-1"></i> Advance Credit (کسٹمر کی امانت)</span>
            <?php else: ?>
                <span class="text-success"><i class="ti ti-check mr-1"></i> Account Cleared (حساب برابر)</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-3 gap-4">
    <!-- Left Column: Customer Profile & Contact Details -->
    <div class="col-span-1">
        <div class="card mb-4">
            <div class="card-header d-flex justify-between align-center">
                <h3 class="card-title"><i class="ti ti-address-book mr-1 text-primary"></i> Profile & Contacts</h3>
                <button type="button" class="btn btn-xs btn-secondary" data-modal-target="edit-customer-modal">
                    <i class="ti ti-pencil mr-1"></i> Edit
                </button>
            </div>
            <div class="card-body">
                <div class="info-list">
                    <div class="info-item mb-2">
                        <span class="info-label text-muted text-xs">Mobile Phone</span>
                        <div class="info-val font-medium font-mono"><?= e($customer['phone'] ?: '—') ?></div>
                    </div>
                    <div class="info-item mb-2">
                        <span class="info-label text-muted text-xs">Email Address</span>
                        <div class="info-val font-medium"><?= e($customer['email'] ?: '—') ?></div>
                    </div>
                    <div class="info-item mb-2">
                        <span class="info-label text-muted text-xs">Billing City / Town</span>
                        <div class="info-val font-medium"><?= e($customer['billing_city'] ?: $customer['city'] ?: '—') ?></div>
                    </div>
                    <div class="info-item mb-2">
                        <span class="info-label text-muted text-xs">Full Address</span>
                        <div class="info-val text-sm">
                            <?= e($customer['billing_address_line1'] ?: $customer['address'] ?: '—') ?>
                        </div>
                    </div>
                    <?php if (!empty($customer['notes'])): ?>
                    <div class="info-item mt-3 p-2 bg-subtle rounded text-xs text-muted">
                        <strong>Notes:</strong> <?= nl2br(e($customer['notes'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Summary Box -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-chart-pie mr-1 text-primary"></i> Activity Summary</h3>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush text-sm">
                    <li class="d-flex justify-between p-3 border-b">
                        <span class="text-muted">Total Invoices</span>
                        <span class="font-bold"><?= count($customer['invoices']) ?></span>
                    </li>
                    <li class="d-flex justify-between p-3 border-b">
                        <span class="text-muted">Total Payments Recorded</span>
                        <span class="font-bold"><?= count($customer['payments']) ?></span>
                    </li>
                    <li class="d-flex justify-between p-3 border-b">
                        <span class="text-muted">Unpaid Invoices</span>
                        <span class="font-bold text-danger"><?= count($unpaidInvoices) ?></span>
                    </li>
                    <li class="d-flex justify-between p-3">
                        <span class="text-muted">Total Ledger Entries</span>
                        <span class="font-bold"><?= count($statement['entries']) ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right Column: Tabbed View (Khata Ledger / Invoices / Payments) -->
    <div class="col-span-2">
        <div class="card">
            <div class="card-header d-flex justify-between align-center p-2 border-b">
                <div class="tabs-nav d-flex gap-2">
                    <a href="<?= url('customer-view.php?id=' . $customer['id'] . '&tab=khata') ?>" 
                       class="btn btn-sm <?= $activeTab === 'khata' ? 'btn-primary' : 'btn-ghost' ?>">
                        <i class="ti ti-notebook mr-1"></i> Khata Ledger (کھاتہ) (<?= count($statement['entries']) ?>)
                    </a>
                    <a href="<?= url('customer-view.php?id=' . $customer['id'] . '&tab=invoices') ?>" 
                       class="btn btn-sm <?= $activeTab === 'invoices' ? 'btn-primary' : 'btn-ghost' ?>">
                        <i class="ti ti-receipt mr-1"></i> Invoices (<?= count($customer['invoices']) ?>)
                    </a>
                    <a href="<?= url('customer-view.php?id=' . $customer['id'] . '&tab=payments') ?>" 
                       class="btn btn-sm <?= $activeTab === 'payments' ? 'btn-primary' : 'btn-ghost' ?>">
                        <i class="ti ti-cash mr-1"></i> Payments (<?= count($customer['payments']) ?>)
                    </a>
                </div>
                <div>
                    <a href="<?= url('customer-ledger-print.php?id=' . $customer['id']) ?>" target="_blank" class="btn btn-xs btn-outline">
                        <i class="ti ti-printer mr-1"></i> Print
                    </a>
                </div>
            </div>

            <div class="card-body p-0">
                <?php if ($activeTab === 'khata'): ?>
                    <!-- TAB 1: Complete Customer Khata Ledger -->
                    <?php if (empty($statement['entries'])): ?>
                        <div class="empty-state p-5 text-center">
                            <i class="ti ti-notebook text-4xl text-muted mb-2"></i>
                            <p class="text-muted text-sm">No ledger entries recorded yet for this customer.</p>
                            <p class="text-xs text-muted">Create an invoice or record a payment to initiate this Khata.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Description & Reference</th>
                                        <th class="text-right text-danger">You Gave (آپ نے دیے)<br><span class="text-xs font-normal">Debit</span></th>
                                        <th class="text-right text-success">You Got (آپ کو ملے)<br><span class="text-xs font-normal">Credit</span></th>
                                        <th class="text-right">Balance (بقایا)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($statement['entries'] as $e): ?>
                                    <tr>
                                        <td class="text-xs font-mono"><?= formatDate($e['entry_date']) ?></td>
                                        <td>
                                            <?php if ($e['entry_type'] === 'invoice'): ?>
                                                <span class="badge badge-danger">INVOICE</span>
                                            <?php elseif ($e['entry_type'] === 'payment'): ?>
                                                <span class="badge badge-success">PAYMENT</span>
                                            <?php elseif ($e['entry_type'] === 'advance_payment'): ?>
                                                <span class="badge badge-success">ADVANCE</span>
                                            <?php elseif ($e['entry_type'] === 'invoice_cancelled'): ?>
                                                <span class="badge badge-secondary">CANCELLED</span>
                                            <?php elseif ($e['entry_type'] === 'opening_balance'): ?>
                                                <span class="badge badge-primary">OP-BAL</span>
                                            <?php else: ?>
                                                <span class="badge badge-subtle"><?= strtoupper(e($e['entry_type'])) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="font-medium text-sm"><?= e($e['description']) ?></div>
                                            <?php if (!empty($e['reference_number'])): ?>
                                                <div class="text-xs text-muted">Ref: <strong><?= e($e['reference_number']) ?></strong></div>
                                            <?php endif; ?>
                                            <?php if (!empty($e['notes'])): ?>
                                                <div class="text-xs text-muted italic"><?= e($e['notes']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right font-bold text-danger">
                                            <?= (float)$e['debit'] > 0 ? formatCurrency((float)$e['debit']) : '—' ?>
                                        </td>
                                        <td class="text-right font-bold text-success">
                                            <?= (float)$e['credit'] > 0 ? formatCurrency((float)$e['credit']) : '—' ?>
                                        </td>
                                        <td class="text-right font-bold <?= (float)$e['running_balance'] > 0 ? 'text-danger' : ((float)$e['running_balance'] < 0 ? 'text-success' : '') ?>">
                                            <?= formatCurrency(abs((float)$e['running_balance'])) ?>
                                            <span class="text-xs font-normal">
                                                <?= (float)$e['running_balance'] > 0 ? 'Dr' : ((float)$e['running_balance'] < 0 ? 'Cr' : '') ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                <?php elseif ($activeTab === 'invoices'): ?>
                    <!-- TAB 2: Invoices List -->
                    <?php if (empty($customer['invoices'])): ?>
                        <div class="empty-state p-4 text-center">
                            <p class="text-muted text-sm">No invoices recorded for this customer.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Due Date</th>
                                        <th class="text-right">Total</th>
                                        <th class="text-right">Paid</th>
                                        <th class="text-right">Outstanding</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customer['invoices'] as $inv): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= url('invoice-view.php?id=' . $inv['id']) ?>" class="font-bold text-primary">
                                                <?= e($inv['invoice_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= formatDate($inv['invoice_date']) ?></td>
                                        <td><?= formatDate($inv['due_date']) ?></td>
                                        <td class="text-right font-medium"><?= formatCurrency($inv['grand_total']) ?></td>
                                        <td class="text-right text-success"><?= formatCurrency($inv['amount_paid']) ?></td>
                                        <td class="text-right font-bold <?= $inv['outstanding'] > 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= formatCurrency($inv['outstanding']) ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= $inv['status'] === 'paid' ? 'success' : ($inv['status'] === 'overdue' ? 'danger' : 'warning') ?>">
                                                <?= ucfirst($inv['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                <?php elseif ($activeTab === 'payments'): ?>
                    <!-- TAB 3: Payments List -->
                    <?php if (empty($customer['payments'])): ?>
                        <div class="empty-state p-4 text-center">
                            <p class="text-muted text-sm">No payments recorded for this customer.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Receipt #</th>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customer['payments'] as $p): ?>
                                    <tr>
                                        <td class="font-bold font-mono text-primary"><?= e($p['payment_number'] ?? 'PAY-#' . $p['id']) ?></td>
                                        <td><?= formatDate($p['payment_date']) ?></td>
                                        <td><span class="badge badge-subtle"><?= strtoupper(e($p['payment_method'] ?? $p['method'] ?? 'cash')) ?></span></td>
                                        <td><?= e($p['reference'] ?? $p['transaction_reference'] ?: '—') ?></td>
                                        <td class="text-right font-bold text-success"><?= formatCurrency((float)$p['amount']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: Receive Khata Payment                                 -->
<!-- ============================================================ -->
<div class="modal-backdrop" id="record-khata-payment-modal" style="display: none;">
    <div class="modal" style="max-width: 520px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="ti ti-cash mr-1 text-success"></i> Receive Customer Payment</h3>
            <button type="button" class="modal-close" data-modal-dismiss>&times;</button>
        </div>
        <form method="POST" action="<?= url('customer-view.php?id=' . $customer['id']) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="record_khata_payment">
            <input type="hidden" name="idempotency_key" value="khata_pay_<?= $businessId ?>_<?= $customerId ?>_<?= time() ?>_<?= rand(100, 999) ?>">

            <div class="modal-body">
                <div class="p-3 bg-subtle rounded mb-3">
                    <div class="d-flex justify-between align-center mb-1">
                        <span class="text-muted text-xs">Customer</span>
                        <strong><?= e($customer['name']) ?></strong>
                    </div>
                    <div class="d-flex justify-between align-center">
                        <span class="text-muted text-xs">Current Khata Balance</span>
                        <strong class="<?= $currentBalance > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= formatCurrency(abs($currentBalance)) ?>
                            (<?= $currentBalance > 0 ? 'Customer Owes' : ($currentBalance < 0 ? 'Advance' : 'Cleared') ?>)
                        </strong>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label required">Payment Amount (رقم)</label>
                    <div class="input-prefix-wrapper">
                        <span class="input-prefix"><?= e($currency) ?></span>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-input" 
                               value="<?= $currentBalance > 0 ? e((string)$currentBalance) : '' ?>" required autofocus>
                    </div>
                    <div class="text-xs text-muted mt-1">If amount exceeds dues, excess is saved as Advance Credit.</div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label required">Payment Method (ادائیگی کا ذریعہ)</label>
                    <select name="method" class="form-select" required>
                        <?php foreach ($paymentMethods as $code => $m): ?>
                            <option value="<?= e($code) ?>">
                                <?= e($m['label']) ?> (<?= e($m['label_ur']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!empty($unpaidInvoices)): ?>
                <div class="form-group mb-3">
                    <label class="form-label">Target Invoice (Optional)</label>
                    <select name="invoice_id" class="form-select">
                        <option value="">Auto-Apply (FIFO Across Unpaid Invoices)</option>
                        <?php foreach ($unpaidInvoices as $u): ?>
                            <option value="<?= $u['id'] ?>">
                                Invoice #<?= e($u['invoice_number']) ?> — Outstanding: <?= formatCurrency($u['outstanding']) ?> (Date: <?= formatDate($u['invoice_date']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Payment Date</label>
                        <input type="date" name="payment_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reference / TID #</label>
                        <input type="text" name="reference" class="form-input" placeholder="e.g. JazzCash TID, Bank Ref">
                    </div>
                </div>

                <div class="form-group mb-1">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-textarea" rows="2" placeholder="Optional notes..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-dismiss>Cancel</button>
                <button type="submit" class="btn btn-success">
                    <i class="ti ti-check mr-1"></i> Confirm & Save Payment
                </button>
            </div>
        </form>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
