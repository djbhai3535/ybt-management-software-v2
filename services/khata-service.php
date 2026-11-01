<?php
declare(strict_types=1);

/**
 * YBT Management Software — Customer Khata / Ledger & Payment Automation Service
 * Handles debit/credit ledger transactions, running balance calculation, advance payments,
 * invoice integration, reversal entries, and bilingual ledger statements (You Gave / You Got).
 */

class KhataService {
    /**
     * Supported Payment Methods
     */
    public static function getPaymentMethods(): array {
        return [
            'cash' => [
                'code' => 'cash',
                'label' => 'Cash',
                'label_ur' => 'نقد',
                'icon' => 'ti ti-cash'
            ],
            'bank_transfer' => [
                'code' => 'bank_transfer',
                'label' => 'Bank Transfer',
                'label_ur' => 'بینک ٹرانسفر',
                'icon' => 'ti ti-building-bank'
            ],
            'jazzcash' => [
                'code' => 'jazzcash',
                'label' => 'JazzCash',
                'label_ur' => 'جاز کیش',
                'icon' => 'ti ti-device-mobile'
            ],
            'easypaisa' => [
                'code' => 'easypaisa',
                'label' => 'Easypaisa',
                'label_ur' => 'ایزی پیسہ',
                'icon' => 'ti ti-wallet'
            ],
            'raast' => [
                'code' => 'raast',
                'label' => 'Raast P2P',
                'label_ur' => 'راست',
                'icon' => 'ti ti-bolt'
            ],
            'other' => [
                'code' => 'other',
                'label' => 'Other / Cheque',
                'label_ur' => 'دیگر',
                'icon' => 'ti ti-credit-card'
            ]
        ];
    }

