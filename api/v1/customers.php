<?php
/**
 * YBT Management Software — REST API v1 Customers Endpoint
 * Scoped by TenantContext to guarantee strict isolation.
 */

$method = $_SERVER['REQUEST_METHOD'];
$tenantId = TenantContext::id();
$userId = (int)($authenticatedUser['user_id'] ?? $authenticatedUser['id'] ?? 1);

// Route parser
$parts = array_values(array_filter(explode('/', trim($route, '/'))));
// Expecting ['customers'] or ['customers', '{id}']
$customerId = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : 0;

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// GET /customers or GET /customers/{id}
if ($method === 'GET') {
    if ($customerId > 0) {
        $customer = CustomerService::getById($customerId, $tenantId);
        if (!$customer) {
            apiError('Customer not found.', 'NOT_FOUND', 404);
        }
        apiResponse($customer);
    } else {
        $filters = [
            'search' => $_GET['search'] ?? '',
            'status' => $_GET['status'] ?? 'all',
            'city' => $_GET['city'] ?? '',
        ];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));

        $result = CustomerService::getAll($tenantId, $filters, $page, $perPage);
        apiResponse($result['data'], 200, [
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'total_pages' => $result['total_pages'],
        ]);
    }
}

// POST /customers
if ($method === 'POST') {
    $name = trim($input['name'] ?? '');
    if ($name === '') {
        apiError('Customer name is required.', 'VALIDATION_FAILED', 422);
    }

    $syncUuid = trim($input['sync_uuid'] ?? '') ?: generateUuidV4();

    // Idempotency: Check if sync_uuid already exists for this tenant
    $existing = Database::fetch(
        "SELECT id FROM customers WHERE business_id = ? AND sync_uuid = ?",
        [$tenantId, $syncUuid]
    );

    if ($existing) {
        $customer = CustomerService::getById($existing['id'], $tenantId);
        apiResponse($customer, 200, ['idempotent_replay' => true]);
    }

    $input['sync_uuid'] = $syncUuid;
    $newId = CustomerService::create($tenantId, $input, $userId);

    // Save sync metadata if column exists
    try {
        Database::query(
            "UPDATE customers SET sync_uuid = ?, sync_version = sync_version + 1, client_updated_at = NOW() WHERE id = ?",
            [$syncUuid, $newId]
        );
    } catch (Throwable $t) {
        // Fallback if column not yet migrated
    }

    $created = CustomerService::getById($newId, $tenantId);
    apiResponse($created, 201);
}

// PUT /customers/{id}
if ($method === 'PUT' && $customerId > 0) {
    $existing = CustomerService::getById($customerId, $tenantId);
    if (!$existing) {
        apiError('Customer not found.', 'NOT_FOUND', 404);
    }

    $success = CustomerService::update($customerId, $tenantId, $input);
    if ($success) {
        try {
            Database::query(
                "UPDATE customers SET sync_version = sync_version + 1, client_updated_at = NOW() WHERE id = ? AND business_id = ?",
                [$customerId, $tenantId]
            );
        } catch (Throwable $t) {}
    }

    $updated = CustomerService::getById($customerId, $tenantId);
    apiResponse($updated);
}

apiError('Unsupported customer method.', 'METHOD_NOT_ALLOWED', 405);
