<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../desktop/src/SqliteClient.php';

echo "=== MILESTONE 3: LOCALIZED SHOP ONBOARDING TEST ===\n\n";

$phpBin = 'C:/Users/Dj Bhai/AppData/Local/YBT Management Software/php/php.exe';
$projectDir = realpath(__DIR__ . '/..');

// Clean up and create test shop & owner
Database::query("DELETE FROM users WHERE email = 'test.onboard@ybt.com'");
Database::query("DELETE FROM businesses WHERE email = 'test.onboard@ybt.com'");
Database::query("DELETE FROM local_sessions WHERE user_email = 'test.onboard@ybt.com'");

$tenantUuid = generateUuidV4();
$bizId = Database::insert('businesses', [
    'name' => 'Rawalpindi Mobile Center',
    'legal_name' => 'Rawalpindi Mobile Center',
    'email' => 'test.onboard@ybt.com',
    'phone' => '+92 312 9876543',
    'country' => 'Pakistan',
    'default_currency' => 'PKR',
    'tenant_uuid' => $tenantUuid,
    'status' => 'pending_verification',
    'onboarding_step' => 1
]);

$userId = Database::insert('users', [
    'business_id' => $bizId,
    'role_id' => 1,
    'name' => 'Imran Khan',
    'email' => 'test.onboard@ybt.com',
    'phone' => '+92 312 9876543',
    'password' => password_hash('Pass@123', PASSWORD_DEFAULT),
    'is_owner' => 1,
    'is_active' => 1,
    'onboarding_completed' => 0
]);

$csrfToken = Csrf::token();

// Run onboarding POST via sub-process
$onboardScript = "
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    \$_SERVER['REQUEST_METHOD'] = 'POST';
    \$_SERVER['HTTP_HOST'] = '127.0.0.1';
    \$_SERVER['REQUEST_URI'] = '/onboarding.php';
    require_once '{$projectDir}/includes/bootstrap.php';
    \$_SESSION['user_id'] = {$userId};
    \$_SESSION['business_id'] = {$bizId};
    \$_SESSION['tenant_uuid'] = '{$tenantUuid}';
    \$_SESSION['is_owner'] = true;
    \$_SESSION['role_slug'] = 'owner';
    \$_SESSION['user_name'] = 'Imran Khan';
    \$_SESSION['user_email'] = 'test.onboard@ybt.com';
    \$_SESSION['auth_mode'] = 'offline';
    TenantContext::set({$bizId}, ['name' => 'Rawalpindi Mobile Center']);

    \$token = Csrf::token();
    \$_POST = [
        '_csrf_token' => \$token,
        'legal_name' => 'Rawalpindi Mega Mobile & Khata Hub',
        'type' => 'Mobile Shop & Service Center',
        'phone' => '+92 312 9876543',
        'whatsapp' => '+92 312 9876543',
        'country' => 'Pakistan',
        'state' => 'Punjab',
        'city' => 'Rawalpindi',
        'postal_code' => '46000',
        'address_line1' => 'Shop 44, Saddar Electronic Plaza',
        'default_currency' => 'PKR',
        'invoice_prefix' => 'RMP-',
        'invoice_next_number' => 2001,
        'tax_label' => 'NTN / STRN',
        'tax_number' => '7654321-9',
        'bank_name' => 'Meezan Bank Ltd',
        'account_holder' => 'Imran Khan',
        'account_number' => 'PK36MEZN0001234567890123',
        'ifsc_code' => '0102',
        'wallet_id' => '03129876543',
        'wallet_type' => 'Raast ID / Easypaisa'
    ];
    ob_start();
    try {
        include '{$projectDir}/onboarding.php';
    } catch (Throwable \$e) {
        echo 'EXCEPTION: ' . \$e->getMessage();
    }
";

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(escapeshellarg($phpBin), $descriptors, $pipes);
fwrite($pipes[0], "<?php " . $onboardScript);
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

// Verify DB updates
$biz = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$bizId]);
$usr = Database::fetch("SELECT * FROM users WHERE id = ?", [$userId]);

if (!$biz || (int)$biz['onboarding_step'] !== 3 || $biz['status'] !== 'active') {
    echo "[FAIL] Business onboarding step was not updated to 3 active.\n";
    echo "Subprocess Out: " . substr($out, 0, 500) . "\n";
    if (!empty($err)) echo "Subprocess Err: " . $err . "\n";
    exit(1);
}

if (!$usr || (int)$usr['onboarding_completed'] !== 1) {
    echo "[FAIL] User onboarding_completed was not marked 1.\n";
    exit(1);
}

echo " [PASS] Shop Profile Onboarding Completed Successfully:\n";
echo "        Shop Legal Name:    {$biz['legal_name']}\n";
echo "        Location:           {$biz['city']}, {$biz['state']}, {$biz['country']} ({$biz['postal_code']})\n";
echo "        Currency:           {$biz['default_currency']}\n";
echo "        Tax ID (NTN/STRN):  {$biz['tax_number']} ({$biz['tax_label']})\n";
echo "        Bank & IBAN:        {$biz['bank_name']} ({$biz['account_number']})\n";
echo "        Digital Wallet:     {$biz['wallet_id']} ({$biz['wallet_type']})\n";
echo "        Invoice Setup:      {$biz['invoice_prefix']}{$biz['invoice_next_number']}\n";
echo "        Business Status:    {$biz['status']} (Step: {$biz['onboarding_step']})\n";
echo "        User Onboarding:    Completed ({$usr['onboarding_completed']})\n\n";

// Check sensitive data masking in activity_logs
$log = Database::fetch("SELECT * FROM activity_logs WHERE business_id = ? AND action = 'onboarding_completed' ORDER BY id DESC LIMIT 1", [$bizId]);
if ($log) {
    if (str_contains($log['description'], 'PK36MEZN0001234567890123')) {
        echo "[FAIL] Raw bank account number was exposed in activity log!\n";
        exit(1);
    }
    echo " [PASS] Sensitive Data Masking in Logs Verified:\n";
    echo "        Log Description: {$log['description']}\n";
}

// Clean up
Database::query("DELETE FROM users WHERE email = 'test.onboard@ybt.com'");
Database::query("DELETE FROM businesses WHERE email = 'test.onboard@ybt.com'");
Database::query("DELETE FROM local_sessions WHERE user_email = 'test.onboard@ybt.com'");
Database::query("DELETE FROM activity_logs WHERE business_id = {$bizId}");

echo "\nMILESTONE 3 VERIFICATION 100% SUCCESSFUL!\n";
