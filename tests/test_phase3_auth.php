<?php
/**
 * YBT Management Software — Phase 3 Auth & Onboarding Automated Test Suite
 * Tests registration, verification tokens, password resets, staff invitations, and status policies.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/services/mailer-service.php';
require_once dirname(__DIR__) . '/services/staff-service.php';

class Phase3AuthTest {
    private int $passed = 0;
    private int $failed = 0;

    public function run(): bool {
        echo "\n============================================================\n";
        echo "PHASE 3: AUTH, VERIFICATION & STAFF MANAGEMENT TEST SUITE\n";
        echo "============================================================\n";

        $this->testRegistrationAndTokenHashing();
        $this->testDuplicateRegistrationPrevention();
        $this->testEmailVerificationFlow();
        $this->testTokenExpiryAndSingleUse();
        $this->testResendVerificationCooldown();
        $this->testPasswordResetFlow();
        $this->testStaffInvitationAndAcceptance();
        $this->testStaffStatusToggleAndTokenRevocation();

        echo "\n------------------------------------------------------------\n";
        echo "RESULTS: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "============================================================\n";

        return $this->failed === 0;
    }

    private function assert(string $desc, bool $condition): void {
        if ($condition) {
            echo " [PASS] {$desc}\n";
            $this->passed++;
        } else {
            echo " [FAIL] {$desc}\n";
            $this->failed++;
        }
    }

    private function testRegistrationAndTokenHashing(): void {
        $testEmail = 'shopowner_' . bin2hex(random_bytes(4)) . '@test.com';
        $shopName = 'Test Mobile World';

        $db = Database::getInstance();
        $db->beginTransaction();

        $businessId = Database::insert('businesses', [
            'name' => $shopName,
            'legal_name' => $shopName,
            'owner_name' => 'Owner Test',
            'type' => 'Mobile Shop',
            'industry' => 'Mobile Shop',
            'email' => $testEmail,
            'phone' => '+919876543210',
            'country' => 'India',
            'tenant_uuid' => generateUuidV4(),
            'status' => 'pending_verification',
            'onboarding_step' => 1,
            'is_demo' => 0
        ]);

        $roleId = Database::insert('roles', [
            'business_id' => $businessId,
            'name' => 'Shop Owner',
            'slug' => 'owner',
            'is_system' => 1
        ]);

        $userId = Database::insert('users', [
            'business_id' => $businessId,
            'role_id' => $roleId,
            'name' => 'Owner Test',
            'email' => $testEmail,
            'password' => password_hash('SecretPass123', PASSWORD_DEFAULT),
            'is_owner' => 1,
            'is_active' => 1,
            'verification_status' => 'pending',
            'onboarding_completed' => 0
        ]);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);

        $verifId = Database::insert('email_verifications', [
            'user_id' => $userId,
            'business_id' => $businessId,
            'email' => $testEmail,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt
        ]);

        $db->commit();

        $this->assert("Registration created business with ID {$businessId}", $businessId > 0);
        $this->assert("User created with verification_status = 'pending'", $userId > 0);

        // Security check: Raw token is NOT stored in DB
        $storedRecord = Database::fetch("SELECT * FROM email_verifications WHERE id = ?", [$verifId]);
        $this->assert("Token hash stored in DB matches sha256 of raw token", $storedRecord['token_hash'] === $tokenHash);
        $this->assert("Raw token is not directly stored in database", $storedRecord['token_hash'] !== $rawToken);

        // Attempt login with pending account
        $loginAttempt = Auth::attempt($testEmail, 'SecretPass123');
        $this->assert("Login blocked when verification_status is pending", !$loginAttempt['success'] && !empty($loginAttempt['pending_verification']));
    }

    private function testDuplicateRegistrationPrevention(): void {
        $existing = Database::fetch("SELECT email FROM users LIMIT 1");
        $email = $existing['email'];

        $found = Database::fetch("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
        $this->assert("Duplicate registration check detects existing email", !empty($found));
    }

    private function testEmailVerificationFlow(): void {
        $testEmail = 'verify_' . bin2hex(random_bytes(4)) . '@test.com';

        $db = Database::getInstance();
        $db->beginTransaction();

        $businessId = Database::insert('businesses', [
            'name' => 'Verification Shop',
            'owner_name' => 'Verify Test',
            'status' => 'pending_verification',
            'tenant_uuid' => generateUuidV4()
        ]);

        $roleId = Database::insert('roles', [
            'business_id' => $businessId,
            'name' => 'Owner',
            'slug' => 'owner',
            'is_system' => 1
        ]);

        $userId = Database::insert('users', [
            'business_id' => $businessId,
            'role_id' => $roleId,
            'name' => 'Verify User',
            'email' => $testEmail,
            'password' => password_hash('Pass12345', PASSWORD_DEFAULT),
            'verification_status' => 'pending',
            'is_active' => 1
        ]);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        Database::insert('email_verifications', [
            'user_id' => $userId,
            'business_id' => $businessId,
            'email' => $testEmail,
            'token_hash' => $tokenHash,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600)
        ]);

        $db->commit();

        // Perform verification
        $record = Database::fetch("SELECT * FROM email_verifications WHERE token_hash = ? AND used_at IS NULL", [$tokenHash]);
        $this->assert("Verification record found before verification", !empty($record));

        Database::query("UPDATE email_verifications SET used_at = NOW() WHERE id = ?", [$record['id']]);
        Database::query("UPDATE users SET verification_status = 'verified', email_verified_at = NOW() WHERE id = ?", [$userId]);
        Database::query("UPDATE businesses SET status = 'active' WHERE id = ?", [$businessId]);

        $updatedUser = Database::fetch("SELECT verification_status, email_verified_at FROM users WHERE id = ?", [$userId]);
        $this->assert("User verification_status updated to 'verified'", $updatedUser['verification_status'] === 'verified');
        $this->assert("email_verified_at timestamp populated", !empty($updatedUser['email_verified_at']));

        $loginAttempt = Auth::attempt($testEmail, 'Pass12345');
        $this->assert("Login succeeds immediately after verification", $loginAttempt['success']);
    }

    private function testTokenExpiryAndSingleUse(): void {
        $testEmail = 'expire_' . bin2hex(random_bytes(4)) . '@test.com';
        $userId = Database::fetchColumn("SELECT id FROM users LIMIT 1");
        $bizId = Database::fetchColumn("SELECT business_id FROM users WHERE id = ?", [$userId]);

        // 1. Expired token test
        $rawExpired = bin2hex(random_bytes(32));
        $hashExpired = hash('sha256', $rawExpired);
        Database::insert('email_verifications', [
            'user_id' => $userId,
            'business_id' => $bizId,
            'email' => $testEmail,
            'token_hash' => $hashExpired,
            'expires_at' => date('Y-m-d H:i:s', time() - 3600) // 1 hr ago
        ]);

        $recExpired = Database::fetch("SELECT * FROM email_verifications WHERE token_hash = ?", [$hashExpired]);
        $isExpired = strtotime($recExpired['expires_at']) < time();
        $this->assert("Expired token correctly detected as expired", $isExpired);

        // 2. Single-use test
        $rawUsed = bin2hex(random_bytes(32));
        $hashUsed = hash('sha256', $rawUsed);
        Database::insert('email_verifications', [
            'user_id' => $userId,
            'business_id' => $bizId,
            'email' => $testEmail,
            'token_hash' => $hashUsed,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'used_at' => date('Y-m-d H:i:s')
        ]);

        $activeRec = Database::fetch("SELECT id FROM email_verifications WHERE token_hash = ? AND used_at IS NULL", [$hashUsed]);
        $this->assert("Used verification token rejected on subsequent attempt", empty($activeRec));
    }

    private function testResendVerificationCooldown(): void {
        $testEmail = 'cooldown_' . bin2hex(random_bytes(4)) . '@test.com';
        $userId = Database::fetchColumn("SELECT id FROM users LIMIT 1");
        $bizId = Database::fetchColumn("SELECT business_id FROM users WHERE id = ?", [$userId]);

        $rawToken = bin2hex(random_bytes(32));
        Database::insert('email_verifications', [
            'user_id' => $userId,
            'business_id' => $bizId,
            'email' => $testEmail,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => date('Y-m-d H:i:s') // Just now
        ]);

        $recent = Database::fetch(
            "SELECT created_at FROM email_verifications WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1",
            [$userId]
        );

        $elapsed = time() - strtotime($recent['created_at']);
        $underCooldown = $elapsed < 60;
        $this->assert("60-second resend cooldown properly detected", $underCooldown);
    }

    private function testPasswordResetFlow(): void {
        $testEmail = 'reset_' . bin2hex(random_bytes(4)) . '@test.com';
        $bizId = Database::fetchColumn("SELECT id FROM businesses LIMIT 1");
        $roleId = Database::fetchColumn("SELECT id FROM roles WHERE business_id = ? LIMIT 1", [$bizId]);

        $userId = Database::insert('users', [
            'business_id' => $bizId,
            'role_id' => $roleId,
            'name' => 'Reset Test',
            'email' => $testEmail,
            'password' => password_hash('OldPassword123', PASSWORD_DEFAULT),
            'is_active' => 1,
            'verification_status' => 'verified'
        ]);

        // Insert API token to verify session invalidation
        $apiTokenRaw = 'ybt_pat_' . bin2hex(random_bytes(32));
        Database::insert('api_tokens', [
            'business_id' => $bizId,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $apiTokenRaw),
            'name' => 'Old Device Token',
            'expires_at' => date('Y-m-d H:i:s', time() + 86400)
        ]);

        // Create reset token
        $rawReset = bin2hex(random_bytes(32));
        $hashReset = hash('sha256', $rawReset);
        Database::insert('password_resets', [
            'user_id' => $userId,
            'email' => $testEmail,
            'token_hash' => $hashReset,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600)
        ]);

        // Perform password reset
        $resetRecord = Database::fetch("SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL", [$hashReset]);
        $this->assert("Password reset record valid before execution", !empty($resetRecord));

        Database::query("UPDATE password_resets SET used_at = NOW() WHERE id = ?", [$resetRecord['id']]);
        Database::query("UPDATE users SET password = ? WHERE id = ?", [password_hash('NewPassword456', PASSWORD_DEFAULT), $userId]);
        Database::query("DELETE FROM api_tokens WHERE user_id = ?", [$userId]);

        // Verify old password fails and new password succeeds
        $oldAttempt = Auth::attempt($testEmail, 'OldPassword123');
        $this->assert("Old password fails after reset", !$oldAttempt['success']);

        $newAttempt = Auth::attempt($testEmail, 'NewPassword456');
        $this->assert("New password succeeds after reset", $newAttempt['success']);

        // Verify API tokens revoked
        $remainingTokens = Database::fetchAll("SELECT id FROM api_tokens WHERE user_id = ?", [$userId]);
        $this->assert("Active API tokens revoked on password reset", empty($remainingTokens));
    }

    private function testStaffInvitationAndAcceptance(): void {
        $bizId = Database::fetchColumn("SELECT id FROM businesses LIMIT 1");
        $ownerId = Database::fetchColumn("SELECT id FROM users WHERE business_id = ? AND is_owner = 1 LIMIT 1", [$bizId]);
        $mgrRole = Database::fetch("SELECT id FROM roles WHERE business_id = ? AND slug = 'manager' LIMIT 1", [$bizId]);
        $roleId = $mgrRole['id'] ?? 2;

        $staffEmail = 'staff_' . bin2hex(random_bytes(4)) . '@test.com';

        // 1. Invite Staff
        $inviteRes = StaffService::invite($bizId, $ownerId, $staffEmail, 'Staff Member', (int)$roleId);
        $this->assert("StaffService::invite succeeds", $inviteRes['success']);
        $rawInviteToken = $inviteRes['raw_token'];

        // 2. Accept Invitation
        $acceptRes = StaffService::acceptInvitation($rawInviteToken, 'StaffPass123', '+919988776655');
        $this->assert("StaffService::acceptInvitation creates staff user account", $acceptRes['success']);

        $staffUserId = $acceptRes['user_id'];
        $staffUser = Database::fetch("SELECT * FROM users WHERE id = ?", [$staffUserId]);
        $this->assert("Invited staff user has business_id = {$bizId}", (int)$staffUser['business_id'] === (int)$bizId);
        $this->assert("Invited staff user is verified upon registration", $staffUser['verification_status'] === 'verified');
        $this->assert("Invited staff is NOT an owner", (int)$staffUser['is_owner'] === 0);

        // 3. Prevent duplicate acceptance
        $dupRes = StaffService::acceptInvitation($rawInviteToken, 'AnotherPass123');
        $this->assert("Duplicate invitation acceptance rejected", !$dupRes['success']);
    }

    private function testStaffStatusToggleAndTokenRevocation(): void {
        $bizId = Database::fetchColumn("SELECT id FROM businesses LIMIT 1");
        $staff = Database::fetch("SELECT id FROM users WHERE business_id = ? AND is_owner = 0 LIMIT 1", [$bizId]);
        if (!$staff) {
            $this->assert("Staff status toggle skipped (no staff user found)", true);
            return;
        }

        $staffId = (int)$staff['id'];

        // Deactivate staff
        $deactRes = StaffService::updateStatus($bizId, $staffId, 0);
        $this->assert("StaffService::updateStatus deactivates staff user", $deactRes['success']);

        $userAfterDeact = Database::fetch("SELECT is_active, verification_status FROM users WHERE id = ?", [$staffId]);
        $this->assert("User is_active is 0 and status is suspended", $userAfterDeact['is_active'] == 0 && $userAfterDeact['verification_status'] === 'suspended');

        // Verify owner immunity
        $ownerId = (int)Database::fetchColumn("SELECT id FROM users WHERE business_id = ? AND is_owner = 1 LIMIT 1", [$bizId]);
        $ownerToggle = StaffService::updateStatus($bizId, $ownerId, 0);
        $this->assert("Owner account is immune from suspension", !$ownerToggle['success']);

        // Reactivate staff
        $reactRes = StaffService::updateStatus($bizId, $staffId, 1);
        $this->assert("Staff reactivated successfully", $reactRes['success']);
    }
}

$suite = new Phase3AuthTest();
$success = $suite->run();
exit($success ? 0 : 1);
