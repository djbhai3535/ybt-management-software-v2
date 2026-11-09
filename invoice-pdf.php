<?php
/**
 * YBT Management Software — Invoice PDF & Print Document Endpoint
 * Supports direct standalone A4 browser print view and binary PDF download (?download=1)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/invoice-service.php';
require_once __DIR__ . '/services/pdf-service.php';
require_once __DIR__ . '/services/whatsapp-service.php';

Auth::requireLogin();
Permissions::require('invoices.view');

$businessId = Auth::businessId();
session_write_close(); // Release session file lock immediately to prevent blocking
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

// Fail-Closed Multi-Tenant Check
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
        'address_line1' => 'Commercial Area',
        'city' => 'Lahore',
        'country' => 'Pakistan'
    ];
}

// BINARY PDF DOWNLOAD MODE (?download=1 or ?format=pdf)
if (!empty($_GET['download']) || ($_GET['format'] ?? '') === 'pdf') {
    $pdfBinary = PdfService::generatePdfBinary($invoice, $business);
    $filename = "Invoice-" . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$invoice['invoice_number']) . ".pdf";

    while (ob_get_level()) {
        @ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBinary));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $pdfBinary;
    flush();
    exit;
}

// BROWSER PRINT / PREVIEW MODE
$whatsAppUrl = WhatsAppService::getClickToChatUrl($invoice, $business, url('invoice-pdf.php?id=' . $invoiceId));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($invoice['invoice_number']) ?> — Tax Invoice</title>
    <link rel="stylesheet" href="<?= url('assets/css/tabler-icons.min.css') ?>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #f1f5f9;
            color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 13px;
            padding: 24px;
        }
        .page-toolbar {
            max-width: 820px;
            margin: 0 auto 20px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-primary:hover { background: #0369a1; }
        .btn-secondary { background: #fff; color: #334155; border-color: #cbd5e1; }
        .btn-secondary:hover { background: #f8fafc; }
        .btn-whatsapp { background: #25d366; color: #fff; }
        .btn-whatsapp:hover { background: #128c7e; }
        .btn-thermal { background: #475569; color: #fff; }
        .btn-thermal:hover { background: #334155; }

        .invoice-sheet {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm;
            }
            body {
                background: #fff !important;
                padding: 0 !important;
            }
            .page-toolbar, .no-print {
                display: none !important;
            }
            .invoice-sheet {
                max-width: 100% !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>

<div class="page-toolbar no-print">
    <div style="display: flex; gap: 8px;">
        <a href="<?= url('invoice-view.php?id=' . $invoice['id']) ?>" class="btn-action btn-secondary">
            &larr; Back to Invoice
        </a>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="<?= url('invoice-pdf.php?id=' . $invoice['id'] . '&download=1') ?>" class="btn-action btn-primary" title="Download Standard PDF">
            <i class="ti ti-download"></i> Download PDF
        </a>
        <button class="btn-action btn-secondary" onclick="window.print()" title="Print A4 Document">
            <i class="ti ti-printer"></i> Print A4
        </button>
        <a href="<?= url('invoice-thermal-print.php?id=' . $invoice['id'] . '&size=80mm') ?>" class="btn-action btn-thermal" title="Thermal POS Receipt">
            <i class="ti ti-receipt"></i> Thermal 80mm
        </a>
        <a href="<?= $whatsAppUrl ?>" target="_blank" class="btn-action btn-whatsapp" title="Share on WhatsApp">
            <i class="ti ti-brand-whatsapp"></i> WhatsApp
        </a>
    </div>
</div>

<div class="invoice-sheet">
    <?= PdfService::renderInvoiceHtml($invoice, $business, false) ?>
</div>

</body>
</html>
