<?php
/**
 * YBT Management Software — Authentication
 */

class Auth {
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
                if ($sessionTableExists) {
                    $existingLocal = Database::fetch("SELECT id FROM local_sessions WHERE user_id = ? LIMIT 1", [$userId]);
                    if ($existingLocal) {
                        Database::query(
                            "UPDATE `local_sessions` SET `last_offline_login_at` = ?, `status` = 'active', `password_hash` = ? WHERE id = ?",
                            [$now, $user['password'], $existingLocal['id']]
                        );
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
                            'status' => 'active',
                            'last_offline_login_at' => $now,
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
