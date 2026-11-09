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

function handleKhataFileUpload(string $fileKey, string $type = 'image'): ?string {
    if (empty($_FILES[$fileKey]) || !isset($_FILES[$fileKey]['error']) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES[$fileKey];
    $uploadDir = YBT_ROOT . '/storage/uploads/khata';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ($type === 'image')
        ? ['jpg', 'jpeg', 'png', 'webp', 'gif']
        : ['mp3', 'wav', 'ogg', 'm4a', 'webm', 'aac', '3gp'];

    if (!in_array($ext, $allowed, true)) {
        return null;
    }
    $filename = 'khata_' . $type . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $uploadDir . '/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'storage/uploads/khata/' . $filename;
    }
    return null;
}

// 0a. Handle You Gave POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_you_gave') {
    if (!Permissions::has('customers.edit') && !Permissions::has('payments.record') && !Permissions::has('invoices.create')) {
        Permissions::require('customers.edit');
    }
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token. Please refresh and try again.');
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        $entryDate = !empty($_POST['entry_date']) ? trim($_POST['entry_date']) : date('Y-m-d');
        $description = trim($_POST['description'] ?? 'Items / Cash Given');
        $itemDetails = trim($_POST['item_details'] ?? '');
        $idempKey = trim($_POST['idempotency_key'] ?? '');

        if ($amount <= 0.001) {
            Flash::error('Please enter a valid amount greater than zero.');
        } else {
            try {
                $imagePath = handleKhataFileUpload('image_attachment', 'image');
                $voicePath = handleKhataFileUpload('voice_attachment', 'audio');

                $res = KhataService::recordYouGave(
                    $businessId,
                    $customerId,
                    $amount,
                    $entryDate,
                    $description,
                    $itemDetails ?: null,
                    $imagePath,
                    $voicePath,
                    $idempKey ?: null,
                    $userId
                );

                Flash::success("Recorded You Gave: " . formatCurrency($amount) . ". New Balance: " . formatCurrency(abs($res['running_balance'])) . " (" . ($res['running_balance'] > 0 ? 'Dr' : 'Cr') . ")");
                header('Location: ' . url('customer-view.php?id=' . $customerId . '&tab=khata'));
                exit;
            } catch (Exception $e) {
                Flash::error('Error recording transaction: ' . $e->getMessage());
            }
        }
    }
}

