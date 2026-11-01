<?php
declare(strict_types=1);

/**
 * YBT Management Software — Stage 3: Customer Khata / Ledger & Payment Automation
 * Comprehensive Verification Test Suite
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../services/customer-service.php';
require_once __DIR__ . '/../services/khata-service.php';
require_once __DIR__ . '/../services/invoice-service.php';
require_once __DIR__ . '/../services/payment-service.php';
require_once __DIR__ . '/../services/stock-service.php';

$passedCount = 0;
$totalTests = 13;
$failures = [];

function runTest(int $num, string $title, callable $fn): void {
    global $passedCount, $failures;
    echo sprintf("\n--- [STAGE 3 - TEST %02d/13] %s ---\n", $num, $title);
    try {
        $res = $fn();
        if ($res === true || $res === null) {
            echo " [PASS] Test {$num} Passed: {$title}\n";
            $passedCount++;
        } else {
            echo " [FAIL] Test {$num} Failed: {$title} - {$res}\n";
            $failures[] = "Test {$num}: {$title} - {$res}";
        }
    } catch (\Throwable $e) {
        echo " [FAIL] Exception in Test {$num}: " . $e->getMessage() . "\n";
        echo "        File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failures[] = "Test {$num}: {$title} - Exception: " . $e->getMessage();
    }
}

echo "====================================================================\n";
echo "   YBT MANAGEMENT SOFTWARE — STAGE 3 CUSTOMER KHATA & PAYMENTS      \n";
echo "====================================================================\n";

// Shared Fixture Setup
$testBizId = Database::insert('businesses', [
    'tenant_uuid' => generateUuidV4(),
    'name' => 'Al-Rehman Mobile Mall Test Lab',
    'country' => 'PK',
    'default_currency' => 'PKR',
    'status' => 'active'
]);

$testBizId2 = Database::insert('businesses', [
    'tenant_uuid' => generateUuidV4(),
    'name' => 'Cross-Tenant Mobile Shop B',
    'country' => 'PK',
    'default_currency' => 'PKR',
    'status' => 'active'
]);

$testUserId = Database::insert('users', [
    'business_id' => $testBizId,
    'name' => 'Kashif Shopkeeper',
    'email' => 'kashif_khata_' . uniqid() . '@test.pk',
    'password' => password_hash('Pass#2026', PASSWORD_DEFAULT),
    'is_owner' => 1,
    'is_active' => 1
]);

// Tracked variables across tests
$customer1Id = 0;
$customer2Id = 0;
$invoice1Id = 0;
$productId = 0;

// Set TenantContext for Shop A
TenantContext::set($testBizId);

// Create a stock product for selling (10 units in stock)
$productId = Database::insert('products', [
    'business_id' => $testBizId,
    'name' => 'Samsung A15 Super AMOLED Display',
    'sku' => 'DISP-SAM-A15-' . rand(1000, 9999),
    'brand' => 'Samsung',
    'model' => 'Galaxy A15',
    'part_type' => 'Display / Touch Screen',
    'selling_price' => 5000.00,
    'purchase_price' => 3500.00,
    'stock_quantity' => 10.0,
    'min_stock_alert' => 2,
    'status' => 'active',
    'created_at' => date('Y-m-d H:i:s')
]);

// -------------------------------------------------------------------------
// TEST 1: Customer Creation with Opening Balance (PKR 1,500 Debit)
// -------------------------------------------------------------------------
runTest(1, "Create Customer with Opening Balance (PKR 1,500 Debit)", function() use ($testBizId, $testUserId, &$customer1Id) {
    $custData = [
        'name' => 'Haji Bilal Electronics',
        'company_name' => 'Bilal Telecom',
        'phone' => '+92 321 9876543',
        'email' => 'bilal_telecom_' . uniqid() . '@test.pk',
        'billing_city' => 'Lahore',
        'opening_balance' => 1500.00,
        'opening_balance_type' => 'debit',
        'opening_balance_notes' => 'Old ledger dues brought forward from paper register'
    ];

    $customer1Id = CustomerService::create($testBizId, $custData, $testUserId);
    if (!$customer1Id) {
        return "Failed to create customer.";
    }

    // Verify customer current balance is 1500
    $bal = KhataService::getCustomerBalance($customer1Id, $testBizId);
    if ((float)$bal !== 1500.0) {
        return "Expected initial customer balance 1500.0, found: {$bal}";
    }

    // Verify ledger has opening_balance entry
    $statement = KhataService::getStatement($customer1Id, $testBizId);
    if (count($statement['entries']) !== 1) {
        return "Expected 1 ledger entry, found: " . count($statement['entries']);
    }
    $opEntry = $statement['entries'][0];
    if ($opEntry['entry_type'] !== 'opening_balance' || (float)$opEntry['debit'] !== 1500.0 || (float)$opEntry['running_balance'] !== 1500.0) {
        return "Opening balance entry mismatch: " . json_encode($opEntry);
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 2: Customer 2 Creation & Sales Invoice of PKR 5,000
// -------------------------------------------------------------------------
runTest(2, "Create Customer 2 (Zero Balance) & Issue Sales Invoice of PKR 5,000", function() use ($testBizId, $testUserId, $productId, &$customer2Id, &$invoice1Id) {
    $customer2Id = CustomerService::create($testBizId, [
        'name' => 'Waqas Repair Shop',
        'phone' => '+92 300 5551234',
        'billing_city' => 'Rawalpindi',
        'opening_balance' => 0.00
    ], $testUserId);

    $bal0 = KhataService::getCustomerBalance($customer2Id, $testBizId);
    if ((float)$bal0 !== 0.0) {
        return "Expected initial balance 0.0, found: {$bal0}";
    }

    // Create Invoice for PKR 5,000 (1x Samsung A15 Display)
    $invData = [
        'customer_id' => $customer2Id,
        'invoice_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+10 days')),
        'status' => 'sent', // non-draft triggers stock deduction and ledger debit
        'notes' => 'Handset repair display replacement'
    ];
    $items = [
        [
            'product_id' => $productId,
            'name' => 'Samsung A15 Super AMOLED Display',
            'description' => 'Display replacement unit',
            'quantity' => 1,
            'unit_price' => 5000.00,
            'discount_amount' => 0,
            'tax_rate' => 0
        ]
    ];

    $invoice1Id = InvoiceService::create($testBizId, $invData, $items, $testUserId);
    if (!$invoice1Id) {
        return "Invoice creation failed.";
    }

    // Verify stock decreased from 10 to 9
    $prod = Database::fetch("SELECT stock_quantity FROM products WHERE id = ?", [$productId]);
    if ((float)$prod['stock_quantity'] !== 9.0) {
        return "Expected product stock 9.0 after sale, found: " . ($prod['stock_quantity'] ?? 'null');
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 3: Verify Customer Outstanding Balance Becomes PKR 5,000
// -------------------------------------------------------------------------
runTest(3, "Verify Customer Outstanding Balance Becomes Exactly PKR 5,000", function() use ($testBizId, $customer2Id, $invoice1Id) {
    $bal = KhataService::getCustomerBalance($customer2Id, $testBizId);
    if ((float)$bal !== 5000.0) {
        return "Expected customer balance 5000.0, found: {$bal}";
    }

    $statement = KhataService::getStatement($customer2Id, $testBizId);
    if ((float)$statement['total_gave'] !== 5000.0) {
        return "Expected You Gave (Total Debits) = 5000.0, found: " . $statement['total_gave'];
    }
    if ((float)$statement['total_got'] !== 0.0) {
        return "Expected You Got (Total Credits) = 0.0, found: " . $statement['total_got'];
    }
    if ((float)$statement['current_balance'] !== 5000.0) {
        return "Expected current_balance = 5000.0, found: " . $statement['current_balance'];
    }

    // Verify ledger record
    $entries = $statement['entries'];
    if (count($entries) !== 1 || $entries[0]['entry_type'] !== 'invoice' || (float)$entries[0]['debit'] !== 5000.0) {
        return "Invoice ledger entry mismatch: " . json_encode($entries);
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 4: Partial Payment of PKR 2,000 (JazzCash)
// -------------------------------------------------------------------------
runTest(4, "Receive PKR 2,000 Partial Payment (JazzCash): Invoice Becomes 'partial'", function() use ($testBizId, $testUserId, $customer2Id, $invoice1Id) {
    $payData = [
        'customer_id' => $customer2Id,
        'amount' => 2000.00,
        'method' => 'jazzcash',
        'reference' => 'JC-TID-998811',
        'notes' => 'Paid via JazzCash mobile transfer',
        'invoice_id' => $invoice1Id
    ];

    $payRes = KhataService::recordCustomerPayment($testBizId, $payData, $testUserId);
    if (!$payRes['success']) {
        return "Payment recording failed: " . json_encode($payRes);
    }

    // Verify invoice outstanding is 3000 and status is 'partial'
    $inv = Database::fetch("SELECT status, amount_paid, outstanding FROM invoices WHERE id = ?", [$invoice1Id]);
    if ($inv['status'] !== 'partial') {
        return "Expected invoice status 'partial', found: " . $inv['status'];
    }
    if ((float)$inv['amount_paid'] !== 2000.0 || (float)$inv['outstanding'] !== 3000.0) {
        return "Invoice amount_paid or outstanding mismatch: " . json_encode($inv);
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 5: Verify Remaining Khata Balance Becomes PKR 3,000
// -------------------------------------------------------------------------
runTest(5, "Verify Customer Remaining Khata Balance Becomes Exactly PKR 3,000", function() use ($testBizId, $customer2Id) {
    $bal = KhataService::getCustomerBalance($customer2Id, $testBizId);
    if ((float)$bal !== 3000.0) {
        return "Expected customer khata balance 3000.0, found: {$bal}";
    }

    $statement = KhataService::getStatement($customer2Id, $testBizId);
    if ((float)$statement['total_gave'] !== 5000.0) {
        return "Expected You Gave = 5000.0, found: " . $statement['total_gave'];
    }
    if ((float)$statement['total_got'] !== 2000.0) {
        return "Expected You Got = 2000.0, found: " . $statement['total_got'];
    }
    if ((float)$statement['current_balance'] !== 3000.0) {
        return "Expected Net Balance = 3000.0, found: " . $statement['current_balance'];
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 6: Receive Remaining PKR 3,000 (Raast / Bank Transfer)
// -------------------------------------------------------------------------
runTest(6, "Receive Another PKR 3,000 Payment (Raast P2P): Invoice Becomes 'paid'", function() use ($testBizId, $testUserId, $customer2Id, $invoice1Id) {
    $payData = [
        'customer_id' => $customer2Id,
        'amount' => 3000.00,
        'method' => 'raast',
        'reference' => 'RAAST-STAN-445566',
        'notes' => 'Cleared remaining invoice balance via Raast',
        'invoice_id' => $invoice1Id
    ];

    $payRes = KhataService::recordCustomerPayment($testBizId, $payData, $testUserId);
    if (!$payRes['success']) {
        return "Payment recording failed: " . json_encode($payRes);
    }

    // Verify invoice is fully paid
    $inv = Database::fetch("SELECT status, amount_paid, outstanding FROM invoices WHERE id = ?", [$invoice1Id]);
    if ($inv['status'] !== 'paid') {
        return "Expected invoice status 'paid', found: " . $inv['status'];
    }
    if ((float)$inv['outstanding'] !== 0.0) {
        return "Expected invoice outstanding 0.0, found: " . $inv['outstanding'];
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 7: Verify Customer Balance Becomes Exactly Zero
// -------------------------------------------------------------------------
runTest(7, "Verify Customer Balance Becomes Exactly Zero (Account Cleared)", function() use ($testBizId, $customer2Id) {
    $bal = KhataService::getCustomerBalance($customer2Id, $testBizId);
    if ((float)$bal !== 0.0) {
        return "Expected customer khata balance 0.0, found: {$bal}";
    }

    $statement = KhataService::getStatement($customer2Id, $testBizId);
    if ((float)$statement['total_gave'] !== 5000.0 || (float)$statement['total_got'] !== 5000.0) {
        return "Totals mismatch: Gave={$statement['total_gave']}, Got={$statement['total_got']}";
    }
    if ((float)$statement['current_balance'] !== 0.0) {
        return "Net balance is not zero: " . $statement['current_balance'];
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 8: Receive Advance Payment of PKR 1,500 (Easypaisa)
// -------------------------------------------------------------------------
runTest(8, "Receive Advance Payment of PKR 1,500 (Easypaisa): Balance Becomes -1,500 (Credit)", function() use ($testBizId, $testUserId, $customer2Id) {
    $advData = [
        'customer_id' => $customer2Id,
        'amount' => 1500.00,
        'method' => 'easypaisa',
        'reference' => 'EP-TXN-778899',
        'notes' => 'Customer advance deposit for upcoming repairs'
    ];

    $advRes = KhataService::recordCustomerPayment($testBizId, $advData, $testUserId);
    if (!$advRes['success']) {
        return "Advance payment recording failed: " . json_encode($advRes);
    }
    if ((float)$advRes['advance'] !== 1500.0) {
        return "Expected full amount 1500.0 as advance credit, found: " . $advRes['advance'];
    }

    // Verify balance is now -1500 (Credit / Advance held by shop)
    $bal = KhataService::getCustomerBalance($customer2Id, $testBizId);
    if ((float)$bal !== -1500.0) {
        return "Expected advance credit balance -1500.0, found: {$bal}";
    }

    $statement = KhataService::getStatement($customer2Id, $testBizId);
    if ((float)$statement['total_got'] !== 6500.0) {
        return "Expected total Got 6500.0, found: " . $statement['total_got'];
    }
    if ((float)$statement['current_balance'] !== -1500.0) {
        return "Expected statement current balance -1500.0, found: " . $statement['current_balance'];
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 9: Invoice Cancellation Reverses Ledger Effect Exactly Once
// -------------------------------------------------------------------------
runTest(9, "Invoice Cancellation Reverses Ledger Effect Exactly Once (Idempotent)", function() use ($testBizId, $testUserId, $productId) {
    // Create new customer 3 for isolated cancellation test
    $cust3Id = CustomerService::create($testBizId, ['name' => 'Cancel Test Customer', 'phone' => '+92 333 1112233'], $testUserId);
    
    // Issue invoice of PKR 4,000
    $invId = InvoiceService::create($testBizId, [
        'customer_id' => $cust3Id,
        'invoice_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+5 days')),
        'status' => 'sent'
    ], [
        [
            'product_id' => $productId,
            'name' => 'Samsung A15 Super AMOLED Display',
            'quantity' => 1,
            'unit_price' => 4000.00,
            'tax_rate' => 0
        ]
    ], $testUserId);

    // Verify balance is 4000
    $balBefore = KhataService::getCustomerBalance($cust3Id, $testBizId);
    if ((float)$balBefore !== 4000.0) {
        return "Expected balance 4000.0 after invoice, found: {$balBefore}";
    }

    // Cancel invoice
    $cancelRes = InvoiceService::cancel($invId, $testBizId, $testUserId);
    if (!$cancelRes['success']) {
        return "Invoice cancellation failed: " . json_encode($cancelRes);
    }

    // Verify balance is now reversed back to 0.0
    $balAfter = KhataService::getCustomerBalance($cust3Id, $testBizId);
    if ((float)$balAfter !== 0.0) {
        return "Expected balance restored to 0.0 after cancellation reversal, found: {$balAfter}";
    }

    // Verify ledger contains reversal entry
    $st = KhataService::getStatement($cust3Id, $testBizId);
    if (count($st['entries']) !== 2) {
        return "Expected 2 ledger entries (invoice + cancellation reversal), found: " . count($st['entries']);
    }
    if ($st['entries'][1]['entry_type'] !== 'invoice_cancelled' || (float)$st['entries'][1]['credit'] !== 4000.0) {
        return "Cancellation reversal entry incorrect: " . json_encode($st['entries'][1]);
    }

    // Re-trigger cancel to verify idempotency: balance must remain 0.0
    $secondCancel = InvoiceService::cancel($invId, $testBizId, $testUserId);
    $balSecond = KhataService::getCustomerBalance($cust3Id, $testBizId);
    if ((float)$balSecond !== 0.0) {
        return "Balance corrupted on second cancel! Expected 0.0, found: {$balSecond}";
    }
    $st2 = KhataService::getStatement($cust3Id, $testBizId);
    if (count($st2['entries']) !== 2) {
        return "Duplicate reversal entry was created on retry!";
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 10: Duplicate Payment Prevention via Idempotency Key
// -------------------------------------------------------------------------
runTest(10, "Duplicate Payment Prevention: Identical Key Returns already_recorded and Preserves Balance", function() use ($testBizId, $testUserId, $customer2Id) {
    $balBefore = KhataService::getCustomerBalance($customer2Id, $testBizId);
    $idempKey = 'pay_unique_idemp_key_' . uniqid();

    $payData = [
        'customer_id' => $customer2Id,
        'amount' => 500.00,
        'method' => 'cash',
        'reference' => 'CASH-REC-1',
        'idempotency_key' => $idempKey
    ];

    // First attempt -> succeeds
    $res1 = KhataService::recordCustomerPayment($testBizId, $payData, $testUserId);
    if (!$res1['success'] || !empty($res1['already_recorded'])) {
        return "First payment failed: " . json_encode($res1);
    }

    $balAfterFirst = KhataService::getCustomerBalance($customer2Id, $testBizId);
    if ((float)$balAfterFirst !== (float)($balBefore - 500.0)) {
        return "Balance after first payment mismatch: expected " . ($balBefore - 500.0) . ", found: {$balAfterFirst}";
    }

    // Second attempt with SAME idempotency key -> must be caught as duplicate
    $res2 = KhataService::recordCustomerPayment($testBizId, $payData, $testUserId);
    if (empty($res2['already_recorded'])) {
        return "System allowed duplicate payment submission! Result: " . json_encode($res2);
    }

    // Verify balance is completely unchanged
    $balAfterSecond = KhataService::getCustomerBalance($customer2Id, $testBizId);
    if ((float)$balAfterSecond !== (float)$balAfterFirst) {
        return "Balance was corrupted by duplicate payment! Expected {$balAfterFirst}, found: {$balAfterSecond}";
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 11: Cross-Tenant Khata Isolation (Fail-Closed)
// -------------------------------------------------------------------------
runTest(11, "Cross-Tenant Khata Isolation: Shop A Customer Ledger Blocked for Shop B", function() use ($testBizId, $testBizId2, $customer1Id) {
    // Switch context to Shop B
    TenantContext::set($testBizId2);

    $crossReadBlocked = false;
    try {
        KhataService::getStatement($customer1Id, $testBizId2);
    } catch (\Throwable $e) {
        $crossReadBlocked = true;
    }

    if (!$crossReadBlocked) {
        return "Shop B was able to read Shop A's customer ledger statement!";
    }

    // Switch context back to Shop A
    TenantContext::set($testBizId);
    return true;
});

// -------------------------------------------------------------------------
// TEST 12: Offline-to-Online Idempotent Sync Compatibility
// -------------------------------------------------------------------------
runTest(12, "Offline-to-Online Idempotent Sync: UUID Deduplication & Ledger Integrity", function() use ($testBizId, $testUserId, $customer1Id) {
    // Generate an offline ledger transaction
    $offlineUuid = generateUuidV4();
    $idempKey = "sync_offline_ledger_{$offlineUuid}";

    $res1 = KhataService::recordEntry(
        $testBizId,
        $customer1Id,
        'adjustment',
        250.00,
        0.0,
        date('Y-m-d'),
        'manual',
        null,
        'OFFLINE-ADJ-01',
        'Offline repair diagnostic debit',
        'Simulated offline device transaction',
        null,
        $idempKey,
        $testUserId
    );

    if (!$res1['success']) {
        return "Initial offline transaction insertion failed.";
    }

    $balAfterSync1 = KhataService::getCustomerBalance($customer1Id, $testBizId);

    // Simulate online synchronization retry of the same offline record
    $res2 = KhataService::recordEntry(
        $testBizId,
        $customer1Id,
        'adjustment',
        250.00,
        0.0,
        date('Y-m-d'),
        'manual',
        null,
        'OFFLINE-ADJ-01',
        'Offline repair diagnostic debit',
        'Simulated offline device transaction',
        null,
        $idempKey,
        $testUserId
    );

    if (empty($res2['already_recorded'])) {
        return "Sync retry was not deduplicated by idempotency key!";
    }

    $balAfterSync2 = KhataService::getCustomerBalance($customer1Id, $testBizId);
    if ((float)$balAfterSync1 !== (float)$balAfterSync2) {
        return "Sync retry corrupted customer ledger balance!";
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 13: Customer Ledger Statement & Printable Statement View
// -------------------------------------------------------------------------
runTest(13, "Customer Ledger Statement & Printable View Rendering", function() use ($testBizId, $customer2Id) {
    // 1. Service Level Statement
    $st = KhataService::getStatement($customer2Id, $testBizId);
    if (empty($st['customer']['name']) || !isset($st['total_gave']) || !isset($st['total_got']) || !isset($st['current_balance'])) {
        return "Statement structure missing expected metrics: " . json_encode(array_keys($st));
    }

    // 2. HTTP/CLI Execution of customer-ledger-print.php
    $_GET['id'] = $customer2Id;
    $_SESSION['user_id'] = Auth::id() ?? 1;
    $_SESSION['business_id'] = $testBizId;

    ob_start();
    include __DIR__ . '/../customer-ledger-print.php';
    $output = ob_get_clean();

    if (!str_contains($output, 'Customer Ledger Statement') || !str_contains($output, 'You Gave (آپ نے دیے)') || !str_contains($output, 'You Got (آپ کو ملے)')) {
        return "Printable ledger HTML output is missing bilingual header or Khata terms!";
    }

    return true;
});

// -------------------------------------------------------------------------
// Final Results
// -------------------------------------------------------------------------
echo "\n====================================================================\n";
echo " STAGE 3 CUSTOMER KHATA & PAYMENTS TEST RESULTS\n";
echo " Passed: {$passedCount} / {$totalTests}\n";
if (!empty($failures)) {
    echo " FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
}
echo "====================================================================\n";

if ($passedCount === $totalTests) {
    echo ">>> ALL 13 STAGE 3 CUSTOMER KHATA TESTS PASSED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
