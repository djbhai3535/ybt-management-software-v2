<?php
/**
 * Automated Test Suite: Complete CSRF Lifecycle, Session Persistence, and Authentication
 * 
 * Verifies:
 * 1. GET /login.php sets session cookie and generates CSRF token
 * 2. Session file persists in storage/sessions
 * 3. POST /login.php with valid cookie + token for Owner succeeds (HTTP 302 -> dashboard.php)
 * 4. GET /dashboard.php renders with authenticated session
 * 5. POST /login.php with valid cookie + token for Manager succeeds (HTTP 302 -> dashboard.php)
 * 6. POST /login.php with invalid CSRF token fails with security error
 * 7. POST /login.php with missing session cookie fails with CSRF error (matching original bug)
 * 8. SQLite offline storage data integrity
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectDir = realpath(__DIR__ . '/..');
$port = 18883;
$phpBin = 'C:/Users/Dj Bhai/AppData/Local/YBT Management Software/php/php.exe';
if (!file_exists($phpBin)) {
    $phpBin = 'C:/xampp/php/php.exe';
}
$phpIni = $projectDir . '/desktop/php/php.ini';
if (!file_exists($phpIni)) {
    $phpIni = 'C:/Users/Dj Bhai/AppData/Local/YBT Management Software/php/php.ini';
}

echo "=== YBT MANAGEMENT SOFTWARE — CSRF & AUTH VERIFICATION SUITE ===\n";
echo "Project Directory: {$projectDir}\n";
echo "PHP Binary:        {$phpBin}\n";
echo "PHP INI:           {$phpIni}\n";
echo "Test Port:         {$port}\n\n";

// Ensure sessions directory exists
$sessionDir = $projectDir . '/storage/sessions';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0755, true);
}

// 0. Ensure SQLite database is seeded with demo accounts in both locations
require_once $projectDir . '/desktop/src/SqliteClient.php';
$dbPaths = [
    $projectDir . '/storage/desktop/ybt_local.sqlite',
];
if (getenv('APPDATA')) {
    $dbPaths[] = str_replace('\\', '/', getenv('APPDATA')) . '/YBT Management Software/storage/desktop/ybt_local.sqlite';
}

$activeSqlite = null;
foreach ($dbPaths as $path) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $c = new \SqliteClient($path);
    $c->runMigrations();
    $c->seedDemoAccounts();
    if ($activeSqlite === null) {
        $activeSqlite = $c;
    }
}
$sqlite = $activeSqlite;

// Start built-in PHP server on 127.0.0.1:port
$cmd = escapeshellarg($phpBin) . ' -c ' . escapeshellarg($phpIni) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($projectDir);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
];
$serverProc = proc_open($cmd, $descriptors, $pipes);

if (!is_resource($serverProc)) {
    echo "[FAIL] Could not start PHP built-in server.\n";
    exit(1);
}

// Wait for server to bind
for ($w = 0; $w < 30; $w++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($fp) {
        fclose($fp);
        break;
    }
    usleep(100000); // 100ms
}

$testsPassed = 0;
$totalTests = 8;

try {
    // -------------------------------------------------------------
    // TEST 1: GET /login.php sets session cookie and CSRF token
    // -------------------------------------------------------------
    echo "--- TEST 1: GET /login.php CSRF & Session Initialization ---\n";
    $ch = curl_init("http://127.0.0.1:{$port}/login.php");
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
        echo " [PASS] HTTP 200 received.\n";
        echo "        Session Cookie: {$sessionCookie}\n";
        echo "        CSRF Token:     {$csrfToken}\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Could not get valid session or CSRF token. HTTP Code: {$httpCode}\n";
    }

    // -------------------------------------------------------------
    // TEST 2: Session file persistence in storage/sessions
    // -------------------------------------------------------------
    echo "\n--- TEST 2: Session File Persistence on Disk ---\n";
    $sessionDirs = [
        $sessionDir,
        getenv('APPDATA') ? str_replace('\\', '/', getenv('APPDATA')) . '/YBT Management Software/storage/sessions' : ''
    ];
    $foundFiles = 0;
    $foundPath = '';
    foreach (array_filter($sessionDirs) as $sDir) {
        $files = glob($sDir . '/sess_*');
        if (!empty($files)) {
            $foundFiles += count($files);
            $foundPath = $sDir;
        }
    }
    if ($foundFiles > 0) {
        echo " [PASS] Found {$foundFiles} session file(s) in {$foundPath}\n";
        $testsPassed++;
    } else {
        echo " [FAIL] No session files found in checked paths.\n";
    }

    // -------------------------------------------------------------
    // TEST 3: POST /login.php with Owner credentials
    // -------------------------------------------------------------
    echo "\n--- TEST 3: POST /login.php (Owner Login with Valid CSRF) ---\n";
    $ch = curl_init("http://127.0.0.1:{$port}/login.php");
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
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    $hasInvalidCsrf = str_contains($res, 'Invalid security token');
    $isRedirect = ($httpCode === 302 || str_contains($res, 'Location: dashboard.php'));

    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $res, $newCookieMatch);
    $authenticatedCookie = !empty($newCookieMatch[1]) ? $newCookieMatch[1] : $sessionCookie;

    if (!$hasInvalidCsrf && $isRedirect) {
        echo " [PASS] Owner login succeeded with HTTP 302 redirect to dashboard.php.\n";
        echo "        No CSRF error detected.\n";
        echo "        Authenticated Cookie: {$authenticatedCookie}\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Owner login failed. HTTP: {$httpCode}, Has CSRF Error: " . ($hasInvalidCsrf ? 'YES' : 'NO') . "\n";
    }

    // -------------------------------------------------------------
    // TEST 4: Access /dashboard.php with authenticated session
    // -------------------------------------------------------------
    echo "\n--- TEST 4: GET /dashboard.php Authenticated Access ---\n";
    $ch = curl_init("http://127.0.0.1:{$port}/dashboard.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$authenticatedCookie}"]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $isLoggedIn = !str_contains($res, 'Location: login.php') && ($httpCode === 200);
    $hasOwnerIndicator = str_contains($res, 'Vikram') || str_contains($res, 'Owner') || str_contains($res, 'Dashboard') || str_contains($res, 'Total Revenue') || str_contains($res, 'Quick Actions');

    if ($isLoggedIn && $hasOwnerIndicator) {
        echo " [PASS] Dashboard rendered successfully for authenticated Owner session.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Dashboard access failed. HTTP: {$httpCode}\n";
        echo "--- SNIPPET ---\n" . substr($res, 0, 500) . "\n--- END SNIPPET ---\n";
    }

    // -------------------------------------------------------------
    // TEST 5: POST /login.php with Manager credentials
    // -------------------------------------------------------------
    echo "\n--- TEST 5: POST /login.php (Manager Login with Valid CSRF) ---\n";
    // Get fresh session & token for manager test
    $ch = curl_init("http://127.0.0.1:{$port}/logout.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$sessionCookie}"]);
    curl_exec($ch);
    curl_close($ch);

    $ch = curl_init("http://127.0.0.1:{$port}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $res = curl_exec($ch);
    curl_close($ch);

    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $res, $cookieMatch2);
    preg_match('/name=["\']_csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $res, $csrfMatch2);
    $mgrCookie = $cookieMatch2[1] ?? '';
    $mgrToken = $csrfMatch2[1] ?? '';

    $ch = curl_init("http://127.0.0.1:{$port}/login.php");
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
        echo " [PASS] Manager login succeeded with HTTP 302 redirect to dashboard.php.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Manager login failed. HTTP: {$httpCode}, Has CSRF Error: " . ($mgrHasInvalidCsrf ? 'YES' : 'NO') . "\n";
    }

    // -------------------------------------------------------------
    // TEST 6: POST /login.php with Invalid CSRF Token
    // -------------------------------------------------------------
    echo "\n--- TEST 6: POST /login.php with Tampered/Invalid CSRF Token ---\n";
    $ch = curl_init("http://127.0.0.1:{$port}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$mgrCookie}"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        '_csrf_token' => 'tampered_token_9999999999',
        'email' => 'owner@ybt.com',
        'password' => 'password123'
    ]));
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rejectedCsrf = str_contains($res, 'Invalid security token') || $httpCode === 403;

    if ($rejectedCsrf) {
        echo " [PASS] Tampered CSRF token was correctly rejected with security message.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Tampered CSRF token was NOT rejected!\n";
    }

    // -------------------------------------------------------------
    // TEST 7: POST /login.php with Missing Session Cookie (Dropped Cookie scenario)
    // -------------------------------------------------------------
    echo "\n--- TEST 7: POST /login.php with Missing Session Cookie ---\n";
    $ch = curl_init("http://127.0.0.1:{$port}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    // Intentionally omit Cookie header to simulate cross-origin iframe cookie drop
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        '_csrf_token' => $mgrToken,
        'email' => 'owner@ybt.com',
        'password' => 'password123'
    ]));
    $res = curl_exec($ch);
    curl_close($ch);

    $missingCookieRejected = str_contains($res, 'Invalid security token');

    if ($missingCookieRejected) {
        echo " [PASS] Request without session cookie correctly fails validation.\n";
        echo "        Confirms bug occurs when WebView drops cookies, and passes when cookies are sent.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Expected failure without session cookie, but response differed.\n";
    }

    // -------------------------------------------------------------
    // TEST 8: SQLite Database Integrity and Demo Records
    // -------------------------------------------------------------
    echo "\n--- TEST 8: Local SQLite Offline Database Integrity ---\n";
    $ownerRow = $sqlite->fetch("SELECT * FROM `local_sessions` WHERE `user_email` = 'owner@ybt.com'");
    $mgrRow = $sqlite->fetch("SELECT * FROM `local_sessions` WHERE `user_email` = 'manager@ybt.com'");
    $businessRow = $sqlite->fetch("SELECT * FROM `businesses` WHERE `id` = 1");

    if ($ownerRow && $mgrRow && $businessRow) {
        echo " [PASS] Demo accounts verified in local SQLite database:\n";
        echo "        - Business: " . $businessRow['name'] . "\n";
        echo "        - Owner:    " . $ownerRow['user_name'] . " (" . $ownerRow['user_email'] . ")\n";
        echo "        - Manager:  " . $mgrRow['user_name'] . " (" . $mgrRow['user_email'] . ")\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Demo accounts missing from SQLite database.\n";
    }

} finally {
    // Terminate server process cleanly
    proc_terminate($serverProc);
    foreach ($pipes as $p) {
        if (is_resource($p)) fclose($p);
    }
}

echo "\n==================================================\n";
echo "TEST RESULTS: {$testsPassed} / {$totalTests} TESTS PASSED\n";
echo "==================================================\n";

if ($testsPassed === $totalTests) {
    echo "ALL CSRF AND AUTHENTICATION TESTS PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
