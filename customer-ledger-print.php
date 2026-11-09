<?php
declare(strict_types=1);

/**
 * DJ CRM Software — Customer Khata / Ledger Printable Statement
 * Clean, print-optimized customer ledger statement (You Gave / You Got)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/khata-service.php';

Auth::requireLogin();
Permissions::require('customers.view');

$businessId = Auth::businessId();
$customerId = (int)($_GET['id'] ?? 0);
$fromDate = !empty($_GET['from_date']) ? trim($_GET['from_date']) : null;
$toDate = !empty($_GET['to_date']) ? trim($_GET['to_date']) : null;

if ($customerId <= 0) {
    Flash::error('Customer not found.');
    header('Location: ' . url('customers.php'));
    exit;
}

$statement = KhataService::getStatement($customerId, $businessId, $fromDate, $toDate);
$customer = $statement['customer'];
$currency = $statement['currency'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($customer['name']) ?> — Customer Ledger Statement</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 13px; color: #1e293b; background: #fff; padding: 24px; line-height: 1.5; }
        .statement-header { border-bottom: 2px solid #0f172a; padding-bottom: 16px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; }
        .shop-name { font-size: 24px; font-weight: 800; color: #0f172a; }
        .statement-title { font-size: 18px; font-weight: 700; color: #3b82f6; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .meta-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; }
        .meta-box h4 { font-size: 12px; text-transform: uppercase; color: #64748b; margin-bottom: 8px; font-weight: 700; }
        .kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
        .kpi-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; text-align: center; }
        .kpi-label { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600; }
        .kpi-val { font-size: 18px; font-weight: 800; margin-top: 4px; }
        .text-debit { color: #dc2626; }
        .text-credit { color: #16a34a; }
        .text-primary { color: #2563eb; }
        table.ledger-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 12px; }
        table.ledger-table th { background: #0f172a; color: #fff; text-align: left; padding: 10px 8px; font-weight: 600; border: 1px solid #0f172a; }
        table.ledger-table td { padding: 9px 8px; border: 1px solid #cbd5e1; }
        table.ledger-table tr:nth-child(even) { background: #f8fafc; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .balance-summary { border-top: 2px solid #0f172a; padding-top: 16px; display: flex; justify-content: flex-end; }
        .summary-table { width: 340px; border-collapse: collapse; }
        .summary-table td { padding: 6px 10px; }
        .summary-table tr.total-row { font-size: 15px; font-weight: 800; border-top: 2px solid #cbd5e1; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-debit { background: #fee2e2; color: #991b1b; }
        .badge-credit { background: #dcfce7; color: #166534; }
        .badge-neutral { background: #e2e8f0; color: #334155; }
        .print-btn { background: #2563eb; color: #fff; border: none; padding: 10px 20px; font-size: 14px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .no-print { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; font-size: 11px; }
            .kpi-val { font-size: 15px; }
            table.ledger-table th, table.ledger-table td { padding: 6px 4px; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <a href="<?= url('customer-view.php?id=' . $customer['id']) ?>" style="color: #2563eb; text-decoration: none; font-weight: 600;">
        &larr; Back to Customer Profile
    </a>
    <button class="print-btn" onclick="window.print();">Print Statement</button>
</div>

<div class="statement-header">
    <div>
        <div class="shop-name"><?= e($customer['business_name'] ?? 'Mobile Care & Telecom') ?></div>
        <div style="color: #64748b; font-size: 12px; margin-top: 4px;">
            <?= e($customer['business_address'] ?: 'Commercial Area') ?> <?= e($customer['business_city'] ? ', ' . $customer['business_city'] : '') ?><br>
            Phone: <?= e($customer['business_phone'] ?: '—') ?> | Email: <?= e($customer['business_email'] ?: '—') ?>
        </div>
    </div>
    <div style="text-align: right;">
        <div class="statement-title">Customer Ledger Statement</div>
        <div style="font-size: 11px; color: #64748b; margin-top: 6px;">
            Statement Date: <strong><?= date('d M Y') ?></strong>
            <?php if ($fromDate || $toDate): ?>
                <br>Period: <?= e($fromDate ?: 'Beginning') ?> to <?= e($toDate ?: 'Today') ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="meta-grid">
    <div class="meta-box">
        <h4>Customer Information</h4>
        <div style="font-size: 16px; font-weight: 700; color: #0f172a;"><?= e($customer['name']) ?></div>
        <?php if (!empty($customer['company_name'])): ?>
            <div style="color: #475569;"><?= e($customer['company_name']) ?></div>
        <?php endif; ?>
        <div style="margin-top: 6px; font-size: 12px; color: #334155;">
            Phone: <strong><?= e($customer['phone'] ?: '—') ?></strong><br>
            Address: <?= e(($customer['billing_address_line1'] ?? $customer['billing_address'] ?? $customer['address'] ?? '') ?: '—') ?>
        </div>
    </div>
    <div class="meta-box">
        <h4>Account Status</h4>
        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
            <span>Current Khata Balance:</span>
            <span class="font-bold <?= $statement['current_balance'] > 0 ? 'text-debit' : ($statement['current_balance'] < 0 ? 'text-credit' : '') ?>">
                <?= e($currency) ?> <?= number_format(abs($statement['current_balance']), 2) ?>
                <span class="badge <?= $statement['current_balance'] > 0 ? 'badge-debit' : ($statement['current_balance'] < 0 ? 'badge-credit' : 'badge-neutral') ?>">
                    <?= $statement['current_balance'] > 0 ? 'Owed by Customer' : ($statement['current_balance'] < 0 ? 'Advance Credit' : 'Cleared') ?>
                </span>
            </span>
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 12px; color: #64748b;">
            <span>Total Transactions:</span>
            <span><?= count($statement['entries']) ?> entries</span>
        </div>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-label">Opening Balance</div>
        <div class="kpi-val text-primary"><?= e($currency) ?> <?= number_format((float)$customer['opening_balance'], 2) ?></div>
        <div style="font-size: 10px; color: #64748b;"><?= ucfirst($customer['opening_balance_type'] ?? 'debit') ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">You Gave</div>
        <div class="kpi-val text-debit"><?= e($currency) ?> <?= number_format($statement['total_gave'], 2) ?></div>
        <div style="font-size: 10px; color: #64748b;">Total Invoices & Debits</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">You Got</div>
        <div class="kpi-val text-credit"><?= e($currency) ?> <?= number_format($statement['total_got'], 2) ?></div>
        <div style="font-size: 10px; color: #64748b;">Total Received Collections</div>
    </div>
    <div class="kpi-card" style="background: #f1f5f9;">
        <div class="kpi-label">Net Balance</div>
        <div class="kpi-val <?= $statement['current_balance'] > 0 ? 'text-debit' : 'text-credit' ?>">
            <?= e($currency) ?> <?= number_format(abs($statement['current_balance']), 2) ?>
        </div>
        <div style="font-size: 10px; font-weight: 600; color: #475569;">
            <?= $statement['current_balance'] > 0 ? 'Customer Owes' : ($statement['current_balance'] < 0 ? 'Advance Credit' : 'Cleared') ?>
        </div>
    </div>
</div>

<!-- Ledger Transactions Table -->
<table class="ledger-table">
    <thead>
        <tr>
            <th style="width: 90px;">Date</th>
            <th style="width: 120px;">Type</th>
            <th>Description & Reference</th>
            <th style="width: 100px;">Method</th>
            <th class="text-right" style="width: 120px;">You Gave<br><span style="font-size: 9px; font-weight: normal;">(Debit)</span></th>
            <th class="text-right" style="width: 120px;">You Got<br><span style="font-size: 9px; font-weight: normal;">(Credit)</span></th>
            <th class="text-right" style="width: 130px;">Balance</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($statement['period_opening_balance'] != 0): ?>
        <tr style="background: #f1f5f9; font-weight: 600;">
            <td><?= e($fromDate) ?></td>
            <td><span class="badge badge-neutral">OPENING</span></td>
            <td>Period Opening Balance Brought Forward</td>
            <td>—</td>
            <td class="text-right text-debit"><?= $statement['period_opening_balance'] > 0 ? number_format($statement['period_opening_balance'], 2) : '—' ?></td>
            <td class="text-right text-credit"><?= $statement['period_opening_balance'] < 0 ? number_format(abs($statement['period_opening_balance']), 2) : '—' ?></td>
            <td class="text-right font-bold"><?= number_format($statement['period_opening_balance'], 2) ?></td>
        </tr>
        <?php endif; ?>

        <?php if (empty($statement['entries'])): ?>
        <tr>
            <td colspan="7" style="text-align: center; padding: 24px; color: #64748b;">
                No financial transactions recorded in this period.
            </td>
        </tr>
        <?php else: ?>
            <?php foreach ($statement['entries'] as $e): ?>
            <tr>
                <td><?= date('d M Y', strtotime($e['entry_date'])) ?></td>
                <td>
                    <?php if ($e['entry_type'] === 'invoice'): ?>
                        <span class="badge badge-debit">INVOICE</span>
                    <?php elseif ($e['entry_type'] === 'payment'): ?>
                        <span class="badge badge-credit">PAYMENT</span>
                    <?php elseif ($e['entry_type'] === 'advance_payment'): ?>
                        <span class="badge badge-credit">ADVANCE</span>
                    <?php elseif ($e['entry_type'] === 'invoice_cancelled'): ?>
                        <span class="badge badge-neutral">CANCELLED</span>
                    <?php elseif ($e['entry_type'] === 'you_gave'): ?>
                        <span class="badge badge-debit">YOU GAVE</span>
                    <?php elseif ($e['entry_type'] === 'you_got'): ?>
                        <span class="badge badge-credit">YOU GOT</span>
                    <?php else: ?>
                        <span class="badge badge-neutral"><?= strtoupper(e($e['entry_type'])) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= e($e['description']) ?></strong>
                    <?php if (!empty($e['reference_number'])): ?>
                        <span style="color: #64748b;">(Ref: <?= e($e['reference_number']) ?>)</span>
                    <?php endif; ?>
                    <?php if (!empty($e['item_details'])): ?>
                        <div style="font-size: 11px; color: #475569; margin-top: 2px;">Items: <?= e($e['item_details']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($e['notes'])): ?>
                        <div style="font-size: 11px; color: #64748b;"><?= e($e['notes']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="text-transform: capitalize;"><?= e($e['payment_method'] ?: '—') ?></td>
                <td class="text-right font-bold text-debit">
                    <?= (float)$e['debit'] > 0 ? number_format((float)$e['debit'], 2) : '—' ?>
                </td>
                <td class="text-right font-bold text-credit">
                    <?= (float)$e['credit'] > 0 ? number_format((float)$e['credit'], 2) : '—' ?>
                </td>
                <td class="text-right font-bold <?= (float)$e['running_balance'] > 0 ? 'text-debit' : ((float)$e['running_balance'] < 0 ? 'text-credit' : '') ?>">
                    <?= number_format(abs((float)$e['running_balance']), 2) ?>
                    <span style="font-size: 10px; font-weight: normal;">
                        <?= (float)$e['running_balance'] > 0 ? 'Dr' : ((float)$e['running_balance'] < 0 ? 'Cr' : '') ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- Statement Closing Summary -->
<div class="balance-summary">
    <table class="summary-table">
        <tr>
            <td>Total You Gave (Debit):</td>
            <td class="text-right font-bold text-debit"><?= e($currency) ?> <?= number_format($statement['total_gave'], 2) ?></td>
        </tr>
        <tr>
            <td>Total You Got (Credit):</td>
            <td class="text-right font-bold text-credit"><?= e($currency) ?> <?= number_format($statement['total_got'], 2) ?></td>
        </tr>
        <tr class="total-row">
            <td>Net Closing Balance:</td>
            <td class="text-right <?= $statement['current_balance'] > 0 ? 'text-debit' : 'text-credit' ?>">
                <?= e($currency) ?> <?= number_format(abs($statement['current_balance']), 2) ?>
                <div style="font-size: 11px; font-weight: 600;">
                    <?= $statement['current_balance'] > 0 ? 'Owed by Customer' : ($statement['current_balance'] < 0 ? 'Customer Advance' : 'Settled') ?>
                </div>
            </td>
        </tr>
    </table>
</div>

<div style="margin-top: 40px; border-top: 1px dashed #cbd5e1; padding-top: 16px; display: flex; justify-content: space-between; font-size: 11px; color: #64748b;">
    <div>Generated by DJ CRM Software on <?= date('Y-m-d H:i:s') ?></div>
    <div>Customer Signature: _________________________</div>
</div>

</body>
</html>
