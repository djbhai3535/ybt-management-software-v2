<?php
/**
 * YBT Management Software — Invoice Engine Service
 * Handles invoice generation, item calculations, tax breakdown (CGST/SGST/IGST),
 * status transitions, and quotation-to-invoice conversions.
 */

class InvoiceService {
    /**
     * Generate next sequential invoice number
     */
    public static function generateInvoiceNumber(int $businessId): string {
        $business = Database::fetch("SELECT invoice_prefix, invoice_next_number FROM businesses WHERE id = ?", [$businessId]);
        $prefix = $business['invoice_prefix'] ?? 'INV-';
        $next = $business['invoice_next_number'] ?? 1;

        // Increment sequence in business record
        Database::query("UPDATE businesses SET invoice_next_number = invoice_next_number + 1 WHERE id = ?", [$businessId]);

        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Create a complete invoice with line items
     */
    public static function create(int $businessId, array $data, array $items, int $userId): int {
        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $customer = Database::fetch("SELECT * FROM customers WHERE id = ? AND business_id = ?", [$data['customer_id'], $businessId]);
            $business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);

            if (!$customer) {
                throw new Exception('Invalid customer selected.');
            }

            $invoiceNumber = !empty($data['invoice_number']) ? trim($data['invoice_number']) : self::generateInvoiceNumber($businessId);
            $invoiceDate = $data['invoice_date'] ?? date('Y-m-d');
            $dueDays = !empty($data['due_days']) ? (int)$data['due_days'] : (int)($business['default_due_days'] ?? 15);
            $dueDate = $data['due_date'] ?? date('Y-m-d', strtotime("+$dueDays days", strtotime($invoiceDate)));

            // Determine if Intra-state or Inter-state GST
            $businessState = strtolower(trim($business['state'] ?? ''));
            $customerState = strtolower(trim($customer['billing_state'] ?? ''));
            $isInterstate = ($businessState !== '' && $customerState !== '' && $businessState !== $customerState);

            $subtotal = 0.0;
            $totalDiscount = 0.0;
            $cgstTotal = 0.0;
            $sgstTotal = 0.0;
            $igstTotal = 0.0;
            $taxTotal = 0.0;
            $processedItems = [];

            foreach ($items as $idx => $item) {
                $desc = trim($item['description'] ?? $item['name'] ?? '');
                if ($desc === '') continue;

                $qty = max(0.001, (float)($item['quantity'] ?? 1));
                $price = max(0, (float)($item['unit_price'] ?? 0));
                $disc = max(0, (float)($item['discount_amount'] ?? 0));
                $taxRate = max(0, (float)($item['tax_rate'] ?? 18));
                $hsn = trim($item['hsn_sac'] ?? '');
                $unit = trim($item['unit'] ?? 'Nos');

                $itemBase = $qty * $price;
                $afterDisc = max(0, $itemBase - $disc);
                $itemTax = ($afterDisc * $taxRate) / 100;
                $lineTotal = $afterDisc + $itemTax;

                $cgstRate = 0; $cgstAmt = 0;
                $sgstRate = 0; $sgstAmt = 0;
                $igstRate = 0; $igstAmt = 0;

                if ($isInterstate) {
                    $igstRate = $taxRate;
                    $igstAmt = $itemTax;
                    $igstTotal += $igstAmt;
                } else {
                    $cgstRate = $taxRate / 2;
                    $cgstAmt = $itemTax / 2;
                    $sgstRate = $taxRate / 2;
                    $sgstAmt = $itemTax / 2;
                    $cgstTotal += $cgstAmt;
                    $sgstTotal += $sgstAmt;
                }

                $subtotal += $afterDisc;
                $taxTotal += $itemTax;
                $totalDiscount += $disc;

                $processedItems[] = [
                    'product_id' => !empty($item['product_id']) ? (int)$item['product_id'] : null,
                    'description' => $desc,
                    'quantity' => $qty,
                    'unit' => $unit,
                    'unit_price' => $price,
                    'discount_amount' => $disc,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $itemTax,
                    'cgst_rate' => $cgstRate,
                    'cgst_amount' => $cgstAmt,
                    'sgst_rate' => $sgstRate,
                    'sgst_amount' => $sgstAmt,
                    'igst_rate' => $igstRate,
                    'igst_amount' => $igstAmt,
                    'hsn_sac' => $hsn,
                    'line_total' => $lineTotal,
                    'sort_order' => $idx + 1
                ];
            }

            if (empty($processedItems)) {
                throw new Exception('Invoice must contain at least one valid line item.');
            }

            $overallDiscount = max(0, (float)($data['discount_total'] ?? 0));
            $grandTotal = max(0, ($subtotal - $overallDiscount) + $taxTotal);
            $status = $data['status'] ?? 'sent';

            // Address builders
            $bStreet = trim(($business['address_line1'] ?? '') . ' ' . ($business['address_line2'] ?? ''));
            $bCity = $business['city'] ?? '';
            $bState = $business['state'] ?? '';
            $bZip = $business['postal_code'] ?? '';
            $bAddr = trim("{$bStreet}, {$bCity}, {$bState} {$bZip}");

            $cStreet = trim(($customer['billing_address_line1'] ?? $customer['address'] ?? '') . ' ' . ($customer['billing_address_line2'] ?? ''));
            $cCity = $customer['billing_city'] ?? $customer['city'] ?? '';
            $cState = $customer['billing_state'] ?? $customer['state'] ?? '';
            $cZip = $customer['billing_postal_code'] ?? $customer['postal_code'] ?? '';
            $cAddr = trim("{$cStreet}, {$cCity}, {$cState} {$cZip}");

            // Insert invoice header with full dual SQLite/MySQL canonical compatibility
            $invoiceRecord = [
                'uuid' => generateUuidV4(),
                'sync_uuid' => generateUuidV4(),
                'business_id' => $businessId,
                'customer_id' => $customer['id'],
                'customer_uuid' => $customer['uuid'] ?? null,
                'invoice_number' => $invoiceNumber,
                'issue_date' => $invoiceDate,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'discount_amount' => $totalDiscount + $overallDiscount,
                'discount_total' => $totalDiscount + $overallDiscount,
                'tax_amount' => $taxTotal,
                'tax_total' => $taxTotal,
                'total_amount' => $grandTotal,
                'grand_total' => $grandTotal,
                'paid_amount' => 0.00,
                'amount_paid' => 0.00,
                'balance_amount' => $grandTotal,
                'outstanding' => $grandTotal,
                'status' => $status,
                'payment_status' => ($status === 'paid') ? 'paid' : 'unpaid',
                'notes' => trim($data['notes'] ?? $business['default_notes'] ?? ''),
                'terms' => trim($data['terms'] ?? $business['default_terms'] ?? ''),
                'place_of_supply' => !empty($customer['billing_state']) ? $customer['billing_state'] : (!empty($customer['state']) ? $customer['state'] : ($business['state'] ?? 'State')),
                'billing_address' => $cAddr,
                'shipping_address' => $cAddr,
                'sync_status' => 'pending',
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $invoiceId = Database::insert('invoices', $invoiceRecord);

            // Insert line items
            foreach ($processedItems as $row) {
                $rowItem = [
                    'invoice_id' => $invoiceId,
                    'product_id' => $row['product_id'],
                    'item_name' => $row['description'],
                    'item_description' => $row['description'],
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'] ?? 'pcs',
                    'unit_price' => $row['unit_price'],
                    'discount_amount' => $row['discount_amount'],
                    'tax_rate' => $row['tax_rate'],
                    'tax_amount' => $row['tax_amount'],
                    'hsn_sac' => $row['hsn_sac'] ?? '',
                    'total_amount' => $row['line_total'],
                    'line_total' => $row['line_total'],
                    'sort_order' => $row['sort_order'] ?? 1
                ];
                Database::insert('invoice_items', $rowItem);
            }

            // Stage 2: Automatic Stock Deduction & Oversell Prevention
            if ($status !== 'draft') {
                require_once __DIR__ . '/stock-service.php';
                StockService::deductForInvoice($businessId, $invoiceId, $userId);
                // Stage 3: Customer Khata / Ledger Debit Entry (You Gave)
                require_once __DIR__ . '/khata-service.php';
                KhataService::recordInvoiceDebit($businessId, $invoiceId, $userId);
            }

            // Auto-enqueue invoice creation into pending_operations for cloud sync
            try {
                $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? (getenv('YBT_DEVICE_ID') ?: 'desktop-default');
                $invoicePayload = [
                    'uuid' => $invoiceRecord['uuid'],
                    'invoice_number' => $invoiceNumber,
                    'customer_id' => $customer['id'],
                    'customer_uuid' => $customer['uuid'] ?? null,
                    'invoice_date' => $invoiceDate,
                    'issue_date' => $invoiceDate,
                    'due_date' => $dueDate,
                    'subtotal' => $subtotal,
                    'discount_total' => $totalDiscount + $overallDiscount,
                    'discount_amount' => $totalDiscount + $overallDiscount,
                    'tax_total' => $taxTotal,
                    'tax_amount' => $taxTotal,
                    'grand_total' => $grandTotal,
                    'total_amount' => $grandTotal,
                    'status' => $status,
                    'notes' => $invoiceRecord['notes'],
                    'terms' => $invoiceRecord['terms'],
                    'items' => array_map(function($pi) {
                        return [
                            'product_id' => $pi['product_id'],
                            'description' => $pi['description'],
                            'item_name' => $pi['description'],
                            'quantity' => $pi['quantity'],
                            'unit' => $pi['unit'],
                            'unit_price' => $pi['unit_price'],
                            'discount_amount' => $pi['discount_amount'],
                            'tax_rate' => $pi['tax_rate'],
                            'tax_amount' => $pi['tax_amount'],
                            'line_total' => $pi['line_total'],
                            'total_amount' => $pi['line_total']
                        ];
                    }, $processedItems)
                ];

                Database::insert('pending_operations', [
                    'operation_uuid' => generateUuidV4(),
                    'business_id' => $businessId,
                    'device_id' => $deviceId,
                    'entity_type' => 'invoices',
                    'entity_uuid' => $invoiceRecord['uuid'],
                    'operation_type' => 'insert',
                    'payload_json' => json_encode($invoicePayload),
                    'retry_count' => 0,
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            } catch (\Throwable $qe) {
                error_log("Non-blocking: Failed to enqueue invoice: " . $qe->getMessage());
            }

            ActivityLog::log($businessId, $userId, 'invoice_created', 'invoice', $invoiceId, "Created invoice {$invoiceNumber} for ₹" . number_format($grandTotal, 2));

            $db->commit();
            return $invoiceId;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Cancel an invoice and idempotently restore stock
     */
    public static function cancel(int $invoiceId, int $businessId, int $userId): array {
        require_once __DIR__ . '/stock-service.php';
        $res = StockService::restoreForCancelledInvoice($businessId, $invoiceId, $userId);
        // Stage 3: Customer Khata / Ledger Reversal (Reverses You Gave effect exactly once)
        require_once __DIR__ . '/khata-service.php';
        KhataService::reverseInvoice($businessId, $invoiceId, $userId);
        ActivityLog::log($businessId, $userId, 'invoice_cancelled', 'invoice', $invoiceId, "Cancelled invoice #{$invoiceId} and restored inventory");
        return $res;
    }

    /**
     * Get single invoice with customer and line items
     */
    public static function getById(int $invoiceId, int $businessId): ?array {
        $invoice = Database::fetch(
            "SELECT i.*, c.name as customer_name, c.company_name, c.email as customer_email, c.phone as customer_phone, c.gstin as customer_gstin,
             b.name as business_name, b.email as business_email, b.phone as business_phone, b.gstin as business_gstin, b.bank_name, b.account_number, b.ifsc_code, b.upi_id
             FROM invoices i
             JOIN customers c ON i.customer_id = c.id
             JOIN businesses b ON i.business_id = b.id
             WHERE i.id = ? AND i.business_id = ?",
            [$invoiceId, $businessId]
        );

        if (!$invoice) return null;

        $invoice['items'] = Database::fetchAll(
            "SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC",
            [$invoiceId]
        );

        // Normalize invoice top-level and item aliases
        $invoice['grand_total'] = (float)($invoice['total_amount'] ?? $invoice['grand_total'] ?? 0);
        $invoice['amount_paid'] = (float)($invoice['paid_amount'] ?? $invoice['amount_paid'] ?? 0);
        $invoice['outstanding'] = (float)($invoice['balance_amount'] ?? $invoice['outstanding'] ?? 0);
        $invoice['invoice_date'] = $invoice['issue_date'] ?? $invoice['invoice_date'] ?? date('Y-m-d');
        $invoice['tax_total'] = (float)($invoice['tax_amount'] ?? $invoice['tax_total'] ?? 0);
        $invoice['discount_total'] = (float)($invoice['discount_amount'] ?? $invoice['discount_total'] ?? 0);

        foreach ($invoice['items'] as &$it) {
            $it['description'] = $it['item_name'] ?? $it['item_description'] ?? $it['description'] ?? '';
            $it['line_total'] = (float)($it['total_amount'] ?? $it['line_total'] ?? 0);
        }
        unset($it);

        $invoice['payments'] = Database::fetchAll(
            "SELECT p.*, pa.amount as allocated_amount 
             FROM payment_allocations pa
             JOIN payments p ON pa.payment_id = p.id
             WHERE pa.invoice_id = ? ORDER BY p.payment_date DESC",
            [$invoiceId]
        );

        return $invoice;
    }

    /**
     * Mark invoice as void
     */
    public static function void(int $invoiceId, int $businessId, int $userId): bool {
        $inv = self::getById($invoiceId, $businessId);
        if (!$inv) return false;

        Database::update('invoices', ['status' => 'void'], 'id = ? AND business_id = ?', [$invoiceId, $businessId]);
        ActivityLog::log($businessId, $userId, 'invoice_voided', 'invoice', $invoiceId, "Voided invoice {$inv['invoice_number']}");
        return true;
    }
}