    /**
     * Transaction helper: begins a transaction only if not already active
     */
    private static function beginTx(\PDO $db): bool {
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            return true;
        }
        return false;
    }

    /**
     * Transaction helper: commits only if this call initiated the transaction
     */
    private static function commitTx(\PDO $db, bool $startedHere): void {
        if ($startedHere && $db->inTransaction()) {
            $db->commit();
        }
    }

    /**
     * Transaction helper: rolls back only if this call initiated the transaction
     */
    private static function rollbackTx(\PDO $db, bool $startedHere): void {
        if ($startedHere && $db->inTransaction()) {
            $db->rollBack();
        }
    }

    /**
     * Get current net balance for a customer (+ = customer owes shop, - = customer has advance credit)
     */
    public static function getCustomerBalance(int $customerId, int $businessId): float {
        TenantContext::assertMatches($businessId);

        $cust = Database::fetch(
            "SELECT current_balance, opening_balance, opening_balance_type FROM customers WHERE id = ? AND business_id = ?",
            [$customerId, $businessId]
        );

        if (!$cust) {
            // Check if customer belongs to another business (IDOR protection)
            $other = Database::fetch("SELECT business_id FROM customers WHERE id = ?", [$customerId]);
            if ($other && (int)$other['business_id'] !== $businessId) {
                throw new InvalidArgumentException("Customer #{$customerId} not found in this business.");
            }
            return 0.0;
        }

        // If current_balance is null, compute from opening balance
        if ($cust['current_balance'] === null) {
            $opBal = (float)($cust['opening_balance'] ?? 0);
            return ($cust['opening_balance_type'] === 'credit') ? -$opBal : $opBal;
        }

        return (float)$cust['current_balance'];
    }

    /**
     * Set or update customer opening balance
     */
    public static function setOpeningBalance(
        int $customerId,
        int $businessId,
        float $amount,
        string $type = 'debit', // 'debit' = customer owes, 'credit' = advance credit
        ?string $notes = null,
        ?int $userId = null
    ): array {
        TenantContext::assertMatches($businessId);
        $db = Database::getInstance();
        $startedTx = self::beginTx($db);

        try {
            $amount = max(0.0, $amount);
            $type = in_array(strtolower($type), ['debit', 'credit'], true) ? strtolower($type) : 'debit';

            // Check if opening balance entry already exists
            $existing = Database::fetch(
                "SELECT id FROM customer_ledger WHERE customer_id = ? AND business_id = ? AND entry_type = 'opening_balance'",
                [$customerId, $businessId]
            );

            $debit = ($type === 'debit') ? $amount : 0.0;
            $credit = ($type === 'credit') ? $amount : 0.0;
            $runningBalance = ($type === 'debit') ? $amount : -$amount;

            if ($existing) {
                // Update existing opening balance entry
                Database::query(
                    "UPDATE customer_ledger SET debit = ?, credit = ?, running_balance = ?, notes = ?, updated_at = datetime('now') WHERE id = ?",
                    [$debit, $credit, $runningBalance, $notes, $existing['id']]
                );
            } else if ($amount > 0) {
                // Insert new opening balance entry
                Database::insert('customer_ledger', [
                    'uuid' => generateUuidV4(),
                    'business_id' => $businessId,
                    'customer_id' => $customerId,
                    'entry_type' => 'opening_balance',
                    'reference_type' => 'manual',
                    'reference_id' => null,
                    'reference_number' => 'OP-BAL',
                    'entry_date' => date('Y-m-d'),
                    'debit' => $debit,
                    'credit' => $credit,
                    'running_balance' => $runningBalance,
                    'description' => 'Opening Balance (' . ($type === 'debit' ? 'Customer Owes' : 'Customer Advance') . ')',
                    'notes' => $notes,
                    'idempotency_key' => "opening_bal_{$businessId}_{$customerId}",
                    'sync_uuid' => generateUuidV4(),
                    'created_by' => $userId,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            // Update customer record
            Database::query(
                "UPDATE customers SET opening_balance = ?, opening_balance_type = ?, updated_at = datetime('now') WHERE id = ? AND business_id = ?",
                [$amount, $type, $customerId, $businessId]
            );

            // Recompute running balance for entire customer ledger
            self::recalculateCustomerKhata($customerId, $businessId);

            self::commitTx($db, $startedTx);

            return [
                'success' => true,
                'customer_id' => $customerId,
                'opening_balance' => $amount,
                'opening_balance_type' => $type
            ];
        } catch (\Throwable $e) {
            self::rollbackTx($db, $startedTx);
            throw $e;
        }
    }

    /**
     * Record a raw ledger entry (Debit or Credit) with atomic balance update and idempotency protection
     */
    public static function recordEntry(
        int $businessId,
        int $customerId,
        string $entryType,
        float $debit,
        float $credit,
        string $entryDate,
        ?string $refType = null,
        ?int $refId = null,
        ?string $refNum = null,
        ?string $description = null,
        ?string $notes = null,
        ?string $paymentMethod = null,
        ?string $idempotencyKey = null,
        ?int $userId = null
    ): array {
        TenantContext::assertMatches($businessId);
        $db = Database::getInstance();
        $startedTx = self::beginTx($db);

        try {
            // IDEMPOTENCY GUARD: Prevent duplicate insertion
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = Database::fetch(
                    "SELECT id, running_balance FROM customer_ledger WHERE idempotency_key = ?",
                    [$idempotencyKey]
                );
                if ($existing) {
                    self::commitTx($db, $startedTx);
                    return [
                        'success' => true,
                        'already_recorded' => true,
                        'ledger_id' => (int)$existing['id'],
                        'running_balance' => (float)$existing['running_balance'],
                        'message' => 'Ledger entry already recorded with this idempotency key.'
                    ];
                }
            }

            $currentBal = self::getCustomerBalance($customerId, $businessId);
            $newBal = $currentBal + $debit - $credit;

            $ledgerId = Database::insert('customer_ledger', [
                'uuid' => generateUuidV4(),
                'business_id' => $businessId,
                'customer_id' => $customerId,
                'entry_type' => $entryType,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'reference_number' => $refNum,
                'entry_date' => $entryDate,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $newBal,
                'payment_method' => $paymentMethod,
                'description' => $description,
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
                'sync_uuid' => generateUuidV4(),
                'sync_status' => 'pending',
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Update customer's current balance
            Database::query(
                "UPDATE customers SET current_balance = ?, updated_at = datetime('now') WHERE id = ? AND business_id = ?",
                [$newBal, $customerId, $businessId]
            );

            self::commitTx($db, $startedTx);

            return [
                'success' => true,
                'already_recorded' => false,
                'ledger_id' => $ledgerId,
                'debit' => $debit,
                'credit' => $credit,
                'balance_before' => $currentBal,
                'running_balance' => $newBal
            ];
        } catch (\Throwable $e) {
            self::rollbackTx($db, $startedTx);
            throw $e;
        }
    }

    /**
     * Record sales invoice debit into customer khata (You Gave / آپ نے دیے)
     */
    public static function recordInvoiceDebit(int $businessId, int $invoiceId, ?int $userId = null): array {
        TenantContext::assertMatches($businessId);

        $inv = Database::fetch(
            "SELECT id, customer_id, invoice_number, invoice_date, grand_total, status FROM invoices WHERE id = ? AND business_id = ?",
            [$invoiceId, $businessId]
        );

        if (!$inv) {
            throw new InvalidArgumentException("Invoice #{$invoiceId} not found in this business.");
        }

        $idempKey = "invoice_debit_{$businessId}_{$invoiceId}";
        $desc = "Sales Invoice #{$inv['invoice_number']}";

        return self::recordEntry(
            $businessId,
            (int)$inv['customer_id'],
            'invoice',
            (float)$inv['grand_total'],
            0.0,
            $inv['invoice_date'],
            'invoice',
            $invoiceId,
            $inv['invoice_number'],
            $desc,
            null,
            null,
            $idempKey,
            $userId
        );
    }

    /**
     * Reverse invoice effects in customer khata upon cancellation (Exact & Idempotent)
     */
    public static function reverseInvoice(int $businessId, int $invoiceId, ?int $userId = null): array {
        TenantContext::assertMatches($businessId);

        $inv = Database::fetch(
            "SELECT id, customer_id, invoice_number, invoice_date, grand_total FROM invoices WHERE id = ? AND business_id = ?",
            [$invoiceId, $businessId]
        );

        if (!$inv) {
            throw new InvalidArgumentException("Invoice #{$invoiceId} not found.");
        }

        $idempKey = "invoice_cancel_{$businessId}_{$invoiceId}";
        $desc = "Reversal: Cancelled Invoice #{$inv['invoice_number']}";

        // Cancellation credit = invoice grand_total (reversing the debit)
        return self::recordEntry(
            $businessId,
            (int)$inv['customer_id'],
            'invoice_cancelled',
            0.0,
            (float)$inv['grand_total'],
            date('Y-m-d'),
            'invoice',
            $invoiceId,
            $inv['invoice_number'],
            $desc,
            'Automatic ledger reversal on invoice cancellation',
            null,
            $idempKey,
            $userId
        );
    }

    /**
     * Record a customer payment (You Got / آپ کو ملے)
     * Handles FIFO invoice allocations, partial payments, and advance payments.
     */
    public static function recordCustomerPayment(int $businessId, array $data, int $userId): array {
        TenantContext::assertMatches($businessId);
        $db = Database::getInstance();
        $startedTx = self::beginTx($db);

        try {
            $customerId = (int)($data['customer_id'] ?? 0);
            $amount = (float)($data['amount'] ?? 0);
            $method = trim($data['method'] ?? 'cash');
            $paymentDate = !empty($data['payment_date']) ? trim($data['payment_date']) : date('Y-m-d');
            $ref = trim($data['reference'] ?? '');
            $notes = trim($data['notes'] ?? '');
            $specifiedInvoiceId = !empty($data['invoice_id']) ? (int)$data['invoice_id'] : null;
            $idempotencyKey = !empty($data['idempotency_key']) ? trim($data['idempotency_key']) : null;

            if ($customerId <= 0) {
                throw new InvalidArgumentException("A valid customer must be selected.");
            }
            if ($amount <= 0.001) {
                throw new InvalidArgumentException("Payment amount must be greater than zero.");
            }

            // DUPLICATE PAYMENT GUARD: check explicit idempotency key or natural key
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = Database::fetch(
                    "SELECT cl.id, cl.reference_id, cl.reference_number, cl.running_balance 
                     FROM customer_ledger cl 
                     WHERE cl.idempotency_key = ?",
                    [$idempotencyKey]
                );
                if ($existing) {
                    self::commitTx($db, $startedTx);
                    return [
                        'success' => true,
                        'already_recorded' => true,
                        'payment_id' => (int)($existing['reference_id'] ?? 0),
                        'payment_number' => $existing['reference_number'] ?? '',
                        'running_balance' => (float)$existing['running_balance'],
                        'message' => 'Payment already recorded with this idempotency key.'
                    ];
                }
            } else {
                // Automatic duplicate check within same minute for identical customer, amount, reference
                $existingRecent = Database::fetch(
                    "SELECT id, payment_number FROM payments 
                     WHERE business_id = ? AND customer_id = ? AND amount = ? AND payment_date = ? AND reference = ? 
                       AND created_at >= datetime('now', '-2 minutes')",
                    [$businessId, $customerId, $amount, $paymentDate, $ref]
                );
                if ($existingRecent && $ref !== '') {
                    self::commitTx($db, $startedTx);
                    return [
                        'success' => true,
                        'already_recorded' => true,
                        'payment_id' => (int)$existingRecent['id'],
                        'payment_number' => $existingRecent['payment_number'],
                        'message' => 'Duplicate payment detected within 2 minutes.'
                    ];
                }
            }

            // 1. Generate Payment Number
            $payCount = Database::fetch("SELECT COUNT(*) as cnt FROM payments WHERE business_id = ?", [$businessId]);
            $num = ($payCount['cnt'] ?? 0) + 1;
            $paymentNumber = 'PAY-' . date('Y') . '-' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
            $paymentUuid = generateUuidV4();

            // 2. Insert into payments table
            $paymentId = Database::insert('payments', [
                'uuid' => $paymentUuid,
                'business_id' => $businessId,
                'customer_id' => $customerId,
                'payment_number' => $paymentNumber,
                'payment_method' => $method,
                'method' => $method,
                'amount' => $amount,
                'transaction_reference' => $ref,
                'reference' => $ref,
                'payment_date' => $paymentDate,
                'notes' => $notes,
                'status' => 'completed',
                'sync_status' => 'pending',
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // 3. Invoice Allocations (FIFO or Specified)
            $remainingToAllocate = $amount;
            $allocatedInvoices = [];

            if ($specifiedInvoiceId !== null) {
                // Allocate specifically to selected invoice
                $inv = Database::fetch(
                    "SELECT id, invoice_number, grand_total, outstanding FROM invoices WHERE id = ? AND business_id = ? AND customer_id = ?",
                    [$specifiedInvoiceId, $businessId, $customerId]
                );
                if ($inv && $inv['outstanding'] > 0) {
                    $allocAmt = min($remainingToAllocate, (float)$inv['outstanding']);
                    self::applyInvoiceAllocation($paymentId, $inv['id'], $allocAmt, $businessId);
                    $remainingToAllocate -= $allocAmt;
                    $allocatedInvoices[] = [
                        'invoice_id' => $inv['id'],
                        'invoice_number' => $inv['invoice_number'],
                        'allocated_amount' => $allocAmt
                    ];
                }
            } else {
                // Allocate FIFO across unpaid/partial invoices
                $unpaidInvoices = Database::fetchAll(
                    "SELECT id, invoice_number, grand_total, outstanding FROM invoices 
                     WHERE business_id = ? AND customer_id = ? AND status IN ('sent', 'partial', 'overdue', 'unpaid') AND outstanding > 0 
                     ORDER BY invoice_date ASC, id ASC",
                    [$businessId, $customerId]
                );

                foreach ($unpaidInvoices as $inv) {
                    if ($remainingToAllocate <= 0.001) break;
                    $allocAmt = min($remainingToAllocate, (float)$inv['outstanding']);
                    self::applyInvoiceAllocation($paymentId, $inv['id'], $allocAmt, $businessId);
                    $remainingToAllocate -= $allocAmt;
                    $allocatedInvoices[] = [
                        'invoice_id' => $inv['id'],
                        'invoice_number' => $inv['invoice_number'],
                        'allocated_amount' => $allocAmt
                    ];
                }
            }

            // 4. Ledger Entry Description
            $methodList = self::getPaymentMethods();
            $methodName = $methodList[$method]['label'] ?? ucfirst($method);
            $entryType = 'payment';
            $description = "Payment Received ({$methodName}) #{$paymentNumber}";

            // If remaining amount > 0, customer paid advance credit!
            if ($remainingToAllocate > 0.001 && empty($allocatedInvoices)) {
                $entryType = 'advance_payment';
                $description = "Advance Payment ({$methodName}) #{$paymentNumber}";
            } elseif ($remainingToAllocate > 0.001) {
                $description .= " (Incl. Advance: " . number_format($remainingToAllocate, 2) . ")";
            }

            $payIdempKey = $idempotencyKey ?: "payment_{$businessId}_{$paymentId}";

            // 5. Record Ledger Credit (You Got / آپ کو ملے)
            $ledgerRes = self::recordEntry(
                $businessId,
                $customerId,
                $entryType,
                0.0,
                $amount,
                $paymentDate,
                'payment',
                $paymentId,
                $paymentNumber,
                $description,
                $notes,
                $method,
                $payIdempKey,
                $userId
            );

            ActivityLog::log(
                $businessId,
                $userId,
                'payment_recorded',
                'payment',
                $paymentId,
                "Received {$paymentNumber} of " . number_format($amount, 2) . " via {$methodName} for customer #{$customerId}"
            );

            self::commitTx($db, $startedTx);

            return [
                'success' => true,
                'already_recorded' => false,
                'payment_id' => $paymentId,
                'payment_number' => $paymentNumber,
                'amount' => $amount,
                'allocated' => $amount - $remainingToAllocate,
                'advance' => $remainingToAllocate,
                'running_balance' => $ledgerRes['running_balance'],
                'allocations' => $allocatedInvoices
            ];
        } catch (\Throwable $e) {
            self::rollbackTx($db, $startedTx);
            throw $e;
        }
    }

    /**
     * Apply allocation to an invoice and recalculate invoice status atomically
     */
    private static function applyInvoiceAllocation(int $paymentId, int $invoiceId, float $amount, int $businessId): void {
        Database::insert('payment_allocations', [
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $allocRow = Database::fetch("SELECT COALESCE(SUM(amount), 0) as total FROM payment_allocations WHERE invoice_id = ?", [$invoiceId]);
        $totalPaid = (float)($allocRow['total'] ?? 0);
        $inv = Database::fetch("SELECT grand_total FROM invoices WHERE id = ?", [$invoiceId]);
        $outstanding = max(0.0, (float)$inv['grand_total'] - $totalPaid);

        $newStatus = 'sent';
        if ($outstanding <= 0.009) {
            $newStatus = 'paid';
        } elseif ($totalPaid > 0) {
            $newStatus = 'partial';
        }

        Database::query(
            "UPDATE invoices SET amount_paid = ?, outstanding = ?, status = ?, updated_at = datetime('now') WHERE id = ?",
            [$totalPaid, $outstanding, $newStatus, $invoiceId]
        );
    }

    /**
     * Recalculate customer running balances chronologically for 100% mathematical integrity
     */
    public static function recalculateCustomerKhata(int $customerId, int $businessId): float {
        TenantContext::assertMatches($businessId);

        $entries = Database::fetchAll(
            "SELECT id, debit, credit FROM customer_ledger 
             WHERE customer_id = ? AND business_id = ? 
             ORDER BY entry_date ASC, id ASC",
            [$customerId, $businessId]
        );

        $running = 0.0;
        foreach ($entries as $row) {
            $running += (float)$row['debit'] - (float)$row['credit'];
            Database::query("UPDATE customer_ledger SET running_balance = ? WHERE id = ?", [$running, $row['id']]);
        }

        Database::query("UPDATE customers SET current_balance = ? WHERE id = ? AND business_id = ?", [$running, $customerId, $businessId]);
        return $running;
    }

    /**
     * Get complete printable statement & ledger history for a customer
     */
    public static function getStatement(
        int $customerId,
        int $businessId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $searchTerm = null
    ): array {
        TenantContext::assertMatches($businessId);

        $customer = Database::fetch(
            "SELECT c.*, b.name as business_name, b.email as business_email, b.phone as business_phone, 
                    b.address_line1 as business_address, b.city as business_city, b.default_currency 
             FROM customers c 
             JOIN businesses b ON c.business_id = b.id 
             WHERE c.id = ? AND c.business_id = ?",
            [$customerId, $businessId]
        );

        if (!$customer) {
            throw new InvalidArgumentException("Customer not found.");
        }

        $currency = $customer['default_currency'] ?: 'PKR';

        // Base where clause
        $where = ["cl.business_id = ?", "cl.customer_id = ?"];
        $params = [$businessId, $customerId];

        // Period filter & Period opening balance calculation
        $periodOpeningBalance = 0.0;
        if ($fromDate !== null && $fromDate !== '') {
            // Calculate opening balance before fromDate
            $beforeRow = Database::fetch(
                "SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as prev_bal 
                 FROM customer_ledger 
                 WHERE business_id = ? AND customer_id = ? AND entry_date < ?",
                [$businessId, $customerId, $fromDate]
            );
            $periodOpeningBalance = (float)($beforeRow['prev_bal'] ?? 0);

            $where[] = "cl.entry_date >= ?";
            $params[] = $fromDate;
        }

        if ($toDate !== null && $toDate !== '') {
            $where[] = "cl.entry_date <= ?";
            $params[] = $toDate;
        }

        if ($searchTerm !== null && trim($searchTerm) !== '') {
            $term = '%' . trim($searchTerm) . '%';
            $where[] = "(cl.description LIKE ? OR cl.reference_number LIKE ? OR cl.notes LIKE ?)";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $whereClause = implode(' AND ', $where);
        $entries = Database::fetchAll(
            "SELECT cl.*, u.name as created_by_name 
             FROM customer_ledger cl 
             LEFT JOIN users u ON cl.created_by = u.id 
             WHERE {$whereClause} 
             ORDER BY cl.entry_date ASC, cl.id ASC",
            $params
        );

        // Calculate totals for statement period
        $totalGave = 0.0; // Debits
        $totalGot = 0.0;  // Credits
        foreach ($entries as $e) {
            $totalGave += (float)$e['debit'];
            $totalGot += (float)$e['credit'];
        }

        $netPeriod = $totalGave - $totalGot;
        $netClosingBalance = $periodOpeningBalance + $netPeriod;

        // Current real-time overall balance
        $currentRealBalance = self::getCustomerBalance($customerId, $businessId);

        return [
            'customer' => $customer,
            'currency' => $currency,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'period_opening_balance' => $periodOpeningBalance,
            'total_gave' => $totalGave,           // آپ نے دیے (Debits)
            'total_got' => $totalGot,             // آپ کو ملے (Credits)
            'net_closing_balance' => $netClosingBalance,
            'current_balance' => $currentRealBalance,
            'entries' => $entries
        ];
    }
}
