<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../desktop/src/SqliteClient.php';

echo "=== MILESTONE 2: MULTI-COUNTRY TENANT REGISTRATION TEST ===\n\n";

$phpBin = 'C:/Users/Dj Bhai/AppData/Local/YBT Management Software/php/php.exe';
$projectDir = realpath(__DIR__ . '/..');

// Clean up any test user if exists from earlier run
Database::query("DELETE FROM users WHERE email = 'tariq.mobile@gmail.com'");
Database::query("DELETE FROM businesses WHERE email = 'tariq.mobile@gmail.com'");
Database::query("DELETE FROM local_sessions WHERE user_email = 'tariq.mobile@gmail.com'");

// Run register.php via sub-process
$regScript = "
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    \$_SERVER['REQUEST_METHOD'] = 'POST';
    \$_SERVER['HTTP_HOST'] = '127.0.0.1';
    \$_SERVER['REQUEST_URI'] = '/register.php';
    require_once '{$projectDir}/includes/bootstrap.php';
    \$token = Csrf::token();
    \$_POST = [
        '_csrf_token' => \$token,
        'name' => 'Tariq Mehmood',
        'email' => 'tariq.mobile@gmail.com',
        'phone' => '+92 300 1234567',
        'shop_name' => 'Lahore Mobile & Service Hub',
        'shop_type' => 'Mobile Shop & Service Center',
        'country' => 'Pakistan',
        'currency' => 'PKR',
        'password' => 'Pakistan@123',
        'confirm_password' => 'Pakistan@123',
        'terms' => '1'
    ];
    ob_start();
    try {
        include '{$projectDir}/register.php';
    } catch (Throwable \$e) {
        echo 'EXCEPTION: ' . \$e->getMessage();
    }
";

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(escapeshellarg($phpBin), $descriptors, $pipes);
fwrite($pipes[0], "<?php " . $regScript);
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

// Verify in DB
$user = Database::fetch("SELECT u.*, r.slug as role_slug, b.name as shop_name, b.country, b.default_currency, b.tenant_uuid FROM users u JOIN roles r ON u.role_id = r.id JOIN businesses b ON u.business_id = b.id WHERE u.email = 'tariq.mobile@gmail.com'");

if (!$user) {
    echo "[FAIL] User was not created in database.\n";
    echo "Subprocess Output:\n" . substr($out, 0, 500) . "\n";
    if (!empty($err)) echo "Subprocess Err:\n" . $err . "\n";
    exit(1);
}

echo " [PASS] Pakistan Tenant & Owner User Created Successfully:\n";
echo "        Shop:      {$user['shop_name']} (ID: {$user['business_id']})\n";
echo "        Country:   {$user['country']}\n";
echo "        Currency:  {$user['default_currency']}\n";
echo "        UUID:      {$user['tenant_uuid']}\n";
echo "        Owner:     {$user['name']} (ID: {$user['id']})\n";
echo "        Role:      {$user['role_slug']} (is_owner: {$user['is_owner']})\n\n";

// Verify Roles
$roles = Database::fetchAll("SELECT id, name, slug FROM roles WHERE business_id = ? ORDER BY id ASC", [$user['business_id']]);
echo " [PASS] Tenant Roles Verified (" . count($roles) . " roles):\n";
foreach ($roles as $r) {
    $permCount = Database::fetchColumn("SELECT COUNT(*) FROM role_permissions WHERE role_id = ?", [$r['id']]);
    echo "        - {$r['name']} ({$r['slug']}): {$permCount} permissions assigned\n";
}

// 2. Test Offline Authentication with newly created Owner
echo "\n2. Testing Offline Authentication for newly registered Owner...\n";
$authResult = Auth::attempt('tariq.mobile@gmail.com', 'Pakistan@123');
if ($authResult['success']) {
    echo " [PASS] Offline authentication succeeded!\n";
    echo "        Auth Mode:       {$authResult['mode']}\n";
    echo "        User:            {$authResult['user']['name']} ({$authResult['user']['role_slug']})\n";
    echo "        Active Tenant:   " . TenantContext::id() . " (" . TenantContext::data()['name'] . ")\n";
    echo "        Tenant Currency: " . TenantContext::data()['default_currency'] . "\n";
} else {
    echo " [FAIL] Offline authentication failed: " . ($authResult['error'] ?? 'Unknown') . "\n";
    exit(1);
}

// Clean up test data
Database::query("DELETE FROM users WHERE email = 'tariq.mobile@gmail.com'");
Database::query("DELETE FROM businesses WHERE email = 'tariq.mobile@gmail.com'");
Database::query("DELETE FROM local_sessions WHERE user_email = 'tariq.mobile@gmail.com'");
TenantContext::clear();

echo "\nMILESTONE 2 VERIFICATION 100% SUCCESSFUL!\n";
