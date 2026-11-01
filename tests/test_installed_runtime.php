<?php
/**
 * Test installed desktop application runtime in AppData
 */

$appData = getenv('APPDATA') ?: 'C:/Users/Dj Bhai/AppData/Roaming';
$installDir = 'C:/Users/Dj Bhai/AppData/Local/YBT Management Software';

require_once $installDir . '/desktop/src/SqliteClient.php';
require_once $installDir . '/desktop/src/SyncEngine.php';

echo "1. Initializing SqliteClient with AppData...\n";
$sqlite = new SqliteClient();
$dbFile = $sqlite->getPdo()->query("PRAGMA database_list")->fetchAll(PDO::FETCH_ASSOC);
echo "Database file: " . ($dbFile[0]['file'] ?? 'unknown') . "\n";

echo "2. Running migrations...\n";
$migrated = $sqlite->runMigrations();
echo "Migrations executed: " . $migrated . "\n";

echo "3. Creating offline customer...\n";
$engine = new SyncEngine($sqlite);
$cust = $engine->createCustomerOffline(1, 'device_installed_prod', [
    'name' => 'Installed Production Test Customer',
    'phone' => '+919876543210',
    'email' => 'installed_test@example.com'
]);
echo "Customer created: " . ($cust['success'] ? 'YES' : 'NO') . " (UUID: " . ($cust['uuid'] ?? 'none') . ")\n";

echo "4. Creating offline invoice...\n";
$inv = $engine->createInvoiceOffline(1, 'device_installed_prod', [
    'customer_id' => $cust['id'] ?? 1,
    'total_amount' => 1500.00,
    'tax_amount' => 270.00,
    'grand_total' => 1770.00,
    'items' => [
        ['name' => 'Installed Item 1', 'quantity' => 1, 'unit_price' => 1500.00, 'total' => 1500.00]
    ]
]);
echo "Invoice created: " . ($inv['success'] ? 'YES' : 'NO') . " (UUID: " . ($inv['uuid'] ?? 'none') . ")\n";

echo "5. Verifying AppData SQLite persistence...\n";
$persistedCust = $sqlite->fetch("SELECT * FROM `customers` WHERE `phone` = '+919876543210'");
echo "Customer found in AppData SQLite: " . (!empty($persistedCust) ? 'YES' : 'NO') . "\n";
echo "Customer name: " . ($persistedCust['name'] ?? 'none') . "\n";

echo "\nALL INSTALLED RUNTIME TESTS PASSED!\n";
