<?php
/**
 * YBT Management Software — REST API v1 Authentication & Device Binding
 */

$method = $_SERVER['REQUEST_METHOD'];
$subRoute = substr($route, strlen('/auth'));
$subRoute = '/' . trim($subRoute, '/');

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// 1. POST /auth/login
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
        "SELECT u.*, b.name as business_name, b.is_demo, b.default_currency, r.slug as role_slug
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

    // Register / update terminal in sync_devices if device_id provided
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
        ],
        'business' => [
            'id' => (int)$user['business_id'],
            'name' => $user['business_name'],
            'currency' => $user['default_currency'] ?? 'INR',
            'is_demo' => (bool)$user['is_demo'],
        ]
    ]);
}

// 2. GET /auth/me
if ($subRoute === '/me' && $method === 'GET') {
    $tenantId = TenantContext::id();
    $business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$tenantId]);

    apiResponse([
        'user' => $authenticatedUser,
        'business' => $business,
    ]);
}

// 3. POST /auth/logout
if ($subRoute === '/logout' && $method === 'POST') {
    if (!empty($tokenRecord['id'])) {
        Database::query("DELETE FROM api_tokens WHERE id = ?", [$tokenRecord['id']]);
    }
    TenantContext::clear();
    apiResponse(['message' => 'Logged out successfully.']);
}

apiError('Invalid authentication sub-route.', 'NOT_FOUND', 404);
