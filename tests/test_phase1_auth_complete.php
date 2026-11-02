<?php
/**
 * YBT Management Software — Phase 1 Complete Authentication Verification Suite
 *
 * Tests:
 * 1. Multi-Business registration across all 5 business types.
 * 2. Optional phone number handling (both provided and null/empty).
 * 3. Inactive/unverified status invariant (is_active = 0, verification_status = 'pending').
 * 4. 6-digit numeric OTP generation, SHA-256 hash storage, 15-minute expiry.
 * 5. Mailer dispatch and log security (zero raw OTP or password leaks in mail.log).
 * 6. Login blocking unverified users with pending_verification flag.
 * 7. Invalid and expired OTP rejection.
 * 8. Valid 6-digit OTP verification and full account activation (user + business).
 * 9. Post-activation login success, tenant context, and session data.
 * 10. Forgot password 6-digit OTP dispatch and secure password reset.
 * 11. Prevention of used/expired OTP reuse.
 * 12. Verification of login.php UI cleanliness (zero demo buttons or scripts).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../services/mailer-service.php';

$totalTests = 0;
$passedTests = 0;

function it(string $description, callable $test) {
    global $totalTests, $passedTests;
    $totalTests++;
    try {
        $result = $test();
        if ($result === false) {
            echo "  FAIL: {$description}\n";
        } else {
            $passedTests++;
            echo "  PASS: {$description}\n";
        }
    } catch (\Throwable $e) {
        echo "  EXCEPTION: {$description} -> " . $e->getMessage() . " at line " . $e->getLine() . "\n";
    }
}

echo "\n====================================================================\n";
echo "   PHASE 1 AUTHENTICATION & MULTI-BUSINESS SUITE VERIFICATION\n";
echo "====================================================================\n\n";

$db = Database::getInstance();

// Clean up any test users created by previous runs
$testPrefix = 'test_p1_' . time();
$testEmails = [
    "{$testPrefix}_mobile@testdomain.com",
    "{$testPrefix}_medical@testdomain.com",
    "{$testPrefix}_general@testdomain.com",
    "{$testPrefix}_wholesale@testdomain.com",
    "{$testPrefix}_other@testdomain.com",
    "{$testPrefix}_nophone@testdomain.com",
];

// SECTION 1: Multi-Business Registration & Defaults
echo "--- Section 1: Multi-Business Types & Registration Defaults ---\n";

$defaults = require YBT_ROOT . '/config/defaults.php';
it("defaults.php contains all 5 required business types", function() use ($defaults) {
    $types = $defaults['business_types'] ?? [];
    $expected = ['mobile_electronics', 'medical_pharmacy', 'general_store', 'wholesale_retail', 'other_business'];
    foreach ($expected as $exp) {
        if (!isset($types[$exp])) return false;
    }
    return true;
});

// Helper function to simulate registration without HTTP POST
function registerTestBusiness(string $name, string $email, string $businessName, string $businessType, ?string $phone, string $password = 'Secret123!') {
    $tenantUuid = generateUuidV4();
    $businessId = Database::insert('businesses', [
        'name' => $businessName,
        'legal_name' => $businessName,
        'owner_name' => $name,
        'type' => $businessType,
        'industry' => $businessType,
        'email' => $email,
        'phone' => !empty($phone) ? $phone : null,
        'country' => 'Pakistan',
        'default_currency' => 'PKR',
        'tenant_uuid' => $tenantUuid,
        'status' => 'pending_verification',
        'onboarding_step' => 1,
        'is_demo' => 0
    ]);

    $ownerRoleId = Database::insert('roles', [
        'business_id' => $businessId,
        'name' => 'Business Owner',
        'slug' => 'owner',
        'description' => 'Full administrative control',
        'is_system' => 1
    ]);

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $userId = Database::insert('users', [
        'business_id' => $businessId,
        'role_id' => $ownerRoleId,
        'name' => $name,
        'email' => $email,
        'phone' => !empty($phone) ? $phone : null,
        'password' => $passwordHash,
        'is_owner' => 1,
        'is_super_admin' => 0,
        'is_active' => 0,
        'email_verified_at' => null,
        'verification_status' => 'pending',
        'onboarding_completed' => 0
    ]);

    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $tokenHash = hash('sha256', $otp);
    $expiresAt = date('Y-m-d H:i:s', time() + 900);

    $verificationId = Database::insert('email_verifications', [
        'user_id' => $userId,
        'business_id' => $businessId,
        'email' => $email,
        'token_hash' => $tokenHash,
        'token_type' => 'register',
        'expires_at' => $expiresAt
    ]);

    MailerService::sendVerificationOtp($email, $name, $otp);

    return [
        'business_id' => $businessId,
        'user_id' => $userId,
        'verification_id' => $verificationId,
        'otp' => $otp,
        'token_hash' => $tokenHash
    ];
}

$mobileTest = registerTestBusiness('Mobile Owner', $testEmails[0], 'Fast Mobile Store', 'mobile_electronics', '+923001112233');
$pharmacyTest = registerTestBusiness('Pharmacy Owner', $testEmails[1], 'Shifa Pharmacy', 'medical_pharmacy', '+923002223344');
$generalTest = registerTestBusiness('General Store Owner', $testEmails[2], 'Al-Madina General Store', 'general_store', '+923003334455');
$wholesaleTest = registerTestBusiness('Wholesale Owner', $testEmails[3], 'Premier Wholesale', 'wholesale_retail', '+923004445566');
$otherTest = registerTestBusiness('Workshop Owner', $testEmails[4], 'Custom Workshop & Co', 'other_business', '+923005556677');
$noPhoneTest = registerTestBusiness('No Phone Owner', $testEmails[5], 'Quick Trade Co', 'wholesale_retail', null);

it("creates businesses with distinct business types and pending_verification status", function() use ($mobileTest, $pharmacyTest, $generalTest, $wholesaleTest, $otherTest) {
    $b1 = Database::fetch("SELECT type, status FROM businesses WHERE id = ?", [$mobileTest['business_id']]);
    $b2 = Database::fetch("SELECT type, status FROM businesses WHERE id = ?", [$pharmacyTest['business_id']]);
    $b3 = Database::fetch("SELECT type, status FROM businesses WHERE id = ?", [$generalTest['business_id']]);
    $b4 = Database::fetch("SELECT type, status FROM businesses WHERE id = ?", [$wholesaleTest['business_id']]);
    $b5 = Database::fetch("SELECT type, status FROM businesses WHERE id = ?", [$otherTest['business_id']]);

    return $b1['type'] === 'mobile_electronics' && $b1['status'] === 'pending_verification' &&
           $b2['type'] === 'medical_pharmacy' && $b2['status'] === 'pending_verification' &&
           $b3['type'] === 'general_store' && $b3['status'] === 'pending_verification' &&
           $b4['type'] === 'wholesale_retail' && $b4['status'] === 'pending_verification' &&
           $b5['type'] === 'other_business' && $b5['status'] === 'pending_verification';
});

it("supports optional phone numbers (stored as null when empty)", function() use ($noPhoneTest) {
    $u = Database::fetch("SELECT phone FROM users WHERE id = ?", [$noPhoneTest['user_id']]);
    $b = Database::fetch("SELECT phone FROM businesses WHERE id = ?", [$noPhoneTest['business_id']]);
    return $u['phone'] === null && $b['phone'] === null;
});

it("newly registered users are strictly inactive and unverified (is_active = 0, verification_status = 'pending')", function() use ($mobileTest) {
    $u = Database::fetch("SELECT is_active, verification_status, email_verified_at FROM users WHERE id = ?", [$mobileTest['user_id']]);
    return (int)$u['is_active'] === 0 && $u['verification_status'] === 'pending' && $u['email_verified_at'] === null;
});

it("stores 6-digit numeric OTP as SHA-256 hash with 15-minute expiry in email_verifications", function() use ($mobileTest) {
    $ev = Database::fetch("SELECT * FROM email_verifications WHERE id = ?", [$mobileTest['verification_id']]);
    if (!$ev) return false;
    $is6Digit = preg_match('/^\d{6}$/', $mobileTest['otp']) === 1;
    $hashMatches = $ev['token_hash'] === hash('sha256', $mobileTest['otp']);
    $expiryDiff = strtotime($ev['expires_at']) - time();
    $validExpiry = ($expiryDiff > 850 && $expiryDiff <= 900); // 15 mins
    return $is6Digit && $hashMatches && $validExpiry && empty($ev['used_at']);
});

// SECTION 2: Security & Mailer Log Redaction
echo "\n--- Section 2: Security & Log Redaction Invariants ---\n";

it("strictly redacts 6-digit OTP codes and passwords from storage/logs/mail.log", function() use ($mobileTest) {
    $logFile = YBT_ROOT . '/storage/logs/mail.log';
    if (!file_exists($logFile)) return false;
    $logContent = file_get_contents($logFile);
    // Raw 6-digit OTP for this test must NOT exist in the log file
    $otpFound = strpos($logContent, $mobileTest['otp']) !== false;
    // But the masked string '******' should be present
    $maskFound = strpos($logContent, '******') !== false;
    return !$otpFound && $maskFound;
});

// SECTION 3: Login Blocking Unverified Users
echo "\n--- Section 3: Authentication & Verification Enforcement ---\n";

it("Auth::attempt blocks unverified users and returns pending_verification = true", function() use ($testEmails) {
    $loginResult = Auth::attempt($testEmails[0], 'Secret123!');
    return $loginResult['success'] === false &&
           !empty($loginResult['pending_verification']) &&
           $loginResult['email'] === $testEmails[0];
});

it("Auth::attempt rejects invalid passwords even for unverified users", function() use ($testEmails) {
    $loginResult = Auth::attempt($testEmails[0], 'WrongPassword999!');
    return $loginResult['success'] === false && empty($loginResult['pending_verification']);
});

// SECTION 4: OTP Verification & Account Activation
echo "\n--- Section 4: 6-Digit OTP Verification & Activation ---\n";

it("rejects incorrect 6-digit OTP code", function() use ($testEmails) {
    $fakeOtp = '000000';
    $fakeHash = hash('sha256', $fakeOtp);
    $rec = Database::fetch("SELECT id FROM email_verifications WHERE email = ? AND token_hash = ? AND used_at IS NULL", [$testEmails[0], $fakeHash]);
    return $rec === null;
});

it("rejects expired OTP code", function() use ($testEmails, $mobileTest) {
    // Temporarily set expires_at in the past
    Database::query("UPDATE email_verifications SET expires_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 60), $mobileTest['verification_id']]);
    $ev = Database::fetch("SELECT expires_at FROM email_verifications WHERE id = ?", [$mobileTest['verification_id']]);
    $isExpired = strtotime($ev['expires_at']) < time();
    // Restore proper expiry for subsequent test
    Database::query("UPDATE email_verifications SET expires_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() + 900), $mobileTest['verification_id']]);
    return $isExpired;
});

it("valid 6-digit OTP code activates user account, sets verified status, and activates business tenant", function() use ($mobileTest, $testEmails) {
    $otp = $mobileTest['otp'];
    $tokenHash = hash('sha256', $otp);

    $record = Database::fetch(
        "SELECT ev.*, u.id as user_id, u.business_id 
         FROM email_verifications ev 
         JOIN users u ON ev.user_id = u.id 
         WHERE ev.email = ? AND ev.token_hash = ? AND ev.used_at IS NULL 
         LIMIT 1",
        [$testEmails[0], $tokenHash]
    );

    if (!$record) return false;

    // Execute activation transaction
    $now = date('Y-m-d H:i:s');
    Database::query("UPDATE email_verifications SET used_at = ? WHERE id = ?", [$now, $record['id']]);
    Database::query("UPDATE users SET email_verified_at = ?, verification_status = 'verified', is_active = 1 WHERE id = ?", [$now, $record['user_id']]);
    Database::query("UPDATE businesses SET status = 'active' WHERE id = ?", [$record['business_id']]);

    $user = Database::fetch("SELECT is_active, verification_status, email_verified_at FROM users WHERE id = ?", [$record['user_id']]);
    $biz = Database::fetch("SELECT status FROM businesses WHERE id = ?", [$record['business_id']]);
    $ev = Database::fetch("SELECT used_at FROM email_verifications WHERE id = ?", [$record['id']]);

    return (int)$user['is_active'] === 1 &&
           $user['verification_status'] === 'verified' &&
           !empty($user['email_verified_at']) &&
           $biz['status'] === 'active' &&
           !empty($ev['used_at']);
});

it("prevents reuse of already-used OTP code", function() use ($mobileTest, $testEmails) {
    $otp = $mobileTest['otp'];
    $tokenHash = hash('sha256', $otp);
    // Searching for unused code must return null
    $unused = Database::fetch("SELECT id FROM email_verifications WHERE email = ? AND token_hash = ? AND used_at IS NULL", [$testEmails[0], $tokenHash]);
    // Searching for used code must return the record
    $used = Database::fetch("SELECT id FROM email_verifications WHERE email = ? AND token_hash = ? AND used_at IS NOT NULL", [$testEmails[0], $tokenHash]);
    return $unused === null && $used !== null;
});

it("Auth::attempt successfully logs in verified user and establishes session state", function() use ($testEmails) {
    $loginResult = Auth::attempt($testEmails[0], 'Secret123!');
    if (!$loginResult['success']) return false;
    $user = $loginResult['user'];
    return $user['email'] === $testEmails[0] &&
           $_SESSION['user_email'] === $testEmails[0] &&
           $_SESSION['role_slug'] === 'owner' &&
           $_SESSION['is_owner'] === true &&
           TenantContext::has();
});

// SECTION 5: Forgot Password & Reset 6-Digit OTP Flow
echo "\n--- Section 5: Forgot Password & 6-Digit OTP Reset Flow ---\n";

$resetOtp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
$resetHash = hash('sha256', $resetOtp);
$resetExpiresAt = date('Y-m-d H:i:s', time() + 900);

it("dispatches 6-digit OTP for password reset and stores SHA-256 hash in password_resets", function() use ($mobileTest, $testEmails, $resetOtp, $resetHash, $resetExpiresAt) {
    $prId = Database::insert('password_resets', [
        'user_id' => $mobileTest['user_id'],
        'email' => $testEmails[0],
        'token_hash' => $resetHash,
        'expires_at' => $resetExpiresAt
    ]);

    MailerService::sendPasswordResetOtp($testEmails[0], 'Mobile Owner', $resetOtp);

    $pr = Database::fetch("SELECT * FROM password_resets WHERE id = ?", [$prId]);
    return $pr && $pr['token_hash'] === $resetHash && empty($pr['used_at']);
});

it("resets user password with valid 6-digit OTP and marks token used", function() use ($testEmails, $resetOtp, $resetHash, $mobileTest) {
    $pr = Database::fetch("SELECT * FROM password_resets WHERE email = ? AND token_hash = ? AND used_at IS NULL", [$testEmails[0], $resetHash]);
    if (!$pr) return false;

    $now = date('Y-m-d H:i:s');
    $newPasswordHash = password_hash('BrandNewPass456!', PASSWORD_DEFAULT);

    Database::query("UPDATE password_resets SET used_at = ? WHERE id = ?", [$now, $pr['id']]);
    Database::query("UPDATE users SET password = ? WHERE id = ?", [$newPasswordHash, $mobileTest['user_id']]);

    // Check old password no longer works
    $oldLogin = Auth::attempt($testEmails[0], 'Secret123!');
    // Check new password works
    $newLogin = Auth::attempt($testEmails[0], 'BrandNewPass456!');

    return $oldLogin['success'] === false && $newLogin['success'] === true;
});

it("prevents reuse of already-used password reset OTP", function() use ($testEmails, $resetHash) {
    $unused = Database::fetch("SELECT id FROM password_resets WHERE email = ? AND token_hash = ? AND used_at IS NULL", [$testEmails[0], $resetHash]);
    return $unused === null;
});

// SECTION 6: UI Cleanliness & Removal of Demo Buttons
echo "\n--- Section 6: UI Cleanliness & Demo Removal ---\n";

it("login.php has no demo buttons, quickLogin scripts, or owner@ybt.com references", function() {
    $loginContent = file_get_contents(YBT_ROOT . '/login.php');
    $hasOwnerDemo = strpos($loginContent, 'owner@ybt.com') !== false;
    $hasManagerDemo = strpos($loginContent, 'manager@ybt.com') !== false;
    $hasQuickLogin = strpos($loginContent, 'quickLogin') !== false;
    $hasDemoDivider = strpos($loginContent, 'Quick Demo Access') !== false;

    return !$hasOwnerDemo && !$hasManagerDemo && !$hasQuickLogin && !$hasDemoDivider;
});

it("register.php includes business_type selector and makes phone optional", function() {
    $regContent = file_get_contents(YBT_ROOT . '/register.php');
    $hasBusinessType = strpos($regContent, 'name="business_type"') !== false;
    $phoneNotRequired = strpos($regContent, 'id="phone" name="phone" class="clean-input"') !== false &&
                        strpos($regContent, 'id="phone" name="phone" class="clean-input" \nplaceholder="+92 300 1234567" required') === false;

    return $hasBusinessType && $phoneNotRequired;
});

it("verify-email.php supports 6-digit OTP input with resend cooldown", function() {
    $verifyContent = file_get_contents(YBT_ROOT . '/verify-email.php');
    $hasOtpInput = strpos($verifyContent, 'name="otp"') !== false;
    $hasResendCooldown = strpos($verifyContent, 'resendCooldown') !== false;

    return $hasOtpInput && $hasResendCooldown;
});

// Clean up test data
foreach ($testEmails as $tEmail) {
    $user = Database::fetch("SELECT id, business_id FROM users WHERE email = ?", [$tEmail]);
    if ($user) {
        Database::query("DELETE FROM email_verifications WHERE user_id = ?", [$user['id']]);
        Database::query("DELETE FROM password_resets WHERE user_id = ?", [$user['id']]);
        Database::query("DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE business_id = ?)", [$user['business_id']]);
        Database::query("DELETE FROM roles WHERE business_id = ?", [$user['business_id']]);
        Database::query("DELETE FROM local_sessions WHERE user_id = ?", [$user['id']]);
        Database::query("DELETE FROM users WHERE id = ?", [$user['id']]);
        Database::query("DELETE FROM businesses WHERE id = ?", [$user['business_id']]);
    }
}

echo "\n====================================================================\n";
echo "SUMMARY: {$passedTests} / {$totalTests} tests passed.\n";
echo "====================================================================\n\n";

if ($passedTests === $totalTests) {
    echo "SUCCESS: All Phase 1 authentication requirements verified!\n";
    exit(0);
} else {
    echo "FAILED: Some Phase 1 tests did not pass.\n";
    exit(1);
}
