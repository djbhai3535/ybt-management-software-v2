<?php
/**
 * YBT Management Software — REST API v1 Authentication & Device Binding
 * Supports Shopkeeper Registration, Email Verification, Password Reset, and Staff Invitations.
 */

require_once dirname(__DIR__, 2) . '/services/mailer-service.php';
require_once dirname(__DIR__, 2) . '/services/staff-service.php';

$method = $_SERVER['REQUEST_METHOD'];
$subRoute = substr($route, strlen('/auth'));
$subRoute = '/' . trim($subRoute, '/');

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// 1. POST /auth/register — Shopkeeper Registration
if ($subRoute === '/register' && $method === 'POST') {
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $shopName = trim($input['shop_name'] ?? '');
    $shopType = trim($input['shop_type'] ?? 'Mobile Shop');
    $phone = trim($input['phone'] ?? '');
    $country = trim($input['country'] ?? 'India');

    if (empty($name) || empty($email) || empty($password) || empty($shopName) || empty($phone)) {
        apiError('Full name, email, password, shop name, and phone are required.', 'VALIDATION_FAILED', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError('Invalid email address format.', 'VALIDATION_FAILED', 422);
    }

    if (strlen($password) < 8) {
        apiError('Password must be at least 8 characters long.', 'VALIDATION_FAILED', 422);
    }

    $existing = Database::fetch("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
    if ($existing) {
        apiError('An account with this email address already exists.', 'EMAIL_EXISTS', 409);
    }

    $db = Database::getInstance();
    $db->beginTransaction();

    try {
        $tenantUuid = generateUuidV4();

        // 1. Create Business
        $businessId = Database::insert('businesses', [
            'name' => $shopName,
            'legal_name' => $shopName,
            'owner_name' => $name,
            'type' => $shopType,
            'industry' => $shopType,
            'email' => $email,
            'phone' => $phone,
            'country' => $country,
            'tenant_uuid' => $tenantUuid,
            'status' => 'pending_verification',
            'onboarding_step' => 1,
            'is_demo' => 0
        ]);

        // 2. Create Roles
        $ownerRoleId = Database::insert('roles', [
            'business_id' => $businessId,
            'name' => 'Shop Owner',
            'slug' => 'owner',
            'description' => 'Full administrative access and financial control',
            'is_system' => 1
        ]);

        $managerRoleId = Database::insert('roles', [
            'business_id' => $businessId,
            'name' => 'Shop Manager',
            'slug' => 'manager',
            'description' => 'Operational management and daily billing',
            'is_system' => 1
        ]);

        $staffRoleId = Database::insert('roles', [
            'business_id' => $businessId,
            'name' => 'Shop Staff',
            'slug' => 'staff',
            'description' => 'Point of sale, customer queries, and repair intake',
            'is_system' => 1
        ]);

        // Permissions for Owner
        $allPermissions = Database::fetchAll("SELECT id FROM permissions");
        foreach ($allPermissions as $perm) {
            Database::insert('role_permissions', [
                'role_id' => $ownerRoleId,
                'permission_id' => $perm['id']
            ]);
        }

        // 3. Create User Account
        $userId = Database::insert('users', [
            'business_id' => $businessId,
            'role_id' => $ownerRoleId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'is_owner' => 1,
            'is_active' => 1,
            'verification_status' => 'pending',
            'onboarding_completed' => 0
        ]);

        // 4. Create Verification Token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + (24 * 3600));

        Database::insert('email_verifications', [
            'user_id' => $userId,
            'business_id' => $businessId,
            'email' => $email,
            'token_hash' => $tokenHash,
            'token_type' => 'register',
            'expires_at' => $expiresAt
        ]);

        $db->commit();

        MailerService::sendVerificationEmail($email, $name, $rawToken);

        ActivityLog::log($businessId, $userId, 'registration_initiated', 'user', $userId, "Shopkeeper registered via API: {$name} ({$shopName})");

        apiResponse([
            'message' => 'Registration successful! Please check your email to verify your account.',
            'email' => $email,
            'shop_name' => $shopName,
            'requires_verification' => true
        ], 201);

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('API registration error: ' . $e->getMessage());
        apiError('Registration failed: ' . $e->getMessage(), 'SERVER_ERROR', 500);
    }
}

// 2. POST /auth/verify-email
if ($subRoute === '/verify-email' && $method === 'POST') {
    $token = trim($input['token'] ?? '');
    if ($token === '') {
        apiError('Verification token is required.', 'VALIDATION_FAILED', 422);
    }

    $tokenHash = hash('sha256', $token);
    $record = Database::fetch(
        "SELECT ev.*, u.id as user_id, u.business_id 
         FROM email_verifications ev
         JOIN users u ON ev.user_id = u.id
         WHERE ev.token_hash = ? AND ev.used_at IS NULL
         LIMIT 1",
        [$tokenHash]
    );

    if (!$record) {
        apiError('Invalid or already used verification token.', 'INVALID_TOKEN', 400);
    }

    if (strtotime($record['expires_at']) < time()) {
        apiError('Verification token has expired. Please request a new one.', 'TOKEN_EXPIRED', 400);
    }

    $db = Database::getInstance();
    $db->beginTransaction();

    try {
        Database::query("UPDATE email_verifications SET used_at = NOW() WHERE id = ?", [$record['id']]);
        Database::query("UPDATE users SET email_verified_at = NOW(), verification_status = 'verified', is_active = 1 WHERE id = ?", [$record['user_id']]);
        Database::query("UPDATE businesses SET status = 'active' WHERE id = ?", [$record['business_id']]);

        $db->commit();

        ActivityLog::log($record['business_id'], $record['user_id'], 'email_verified', 'user', $record['user_id'], 'Email verified via API');

        apiResponse([
            'message' => 'Email verified successfully. You can now log in.',
            'verified' => true
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        apiError('Account verification failed.', 'SERVER_ERROR', 500);
    }
}

// 3. POST /auth/resend-verification
if ($subRoute === '/resend-verification' && $method === 'POST') {
    $email = strtolower(trim($input['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError('A valid email address is required.', 'VALIDATION_FAILED', 422);
    }

    $user = Database::fetch(
        "SELECT id, name, business_id, verification_status FROM users WHERE email = ? LIMIT 1",
        [$email]
    );

    $genericMsg = 'If an unverified account exists for this email, a new verification link has been sent.';

    if (!$user || $user['verification_status'] === 'verified') {
        apiResponse(['message' => $genericMsg]);
    }

    if ($user['verification_status'] === 'suspended') {
        apiError('This account has been suspended.', 'ACCOUNT_SUSPENDED', 403);
    }

    $recent = Database::fetch(
        "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds 
         FROM email_verifications WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1",
        [$user['id']]
    );

    if ($recent && $recent['elapsed_seconds'] !== null) {
        $elapsed = (int)$recent['elapsed_seconds'];
        if ($elapsed < 60) {
            $rem = 60 - $elapsed;
            apiError("Please wait {$rem} second(s) before requesting another email.", 'COOLDOWN_ACTIVE', 429);
        }
    }

    Database::query("DELETE FROM email_verifications WHERE user_id = ? AND used_at IS NULL", [$user['id']]);

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + (24 * 3600));

    Database::insert('email_verifications', [
        'user_id' => $user['id'],
        'business_id' => $user['business_id'],
        'email' => $email,
        'token_hash' => $tokenHash,
        'token_type' => 'register',
        'expires_at' => $expiresAt
    ]);

    MailerService::sendVerificationEmail($email, $user['name'], $rawToken);

    apiResponse(['message' => $genericMsg]);
}

// 4. POST /auth/forgot-password
if ($subRoute === '/forgot-password' && $method === 'POST') {
    $email = strtolower(trim($input['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError('A valid email address is required.', 'VALIDATION_FAILED', 422);
    }

    $user = Database::fetch(
        "SELECT id, business_id, name, is_active, verification_status FROM users WHERE email = ? LIMIT 1",
        [$email]
    );

    $genericMsg = 'If an account matches that email address, password reset instructions have been sent.';

    if (!$user || !$user['is_active'] || $user['verification_status'] === 'suspended') {
        apiResponse(['message' => $genericMsg]);
    }

    $recent = Database::fetch(
        "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds 
         FROM password_resets WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1",
        [$user['id']]
    );

    if ($recent && $recent['elapsed_seconds'] !== null) {
        $elapsed = (int)$recent['elapsed_seconds'];
        if ($elapsed < 60) {
            $rem = 60 - $elapsed;
            apiError("Please wait {$rem} second(s) before requesting another reset email.", 'COOLDOWN_ACTIVE', 429);
        }
    }

    Database::query("DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL", [$user['id']]);

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);

    Database::insert('password_resets', [
        'user_id' => $user['id'],
        'email' => $email,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt
    ]);

    MailerService::sendPasswordResetEmail($email, $user['name'], $rawToken);

    apiResponse(['message' => $genericMsg]);
}

// 5. POST /auth/reset-password
if ($subRoute === '/reset-password' && $method === 'POST') {
    $token = trim($input['token'] ?? '');
    $password = $input['password'] ?? '';

    if ($token === '' || $password === '') {
        apiError('Token and new password are required.', 'VALIDATION_FAILED', 422);
    }

    if (strlen($password) < 8) {
        apiError('Password must be at least 8 characters long.', 'VALIDATION_FAILED', 422);
    }

    $tokenHash = hash('sha256', $token);
    $resetRecord = Database::fetch(
        "SELECT pr.*, u.id as user_id, u.business_id, u.is_active 
         FROM password_resets pr
         JOIN users u ON pr.user_id = u.id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL
         LIMIT 1",
        [$tokenHash]
    );

    if (!$resetRecord) {
        apiError('Invalid or already used password reset link.', 'INVALID_TOKEN', 400);
    }

    if (strtotime($resetRecord['expires_at']) < time()) {
        apiError('Password reset link has expired.', 'TOKEN_EXPIRED', 400);
    }

    $db = Database::getInstance();
    $db->beginTransaction();

    try {
        Database::query("UPDATE password_resets SET used_at = NOW() WHERE id = ?", [$resetRecord['id']]);
        Database::query("UPDATE users SET password = ?, failed_login_count = 0, locked_until = NULL WHERE id = ?", [
            password_hash($password, PASSWORD_DEFAULT),
            $resetRecord['user_id']
        ]);
        Database::query("DELETE FROM api_tokens WHERE user_id = ?", [$resetRecord['user_id']]);

        $db->commit();

        ActivityLog::log($resetRecord['business_id'], $resetRecord['user_id'], 'password_changed', 'user', $resetRecord['user_id'], 'Password reset via API');

        apiResponse(['message' => 'Password has been reset successfully.']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        apiError('Failed to reset password.', 'SERVER_ERROR', 500);
    }
}

// 6. POST /auth/accept-invitation
if ($subRoute === '/accept-invitation' && $method === 'POST') {
    $token = trim($input['token'] ?? '');
    $password = $input['password'] ?? '';
    $phone = trim($input['phone'] ?? '');

    if ($token === '' || $password === '') {
        apiError('Invitation token and password are required.', 'VALIDATION_FAILED', 422);
    }

    $result = StaffService::acceptInvitation($token, $password, $phone ?: null);
    if (!$result['success']) {
        apiError($result['error'], 'INVITATION_FAILED', 400);
    }

    apiResponse([
        'message' => 'Invitation accepted successfully.',
        'user' => [
            'id' => $result['user_id'],
            'name' => $result['name'],
            'email' => $result['email'],
            'role' => $result['role_slug']
        ]
    ]);
}

// 7. POST /auth/login
if ($subRoute === '/login' && $method === 'POST') {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $deviceId = trim($input['device_id'] ?? '');
    $deviceName = trim($input['device_name'] ?? 'Windows Desktop Client');
    $platform = trim($input['platform'] ?? 'windows');

    if ($email === '' || $password === '') {
        apiError('Email and password are required.', 'INVALID_CREDENTIALS', 422);
    }

    $user = Database::fetch(
        "SELECT u.*, b.name as business_name, b.is_demo, b.default_currency, b.status as business_status, r.slug as role_slug
         FROM users u
         JOIN businesses b ON u.business_id = b.id
         JOIN roles r ON u.role_id = r.id
         WHERE u.email = ? LIMIT 1",
        [$email]
    );

    if (!$user || !password_verify($password, $user['password'])) {
        apiError('Invalid email or password.', 'INVALID_CREDENTIALS', 401);
    }

    if (!$user['is_active']) {
        apiError('This account has been deactivated.', 'ACCOUNT_DEACTIVATED', 403);
    }

    if (($user['verification_status'] ?? '') === 'suspended' || ($user['business_status'] ?? '') === 'suspended') {
        apiError('Your account or business has been suspended.', 'ACCOUNT_SUSPENDED', 403);
    }

    if (($user['verification_status'] ?? 'verified') === 'pending') {
        apiError('Please verify your email address before signing in.', 'EMAIL_UNVERIFIED', 403);
    }

    // Generate Bearer Token
    $rawToken = 'ybt_pat_' . bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + (30 * 86400)); // 30 days

    Database::insert('api_tokens', [
        'business_id' => $user['business_id'],
        'user_id' => $user['id'],
        'token_hash' => $tokenHash,
        'name' => $deviceName,
        'device_id' => $deviceId ?: null,
        'abilities' => json_encode(['*']),
        'expires_at' => $expiresAt,
    ]);

    if ($deviceId !== '') {
        $existingDevice = Database::fetch(
            "SELECT id FROM sync_devices WHERE business_id = ? AND device_id = ?",
            [$user['business_id'], $deviceId]
        );
        if ($existingDevice) {
            Database::query(
                "UPDATE sync_devices SET device_name = ?, last_sync_at = NOW(), is_active = 1 WHERE id = ?",
                [$deviceName, $existingDevice['id']]
            );
        } else {
            Database::insert('sync_devices', [
                'business_id' => $user['business_id'],
                'user_id' => $user['id'],
                'device_id' => $deviceId,
                'device_name' => $deviceName,
                'platform' => $platform,
                'is_active' => 1,
                'last_sync_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    ActivityLog::log($user['business_id'], $user['id'], 'api_login', 'user', $user['id'], "Logged in via API ({$deviceName})");

    apiResponse([
        'access_token' => $rawToken,
        'token_type' => 'Bearer',
        'expires_in' => 30 * 86400,
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role_slug'],
            'is_owner' => (bool)$user['is_owner'],
            'onboarding_completed' => (bool)($user['onboarding_completed'] ?? 1),
        ],
        'business' => [
            'id' => (int)$user['business_id'],
            'name' => $user['business_name'],
            'currency' => $user['default_currency'] ?? 'INR',
            'is_demo' => (bool)$user['is_demo'],
        ]
    ]);
}

// 8. GET /auth/me
if ($subRoute === '/me' && $method === 'GET') {
    $tenantId = TenantContext::id();
    $business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$tenantId]);

    apiResponse([
        'user' => $authenticatedUser,
        'business' => $business,
    ]);
}

// 9. POST /auth/logout
if ($subRoute === '/logout' && $method === 'POST') {
    if (!empty($tokenRecord['id'])) {
        Database::query("DELETE FROM api_tokens WHERE id = ?", [$tokenRecord['id']]);
    }
    TenantContext::clear();
    apiResponse(['message' => 'Logged out successfully.']);
}

apiError('Invalid authentication sub-route.', 'NOT_FOUND', 404);
