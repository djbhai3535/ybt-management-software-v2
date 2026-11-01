<?php
/**
 * YBT Management Software — Permissions
 */

class Permissions {
    private static ?array $userPermissions = null;

    /**
     * Load all permission keys for the current user
     */
    public static function load(): array {
        if (self::$userPermissions !== null) {
            return self::$userPermissions;
        }

        // Owner has all permissions
        if (Auth::isOwner()) {
            if (Database::getDriver() === 'sqlite') {
                self::$userPermissions = ['*'];
                return self::$userPermissions;
            }
            $all = Database::fetchAll("SELECT `key` FROM permissions");
            self::$userPermissions = array_column($all, 'key');
            return self::$userPermissions;
        }

        if (Database::getDriver() === 'sqlite') {
            // Manager role permissions in desktop offline mode
            self::$userPermissions = [
                'dashboard.view', 'customers.view', 'customers.create', 'customers.edit',
                'products.view', 'products.create', 'products.edit',
                'invoices.view', 'invoices.create', 'invoices.edit',
                'payments.view', 'payments.create',
                'expenses.view', 'expenses.create'
            ];
            return self::$userPermissions;
        }

        $userId = Auth::id();
        if (!$userId) {
            self::$userPermissions = [];
            return [];
        }

        $user = Database::fetch("SELECT role_id FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            self::$userPermissions = [];
            return [];
        }

        $perms = Database::fetchAll(
            "SELECT p.`key` FROM permissions p 
             JOIN role_permissions rp ON p.id = rp.permission_id 
             WHERE rp.role_id = ?",
            [$user['role_id']]
        );
        self::$userPermissions = array_column($perms, 'key');
        return self::$userPermissions;
    }

    /**
     * Check if current user has a specific permission
     */
    public static function has(string $permissionKey): bool {
        if (Auth::isOwner()) return true;
        $perms = self::load();
        if (in_array('*', $perms, true)) return true;
        return in_array($permissionKey, $perms, true);
    }

    /**
     * Check if current user has any of the given permissions
     */
    public static function hasAny(array $keys): bool {
        if (Auth::isOwner()) return true;
        $perms = self::load();
        if (in_array('*', $perms, true)) return true;
        foreach ($keys as $key) {
            if (in_array($key, $perms, true)) return true;
        }
        return false;
    }

    /**
     * Check if current user has ALL the given permissions
     */
    public static function hasAll(array $keys): bool {
        if (Auth::isOwner()) return true;
        $perms = self::load();
        if (in_array('*', $perms, true)) return true;
        foreach ($keys as $key) {
            if (!in_array($key, $perms, true)) return false;
        }
        return true;
    }

    /**
     * Require a permission or abort with 403
     */
    public static function require(string $permissionKey): void {
        if (!self::has($permissionKey)) {
            http_response_code(403);
            Flash::error('You do not have permission to perform this action.');
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        }
    }

    /**
     * Require Owner role
     */
    public static function requireOwner(): void {
        if (!Auth::isOwner()) {
            http_response_code(403);
            Flash::error('This action requires Owner access.');
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        }
    }

    /**
     * Get all permissions grouped
     */
    public static function getAllGrouped(): array {
        $all = Database::fetchAll("SELECT * FROM permissions ORDER BY group_name, id");
        $grouped = [];
        foreach ($all as $p) {
            $grouped[$p['group_name']][] = $p;
        }
        return $grouped;
    }

    /**
     * Get permissions for a role
     */
    public static function forRole(int $roleId): array {
        return Database::fetchAll(
            "SELECT p.`key` FROM permissions p 
             JOIN role_permissions rp ON p.id = rp.permission_id 
             WHERE rp.role_id = ?",
            [$roleId]
        );
    }

    /**
     * Update permissions for a role
     */
    public static function updateRole(int $roleId, array $permissionKeys): void {
        Database::beginTransaction();
        try {
            Database::query("DELETE FROM role_permissions WHERE role_id = ?", [$roleId]);
            if (!empty($permissionKeys)) {
                $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
                $permIds = Database::fetchAll(
                    "SELECT id FROM permissions WHERE `key` IN ({$placeholders})",
                    $permissionKeys
                );
                foreach ($permIds as $p) {
                    Database::query(
                        "INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)",
                        [$roleId, $p['id']]
                    );
                }
            }
            Database::commit();
        } catch (Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Clear cached permissions (call after permission change)
     */
    public static function clearCache(): void {
        self::$userPermissions = null;
    }
}
