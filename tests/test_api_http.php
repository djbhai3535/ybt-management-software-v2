<?php
/**
 * Test Suite: Real HTTP Request Verification for REST API v1
 * Tests every endpoint over HTTP against running server http://127.0.0.1:8088
 */

$baseUrl = 'http://127.0.0.1:8088/api/v1/index.php?route=';

function makeRequest(string $url, string $method = 'GET', ?array $data = null, ?string $token = null): array {
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'POST' || $method === 'PUT') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    }

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $json = json_decode($response, true) ?? [];
    return [
        'code' => $statusCode,
        'body' => $json,
        'raw' => $response,
        'error' => $error,
    ];
}

echo "=== Phase 2.1 Real HTTP API Functional & Security Verification ===\n";

// 1. GET /health
echo ">> Endpoint 1: GET /health...\n";
$res = makeRequest($baseUrl . '/health');
if ($res['code'] !== 200 || empty($res['body']['data']['status'])) {
    echo "FAIL: GET /health failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
echo "   PASS: GET /health returned 200 OK (Status: {$res['body']['data']['status']}, DB: {$res['body']['data']['database']})\n";

// 2. POST /auth/login (Invalid Credentials)
echo ">> Endpoint 2: POST /auth/login (Invalid Credentials)...\n";
$res = makeRequest($baseUrl . '/auth/login', 'POST', [
    'email' => 'wrong@shop.com',
    'password' => 'badpass'
]);
if ($res['code'] !== 401) {
    echo "FAIL: Expected 401 Unauthorized, got {$res['code']}\n";
    exit(1);
}
echo "   PASS: Invalid login rejected with 401 Unauthorized.\n";

// 3. POST /auth/login (Owner Valid Credentials)
echo ">> Endpoint 3: POST /auth/login (Owner Valid Credentials)...\n";
$res = makeRequest($baseUrl . '/auth/login', 'POST', [
    'email' => 'owner@ybt.com',
    'password' => 'password123',
    'device_id' => 'win-term-001',
    'device_name' => 'Cash Register 1',
    'platform' => 'windows_x64'
]);
if ($res['code'] !== 200 || empty($res['body']['data']['access_token'])) {
    echo "FAIL: Valid login failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
$tokenA = $res['body']['data']['access_token'];
$tenantAId = $res['body']['data']['business']['id'];
echo "   PASS: Shop A authenticated. Bearer token issued. Tenant ID: {$tenantAId}\n";

// Verify password not leaked in response
if (isset($res['body']['data']['user']['password'])) {
    echo "FAIL: Password field was exposed in API login response!\n";
    exit(1);
}
echo "   PASS: Password is not exposed in API response.\n";

// 4. GET /auth/me
echo ">> Endpoint 4: GET /auth/me (Protected)...\n";
$res = makeRequest($baseUrl . '/auth/me', 'GET', null, $tokenA);
if ($res['code'] !== 200 || empty($res['body']['data']['business']['id'])) {
    echo "FAIL: GET /auth/me failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
echo "   PASS: GET /auth/me verified active tenant: {$res['body']['data']['business']['name']}\n";

// 5. Protected Endpoint Without Token
echo ">> Endpoint 5: GET /customers (Missing Authorization Header)...\n";
$res = makeRequest($baseUrl . '/customers', 'GET');
if ($res['code'] !== 401) {
    echo "FAIL: Missing token was not rejected with 401! Code: {$res['code']}\n";
    exit(1);
}
echo "   PASS: Missing authorization header rejected with 401 Unauthorized.\n";

// 6. Protected Endpoint With Invalid Token
echo ">> Endpoint 6: GET /customers (Invalid Token)...\n";
$res = makeRequest($baseUrl . '/customers', 'GET', null, 'invalid_token_xyz_999');
if ($res['code'] !== 401) {
    echo "FAIL: Invalid token was not rejected with 401! Code: {$res['code']}\n";
    exit(1);
}
echo "   PASS: Invalid Bearer token rejected with 401 Unauthorized.\n";

// 7. GET /customers (Authorized)
echo ">> Endpoint 7: GET /customers (Authorized Shop A)...\n";
$res = makeRequest($baseUrl . '/customers', 'GET', null, $tokenA);
if ($res['code'] !== 200 || !is_array($res['body']['data'])) {
    echo "FAIL: GET /customers failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
echo "   PASS: GET /customers returned 200 OK. Items count: " . count($res['body']['data']) . "\n";

// 8. POST /customers (Create Customer with sync_uuid)
echo ">> Endpoint 8: POST /customers (Create Customer)...\n";
$custUuid = '550e8400-e29b-41d4-a716-' . bin2hex(random_bytes(6));
$res = makeRequest($baseUrl . '/customers', 'POST', [
    'sync_uuid' => $custUuid,
    'name' => 'API Created Customer',
    'phone' => '9876543210',
    'email' => 'apicust@example.com'
], $tokenA);
if ($res['code'] !== 201 || empty($res['body']['data']['id'])) {
    echo "FAIL: POST /customers failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
$createdCustId = $res['body']['data']['id'];
echo "   PASS: Customer created successfully (ID: {$createdCustId}, UUID: {$custUuid})\n";

// 9. GET /products
echo ">> Endpoint 9: GET /products...\n";
$res = makeRequest($baseUrl . '/products', 'GET', null, $tokenA);
if ($res['code'] !== 200) {
    echo "FAIL: GET /products failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
echo "   PASS: GET /products returned 200 OK. Items count: " . count($res['body']['data']) . "\n";

// 10. POST /products
echo ">> Endpoint 10: POST /products (Add Item)...\n";
$res = makeRequest($baseUrl . '/products', 'POST', [
    'name' => 'USB-C Fast Charging Cable 65W',
    'selling_price' => 450.00,
    'type' => 'product',
    'tax_rate' => 18
], $tokenA);
if ($res['code'] !== 201 || empty($res['body']['data']['id'])) {
    echo "FAIL: POST /products failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
$createdProdId = $res['body']['data']['id'];
echo "   PASS: Product created successfully (ID: {$createdProdId})\n";

// 11. GET /invoices
echo ">> Endpoint 11: GET /invoices...\n";
$res = makeRequest($baseUrl . '/invoices', 'GET', null, $tokenA);
if ($res['code'] !== 200) {
    echo "FAIL: GET /invoices failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
echo "   PASS: GET /invoices returned 200 OK.\n";

// 12. POST /invoices (Offline Draft Reconciliation)
echo ">> Endpoint 12: POST /invoices (Offline Temporary ID Resolution)...\n";
$invSyncUuid = 'c3d6118b-592f-4886-bce7-' . bin2hex(random_bytes(6));
$res = makeRequest($baseUrl . '/invoices', 'POST', [
    'sync_uuid' => $invSyncUuid,
    'client_temp_number' => 'OFF-TMP-099',
    'customer_id' => $createdCustId,
    'items' => [
        ['description' => 'USB-C Cable', 'quantity' => 2, 'unit_price' => 450.00, 'tax_rate' => 18]
    ]
], $tokenA);
if ($res['code'] !== 201 || empty($res['body']['data']['canonical_invoice_number'])) {
    echo "FAIL: POST /invoices failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
$canonicalNumber = $res['body']['data']['canonical_invoice_number'];
echo "   PASS: Invoice created. Temporary OFF-TMP-099 reconciled to canonical: {$canonicalNumber}\n";

// 13. Idempotency Check: Re-send same invoice sync_uuid
echo ">> Endpoint 13: POST /invoices (Duplicate sync_uuid Idempotency Test)...\n";
$resDuplicate = makeRequest($baseUrl . '/invoices', 'POST', [
    'sync_uuid' => $invSyncUuid,
    'client_temp_number' => 'OFF-TMP-099',
    'customer_id' => $createdCustId,
    'items' => [
        ['description' => 'USB-C Cable', 'quantity' => 2, 'unit_price' => 450.00, 'tax_rate' => 18]
    ]
], $tokenA);
if ($resDuplicate['code'] !== 200 || empty($resDuplicate['body']['meta']['idempotent_replay'])) {
    echo "FAIL: Idempotency replay failed or created duplicate invoice! Code: {$resDuplicate['code']}\n";
    exit(1);
}
echo "   PASS: Server recognized existing sync_uuid. Returned cached invoice without duplicate transaction.\n";

// 14. POST /sync/push (Batch Operation)
echo ">> Endpoint 14: POST /sync/push...\n";
$res = makeRequest($baseUrl . '/sync/push', 'POST', [
    'device_id' => 'win-term-001',
    'operations' => [
        [
            'operation_uuid' => 'op-' . bin2hex(random_bytes(16)),
            'entity_type' => 'customers',
            'entity_uuid' => bin2hex(random_bytes(16)),
            'operation_type' => 'insert',
            'payload' => [
                'name' => 'Synced Customer from Queue',
                'phone' => '7777777777'
            ]
        ]
    ]
], $tokenA);
if ($res['code'] !== 200 || $res['body']['data']['processed_count'] < 1) {
    echo "FAIL: POST /sync/push failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
echo "   PASS: POST /sync/push processed batch successfully.\n";

// 15. GET /sync/pull
echo ">> Endpoint 15: GET /sync/pull...\n";
$res = makeRequest($baseUrl . '/sync/pull&since_version=0', 'GET', null, $tokenA);
if ($res['code'] !== 200 || !isset($res['body']['data']['cursor'])) {
    echo "FAIL: GET /sync/pull failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
echo "   PASS: GET /sync/pull returned 200 OK. Cursor: {$res['body']['data']['cursor']}\n";

// 16. POST /auth/logout (Revoke Token)
echo ">> Endpoint 16: POST /auth/logout...\n";
$res = makeRequest($baseUrl . '/auth/logout', 'POST', null, $tokenA);
if ($res['code'] !== 200) {
    echo "FAIL: POST /auth/logout failed (Code {$res['code']}): {$res['raw']}\n";
    exit(1);
}
echo "   PASS: POST /auth/logout succeeded. Token revoked.\n";

// 17. Use Revoked Token
echo ">> Endpoint 17: Attempting access with revoked token...\n";
$res = makeRequest($baseUrl . '/auth/me', 'GET', null, $tokenA);
if ($res['code'] !== 401) {
    echo "FAIL: Revoked token was still accepted! Code: {$res['code']}\n";
    exit(1);
}
echo "   PASS: Revoked token strictly rejected with 401 Unauthorized.\n";

echo "\n>>> ALL 17 REAL HTTP REST API ENDPOINT TESTS: 100% VERIFIED PASS!\n";
