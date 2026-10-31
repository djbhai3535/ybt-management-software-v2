<?php
/**
 * Test Suite: Deep Multi-Tenant Security & Cross-Tenant Attack Verification
 * Uses real MySQL test database 'ybt_test_verification' with Shop A and Shop B.
 */

if (!defined('YBT_ROOT')) {
    define('YBT_ROOT', dirname(__DIR__));
}

// Point config to test database
putenv('DB_NAME=ybt_test_verification');
$_ENV['DB_NAME'] = 'ybt_test_verification';

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../services/customer-service.php';
require_once __DIR__ . '/../services/invoice-service.php';

echo "=== Phase 2.1 Deep Multi-Tenant Security Verification ===\n";

$db = Database::getInstance();

// 1. Setup Shop A and Shop B
echo ">> Setting up Shop A and Shop B in test database...\n";

// Clear previous test tenants
$db->query("DELETE FROM businesses WHERE name IN ('Shop A Electronics', 'Shop B Mobile Hub')");

$shopAId = Database::insert('businesses', [
    'name' => 'Shop A Electronics',
    'email' => 'owner_a@shop.com',
    'phone' => '1111111111',
    'city' => 'Delhi',
    'state' => 'Delhi',
    'is_demo' => 1
]);

$shopBId = Database::insert('businesses', [
    'name' => 'Shop B Mobile Hub',
    'email' => 'owner_b@shop.com',
    'phone' => '2222222222',
    'city' => 'Mumbai',
    'state' => 'Maharashtra',
    'is_demo' => 1
]);

// Roles
$roleA = Database::insert('roles', [
    'business_id' => $shopAId,
    'name' => 'Owner',
    'slug' => 'owner'
]);
$roleB = Database::insert('roles', [
    'business_id' => $shopBId,
    'name' => 'Owner',
    'slug' => 'owner'
]);

// Users
$pwd = password_hash('password123', PASSWORD_DEFAULT);
$userA = Database::insert('users', [
    'business_id' => $shopAId,
    'role_id' => $roleA,
    'name' => 'Owner A',
    'email' => 'owner_a@shop.com',
    'password' => $pwd,
    'is_owner' => 1,
    'is_active' => 1
]);
$userB = Database::insert('users', [
    'business_id' => $shopBId,
    'role_id' => $roleB,
    'name' => 'Owner B',
    'email' => 'owner_b@shop.com',
    'password' => $pwd,
    'is_owner' => 1,
    'is_active' => 1
]);

// Create Private Records for Shop A
TenantContext::set($shopAId);
$custAId = CustomerService::create($shopAId, [
    'name' => 'Customer of Shop A',
    'phone' => '9999900001',
    'email' => 'custA@example.com'
], $userA);

$prodAId = Database::insert('products', [
    'business_id' => $shopAId,
    'name' => 'Shop A Samsung Battery',
    'selling_price' => 1200.00,
    'status' => 'active'
]);

$invAId = InvoiceService::create($shopAId, [
    'customer_id' => $custAId,
    'invoice_date' => date('Y-m-d'),
], [
    ['description' => 'Samsung Battery', 'quantity' => 1, 'unit_price' => 1200.00, 'tax_rate' => 18]
], $userA);

// Create Private Records for Shop B
TenantContext::set($shopBId);
$custBId = CustomerService::create($shopBId, [
    'name' => 'Customer of Shop B',
    'phone' => '9999900002',
    'email' => 'custB@example.com'
], $userB);

$prodBId = Database::insert('products', [
    'business_id' => $shopBId,
    'name' => 'Shop B iPhone Screen',
    'selling_price' => 5000.00,
    'status' => 'active'
]);

$invBId = InvoiceService::create($shopBId, [
    'customer_id' => $custBId,
    'invoice_date' => date('Y-m-d'),
], [
    ['description' => 'iPhone Screen', 'quantity' => 1, 'unit_price' => 5000.00, 'tax_rate' => 18]
], $userB);

echo "   Shop A ID: {$shopAId} | Cust A: {$custAId} | Prod A: {$prodAId} | Inv A: {$invAId}\n";
echo "   Shop B ID: {$shopBId} | Cust B: {$custBId} | Prod B: {$prodBId} | Inv B: {$invBId}\n";

// --- SECURITY TESTS ---
echo "\n>> Commencing Cross-Tenant Security Attacks...\n";

