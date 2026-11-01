<?php
declare(strict_types=1);

/**
 * YBT Management Software — Stage 2: Mobile Parts Inventory & Stock Automation Engine
 * Comprehensive Verification Test Suite
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../services/stock-service.php';
require_once __DIR__ . '/../services/invoice-service.php';

$passedCount = 0;
$totalTests = 11;
$failures = [];

function runTest(int $num, string $title, callable $fn): void {
    global $passedCount, $failures;
    echo sprintf("\n--- [STAGE 2 - TEST %02d/11] %s ---\n", $num, $title);
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
echo "   YBT MANAGEMENT SOFTWARE — STAGE 2 INVENTORY & STOCK AUTOMATION   \n";
echo "====================================================================\n";

// Shared Fixture Setup for Test Run
$testBizId = Database::insert('businesses', [
    'tenant_uuid' => generateUuidV4(),
    'name' => 'Apex Mobile Parts Test Lab',
    'country' => 'PK',
    'default_currency' => 'PKR',
    'status' => 'active'
]);

$testBizId2 = Database::insert('businesses', [
    'tenant_uuid' => generateUuidV4(),
    'name' => 'Second Mobile Shop (Isolation Test)',
    'country' => 'PK',
    'default_currency' => 'PKR',
    'status' => 'active'
]);

$testUserId = Database::insert('users', [
    'business_id' => $testBizId,
    'name' => 'Tariq Shopkeeper',
    'email' => 'tariq_inventory_' . uniqid() . '@test.pk',
    'password' => password_hash('Pass#2026', PASSWORD_DEFAULT),
    'is_owner' => 1,
    'is_active' => 1
]);

$testCustomerId = Database::insert('customers', [
    'business_id' => $testBizId,
    'name' => 'Ali Customer',
    'phone' => '+923007654321',
    'status' => 'active',
    'created_at' => date('Y-m-d H:i:s')
]);

// Track IDs for test operations
$v20ProductId = 0;
$invoice1Id = 0;

// -------------------------------------------------------------------------
// TEST 1: Initial Mobile Part Creation with 5 V20 Display Panels
// -------------------------------------------------------------------------
runTest(1, "Create Part: 5 Vivo V20 Display Panels & Initial Stock Movement", function() use ($testBizId, $testUserId, &$v20ProductId) {
    $sku = 'DISP-VIVO-V20-' . rand(1000, 9999);
    $initialStock = 5.0;
    
    $v20ProductId = Database::insert('products', [
        'business_id' => $testBizId,
        'name' => 'Vivo V20 AMOLED Display Panel Original',
        'sku' => $sku,
        'brand' => 'Vivo',
        'model' => 'V20 (V2025)',
        'part_type' => 'Display / Touch Screen',
        'supplier_name' => 'Karachi Wholesale Display Hub',
        'purchase_price' => 4500.00,
        'selling_price' => 6500.00,
        'stock_quantity' => $initialStock,
        'min_stock_alert' => 3,
        'allow_negative_stock' => 0,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // Record initial stock creation movement
    StockService::recordMovement(
        $testBizId,
        $v20ProductId,
        'initial',
        $initialStock,
        0.0,
        $initialStock,
        'initial',
        null,
        null,
        'Initial inventory count during catalog addition',
        $testUserId
    );

    // Verify stock quantity
    $prod = Database::fetch("SELECT stock_quantity, allow_negative_stock FROM products WHERE id = ?", [$v20ProductId]);
    if (!$prod || (float)$prod['stock_quantity'] !== 5.0) {
        return "Product stock is not 5.0; found: " . ($prod['stock_quantity'] ?? 'null');
    }

    // Verify movement entry
    $movements = StockService::getMovements($testBizId, $v20ProductId);
    if (count($movements) !== 1) {
        return "Expected exactly 1 movement record, found: " . count($movements);
    }
    if ($movements[0]['type'] !== 'initial' || (float)$movements[0]['quantity_after'] !== 5.0) {
        return "Movement record type or quantity_after incorrect.";
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 2: Customer Sale Invoice Creation Selling 2 Units (5 -> 3)
// -------------------------------------------------------------------------
runTest(2, "Confirm Sale Invoice Selling 2 Units: Stock Decreases from 5 to 3", function() use ($testBizId, $testCustomerId, $testUserId, &$v20ProductId, &$invoice1Id) {
    // Generate invoice selling 2 V20 Display Panels
    $invoiceData = [
        'customer_id' => $testCustomerId,
        'invoice_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+7 days')),
        'status' => 'unpaid', // Non-draft status triggers stock deduction
        'notes' => 'Customer repair screen replacement'
    ];

    $items = [
        [
            'product_id' => $v20ProductId,
            'name' => 'Vivo V20 AMOLED Display Panel Original',
            'description' => 'Display repair unit',
            'quantity' => 2,
            'unit_price' => 6500.00,
            'discount_amount' => 0,
            'tax_rate' => 0
        ]
    ];

    $invoice1Id = InvoiceService::create($testBizId, $invoiceData, $items, $testUserId);
    if (!$invoice1Id) {
        return "Invoice creation failed.";
    }

    // Check product stock: must be 3
    $prod = Database::fetch("SELECT stock_quantity FROM products WHERE id = ?", [$v20ProductId]);
    if ((float)$prod['stock_quantity'] !== 3.0) {
        return "Expected stock quantity 3.0 after selling 2 units, found: " . ($prod['stock_quantity'] ?? 'null');
    }

    // Check movement trail
    $movements = StockService::getMovements($testBizId, $v20ProductId, 2);
    if ($movements[0]['type'] !== 'sale' || (float)$movements[0]['quantity'] !== -2.0) {
        return "Latest movement record is not sale of -2 units: " . json_encode($movements[0]);
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 3: Verify Invoice Items & Deducted State
// -------------------------------------------------------------------------
runTest(3, "Verify Customer Invoice Contains 2 Units and stock_deducted = 1", function() use ($invoice1Id) {
    $inv = Database::fetch("SELECT id, invoice_number, stock_deducted, stock_restored FROM invoices WHERE id = ?", [$invoice1Id]);
    if (!$inv) {
        return "Invoice #{$invoice1Id} not found.";
    }
    if ((int)$inv['stock_deducted'] !== 1) {
        return "Invoice stock_deducted flag is not 1; value is: " . $inv['stock_deducted'];
    }
    if (!empty($inv['stock_restored'])) {
        return "Invoice stock_restored flag should be 0 or null before cancellation.";
    }

    $items = Database::fetchAll("SELECT * FROM invoice_items WHERE invoice_id = ?", [$invoice1Id]);
    if (count($items) !== 1) {
        return "Expected 1 invoice line item, found: " . count($items);
    }
    if ((float)$items[0]['quantity'] !== 2.0) {
        return "Expected item quantity 2.0, found: " . $items[0]['quantity'];
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 4: Idempotent Stock Deduction (Double Deduction Prevention)
// -------------------------------------------------------------------------
runTest(4, "Idempotent Stock Deduction: Re-triggering deductForInvoice Does Not Double-Deduct", function() use ($testBizId, $invoice1Id, &$v20ProductId) {
    // Attempt to invoke deductForInvoice a second time on the same invoice
    $res = StockService::deductForInvoice($testBizId, $invoice1Id);
    
    if (empty($res['already_deducted'])) {
        return "Expected already_deducted=true, received: " . json_encode($res);
    }

    // Stock must stay strictly at 3
    $prod = Database::fetch("SELECT stock_quantity FROM products WHERE id = ?", [$v20ProductId]);
    if ((float)$prod['stock_quantity'] !== 3.0) {
        return "Stock corrupted by duplicate deduction! Expected 3.0, found: " . $prod['stock_quantity'];
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 5: Cancel Invoice Restores Stock to 5
// -------------------------------------------------------------------------
runTest(5, "Cancel Invoice Restores Stock to 5 and Logs cancellation_restore Movement", function() use ($invoice1Id, $testBizId, $testUserId, &$v20ProductId) {
    $cancelRes = InvoiceService::cancel($invoice1Id, $testBizId, $testUserId);
    if (!$cancelRes['success']) {
        return "Invoice cancellation failed: " . json_encode($cancelRes);
    }

    // Product stock must now be restored to 5.0
    $prod = Database::fetch("SELECT stock_quantity FROM products WHERE id = ?", [$v20ProductId]);
    if ((float)$prod['stock_quantity'] !== 5.0) {
        return "Expected stock restored to 5.0, found: " . ($prod['stock_quantity'] ?? 'null');
    }

    // Invoice status must be 'cancelled' and stock_restored = 1
    $inv = Database::fetch("SELECT status, stock_restored FROM invoices WHERE id = ?", [$invoice1Id]);
    if ($inv['status'] !== 'cancelled' || (int)$inv['stock_restored'] !== 1) {
        return "Invoice status or stock_restored incorrect: " . json_encode($inv);
    }

    // Movement must show cancellation_restore of +2
    $movements = StockService::getMovements($testBizId, $v20ProductId, 1);
    if ($movements[0]['type'] !== 'cancellation_restore' || (float)$movements[0]['quantity'] !== 2.0) {
        return "Latest movement is not cancellation_restore of +2: " . json_encode($movements[0]);
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 6: Idempotent Stock Restoration (Duplicate Cancel Prevention)
// -------------------------------------------------------------------------
runTest(6, "Idempotent Stock Restoration: Re-triggering Cancel Does Not Restore Again", function() use ($invoice1Id, $testBizId, $testUserId, &$v20ProductId) {
    $secondCancel = InvoiceService::cancel($invoice1Id, $testBizId, $testUserId);
    
    if (empty($secondCancel['already_restored'])) {
        return "Expected already_restored=true on second cancel, received: " . json_encode($secondCancel);
    }

    // Stock must stay exactly at 5.0
    $prod = Database::fetch("SELECT stock_quantity FROM products WHERE id = ?", [$v20ProductId]);
    if ((float)$prod['stock_quantity'] !== 5.0) {
        return "Stock corrupted by duplicate restore! Expected 5.0, found: " . $prod['stock_quantity'];
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 7: Strict Insufficient Stock / Oversell Rejection
// -------------------------------------------------------------------------
runTest(7, "Strict Insufficient Stock Rejection: Attempting to Sell 10 Units Fails & Preserves Stock", function() use ($testBizId, $testCustomerId, $testUserId, &$v20ProductId) {
    $oversellData = [
        'customer_id' => $testCustomerId,
        'invoice_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+7 days')),
        'status' => 'unpaid'
    ];
    $oversellItems = [
        [
            'product_id' => $v20ProductId,
            'name' => 'Vivo V20 AMOLED Display Panel Original',
            'description' => 'Bulk sale attempt',
            'quantity' => 10, // Stock is only 5!
            'unit_price' => 6500.00
        ]
    ];

    $exceptionCaught = false;
    try {
        InvoiceService::create($testBizId, $oversellData, $oversellItems, $testUserId);
    } catch (InsufficientStockException $e) {
        $exceptionCaught = true;
    } catch (\Throwable $t) {
        return "Expected InsufficientStockException, got " . get_class($t) . ": " . $t->getMessage();
    }

    if (!$exceptionCaught) {
        return "System allowed selling 10 units when only 5 were in stock!";
    }

    // Verify stock is still intact at 5.0
    $prod = Database::fetch("SELECT stock_quantity FROM products WHERE id = ?", [$v20ProductId]);
    if ((float)$prod['stock_quantity'] !== 5.0) {
        return "Stock was altered despite oversell exception! Found: " . $prod['stock_quantity'];
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 8: Tenant Stock Isolation
// -------------------------------------------------------------------------
runTest(8, "Tenant Stock Isolation: Shop A Stock Adjustments Do Not Affect Shop B", function() use ($testBizId, $testBizId2, &$v20ProductId) {
    // Create product in Shop B with identical SKU
    $skuB = 'DISP-VIVO-V20-SHOPB';
    $prodBId = Database::insert('products', [
        'business_id' => $testBizId2,
        'name' => 'Vivo V20 Screen (Shop B)',
        'sku' => $skuB,
        'brand' => 'Vivo',
        'model' => 'V20',
        'part_type' => 'Display / Touch Screen',
        'stock_quantity' => 20.0,
        'min_stock_alert' => 5,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // Attempt to adjust Shop B's product using Shop A's business_id -> should fail
    $isolationCrossAdjustBlocked = false;
    try {
        StockService::adjustStock($testBizId, $prodBId, -5);
    } catch (InvalidArgumentException $e) {
        $isolationCrossAdjustBlocked = true;
    }

    if (!$isolationCrossAdjustBlocked) {
        return "Shop A was able to alter Shop B's product stock!";
    }

    // Verify Shop B's stock remains exactly 20.0
    $prodB = Database::fetch("SELECT stock_quantity FROM products WHERE id = ?", [$prodBId]);
    if ((float)$prodB['stock_quantity'] !== 20.0) {
        return "Shop B stock was altered! Found: " . $prodB['stock_quantity'];
    }

    // Verify Shop A's stock is still 5.0
    $prodA = Database::fetch("SELECT stock_quantity FROM products WHERE id = ?", [$v20ProductId]);
    if ((float)$prodA['stock_quantity'] !== 5.0) {
        return "Shop A stock was altered! Found: " . $prodA['stock_quantity'];
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 9: Purchase Restock (+4 Units: 5 -> 9)
// -------------------------------------------------------------------------
runTest(9, "Purchase Restock: Adding 4 Units via recordPurchase Increases Stock to 9", function() use ($testBizId, $testUserId, &$v20ProductId) {
    $restockRes = StockService::recordPurchase(
        $testBizId,
        $v20ProductId,
        4.0,
        4600.00,
        'Lahore Parts Plaza',
        'PO-2026-089',
        $testUserId
    );

    if (!$restockRes['success']) {
        return "recordPurchase returned failure: " . json_encode($restockRes);
    }

    // Verify stock is 9.0
    $prod = Database::fetch("SELECT stock_quantity, purchase_price, supplier_name FROM products WHERE id = ?", [$v20ProductId]);
    if ((float)$prod['stock_quantity'] !== 9.0) {
        return "Expected stock 9.0 after +4 restock, found: " . $prod['stock_quantity'];
    }
    if ((float)$prod['purchase_price'] !== 4600.00 || $prod['supplier_name'] !== 'Lahore Parts Plaza') {
        return "Purchase price or supplier not updated properly: " . json_encode($prod);
    }

    // Verify movement
    $movements = StockService::getMovements($testBizId, $v20ProductId, 1);
    if ($movements[0]['type'] !== 'purchase' || (float)$movements[0]['quantity'] !== 4.0 || (float)$movements[0]['quantity_after'] !== 9.0) {
        return "Purchase movement record mismatch: " . json_encode($movements[0]);
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 10: Supplier Return (-2 Units: 9 -> 7)
// -------------------------------------------------------------------------
runTest(10, "Supplier Return: Returning 2 Defective Units Decreases Stock to 7", function() use ($testBizId, $testUserId, &$v20ProductId) {
    $returnRes = StockService::recordSupplierReturn(
        $testBizId,
        $v20ProductId,
        2.0,
        'Touch digitizer dead zone on lower half',
        $testUserId
    );

    if (!$returnRes['success']) {
        return "recordSupplierReturn failed: " . json_encode($returnRes);
    }

    // Verify stock is 7.0
    $prod = Database::fetch("SELECT stock_quantity FROM products WHERE id = ?", [$v20ProductId]);
    if ((float)$prod['stock_quantity'] !== 7.0) {
        return "Expected stock 7.0 after supplier return of 2 units, found: " . $prod['stock_quantity'];
    }

    // Verify movement
    $movements = StockService::getMovements($testBizId, $v20ProductId, 1);
    if ($movements[0]['type'] !== 'return_supplier' || (float)$movements[0]['quantity'] !== -2.0) {
        return "Return movement record mismatch: " . json_encode($movements[0]);
    }

    return true;
});

// -------------------------------------------------------------------------
// TEST 11: Low Stock Alerts Engine
// -------------------------------------------------------------------------
runTest(11, "Low Stock Alerts Engine: Accurately Detects Items At or Below min_stock_alert", function() use ($testBizId) {
    // Current V20 product has stock 7 and min_stock_alert 3 (not low stock)
    $alerts1 = StockService::getLowStockAlerts($testBizId);
    foreach ($alerts1 as $a) {
        if ($a['name'] === 'Vivo V20 AMOLED Display Panel Original') {
            return "V20 (stock 7, min 3) should not appear in low stock alert list!";
        }
    }

    // Create low stock product (iPhone 13 battery, stock 2, min 5)
    $lowStockProdId = Database::insert('products', [
        'business_id' => $testBizId,
        'name' => 'iPhone 13 Battery OEM 3227mAh',
        'sku' => 'BAT-IPH-13-' . rand(100, 999),
        'brand' => 'Apple',
        'model' => 'iPhone 13',
        'part_type' => 'Battery',
        'stock_quantity' => 2.0,
        'min_stock_alert' => 5,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    $alerts2 = StockService::getLowStockAlerts($testBizId);
    $found = false;
    foreach ($alerts2 as $item) {
        if ($item['id'] == $lowStockProdId) {
            $found = true;
            if ((float)$item['stock_quantity'] !== 2.0 || (int)$item['min_stock_alert'] !== 5) {
                return "Low stock item values mismatch in alert payload.";
            }
        }
    }

    if (!$found) {
        return "iPhone 13 Battery (stock 2, min 5) was not returned in low stock alerts!";
    }

    return true;
});

// -------------------------------------------------------------------------
// Final Summary & Cleanup
// -------------------------------------------------------------------------
echo "\n====================================================================\n";
echo " STAGE 2 INVENTORY & STOCK AUTOMATION TEST RESULTS\n";
echo " Passed: {$passedCount} / {$totalTests}\n";
if (!empty($failures)) {
    echo " FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
}
echo "====================================================================\n";

if ($passedCount === $totalTests) {
    echo ">>> ALL 11 STAGE 2 INVENTORY & STOCK TESTS PASSED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
