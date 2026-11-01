<?php
/**
 * CSRF Lifecycle & Session Verification Test
 */

$port = 18882;
$phpBin = 'C:/Users/Dj Bhai/AppData/Local/YBT Management Software/php/php.exe';
$phpIni = 'C:/Users/Dj Bhai/AppData/Local/YBT Management Software/php/php.ini';
$docRoot = 'C:/Users/Dj Bhai/AppData/Local/YBT Management Software';

$cmd = escapeshellarg($phpBin) . ' -c ' . escapeshellarg($phpIni) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docRoot);
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
usleep(500000); // 500ms

try {
    // 1. GET login.php
    $ch = curl_init("http://127.0.0.1:{$port}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $res = curl_exec($ch);
    curl_close($ch);

    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $res, $cookieMatch);
    preg_match('/name=["\']_csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $res, $csrfMatch);

    $cookie = $cookieMatch[1] ?? '';
    $token = $csrfMatch[1] ?? '';

    echo "1. GET login.php response:\n";
    echo "   Cookie: " . ($cookie ?: 'NONE') . "\n";
    echo "   CSRF Token: " . ($token ?: 'NONE') . "\n";

    if (empty($cookie) || empty($token)) {
        throw new Exception("Failed to obtain cookie or CSRF token on GET");
    }

    // 2. Test POST WITH Cookie and WITH Token
    $ch2 = curl_init("http://127.0.0.1:{$port}/login.php");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HEADER, true);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Cookie: {$cookie}"]);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, http_build_query([
        '_csrf_token' => $token,
        'email' => 'owner@ybt.com',
        'password' => 'password123'
    ]));
    $postRes = curl_exec($ch2);
    curl_close($ch2);

    $hasInvalidCsrf = str_contains($postRes, 'Invalid security token');
    echo "\n2. POST WITH cookie and valid token:\n";
    echo "   Has 'Invalid security token' error: " . ($hasInvalidCsrf ? 'YES (BUG!)' : 'NO (PASSED)') . "\n";

    // 3. Test POST WITHOUT Cookie (Simulating WebView2 dropping third-party iframe cookie)
    $ch3 = curl_init("http://127.0.0.1:{$port}/login.php");
    curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch3, CURLOPT_HEADER, true);
    curl_setopt($ch3, CURLOPT_POST, true);
    // Intentionally no cookie sent
    curl_setopt($ch3, CURLOPT_POSTFIELDS, http_build_query([
        '_csrf_token' => $token,
        'email' => 'owner@ybt.com',
        'password' => 'password123'
    ]));
    $postResNoCookie = curl_exec($ch3);
    curl_close($ch3);

    $noCookieHasInvalidCsrf = str_contains($postResNoCookie, 'Invalid security token');
    echo "\n3. POST WITHOUT cookie (dropped cookie scenario):\n";
    echo "   Has 'Invalid security token' error: " . ($noCookieHasInvalidCsrf ? 'YES (MATCHES USER BUG!)' : 'NO') . "\n";

} finally {
    proc_terminate($proc);
}