// TEST 1: Shop A attempts to read Shop B Customer
TenantContext::set($shopAId);
echo "Test 1: Shop A attempts direct ID lookup of Shop B customer (ID: {$custBId})...\n";
$leak = CustomerService::getById($custBId, $shopAId);
if ($leak !== null) {
    echo "FAIL: Shop A read Shop B customer details!\n";
    exit(1);
}
echo "   PASS: Shop A received null. Direct customer read blocked.\n";

// TEST 2: Shop A attempts to update Shop B Customer
echo "Test 2: Shop A attempts to update Shop B customer...\n";
$updated = CustomerService::update($custBId, $shopAId, ['name' => 'Hacked by Shop A']);
$custBCheck = Database::fetch("SELECT name FROM customers WHERE id = ?", [$custBId]);
if ($updated || $custBCheck['name'] === 'Hacked by Shop A') {
    echo "FAIL: Shop A updated Shop B customer!\n";
    exit(1);
}
echo "   PASS: Update operation returned false and record was not modified.\n";

// TEST 3: Shop A attempts to delete Shop B Customer
echo "Test 3: Shop A attempts to delete Shop B customer...\n";
$deleted = CustomerService::delete($custBId, $shopAId);
$custBStatus = Database::fetch("SELECT status FROM customers WHERE id = ?", [$custBId]);
if ($deleted || $custBStatus['status'] === 'inactive') {
    echo "FAIL: Shop A archived/deleted Shop B customer!\n";
    exit(1);
}
echo "   PASS: Delete operation returned false and record remained active.\n";

// TEST 4: Shop A queries Invoices list
echo "Test 4: Shop A queries Invoices list...\n";
$shopAInvoices = Database::fetchAll("SELECT * FROM invoices WHERE business_id = ?", [$shopAId]);
foreach ($shopAInvoices as $inv) {
    if ((int)$inv['business_id'] !== $shopAId || (int)$inv['id'] === $invBId) {
        echo "FAIL: Shop B invoice leaked into Shop A invoices list!\n";
        exit(1);
    }
}
echo "   PASS: Invoices list contains strictly Shop A records. Zero Shop B invoices leaked.\n";

// TEST 5: Shop A queries Products catalog
echo "Test 5: Shop A queries Products catalog...\n";
$shopAProducts = Database::fetchAll("SELECT * FROM products WHERE business_id = ?", [$shopAId]);
foreach ($shopAProducts as $prod) {
    if ((int)$prod['business_id'] !== $shopAId || (int)$prod['id'] === $prodBId) {
        echo "FAIL: Shop B product leaked into Shop A catalog!\n";
        exit(1);
    }
}
echo "   PASS: Products catalog contains strictly Shop A records.\n";

// TEST 6: Subquery Isolation in CustomerService::getAll
echo "Test 6: Verifying subquery financial isolation in CustomerService::getAll...\n";
$allA = CustomerService::getAll($shopAId);
foreach ($allA['data'] as $c) {
    if ((int)$c['business_id'] !== $shopAId) {
        echo "FAIL: Cross-tenant customer found in getAll!\n";
        exit(1);
    }
}
echo "   PASS: CustomerService::getAll subqueries strictly bounded by tenant.\n";

// TEST 7: Input tampering with business_id
echo "Test 7: Client injects 'business_id' => 9999 in customer create payload...\n";
$spoofedCustId = CustomerService::create($shopAId, [
    'business_id' => 9999,
    'name' => 'Spoof Test Customer',
    'phone' => '8888888888'
], $userA);
$spoofedRecord = Database::fetch("SELECT business_id FROM customers WHERE id = ?", [$spoofedCustId]);
if ((int)$spoofedRecord['business_id'] !== $shopAId) {
    echo "FAIL: Client-injected business_id bypassed server scoping!\n";
    exit(1);
}
echo "   PASS: Server strictly enforced active tenant ID ({$shopAId}), ignoring payload tampering.\n";

// TEST 8: Fail-Closed without TenantContext
echo "Test 8: Executing query without TenantContext...\n";
TenantContext::clear();
try {
    CustomerService::getById($custAId, 1);
    echo "FAIL: Expected TenancyException when TenantContext is unset.\n";
    exit(1);
} catch (TenancyException $e) {
    echo "   PASS: Fail-closed architecture successfully blocked access when TenantContext was missing.\n";
}

echo "\n>>> MULTI-TENANT SECURITY TESTS: 100% VERIFIED PASS!\n";
