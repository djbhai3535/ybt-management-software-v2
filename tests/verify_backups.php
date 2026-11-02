<?php
declare(strict_types=1);

$backups = [
    'AppData Timestamped Backup' => getenv('APPDATA') . '/YBT Management Software/storage/desktop/ybt_local_backup_20261102_083237.sqlite',
    'Project Backup Directory DB' => 'C:/CRM-Software_backup_recovery_pre_20261102/storage/desktop/ybt_local_active_database_backup.sqlite',
    'Stage 4 Pre-Backup DB' => 'C:/CRM-Software_backup_stage4_pre/storage/desktop/ybt_local.sqlite',
    'Active Original Database' => getenv('APPDATA') . '/YBT Management Software/storage/desktop/ybt_local.sqlite'
];

echo "====================================================================\n";
echo " DATABASE BACKUP INTEGRITY & RECORD VERIFICATION\n";
echo "====================================================================\n\n";

foreach ($backups as $label => $path) {
    echo "--- Checking: {$label} ---\n";
    echo "Path: {$path}\n";
    if (!file_exists($path)) {
        echo "[FAIL] File does not exist!\n\n";
        continue;
    }
    $size = filesize($path);
    echo "File Size: {$size} bytes (" . round($size / 1024, 2) . " KB)\n";

    try {
        $pdo = new PDO("sqlite:" . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // 1. PRAGMA integrity_check
        $integrity = $pdo->query("PRAGMA integrity_check")->fetchColumn();
        echo "SQLite Integrity Check: {$integrity}\n";
        if ($integrity !== 'ok') {
            echo "[FAIL] Integrity check failed!\n\n";
            continue;
        }

        // 2. Count tables
        $tables = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
        echo "Total Tables: {$tables}\n";

        // 3. Count critical entities
        $counts = [
            'businesses' => $pdo->query("SELECT COUNT(*) FROM businesses")->fetchColumn(),
            'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'customers' => $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
            'customer_ledger' => $pdo->query("SELECT COUNT(*) FROM customer_ledger")->fetchColumn(),
            'products' => $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
            'stock_movements' => $pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn(),
            'invoices' => $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn(),
            'payments' => $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn()
        ];

        foreach ($counts as $tbl => $cnt) {
            echo sprintf("  - %-18s: %d records\n", $tbl, (int)$cnt);
        }

        // 4. Sample record check
        $sampleCustomer = $pdo->query("SELECT id, name, phone, current_balance FROM customers ORDER BY id ASC LIMIT 1")->fetch();
        if ($sampleCustomer) {
            echo "  Sample Customer #{$sampleCustomer['id']}: {$sampleCustomer['name']} (Phone: {$sampleCustomer['phone']}, Bal: {$sampleCustomer['current_balance']})\n";
        }

        $sampleLedger = $pdo->query("SELECT id, customer_id, entry_type, debit, credit, running_balance FROM customer_ledger ORDER BY id DESC LIMIT 1")->fetch();
        if ($sampleLedger) {
            echo "  Latest Ledger #{$sampleLedger['id']}: Cust #{$sampleLedger['customer_id']}, Type: {$sampleLedger['entry_type']}, Deb: {$sampleLedger['debit']}, Cred: {$sampleLedger['credit']}, Bal: {$sampleLedger['running_balance']}\n";
        }

        echo "Status: [PASS] VALID & VERIFIED OPENED SUCCESSFULLY\n\n";
    } catch (Exception $e) {
        echo "[ERROR] Failed to query database: " . $e->getMessage() . "\n\n";
    }
}
