<?php
/**
 * DJ CRM Software 5.2 — Platform Super Administrator CLI Creator
 * 
 * Usage:
 *   php bin/create-admin.php --email=admin@domain.com --password=SecretPassword123 --name="Super Admin"
 * Or run interactively:
 *   php bin/create-admin.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be executed from the CLI.\n";
    exit(1);
}

define('YBT_ROOT', dirname(__DIR__));
require_once YBT_ROOT . '/includes/bootstrap.php';

echo "========================================================\n";
echo "   DJ CRM Software 5.2 — Super Admin CLI Setup\n";
echo "========================================================\n\n";

// Parse CLI Options
$options = getopt('', ['email::', 'password::', 'name::', 'help::']);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php bin/create-admin.php --email=admin@example.com --password=Secret123 --name=\"Admin Name\"\n\n";
    exit(0);
}

$email = $options['email'] ?? null;
$password = $options['password'] ?? null;
$name = $options['name'] ?? null;

// Interactive input if not provided
if (!$email) {
    echo "Enter Administrator Email: ";
    $email = trim(fgets(STDIN));
}
if (!$name) {
    echo "Enter Administrator Full Name (default: Super Administrator): ";
    $name = trim(fgets(STDIN));
    if (empty($name)) {
        $name = 'Super Administrator';
    }
}
if (!$password) {
    echo "Enter Administrator Password: ";
    $password = trim(fgets(STDIN));
}

$email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
if (!$email) {
    echo "\n[ERROR] Invalid email address format.\n";
    exit(1);
}

if (strlen($password) < 8) {
    echo "\n[ERROR] Password must be at least 8 characters long for production security.\n";
    exit(1);
}

try {
    $db = Database::getInstance();

    // 1. Ensure at least one business exists
    $biz = Database::fetch("SELECT id FROM businesses ORDER BY id ASC LIMIT 1");
    if (!$biz) {
        $bizUuid = bin2hex(random_bytes(16));
        $bizId = Database::insert('businesses', [
            'name' => 'DJ CRM Platform Administration',
            'business_type' => 'other',
            'country' => 'PK',
            'currency' => 'PKR',
            'default_currency' => 'PKR',
            'status' => 'active',
            'tenant_uuid' => $bizUuid,
            'onboarding_step' => 5
        ]);
        echo "✓ Created Platform Administration business entity (#{$bizId}).\n";
    } else {
        $bizId = (int)$biz['id'];
    }

    // 2. Ensure super_admin role exists
    $role = Database::fetch("SELECT id FROM roles WHERE slug = 'super_admin' LIMIT 1");
    if (!$role) {
        $roleId = Database::insert('roles', [
            'business_id' => $bizId,
            'name' => 'Super Administrator',
            'slug' => 'super_admin',
            'description' => 'Full unrestricted platform super admin privileges',
            'is_system' => 1
        ]);
        echo "✓ Created 'super_admin' system role (#{$roleId}).\n";
    } else {
        $roleId = (int)$role['id'];
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $existingUser = Database::fetch("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);

    if ($existingUser) {
        $userId = (int)$existingUser['id'];
        Database::query(
            "UPDATE users SET 
                role_id = ?, 
                name = ?, 
                password = ?, 
                is_super_admin = 1, 
                is_owner = 1, 
                is_active = 1, 
                verification_status = 'verified', 
                email_verified_at = NOW() 
             WHERE id = ?",
            [$roleId, $name, $passwordHash, $userId]
        );
        echo "\n[SUCCESS] Existing user '{$email}' has been promoted to Super Administrator.\n";
    } else {
        $userId = Database::insert('users', [
            'business_id' => $bizId,
            'role_id' => $roleId,
            'name' => $name,
            'email' => $email,
            'password' => $passwordHash,
            'is_super_admin' => 1,
            'is_owner' => 1,
            'is_active' => 1,
            'verification_status' => 'verified',
            'onboarding_completed' => 1,
            'email_verified_at' => date('Y-m-d H:i:s')
        ]);
        echo "\n[SUCCESS] New Super Administrator created successfully (User #{$userId}).\n";
    }

    echo "Account Details:\n";
    echo "  Email:    {$email}\n";
    echo "  Role:     Super Administrator\n";
    echo "  Portal:   " . APP_URL . "/admin/\n";
    echo "\nSetup completed successfully.\n";
    exit(0);
} catch (\Throwable $e) {
    echo "\n[FATAL ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
