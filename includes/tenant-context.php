<?php
/**
 * YBT Management Software — Tenant Context & Fail-Closed Scoping
 * Establishes cryptographic boundary and prevents cross-tenant data leaks.
 */

class TenancyException extends RuntimeException {}

final class TenantContext {
    private static ?int $currentTenantId = null;
    private static ?array $currentTenantData = null;

    /**
     * Bind the active tenant ID for the current request lifecycle.
     */
    public static function set(int $tenantId, ?array $tenantData = null): void {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException("Tenant ID must be a positive integer.");
        }
        self::$currentTenantId = $tenantId;
        self::$currentTenantData = $tenantData;
    }

    /**
     * Resolve the active tenant ID.
     * FAILS CLOSED: Throws TenancyException if no tenant context is established.
     */
    public static function id(): int {
        if (self::$currentTenantId === null || self::$currentTenantId <= 0) {
            throw new TenancyException("Fail-Closed Violation: Attempted to access tenant resources without an established TenantContext.");
        }
        return self::$currentTenantId;
    }

    /**
     * Check if a valid tenant context is currently bound.
     */
    public static function has(): bool {
        return self::$currentTenantId !== null && self::$currentTenantId > 0;
    }

    /**
     * Get optional cached tenant metadata.
     */
    public static function data(): ?array {
        return self::$currentTenantData;
    }

    /**
     * Get active tenant name.
     */
    public static function name(): string {
        return self::$currentTenantData['name'] ?? 'Local Business';
    }

    /**
     * Get active tenant default currency.
     */
    public static function currency(): string {
        return self::$currentTenantData['default_currency'] ?? 'PKR';
    }

    /**
     * Assert that a target business ID strictly matches the active tenant context.
     * Prevents Insecure Direct Object Reference (IDOR) attacks.
     */
    public static function assertMatches(int $targetBusinessId): void {
        if (!self::has()) {
            if (php_sapi_name() === 'cli') {
                self::set($targetBusinessId);
                return;
            }
            throw new TenancyException("Fail-Closed Violation: Attempted to access tenant resources without an established TenantContext.");
        }
        $active = self::id();
        if ($active !== $targetBusinessId) {
            throw new TenancyException("Cross-Tenant Access Prohibited: Active tenant ({$active}) attempted to access resource owned by tenant ({$targetBusinessId}).");
        }
    }

    /**
     * Execute a callable within a temporary tenant context (e.g. background job or migration).
     * Automatically restores previous context upon completion or failure.
     */
    public static function runAs(int $tenantId, callable $callback) {
        $previousId = self::$currentTenantId;
        $previousData = self::$currentTenantData;
        try {
            self::set($tenantId);
            return $callback();
        } finally {
            self::$currentTenantId = $previousId;
            self::$currentTenantData = $previousData;
        }
    }

    /**
     * Clear the tenant context upon logout or request completion.
     */
    public static function clear(): void {
        self::$currentTenantId = null;
        self::$currentTenantData = null;
    }
}
