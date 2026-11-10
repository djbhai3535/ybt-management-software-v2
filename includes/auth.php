<?php
/**
 * YBT Management Software — Authentication
 */

class Auth {
    /**
     * Check if cloud SaaS server is reachable
     */
    public static function isCloudReachable(?string $cloudUrl = null, int $timeoutMs = 1500): bool {
        $url = rtrim($cloudUrl ?: (defined('CLOUD_SYNC_URL') ? CLOUD_SYNC_URL : (getenv('CLOUD_SYNC_URL') ?: 'https://apkashow.com')), '/');
        $healthUrl = $url . '/api/v1/health.php';
        $ch = curl_init($healthUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $timeoutMs);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 400);
    }

    /**
     * Authenticate against central SaaS platform (https://apkashow.com) and hydrate local SQLite
     */
    private static function attemptCloudLoginAndHydration(string $email, string $password): ?array {
        $cloudUrl = rtrim(defined('CLOUD_SYNC_URL') ? CLOUD_SYNC_URL : (getenv('CLOUD_SYNC_URL') ?: 'https://apkashow.com'), '/');
        $endpoint = $cloudUrl . '/api/v1/index.php?route=/auth/login';

        $deviceId = 'desktop-' . substr(md5(gethostname()), 0, 8);
        $payload = json_encode([
            'email' => $email,
            'password' => $password,
            'device_id' => $deviceId,
            'device_name' => 'Windows Desktop (' . gethostname() . ')',
            'platform' => 'windows'
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 2500);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$resp) {
            return null;
        }

        $res = json_decode((string)$resp, true);
        if (empty($res['success']) || empty($res['data']['access_token'])) {
            return null;
        }

        $data = $res['data'];
        $rawToken = $data['access_token'];
        $cUser = $data['user'];
        $cBiz = $data['business'];
        $now = date('Y-m-d H:i:s');

        try {
            // 1. Ensure business in local SQLite
            $bizId = (int)$cBiz['id'];
            $bizExists = Database::fetch("SELECT id FROM businesses WHERE id = ?", [$bizId]);
            if (!$bizExists) {
                Database::insert('businesses', [
                    'id' => $bizId,
                    'name' => $cBiz['name'] ?? 'My Shop',
                    'legal_name' => $cBiz['name'] ?? 'My Shop',
                    'default_currency' => $cBiz['currency'] ?? 'PKR',
                    'tenant_uuid' => 'tenant-cloud-' . $bizId,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }

            // 2. Ensure roles in local SQLite
            $roleSlug = $cUser['role'] ?? (!empty($cUser['is_owner']) ? 'owner' : 'staff');
            $role = Database::fetch("SELECT id FROM roles WHERE business_id = ? AND slug = ?", [$bizId, $roleSlug]);
            $roleId = $role ? (int)$role['id'] : 1;
            if (!$role) {
                $roleId = Database::insert('roles', [
                    'business_id' => $bizId,
                    'name' => ucfirst($roleSlug),
                    'slug' => $roleSlug,
                    'is_system' => 1
                ]);
            }

            // 3. Upsert user in local SQLite with BCrypt hashed password for offline access
            $userId = (int)$cUser['id'];
            $passHash = password_hash($password, PASSWORD_DEFAULT);
            $uExists = Database::fetch("SELECT id FROM users WHERE id = ? OR email = ?", [$userId, $email]);
            if ($uExists) {
                Database::query(
                    "UPDATE users SET name = ?, email = ?, password = ?, role_id = ?, business_id = ?, is_owner = ?, is_active = 1, verification_status = 'verified', updated_at = ? WHERE id = ?",
                    [$cUser['name'], $email, $passHash, $roleId, $bizId, !empty($cUser['is_owner']) ? 1 : 0, $now, $uExists['id']]
                );
            } else {
                Database::insert('users', [
                    'id' => $userId,
                    'business_id' => $bizId,
                    'role_id' => $roleId,
                    'name' => $cUser['name'],
                    'email' => $email,
                    'password' => $passHash,
                    'is_owner' => !empty($cUser['is_owner']) ? 1 : 0,
                    'is_active' => 1,
                    'verification_status' => 'verified',
                    'onboarding_completed' => 1,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }

            // 4. Save API Token for background sync
            Database::query("DELETE FROM api_tokens WHERE business_id = ? AND user_id = ?", [$bizId, $userId]);
            Database::insert('api_tokens', [
                'business_id' => $bizId,
                'user_id' => $userId,
                'token_hash' => hash('sha256', $rawToken),
                'name' => 'Desktop App (' . gethostname() . ')',
                'device_id' => $deviceId,
                'abilities' => json_encode(['*']),
                'expires_at' => date('Y-m-d H:i:s', time() + (30 * 86400))
            ]);
            $_SESSION['cached_api_token'] = $rawToken;

            // 5. Update local_sessions
            $sessionTableExists = Database::fetchColumn("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='local_sessions'");
            if ($sessionTableExists) {
                Database::query("DELETE FROM local_sessions WHERE user_id = ?", [$userId]);
                Database::insert('local_sessions', [
                    'device_id' => $deviceId,
                    'business_id' => $bizId,
                    'tenant_uuid' => 'tenant-cloud-' . $bizId,
                    'user_id' => $userId,
                    'user_email' => $email,
                    'user_name' => $cUser['name'],
                    'role_slug' => $roleSlug,
                    'password_hash' => $passHash,
                    'encrypted_api_token' => $rawToken,
                    'offline_access_allowed' => 1,
                    'last_online_login_at' => $now,
                    'last_offline_login_at' => $now,
                    'expires_at' => date('Y-m-d H:i:s', time() + (30 * 86400)),
                    'status' => 'active'
                ]);
            }

            // 6. Initial Hydration Pull from cloud
            $sqlitePath = getenv('YBT_SQLITE_PATH') ?: (getenv('APPDATA') ? (str_replace('\\', '/', getenv('APPDATA')) . '/YBT Management Software/storage/desktop/ybt_local.sqlite') : null);
            if (file_exists(YBT_ROOT . '/desktop/src/SqliteClient.php') && file_exists(YBT_ROOT . '/desktop/src/SyncEngine.php')) {
                require_once YBT_ROOT . '/desktop/src/SqliteClient.php';
                require_once YBT_ROOT . '/desktop/src/SyncEngine.php';
                try {
                    $sqlite = new SqliteClient($sqlitePath);
                    $sync = new SyncEngine($sqlite);
                    $sync->pullUpdates($cloudUrl, $rawToken, $bizId);
                } catch (\Throwable $pullErr) {
                    error_log('Initial hydration pull notice: ' . $pullErr->getMessage());
                }
            }

            // Return re-fetched local user
            return Database::fetch(
                "SELECT u.*, r.slug as role_slug, b.name as business_name, b.default_currency, 
                        b.tenant_uuid, b.status as business_status, b.onboarding_step 
                 FROM users u 
                 JOIN roles r ON u.role_id = r.id 
                 JOIN businesses b ON u.business_id = b.id 
                 WHERE u.email = ? LIMIT 1",
                [$email]
            );
        } catch (\Throwable $t) {
            error_log('Cloud login hydration error: ' . $t->getMessage());
            return null;
        }
    }

    /**
     * Attempt login with email and password
     */
    public static function attempt(string $email, string $password): array {
        // Unified User Lookup from users table (compatible with both SQLite & MySQL)
        $user = Database::fetch(
            "SELECT u.*, r.slug as role_slug, b.name as business_name, b.default_currency, 
                    b.tenant_uuid, b.status as business_status, b.onboarding_step 
             FROM users u 
             JOIN roles r ON u.role_id = r.id 
             JOIN businesses b ON u.business_id = b.id 
             WHERE u.email = ? LIMIT 1",
            [$email]
        );

        $isDesktop = (Database::getDriver() === 'sqlite' || getenv('APP_ENV') === 'desktop');

        // Cloud Hydration for First-Time or Updated Desktop Users
        if ((!$user || empty($user['password']) || !password_verify($password, $user['password'])) && $isDesktop) {
            $hydratedUser = self::attemptCloudLoginAndHydration($email, $password);
            if ($hydratedUser) {
                $user = $hydratedUser;
            } elseif (!$user) {
                $online = self::isCloudReachable();
                if (!$online) {
                    return [
                        'success' => false,
                        'error' => 'First-time desktop sign in requires an internet connection to sync your shop account from apkashow.com. Please connect to the internet and try again.'
                    ];
                }
                return ['success' => false, 'error' => 'Invalid email or password.'];
            }
        }

        if (!$user) {
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        $isSuperAdmin = !empty($user['is_super_admin']) || (($user['role_slug'] ?? '') === 'super_admin');

        // Check optional lockout if column exists
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'error' => "Account locked. Try again in {$remaining} minute(s)."];
        }

        // Verify password
        if (empty($user['password']) || !password_verify($password, $user['password'])) {
            ActivityLog::log($user['business_id'], $user['id'], 'login_failed', 'user', $user['id'], 'Failed login attempt');
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        // Phase 9: Offline Grace Period Check (14 days)
        if ($isDesktop && !$isSuperAdmin) {
            try {
                $sessionTableExists = Database::fetchColumn("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='local_sessions'");
                if ($sessionTableExists) {
                    $localSession = Database::fetch("SELECT last_online_login_at FROM local_sessions WHERE user_id = ? LIMIT 1", [$user['id']]);
                    if ($localSession && !empty($localSession['last_online_login_at'])) {
                        $lastOnline = strtotime($localSession['last_online_login_at']);
                        $graceSeconds = 14 * 86400; // 14 days grace period
                        if ((time() - $lastOnline) > $graceSeconds) {
                            $online = self::isCloudReachable();
                            if (!$online) {
                                return [
                                    'success' => false,
                                    'error' => 'Your 14-day offline grace period has expired. Please connect to the internet once to verify your subscription and continue using DJ CRM Software.'
                                ];
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        // Check email verification status: unverified users are strictly blocked
        if (!$isSuperAdmin && ((int)$user['is_active'] === 0 || ($user['verification_status'] ?? 'verified') === 'pending')) {
            return [
                'success' => false,
                'error' => 'Please verify your email address before logging in.',
                'pending_verification' => true,
                'email' => $user['email']
            ];
        }

        // Check suspension
        if (!$isSuperAdmin && (($user['verification_status'] ?? '') === 'suspended' || ($user['business_status'] ?? '') === 'suspended')) {
            return ['success' => false, 'error' => 'Your account or business has been suspended. Please contact platform support.'];
        }

        // Record login time if updated_at exists
        $now = date('Y-m-d H:i:s');
        try {
            Database::query("UPDATE users SET updated_at = ? WHERE id = ?", [$now, $user['id']]);
        } catch (\Throwable $e) {}

        // Regenerate session ID safely
        if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $userId = (int)$user['id'];
        $businessId = (int)$user['business_id'];
        $roleSlug = $user['role_slug'] ?? 'staff';
        $isOwner = (!$isSuperAdmin) && (($roleSlug === 'owner') || !empty($user['is_owner']));
        $userName = $user['name'];
        $userEmail = $user['email'];
        $businessName = $user['business_name'] ?? 'Local Business';
        $defaultCurrency = $user['default_currency'] ?? 'PKR';
        $tenantUuid = $user['tenant_uuid'] ?? '';
        $onboardingCompleted = (int)($user['onboarding_completed'] ?? 1);

        $_SESSION['user_id'] = $userId;
        $_SESSION['business_id'] = $businessId;
        $_SESSION['tenant_uuid'] = $tenantUuid;
        $_SESSION['is_owner'] = $isOwner;
        $_SESSION['is_super_admin'] = (bool)$isSuperAdmin;
        $_SESSION['role_slug'] = $roleSlug;
        $_SESSION['user_name'] = $userName;
        $_SESSION['user_email'] = $userEmail;
        $_SESSION['business_name'] = $businessName;
        $_SESSION['default_currency'] = $defaultCurrency;
        $_SESSION['login_time'] = time();

        TenantContext::set($businessId, [
            'name' => $businessName,
            'default_currency' => $defaultCurrency,
            'tenant_uuid' => $tenantUuid,
            'role_slug' => $roleSlug,
            'is_owner' => $isOwner,
            'is_super_admin' => (bool)$isSuperAdmin
        ]);

        // Dual Engine: Upsert local_sessions table for verified desktop sessions
        if (Database::getDriver() === 'sqlite') {
            try {
                $sessionTableExists = Database::fetchColumn("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='local_sessions'");
                $cloudOnline = self::isCloudReachable();
                $onlineLoginAt = $cloudOnline ? $now : null;

                if ($sessionTableExists) {
                    $existingLocal = Database::fetch("SELECT id FROM local_sessions WHERE user_id = ? LIMIT 1", [$userId]);
                    if ($existingLocal) {
                        $updateSql = $cloudOnline 
                            ? "UPDATE `local_sessions` SET `last_offline_login_at` = ?, `last_online_login_at` = ?, `status` = 'active', `password_hash` = ? WHERE id = ?"
                            : "UPDATE `local_sessions` SET `last_offline_login_at` = ?, `status` = 'active', `password_hash` = ? WHERE id = ?";
                        $updateParams = $cloudOnline 
                            ? [$now, $now, $user['password'], $existingLocal['id']]
                            : [$now, $user['password'], $existingLocal['id']];
                        Database::query($updateSql, $updateParams);
                    } else {
                        Database::insert('local_sessions', [
                            'device_id' => 'desktop-local',
                            'business_id' => $businessId,
                            'tenant_uuid' => $tenantUuid,
                            'user_id' => $userId,
                            'user_email' => $userEmail,
                            'user_name' => $userName,
                            'role_slug' => $roleSlug,
                            'password_hash' => $user['password'],
                            'encrypted_api_token' => 'offline_local_token_' . bin2hex(random_bytes(16)),
                            'offline_access_allowed' => 1,
                            'status' => 'active',
                            'last_offline_login_at' => $now,
                            'last_online_login_at' => $onlineLoginAt,
                            'expires_at' => date('Y-m-d H:i:s', time() + (30 * 86400))
                        ]);
                    }
                }
            } catch (\Throwable $t) {
                // Non-critical session sync
            }
        }

        ActivityLog::log($businessId, $userId, 'login_success', 'user', $userId, 'User logged in successfully');

        return [
            'success' => true,
            'user' => [
                'id' => $userId,
                'name' => $userName,
                'email' => $userEmail,
                'role_slug' => $roleSlug,
                'is_super_admin' => (bool)$isSuperAdmin,
                'onboarding_completed' => $onboardingCompleted
            ]
        ];
    }

    /**
     * Check if user is logged in
     */
    public static function check(): bool {
        return !empty($_SESSION['user_id']);
    }

    /**
     * Require authentication or redirect to login
     */
    public static function requireLogin(): void {
        if (!self::check()) {
            Flash::warning('Please log in to continue.');
            header('Location: ' . APP_URL . '/login.php');
            exit;
        }
        // Session timeout check
        $config = require YBT_ROOT . '/config/config.php';
        $lifetime = $config['session_lifetime'] ?? 86400;
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $lifetime) {
            self::logout();
            Flash::warning('Session expired. Please log in again.');
            header('Location: ' . APP_URL . '/login.php');
            exit;
        }

        // Active Tenant Suspension Check (Super Admin exempt)
        if (!self::isSuperAdmin() && self::businessId()) {
            $bStatus = Database::fetchColumn("SELECT status FROM businesses WHERE id = ?", [self::businessId()]);
            if ($bStatus === 'suspended') {
                self::logout();
                Flash::error('Your business account has been suspended by the platform administrator. Please contact support.');
                header('Location: ' . APP_URL . '/login.php');
                exit;
            }
        }
    }

    /**
     * Check if current user is Super Admin
     */
    public static function isSuperAdmin(): bool {
        return !empty($_SESSION['is_super_admin']);
    }

    /**
     * Require Super Admin authorization or 403 Forbidden
     */
    public static function requireSuperAdmin(): void {
        self::requireLogin();
        if (!self::isSuperAdmin()) {
            http_response_code(403);
            require YBT_ROOT . '/errors/403.php';
            exit;
        }
    }

    /**
     * Get current user ID
     */
    public static function id(): ?int {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current business ID
     */
    public static function businessId(): ?int {
        if (TenantContext::has()) {
            return TenantContext::id();
        }
        return $_SESSION['business_id'] ?? null;
    }

    /**
     * Check if current user is Owner
     */
    public static function isOwner(): bool {
        return !empty($_SESSION['is_owner']);
    }

    /**
     * Get current user name
     */
    public static function name(): string {
        return $_SESSION['user_name'] ?? '';
    }

    /**
     * Get current user email
     */
    public static function email(): string {
        return $_SESSION['user_email'] ?? '';
    }

    /**
     * Get business name
     */
    public static function businessName(): string {
        return $_SESSION['business_name'] ?? '';
    }

    /**
     * Get full user record from database
     */
    public static function user(): ?array {
        if (!self::check()) return null;
        return Database::fetch("SELECT * FROM users WHERE id = ?", [self::id()]);
    }

    /**
     * Logout and invalidate session
     */
    public static function logout(): void {
        if (self::check()) {
            ActivityLog::log(
                $_SESSION['business_id'] ?? 0,
                $_SESSION['user_id'] ?? 0,
                'logout', 'user',
                $_SESSION['user_id'] ?? 0,
                'User logged out'
            );
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        TenantContext::clear();
    }
}
