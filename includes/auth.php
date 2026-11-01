<?php
/**
 * YBT Management Software — Authentication
 */

class Auth {
    /**
     * Attempt login with email and password
     */
    public static function attempt(string $email, string $password): array {
        $config = require YBT_ROOT . '/config/config.php';
        $maxAttempts = $config['login_max_attempts'];
        $lockoutMins = $config['login_lockout_mins'];

        // Dual Engine: Local SQLite offline authentication for desktop
        if (Database::getDriver() === 'sqlite') {
            $session = Database::fetch(
                "SELECT s.*, b.name as business_name 
                 FROM `local_sessions` s 
                 LEFT JOIN `businesses` b ON s.business_id = b.id 
                 WHERE s.user_email = ? AND s.status = 'active' LIMIT 1",
                [$email]
            );

            if (!$session) {
                return ['success' => false, 'error' => 'Invalid email or password.'];
            }

            if (!password_verify($password, $session['password_hash'])) {
                return ['success' => false, 'error' => 'Invalid email or password.'];
            }

            // Update last offline login timestamp
            Database::query("UPDATE `local_sessions` SET `last_offline_login_at` = datetime('now') WHERE id = ?", [$session['id']]);

            // Regenerate session ID safely
            if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            $_SESSION['user_id'] = (int)$session['user_id'];
            $_SESSION['business_id'] = (int)$session['business_id'];
            $_SESSION['is_owner'] = ($session['role_slug'] === 'owner');
            $_SESSION['role_slug'] = $session['role_slug'];
            $_SESSION['user_name'] = $session['user_name'];
            $_SESSION['user_email'] = $session['user_email'];
            $_SESSION['business_name'] = $session['business_name'] ?? 'Local Business';
            $_SESSION['login_time'] = time();
            $_SESSION['auth_mode'] = 'offline';

            return [
                'success' => true,
                'user' => [
                    'id' => (int)$session['user_id'],
                    'name' => $session['user_name'],
                    'email' => $session['user_email'],
                    'role_slug' => $session['role_slug'],
                    'onboarding_completed' => 1
                ],
                'mode' => 'offline'
            ];
        }

        // MySQL Cloud Authentication Flow
        $user = Database::fetch(
            "SELECT u.*, r.slug as role_slug, b.name as business_name, b.status as business_status, b.onboarding_step 
             FROM users u 
             JOIN roles r ON u.role_id = r.id 
             JOIN businesses b ON u.business_id = b.id 
             WHERE u.email = ? LIMIT 1",
            [$email]
        );

        if (!$user) {
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        // Check account active
        if (!$user['is_active']) {
            return ['success' => false, 'error' => 'This account has been deactivated.'];
        }

        // Check suspension
        if (($user['verification_status'] ?? '') === 'suspended' || ($user['business_status'] ?? '') === 'suspended') {
            return ['success' => false, 'error' => 'Your account or business has been suspended. Please contact support.'];
        }

        // Check email verification status
        if (($user['verification_status'] ?? 'verified') === 'pending') {
            return [
                'success' => false,
                'error' => 'Please verify your email address before logging in.',
                'pending_verification' => true,
                'email' => $user['email']
            ];
        }

        // Check lockout
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'error' => "Account locked. Try again in {$remaining} minute(s)."];
        }

        // Verify password
        if (!password_verify($password, $user['password'])) {
            $attempts = $user['failed_login_count'] + 1;
            $updates = ['failed_login_count' => $attempts];

            if ($attempts >= $maxAttempts) {
                $updates['locked_until'] = date('Y-m-d H:i:s', time() + ($lockoutMins * 60));
            }

            $setClauses = [];
            $params = [];
            foreach ($updates as $col => $val) {
                $setClauses[] = "`{$col}` = ?";
                $params[] = $val;
            }
            $params[] = $user['id'];
            Database::query(
                "UPDATE users SET " . implode(', ', $setClauses) . " WHERE id = ?",
                $params
            );

            ActivityLog::log($user['business_id'], $user['id'], 'login_failed', 'user', $user['id'], 'Failed login attempt');

            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        // Success — reset failed count, update last login
        Database::query(
            "UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?",
            [$user['id']]
        );

        // Regenerate session ID if headers not sent
        if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Store session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['business_id'] = $user['business_id'];
        $_SESSION['is_owner'] = (bool)$user['is_owner'];
        $_SESSION['role_slug'] = $user['role_slug'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['business_name'] = $user['business_name'];
        $_SESSION['login_time'] = time();

        // Bind Fail-Closed Tenant Context
        TenantContext::set((int)$user['business_id'], [
            'name' => $user['business_name'],
            'user_id' => $user['id'],
            'role_slug' => $user['role_slug'],
        ]);

        ActivityLog::log($user['business_id'], $user['id'], 'login_success', 'user', $user['id'], 'Successful login');

        return ['success' => true, 'user' => $user];
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
        $lifetime = $config['session_lifetime'];
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $lifetime) {
            self::logout();
            Flash::warning('Session expired. Please log in again.');
            header('Location: ' . APP_URL . '/login.php');
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
        session_destroy();
        TenantContext::clear();
    }

    /**
     * Generate a secure password reset token
     */
    public static function createResetToken(string $email): ?string {
        $user = Database::fetch("SELECT id FROM users WHERE email = ? AND is_active = 1", [$email]);
        if (!$user) return null;

        $token = bin2hex(random_bytes(32));
        $config = require YBT_ROOT . '/config/config.php';
        $expires = date('Y-m-d H:i:s', time() + $config['password_reset_ttl']);

        Database::query(
            "UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?",
            [password_hash($token, PASSWORD_DEFAULT), $expires, $user['id']]
        );

        return $token;
    }

    /**
     * Reset password using token
     */
    public static function resetPassword(string $email, string $token, string $newPassword): bool {
        $user = Database::fetch(
            "SELECT id, password_reset_token, password_reset_expires FROM users WHERE email = ? AND is_active = 1",
            [$email]
        );

        if (!$user || !$user['password_reset_token']) return false;
        if (strtotime($user['password_reset_expires']) < time()) return false;
        if (!password_verify($token, $user['password_reset_token'])) return false;

        Database::query(
            "UPDATE users SET password = ?, password_reset_token = NULL, password_reset_expires = NULL, failed_login_count = 0, locked_until = NULL WHERE id = ?",
            [password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]
        );

        return true;
    }
}
