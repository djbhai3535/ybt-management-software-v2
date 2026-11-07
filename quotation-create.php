<?php
/**
 * YBT Management Software — Create Commercial Quotation
 */

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();
Permissions::require('quotations.create');

$businessId = Auth::businessId();
$userId = Auth::id();

$customers = Database::fetchAll("SELECT id, name, company_name FROM customers WHERE business_id = ? AND status = 'active' ORDER BY name ASC", [$businessId]);
$products = Database::fetchAll("SELECT * FROM products WHERE business_id = ? AND status = 'active' ORDER BY name ASC", [$businessId]);
$business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);

// Auto-sequence quotation number
$qPrefix = $business['quotation_prefix'] ?? 'QT-2026-';
$qNext = $business['quotation_next_number'] ?? 1004;
$suggestedQNumber = $qPrefix . str_pad((string)$qNext, 4, '0', STR_PAD_LEFT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $itemsData = $_POST['items'] ?? [];

        if ($customerId <= 0) {
            Flash::error('Please select a customer.');
        } elseif (empty($itemsData)) {
            Flash::error('Please include at least one item.');
        } else {
            $db = Database::getInstance();
            $db->beginTransaction();

            try {
                $subtotal = 0.0;
                $taxTotal = 0.0;
                $processed = [];

                foreach ($itemsData as $idx => $it) {
                    $desc = trim($it['name'] ?? $it['description'] ?? '');
                    if ($desc === '') continue;

                    $qty = max(0.001, (float)($it['quantity'] ?? 1));
                    $price = max(0, (float)($it['unit_price'] ?? 0));
                    $taxRate = max(0, (float)($it['tax_rate'] ?? 18));

                    $base = $qty * $price;
                    $taxAmt = ($base * $taxRate) / 100;
                    $lineTot = $base + $taxAmt;

                    $subtotal += $base;
                    $taxTotal += $taxAmt;

                    $processed[] = [
                        'description' => $desc,
                        'hsn_sac' => trim($it['hsn'] ?? ''),
                        'quantity' => $qty,
                        'unit' => 'Nos',
                        'unit_price' => $price,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmt,
                        'line_total' => $lineTot,
                        'sort_order' => $idx + 1
                    ];
                }

                $grandTotal = $subtotal + $taxTotal;
                $qNum = trim($_POST['quotation_number'] ?? $suggestedQNumber);
                $qDate = $_POST['quotation_date'] ?? date('Y-m-d');
                $valDays = (int)($_POST['valid_days'] ?? 30);
                $validityDate = date('Y-m-d', strtotime("+$valDays days", strtotime($qDate)));

                $qId = Database::insert('quotations', [
                    'business_id' => $businessId,
                    'customer_id' => $customerId,
                    'quotation_number' => $qNum,
                    'issue_date' => $qDate,
                    'quotation_date' => $qDate,
                    'valid_until' => $validityDate,
                    'validity_date' => $validityDate,
                    'subtotal' => $subtotal,
                    'discount_amount' => 0.00,
                    'discount_total' => 0.00,
                    'tax_amount' => $taxTotal,
                    'tax_total' => $taxTotal,
                    'total_amount' => $grandTotal,
                    'grand_total' => $grandTotal,
                    'notes' => trim($_POST['notes'] ?? ''),
                    'terms' => trim($_POST['terms'] ?? $business['default_terms'] ?? ''),
                    'status' => 'sent',
                    'created_by' => $userId
                ]);

                foreach ($processed as $row) {
                    $row['quotation_id'] = $qId;
                    $row['item_name'] = $row['description'];
                    $row['item_description'] = $row['description'];
                    $row['total_amount'] = $row['line_total'];
                    Database::insert('quotation_items', $row);
                }

                // Increment sequence
                Database::query("UPDATE businesses SET quotation_next_number = quotation_next_number + 1 WHERE id = ?", [$businessId]);

                ActivityLog::log($businessId, $userId, 'quotation_created', 'quotation', $qId, "Created quotation {$qNum} for ₹" . number_format($grandTotal, 2));

                $db->commit();
                Flash::success("Quotation {$qNum} created successfully.");
                header('Location: ' . url('quotations.php'));
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                Flash::error('Error creating quotation: ' . $e->getMessage());
            }
        }
    }
}

