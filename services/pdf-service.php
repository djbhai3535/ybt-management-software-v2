<?php
/**
 * YBT Management Software — PDF & Print Service
 * Generates pixel-perfect bilingual printable HTML documents and standard standalone PDF 1.4 binaries.
 * Compatible with offline SQLite and cloud MySQL without external composer dependencies.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/formatting.php';

class PdfService {
    /**
     * Render complete invoice document HTML with bilingual English & Urdu support
     */
    public static function renderInvoiceHtml(array $invoice, array $business, bool $isPdfMode = false): string {
        $items = $invoice['items'] ?? [];
        $currency = $invoice['currency'] ?? $business['default_currency'] ?? 'PKR';
        $symbol = getCurrencySymbol($currency);
        $amountInWords = function_exists('formatNumberToWords') ? formatNumberToWords($invoice['grand_total'] ?? 0) : '';
        $isCancelled = strtolower($invoice['status'] ?? '') === 'cancelled';

        // Payment history on this invoice
        $payments = $invoice['payments'] ?? [];

        // Check if customer has khata current balance
        $customerKhataBalance = isset($invoice['current_balance']) ? (float)$invoice['current_balance'] : null;

        ob_start();
        ?>
        <div class="invoice-paper <?= $isCancelled ? 'is-cancelled' : '' ?>" style="position: relative; background: #fff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Noto Nastaliq Urdu', 'Urdu Typesetting', Arial, sans-serif;">
            
            <?php if ($isCancelled): ?>
            <!-- Cancelled Watermark & Banner -->
            <div class="cancelled-watermark" style="position: absolute; top: 35%; left: 10%; transform: rotate(-25deg); font-size: 70px; font-weight: 900; color: rgba(220, 38, 38, 0.15); pointer-events: none; z-index: 10; border: 8px solid rgba(220, 38, 38, 0.2); padding: 10px 40px; border-radius: 12px; text-transform: uppercase;">
                CANCELLED
            </div>
            <div class="cancelled-alert" style="background: #fef2f2; border: 1px solid #f87171; color: #991b1b; padding: 10px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
                <span>⚠️ THIS INVOICE IS CANCELLED & RESTOCKED — NOT VALID FOR PAYMENT</span>
                <span style="font-size: 11px;">This invoice is cancelled and not valid for payment.</span>
            </div>
            <?php endif; ?>

            <!-- Header Section -->
            <div class="invoice-header" style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0284c7; padding-bottom: 18px; margin-bottom: 20px;">
                <div class="invoice-company" style="max-width: 60%;">
                    <?php 
                    $shopLogo = $business['logo_url'] ?? $business['logo_path'] ?? null;
                    if (!empty($shopLogo) && file_exists(YBT_ROOT . '/' . ltrim($shopLogo, '/'))): ?>
                        <img src="<?= url($shopLogo) ?>" alt="Logo" style="max-height: 50px; max-width: 160px; object-fit: contain; margin-bottom: 8px;">
                    <?php endif; ?>
                    <h2 class="company-name" style="font-size: 22px; font-weight: 800; color: #0f172a; margin: 0;"><?= e($business['name']) ?></h2>
                    <?php if (!empty($business['tagline'])): ?>
                        <p class="company-tagline text-muted" style="color: #64748b; font-size: 12px; margin: 2px 0 6px 0;"><?= e($business['tagline']) ?></p>
                    <?php endif; ?>
                    <div class="company-address" style="font-size: 12px; color: #334155; line-height: 1.5; margin-top: 6px;">
                        <?= e($business['address_line1'] ?? '') ?> <?= e($business['address_line2'] ?? '') ?><br>
                        <?= e($business['city'] ?? '') ?><?= !empty($business['state']) ? ', ' . e($business['state']) : '' ?><?= !empty($business['country']) ? ' (' . e($business['country']) . ')' : '' ?><br>
                        <?php if (!empty($business['ntn'])): ?>
                            <strong>NTN:</strong> <?= e($business['ntn']) ?> &nbsp;|&nbsp;
                        <?php elseif (!empty($business['gstin'])): ?>
                            <strong>GSTIN:</strong> <?= e($business['gstin']) ?> &nbsp;|&nbsp;
                        <?php endif; ?>
                        <strong>Phone:</strong> <?= e($business['phone'] ?: '—') ?><br>
                        <?php if (!empty($business['email'])): ?>
                            <strong>Email:</strong> <?= e($business['email']) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="invoice-badge-box" style="text-align: right;">
                    <h1 class="invoice-title" style="font-size: 24px; font-weight: 900; color: #0284c7; margin: 0; text-transform: uppercase; letter-spacing: 0.5px;">TAX INVOICE</h1>
                    <div style="margin-top: 10px; font-size: 13px;">
                        <span style="color: #64748b;">Invoice No:</span>
                        <strong style="font-size: 15px; color: #0f172a;"><?= e($invoice['invoice_number']) ?></strong>
                    </div>
                    <div style="font-size: 12px; color: #334155; margin-top: 4px;">
                        <span style="color: #64748b;">Date:</span>
                        <strong><?= formatDate($invoice['invoice_date']) ?></strong>
                    </div>
                    <?php if (!empty($invoice['due_date'])): ?>
                    <div style="font-size: 12px; color: #334155; margin-top: 2px;">
                        <span style="color: #64748b;">Due Date:</span>
                        <strong><?= formatDate($invoice['due_date']) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 8px;">
                        <?php
                        $badgeBg = '#e2e8f0';
                        $badgeColor = '#334155';
                        $statusText = strtoupper(e($invoice['status']));
                        if ($invoice['status'] === 'paid') {
                            $badgeBg = '#dcfce7'; $badgeColor = '#15803d';
                        } elseif ($invoice['status'] === 'partial') {
                            $badgeBg = '#fef9c3'; $badgeColor = '#a16207';
                        } elseif ($invoice['status'] === 'cancelled') {
                            $badgeBg = '#fee2e2'; $badgeColor = '#b91c1c';
                        } elseif ($invoice['status'] === 'overdue') {
                            $badgeBg = '#fee2e2'; $badgeColor = '#b91c1c';
                        }
                        ?>
                        <span style="display: inline-block; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; background: <?= $badgeBg ?>; color: <?= $badgeColor ?>; letter-spacing: 0.5px;">
                            <?= $statusText ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Billed To & Payment Accounts Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px;">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b;">Billed To:</span>
                    </div>
                    <h4 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;"><?= e($invoice['customer_name']) ?></h4>
                    <?php if (!empty($invoice['company_name'])): ?>
                        <div style="color: #475569; font-size: 13px; font-weight: 600;"><?= e($invoice['company_name']) ?></div>
                    <?php endif; ?>
                    <div style="font-size: 12px; color: #334155; margin-top: 6px; line-height: 1.5;">
                        <strong>Phone:</strong> <?= e($invoice['customer_phone'] ?: '—') ?><br>
                        <?php if (!empty($invoice['customer_email'])): ?>
                            <strong>Email:</strong> <?= e($invoice['customer_email']) ?><br>
                        <?php endif; ?>
                        <?php if (!empty($invoice['customer_gstin'])): ?>
                            <strong>GSTIN / NTN:</strong> <?= e($invoice['customer_gstin']) ?><br>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                    <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b;">Payment Details:</span>
                    <div style="font-size: 12px; color: #334155; margin-top: 6px; line-height: 1.6;">
                        <?php if (!empty($business['bank_name'])): ?>
                            <div><strong>Bank:</strong> <?= e($business['bank_name']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($business['account_number'])): ?>
                            <div><strong>Account:</strong> <?= e($business['account_number']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($business['iban'])): ?>
                            <div><strong>IBAN:</strong> <?= e($business['iban']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($business['raast_id'])): ?>
                            <div><strong>Raast ID:</strong> <?= e($business['raast_id']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($business['jazzcash_number'])): ?>
                            <div><strong>JazzCash:</strong> <?= e($business['jazzcash_number']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($business['easypaisa_number'])): ?>
                            <div><strong>Easypaisa:</strong> <?= e($business['easypaisa_number']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($business['upi_id'])): ?>
                            <div><strong>UPI ID:</strong> <?= e($business['upi_id']) ?></div>
                        <?php endif; ?>
                        <?php if (empty($business['bank_name']) && empty($business['iban']) && empty($business['raast_id']) && empty($business['jazzcash_number'])): ?>
                            <div style="color: #64748b;">Cash or Counter Payment Accepted</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Line Items Table -->
            <table class="invoice-table" style="width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 12px;">
                <thead>
                    <tr style="background: #0f172a; color: #ffffff;">
                        <th style="width: 5%; padding: 10px 8px; text-align: center; border: 1px solid #0f172a;">#</th>
                        <th style="width: 45%; padding: 10px 8px; text-align: left; border: 1px solid #0f172a;">
                            Item & Description
                        </th>
                        <th style="width: 10%; padding: 10px 8px; text-align: center; border: 1px solid #0f172a;">Qty</th>
                        <th style="width: 18%; padding: 10px 8px; text-align: right; border: 1px solid #0f172a;">Unit Price</th>
                        <th style="width: 7%; padding: 10px 8px; text-align: right; border: 1px solid #0f172a;">Tax</th>
                        <th style="width: 15%; padding: 10px 8px; text-align: right; border: 1px solid #0f172a;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="6" style="padding: 16px; text-align: center; color: #64748b; border: 1px solid #cbd5e1;">
                            No items recorded in this invoice.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr style="background: <?= ($idx % 2 === 0) ? '#ffffff' : '#f8fafc' ?>;">
                        <td style="padding: 9px 8px; text-align: center; border: 1px solid #cbd5e1;"><?= $idx + 1 ?></td>
                        <td style="padding: 9px 8px; border: 1px solid #cbd5e1;">
                            <strong style="color: #0f172a; font-size: 13px;"><?= e($item['description']) ?></strong>
                            <?php if (!empty($item['notes'])): ?>
                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;"><?= e($item['notes']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 9px 8px; text-align: center; border: 1px solid #cbd5e1;"><?= number_format((float)$item['quantity'], 2) ?></td>
                        <td style="padding: 9px 8px; text-align: right; border: 1px solid #cbd5e1;"><?= formatCurrency((float)$item['unit_price'], $symbol) ?></td>
                        <td style="padding: 9px 8px; text-align: right; border: 1px solid #cbd5e1;"><?= (float)($item['tax_rate'] ?? 0) ?>%</td>
                        <td style="padding: 9px 8px; text-align: right; border: 1px solid #cbd5e1; font-weight: 700; color: #0f172a;">
                            <?= formatCurrency((float)($item['line_total'] ?? ((float)$item['quantity'] * (float)$item['unit_price'])), $symbol) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Summary & Terms Section -->
            <div style="display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 24px; margin-bottom: 24px;">
                <div>
                    <?php if (!empty($amountInWords)): ?>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px;">
                        <span style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700;">Amount in Words:</span>
                        <div style="font-size: 12px; font-weight: 600; color: #1e293b; margin-top: 2px;"><?= e($amountInWords) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($invoice['notes'])): ?>
                    <div style="font-size: 12px; color: #475569; margin-bottom: 8px;">
                        <strong>Notes:</strong> <?= e($invoice['notes']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($invoice['terms'])): ?>
                    <div style="font-size: 11px; color: #64748b; line-height: 1.5;">
                        <strong>Terms & Conditions:</strong><br>
                        <?= nl2br(e($invoice['terms'])) ?>
                    </div>
                    <?php else: ?>
                    <div style="font-size: 11px; color: #64748b; line-height: 1.5;">
                        <strong>Terms & Conditions:</strong><br>
                        Goods once sold will only be replaced or returned as per shop warranty policy.
                    </div>
                    <?php endif; ?>
                </div>

                <div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                        <tr>
                            <td style="padding: 6px 0; color: #64748b;">Subtotal:</td>
                            <td style="padding: 6px 0; text-align: right; font-weight: 600;"><?= formatCurrency((float)($invoice['subtotal'] ?? $invoice['grand_total']), $symbol) ?></td>
                        </tr>
                        <?php if ((float)($invoice['discount_total'] ?? 0) > 0): ?>
                        <tr>
                            <td style="padding: 6px 0; color: #16a34a;">Discount:</td>
                            <td style="padding: 6px 0; text-align: right; font-weight: 600; color: #16a34a;">- <?= formatCurrency((float)$invoice['discount_total'], $symbol) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ((float)($invoice['tax_total'] ?? 0) > 0): ?>
                        <tr>
                            <td style="padding: 6px 0; color: #64748b;">Tax:</td>
                            <td style="padding: 6px 0; text-align: right; font-weight: 600;"><?= formatCurrency((float)$invoice['tax_total'], $symbol) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr style="border-top: 2px solid #0f172a; border-bottom: 2px solid #0f172a;">
                            <td style="padding: 10px 0; font-size: 14px; font-weight: 800; color: #0f172a;">Grand Total:</td>
                            <td style="padding: 10px 0; text-align: right; font-size: 16px; font-weight: 900; color: #0284c7;">
                                <?= formatCurrency((float)$invoice['grand_total'], $symbol) ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #15803d; font-weight: 600;">Amount Paid:</td>
                            <td style="padding: 8px 0; text-align: right; font-weight: 700; color: #15803d;">
                                <?= formatCurrency((float)$invoice['amount_paid'], $symbol) ?>
                            </td>
                        </tr>
                        <tr style="border-top: 1px dashed #cbd5e1;">
                            <td style="padding: 8px 0; font-weight: 800; color: <?= (float)$invoice['outstanding'] > 0 ? '#dc2626' : '#15803d' ?>;">
                                Balance Due:
                            </td>
                            <td style="padding: 8px 0; text-align: right; font-size: 14px; font-weight: 900; color: <?= (float)$invoice['outstanding'] > 0 ? '#dc2626' : '#15803d' ?>;">
                                <?= formatCurrency((float)$invoice['outstanding'], $symbol) ?>
                            </td>
                        </tr>
                        <?php if ($customerKhataBalance !== null): ?>
                        <tr style="background: #f1f5f9;">
                            <td style="padding: 6px 8px; font-size: 11px; color: #475569;">Total Khata Balance:</td>
                            <td style="padding: 6px 8px; text-align: right; font-size: 12px; font-weight: 800; color: <?= $customerKhataBalance > 0 ? '#dc2626' : '#15803d' ?>;">
                                <?= formatCurrency(abs($customerKhataBalance), $symbol) ?> <?= $customerKhataBalance > 0 ? '(Dr)' : '(Cr)' ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Footer Signatures Section -->
            <div style="border-top: 1px solid #cbd5e1; padding-top: 18px; display: flex; justify-content: space-between; align-items: flex-end; margin-top: 30px;">
                <div style="font-size: 11px; color: #64748b; max-width: 55%;">
                    Thank you for your business!<br>
                    This is a computer-generated invoice and requires no physical signature.<br>
                    <span style="color: #94a3b8; font-size: 10px;">Powered by YBT Management Software</span>
                </div>
                <div style="text-align: center; width: 180px;">
                    <div style="border-bottom: 1px solid #0f172a; height: 35px; margin-bottom: 6px;"></div>
                    <div style="font-size: 12px; font-weight: 700; color: #0f172a;">Authorized Signature</div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate a 100% compliant standalone PDF 1.4 binary file in pure PHP without external dependencies.
     * Generates a downloadable standard PDF file that opens in Acrobat, Chrome, Edge, and iOS.
     */
    public static function generatePdfBinary(array $invoice, array $business): string {
        $currency = $invoice['currency'] ?? $business['default_currency'] ?? 'PKR';
        $invNum = $invoice['invoice_number'] ?? 'INV-0000';
        $invDate = !empty($invoice['invoice_date']) ? date('d M Y', strtotime($invoice['invoice_date'])) : date('d M Y');
        $dueDate = !empty($invoice['due_date']) ? date('d M Y', strtotime($invoice['due_date'])) : '-';
        $status = strtoupper($invoice['status'] ?? 'DRAFT');
        $custName = $invoice['customer_name'] ?? 'Customer';
        $custPhone = $invoice['customer_phone'] ?? '-';
        $bizName = $business['name'] ?? 'YBT Store';
        $bizPhone = $business['phone'] ?? '-';
        $bizAddress = ($business['address_line1'] ?? '') . ', ' . ($business['city'] ?? '');
        $grandTotal = number_format((float)($invoice['grand_total'] ?? 0), 2);
        $amountPaid = number_format((float)($invoice['amount_paid'] ?? 0), 2);
        $outstanding = number_format((float)($invoice['outstanding'] ?? 0), 2);

        $items = $invoice['items'] ?? [];

        // PDF Objects collection
        $objects = [];

        // Helper function to escape text in PDF stream
        $esc = function(string $str): string {
            // Remove non-printable and ASCII escape
            $clean = preg_replace('/[^\x20-\x7E]/', '', $str);
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
        };

        // Construct Content Stream
        $stream = "";
        
        // Colors & Background Header bar
        $stream .= "0.008 0.518 0.780 rg\n"; // Primary Blue #0284c7
        $stream .= "40 790 515 4 re f\n"; // Header accent line

        // Company Name
        $stream .= "BT\n";
        $stream .= "/F2 18 Tf\n";
        $stream .= "0.059 0.090 0.165 rg\n"; // #0f172a
        $stream .= "40 760 Td\n";
        $stream .= "(" . $esc($bizName) . ") Tj\n";
        $stream .= "ET\n";

        // Company Details
        $stream .= "BT\n";
        $stream .= "/F1 9 Tf\n";
        $stream .= "0.392 0.455 0.545 rg\n"; // #64748b
        $stream .= "40 745 Td\n";
        $stream .= "(" . $esc($bizAddress) . ") Tj\n";
        $stream .= "0 -12 Td\n";
        $stream .= "(Phone: " . $esc($bizPhone) . ") Tj\n";
        $stream .= "ET\n";

        // Invoice Title & Meta (Right Aligned)
        $stream .= "BT\n";
        $stream .= "/F2 20 Tf\n";
        $stream .= "0.008 0.518 0.780 rg\n";
        $stream .= "400 760 Td\n";
        $stream .= "(TAX INVOICE) Tj\n";
        $stream .= "ET\n";

        $stream .= "BT\n";
        $stream .= "/F1 10 Tf\n";
        $stream .= "0.059 0.090 0.165 rg\n";
        $stream .= "400 740 Td\n";
        $stream .= "(Invoice No: " . $esc($invNum) . ") Tj\n";
        $stream .= "0 -13 Td\n";
        $stream .= "(Date: " . $esc($invDate) . ") Tj\n";
        $stream .= "0 -13 Td\n";
        $stream .= "(Due Date: " . $esc($dueDate) . ") Tj\n";
        $stream .= "0 -14 Td\n";
        $stream .= "(Status: " . $esc($status) . ") Tj\n";
        $stream .= "ET\n";

        // Billed To Box
        $stream .= "0.973 0.980 0.988 rg\n"; // #f8fafc
        $stream .= "0.886 0.906 0.941 RG\n"; // #e2e8f0
        $stream .= "40 640 515 50 re B\n";

        $stream .= "BT\n";
        $stream .= "/F2 9 Tf\n";
        $stream .= "0.392 0.455 0.545 rg\n";
        $stream .= "50 675 Td\n";
        $stream .= "(BILLED TO / CUSTOMER:) Tj\n";
        $stream .= "ET\n";

        $stream .= "BT\n";
        $stream .= "/F2 12 Tf\n";
        $stream .= "0.059 0.090 0.165 rg\n";
        $stream .= "50 660 Td\n";
        $stream .= "(" . $esc($custName) . ") Tj\n";
        $stream .= "ET\n";

        $stream .= "BT\n";
        $stream .= "/F1 9 Tf\n";
        $stream .= "0.200 0.255 0.333 rg\n";
        $stream .= "50 648 Td\n";
        $stream .= "(Phone: " . $esc($custPhone) . ") Tj\n";
        $stream .= "ET\n";

        // Items Table Header
        $stream .= "0.059 0.090 0.165 rg\n"; // #0f172a
        $stream .= "40 605 515 22 re f\n";

        $stream .= "BT\n";
        $stream .= "/F2 9 Tf\n";
        $stream .= "1 1 1 rg\n"; // White text
        $stream .= "45 612 Td ( #) Tj\n";
        $stream .= "30 0 Td (Item Description) Tj\n";
        $stream .= "240 0 Td (Qty) Tj\n";
        $stream .= "50 0 Td (Unit Price) Tj\n";
        $stream .= "90 0 Td (Total (" . $esc($currency) . ")) Tj\n";
        $stream .= "ET\n";

        // Table Rows
        $y = 590;
        $rowIdx = 1;
        foreach ($items as $item) {
            $desc = $item['description'] ?? 'Item';
            $qty = number_format((float)($item['quantity'] ?? 1), 2);
            $price = number_format((float)($item['unit_price'] ?? 0), 2);
            $total = number_format((float)($item['line_total'] ?? ((float)$item['quantity'] * (float)$item['unit_price'])), 2);

            // Row alternate background
            if ($rowIdx % 2 === 0) {
                $stream .= "0.973 0.980 0.988 rg\n";
                $stream .= "40 " . ($y - 4) . " 515 18 re f\n";
            }
            // Row bottom border
            $stream .= "0.886 0.906 0.941 RG\n";
            $stream .= "40 " . ($y - 4) . " 515 0.5 re S\n";

            $stream .= "BT\n";
            $stream .= "/F1 9 Tf\n";
            $stream .= "0.059 0.090 0.165 rg\n";
            $stream .= "45 " . $y . " Td (" . $rowIdx . ") Tj\n";
            $stream .= "30 0 Td (" . $esc(substr($desc, 0, 40)) . ") Tj\n";
            $stream .= "240 0 Td (" . $esc($qty) . ") Tj\n";
            $stream .= "50 0 Td (" . $esc($price) . ") Tj\n";
            $stream .= "90 0 Td (" . $esc($total) . ") Tj\n";
            $stream .= "ET\n";

            $y -= 18;
            $rowIdx++;
            if ($y < 280) break; // Limit single page
        }

        // Summary Block
        $ySummary = $y - 20;
        $stream .= "0.973 0.980 0.988 rg\n";
        $stream .= "0.886 0.906 0.941 RG\n";
        $stream .= "340 " . ($ySummary - 75) . " 215 90 re B\n";

        $stream .= "BT\n";
        $stream .= "/F1 10 Tf\n";
        $stream .= "0.200 0.255 0.333 rg\n";
        $stream .= "350 " . ($ySummary) . " Td (Subtotal:) Tj\n";
        $stream .= "110 0 Td (" . $esc($currency . ' ' . $grandTotal) . ") Tj\n";
        $stream .= "-110 -18 Td\n";
        $stream .= "/F2 11 Tf\n";
        $stream .= "0.008 0.518 0.780 rg\n";
        $stream .= "(Grand Total:) Tj\n";
        $stream .= "110 0 Td (" . $esc($currency . ' ' . $grandTotal) . ") Tj\n";
        $stream .= "-110 -18 Td\n";
        $stream .= "/F1 10 Tf\n";
        $stream .= "0.086 0.502 0.239 rg\n";
        $stream .= "(Amount Paid:) Tj\n";
        $stream .= "110 0 Td (" . $esc($currency . ' ' . $amountPaid) . ") Tj\n";
        $stream .= "-110 -18 Td\n";
        $stream .= "/F2 11 Tf\n";
        $stream .= "0.863 0.149 0.149 rg\n";
        $stream .= "(Balance Due:) Tj\n";
        $stream .= "110 0 Td (" . $esc($currency . ' ' . $outstanding) . ") Tj\n";
        $stream .= "ET\n";

        // Footer & Signatures
        $stream .= "0.886 0.906 0.941 RG\n";
        $stream .= "40 100 515 0.5 re S\n";

        $stream .= "BT\n";
        $stream .= "/F1 8 Tf\n";
        $stream .= "0.392 0.455 0.545 rg\n";
        $stream .= "40 85 Td (Thank you for your business! This is a computer generated invoice.) Tj\n";
        $stream .= "0 -10 Td (Powered by YBT Management Software) Tj\n";
        $stream .= "ET\n";

        $stream .= "BT\n";
        $stream .= "/F2 9 Tf\n";
        $stream .= "0.059 0.090 0.165 rg\n";
        $stream .= "420 85 Td (Authorized Signatory) Tj\n";
        $stream .= "ET\n";

        // Assemble PDF 1.4 Object Graph
        $streamLen = strlen($stream);

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>";
        $objects[4] = "<< /Length " . $streamLen . " >>\nstream\n" . $stream . "\nendstream";
        $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        // Build Cross-Reference Table
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $num => $obj) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$obj}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n";
        $pdf .= "0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n";
        $pdf .= "<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n";
        $pdf .= "{$xrefOffset}\n";
        $pdf .= "%%EOF\n";

        return $pdf;
    }
}