// 0b. Handle You Got POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_you_got') {
    Permissions::require('payments.record');
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token. Please refresh and try again.');
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        $entryDate = !empty($_POST['entry_date']) ? trim($_POST['entry_date']) : date('Y-m-d');
        $description = trim($_POST['description'] ?? 'Payment Received');
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $idempKey = trim($_POST['idempotency_key'] ?? '');

        if ($amount <= 0.001) {
            Flash::error('Please enter a valid amount greater than zero.');
        } else {
            try {
                $imagePath = handleKhataFileUpload('image_attachment', 'image');
                $voicePath = handleKhataFileUpload('voice_attachment', 'audio');

                $res = KhataService::recordYouGot(
                    $businessId,
                    $customerId,
                    $amount,
                    $entryDate,
                    $description,
                    $paymentMethod,
                    $imagePath,
                    $voicePath,
                    $idempKey ?: null,
                    $userId
                );

                Flash::success("Recorded You Got: " . formatCurrency($amount) . ". New Balance: " . formatCurrency(abs($res['running_balance'])) . " (" . ($res['running_balance'] > 0 ? 'Dr' : 'Cr') . ")");
                header('Location: ' . url('customer-view.php?id=' . $customerId . '&tab=khata'));
                exit;
            } catch (Exception $e) {
                Flash::error('Error recording collection: ' . $e->getMessage());
            }
        }
    }
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
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            Flash::error('Customer name is required.');
            header('Location: ' . url('customer-view.php?id=' . $customerId));
            exit;
        }

        $countryCode = trim($_POST['country_code'] ?? '+92');
        $rawPhone = trim($_POST['phone'] ?? '');
        if ($rawPhone !== '') {
            $phoneValidation = PhoneValidator::validate($countryCode, $rawPhone);
            if (!$phoneValidation['valid']) {
                Flash::error($phoneValidation['error']);
                header('Location: ' . url('customer-view.php?id=' . $customerId));
                exit;
            }
            $_POST['phone'] = $phoneValidation['formatted'];
        }

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
    "SELECT id, invoice_number, 
            issue_date as invoice_date, 
            total_amount as grand_total, 
            balance_amount as outstanding 
     FROM invoices 
     WHERE customer_id = ? AND business_id = ? AND status IN ('issued', 'partially_paid', 'sent', 'partial', 'overdue', 'unpaid') AND balance_amount > 0 
     ORDER BY issue_date ASC, id ASC",
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
    <div class="page-header-actions d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-danger font-bold" data-modal-target="modal-you-gave">
            <i class="ti ti-arrow-up-right mr-1"></i> You Gave
        </button>
        <button type="button" class="btn btn-success font-bold" data-modal-target="modal-you-got">
            <i class="ti ti-arrow-down-left mr-1"></i> You Got
        </button>
        <?php if (Permissions::has('payments.record')): ?>
        <button type="button" class="btn btn-outline" data-modal-target="record-khata-payment-modal">
            <i class="ti ti-cash mr-1"></i> Receive Payment
        </button>
        <?php endif; ?>
        
        <a href="<?= url('customer-ledger-print.php?id=' . $customer['id']) ?>" target="_blank" class="btn btn-secondary">
            <i class="ti ti-printer mr-1"></i> Print Statement
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
            <span class="kpi-title">Opening Balance</span>
            <i class="ti ti-scale text-primary"></i>
        </div>
        <div class="kpi-value"><?= formatCurrency((float)$customer['opening_balance']) ?></div>
        <div class="kpi-footer text-xs text-muted">
            <span class="badge badge-subtle"><?= strtoupper($customer['opening_balance_type'] ?? 'DEBIT') ?></span>
            <?= ($customer['opening_balance_type'] === 'credit') ? 'Customer Advance' : 'Opening Dues' ?>
        </div>
    </div>

    <!-- You Gave -->
    <div class="kpi-card card-glow-warning">
        <div class="kpi-card-header">
            <span class="kpi-title text-danger">You Gave</span>
            <i class="ti ti-arrow-up-right text-danger"></i>
        </div>
        <div class="kpi-value text-danger"><?= formatCurrency($statement['total_gave']) ?></div>
        <div class="kpi-footer text-xs text-muted">Total Invoiced Debits</div>
    </div>

    <!-- You Got -->
    <div class="kpi-card card-glow-success">
        <div class="kpi-card-header">
            <span class="kpi-title text-success">You Got</span>
            <i class="ti ti-arrow-down-left text-success"></i>
        </div>
        <div class="kpi-value text-success"><?= formatCurrency($statement['total_got']) ?></div>
        <div class="kpi-footer text-xs text-muted">Total Collections Received</div>
    </div>

    <!-- Net Balance -->
    <div class="kpi-card <?= $currentBalance > 0 ? 'card-glow-danger' : ($currentBalance < 0 ? 'card-glow-success' : '') ?>" style="background: var(--bg-surface);">
        <div class="kpi-card-header">
            <span class="kpi-title font-bold">Net Balance</span>
            <i class="ti ti-wallet <?= $currentBalance > 0 ? 'text-danger' : 'text-success' ?>"></i>
        </div>
        <div class="kpi-value <?= $currentBalance > 0 ? 'text-danger' : ($currentBalance < 0 ? 'text-success' : 'text-muted') ?>">
            <?= formatCurrency(abs($currentBalance)) ?>
        </div>
        <div class="kpi-footer text-xs font-medium">
            <?php if ($currentBalance > 0): ?>
                <span class="text-danger"><i class="ti ti-alert-circle mr-1"></i> Customer Owes</span>
            <?php elseif ($currentBalance < 0): ?>
                <span class="text-success"><i class="ti ti-circle-check mr-1"></i> Advance Credit</span>
            <?php else: ?>
                <span class="text-success"><i class="ti ti-check mr-1"></i> Account Cleared</span>
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
                        <div class="info-val font-medium"><?= e(($customer['billing_city'] ?? $customer['city'] ?? '') ?: '—') ?></div>
                    </div>
                    <div class="info-item mb-2">
                        <span class="info-label text-muted text-xs">Full Address</span>
                        <div class="info-val text-sm">
                            <?= e(($customer['billing_address_line1'] ?? $customer['billing_address'] ?? $customer['address'] ?? '') ?: '—') ?>
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
                        <i class="ti ti-notebook mr-1"></i> Customer Ledger (<?= count($statement['entries']) ?>)
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
                    <!-- Quick Khata Transaction Bar -->
                    <div class="p-3 bg-surface border-b d-flex justify-between align-center flex-wrap gap-2">
                        <div class="d-flex align-center gap-2">
                            <span class="badge badge-subtle"><i class="ti ti-scale mr-1"></i> Khata Transactions</span>
                            <span class="text-xs text-muted">Direct entry for credit purchases & collections</span>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-danger btn-sm font-bold" data-modal-target="modal-you-gave">
                                <i class="ti ti-arrow-up-right mr-1"></i> You Gave
                            </button>
                            <button type="button" class="btn btn-success btn-sm font-bold" data-modal-target="modal-you-got">
                                <i class="ti ti-arrow-down-left mr-1"></i> You Got
                            </button>
                        </div>
                    </div>

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
                                        <th class="text-right text-danger">You Gave<br><span class="text-xs font-normal">Debit</span></th>
                                        <th class="text-right text-success">You Got<br><span class="text-xs font-normal">Credit</span></th>
                                        <th class="text-right">Balance</th>
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
                                            <?php elseif ($e['entry_type'] === 'you_gave'): ?>
                                                <span class="badge badge-danger font-bold">YOU GAVE</span>
                                            <?php elseif ($e['entry_type'] === 'you_got'): ?>
                                                <span class="badge badge-success font-bold">YOU GOT</span>
                                            <?php else: ?>
                                                <span class="badge badge-subtle"><?= strtoupper(e($e['entry_type'])) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="font-medium text-sm"><?= e($e['description']) ?></div>
                                            <?php if (!empty($e['reference_number'])): ?>
                                                <div class="text-xs text-muted">Ref: <strong><?= e($e['reference_number']) ?></strong></div>
                                            <?php endif; ?>
                                            <?php if (!empty($e['item_details'])): ?>
                                                <div class="text-xs text-secondary mt-1"><i class="ti ti-package mr-1"></i><strong>Items:</strong> <?= e($e['item_details']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($e['notes'])): ?>
                                                <div class="text-xs text-muted italic"><?= e($e['notes']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($e['image_attachment'])): ?>
                                                <div class="mt-1">
                                                    <a href="<?= e($e['image_attachment']) ?>" target="_blank" class="badge badge-subtle text-primary" style="text-decoration: none;">
                                                        <i class="ti ti-photo mr-1"></i> View Attachment
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($e['voice_attachment'])): ?>
                                                <div class="mt-1">
                                                    <a href="<?= e($e['voice_attachment']) ?>" target="_blank" class="badge badge-subtle text-info" style="text-decoration: none;">
                                                        <i class="ti ti-volume mr-1"></i> Audio Note
                                                    </a>
                                                </div>
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
    <div class="modal-card" style="max-width: 520px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <div class="modal-header" style="flex-shrink: 0;">
            <h3 class="modal-title"><i class="ti ti-cash mr-1 text-success"></i> Receive Customer Payment</h3>
            <button type="button" class="modal-close" data-modal-dismiss>&times;</button>
        </div>
        <form method="POST" action="<?= url('customer-view.php?id=' . $customer['id']) ?>" style="display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; margin: 0;">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="record_khata_payment">
            <input type="hidden" name="idempotency_key" value="khata_pay_<?= $businessId ?>_<?= $customerId ?>_<?= time() ?>_<?= rand(100, 999) ?>">

            <div class="modal-body" style="flex: 1 1 auto; overflow-y: auto; min-height: 0; padding: 1.25rem;">
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
                    <label class="form-label required">Payment Amount</label>
                    <div class="input-prefix-wrapper">
                        <span class="input-prefix"><?= e($currency) ?></span>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-input" 
                               value="<?= $currentBalance > 0 ? e((string)$currentBalance) : '' ?>" required autofocus>
                    </div>
                    <div class="text-xs text-muted mt-1">If amount exceeds dues, excess is saved as Advance Credit.</div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label required">Payment Method</label>
                    <select name="method" class="form-select" required>
                        <?php foreach ($paymentMethods as $code => $m): ?>
                            <option value="<?= e($code) ?>">
                                <?= e($m['label']) ?>
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

            <div class="modal-footer d-flex justify-between align-center" style="flex-shrink: 0; position: sticky; bottom: 0; z-index: 10; background: var(--color-surface, #ffffff); border-top: 1px solid var(--color-border, #e2e8f0); padding: 0.875rem 1.25rem;">
                <button type="button" class="btn btn-secondary" data-modal-dismiss>Cancel</button>
                <button type="submit" class="btn btn-success font-bold" style="min-width: 150px; cursor: pointer;">
                    <i class="ti ti-check mr-1"></i> Confirm & Save Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: You Gave — Debit Entry                    -->
