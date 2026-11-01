<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';

echo "=== MILESTONE 5: RBAC PERMISSIONS & TENANT ISOLATION SUITE ===\n\n";

// 1. Unbound Tenant Context Fail-Closed Test
echo "1. Testing Fail-Closed Tenancy When Unbound...\n";
TenantContext::clear();
try {
    TenantContext::id();
    echo "[FAIL] Expected TenancyException was not thrown!\n";
    exit(1);
} catch (TenancyException $te) {
    echo " [PASS] Fail-closed violation correctly triggered: " . $te->getMessage() . "\n";
}

// 2. Cross-Tenant IDOR Guard Test
echo "\n2. Testing Cross-Tenant Access Assertions (IDOR Guard)...\n";
TenantContext::set(1, ['name' => 'Tenant 1']);
try {
    TenantContext::assertMatches(2); // Attempting to access Tenant 2 resource
    echo "[FAIL] Expected TenancyException was not thrown on IDOR attempt!\n";
    exit(1);
} catch (TenancyException $te) {
    echo " [PASS] Cross-tenant access strictly prohibited: " . $te->getMessage() . "\n";
}

// 3. Owner Wildcard Permissions Test
echo "\n3. Testing Owner Wildcard Permissions (*)...\n";
$_SESSION['user_id'] = 1;
$_SESSION['is_owner'] = true;
$_SESSION['role_slug'] = 'owner';
Permissions::clearCache();

if (!Permissions::has('any.arbitrary.permission') || !Permissions::has('settings.manage') || !Permissions::has('financial.reports')) {
    echo "[FAIL] Owner was denied arbitrary permission!\n";
    exit(1);
}
echo " [PASS] Owner possesses wildcard (*) permissions across all modules.\n";

// 4. Manager Granular Permissions Test
echo "\n4. Testing Manager Role Permission Boundaries...\n";
$_SESSION['user_id'] = 2;
$_SESSION['is_owner'] = false;
$_SESSION['role_slug'] = 'manager';
Permissions::clearCache();

if (!Permissions::has('customers.view') || !Permissions::has('invoices.create')) {
    echo "[FAIL] Manager was denied standard operational permissions!\n";
    exit(1);
}
if (Permissions::has('non_existent.super_admin_permission')) {
    echo "[FAIL] Manager was granted unauthorized permission!\n";
    exit(1);
}
echo " [PASS] Manager correctly granted operational rights and denied administrative overrides.\n";

// 5. Dynamic Permission Matrix Modification Test
echo "\n5. Testing Dynamic Permission Matrix Synchronization...\n";
$mgrRole = Database::fetch("SELECT id FROM roles WHERE slug = 'manager' LIMIT 1");
$mgrRoleId = (int)$mgrRole['id'];

// Fetch two permission IDs
$perm1 = Database::fetch("SELECT id FROM permissions WHERE `key` = 'customers.view'");
$perm2 = Database::fetch("SELECT id FROM permissions WHERE `key` = 'products.view'");

Permissions::syncRole($mgrRoleId, [(int)$perm1['id'], (int)$perm2['id']]);
Permissions::clearCache();

$hasCustomerView = Permissions::has('customers.view');
$hasProductView = Permissions::has('products.view');
$hasInvoiceEdit = Permissions::has('invoices.edit'); // Should now be false since excluded

if (!$hasCustomerView || !$hasProductView || $hasInvoiceEdit) {
    echo "[FAIL] Dynamic permission matrix update failed to reflect accurately.\n";
    exit(1);
}
echo " [PASS] Dynamic permission matrix successfully synchronized and active.\n";

// 6. Anti-Escalation Test: Forbid modifying Owner role permissions
echo "\n6. Testing Anti-Escalation (Attempting to modify Owner role permissions)...\n";
$ownerRole = Database::fetch("SELECT id FROM roles WHERE slug = 'owner' LIMIT 1");
$ownerRoleId = (int)$ownerRole['id'];

try {
    Permissions::syncRole($ownerRoleId, [(int)$perm1['id']]);
    echo "[FAIL] System allowed modifying Owner role permissions!\n";
    exit(1);
} catch (RuntimeException $re) {
    echo " [PASS] Owner permission modification strictly blocked: " . $re->getMessage() . "\n";
}

// Reset Manager permissions to full defaults
$allMgrPerms = Database::fetchAll("SELECT id FROM permissions WHERE `key` IN ('dashboard.view', 'customers.view', 'customers.create', 'customers.edit', 'products.view', 'products.create', 'invoices.view', 'invoices.create', 'payments.view', 'payments.create', 'expenses.view', 'expenses.create')");
$resetIds = array_map(fn($p) => (int)$p['id'], $allMgrPerms);
Permissions::syncRole($mgrRoleId, $resetIds);
Permissions::clearCache();

// Reset session to demo Owner
$_SESSION['user_id'] = 1;
$_SESSION['is_owner'] = true;
$_SESSION['role_slug'] = 'owner';
TenantContext::set(1, ['name' => 'YBT Technologies (Demo)']);

echo "\nMILESTONE 5 VERIFICATION 100% SUCCESSFUL!\n";
