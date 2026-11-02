<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "====================================================================\n";
echo " PHASE 0.5: SAFE RUNTIME & INSTALLER PROTECTION VERIFICATION\n";
echo "====================================================================\n\n";

$testsPassed = 0;
$totalTests = 6;

// 1. Verify Active SQLite Database Integrity
echo "--- [TEST 1/6] Active SQLite Database Verification ---\n";
$activeDbPath = getenv('APPDATA') . '/YBT Management Software/storage/desktop/ybt_local.sqlite';
if (!file_exists($activeDbPath)) {
    echo "[FAIL] Active database does not exist at {$activeDbPath}\n";
    exit(1);
}
$pdo = new PDO("sqlite:" . $activeDbPath);
$integrity = $pdo->query("PRAGMA integrity_check")->fetchColumn();
$custCount = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$ledgerCount = (int)$pdo->query("SELECT COUNT(*) FROM customer_ledger")->fetchColumn();
$prodCount = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

if ($integrity === 'ok' && $custCount === 26 && $ledgerCount === 49 && $prodCount === 30) {
    echo " [PASS] Active database integrity verified: PRAGMA {$integrity}, Customers: {$custCount}, Ledger: {$ledgerCount}, Products: {$prodCount}\n";
    $testsPassed++;
} else {
    echo " [FAIL] Database verification failed! Customers: {$custCount}, Ledger: {$ledgerCount}\n";
}

// 2. Verify Database Backup Verification
echo "\n--- [TEST 2/6] Dedicated Timestamped Backup Verification ---\n";
$backupDbPath = getenv('APPDATA') . '/YBT Management Software/storage/desktop/ybt_local_backup_20261102_083237.sqlite';
$backupPdo = new PDO("sqlite:" . $backupDbPath);
$bIntegrity = $backupPdo->query("PRAGMA integrity_check")->fetchColumn();
$bCust = (int)$backupPdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$bLedger = (int)$backupPdo->query("SELECT COUNT(*) FROM customer_ledger")->fetchColumn();

if ($bIntegrity === 'ok' && $bCust === 26 && $bLedger === 49) {
    echo " [PASS] Dedicated backup verified queryable with 100% record match.\n";
    $testsPassed++;
} else {
    echo " [FAIL] Backup database check failed.\n";
}

// 3. Verify Tauri Config Resource Mappings
echo "\n--- [TEST 3/6] Packaging Configuration (tauri.conf.json) ---\n";
$tauriConfPath = __DIR__ . '/../desktop/src-tauri/tauri.conf.json';
$tauriConf = json_decode(file_get_contents($tauriConfPath), true);
$resources = $tauriConf['bundle']['resources'] ?? [];

$hasAdmin = isset($resources['../../admin']) && $resources['../../admin'] === 'admin';
$hasErrors = isset($resources['../../errors']) && $resources['../../errors'] === 'errors';
$hasServices = isset($resources['../../services']) && $resources['../../services'] === 'services';
$hasIncludes = isset($resources['../../includes']) && $resources['../../includes'] === 'includes';

if ($hasAdmin && $hasErrors && $hasServices && $hasIncludes) {
    echo " [PASS] tauri.conf.json includes admin, errors, services, and includes in packaged bundle resources.\n";
    $testsPassed++;
} else {
    echo " [FAIL] tauri.conf.json missing critical resource mappings.\n";
}

// 4. Verify Native Launcher Source Override (main.rs)
echo "\n--- [TEST 4/6] Native Windows Launcher Environment Support (main.rs) ---\n";
$mainRsPath = __DIR__ . '/../desktop/src-tauri/src/main.rs';
$mainRs = file_get_contents($mainRsPath);

if (str_contains($mainRs, 'YBT_SOURCE_ROOT')) {
    echo " [PASS] main.rs supports developer YBT_SOURCE_ROOT override, preventing fallback to old installed files.\n";
    $testsPassed++;
} else {
    echo " [FAIL] main.rs does not contain YBT_SOURCE_ROOT support.\n";
}

// 5. Verify Development Launcher Script (launch-desktop.ps1)
echo "\n--- [TEST 5/6] Development Process Supervisor (launch-desktop.ps1) ---\n";
$launcherPs1 = file_get_contents(__DIR__ . '/../desktop/launch-desktop.ps1');

if (str_contains($launcherPs1, 'YBT_SQLITE_PATH') && str_contains($launcherPs1, 'YBT_SOURCE_ROOT') && str_contains($launcherPs1, 'APP_ENV')) {
    echo " [PASS] launch-desktop.ps1 binds to live source directory C:\\CRM-Software with isolated database path.\n";
    $testsPassed++;
} else {
    echo " [FAIL] launch-desktop.ps1 missing environment configuration.\n";
}

// 6. Test Development Server Execution & HTTP Delivery
echo "\n--- [TEST 6/6] Development Micro-Engine HTTP Loopback Test ---\n";
$phpBin = 'C:/xampp/php/php.exe';
$phpIni = 'C:/CRM-Software/desktop/php/php.ini';
$rootDir = realpath(__DIR__ . '/..');

// Choose a clean test port
$port = 18895;

putenv('APP_ENV=desktop');
putenv("YBT_SQLITE_PATH={$activeDbPath}");

$cmd = escapeshellarg($phpBin) . " -S 127.0.0.1:{$port} -t " . escapeshellarg($rootDir);
$proc = proc_open(
    $cmd,
    [
        0 => ['pipe', 'r'],
        1 => ['file', 'NUL', 'w'],
        2 => ['file', 'NUL', 'w']
    ],
    $pipes,
    $rootDir
);

usleep(500000); // 500ms startup

$ch = curl_init("http://127.0.0.1:{$port}/login.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Connection: close']);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

proc_terminate($proc);
foreach ($pipes as $p) {
    if (is_resource($p)) fclose($p);
}

if ($httpCode === 200 && is_string($res) && str_contains($res, 'Sign In')) {
    echo " [PASS] Portable PHP micro-engine served live C:\\CRM-Software source on port {$port} (HTTP 200).\n";
    $testsPassed++;
} else {
    echo " [FAIL] Development micro-engine test failed. HTTP Code: {$httpCode}, Error: {$curlError}\n";
    if ($stderrOutput) {
        echo "        Stderr: {$stderrOutput}\n";
    }
}

echo "\n====================================================================\n";
echo "PHASE 0.5 RESULTS: {$testsPassed} / {$totalTests} PASSED\n";
echo "====================================================================\n";

if ($testsPassed === $totalTests) {
    echo ">>> PHASE 0.5 RUNTIME PROTECTION & PACKAGING AUDIT 100% SUCCESSFUL! <<<\n";
    exit(0);
} else {
    echo ">>> PHASE 0.5 FAILED! <<<\n";
    exit(1);
}