<!-- ============================================================ -->
<div class="modal-backdrop" id="modal-you-gave" style="display: none;">
    <div class="modal-card" style="max-width: 560px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <div class="modal-header" style="border-bottom: 2px solid #ef4444; flex-shrink: 0;">
            <h3 class="modal-title text-danger">
                <i class="ti ti-arrow-up-right mr-1"></i> You Gave — Debit Entry
            </h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="<?= url('customer-view.php?id=' . $customer['id']) ?>" enctype="multipart/form-data" style="display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; margin: 0;">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="record_you_gave">
            <input type="hidden" name="idempotency_key" value="gave_<?= $businessId ?>_<?= $customerId ?>_<?= time() ?>_<?= rand(100, 999) ?>">

            <div class="modal-body" style="flex: 1 1 auto; overflow-y: auto; min-height: 0; padding: 1.25rem;">
                <div class="p-3 rounded mb-3" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2);">
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

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Amount Given *</label>
                        <div class="input-prefix-wrapper">
                            <span class="input-prefix"><?= e($currency) ?></span>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-input" placeholder="0.00" required autofocus>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Transaction Date *</label>
                        <input type="date" name="entry_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label required">Description / Details *</label>
                    <input type="text" name="description" class="form-input" placeholder="e.g. Spare parts taken on credit" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Optional Item / Product Details</label>
                    <textarea name="item_details" class="form-input" rows="2" placeholder="e.g. 1x iPhone 14 Pro OLED Display, 2x Charging Port, 1x Battery"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-2">
                    <div class="form-group">
                        <label class="form-label"><i class="ti ti-photo mr-1 text-primary"></i> Optional Image / Bill</label>
                        <input type="file" name="image_attachment" class="form-input" accept="image/*">
                        <div class="text-xs text-muted mt-1">Photo of bill, parts, or handwritten slip</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="ti ti-microphone mr-1 text-info"></i> Optional Voice Note</label>
                        <input type="file" name="voice_attachment" class="form-input" accept="audio/*">
                        <div class="text-xs text-muted mt-1">Audio recording or voice memo</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer d-flex justify-between align-center" style="flex-shrink: 0; position: sticky; bottom: 0; z-index: 10; background: var(--color-surface, #ffffff); border-top: 1px solid var(--color-border, #e2e8f0); padding: 0.875rem 1.25rem;">
                <span class="text-xs text-muted">This increases customer payable dues.</span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                    <button type="submit" id="btn-save-you-gave" class="btn btn-danger font-bold" style="min-width: 140px; cursor: pointer;">
                        <i class="ti ti-check mr-1"></i> Save You Gave
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: You Got — Credit Entry                    -->
<!-- ============================================================ -->
<div class="modal-backdrop" id="modal-you-got" style="display: none;">
    <div class="modal-card" style="max-width: 560px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <div class="modal-header" style="border-bottom: 2px solid #10b981; flex-shrink: 0;">
            <h3 class="modal-title text-success">
                <i class="ti ti-arrow-down-left mr-1"></i> You Got — Credit Entry
            </h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="<?= url('customer-view.php?id=' . $customer['id']) ?>" enctype="multipart/form-data" style="display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; margin: 0;">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="record_you_got">
            <input type="hidden" name="idempotency_key" value="got_<?= $businessId ?>_<?= $customerId ?>_<?= time() ?>_<?= rand(100, 999) ?>">

            <div class="modal-body" style="flex: 1 1 auto; overflow-y: auto; min-height: 0; padding: 1.25rem;">
                <div class="p-3 rounded mb-3" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2);">
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

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Amount Received *</label>
                        <div class="input-prefix-wrapper">
                            <span class="input-prefix"><?= e($currency) ?></span>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-input" 
                                   value="<?= $currentBalance > 0 ? e((string)$currentBalance) : '' ?>" placeholder="0.00" required autofocus>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Transaction Date *</label>
                        <input type="date" name="entry_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Payment Method *</label>
                        <select name="payment_method" class="form-select" required>
                            <?php foreach ($paymentMethods as $code => $m): ?>
                                <option value="<?= e($code) ?>">
                                    <?= e($m['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Description *</label>
                        <input type="text" name="description" class="form-input" value="Cash received" required>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-2">
                    <div class="form-group">
                        <label class="form-label"><i class="ti ti-photo mr-1 text-primary"></i> Optional Payment Slip / Screenshot</label>
                        <input type="file" name="image_attachment" class="form-input" accept="image/*">
                        <div class="text-xs text-muted mt-1">Receipt, deposit slip or screenshot</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="ti ti-microphone mr-1 text-info"></i> Optional Voice Note</label>
                        <input type="file" name="voice_attachment" class="form-input" accept="audio/*">
                        <div class="text-xs text-muted mt-1">Audio recording or voice memo</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer d-flex justify-between align-center" style="flex-shrink: 0; position: sticky; bottom: 0; z-index: 10; background: var(--color-surface, #ffffff); border-top: 1px solid var(--color-border, #e2e8f0); padding: 0.875rem 1.25rem;">
                <span class="text-xs text-muted">This reduces customer payable dues.</span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                    <button type="submit" id="btn-save-you-got" class="btn btn-success font-bold" style="min-width: 140px; cursor: pointer;">
                        <i class="ti ti-check mr-1"></i> Save You Got
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
    </div>
</div>


<!-- ============================================================ -->
<!-- MODAL: Edit Customer Profile                                 -->
<!-- ============================================================ -->
<div class="modal-backdrop" id="edit-customer-modal" style="display: none;">
    <div class="modal-card" style="max-width: 560px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <div class="modal-header" style="flex-shrink: 0;">
            <h3 class="modal-title">
                <i class="ti ti-user-edit mr-1 text-primary"></i> Edit Customer Profile
            </h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="<?= url('customer-view.php?id=' . $customer['id']) ?>" style="display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; margin: 0;">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="update_customer">

            <div class="modal-body" style="flex: 1 1 auto; overflow-y: auto; min-height: 0; padding: 1.25rem;">
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Customer Name *</label>
                        <input type="text" name="name" class="form-input" value="<?= e($customer['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Shop / Company Name</label>
                        <input type="text" name="company_name" class="form-input" value="<?= e($customer['company_name'] ?? '') ?>" placeholder="e.g. Al-Madina Telecom">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">Mobile Phone</label>
                        <div class="d-flex">
                            <select name="country_code" id="edit-cust-country" class="form-select" style="width: 105px; border-top-right-radius: 0; border-bottom-right-radius: 0;">
                                <option value="+92" selected>🇵🇰 +92</option>
                                <option value="+971">🇦🇪 +971</option>
                                <option value="+966">🇸🇦 +966</option>
                                <option value="+1">🇺🇸 +1</option>
                                <option value="+44">🇬🇧 +44</option>
                                <option value="+91">🇮🇳 +91</option>
                            </select>
                            <input type="tel" name="phone" id="edit-cust-phone" class="form-input flex-1" 
                                   style="border-top-left-radius: 0; border-bottom-left-radius: 0;"
                                   value="<?= e($customer['phone'] ?? '') ?>" placeholder="03001234567" maxlength="15">
                        </div>
                        <div class="text-xs text-muted mt-1" id="edit-cust-hint">11 digits starting with 03 for Pakistan</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-input" value="<?= e($customer['email'] ?? '') ?>" placeholder="client@example.com">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">Billing City</label>
                        <input type="text" name="billing_city" class="form-input" value="<?= e($customer['billing_city'] ?? $customer['city'] ?? '') ?>" placeholder="e.g. Lahore">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?= ($customer['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($customer['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Address</label>
                    <input type="text" name="billing_address" class="form-input" value="<?= e($customer['billing_address_line1'] ?? $customer['billing_address'] ?? $customer['address'] ?? '') ?>" placeholder="Shop / Street Address">
                </div>

                <div class="form-group mb-1">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-textarea" rows="2" placeholder="Customer notes or preferences..."><?= e($customer['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="modal-footer d-flex justify-between align-center" style="flex-shrink: 0; position: sticky; bottom: 0; z-index: 10; background: var(--color-surface, #ffffff); border-top: 1px solid var(--color-border, #e2e8f0); padding: 0.875rem 1.25rem;">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary font-bold" style="min-width: 140px; cursor: pointer;">
                    <i class="ti ti-check mr-1"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