$pageTitle = 'Create Quotation';
$pageScripts = ['forms.js'];
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <div class="d-flex align-center gap-2 mb-1">
            <a href="<?= url('quotations.php') ?>" class="text-muted"><i class="ti ti-arrow-left"></i> Quotations</a>
            <span class="text-muted">/</span>
            <span>New Estimate</span>
        </div>
        <h1 class="page-title">Create Quotation / Proposal</h1>
    </div>
</div>

<form method="POST" action="<?= url('quotation-create.php') ?>" id="quotation-form">
    <?= Csrf::field() ?>

    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">Quotation Details</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <div class="form-group">
                    <label class="form-label required">Customer</label>
                    <select name="customer_id" class="form-select" required>
                        <option value="">— Select Customer —</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ((int)($_GET['customer_id'] ?? 0) === $c['id']) ? 'selected' : '' ?>>
                                <?= e($c['name']) ?> <?= !empty($c['company_name']) ? '(' . e($c['company_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label required">Quotation Number</label>
                    <input type="text" name="quotation_number" class="form-input" value="<?= e($suggestedQNumber) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Quotation Date</label>
                    <input type="date" name="quotation_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="form-group">
                    <label class="form-label">Validity Period (Days)</label>
                    <input type="number" name="valid_days" class="form-input" value="30" min="1">
                </div>
                <div class="form-group col-span-2">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-input" placeholder="Proposal summary or special terms...">
                </div>
            </div>
        </div>
    </div>

    <!-- Line Items Table -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-between align-center">
            <h3 class="card-title">Line Items & Pricing</h3>
            <div class="d-flex gap-2">
                <select class="form-select form-select-sm item-select-product" style="width: 250px;">
                    <option value="">+ Insert from Catalog...</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= $p['selling_price'] ?>" data-tax="<?= $p['tax_rate'] ?>" data-description="<?= e($p['description']) ?>">
                            <?= e($p['name']) ?> (₹<?= number_format($p['selling_price'], 2) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-secondary btn-sm" id="add-item-btn">
                    <i class="ti ti-plus mr-1"></i> Add Row
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="data-table" id="line-items-table">
                <thead>
                    <tr>
                        <th style="width: 40%;">Item / Description</th>
                        <th style="width: 12%;">HSN/SAC</th>
                        <th style="width: 10%;">Qty</th>
                        <th style="width: 14%;">Rate (₹)</th>
                        <th style="width: 10%;">Tax (%)</th>
                        <th style="width: 14%;" class="text-right">Total (₹)</th>
                        <th style="width: 50px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="line-item-row">
                        <td>
                            <input type="text" name="items[0][name]" class="form-input item-name" placeholder="Item or service name" required>
                            <textarea name="items[0][description]" class="form-input form-textarea-sm item-description mt-1" rows="1" placeholder="Description (optional)"></textarea>
                        </td>
                        <td><input type="text" name="items[0][hsn]" class="form-input form-input-sm" placeholder="HSN/SAC"></td>
                        <td><input type="number" name="items[0][quantity]" class="form-input item-qty" value="1" min="0.01" step="any" required></td>
                        <td><input type="number" name="items[0][unit_price]" class="form-input item-price" value="0.00" min="0" step="0.01" required></td>
                        <td><input type="number" name="items[0][tax_rate]" class="form-input item-tax-rate" value="18" min="0" step="0.5"></td>
                        <td class="text-right"><strong class="item-total-display">0.00</strong></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-icon btn-ghost text-danger remove-row-btn" title="Remove row">
                                <i class="ti ti-trash"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-end p-4">
            <div style="width: 320px;">
                <div class="d-flex justify-between mb-2">
                    <span class="text-muted">Subtotal:</span>
                    <strong id="display-subtotal">₹0.00</strong>
                </div>
                <div class="d-flex justify-between mb-2">
                    <span class="text-muted">Tax (GST):</span>
                    <strong id="display-tax">₹0.00</strong>
                </div>
                <div class="d-flex justify-between pt-2 border-top">
                    <span class="text-lg font-bold">Total Estimate:</span>
                    <span class="text-lg font-bold text-primary" id="display-grand-total">₹0.00</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="d-flex justify-end gap-2 mb-5">
        <a href="<?= url('quotations.php') ?>" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg"><i class="ti ti-check mr-1"></i> Save Quotation</button>
    </div>
</form>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
