<?php
/**
 * YBT Management Software — Master Automated Test Runner
 */

define('YBT_ROOT', dirname(__DIR__));

echo "====================================================\n";
echo "   YBT Management Software — Phase 2 Test Runner    \n";
echo "====================================================\n\n";

$tests = [
    'Tenant Isolation'   => __DIR__ . '/test_tenant_isolation.php',
    'Encryption Security' => __DIR__ . '/test_encryption.php',
    'CSRF Protection'    => __DIR__ . '/test_csrf.php',
    'Restore Safety'     => __DIR__ . '/test_restore_safety.php',
];

$passedCount = 0;
$failedCount = 0;

foreach ($tests as $title => $script) {
    echo ">> Running {$title} Suite:\n";
    $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($script);
    passthru($cmd, $exitCode);
    if ($exitCode === 0) {
        $passedCount++;
    } else {
        $failedCount++;
    }
    echo "----------------------------------------------------\n";
}

echo "\n====================================================\n";
echo "Test Execution Summary:\n";
echo "Total Suites: " . count($tests) . "\n";
echo "Passed:       {$passedCount}\n";
echo "Failed:       {$failedCount}\n";
echo "====================================================\n";

exit($failedCount > 0 ? 1 : 0);
