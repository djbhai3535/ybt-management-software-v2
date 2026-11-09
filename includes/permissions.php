<?php
/**
 * YBT Management Software — Role-Based Access Control (RBAC) Permissions
 * Database-driven permission resolution across MySQL and SQLite offline modes
 * with fail-closed Owner anti-privilege-escalation enforcement.
 */

class Permissions {
    private static ?array $userPermissions = null;

    /**
     * Clear cached permissions (e.g. after role or permission update)
     */
    public static function clearCache(): void {
        self::$userPermissions = null;
    }

    /**
     * Load all permission keys for the current user
     */
    public static function load(): array {
        if (self::$userPermissions !== null) {
            return self::$userPermissions;
        }

        // Owner always has unrestricted wildcard access across all engines
        if (Auth::isOwner()) {
            self::$userPermissions = ['*'];
            return self::$userPermissions;
        }

        $userId = Auth::id();
        if (!$userId) {
            self::$userPermissions = [];
            return [];
        }

        $user = Database::fetch("SELECT role_id FROM users WHERE id = ?", [$userId]);
        if (!$user || empty($user['role_id'])) {
            self::$userPermissions = [];
            return [];
        }

        $perms = Database::fetchAll(
            "SELECT p.`key` FROM permissions p 
             JOIN role_permissions rp ON p.id = rp.permission_id 
             WHERE rp.role_id = ?",
            [$user['role_id']]
        );

        if (!empty($perms)) {
            self::$userPermissions = array_column($perms, 'key');
            return self::$userPermissions;
        }

        // Fallback default operational permissions by role slug if not explicitly in DB
        $roleSlug = $_SESSION['role_slug'] ?? 'staff';
        if ($roleSlug === 'manager') {
            self::$userPermissions = [
                'dashboard.view', 'customers.view', 'customers.create', 'customers.edit',
                'leads.view', 'leads.create', 'leads.edit',
                'products.view', 'products.create', 'products.edit',
                'invoices.view', 'invoices.create', 'invoices.edit',
                'payments.view', 'payments.create', 'payments.record',
                'expenses.view', 'expenses.create', 'tasks.view', 'tasks.create'
            ];
        } else {
            self::$userPermissions = [
                'dashboard.view', 'customers.view', 'customers.create', 'customers.edit',
                'products.view', 'invoices.view', 'invoices.create',
                'payments.view', 'payments.create', 'payments.record'
            ];
        }
        return self::$userPermissions;
    }

    /**
     * Check if current user has a specific permission
     */
    public static function has(string $permissionKey): bool {
        if (Auth::isOwner() || Auth::isSuperAdmin()) return true;
        $perms = self::load();
        if (in_array('*', $perms, true)) return true;
        if (in_array($permissionKey, $perms, true)) return true;

        // Alias compatibility
        if ($permissionKey === 'payments.record' && in_array('payments.create', $perms, true)) return true;
        if ($permissionKey === 'payments.create' && in_array('payments.record', $perms, true)) return true;

        return false;
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
        Auth::requireLogin();
        if (!self::has($permissionKey)) {
            http_response_code(403);
            require YBT_ROOT . '/errors/403.php';
            exit;
        }
    }

    /**
     * Require any of the given permissions or abort with 403
     */
    public static function requireAny(array $keys): void {
        Auth::requireLogin();
        if (!self::hasAny($keys)) {
            http_response_code(403);
            require YBT_ROOT . '/errors/403.php';
            exit;
        }
    }

    /**
     * Require owner role or abort with 403
     */
    public static function requireOwner(): void {
        Auth::requireLogin();
        if (!Auth::isOwner()) {
            http_response_code(403);
            require YBT_ROOT . '/errors/403.php';
            exit;
        }
    }

    /**
     * Get all permissions grouped by category
     */
    public static function allGrouped(): array {
        $all = Database::fetchAll("SELECT * FROM permissions ORDER BY group_name, sort_order, label");
        $grouped = [];
        foreach ($all as $perm) {
            $grouped[$perm['group_name']][] = $perm;
        }
        return $grouped;
    }

    /**
     * Get permissions for a specific role
     */
    public static function forRole(int $roleId): array {
        $perms = Database::fetchAll(
            "SELECT p.* FROM permissions p 
             JOIN role_permissions rp ON p.id = rp.permission_id 
             WHERE rp.role_id = ? 
             ORDER BY p.group_name, p.sort_order",
            [$roleId]
        );
        return $perms;
    }

    /**
     * Sync permissions for a role
     */
    public static function syncRole(int $roleId, array $permissionIds): void {
        // Anti-escalation guard: Do not allow modifying Owner role
        $role = Database::fetch("SELECT slug FROM roles WHERE id = ?", [$roleId]);
        if ($role && $role['slug'] === 'owner') {
            throw new RuntimeException("Cannot modify permissions of the Owner role.");
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            Database::query("DELETE FROM role_permissions WHERE role_id = ?", [$roleId]);
            foreach ($permissionIds as $permId) {
                Database::insert('role_permissions', [
                    'role_id'       => $roleId,
                    'permission_id' => (int)$permId,
                ]);
            }
            $db->commit();
            self::clearCache();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
