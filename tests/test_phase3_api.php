<?php
/**
 * YBT Management Software — Phase 3 Real HTTP API Test Suite
 * Tests all Phase 3 endpoints against live server on http://127.0.0.1:8088
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

class Phase3ApiTest {
    private string $baseUrl = 'http://127.0.0.1:8088/api/v1';
    private int $passed = 0;
    private int $failed = 0;

    public function run(): bool {
        echo "\n============================================================\n";
        echo "PHASE 3: REAL HTTP API ENDPOINTS TEST SUITE\n";
        echo "============================================================\n";

        $uniqueSuffix = bin2hex(random_bytes(3));
        $shopkeeperEmail = "shopkeeper_{$uniqueSuffix}@domain.com";
        $shopkeeperPassword = "ShopPassword123!";
        $shopName = "Mobile Hub {$uniqueSuffix}";

        // 1. Register Shopkeeper via API
        $regData = [
            'name' => 'Shopkeeper One',
            'email' => $shopkeeperEmail,
            'password' => $shopkeeperPassword,
            'shop_name' => $shopName,
            'shop_type' => 'Mobile Repair Shop',
            'phone' => '+919876543210',
            'country' => 'India'
        ];
        $regRes = $this->request('POST', '/auth/register', $regData);
        $this->assert("POST /auth/register creates new shopkeeper account (HTTP 201)", $regRes['status'] === 201 && !empty($regRes['body']['data']['requires_verification']));

        // 2. Attempt login before verification (should fail with 403 EMAIL_UNVERIFIED)
        $loginBeforeVerif = $this->request('POST', '/auth/login', [
            'email' => $shopkeeperEmail,
            'password' => $shopkeeperPassword
        ]);
        $this->assert("POST /auth/login blocked before verification (HTTP 403 EMAIL_UNVERIFIED)", $loginBeforeVerif['status'] === 403 && ($loginBeforeVerif['body']['error']['code'] ?? '') === 'EMAIL_UNVERIFIED');

        // 3. Resend verification cooldown check
        $resendCooldwn = $this->request('POST', '/auth/resend-verification', ['email' => $shopkeeperEmail]);
        $this->assert("POST /auth/resend-verification rate limit triggered (HTTP 429 COOLDOWN_ACTIVE)", $resendCooldwn['status'] === 429);

        // 4. Extract token directly from DB for verification
        $userRecord = Database::fetch("SELECT id FROM users WHERE email = ?", [$shopkeeperEmail]);
        $verifRecord = Database::fetch("SELECT id, token_hash FROM email_verifications WHERE user_id = ? AND used_at IS NULL", [$userRecord['id']]);

        // Create a known verification token for testing
        $testToken = bin2hex(random_bytes(32));
        $testTokenHash = hash('sha256', $testToken);
        Database::query("UPDATE email_verifications SET token_hash = ? WHERE id = ?", [$testTokenHash, $verifRecord['id']]);

        // 5. POST /auth/verify-email
        $verifyRes = $this->request('POST', '/auth/verify-email', ['token' => $testToken]);
        $this->assert("POST /auth/verify-email verifies account (HTTP 200)", $verifyRes['status'] === 200 && !empty($verifyRes['body']['data']['verified']));

        // 6. Login after verification
        $loginAfterVerif = $this->request('POST', '/auth/login', [
            'email' => $shopkeeperEmail,
            'password' => $shopkeeperPassword
        ]);
        $this->assert("POST /auth/login succeeds after verification (HTTP 200)", $loginAfterVerif['status'] === 200 && !empty($loginAfterVerif['body']['data']['access_token']));
        $bearerToken = $loginAfterVerif['body']['data']['access_token'] ?? '';

        // 7. GET /business/profile
        $profileRes = $this->request('GET', '/business/profile', null, $bearerToken);
        $this->assert("GET /business/profile returns shop profile (HTTP 200)", $profileRes['status'] === 200 && ($profileRes['body']['data']['business']['name'] ?? '') === $shopName);

        // 8. PUT /business/profile
        $updateProfile = $this->request('PUT', '/business/profile', [
            'legal_name' => "{$shopName} Pvt Ltd",
            'phone' => '+919988776655',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'default_currency' => 'INR',
            'invoice_prefix' => 'MBL-'
        ], $bearerToken);
        $this->assert("PUT /business/profile updates shop preferences (HTTP 200)", $updateProfile['status'] === 200 && ($updateProfile['body']['data']['business']['invoice_prefix'] ?? '') === 'MBL-');

        // 9. Staff Management: POST /staff/invite
        $staffEmail = "staff_{$uniqueSuffix}@domain.com";
        $ownerBizId = $loginAfterVerif['body']['data']['business']['id'];
        $mgrRoleId = Database::fetchColumn("SELECT id FROM roles WHERE business_id = ? AND slug = 'manager' LIMIT 1", [$ownerBizId]);

        $inviteRes = $this->request('POST', '/staff/invite', [
            'name' => 'Ali Khan Staff',
            'email' => $staffEmail,
            'role_id' => (int)$mgrRoleId
        ], $bearerToken);
        $this->assert("POST /staff/invite sends staff invitation (HTTP 201)", $inviteRes['status'] === 201);

        // 10. GET /staff
        $listStaff = $this->request('GET', '/staff', null, $bearerToken);
        $this->assert("GET /staff returns staff list and invitations (HTTP 200)", $listStaff['status'] === 200 && count($listStaff['body']['data']['invitations']) >= 1);

        // Retrieve raw token for invitation
        $invRecord = Database::fetch("SELECT id FROM staff_invitations WHERE email = ? AND status = 'pending'", [$staffEmail]);
        $testInviteToken = bin2hex(random_bytes(32));
        Database::query("UPDATE staff_invitations SET token_hash = ? WHERE id = ?", [hash('sha256', $testInviteToken), $invRecord['id']]);

        // 11. POST /auth/accept-invitation
        $acceptRes = $this->request('POST', '/auth/accept-invitation', [
            'token' => $testInviteToken,
            'password' => 'StaffPassword123!',
            'phone' => '+919900112233'
        ]);
        $this->assert("POST /auth/accept-invitation activates staff account (HTTP 200)", $acceptRes['status'] === 200 && !empty($acceptRes['body']['data']['user']['id']));
        $staffUserId = (int)$acceptRes['body']['data']['user']['id'];

        // 12. PUT /staff/status (deactivate staff)
        $deactRes = $this->request('PUT', '/staff/status', [
            'user_id' => $staffUserId,
            'is_active' => false
        ], $bearerToken);
        $this->assert("PUT /staff/status deactivates staff member (HTTP 200)", $deactRes['status'] === 200 && $deactRes['body']['data']['is_active'] === false);

        // 13. Verify deactivated staff cannot log in
        $staffLoginBlocked = $this->request('POST', '/auth/login', [
            'email' => $staffEmail,
            'password' => 'StaffPassword123!'
        ]);
        $this->assert("Deactivated staff login rejected (HTTP 403)", $staffLoginBlocked['status'] === 403);

        // 14. POST /auth/forgot-password
        $forgotRes = $this->request('POST', '/auth/forgot-password', ['email' => $shopkeeperEmail]);
        $this->assert("POST /auth/forgot-password generates recovery dispatch (HTTP 200)", $forgotRes['status'] === 200);

        // Set test reset token
        $testResetToken = bin2hex(random_bytes(32));
        $resetRecord = Database::fetch("SELECT id FROM password_resets WHERE email = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1", [$shopkeeperEmail]);
        Database::query("UPDATE password_resets SET token_hash = ? WHERE id = ?", [hash('sha256', $testResetToken), $resetRecord['id']]);

        // 15. POST /auth/reset-password
        $resetExec = $this->request('POST', '/auth/reset-password', [
            'token' => $testResetToken,
            'password' => 'BrandNewPassword123!'
        ]);
        $this->assert("POST /auth/reset-password updates password (HTTP 200)", $resetExec['status'] === 200);

        // 16. Login with new password
        $loginNewPass = $this->request('POST', '/auth/login', [
            'email' => $shopkeeperEmail,
            'password' => 'BrandNewPassword123!'
        ]);
        $this->assert("POST /auth/login succeeds with newly reset password (HTTP 200)", $loginNewPass['status'] === 200 && !empty($loginNewPass['body']['data']['access_token']));

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

    private function request(string $method, string $path, ?array $data = null, ?string $token = null): array {
        $ch = curl_init();
        $url = $this->baseUrl . $path;
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        if ($token) {
            $headers[] = "Authorization: Bearer {$token}";
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode((string)$response, true) ?? [];
        return ['status' => $status, 'body' => $body];
    }
}

$suite = new Phase3ApiTest();
$success = $suite->run();
exit($success ? 0 : 1);
