<?php
/**
 * YBT Management Software — Expenses Tracking
 */

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();
Permissions::require('expenses.view');

$businessId = Auth::businessId();
$userId = Auth::id();

// Handle Log Expense POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_expense') {
    Permissions::require('expenses.create');
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $desc = trim($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $catId = (int)($_POST['category_id'] ?? 0);

        if (empty($desc)) {
            Flash::error('Expense description is required.');
        } elseif ($amount <= 0) {
            Flash::error('Please enter a valid expense amount.');
        } elseif ($catId <= 0) {
            Flash::error('Please select an expense category.');
        } else {
            $expUuid = generateUuidV4();
            $expId = Database::insert('expenses', [
                'uuid' => $expUuid,
                'business_id' => $businessId,
                'category_id' => $catId,
                'description' => $desc,
                'amount' => $amount,
                'expense_date' => $_POST['expense_date'] ?? date('Y-m-d'),
                'payment_method' => $_POST['method'] ?? 'bank_transfer',
                'method' => $_POST['method'] ?? 'bank_transfer',
                'payee' => trim($_POST['vendor'] ?? ''),
                'vendor' => trim($_POST['vendor'] ?? ''),
                'reference_number' => trim($_POST['reference'] ?? ''),
                'reference' => trim($_POST['reference'] ?? ''),
                'is_deleted' => 0,
                'status' => 'active',
                'created_by' => $userId
            ]);

            // Auto-enqueue expense into pending_operations for cloud sync
            try {
                $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? (getenv('YBT_DEVICE_ID') ?: 'desktop-default');
                $expPayload = [
                    'uuid' => $expUuid,
                    'category_id' => $catId,
                    'description' => $desc,
                    'amount' => $amount,
                    'expense_date' => $_POST['expense_date'] ?? date('Y-m-d'),
                    'payment_method' => $_POST['method'] ?? 'bank_transfer',
                    'payee' => trim($_POST['vendor'] ?? ''),
                    'reference_number' => trim($_POST['reference'] ?? '')
                ];
                Database::insert('pending_operations', [
                    'operation_uuid' => generateUuidV4(),
                    'business_id' => $businessId,
                    'device_id' => $deviceId,
                    'entity_type' => 'expenses',
                    'entity_uuid' => $expUuid,
                    'operation_type' => 'insert',
                    'payload_json' => json_encode($expPayload),
                    'retry_count' => 0,
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            } catch (\Throwable $qe) {
                error_log("Non-blocking: Failed to enqueue expense: " . $qe->getMessage());
            }

            ActivityLog::log($businessId, $userId, 'expense_logged', 'expense', $expId, "Logged expense '{$desc}' of ₹" . number_format($amount, 2));
            Flash::success("Expense logged successfully.");
            header('Location: ' . url('expenses.php'));
            exit;
        }
    }
}

// Fetch categories
$categories = Database::fetchAll("SELECT * FROM expense_categories WHERE business_id = ? ORDER BY name ASC", [$businessId]);

// Fetch Expenses List
$expenses = Database::fetchAll(
    "SELECT e.*, 
            e.payee as vendor, 
            e.payment_method as method, 
            e.reference_number as reference, 
            c.name as category_name, 
            '' as category_color
     FROM expenses e
     LEFT JOIN expense_categories c ON e.category_id = c.id
     WHERE e.business_id = ? AND e.is_deleted = 0
     ORDER BY e.expense_date DESC",
    [$businessId]
);

$totalExpenses = array_sum(array_column($expenses, 'amount'));

$pageTitle = 'Expense Tracking';
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <h1 class="page-title">Expense Tracking</h1>
        <p class="page-subtitle">Record operational costs, software licenses, vendor bills, and tax deductions</p>
    </div>
    <div class="page-header-actions d-flex gap-2 align-center">
        <div class="badge badge-subtle p-2">
            <span class="text-xs text-muted">Total Recorded:</span>
            <strong class="text-danger ml-1"><?= formatCurrency($totalExpenses) ?></strong>
        </div>
        <?php if (Permissions::has('expenses.create')): ?>
        <button type="button" class="btn btn-primary" data-modal-target="log-expense-modal">
            <i class="ti ti-plus mr-1"></i> Log Expense
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title">Recorded Expenses (<?= count($expenses) ?>)</h3>
        <div style="width: 250px;">
            <input type="text" class="form-input form-input-sm" placeholder="Filter expenses..." data-table-filter="#expenses-table">
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($expenses)): ?>
            <div class="empty-state p-5 text-center">
                <i class="ti ti-wallet text-4xl text-muted mb-2"></i>
                <h3>No Expenses Recorded</h3>
                <p class="text-muted text-sm mb-3">Keep track of vendor disbursements and operating overheads.</p>
                <?php if (Permissions::has('expenses.create')): ?>
                <button type="button" class="btn btn-primary btn-sm" data-modal-target="log-expense-modal">
                    <i class="ti ti-plus mr-1"></i> Log First Expense
                </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table" id="expenses-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Vendor / Payee</th>
                            <th>Payment Method</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $ex): ?>
                        <tr>
                            <td><?= formatDate($ex['expense_date']) ?></td>
                            <td>
                                <span class="badge" style="background-color: <?= e($ex['category_color']) ?>15; color: <?= e($ex['category_color']) ?>; border: 1px solid <?= e($ex['category_color']) ?>40;">
                                    <?= e($ex['category_name']) ?>
                                </span>
                            </td>
                            <td><strong><?= e($ex['description']) ?></strong></td>
                            <td><?= e($ex['vendor'] ?: '—') ?></td>
                            <td><span class="badge badge-subtle"><?= ucfirst(str_replace('_', ' ', $ex['method'])) ?></span></td>
                            <td class="text-right font-bold text-danger">
                                <?= formatCurrency($ex['amount']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Log Expense Modal -->
<div class="modal-backdrop" id="log-expense-modal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title"><i class="ti ti-wallet mr-1 text-danger"></i> Log Business Expense</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form action="<?= url('expenses.php') ?>" method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="log_expense">

            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label required">Category</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">— Select Category —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label required">Description / Item</label>
                    <input type="text" name="description" class="form-input" placeholder="e.g. AWS Cloud Hosting, Office Stationery" required>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label required">Amount (₹)</label>
                        <input type="number" name="amount" class="form-input" placeholder="1500" min="0.01" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Expense Date</label>
                        <input type="date" name="expense_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">Vendor / Merchant</label>
                        <input type="text" name="vendor" class="form-input" placeholder="e.g. Amazon Web Services">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <select name="method" class="form-select">
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="credit_card">Company Card</option>
                            <option value="upi">UPI</option>
                            <option value="cash">Cash</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-footer d-flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-check mr-1"></i> Save Expense</button>
            </div>
        </form>
    </div>
</div>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
