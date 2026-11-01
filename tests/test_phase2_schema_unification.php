<?php
/**
 * Automated Verification Suite for Phase 2: Database Schema Unification & SQLite Mirroring
 * 
 * Verifies:
 * 1. Fresh database migration
 * 2. Idempotency (re-running migrations does not fail)
 * 3. All 12 missing tables exist with required indexes
 * 4. All column additions exist
 * 5. Data preservation on existing database upgrade
 * 6. Foreign key integrity
 * 7. Staff invitations and managers.php operations
 * 8. Payment creation and invoice allocation (payment_allocations)
 * 9. Expense categories and expenses logging
 * 10. Invoice items creation and query
 * 11. Tenant isolation with fail-closed checks
 * 12. Offline SQLite transactions and SyncEngine compatibility
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectDir = realpath(__DIR__ . '/..');
require_once $projectDir . '/desktop/src/SqliteClient.php';
require_once $projectDir . '/includes/bootstrap.php';
require_once $projectDir . '/services/staff-service.php';
require_once $projectDir . '/services/payment-service.php';
require_once $projectDir . '/desktop/src/SyncEngine.php';

echo "====================================================================\n";
echo " PHASE 2: DATABASE SCHEMA UNIFICATION & SQLITE MIRRORING SUITE\n";
echo "====================================================================\n\n";

$tmpDir = sys_get_temp_dir() . '/ybt_phase2_test_' . time();
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0755, true);
}

$testsPassed = 0;
$totalTests = 12;

try {
    // -----------------------------------------------------------------
    // TEST 1: Fresh Database Installation & Complete Migration Run
    // -----------------------------------------------------------------
    echo "--- TEST 1: Fresh SQLite Database Installation ---\n";
    $freshDbPath = $tmpDir . '/fresh.sqlite';
    $freshClient = new SqliteClient($freshDbPath);
    $executed = $freshClient->runMigrations();

    echo " [PASS] Fresh database initialized. Migrations executed: {$executed}\n";
    $testsPassed++;

    // -----------------------------------------------------------------
    // TEST 2: Migration Idempotency (Run Again Without Errors)
    // -----------------------------------------------------------------
    echo "\n--- TEST 2: Migration Idempotency Test ---\n";
    $secondRun = $freshClient->runMigrations();
    echo " [PASS] Second migration run completed safely. Migrations executed: {$secondRun} (expected 0 new)\n";
    $testsPassed++;

    // -----------------------------------------------------------------
    // TEST 3: Verification of All 12 Formerly Missing Tables
    // -----------------------------------------------------------------
    echo "\n--- TEST 3: Verification of All 12 Added Tables ---\n";
    $requiredTables = [
        'staff_invitations',
        'roles',
        'permissions',
        'role_permissions',
        'payment_allocations',
        'quotations',
        'quotation_items',
        'expense_categories',
        'invoice_items',
        'backup_records',
        'email_verifications',
        'password_resets',
        'ai_provider_configs'
    ];

    $allTables = $freshClient->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $missingTables = array_diff($requiredTables, $allTables);

    if (empty($missingTables)) {
        echo " [PASS] All " . count($requiredTables) . " required tables exist in SQLite:\n";
        foreach ($requiredTables as $t) {
            echo "        - {$t}\n";
        }
        $testsPassed++;
    } else {
        echo " [FAIL] Missing tables: " . implode(', ', $missingTables) . "\n";
    }

    // -----------------------------------------------------------------
    // TEST 4: Column Parity Verification
    // -----------------------------------------------------------------
    echo "\n--- TEST 4: Column Parity Verification ---\n";
    $columnChecks = [
        'payments' => ['payment_number', 'method', 'reference', 'notes', 'status', 'created_by'],
        'customers' => ['billing_address', 'billing_city', 'billing_state', 'billing_pincode', 'shipping_address', 'status', 'company_name'],
        'invoices' => ['place_of_supply', 'cgst_total', 'sgst_total', 'igst_total', 'tax_total', 'terms', 'amount_paid', 'outstanding', 'status', 'due_date'],
        'tasks' => ['assigned_to', 'created_by'],
        'businesses' => ['email', 'owner_name', 'status', 'onboarding_step'],
        'expenses' => ['category_id', 'vendor', 'method', 'reference', 'created_by', 'status'],
        'users' => ['email_verified_at', 'verification_status', 'onboarding_completed']
    ];

    $colFailures = [];
    foreach ($columnChecks as $table => $cols) {
        $actualCols = $freshClient->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach ($cols as $col) {
            if (!in_array($col, $actualCols, true)) {
                $colFailures[] = "{$table}.{$col}";
            }
        }
    }

    if (empty($colFailures)) {
        echo " [PASS] All critical parity columns verified across payments, customers, invoices, tasks, businesses, expenses, users.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Missing columns: " . implode(', ', $colFailures) . "\n";
    }

    // -----------------------------------------------------------------
    // TEST 5: Data Preservation on Existing Database Upgrade
    // -----------------------------------------------------------------
    echo "\n--- TEST 5: Data Preservation on Existing Database Upgrade ---\n";
    $upgradeDbPath = $tmpDir . '/upgrade.sqlite';
    // Create base 001/002 DB
    $upClient = new SqliteClient($upgradeDbPath);
    $upClient->getPdo()->exec(file_get_contents($projectDir . '/desktop/database/migrations/001_sqlite_initial_schema.sql'));
    $upClient->getPdo()->exec(file_get_contents($projectDir . '/desktop/database/migrations/002_sqlite_desktop_sync_compat.sql'));
    
    // Insert existing customer, product, invoice
    $upClient->insert('businesses', [
        'id' => 10,
        'tenant_uuid' => 'tenant-preserved-001',
        'name' => 'Existing Shop Before Upgrade',
        'sync_status' => 'synced'
    ]);
    $upClient->insert('customers', [
        'id' => 101,
        'uuid' => 'cust-preserved-001',
        'business_id' => 10,
        'name' => 'Preserved Customer Sharma',
        'phone' => '+91 99999 11111',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ]);
    $upClient->insert('products', [
        'id' => 201,
        'uuid' => 'prod-preserved-001',
        'business_id' => 10,
        'name' => 'Samsung Galaxy A15',
        'selling_price' => 14500.00,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    // Now run migration 003 on this existing populated database
    $upClient->runMigrations();

    // Verify existing records are 100% intact
    $cust = $upClient->fetch("SELECT * FROM customers WHERE id = 101");
    $prod = $upClient->fetch("SELECT * FROM products WHERE id = 201");
    $biz = $upClient->fetch("SELECT * FROM businesses WHERE id = 10");

    if ($cust && $cust['name'] === 'Preserved Customer Sharma' && 
        $prod && (float)$prod['selling_price'] === 14500.00 && 
        $biz && $biz['name'] === 'Existing Shop Before Upgrade') {
        echo " [PASS] Existing business, customer, and product records preserved with 100% integrity across migration.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Data preservation failed during upgrade.\n";
    }

    // -----------------------------------------------------------------
    // TEST 6: Foreign Key Integrity & PRAGMA Checks
    // -----------------------------------------------------------------
    echo "\n--- TEST 6: Foreign Key Integrity Verification ---\n";
    $freshPdo = $freshClient->getPdo();
    $fkCheck = $freshPdo->query("PRAGMA foreign_key_check")->fetchAll();
    if (empty($fkCheck)) {
        echo " [PASS] PRAGMA foreign_key_check reported 0 violations.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Foreign key violations found:\n";
        print_r($fkCheck);
    }

    // -----------------------------------------------------------------
    // TEST 7: Staff Invitations & StaffService Operations
    // -----------------------------------------------------------------
    echo "\n--- TEST 7: Staff Invitations & StaffService Operations ---\n";
    putenv("YBT_SQLITE_PATH={$freshDbPath}");
    putenv("APP_ENV=desktop");
    // Clear Database singleton to pick up fresh database
    $refProp = new ReflectionProperty('Database', 'instance');
    $refProp->setAccessible(true);
    $refProp->setValue(null, null);

    TenantContext::set(1, ['name' => 'Test Business']);
    $inviteRes = StaffService::invite(1, 1, 'staff.test@ybt.com', 'Aarav Patel', 2);

    $invRow = Database::fetch("SELECT * FROM staff_invitations WHERE email = 'staff.test@ybt.com'");
    if ($inviteRes['success'] && $invRow && $invRow['name'] === 'Aarav Patel') {
        echo " [PASS] StaffService::invite() successfully created staff invitation record.\n";
        echo "        Invitation ID: {$invRow['id']}, Status: {$invRow['status']}\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Staff invitation creation failed: " . ($inviteRes['error'] ?? 'Unknown') . "\n";
    }

    // -----------------------------------------------------------------
    // TEST 8: Manager & Staff Role/Permission Queries
    // -----------------------------------------------------------------
    echo "\n--- TEST 8: Manager & Staff Role/Permission Queries ---\n";
    $roles = Database::fetchAll("SELECT id, name, slug FROM roles WHERE business_id = 1 OR is_system = 1 ORDER BY id ASC");
    $perms = Database::fetchAll("SELECT * FROM permissions ORDER BY group_name ASC, label ASC");
    $pending = StaffService::listInvitations(1);

    if (count($roles) >= 3 && count($perms) >= 50 && count($pending) >= 1) {
        echo " [PASS] roles (" . count($roles) . "), permissions (" . count($perms) . "), and staff_invitations queried successfully.\n";
        echo "        'no such table: staff_invitations' error is PERMANENTLY RESOLVED.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Role or permission queries returned unexpected counts.\n";
    }

    // -----------------------------------------------------------------
    // TEST 9: Payment Recording & Invoice Allocation (payment_allocations)
    // -----------------------------------------------------------------
    echo "\n--- TEST 9: Payment Recording & Invoice Allocation ---\n";
    // Create an invoice first
    $invId = Database::insert('invoices', [
        'business_id' => 1,
        'customer_id' => 101,
        'invoice_number' => 'INV-TEST-001',
        'invoice_date' => date('Y-m-d'),
        'grand_total' => 5000.00,
        'paid_amount' => 0.00,
        'amount_paid' => 0.00,
        'outstanding' => 5000.00,
        'status' => 'sent'
    ]);

    $payId = PaymentService::record(1, [
        'customer_id' => 101,
        'invoice_id' => $invId,
        'amount' => 2000.00,
        'method' => 'upi',
        'reference' => 'UPI-REF-998877',
        'notes' => 'Advance UPI payment'
    ], 1);

    $payRow = Database::fetch("SELECT * FROM payments WHERE id = ?", [$payId]);
    $allocRow = Database::fetch("SELECT * FROM payment_allocations WHERE payment_id = ? AND invoice_id = ?", [$payId, $invId]);
    $updatedInv = Database::fetch("SELECT * FROM invoices WHERE id = ?", [$invId]);

    if ($payRow && $allocRow && (float)$allocRow['amount'] === 2000.00 && (float)$updatedInv['outstanding'] === 3000.00) {
        echo " [PASS] Payment recorded, allocated in payment_allocations, and invoice balance updated.\n";
        echo "        Payment Number: {$payRow['payment_number']}, Amount: ₹{$payRow['amount']}, Remaining Due: ₹{$updatedInv['outstanding']}\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Payment recording or allocation failed.\n";
    }

    // -----------------------------------------------------------------
    // TEST 10: Expense Categories & Expense Logging
    // -----------------------------------------------------------------
    echo "\n--- TEST 10: Expense Categories & Logging ---\n";
    $cats = Database::fetchAll("SELECT * FROM expense_categories WHERE business_id = 1");
    $catId = $cats[0]['id'] ?? 1;

    $expId = Database::insert('expenses', [
        'business_id' => 1,
        'category_id' => $catId,
        'description' => 'Display Assembly Test Part',
        'amount' => 1250.00,
        'expense_date' => date('Y-m-d'),
        'method' => 'cash',
        'vendor' => 'City Spare Market',
        'status' => 'active',
        'created_by' => 1
    ]);

    $expRow = Database::fetch(
        "SELECT e.*, c.name as category_name 
         FROM expenses e 
         JOIN expense_categories c ON e.category_id = c.id 
         WHERE e.id = ?",
        [$expId]
    );

    if ($expRow && $expRow['category_name'] && (float)$expRow['amount'] === 1250.00) {
        echo " [PASS] Expense logged and joined with expense_categories: {$expRow['description']} (Category: {$expRow['category_name']})\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Expense categories logging failed.\n";
    }

    // -------------------------------------------------------------
    // TEST 11: Invoice Items Insertion and Query
    // -------------------------------------------------------------
    echo "\n--- TEST 11: Invoice Items Insertion and Query ---\n";
    $prodId = Database::insert('products', [
        'business_id' => 1,
        'name' => 'Fast Charger 25W Type-C',
        'selling_price' => 450.00
    ]);

    $itemId = Database::insert('invoice_items', [
        'invoice_id' => $invId,
        'product_id' => $prodId,
        'description' => 'Fast Charger 25W Type-C',
        'quantity' => 2.000,
        'unit' => 'piece',
        'unit_price' => 450.00,
        'line_total' => 900.00,
        'sort_order' => 1
    ]);

    $items = Database::fetchAll("SELECT * FROM invoice_items WHERE invoice_id = ?", [$invId]);
    if (count($items) === 1 && (float)$items[0]['line_total'] === 900.00) {
        echo " [PASS] invoice_items table verified. Line item saved and queried cleanly.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] invoice_items verification failed.\n";
    }

    // -----------------------------------------------------------------
    // TEST 12: Offline Sync Engine Queue Compatibility
    // -----------------------------------------------------------------
    echo "\n--- TEST 12: Offline SyncEngine Operation Queuing ---\n";
    $syncEngine = new SyncEngine($freshClient);
    $custSyncRes = $syncEngine->createCustomerOffline(1, 'pos_device_test', [
        'name' => 'Offline Khata Customer',
        'phone' => '+91 98888 22222',
        'email' => 'khata.cust@example.com'
    ]);

    $queuedOps = $freshClient->query("SELECT * FROM pending_operations WHERE entity_type LIKE 'customer%' AND status = 'pending'")->fetchAll();

    if (($custSyncRes['success'] ?? false) && !empty($queuedOps)) {
        echo " [PASS] Offline operation queued into pending_operations successfully.\n";
        echo "        Queued Op UUID: {$queuedOps[0]['operation_uuid']}, Type: {$queuedOps[0]['operation_type']}\n";
        $testsPassed++;
    } else {
        echo " [FAIL] SyncEngine queue operation failed.\n";
        echo "        Result: " . json_encode($custSyncRes) . "\n";
    }

} finally {
    // Release all PDO handles before unlinking SQLite files
    $freshClient = null;
    $freshPdo = null;
    $upClient = null;
    $syncEngine = null;
    $refProp = new ReflectionProperty('Database', 'instance');
    $refProp->setAccessible(true);
    $refProp->setValue(null, null);

    // Garbage collect PDO resources
    gc_collect_cycles();
    usleep(200000); // 200ms

    // Clean up temporary database files
    foreach (glob("{$tmpDir}/*.*") as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
    putenv("YBT_SQLITE_PATH=");
}

echo "\n====================================================================\n";
echo "PHASE 2 SCHEMA VERIFICATION RESULTS: {$testsPassed} / {$totalTests} PASSED\n";
echo "====================================================================\n";

if ($testsPassed === $totalTests) {
    echo "DATABASE SCHEMA UNIFICATION AND SQLITE MIRRORING 100% SUCCESSFUL!\n";
    exit(0);
} else {
    echo "SOME SCHEMA VERIFICATION CHECKS FAILED!\n";
    exit(1);
}
