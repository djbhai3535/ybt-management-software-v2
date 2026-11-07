<?php
/**
 * YBT Management Software — Quotations Directory & Proposals
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/invoice-service.php';

Auth::requireLogin();
Permissions::require('quotations.view');

$businessId = Auth::businessId();
$userId = Auth::id();

// Handle Convert Quotation to Invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'convert_to_invoice') {
    Permissions::require('invoices.create');
    if (Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $qtId = (int)$_POST['quotation_id'];
        $qt = Database::fetch("SELECT * FROM quotations WHERE id = ? AND business_id = ?", [$qtId, $businessId]);
        
        if ($qt) {
            $items = Database::fetchAll("SELECT * FROM quotation_items WHERE quotation_id = ?", [$qtId]);
            try {
                $newInvId = InvoiceService::create($businessId, [
                    'customer_id' => $qt['customer_id'],
                    'quotation_id' => $qtId,
                    'invoice_date' => date('Y-m-d'),
                    'notes' => $qt['notes'],
                    'terms' => $qt['terms'],
                    'status' => 'sent'
                ], $items, $userId);

                // Mark quotation as accepted / converted
                Database::update('quotations', [
                    'status' => 'accepted',
                    'converted_invoice_id' => $newInvId
                ], 'id = ?', [$qtId]);

                ActivityLog::log($businessId, $userId, 'quotation_converted', 'quotation', $qtId, "Converted quotation {$qt['quotation_number']} to invoice #{$newInvId}");
                Flash::success("Quotation successfully converted to invoice!");
                header('Location: ' . url('invoice-view.php?id=' . $newInvId));
                exit;
            } catch (Exception $e) {
                Flash::error('Failed to convert quotation: ' . $e->getMessage());
            }
        }
    }
}

// Fetch Quotations
$quotations = Database::fetchAll(
    "SELECT q.*, 
            q.total_amount as grand_total, 
            q.issue_date as quotation_date, 
            q.valid_until as validity_date, 
            c.name as customer_name, c.company_name 
     FROM quotations q 
     JOIN customers c ON q.customer_id = c.id 
     WHERE q.business_id = ? 
     ORDER BY q.issue_date DESC",
    [$businessId]
);

$pageTitle = 'Quotations & Estimates';
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <h1 class="page-title">Quotations & Proposals</h1>
        <p class="page-subtitle">Commercial estimates, validity windows, and 1-click invoice conversions</p>
    </div>
    <div class="page-header-actions">
        <?php if (Permissions::has('quotations.create')): ?>
        <a href="<?= url('quotation-create.php') ?>" class="btn btn-primary">
            <i class="ti ti-plus mr-1"></i> New Quotation
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title">All Quotations (<?= count($quotations) ?>)</h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($quotations)): ?>
            <div class="empty-state p-5 text-center">
                <i class="ti ti-file-text text-4xl text-muted mb-2"></i>
                <h3>No Quotations Created</h3>
                <p class="text-muted text-sm mb-3">Create professional proposals and estimates for prospective deals.</p>
                <?php if (Permissions::has('quotations.create')): ?>
                <a href="<?= url('quotation-create.php') ?>" class="btn btn-primary btn-sm">
                    <i class="ti ti-plus mr-1"></i> Create First Quotation
                </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Quotation #</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Valid Until</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-right">Grand Total</th>
                            <th>Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotations as $q): ?>
                        <tr>
                            <td>
                                <strong><?= e($q['quotation_number']) ?></strong>
                            </td>
                            <td>
                                <strong><?= e($q['customer_name']) ?></strong>
                                <?php if (!empty($q['company_name'])): ?>
                                    <div class="text-xs text-muted"><?= e($q['company_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDate($q['quotation_date']) ?></td>
                            <td><?= formatDate($q['validity_date']) ?></td>
                            <td class="text-right"><?= formatCurrency($q['subtotal']) ?></td>
                            <td class="text-right font-bold text-primary"><?= formatCurrency($q['grand_total']) ?></td>
                            <td>
                                <span class="badge badge-<?= $q['status'] === 'accepted' ? 'success' : ($q['status'] === 'sent' ? 'primary' : 'secondary') ?>">
                                    <?= ucfirst($q['status']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-center gap-1">
                                    <?php if ($q['status'] !== 'accepted' && empty($q['converted_invoice_id']) && Permissions::has('invoices.create')): ?>
                                    <form method="POST" action="<?= url('quotations.php') ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="convert_to_invoice">
                                        <input type="hidden" name="quotation_id" value="<?= $q['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Convert to Invoice">
                                            <i class="ti ti-receipt mr-1"></i> Convert to Invoice
                                        </button>
                                    </form>
                                    <?php elseif (!empty($q['converted_invoice_id'])): ?>
                                        <a href="<?= url('invoice-view.php?id=' . $q['converted_invoice_id']) ?>" class="btn btn-sm btn-ghost text-success">
                                            <i class="ti ti-check mr-1"></i> Invoice Created
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
