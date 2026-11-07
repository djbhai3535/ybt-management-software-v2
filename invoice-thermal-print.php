<?php
/**
 * YBT Management Software — Thermal Receipt Printing (80mm & 58mm)
 * Compact POS receipt printing for thermal roll printers with bilingual English/Urdu support.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/invoice-service.php';
require_once __DIR__ . '/services/whatsapp-service.php';

Auth::requireLogin();
Permissions::require('invoices.view');

$businessId = Auth::businessId();
session_write_close(); // Release session file lock immediately to prevent blocking
$invoiceId = (int)($_GET['id'] ?? 0);
$paperSize = strtolower(trim((string)($_GET['size'] ?? '80mm')));
if (!in_array($paperSize, ['80mm', '58mm'], true)) {
    $paperSize = '80mm';
}

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

// Fail-closed tenant isolation
TenantContext::assertMatches($businessId);
if ((int)$invoice['business_id'] !== $businessId) {
    http_response_code(403);
    echo "Unauthorized: Cross-tenant access forbidden.";
    exit;
}

$business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);
if (!$business) {
    $business = [
        'name' => 'Mobile Shop',
        'default_currency' => 'PKR',
        'phone' => ''
    ];
}

$currency = $invoice['currency'] ?? $business['default_currency'] ?? 'PKR';
$symbol = getCurrencySymbol($currency);
$items = $invoice['items'] ?? [];
$isCancelled = strtolower($invoice['status'] ?? '') === 'cancelled';
$autoPrint = !empty($_GET['auto_print']);

$whatsAppUrl = WhatsAppService::getClickToChatUrl($invoice, $business, url('invoice-pdf.php?id=' . $invoiceId));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($invoice['invoice_number']) ?> — Thermal Receipt (رسید)</title>
    <link rel="stylesheet" href="<?= url('assets/css/tabler-icons.min.css') ?>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #f1f5f9;
            color: #000;
            font-family: 'Courier New', Courier, Consolas, Monaco, monospace, 'Noto Nastaliq Urdu', 'Segoe UI', Tahoma;
            font-size: <?= $paperSize === '58mm' ? '11px' : '12px' ?>;
            line-height: 1.35;
            padding: 20px;
        }

        /* Screen Toolbar */
        .thermal-toolbar {
            max-width: <?= $paperSize === '58mm' ? '280px' : '360px' ?>;
            margin: 0 auto 16px auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .toolbar-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-thermal-tool {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #1e293b;
        }
        .btn-thermal-tool:hover { background: #f8fafc; }
        .btn-print-main { background: #0f172a; color: #fff; border-color: #0f172a; }
        .btn-print-main:hover { background: #1e293b; }
        .size-pills {
            display: inline-flex;
            background: #e2e8f0;
            border-radius: 6px;
            padding: 2px;
            font-family: -apple-system, sans-serif;
            font-size: 11px;
            font-weight: 700;
        }
        .size-pill {
            padding: 4px 10px;
            border-radius: 4px;
            text-decoration: none;
            color: #475569;
        }
        .size-pill.active {
            background: #fff;
            color: #0f172a;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        /* Receipt Canvas */
        .receipt-container {
            width: <?= $paperSize === '58mm' ? '56mm' : '78mm' ?>;
            max-width: <?= $paperSize === '58mm' ? '240px' : '330px' ?>;
            margin: 0 auto;
            background: #ffffff;
            padding: <?= $paperSize === '58mm' ? '6px 4px' : '10px 8px' ?>;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }
        .divider {
            border-top: 1px dashed #000;
            margin: 6px 0;
        }
        .double-divider {
            border-top: 2px solid #000;
            margin: 6px 0;
        }

        .shop-title {
            font-size: <?= $paperSize === '58mm' ? '14px' : '16px' ?>;
            font-weight: 900;
            margin-bottom: 2px;
            letter-spacing: 0.5px;
        }

        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: <?= $paperSize === '58mm' ? '10px' : '11px' ?>;
        }
        .receipt-table th {
            padding: 4px 0;
            border-bottom: 1px solid #000;
        }
        .receipt-table td {
            padding: 3px 0;
            vertical-align: top;
        }

        .urdu-text {
            font-family: 'Noto Nastaliq Urdu', 'Urdu Typesetting', Tahoma, sans-serif;
            font-size: <?= $paperSize === '58mm' ? '10px' : '11px' ?>;
        }

        @media print {
            @page {
                size: <?= $paperSize ?> auto;
                margin: 0;
            }
            body {
                background: #fff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .thermal-toolbar, .no-print {
                display: none !important;
            }
            .receipt-container {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 2mm 1.5mm !important;
                border: none !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body>

<div class="thermal-toolbar no-print">
    <div class="toolbar-row">
        <a href="<?= url('invoice-view.php?id=' . $invoice['id']) ?>" class="btn-thermal-tool">
            &larr; Back
        </a>
        <div class="size-pills">
            <a href="<?= url('invoice-thermal-print.php?id=' . $invoice['id'] . '&size=80mm') ?>" class="size-pill <?= $paperSize === '80mm' ? 'active' : '' ?>">80mm</a>
            <a href="<?= url('invoice-thermal-print.php?id=' . $invoice['id'] . '&size=58mm') ?>" class="size-pill <?= $paperSize === '58mm' ? 'active' : '' ?>">58mm</a>
        </div>
    </div>
    <div class="toolbar-row" style="gap: 8px;">
        <button class="btn-thermal-tool btn-print-main" onclick="window.print()" style="flex: 1; justify-content: center;">
            <i class="ti ti-printer"></i> Print Receipt (<?= $paperSize ?>)
        </button>
        <a href="<?= $whatsAppUrl ?>" target="_blank" class="btn-thermal-tool" style="background: #25d366; color: #fff; border-color: #25d366;" title="Share WhatsApp">
            <i class="ti ti-brand-whatsapp"></i>
        </a>
    </div>
</div>

<div class="receipt-container">
    <!-- Header -->
    <div class="text-center">
        <?php 
        $shopLogo = $business['logo_url'] ?? $business['logo_path'] ?? null;
        if (!empty($shopLogo) && file_exists(YBT_ROOT . '/' . ltrim($shopLogo, '/'))): ?>
            <div style="margin-bottom: 4px;"><img src="<?= url($shopLogo) ?>" alt="Logo" style="max-height: 44px; max-width: 140px; object-fit: contain;"></div>
        <?php endif; ?>
        <div class="shop-title"><?= e($business['name']) ?></div>
        <?php if (!empty($business['tagline'])): ?>
            <div style="font-size: 10px;"><?= e($business['tagline']) ?></div>
        <?php endif; ?>
        <?php if (!empty($business['address_line1'])): ?>
            <div style="font-size: 10px;"><?= e($business['address_line1']) ?><?= !empty($business['city']) ? ', ' . e($business['city']) : '' ?></div>
        <?php endif; ?>
        <?php if (!empty($business['phone'])): ?>
            <div style="font-size: 10px;">Phone: <?= e($business['phone']) ?></div>
        <?php endif; ?>
        <?php if (!empty($business['ntn'])): ?>
            <div style="font-size: 10px;">NTN: <?= e($business['ntn']) ?></div>
        <?php elseif (!empty($business['gstin'])): ?>
            <div style="font-size: 10px;">GSTIN: <?= e($business['gstin']) ?></div>
        <?php endif; ?>
    </div>

    <div class="divider"></div>

    <?php if ($isCancelled): ?>
    <div style="border: 2px solid #000; padding: 4px; text-align: center; margin: 4px 0; font-weight: bold;">
        *** CANCELLED / منسوخ ***<br>
        <span style="font-size: 9px;">NOT VALID FOR PAYMENT</span>
    </div>
    <div class="divider"></div>
    <?php endif; ?>

    <!-- Receipt Meta -->
    <div style="font-size: 11px;">
        <div style="display: flex; justify-content: space-between;">
            <span>Rcpt #: <strong><?= e($invoice['invoice_number']) ?></strong></span>
            <span><?= !empty($invoice['invoice_date']) ? date('d/m/Y', strtotime($invoice['invoice_date'])) : date('d/m/Y') ?></span>
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 2px;">
            <span>Time: <?= date('h:i A') ?></span>
            <span>Status: <strong><?= strtoupper(e($invoice['status'])) ?></strong></span>
        </div>
        <div style="margin-top: 3px;">
            Cust: <strong><?= e(substr($invoice['customer_name'] ?? 'Counter Sale', 0, 22)) ?></strong>
        </div>
        <?php if (!empty($invoice['customer_phone'])): ?>
        <div>
            Phone: <?= e($invoice['customer_phone']) ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="divider"></div>

    <!-- Items Table -->
    <table class="receipt-table">
        <thead>
            <tr>
                <th class="text-left" style="width: <?= $paperSize === '58mm' ? '46%' : '52%' ?>;">Item (تفصیل)</th>
                <th class="text-center" style="width: 18%;">Qty</th>
                <th class="text-right" style="width: 30%;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td colspan="3" class="font-bold" style="padding-top: 4px;">
                    <?= e($item['description']) ?>
                </td>
            </tr>
            <tr style="border-bottom: 1px dotted #ccc;">
                <td style="color: #444; font-size: 10px;">
                    @ <?= number_format((float)$item['unit_price'], 2) ?>
                </td>
                <td class="text-center">
                    <?= (int)$item['quantity'] == $item['quantity'] ? (int)$item['quantity'] : number_format((float)$item['quantity'], 2) ?>
                </td>
                <td class="text-right font-bold">
                    <?= number_format((float)$item['line_total'], 2) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="divider"></div>

    <!-- Financial Summary -->
    <div style="font-size: 11px;">
        <div style="display: flex; justify-content: space-between; padding: 1px 0;">
            <span>Subtotal (میزان):</span>
            <span><?= formatCurrency((float)$invoice['subtotal'], $symbol) ?></span>
        </div>
        <?php if ((float)$invoice['discount_total'] > 0): ?>
        <div style="display: flex; justify-content: space-between; padding: 1px 0;">
            <span>Discount (رعایت):</span>
            <span>-<?= formatCurrency((float)$invoice['discount_total'], $symbol) ?></span>
        </div>
        <?php endif; ?>
        <?php if ((float)$invoice['tax_total'] > 0): ?>
        <div style="display: flex; justify-content: space-between; padding: 1px 0;">
            <span>Tax (ٹیکس):</span>
            <span><?= formatCurrency((float)$invoice['tax_total'], $symbol) ?></span>
        </div>
        <?php endif; ?>

        <div class="double-divider"></div>

        <div style="display: flex; justify-content: space-between; font-size: <?= $paperSize === '58mm' ? '12px' : '14px' ?>; font-weight: 900;">
            <span>TOTAL (کل رقم):</span>
            <span><?= formatCurrency((float)$invoice['grand_total'], $symbol) ?></span>
        </div>

        <div style="display: flex; justify-content: space-between; padding: 2px 0; margin-top: 3px;">
            <span>Paid (وصول شدہ):</span>
            <span class="font-bold"><?= formatCurrency((float)$invoice['amount_paid'], $symbol) ?></span>
        </div>

        <div style="display: flex; justify-content: space-between; padding: 2px 0; font-weight: bold;">
            <span>Balance (بقیہ رقم):</span>
            <span><?= formatCurrency((float)$invoice['outstanding'], $symbol) ?></span>
        </div>
    </div>

    <div class="divider"></div>

    <!-- Footer -->
    <div class="text-center" style="font-size: 10px; margin-top: 6px;">
        <div class="font-bold">Thank you for your visit! / شکریہ</div>
        <div>No warranty without original receipt</div>
        <div class="urdu-text" style="margin-top: 2px;">سامان کی واپسی رسید کے بغیر ممکن نہیں۔</div>
        <div style="margin-top: 8px; font-size: 9px; color: #555;">
            === YBT Management Software ===
        </div>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>
    window.addEventListener('load', function() {
        window.print();
    });
</script>
<?php endif; ?>

</body>
</html>
