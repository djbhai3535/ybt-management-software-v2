<?php
/**
 * STAGE 4 LIVE INSTALLED DESKTOP APP HTTP VERIFICATION
 * Tests live HTTP delivery of Invoice PDF, Thermal Receipts, and WhatsApp Sharing
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/tenant-context.php';
require_once __DIR__ . '/../services/customer-service.php';
require_once __DIR__ . '/../services/invoice-service.php';

$appDir = getenv('LOCALAPPDATA') . '/YBT Management Software';
$exePath = $appDir . '/ybt-management-software.exe';

echo "====================================================================\n";
echo " LIVE INSTALLED APP HTTP VERIFICATION — STAGE 4 INVOICE PDF & THERMAL\n";
echo " Executable: {$exePath}\n";
echo "====================================================================\n\n";

if (!file_exists($exePath)) {
    echo "[FAIL] Installed desktop executable does not exist at {$exePath}\n";
    exit(1);
}

// 0. Ensure a test invoice exists for Tenant 1 (Owner)
$db = Database::getInstance();
TenantContext::set(1);

$existingInv = Database::fetch("SELECT id FROM invoices WHERE business_id = 1 ORDER BY id DESC LIMIT 1");
$createdTestInv = false;
$testInvId = null;
$testCustId = null;

if ($existingInv) {
    $testInvId = (int)$existingInv['id'];
} else {
    // Create test customer & invoice
    $testCustId = CustomerService::create(1, [
        'name' => 'Demo Live Customer',
        'email' => 'live_demo_' . uniqid() . '@ybttest.com',
        'phone' => '03001234567',
        'city' => 'Lahore'
    ], 1);

    $testInvId = InvoiceService::create(1, [
        'customer_id' => $testCustId,
        'invoice_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+7 days')),
        'notes' => 'Live test invoice'
    ], [
        [
            'item_type' => 'product',
            'description' => 'Test Mobile Screen Replacement',
            'quantity' => 1,
            'unit_price' => 3000.00,
            'tax_rate' => 0.00
        ]
    ], 1);
    $createdTestInv = true;
}

echo "Using Invoice ID #{$testInvId} for live desktop tests.\n\n";

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
$totalTests = 6;

try {
    // 1. GET /login.php
    echo "--- [LIVE 1/6] GET /login.php CSRF & Session Init ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Connection: close']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', (string)$res, $cookieMatch);
    preg_match('/name=["\']_csrf_token["\']\s+value=["\']([^"\']+)["\']/i', (string)$res, $csrfMatch);
    $sessionCookie = $cookieMatch[1] ?? '';
    $csrfToken = $csrfMatch[1] ?? '';

    if ($httpCode === 200 && !empty($sessionCookie) && !empty($csrfToken)) {
        echo " [PASS] Live login page loaded (HTTP 200). CSRF token extracted.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Failed to load login page. HTTP: {$httpCode}\n";
    }

    // 2. POST /login.php (Authenticate as Owner)
    echo "\n--- [LIVE 2/6] POST /login.php Owner Authentication ---\n";
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

    preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', (string)$res, $authCookieMatch);
    $authCookie = !empty($authCookieMatch[1]) ? $authCookieMatch[1] : $sessionCookie;
    $isRedirect = ($httpCode === 302 || str_contains((string)$res, 'Location: dashboard.php'));

    if ($isRedirect) {
        echo " [PASS] Authenticated successfully as shopkeeper Owner (HTTP 302 -> dashboard.php)\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Login authentication failed. HTTP: {$httpCode}\n";
    }

    // 3. GET /invoices.php (Check Action Toolbar for PDF, Thermal, WhatsApp)
    echo "\n--- [LIVE 3/6] GET /invoices.php Directory with Stage 4 Actions ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/invoices.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$authCookie}", 'Connection: close']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $hasDownloadAction = is_string($html) && str_contains($html, 'invoice-pdf.php') && str_contains($html, 'download=1');
    $hasThermalAction = is_string($html) && str_contains($html, 'invoice-thermal-print.php');
    $hasWhatsAppAction = is_string($html) && str_contains($html, 'wa.me');

    if (!$hasDownloadAction || !$hasThermalAction || !$hasWhatsAppAction) {
        echo "        Debug: Download=" . ($hasDownloadAction ? 'Y' : 'N') . ", Thermal=" . ($hasThermalAction ? 'Y' : 'N') . ", WhatsApp=" . ($hasWhatsAppAction ? 'Y' : 'N') . "\n";
        echo "        HTML length: " . strlen($html) . ", Has 'No Invoices Found': " . (str_contains($html, 'No Invoices Found') ? 'Y' : 'N') . "\n";
    }

    if ($httpCode === 200 && $hasDownloadAction && $hasThermalAction && $hasWhatsAppAction) {
        echo " [PASS] Invoices table renders one-click Download PDF, Thermal POS, and WhatsApp actions.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Invoices table missing Stage 4 action buttons. HTTP: {$httpCode}\n";
    }

    // 4. GET /invoice-pdf.php?id=$testInvId (A4 Print Preview View)
    echo "\n--- [LIVE 4/6] GET /invoice-pdf.php A4 Print Document ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/invoice-pdf.php?id={$testInvId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$authCookie}", 'Connection: close']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $hasTaxInvoice = is_string($html) && str_contains($html, 'TAX INVOICE');
    $hasUrduTitle = is_string($html) && str_contains($html, 'انوائس / بل');
    $hasToolbar = is_string($html) && str_contains($html, 'Download PDF') && str_contains($html, 'Print A4');

    if ($httpCode === 200 && $hasTaxInvoice && $hasUrduTitle && $hasToolbar) {
        echo " [PASS] Standalone A4 invoice document loaded successfully with bilingual headers.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Invoice PDF HTML rendering failed. HTTP: {$httpCode}\n";
    }

    // 5. GET /invoice-pdf.php?id=$testInvId&download=1 (Binary PDF Stream Download)
    echo "\n--- [LIVE 5/6] GET /invoice-pdf.php?download=1 Binary PDF Stream ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/invoice-pdf.php?id={$testInvId}&download=1");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$authCookie}", 'Connection: close']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = is_string($response) ? substr($response, 0, $headerSize) : '';
    $body = is_string($response) ? substr($response, $headerSize) : '';
    curl_close($ch);

    $hasPdfContentType = str_contains($headers, 'Content-Type: application/pdf');
    $hasAttachmentHeader = str_contains($headers, 'Content-Disposition: attachment') && str_contains($headers, '.pdf');
    $hasPdfMagicBytes = str_starts_with($body, '%PDF-1.4');
    $hasPdfEof = str_contains($body, '%%EOF');

    if ($httpCode === 200 && $hasPdfContentType && $hasAttachmentHeader && $hasPdfMagicBytes && $hasPdfEof) {
        echo " [PASS] Direct Binary PDF download stream verified (" . strlen($body) . " bytes).\n";
        echo "        Content-Type: application/pdf, valid %PDF-1.4 header, attachment disposition.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Binary PDF download stream failed. HTTP: {$httpCode}\n";
    }

    // 6. GET /invoice-thermal-print.php?id=$testInvId&size=80mm (Thermal POS Receipt)
    echo "\n--- [LIVE 6/6] GET /invoice-thermal-print.php Thermal POS Receipt ---\n";
    $ch = curl_init("http://127.0.0.1:{$allocatedPort}/invoice-thermal-print.php?id={$testInvId}&size=80mm");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: {$authCookie}", 'Connection: close']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $hasReceiptContainer = is_string($html) && str_contains($html, 'receipt-container') && str_contains($html, '80mm');
    $hasPrintAction = is_string($html) && (str_contains($html, 'window.print()') || str_contains($html, 'Print Receipt'));

    if ($httpCode === 200 && $hasReceiptContainer && $hasPrintAction) {
        echo " [PASS] Thermal POS receipt (80mm) served cleanly with compact receipt styling.\n";
        $testsPassed++;
    } else {
        echo " [FAIL] Thermal POS receipt rendering failed. HTTP: {$httpCode}\n";
    }

} finally {
    echo "\nTerminating live desktop app process...\n";
    shell_exec('taskkill /IM ybt-management-software.exe /F 2>nul');
    if ($phpPid) {
        shell_exec("taskkill /PID {$phpPid} /F 2>nul");
    }
    proc_terminate($proc);
    foreach ($pipes as $p) {
        if (is_resource($p)) fclose($p);
    }

    // Clean up temporary test invoice if we created it
    if ($createdTestInv && $testInvId) {
        $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$testInvId]);
        $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$testInvId]);
        if ($testCustId) {
            $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$testCustId]);
        }
    }
}

echo "\n====================================================================\n";
echo "STAGE 4 LIVE APP RESULTS: {$testsPassed} / {$totalTests} PASSED\n";
echo "====================================================================\n";

if ($testsPassed === $totalTests) {
    echo ">>> STAGE 4 LIVE INSTALLED APP VERIFICATION 100% SUCCESSFUL! <<<\n";
    exit(0);
} else {
    echo ">>> LIVE APP VERIFICATION FAILED! <<<\n";
    exit(1);
}
