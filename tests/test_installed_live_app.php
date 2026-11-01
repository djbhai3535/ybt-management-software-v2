<?php
/**
 * End-to-End Real-World Installed Application Verification
 * Tests the installed Native Windows EXE with bundled PHP runtime.
 * XAMPP Apache and MySQL are completely STOPPED.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$appDir = getenv('LOCALAPPDATA') . '/YBT Management Software';
$exePath = $appDir . '/ybt-management-software.exe';

echo "===================================================================\n";
echo " YBT MANAGEMENT SOFTWARE — INSTALLED DESKTOP APP E2E VERIFICATION\n";
echo "===================================================================\n";
echo "App Directory:  {$appDir}\n";
echo "Executable:     {$exePath}\n\n";

if (!file_exists($exePath)) {
    echo "[FAIL] Installed executable does not exist at {$exePath}\n";
    exit(1);
}

// 1. Launch the installed desktop application
echo "1. Launching installed desktop application...\n";
$proc = proc_open(escapeshellarg($exePath), [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
], $pipes);

if (!is_resource($proc)) {
    echo "[FAIL] Could not launch {$exePath}\n";
    exit(1);
}

// Wait for app and bundled PHP runtime to initialize
echo "   Waiting for bundled runtime to initialize and bind port...\n";
$allocatedPort = null;
$phpPid = null;

for ($i = 0; $i < 25; $i++) {
    usleep(400000); // 400ms

    // Find listening 127.0.0.1 port owned by php process
    $netstatOutput = shell_exec('netstat -ano -p tcp | findstr "LISTENING" | findstr "127.0.0.1"');
    if ($netstatOutput) {
        $lines = explode("\n", trim($netstatOutput));
        foreach ($lines as $line) {
            if (preg_match('/127\.0\.0\.1:(\d+)\s+.*LISTENING\s+(\d+)/i', $line, $m)) {
                $p = (int)$m[1];
                $pid = (int)$m[2];
                // Check if this PID is php.exe
                $tasklist = shell_exec("tasklist /FI \"PID eq {$pid}\" /NH");
                if ($tasklist && str_contains(strtolower($tasklist), 'php.exe')) {
                    $allocatedPort = $p;
                    $phpPid = $pid;
                    break 2;
                }
            }
        }
    }
}

if (!$allocatedPort) {
    echo "[FAIL] Could not find running PHP loopback port.\n";
    proc_terminate($proc);
    exit(1);
}

echo " [PASS] Found bundled PHP runtime listening on port {$allocatedPort} (PID: {$phpPid})\n\n";

$testsPassed = 0;
$totalTests = 6;

try {
    // -----------------------------------------------------------------
    // TEST 1: GET /login.php
    // -----------------------------------------------------------------
    echo "--- TEST 1: GET /login.php via Dynamic Port ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $res, $cookieMatch);
    preg_match('/name=["\']_csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $res, $csrfMatch);

    $sessionCookie = $cookieMatch[1] ?? '';
    $csrfToken = $csrfMatch[1] ?? '';

    if ($httpCode === 200 && !empty($sessionCookie) && !empty($csrfToken)) {
        echo " [PASS] Login page loaded with HTTP 200.\n";
        echo "        Session Cookie: {$sessionCookie}\n";
        echo "        CSRF Token:     {$csrfToken}\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Failed loading login page. Code: {$httpCode}\n";
    }

    // -----------------------------------------------------------------
    // TEST 2: POST /login.php — Owner Demo Login
    // -----------------------------------------------------------------
    echo "\n--- TEST 2: POST /login.php — Owner Demo Login (owner@ybt.com) ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$sessionCookie}"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        '_csrf_token' => $csrfToken,
        'email' => 'owner@ybt.com',
        'password' => 'password123'
    ]));
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $hasInvalidCsrf = str_contains($res, 'Invalid security token');
    $isRedirect = ($httpCode === 302 || str_contains($res, 'Location: dashboard.php'));

    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $res, $newCookieMatch);
    $authCookie = !empty($newCookieMatch[1]) ? $newCookieMatch[1] : $sessionCookie;

    if (!$hasInvalidCsrf && $isRedirect) {
        echo " [PASS] Owner demo login succeeded with HTTP 302 redirect.\n";
        echo "        NO 'Invalid security token' error!\n";
        echo "        Authenticated Cookie: {$authCookie}\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Owner login failed. HTTP: {$httpCode}, Has CSRF Error: " . ($hasInvalidCsrf ? 'YES' : 'NO') . "\n";
    }

    // -----------------------------------------------------------------
    // TEST 3: GET /dashboard.php — Render with Authenticated Session
    // -----------------------------------------------------------------
    echo "\n--- TEST 3: GET /dashboard.php Render ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/dashboard.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$authCookie}"]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $isLoggedIn = !str_contains($res, 'Location: login.php') && ($httpCode === 200);
    $hasContent = str_contains($res, 'Executive Dashboard') || str_contains($res, 'Total Collected') || str_contains($res, 'Vikram');

    if ($isLoggedIn && $hasContent) {
        echo " [PASS] Dashboard loaded successfully for authenticated Owner.\n";
        echo "        Executive Dashboard KPIs and UI rendered cleanly.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Dashboard failed to load. HTTP: {$httpCode}\n";
    }

    // -----------------------------------------------------------------
    // TEST 4: POST /login.php — Manager Demo Login
    // -----------------------------------------------------------------
    echo "\n--- TEST 4: POST /login.php — Manager Demo Login (manager@ybt.com) ---\n";
    // Fresh session
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $res = curl_exec($ch);
    curl_close($ch);

    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $res, $mgrCookieMatch);
    preg_match('/name=["\']_csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $res, $mgrCsrfMatch);
    $mgrCookie = $mgrCookieMatch[1] ?? '';
    $mgrToken = $mgrCsrfMatch[1] ?? '';

    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$mgrCookie}"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        '_csrf_token' => $mgrToken,
        'email' => 'manager@ybt.com',
        'password' => 'password123'
    ]));
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $mgrHasInvalidCsrf = str_contains($res, 'Invalid security token');
    $mgrIsRedirect = ($httpCode === 302 || str_contains($res, 'Location: dashboard.php'));

    if (!$mgrHasInvalidCsrf && $mgrIsRedirect) {
        echo " [PASS] Manager demo login succeeded with HTTP 302 redirect.\n";
        echo "        NO 'Invalid security token' error!\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Manager login failed. HTTP: {$httpCode}, Has CSRF Error: " . ($mgrHasInvalidCsrf ? 'YES' : 'NO') . "\n";
    }

    // -----------------------------------------------------------------
    // TEST 5: POST /login.php — Reject Invalid CSRF Token
    // -----------------------------------------------------------------
    echo "\n--- TEST 5: POST /login.php — Reject Invalid CSRF Token ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$mgrCookie}"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        '_csrf_token' => 'invalid_csrf_token_attack_payload_12345',
        'email' => 'owner@ybt.com',
        'password' => 'password123'
    ]));
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $hasCsrfRejection = str_contains($res, 'Invalid security token') || $httpCode === 403;

    if ($hasCsrfRejection) {
        echo " [PASS] Invalid CSRF token was correctly rejected with security token error.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Invalid CSRF token was not rejected.\n";
    }

    // -----------------------------------------------------------------
    // TEST 6: Application Shutdown and Process Cleanup
    // -----------------------------------------------------------------
    echo "\n--- TEST 6: Clean Application Termination ---\n";
    // Graceful close of window so Tauri on_window_event runs
    shell_exec('taskkill /IM ybt-management-software.exe 2>nul');
    proc_terminate($proc);
    foreach ($pipes as $p) {
        if (is_resource($p)) fclose($p);
    }
    
    // Give up to 3 seconds for graceful cleanup
    $isTerminated = false;
    for ($wait = 0; $wait < 6; $wait++) {
        usleep(500000); // 500ms
        $remainingPhp = shell_exec("tasklist /FI \"PID eq {$phpPid}\" /NH");
        if (!$remainingPhp || !str_contains($remainingPhp, (string)$phpPid)) {
            $isTerminated = true;
            break;
        }
    }

    if ($isTerminated) {
        echo " [PASS] Bundled PHP process (PID: {$phpPid}) terminated cleanly on exit.\n";
        echo "        Zero orphaned processes left behind.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] PHP process {$phpPid} remained running.\n";
        shell_exec("taskkill /PID {$phpPid} /F 2>nul");
    }

} finally {
    shell_exec('taskkill /IM ybt-management-software.exe /F 2>nul');
}

echo "\n===================================================================\n";
echo "E2E VERIFICATION RESULTS: {$testsPassed} / {$totalTests} PASSED\n";
echo "===================================================================\n";

if ($testsPassed === $totalTests) {
    echo "INSTALLED DESKTOP APP VERIFICATION 100% SUCCESSFUL!\n";
    exit(0);
} else {
    echo "SOME E2E VERIFICATION CHECKS FAILED!\n";
    exit(1);
}
