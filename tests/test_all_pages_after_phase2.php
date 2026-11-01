<?php
/**
 * All Pages Verification Audit on SQLite Offline Runtime
 * Tests Shopkeeper pages (26) + Error pages (1) + Super Admin pages (6) = 33 pages
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$projectDir = realpath(__DIR__ . '/..');
require_once $projectDir . '/includes/bootstrap.php';
require_once $projectDir . '/desktop/src/SqliteClient.php';

// Ensure active database is upgraded with all migrations
$sqlitePath = str_replace('\\', '/', getenv('APPDATA')) . '/YBT Management Software/storage/desktop/ybt_local.sqlite';
$sqlite = new SqliteClient($sqlitePath);
$sqlite->runMigrations();

$pages = [
    // Standard Shopkeeper Pages (20)
    'dashboard.php',
    'customers.php',
    'customer-view.php',
    'customer-ledger-print.php',
    'products.php',
    'invoices.php',
    'invoice-create.php',
    'invoice-view.php',
    'payments.php',
    'expenses.php',
    'leads.php',
    'quotations.php',
    'quotation-create.php',
    'tasks.php',
    'reports.php',
    'managers.php',
    'settings.php',
    'profile.php',
    'ai-assistant.php',
    'onboarding.php',
    // Public & Auth Pages (6)
    'register.php',
    'login.php',
    'forgot-password.php',
    'resend-verification.php',
    'reset-password.php',
    'verify-email.php',
    'accept-invitation.php',
    // Errors (1)
    'errors/403.php',
    // Super Admin Pages (6)
    'admin/index.php',
    'admin/tenants.php',
    'admin/tenant-view.php',
    'admin/users.php',
    'admin/announcements.php',
    'admin/settings.php',
];

echo "====================================================================\n";
echo " FULL APPLICATION PAGE AUDIT (SHOPKEEPER & SUPER ADMIN)\n";
echo " Database: {$sqlitePath}\n";
echo "====================================================================\n\n";

echo sprintf("%-25s | %-10s | %s\n", "Page", "Status", "Details");
echo str_repeat("-", 85) . "\n";

$brokenCount = 0;
$okCount = 0;

$publicPages = [
    'login.php',
    'register.php',
    'forgot-password.php',
    'resend-verification.php',
    'reset-password.php',
    'verify-email.php',
    'accept-invitation.php',
    'errors/403.php',
];

$phpBin = 'C:/Users/Dj Bhai/AppData/Local/YBT Management Software/php/php.exe';

foreach ($pages as $page) {
    $isPublic = in_array($page, $publicPages, true);
    $isAdmin = str_starts_with($page, 'admin/');
    
    if ($isAdmin) {
        $authSetup = "
            \$_SESSION['user_id'] = 14;
            \$_SESSION['business_id'] = 1;
            \$_SESSION['is_super_admin'] = true;
            \$_SESSION['role_slug'] = 'super_admin';
            \$_SESSION['user_name'] = 'Platform Super Admin';
            \$_SESSION['user_email'] = 'superadmin@ybt.com';
            \$_SESSION['auth_mode'] = 'offline';
            TenantContext::set(1, ['name' => 'YBT Technologies (Demo)']);
        ";
    } elseif ($isPublic) {
        $authSetup = "
            \$_SESSION = [];
        ";
    } elseif ($page === 'onboarding.php') {
        $authSetup = "
            \$_SESSION['user_id'] = 1;
            \$_SESSION['business_id'] = 1;
            \$_SESSION['tenant_uuid'] = 'tenant-demo-ybt-001';
            \$_SESSION['is_owner'] = true;
            \$_SESSION['role_slug'] = 'owner';
            \$_SESSION['user_name'] = 'Vikram Malhotra';
            \$_SESSION['user_email'] = 'owner@ybt.com';
            \$_SESSION['auth_mode'] = 'offline';
            \$_SESSION['onboarding_step'] = 1;
            TenantContext::set(1, ['name' => 'YBT Technologies (Demo)']);
            Database::query('UPDATE users SET onboarding_completed = 0 WHERE id = 1');
            Database::query('UPDATE businesses SET onboarding_step = 1 WHERE id = 1');
            register_shutdown_function(function() {
                Database::query('UPDATE users SET onboarding_completed = 1 WHERE id = 1');
                Database::query('UPDATE businesses SET onboarding_step = 3 WHERE id = 1');
            });
        ";
    } else {
        $authSetup = "
            \$_SESSION['user_id'] = 1;
            \$_SESSION['business_id'] = 1;
            \$_SESSION['tenant_uuid'] = 'tenant-demo-ybt-001';
            \$_SESSION['is_owner'] = true;
            \$_SESSION['role_slug'] = 'owner';
            \$_SESSION['user_name'] = 'Vikram Malhotra';
            \$_SESSION['user_email'] = 'owner@ybt.com';
            \$_SESSION['auth_mode'] = 'offline';
            TenantContext::set(1, ['name' => 'YBT Technologies (Demo)']);
        ";
    }

    $script = "
        error_reporting(0);
        ini_set('display_errors', '0');
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        \$_SERVER['HTTP_HOST'] = '127.0.0.1';
        \$_SERVER['REQUEST_URI'] = '/{$page}';
        \$_GET = ['id' => 1, 'token' => 'dummy_token'];
        require_once '{$projectDir}/includes/bootstrap.php';
        require_once '{$projectDir}/includes/auth.php';
        {$authSetup}
        ob_start();
        try {
            include '{$projectDir}/{$page}';
            ob_end_clean();
            echo 'OK (Rendered)';
        } catch (Throwable \$e) {
            ob_end_clean();
            echo 'ERROR: ' . \$e->getMessage() . ' in ' . basename(\$e->getFile()) . ':' . \$e->getLine();
        }
    ";

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(escapeshellarg($phpBin), $descriptors, $pipes);
    fwrite($pipes[0], "<?php " . $script);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $cleanOut = trim((string)$out);
    $isOk = str_starts_with($cleanOut, 'OK');
    if ($isOk) {
        $okCount++;
        echo sprintf("%-25s | %-10s | %s\n", $page, "OK", $cleanOut);
    } else {
        $brokenCount++;
        echo sprintf("%-25s | %-10s | %s\n", $page, "BROKEN", $cleanOut);
    }
}

echo "\n====================================================================\n";
echo "AUDIT SUMMARY: {$okCount} OK, {$brokenCount} BROKEN out of " . count($pages) . " pages.\n";
echo "====================================================================\n";

if ($brokenCount > 0) {
    exit(1);
}
exit(0);
