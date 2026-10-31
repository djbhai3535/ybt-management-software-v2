<?php
/**
 * YBT Management Software — CSRF Protection
 */

class CSRF {
    /**
     * Generate or retrieve the current CSRF token
     */
    public static function token(): string {
        if (empty($_SESSION['_csrf_token']) || self::isExpired()) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['_csrf_time'] = time();
        }
        return $_SESSION['_csrf_token'];
    }

    /**
     * Output hidden input field
     */
    public static function field(): string {
        return '<input type="hidden" name="_csrf_token" value="' . self::token() . '">';
    }

    /**
     * Validate CSRF token from parameter, POST request, or HTTP Header
     */
    public static function validate(?string $token = null): bool {
        if ($token === null || $token === '') {
            $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }
        if (empty($token) || empty($_SESSION['_csrf_token'])) {
            return false;
        }
        if (!hash_equals($_SESSION['_csrf_token'], $token)) {
            return false;
        }
        if (self::isExpired()) {
            return false;
        }
        return true;
    }

    /**
     * Validate and abort if invalid
     */
    public static function validateOrDie(?string $token = null): void {
        if (!self::validate($token)) {
            http_response_code(403);
            Flash::error('Invalid or expired security token. Please try again.');
            $redirect = $_SERVER['HTTP_REFERER'] ?? APP_URL . '/dashboard.php';
            header('Location: ' . $redirect);
            exit;
        }
    }

    /**
     * Check if token has expired
     */
    private static function isExpired(): bool {
        $config = require YBT_ROOT . '/config/config.php';
        $lifetime = $config['csrf_token_lifetime'] ?? 3600;
        $tokenTime = $_SESSION['_csrf_time'] ?? 0;
        return (time() - $tokenTime) > $lifetime;
    }

    /**
     * Regenerate token (after sensitive operation)
     */
    public static function regenerate(): string {
        unset($_SESSION['_csrf_token'], $_SESSION['_csrf_time']);
        return self::token();
    }
}
