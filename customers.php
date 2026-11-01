<?php
declare(strict_types=1);

/**
 * YBT Management Software — Customers CRM Directory
 * Integrated with Customer Khata Balances (You Gave / You Got)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/customer-service.php';
require_once __DIR__ . '/services/khata-service.php';

Auth::requireLogin();
Permissions::require('customers.view');

$businessId = Auth::businessId();
$userId = Auth::id();

// Handle Customer Creation via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_customer') {
    Permissions::require('customers.create');
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            Flash::error('Customer name is required.');
        } else {
            try {
                $newId = CustomerService::create($businessId, $_POST, $userId);
                ActivityLog::log($businessId, $userId, 'customer_created', 'customer', $newId, "Added customer {$name}");
                Flash::success("Customer '{$name}' created successfully with Khata initialized.");
                header('Location: ' . url('customer-view.php?id=' . $newId));
                exit;
            } catch (Exception $e) {
                Flash::error('Failed to create customer: ' . $e->getMessage());
            }
        }
    }
}

// Fetch Customers List
$page = currentPage();
$filters = [
    'search' => $_GET['search'] ?? '',
    'status' => $_GET['status'] ?? 'all',
    'city' => $_GET['city'] ?? ''
];

$result = CustomerService::getAll($businessId, $filters, $page, 12);
$customers = $result['data'];
$totalPages = $result['total_pages'];
$totalCount = $result['total'];

$pageTitle = 'Customers Directory (کسٹمرز کھاتہ)';
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <h1 class="page-title">Customers & Khata CRM (کسٹمرز کھاتہ)</h1>
        <p class="page-subtitle">Manage customer profiles, contact directories, and live financial khata ledgers</p>
    </div>
    <div class="page-header-actions">
        <?php if (Permissions::has('customers.create')): ?>
        <button type="button" class="btn btn-primary" data-modal-target="new-customer-modal">
            <i class="ti ti-user-plus mr-1"></i> Add Customer (نیا کسٹمر)
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Filters Bar -->
<div class="card mb-4 filter-card">
    <div class="card-body">
        <form method="GET" action="<?= url('customers.php') ?>" class="d-flex flex-wrap gap-3 align-center">
            <div class="flex-1 min-w-200">
                <div class="input-icon-wrapper">
                    <i class="ti ti-search input-icon"></i>
                    <input type="text" name="search" class="form-input" placeholder="Search by name, company, phone, email..." value="<?= e($filters['search']) ?>">
                </div>
            </div>

            <div style="width: 160px;">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">
                <i class="ti ti-filter mr-1"></i> Filter
            </button>
            <?php if (!empty($filters['search']) || $filters['status'] !== 'all'): ?>
                <a href="<?= url('customers.php') ?>" class="btn btn-ghost text-muted">Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Customers Table -->
<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title">Customer List (<?= $totalCount ?>)</h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($customers)): ?>
            <div class="empty-state p-5 text-center">
                <i class="ti ti-users text-4xl text-muted mb-2"></i>
                <h3>No Customers Found</h3>
                <p class="text-muted text-sm mb-3">No customers match your criteria or none have been added yet.</p>
                <?php if (Permissions::has('customers.create')): ?>
                <button type="button" class="btn btn-primary btn-sm" data-modal-target="new-customer-modal">
                    <i class="ti ti-plus mr-1"></i> Add Your First Customer
                </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Customer Name</th>
                            <th>Contact Info</th>
                            <th>City / Town</th>
                            <th class="text-right">Total Invoiced (مال دیا)</th>
                            <th class="text-right">Khata Balance (بقیہ رقم)</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                        <?php $bal = (float)($c['khata_balance'] ?? 0); ?>
                        <tr>
                            <td>
                                <a href="<?= url('customer-view.php?id=' . $c['id']) ?>" class="font-bold text-primary">
                                    <?= e($c['name']) ?>
                                </a>
                                <?php if (!empty($c['company_name'])): ?>
                                    <div class="text-xs text-muted"><?= e($c['company_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><i class="ti ti-phone text-muted mr-1"></i><strong><?= e($c['phone'] ?: '—') ?></strong></div>
                                <?php if (!empty($c['email'])): ?>
                                    <div class="text-xs text-muted"><i class="ti ti-mail text-muted mr-1"></i><?= e($c['email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($c['billing_city'] ?: $c['city'] ?: '—') ?>
                            </td>
                            <td class="text-right font-medium"><?= formatCurrency((float)$c['total_billed']) ?></td>
                            <td class="text-right font-bold">
                                <?php if ($bal > 0): ?>
                                    <span class="badge badge-danger">
                                        <?= formatCurrency($bal) ?> (Dr)
                                    </span>
                                    <div class="text-2xs text-danger">Customer Owes</div>
                                <?php elseif ($bal < 0): ?>
                                    <span class="badge badge-success">
                                        <?= formatCurrency(abs($bal)) ?> (Cr)
                                    </span>
                                    <div class="text-2xs text-success">Customer Advance</div>
                                <?php else: ?>
                                    <span class="badge badge-subtle">
                                        0.00 (Cleared)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $c['status'] === 'active' ? 'success' : 'secondary' ?>">
                                    <?= ucfirst($c['status']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <a href="<?= url('customer-view.php?id=' . $c['id'] . '&tab=khata') ?>" class="btn btn-icon btn-ghost text-primary" title="View Khata Ledger">
                                        <i class="ti ti-notebook"></i>
                                    </a>
                                    <a href="<?= url('customer-ledger-print.php?id=' . $c['id']) ?>" target="_blank" class="btn btn-icon btn-ghost" title="Print Statement">
                                        <i class="ti ti-printer"></i>
                                    </a>
                                    <?php if (Permissions::has('invoices.create')): ?>
                                    <a href="<?= url('invoice-create.php?customer_id=' . $c['id']) ?>" class="btn btn-icon btn-ghost text-secondary" title="Create Invoice">
                                        <i class="ti ti-receipt"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="p-3 d-flex justify-between align-center">
                <span class="text-sm text-muted">Showing page <?= $page ?> of <?= $totalPages ?></span>
                <?= paginate($totalCount, 12, $page, url('customers.php?search=' . urlencode($filters['search']) . '&status=' . urlencode($filters['status']))) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal-backdrop" id="new-customer-modal">
    <div class="modal-card modal-lg">
        <div class="modal-header">
            <h3 class="modal-title"><i class="ti ti-user-plus mr-1 text-primary"></i> Add New Customer & Open Khata</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
        </div>
        <form action="<?= url('customers.php') ?>" method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_customer">

            <div class="modal-body">
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Customer / Shop Name</label>
                        <input type="text" name="name" class="form-input" placeholder="e.g. Tariq Mehmood" required autofocus>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Company / Shop Trade Name</label>
                        <input type="text" name="company_name" class="form-input" placeholder="e.g. Al-Madina Telecom">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Mobile Phone (+92 / Local)</label>
                        <input type="text" name="phone" class="form-input" placeholder="+92 300 1234567" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-input" placeholder="customer@example.com">
                    </div>
                </div>

                <!-- Opening Balance Khata Initializer -->
                <div class="p-3 bg-subtle rounded mb-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="form-group">
                            <label class="form-label font-bold">Opening Balance (ابتدائی بقایا)</label>
                            <input type="number" step="0.01" min="0" name="opening_balance" class="form-input" placeholder="0.00">
                            <div class="text-xs text-muted mt-1">Leave 0 if starting fresh with zero balance.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label font-bold">Balance Type (قسم)</label>
                            <select name="opening_balance_type" class="form-select">
                                <option value="debit" selected>Customer Owes (آپ نے لینے ہیں - Debit)</option>
                                <option value="credit">Customer Advance (کسٹمر کی امانت - Credit)</option>
                            </select>
                            <div class="text-xs text-muted mt-1">Debit = Customer dues, Credit = Advance balance.</div>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Address & City</label>
                    <input type="text" name="billing_address_line1" class="form-input mb-2" placeholder="Shop #, Plaza, Market / Street address">
                    <div class="grid grid-cols-3 gap-2">
                        <input type="text" name="billing_city" class="form-input" placeholder="City (e.g. Lahore)">
                        <input type="text" name="billing_state" class="form-input" placeholder="Province / State (e.g. Punjab)">
                        <input type="text" name="billing_postal_code" class="form-input" placeholder="Postal Code">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Internal Notes / Ledger Remarks</label>
                    <textarea name="notes" class="form-input" rows="2" placeholder="Credit limit, repair terms, reference notes..."></textarea>
                </div>
            </div>

            <div class="modal-footer d-flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-check mr-1"></i> Save Customer & Khata</button>
            </div>
        </form>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
