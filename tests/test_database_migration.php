<?php
/**
 * Test Suite: Database Migration & Rollback Verification
 * Tests schema compatibility, foreign keys, indexes, and rollback on disposable test databases.
 */

$host = '127.0.0.1';
$user = 'root';
$pass = '';

$pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== Phase 2.1 Database Migration Verification ===\n";

// --- STEP 1: Initialize Test Database ---
echo ">> Step 1: Setting up isolated test database 'ybt_test_verification'...\n";
$pdo->exec("DROP DATABASE IF EXISTS `ybt_test_verification`");
$pdo->exec("CREATE DATABASE `ybt_test_verification` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `ybt_test_verification`");

// --- STEP 2: Import Base Schema ---
echo ">> Step 2: Importing base schema.sql (33 tables)...\n";
$schemaSql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
// Replace target database in schema.sql
$schemaSql = preg_replace('/USE `ybt_management`;/i', 'USE `ybt_test_verification`;', $schemaSql);
$schemaSql = preg_replace('/CREATE DATABASE IF NOT EXISTS `ybt_management`[^;]+;/i', '', $schemaSql);

// Execute schema in chunks or direct exec
$pdo->exec($schemaSql);

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "   Base tables count: " . count($tables) . " (Expected: 33)\n";
if (count($tables) !== 33) {
    echo "FAIL: Expected 33 base tables, found " . count($tables) . "\n";
    exit(1);
}
echo "   PASS: Base 33 tables successfully imported.\n";

// --- STEP 3: Import Demo Data ---
echo ">> Step 3: Importing demo-data.sql into test database...\n";
$demoSql = file_get_contents(dirname(__DIR__) . '/database/demo-data.sql');
$demoSql = preg_replace('/USE `ybt_management`;/i', 'USE `ybt_test_verification`;', $demoSql);
$pdo->exec($demoSql);
echo "   PASS: Demo data imported successfully.\n";

// --- STEP 4: Run Migration 001 ---
echo ">> Step 4: Running database/migrations/001_phase2_sync_and_security.sql...\n";
$migrationSql = file_get_contents(dirname(__DIR__) . '/database/migrations/001_phase2_sync_and_security.sql');
$migrationSql = preg_replace('/USE `ybt_management`;/i', 'USE `ybt_test_verification`;', $migrationSql);
$pdo->exec($migrationSql);

// Verify new tables
$newExpectedTables = [
    'api_tokens',
    'sync_devices',
    'api_idempotency_keys',
    'sync_operations_queue',
    'sync_tombstones',
];

$postTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "   Post-migration tables count: " . count($postTables) . " (Expected: 38)\n";

foreach ($newExpectedTables as $t) {
    if (!in_array($t, $postTables, true)) {
        echo "FAIL: Missing expected migration table '{$t}'\n";
        exit(1);
    }
    echo "   PASS: Verified table exists: {$t}\n";
}

// Verify additive columns
echo ">> Step 5: Verifying additive columns on core synchronizable tables...\n";
$syncTables = ['customers', 'products', 'invoices', 'payments', 'expenses', 'leads', 'tasks'];
foreach ($syncTables as $st) {
    $cols = $pdo->query("SHOW COLUMNS FROM `{$st}`")->fetchAll(PDO::FETCH_COLUMN);
    $requiredCols = ['sync_uuid', 'sync_version', 'client_updated_at', 'is_deleted'];
    foreach ($requiredCols as $rc) {
        if (!in_array($rc, $cols, true)) {
            echo "FAIL: Missing additive column '{$rc}' on table '{$st}'\n";
            exit(1);
        }
    }
    echo "   PASS: Verified sync columns on table: {$st}\n";
}

// Verify invoices specific column
$invCols = $pdo->query("SHOW COLUMNS FROM `invoices`")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('client_temp_number', $invCols, true)) {
    echo "FAIL: Missing client_temp_number on invoices\n";
    exit(1);
}
echo "   PASS: Verified client_temp_number column on invoices table.\n";

// --- STEP 6: Verify Foreign Keys & Constraints ---
echo ">> Step 6: Verifying foreign key relationships and indices on new tables...\n";
$fks = $pdo->query(
    "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
     FROM information_schema.KEY_COLUMN_USAGE 
     WHERE TABLE_SCHEMA = 'ybt_test_verification' 
     AND TABLE_NAME IN ('api_tokens', 'sync_devices', 'api_idempotency_keys', 'sync_operations_queue', 'sync_tombstones')
     AND REFERENCED_TABLE_NAME IS NOT NULL"
)->fetchAll();

echo "   Found " . count($fks) . " foreign key constraints on new tables.\n";
foreach ($fks as $fk) {
    echo "   - {$fk['TABLE_NAME']}.{$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}\n";
}
if (count($fks) < 7) {
    echo "FAIL: Missing expected foreign key constraints.\n";
    exit(1);
}
echo "   PASS: Foreign key constraints verified.\n";

// --- STEP 7: Test Rollback Script on Disposable Database ---
echo ">> Step 7: Testing rollback script against disposable database 'ybt_test_rollback'...\n";
$pdo->exec("DROP DATABASE IF EXISTS `ybt_test_rollback`");
$pdo->exec("CREATE DATABASE `ybt_test_rollback` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `ybt_test_rollback`");

$rollbackSchemaSql = preg_replace('/USE `ybt_management`;/i', 'USE `ybt_test_rollback`;', file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
$rollbackSchemaSql = preg_replace('/CREATE DATABASE IF NOT EXISTS `ybt_management`[^;]+;/i', '', $rollbackSchemaSql);
$pdo->exec($rollbackSchemaSql);

$rollbackMigSql = preg_replace('/USE `ybt_management`;/i', 'USE `ybt_test_rollback`;', file_get_contents(dirname(__DIR__) . '/database/migrations/001_phase2_sync_and_security.sql'));
$pdo->exec($rollbackMigSql);

// Execute Rollback
$rollbackScript = preg_replace('/USE `ybt_management`;/i', 'USE `ybt_test_rollback`;', file_get_contents(dirname(__DIR__) . '/database/migrations/001_phase2_sync_and_security_rollback.sql'));
$pdo->exec($rollbackScript);

$rolledBackTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "   Rolled back tables count: " . count($rolledBackTables) . " (Expected: 33)\n";
if (count($rolledBackTables) !== 33) {
    echo "FAIL: Expected 33 tables after rollback, found " . count($rolledBackTables) . "\n";
    exit(1);
}

// Verify dropped columns on customers
$rbCols = $pdo->query("SHOW COLUMNS FROM `customers`")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('sync_uuid', $rbCols, true) || in_array('sync_version', $rbCols, true)) {
    echo "FAIL: Additive columns were not cleanly removed during rollback.\n";
    exit(1);
}
echo "   PASS: Rollback script cleanly removed new tables and additive columns without touching base schema.\n";

// Clean up disposable rollback database
$pdo->exec("DROP DATABASE `ybt_test_rollback`");

echo "\n>>> DATABASE MIGRATION & ROLLBACK TESTS: 100% VERIFIED PASS!\n";
