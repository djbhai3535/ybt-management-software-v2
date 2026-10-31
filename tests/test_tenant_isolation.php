<?php
/**
 * Test Suite: TenantContext & Fail-Closed Multi-Tenant Scoping
 */

require_once __DIR__ . '/../includes/tenant-context.php';

function testTenantFailClosed(): bool {
    TenantContext::clear();
    try {
        TenantContext::id();
        echo "FAIL: Expected TenancyException when resolving unset TenantContext.\n";
        return false;
    } catch (TenancyException $e) {
        echo "PASS: TenantContext fails closed when unbound.\n";
        return true;
    }
}

function testTenantBindingAndResolution(): bool {
    TenantContext::set(42, ['name' => 'Shop 42']);
    if (TenantContext::id() !== 42) {
        echo "FAIL: Resolved tenant ID did not match bound ID.\n";
        return false;
    }
    echo "PASS: TenantContext binds and resolves ID correctly.\n";
    return true;
}

function testCrossTenantAssertion(): bool {
    TenantContext::set(10);
    // Match
    TenantContext::assertMatches(10);

    // Mismatch
    try {
        TenantContext::assertMatches(11);
        echo "FAIL: Expected TenancyException on cross-tenant mismatch.\n";
        return false;
    } catch (TenancyException $e) {
        echo "PASS: TenantContext::assertMatches successfully blocked cross-tenant mismatch.\n";
        return true;
    }
}

function testTenantRunAs(): bool {
    TenantContext::set(1);
    $executed = false;
    TenantContext::runAs(99, function() use (&$executed) {
        if (TenantContext::id() === 99) {
            $executed = true;
        }
    });

    if (!$executed || TenantContext::id() !== 1) {
        echo "FAIL: TenantContext::runAs failed to scope or restore context.\n";
        return false;
    }
    echo "PASS: TenantContext::runAs successfully scoped and restored context.\n";
    return true;
}

echo "=== Running Tenant Isolation Tests ===\n";
$allPassed = testTenantFailClosed()
    && testTenantBindingAndResolution()
    && testCrossTenantAssertion()
    && testTenantRunAs();

if ($allPassed) {
    echo ">>> ALL TENANT ISOLATION TESTS PASSED!\n\n";
    exit(0);
} else {
    echo ">>> TENANT ISOLATION TESTS FAILED!\n\n";
    exit(1);
}
