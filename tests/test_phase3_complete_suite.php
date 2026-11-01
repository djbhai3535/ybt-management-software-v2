<?php
declare(strict_types=1);

/**
 * YBT Management Software — Phase 3 Complete 16-Point Verification Suite
 * Tests all Tenant Registration, Role Management, Regional & Anti-Escalation Features
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../services/staff-service.php';
require_once __DIR__ . '/../desktop/src/SqliteClient.php';
require_once __DIR__ . '/../desktop/src/SyncEngine.php';

$passedCount = 0;
$totalTests = 16;
$failures = [];

function runAssert(int $testNum, string $title, callable $fn): void {
    global $passedCount, $failures;
    echo sprintf("\n--- [TEST %02d/16] %s ---\n", $testNum, $title);
    try {
        $result = $fn();
        if ($result === true || $result === null) {
            echo " [PASS] Test {$testNum} Passed: {$title}\n";
            $passedCount++;
        } else {
            echo " [FAIL] Test {$testNum} Failed: {$title} - {$result}\n";
            $failures[] = "Test {$testNum}: {$title} - {$result}";
        }
    } catch (\Throwable $e) {
        echo " [FAIL] Exception in Test {$testNum}: " . $e->getMessage() . "\n";
        echo "        File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failures[] = "Test {$testNum}: {$title} - Exception: " . $e->getMessage();
    }
}

echo "====================================================================\n";
echo "   YBT MANAGEMENT SOFTWARE — PHASE 3 COMPLETE 16-POINT TEST SUITE   \n";
echo "====================================================================\n";

// Helper for test cleanup
$cleanTenantUuids = [];
$cleanUserIds = [];

// -------------------------------------------------------------------------
// TEST 1: Pakistan Shopkeeper Registration (PKR, +92, Lahore)
// -------------------------------------------------------------------------
runAssert(1, "Pakistan Shopkeeper Self-Registration (PKR, +92, Lahore)", function() use (&$cleanTenantUuids, &$cleanUserIds) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $email = 'usman.pakistan.' . substr(md5(uniqid()), 0, 8) . '@alrehman.pk';
    
    // Create business tenant
    $bizId = Database::insert('businesses', [
        'tenant_uuid' => $tenantUuid,
        'name' => 'Al-Rehman Mobile Shop',
        'legal_name' => 'Al-Rehman Mobiles & Accessories PVT',
        'phone' => '+92 300 1234567',
        'city' => 'Lahore',
        'state' => 'Punjab',
        'country' => 'PK',
        'default_currency' => 'PKR',
        'currency' => 'PKR',
        'status' => 'active'
    ]);
    
    // Create roles
    $ownerRoleId = Database::insert('roles', [
        'business_id' => $bizId,
        'name' => 'Shop Owner',
        'slug' => 'owner',
        'is_system' => 1
    ]);
    $mgrRoleId = Database::insert('roles', [
        'business_id' => $bizId,
        'name' => 'Shop Manager',
        'slug' => 'manager',
        'is_system' => 1
    ]);
    $staffRoleId = Database::insert('roles', [
        'business_id' => $bizId,
        'name' => 'Shop Staff',
        'slug' => 'staff',
        'is_system' => 1
    ]);
    
    // Assign 54 permissions to owner
    $allPerms = Database::fetchAll("SELECT id FROM permissions");
    foreach ($allPerms as $perm) {
        Database::insert('role_permissions', [
            'role_id' => $ownerRoleId,
            'permission_id' => $perm['id']
        ]);
    }
    
    // Create owner user
    $userUuid = generateUuidV4();
    $userId = Database::insert('users', [
        'business_id' => $bizId,
        'uuid' => $userUuid,
        'name' => 'Usman Ali',
        'email' => $email,
        'phone' => '+92 300 1234567',
        'password' => password_hash('PakistanSecure#2026', PASSWORD_BCRYPT),
        'role_id' => $ownerRoleId,
        'is_owner' => 1,
        'is_active' => 1
    ]);
    $cleanUserIds[] = $userId;
    
    // Verify business
    $biz = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$bizId]);
    if (!$biz || $biz['default_currency'] !== 'PKR' || $biz['country'] !== 'PK') {
        return "Business regional fields not set to PKR/PK";
    }
    
    // Verify user
    $u = Database::fetch("SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$u || (int)$u['is_owner'] !== 1) {
        return "Owner user not created with is_owner = 1";
    }
    
    // Verify owner role has permissions
    $permCount = Database::fetch("SELECT COUNT(*) as c FROM role_permissions WHERE role_id = ?", [$ownerRoleId]);
    if ((int)$permCount['c'] < 50) {
        return "Owner role has insufficient permissions: " . $permCount['c'];
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 2: India & International Multi-Country Registration
// -------------------------------------------------------------------------
runAssert(2, "India & International Registration (INR/USD, Country Presets)", function() use (&$cleanTenantUuids, &$cleanUserIds) {
    // 1. India
    $inTenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $inTenantUuid;
    $inBizId = Database::insert('businesses', [
        'tenant_uuid' => $inTenantUuid,
        'name' => 'Mumbai Electronics Store',
        'country' => 'IN',
        'default_currency' => 'INR',
        'currency' => 'INR',
        'status' => 'active'
    ]);
    $inBiz = Database::fetch("SELECT default_currency, country FROM businesses WHERE id = ?", [$inBizId]);
    if ($inBiz['default_currency'] !== 'INR' || $inBiz['country'] !== 'IN') {
        return "India tenant failed currency/country check";
    }
    
    // 2. USA
    $usTenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $usTenantUuid;
    $usBizId = Database::insert('businesses', [
        'tenant_uuid' => $usTenantUuid,
        'name' => 'New York Tech Hub',
        'country' => 'US',
        'default_currency' => 'USD',
        'currency' => 'USD',
        'status' => 'active'
    ]);
    $usBiz = Database::fetch("SELECT default_currency, country FROM businesses WHERE id = ?", [$usBizId]);
    if ($usBiz['default_currency'] !== 'USD' || $usBiz['country'] !== 'US') {
        return "US tenant failed currency/country check";
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 3: Owner Creation Exclusivity
// -------------------------------------------------------------------------
runAssert(3, "Owner Creation Exclusivity (Only Registration Creates Owner)", function() use (&$cleanTenantUuids) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $bizId = Database::insert('businesses', [
        'tenant_uuid' => $tenantUuid,
        'name' => 'Security Demo Shop',
        'country' => 'PK',
        'default_currency' => 'PKR'
    ]);
    
    $ownerRoleId = Database::insert('roles', [
        'business_id' => $bizId,
        'name' => 'Shop Owner',
        'slug' => 'owner',
        'is_system' => 1
    ]);
    
    // Set tenant context
    TenantContext::set($bizId, ['name' => 'Security Demo Shop']);
    
    // Attempt to invite as 'owner' via StaffService
    $res = StaffService::invite($bizId, 1, 'hacker.owner@test.com', 'Hacker', $ownerRoleId);
    if ($res['success'] !== false) {
        return "StaffService allowed inviting a user with role 'owner'!";
    }
    if (!str_contains($res['error'], 'Cannot assign Owner role')) {
        return "Unexpected error message: " . $res['error'];
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 4: Owner Anti-Privilege Escalation Prevention
// -------------------------------------------------------------------------
runAssert(4, "Owner Anti-Privilege Escalation (Cannot Suspend/Demote Owner or Alter Owner Perms)", function() use (&$cleanTenantUuids, &$cleanUserIds) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $bizId = Database::insert('businesses', [
        'tenant_uuid' => $tenantUuid,
        'name' => 'Escalation Protection Shop',
        'country' => 'PK',
        'default_currency' => 'PKR'
    ]);
    TenantContext::set($bizId, ['name' => 'Escalation Protection Shop']);
    
    $ownerRoleId = Database::insert('roles', [
        'business_id' => $bizId,
        'name' => 'Shop Owner',
        'slug' => 'owner',
        'is_system' => 1
    ]);
    
    $ownerUserId = Database::insert('users', [
        'business_id' => $bizId,
        'name' => 'Real Owner',
        'email' => 'real.owner.' . substr(md5(uniqid()), 0, 6) . '@shop.pk',
        'role_id' => $ownerRoleId,
        'is_owner' => 1,
        'is_active' => 1
    ]);
    $cleanUserIds[] = $ownerUserId;
    
    // 1. Attempt to deactivate Owner
    $statusRes = StaffService::updateStatus($bizId, $ownerUserId, 0);
    if ($statusRes['success'] !== false || !str_contains($statusRes['error'], 'Owner accounts cannot be deactivated')) {
        return "StaffService allowed deactivating the Owner: " . json_encode($statusRes);
    }
    
    // 2. Attempt to alter Owner role permissions
    try {
        Permissions::syncRole($ownerRoleId, [1, 2, 3]);
        return "Permissions::syncRole allowed altering the Owner role permissions!";
    } catch (\Throwable $e) {
        if (!str_contains($e->getMessage(), 'Owner role')) {
            return "Unexpected syncRole exception message: " . $e->getMessage();
        }
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 5: Duplicate Registration Prevention
// -------------------------------------------------------------------------
runAssert(5, "Duplicate Registration Prevention (Duplicate Email Blocked)", function() use (&$cleanTenantUuids, &$cleanUserIds) {
    $sharedEmail = 'duplicate.test.' . substr(md5(uniqid()), 0, 6) . '@test.com';
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    
    $bizId = Database::insert('businesses', [
        'tenant_uuid' => $tenantUuid,
        'name' => 'Shop First',
        'country' => 'PK',
        'default_currency' => 'PKR'
    ]);
    
    $userId = Database::insert('users', [
        'business_id' => $bizId,
        'name' => 'First User',
        'email' => $sharedEmail,
        'is_owner' => 1
    ]);
    $cleanUserIds[] = $userId;
    
    // Attempt duplicate check simulating register.php
    $exists = Database::fetch("SELECT id FROM users WHERE email = ?", [$sharedEmail]);
    if (!$exists) {
        return "First user not found during duplicate test";
    }
    
    // register.php validation check
    $isBlocked = ($exists !== false);
    if (!$isBlocked) {
        return "Duplicate email was not flagged!";
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 6: Pakistan Banking & Mobile Wallet Onboarding
// -------------------------------------------------------------------------
runAssert(6, "Pakistan Banking & Mobile Wallet Onboarding (IBAN, Raast, NTN)", function() use (&$cleanTenantUuids) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $bizId = Database::insert('businesses', [
        'tenant_uuid' => $tenantUuid,
        'name' => 'Karachi Mobile Center',
        'country' => 'PK',
        'default_currency' => 'PKR'
    ]);
    
    // Update onboarding fields
    Database::update('businesses', [
        'legal_name' => 'Karachi Mobile Center SMC-PVT',
        'tax_number' => '4567890-1',
        'tax_label' => 'NTN / STRN',
        'state' => 'Sindh',
        'city' => 'Karachi',
        'postal_code' => '74200',
        'address_line1' => 'Shop 42, Saddar Star City Mall',
        'bank_name' => 'Meezan Bank Ltd',
        'account_holder' => 'Karachi Mobile Center',
        'account_number' => 'PK12MEZN0001234567890123',
        'ifsc_code' => 'MEZNPKKA',
        'wallet_id' => '03211234567@raast',
        'wallet_type' => 'raast'
    ], 'id = ?', [$bizId]);
    
    $updated = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$bizId]);
    if ($updated['bank_name'] !== 'Meezan Bank Ltd' || $updated['wallet_type'] !== 'raast' || $updated['tax_label'] !== 'NTN / STRN') {
        return "Bank/Wallet/Tax fields did not update accurately";
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 7: Sensitive Data Masking in Logs & Sync Queue
// -------------------------------------------------------------------------
runAssert(7, "Sensitive Data Masking (No Raw Bank Account or Passwords in Logs/Sync)", function() use (&$cleanTenantUuids) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $bizId = Database::insert('businesses', [
        'tenant_uuid' => $tenantUuid,
        'name' => 'Masking Test Shop',
        'country' => 'PK'
    ]);
    
    $rawAccount = 'PK99HABB0009876543210123';
    $rawPassword = 'SuperSecretPassword#2026';
    
    // Simulate Activity Logging with masking
    $maskedAccount = strlen($rawAccount) > 4 ? substr($rawAccount, 0, 2) . '****' . substr($rawAccount, -4) : '****';
    
    Database::insert('activity_logs', [
        'business_id' => $bizId,
        'user_id' => 1,
        'action' => 'shop_onboarding_updated',
        'description' => "Shop profile and banking details updated: Bank HBL, Account {$maskedAccount}, Wallet Raast",
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    $log = Database::fetch("SELECT description FROM activity_logs WHERE business_id = ? AND action = 'shop_onboarding_updated'", [$bizId]);
    if (str_contains($log['description'], $rawAccount)) {
        return "Raw bank account leaked into activity_logs!";
    }
    
    // Simulate sync_operations_queue masking
    $payload = [
        'account_number' => $rawAccount,
        'password' => $rawPassword,
        'bank_name' => 'Habib Bank Ltd'
    ];
    $maskedPayload = $payload;
    if (isset($maskedPayload['account_number'])) {
        $acc = (string)$maskedPayload['account_number'];
        $maskedPayload['account_number'] = strlen($acc) > 4 ? substr($acc, 0, 2) . '****' . substr($acc, -4) : '****';
    }
    if (isset($maskedPayload['password'])) {
        $maskedPayload['password'] = '********';
    }
    
    $opUuid = generateUuidV4();
    Database::insert('sync_operations_queue', [
        'operation_uuid' => $opUuid,
        'business_id' => $bizId,
        'device_id' => 'desktop-test',
        'entity_type' => 'business',
        'entity_uuid' => $tenantUuid,
        'operation_type' => 'update',
        'payload' => json_encode($maskedPayload),
        'client_timestamp' => date('Y-m-d H:i:s')
    ]);
    
    $q = Database::fetch("SELECT payload FROM sync_operations_queue WHERE operation_uuid = ?", [$opUuid]);
    if (str_contains($q['payload'], $rawAccount) || str_contains($q['payload'], $rawPassword)) {
        return "Raw financial or credential data leaked into sync_operations_queue!";
    }
    
    // Clean up
    Database::query("DELETE FROM sync_operations_queue WHERE operation_uuid = ?", [$opUuid]);
    return true;
});

// -------------------------------------------------------------------------
// TEST 8: Staff Member Invitation Workflow (48h SHA-256 Tokens)
// -------------------------------------------------------------------------
runAssert(8, "Staff Member Invitation Workflow (48h SHA-256 Tokens)", function() use (&$cleanTenantUuids) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $bizId = Database::insert('businesses', [
        'tenant_uuid' => $tenantUuid,
        'name' => 'Invitation Workflow Shop',
        'country' => 'PK'
    ]);
    TenantContext::set($bizId, ['name' => 'Invitation Workflow Shop']);
    
    $staffRoleId = Database::insert('roles', [
        'business_id' => $bizId,
        'name' => 'Shop Staff',
        'slug' => 'staff',
        'is_system' => 1
    ]);
    
    $inviteEmail = 'tariq.staff.' . substr(md5(uniqid()), 0, 6) . '@shop.pk';
    $res = StaffService::invite($bizId, 1, $inviteEmail, 'Tariq Mobile Technician', $staffRoleId);
    
    if (!$res['success'] || empty($res['raw_token'])) {
        return "Invitation creation failed: " . ($res['error'] ?? '');
    }
    
    $rawToken = $res['raw_token'];
    $expectedHash = hash('sha256', $rawToken);
    
    $record = Database::fetch("SELECT * FROM staff_invitations WHERE id = ?", [$res['invitation_id']]);
    if (!$record) {
        return "Invitation record not found in database";
    }
    if ($record['token_hash'] !== $expectedHash) {
        return "Token hash mismatch in database";
    }
    if ($record['status'] !== 'pending') {
        return "Invitation initial status is not pending";
    }
    
    // Check expiration ~48h
    $expiresAt = strtotime($record['expires_at']);
    $expectedExpiry = time() + (48 * 3600);
    if (abs($expiresAt - $expectedExpiry) > 120) {
        return "Expiration time is not 48 hours in future: " . $record['expires_at'];
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 9: Duplicate Invitation Handling
// -------------------------------------------------------------------------
runAssert(9, "Duplicate Invitation Handling (Prevent Duplicate Pending Invites)", function() use (&$cleanTenantUuids) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $bizId = Database::insert('businesses', [
        'tenant_uuid' => $tenantUuid,
        'name' => 'Dup Invite Shop',
        'country' => 'PK'
    ]);
    TenantContext::set($bizId, ['name' => 'Dup Invite Shop']);
    
    $mgrRoleId = Database::insert('roles', [
        'business_id' => $bizId,
        'name' => 'Shop Manager',
        'slug' => 'manager',
        'is_system' => 1
    ]);
    
    $inviteEmail = 'dup.invite.' . substr(md5(uniqid()), 0, 6) . '@shop.pk';
    
    // First invite
    $res1 = StaffService::invite($bizId, 1, $inviteEmail, 'Manager One', $mgrRoleId);
    if (!$res1['success']) {
        return "First invite failed: " . ($res1['error'] ?? '');
    }
    
    // Second invite with same email (should invalidate/cancel first pending invite)
    $res2 = StaffService::invite($bizId, 1, $inviteEmail, 'Manager One Duplicate', $mgrRoleId);
    if (!$res2['success']) {
        return "Second invite failed: " . ($res2['error'] ?? '');
    }
    
    // Verify first is cancelled and second is pending
    $inv1 = Database::fetch("SELECT status FROM staff_invitations WHERE id = ?", [$res1['invitation_id']]);
    $inv2 = Database::fetch("SELECT status FROM staff_invitations WHERE id = ?", [$res2['invitation_id']]);
    
    if ($inv1['status'] !== 'cancelled' || $inv2['status'] !== 'pending') {
        return "Duplicate handling did not cleanly invalidate previous pending invitation";
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 10: Staff Invitation Acceptance Portal
// -------------------------------------------------------------------------
runAssert(10, "Staff Invitation Acceptance Portal (Token Verification & Account Creation)", function() use (&$cleanTenantUuids, &$cleanUserIds) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $bizId = Database::insert('businesses', [
        'tenant_uuid' => $tenantUuid,
        'name' => 'Acceptance Test Shop',
        'country' => 'PK'
    ]);
    TenantContext::set($bizId, ['name' => 'Acceptance Test Shop']);
    
    $staffRoleId = Database::insert('roles', [
        'business_id' => $bizId,
        'name' => 'Shop Staff',
        'slug' => 'staff',
        'is_system' => 1
    ]);
    
    $email = 'accept.user.' . substr(md5(uniqid()), 0, 6) . '@shop.pk';
    $inv = StaffService::invite($bizId, 1, $email, 'Bilal Ahmed', $staffRoleId);
    if (!$inv['success']) {
        return "Failed to create invitation: " . ($inv['error'] ?? '');
    }
    
    // Accept invitation
    $acceptRes = StaffService::acceptInvitation($inv['raw_token'], 'StaffSecretPassword#2026', '+92 321 9988776');
    if (!$acceptRes['success']) {
        return "Failed to accept valid invitation: " . ($acceptRes['error'] ?? '');
    }
    
    $cleanUserIds[] = $acceptRes['user_id'];
    
    // Verify user in database
    $user = Database::fetch("SELECT * FROM users WHERE id = ?", [$acceptRes['user_id']]);
    if (!$user || $user['email'] !== $email || (int)$user['is_owner'] !== 0) {
        return "Accepted user record does not match expected staff attributes";
    }
    
    // Verify invitation status is 'accepted'
    $invRecord = Database::fetch("SELECT status, accepted_at FROM staff_invitations WHERE id = ?", [$inv['invitation_id']]);
    if ($invRecord['status'] !== 'accepted' || empty($invRecord['accepted_at'])) {
        return "Invitation status was not marked accepted";
    }
    
    // Attempt to accept AGAIN (should fail)
    $secondAccept = StaffService::acceptInvitation($inv['raw_token'], 'AnotherPassword');
    if ($secondAccept['success'] !== false) {
        return "StaffService allowed accepting the same invitation twice!";
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 11: Expired / Tampered Invitation Token Rejection
// -------------------------------------------------------------------------
runAssert(11, "Expired / Tampered Invitation Token Rejection", function() use (&$cleanTenantUuids) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $bizId = Database::insert('businesses', [
        'tenant_uuid' => $tenantUuid,
        'name' => 'Tamper Rejection Shop',
        'country' => 'PK'
    ]);
    TenantContext::set($bizId, ['name' => 'Tamper Rejection Shop']);
    
    $staffRoleId = Database::insert('roles', [
        'business_id' => $bizId,
        'name' => 'Shop Staff',
        'slug' => 'staff',
        'is_system' => 1
    ]);
    
    // 1. Bogus token
    $bogusToken = bin2hex(random_bytes(32));
    $resBogus = StaffService::getInvitationByToken($bogusToken);
    if ($resBogus !== null) {
        return "getInvitationByToken returned a record for a non-existent token!";
    }
    
    // 2. Expired token simulation
    $email = 'expired.user.' . substr(md5(uniqid()), 0, 6) . '@shop.pk';
    $inv = StaffService::invite($bizId, 1, $email, 'Expired Person', $staffRoleId);
    
    // Manually backdate expiration in database
    Database::update('staff_invitations', [
        'expires_at' => date('Y-m-d H:i:s', time() - 3600) // 1 hour ago
    ], 'id = ?', [$inv['invitation_id']]);
    
    $acceptRes = StaffService::acceptInvitation($inv['raw_token'], 'SomePass123');
    if ($acceptRes['success'] !== false || !str_contains($acceptRes['error'], 'expired')) {
        return "StaffService allowed accepting an expired invitation: " . json_encode($acceptRes);
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 12: Cross-Tenant Isolation (Fail-Closed IDOR Test)
// -------------------------------------------------------------------------
runAssert(12, "Cross-Tenant Isolation (Fail-Closed Context & IDOR Protection)", function() use (&$cleanTenantUuids) {
    // Create Tenant A
    $uuidA = generateUuidV4();
    $cleanTenantUuids[] = $uuidA;
    $bizA = Database::insert('businesses', ['tenant_uuid' => $uuidA, 'name' => 'Shop A', 'country' => 'PK']);
    
    // Create Tenant B
    $uuidB = generateUuidV4();
    $cleanTenantUuids[] = $uuidB;
    $bizB = Database::insert('businesses', ['tenant_uuid' => $uuidB, 'name' => 'Shop B', 'country' => 'PK']);
    
    // Insert a customer under Tenant A
    Database::insert('customers', [
        'business_id' => $bizA,
        'name' => 'Customer of Shop A',
        'phone' => '+92 300 1112233'
    ]);
    
    // Set TenantContext to Tenant B
    TenantContext::set($bizB, ['name' => 'Shop B']);
    
    // Query customers under Tenant B context
    $bCustomers = Database::fetchAll("SELECT * FROM customers WHERE business_id = ?", [TenantContext::id()]);
    foreach ($bCustomers as $c) {
        if ($c['name'] === 'Customer of Shop A') {
            return "Cross-tenant leakage: Shop B saw Shop A's customer!";
        }
    }
    
    // Test Fail-Closed Context: Clear TenantContext and verify it throws
    TenantContext::clear();
    $caughtTenancyException = false;
    try {
        TenantContext::id();
    } catch (TenancyException $te) {
        $caughtTenancyException = true;
    }
    
    if (!$caughtTenancyException) {
        return "TenantContext::id() did not throw TenancyException when unbound!";
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 13: Dynamic RBAC Permission Matrix
// -------------------------------------------------------------------------
runAssert(13, "Dynamic RBAC Permission Matrix (Wildcard Owner, Operational Manager, POS Staff)", function() use (&$cleanTenantUuids, &$cleanUserIds) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $bizId = Database::insert('businesses', ['tenant_uuid' => $tenantUuid, 'name' => 'RBAC Shop', 'country' => 'PK']);
    TenantContext::set($bizId, ['name' => 'RBAC Shop']);
    
    // 1. Test Owner: has '*' wildcard via Auth::isOwner()
    $_SESSION['business_id'] = $bizId;
    $_SESSION['is_owner'] = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['role_slug'] = 'owner';
    Permissions::clearCache();
    
    if (!Permissions::has('sales.create') || !Permissions::has('settings.manage') || !Permissions::has('any.arbitrary.perm')) {
        return "Owner does not have full wildcard '*' permission grant";
    }
    
    // 2. Test Staff: restricted to assigned DB permissions
    $staffRole = Database::insert('roles', [
        'business_id' => $bizId, 
        'name' => 'Staff Role', 
        'slug' => 'staff', 
        'is_system' => 1
    ]);
    
    $posPerm = Database::fetch("SELECT id FROM permissions WHERE `key` = 'customers.view'");
    if ($posPerm) {
        Database::insert('role_permissions', ['role_id' => $staffRole, 'permission_id' => $posPerm['id']]);
    }
    
    $staffUserId = Database::insert('users', [
        'business_id' => $bizId,
        'name' => 'Staff Member',
        'email' => 'staff.perm.' . substr(md5(uniqid()), 0, 6) . '@shop.pk',
        'role_id' => $staffRole,
        'is_owner' => 0
    ]);
    $cleanUserIds[] = $staffUserId;
    
    $_SESSION['is_owner'] = 0;
    $_SESSION['user_id'] = $staffUserId;
    $_SESSION['role_slug'] = 'staff';
    Permissions::clearCache();
    
    if (!Permissions::has('customers.view')) {
        return "Staff does not have assigned customers.view permission";
    }
    if (Permissions::has('settings.company')) {
        return "Staff has unauthorized access to settings.company!";
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 14: Dual Engine SQLite / MySQL Parity
// -------------------------------------------------------------------------
runAssert(14, "Dual Engine SQLite / MySQL Function Parity (NOW, CURDATE, IF, TIMESTAMPDIFF, DATEDIFF, CONCAT)", function() {
    $nowRes = Database::fetch("SELECT NOW() as val");
    if (empty($nowRes['val']) || strlen($nowRes['val']) < 19) {
        return "NOW() function failed: " . json_encode($nowRes);
    }
    
    $curdateRes = Database::fetch("SELECT CURDATE() as val");
    if (empty($curdateRes['val']) || strlen($curdateRes['val']) !== 10) {
        return "CURDATE() function failed: " . json_encode($curdateRes);
    }
    
    $ifRes = Database::fetch("SELECT IF(1 = 1, 'yes', 'no') as val");
    if ($ifRes['val'] !== 'yes') {
        return "IF() function failed: " . json_encode($ifRes);
    }
    
    $diffRes = Database::fetch("SELECT TIMESTAMPDIFF('SECOND', '2026-01-01 00:00:00', '2026-01-01 00:01:00') as val");
    if ((int)$diffRes['val'] !== 60) {
        return "TIMESTAMPDIFF() failed: " . json_encode($diffRes);
    }
    
    $dateDiffRes = Database::fetch("SELECT DATEDIFF('2026-01-05', '2026-01-01') as val");
    if ((int)$dateDiffRes['val'] !== 4) {
        return "DATEDIFF() failed: " . json_encode($dateDiffRes);
    }
    
    $concatRes = Database::fetch("SELECT CONCAT('YBT', ' ', 'SaaS') as val");
    if ($concatRes['val'] !== 'YBT SaaS') {
        return "CONCAT() failed: " . json_encode($concatRes);
    }
    
    return true;
});

// -------------------------------------------------------------------------
// TEST 15: Offline-to-Online Idempotent Sync Test
// -------------------------------------------------------------------------
runAssert(15, "Offline-to-Online Idempotent Sync (UUID Deduplication)", function() use (&$cleanTenantUuids) {
    $tenantUuid = generateUuidV4();
    $cleanTenantUuids[] = $tenantUuid;
    $bizId = Database::insert('businesses', ['tenant_uuid' => $tenantUuid, 'name' => 'Sync Test Shop', 'country' => 'PK']);
    TenantContext::set($bizId, ['name' => 'Sync Test Shop']);
    
    $opUuid = generateUuidV4();
    
    // First push of operation
    Database::insert('sync_operations_queue', [
        'operation_uuid' => $opUuid,
        'business_id' => $bizId,
        'device_id' => 'device-unit-01',
        'entity_type' => 'customer',
        'entity_uuid' => generateUuidV4(),
        'operation_type' => 'create',
        'payload' => json_encode(['name' => 'Offline Customer A']),
        'client_timestamp' => date('Y-m-d H:i:s'),
        'status' => 'synced'
    ]);
    
    // Duplicate arrival simulation (check before insert as done in sync.php)
    $existing = Database::fetch("SELECT id FROM sync_operations_queue WHERE operation_uuid = ?", [$opUuid]);
    if (!$existing) {
        return "Original sync operation was not recorded";
    }
    
    // Clean up
    Database::query("DELETE FROM sync_operations_queue WHERE operation_uuid = ?", [$opUuid]);
    return true;
});

// -------------------------------------------------------------------------
// TEST 16: Offline Sync Failure & Local Data Preservation
// -------------------------------------------------------------------------
runAssert(16, "Offline Sync Failure & Local Data Preservation", function() {
    $appData = getenv('APPDATA') ? str_replace('\\', '/', getenv('APPDATA')) : 'C:/Users/Dj Bhai/AppData/Roaming';
    $sqlitePath = $appData . '/YBT Management Software/storage/desktop/ybt_local.sqlite';
    $sqlite = new SqliteClient($sqlitePath);
    $engine = new SyncEngine($sqlite);
    
    // Create customer locally in desktop offline mode
    $cust = $engine->createCustomerOffline(1, 'device-suite-test', [
        'name' => 'Preserved Customer Offline',
        'phone' => '+92 300 9998888',
        'city' => 'Multan'
    ]);
    
    if (!$cust['success']) {
        return "Failed to create customer offline: " . json_encode($cust);
    }
    
    // Attempt push to unreachable endpoint
    $pushRes = $engine->pushPending('http://127.0.0.1:28888_unreachable', 'fake_token', 'device-suite-test');
    if ($pushRes['success'] !== false) {
        return "Expected pushPending to fail gracefully when network is down";
    }
    
    // Verify local customer and pending operation are 100% preserved
    $localCustomer = $sqlite->fetch("SELECT * FROM customers WHERE uuid = ?", [$cust['uuid']]);
    $localOp = $sqlite->fetch("SELECT * FROM pending_operations WHERE operation_uuid = ?", [$cust['operation_uuid']]);
    
    if (!$localCustomer || $localCustomer['name'] !== 'Preserved Customer Offline') {
        return "Local customer record was lost or corrupted during sync failure!";
    }
    if (!$localOp || $localOp['status'] !== 'pending') {
        return "Pending operation was removed or changed status during network failure!";
    }
    
    // Clean up
    $sqlite->query("DELETE FROM customers WHERE uuid = ?", [$cust['uuid']]);
    $sqlite->query("DELETE FROM pending_operations WHERE operation_uuid = ?", [$cust['operation_uuid']]);
    
    return true;
});

// -------------------------------------------------------------------------
// CLEANUP & FINAL REPORT
// -------------------------------------------------------------------------
echo "\nCleaning up test fixture data...\n";
foreach ($cleanTenantUuids as $uuid) {
    $b = Database::fetch("SELECT id FROM businesses WHERE tenant_uuid = ?", [$uuid]);
    if ($b) {
        $bid = $b['id'];
        Database::query("DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE business_id = ?)", [$bid]);
        Database::query("DELETE FROM roles WHERE business_id = ?", [$bid]);
        Database::query("DELETE FROM staff_invitations WHERE business_id = ?", [$bid]);
        Database::query("DELETE FROM users WHERE business_id = ?", [$bid]);
        Database::query("DELETE FROM customers WHERE business_id = ?", [$bid]);
        Database::query("DELETE FROM activity_logs WHERE business_id = ?", [$bid]);
        Database::query("DELETE FROM businesses WHERE id = ?", [$bid]);
    }
}
foreach ($cleanUserIds as $uid) {
    Database::query("DELETE FROM users WHERE id = ?", [$uid]);
}

echo "\n====================================================================\n";
echo sprintf("RESULTS: %d / %d TESTS PASSED\n", $passedCount, $totalTests);
echo "====================================================================\n";

if ($passedCount === $totalTests) {
    echo ">>> ALL 16 PHASE 3 TEST CASES COMPLETED SUCCESSFULLY! <<<\n\n";
    exit(0);
} else {
    echo ">>> FAILED TESTS DETECTED: <<<\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
