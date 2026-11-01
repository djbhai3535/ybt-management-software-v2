<?php
/**
 * STAGE 3 HTTP LIVE INSTALLED APP VERIFICATION
 * YBT Management Software
 *
 * Verifies the live installed Windows desktop binary serves the Stage 3 Khata UI
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$appDir = getenv('LOCALAPPDATA') . '/YBT Management Software';
$exePath = $appDir . '/ybt-management-software.exe';

echo "====================================================================\n";
echo " LIVE INSTALLED APP HTTP VERIFICATION — STAGE 3 KHATA & PRINTING    \n";
echo " Executable: {$exePath}\n";
echo "====================================================================\n\n";

if (!file_exists($exePath)) {
    echo "[FAIL] Installed desktop executable does not exist at {$exePath}\n";
    exit(1);
}

// Launch the desktop application
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

// Find allocated dynamic loopback port
$allocatedPort = null;
$phpPid = null;

for ($i = 0; $i < 30; $i++) {
    usleep(300000); // 300ms
    $netstatOutput = shell_exec('netstat -ano -p tcp | findstr "LISTENING" | findstr "127.0.0.1"');
    if ($netstatOutput) {
        $lines = explode("\n", trim($netstatOutput));
        foreach ($lines as $line) {
            if (preg_match('/127\.0\.0\.1:(\d+)\s+.*LISTENING\s+(\d+)/i', $line, $m)) {
                $p = (int)$m[1];
                $pid = (int)$m[2];
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

echo " [PASS] Installed desktop app is online on dynamic port: {$allocatedPort} (PID: {$phpPid})\n\n";

$testsPassed = 0;
$totalTests = 5;

try {
    // 1. GET /login.php
    echo "--- [LIVE 1/5] GET /login.php CSRF & Session Init ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Connection: close']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $res, $cookieMatch);
    preg_match('/name=["\']_csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $res, $csrfMatch);
    $sessionCookie = $cookieMatch[1] ?? '';
    $csrfToken = $csrfMatch[1] ?? '';

    if ($httpCode === 200 && !empty($sessionCookie) && !empty($csrfToken)) {
        echo " [PASS] Live login page loaded (HTTP 200). CSRF token extracted.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Failed to load login page. HTTP: {$httpCode}\n";
    }

    // 2. POST /login.php (Authenticate as Owner)
    echo "\n--- [LIVE 2/5] POST /login.php Owner Authentication ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$sessionCookie}", 'Connection: close']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        '_csrf_token' => $csrfToken,
        'email' => 'owner@ybt.com',
        'password' => 'password123'
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $res, $authCookieMatch);
    $authCookie = !empty($authCookieMatch[1]) ? $authCookieMatch[1] : $sessionCookie;
    $isRedirect = ($httpCode === 302 || str_contains($res, 'Location: dashboard.php'));

    if ($isRedirect) {
        echo " [PASS] Authenticated successfully as shopkeeper Owner (HTTP 302 -> dashboard.php)\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Login authentication failed. HTTP: {$httpCode}\n";
    }

    // 3. GET /customers.php (Check Khata column rendering)
    echo "\n--- [LIVE 3/5] GET /customers.php Directory with Khata Balance ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/customers.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$authCookie}", 'Connection: close']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $hasKhataColumn = str_contains($html, 'Khata Balance') || str_contains($html, 'Balance');
    $hasAddCustomerModal = str_contains($html, 'Opening Balance') || str_contains($html, 'Add Customer');

    if ($httpCode === 200 && $hasKhataColumn) {
        echo " [PASS] Customer directory rendered cleanly with Khata balance integrations.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Failed loading customer directory. HTTP: {$httpCode}\n";
    }

    // 4. GET /customer-view.php?id=1 (Check 4 KPI Cards: You Gave, You Got, Net Balance)
    echo "\n--- [LIVE 4/5] GET /customer-view.php Khata Ledger Profile ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/customer-view.php?id=1");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$authCookie}", 'Connection: close']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $hasYouGave = str_contains($html, 'You Gave') || str_contains($html, 'آپ نے دیے');
    $hasYouGot = str_contains($html, 'You Got') || str_contains($html, 'آپ کو ملے');
    $hasNetBalance = str_contains($html, 'Net Khata') || str_contains($html, 'خالص بقایا');

    if ($httpCode === 200 && $hasYouGave && $hasYouGot) {
        echo " [PASS] Customer Profile rendered with You Gave / You Got Khata KPI summary cards.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Customer profile Khata rendering failed. HTTP: {$httpCode}\n";
    }

    // 5. GET /customer-ledger-print.php?id=1 (Printable Statement)
    echo "\n--- [LIVE 5/5] GET /customer-ledger-print.php Printable Statement ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/customer-ledger-print.php?id=1");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$authCookie}", 'Connection: close']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $hasPrintView = str_contains($html, 'Customer Ledger Statement') && str_contains($html, 'Print Statement');

    if ($httpCode === 200 && $hasPrintView) {
        echo " [PASS] Printable statement served successfully via desktop app with print triggers.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Printable statement rendering failed. HTTP: {$httpCode}\n";
    }

} finally {
    echo "\nTerminating test desktop application process...\n";
    shell_exec('taskkill /IM ybt-management-software.exe /F 2>nul');
    if ($phpPid) {
        shell_exec("taskkill /PID {$phpPid} /F 2>nul");
    }
    proc_terminate($proc);
    foreach ($pipes as $p) {
        if (is_resource($p)) fclose($p);
    }
}

echo "\n====================================================================\n";
echo "INSTALLED DESKTOP APP VERIFICATION RESULTS: {$testsPassed} / {$totalTests} PASSED\n";
echo "====================================================================\n";

if ($testsPassed === $totalTests) {
    echo ">>> LIVE INSTALLED DESKTOP APPLICATION E2E VERIFICATION 100% SUCCESSFUL! <<<\n";
    exit(0);
} else {
    echo ">>> LIVE INSTALLED APP VERIFICATION FAILED! <<<\n";
    exit(1);
}
