<?php
/**
 * YBT Management Software — Master Automated Test Runner (All 8 Test Suites)
 */

define('YBT_ROOT', dirname(__DIR__));

echo "====================================================\n";
echo "   YBT Management Software — Master Verification    \n";
echo "====================================================\n\n";

$tests = [
    'Tenant Isolation Foundation'    => __DIR__ . '/test_tenant_isolation.php',
    'Authenticated Encryption (GCM)' => __DIR__ . '/test_encryption.php',
    'CSRF Protection Security'       => __DIR__ . '/test_csrf.php',
    'Restore Safety Controls'        => __DIR__ . '/test_restore_safety.php',
    'Database Migration & Rollback'  => __DIR__ . '/test_database_migration.php',
    'Deep Multi-Tenant Isolation'    => __DIR__ . '/test_multi_tenant_deep.php',
    'Real HTTP REST API v1 Suite'    => __DIR__ . '/test_api_http.php',
    'Existing CRM Full Regression'   => __DIR__ . '/test_crm_regression.php',
    'Phase 3 Auth & Management'      => __DIR__ . '/test_phase3_auth.php',
    'Phase 3 Real HTTP API'          => __DIR__ . '/test_phase3_api.php',
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
echo "Master Verification Execution Summary:\n";
echo "Total Suites Executed: " . count($tests) . "\n";
echo "Passed:                {$passedCount}\n";
echo "Failed:                {$failedCount}\n";
echo "====================================================\n";

exit($failedCount > 0 ? 1 : 0);
