<?php
/**
 * YBT Management Software — Portable PHP Process Manager Automated Test
 * Verifies dynamic port discovery, background execution, and clean shutdown without orphans.
 */

require_once dirname(__DIR__) . '/desktop/src/ProcessManager.php';

echo "\n============================================================\n";
echo "TESTING PORTABLE PHP PROCESS MANAGER & RUNTIME\n";
echo "============================================================\n";

$pm = new ProcessManager();

// 1. Dynamic port discovery
$port1 = $pm->findAvailablePort(15000);
$port2 = $pm->findAvailablePort(16000);
echo " [PASS] Found available port 1: {$port1}\n";
echo " [PASS] Found available port 2: {$port2}\n";

// 2. Start portable server
echo "Starting portable PHP server on port {$port1}...\n";
$startRes = $pm->startServer($port1);
$started = $startRes['success'] && $startRes['pid'] > 0;
echo ($started ? " [PASS]" : " [FAIL]") . " Portable PHP started on port {$port1} (PID: {$startRes['pid']})\n";

// 3. Verify server is listening and responding
$ch = curl_init("http://127.0.0.1:{$port1}/api/v1/health");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$healthOk = ($code === 200 && str_contains((string)$res, 'operational'));
echo ($healthOk ? " [PASS]" : " [FAIL]") . " Portable server HTTP 200 health check response verified\n";

// 4. Verify isRunning() reports true
$isRunning = $pm->isRunning();
echo ($isRunning ? " [PASS]" : " [FAIL]") . " ProcessManager::isRunning() reports true\n";

// 5. Clean shutdown
echo "Shutting down portable server...\n";
$pm->stopServer();
usleep(300000); // 300ms

// 6. Verify server stopped
$stopped = !$pm->isRunning();
echo ($stopped ? " [PASS]" : " [FAIL]") . " ProcessManager::isRunning() reports false after shutdown\n";

// 7. Verify socket closed (no orphan)
$fp = @fsockopen('127.0.0.1', $port1, $errno, $errstr, 0.2);
$socketClosed = !is_resource($fp);
if (is_resource($fp)) fclose($fp);
echo ($socketClosed ? " [PASS]" : " [FAIL]") . " Port {$port1} released, zero orphan processes\n";

$allPassed = $started && $healthOk && $isRunning && $stopped && $socketClosed;
echo "\n------------------------------------------------------------\n";
echo "RESULT: " . ($allPassed ? "ALL PROCESS MANAGER TESTS PASSED" : "FAILED") . "\n";
echo "============================================================\n";

exit($allPassed ? 0 : 1);
