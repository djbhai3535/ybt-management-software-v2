<?php
/**
 * YBT Management Software — REST API v1 Products Catalog Endpoint
 */

$method = $_SERVER['REQUEST_METHOD'];
$tenantId = TenantContext::id();
$userId = (int)($authenticatedUser['user_id'] ?? $authenticatedUser['id'] ?? 1);

if ($method === 'GET') {
    $products = Database::fetchAll(
        "SELECT * FROM products WHERE business_id = ? AND status = 'active' ORDER BY name ASC",
        [$tenantId]
    );
    apiResponse($products);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($input['name'] ?? '');
    $price = max(0, (float)($input['selling_price'] ?? 0));

    if ($name === '') {
        apiError('Product name is required.', 'VALIDATION_FAILED', 422);
    }

    $syncUuid = trim($input['sync_uuid'] ?? '') ?: generateUuidV4();

    $newId = Database::insert('products', [
        'business_id' => $tenantId,
        'name' => $name,
        'type' => $input['type'] ?? 'product',
        'sku' => trim($input['sku'] ?? ''),
        'hsn_sac' => trim($input['hsn_sac'] ?? ''),
        'unit' => trim($input['unit'] ?? 'Nos'),
        'selling_price' => $price,
        'cost_price' => max(0, (float)($input['cost_price'] ?? 0)),
        'tax_rate' => max(0, (float)($input['tax_rate'] ?? 18)),
        'description' => trim($input['description'] ?? ''),
        'status' => 'active',
        'created_by' => $userId,
    ]);

    try {
        Database::query("UPDATE products SET sync_uuid = ? WHERE id = ?", [$syncUuid, $newId]);
    } catch (Throwable $t) {}

    $created = Database::fetch("SELECT * FROM products WHERE id = ? AND business_id = ?", [$newId, $tenantId]);
    apiResponse($created, 201);
}

apiError('Unsupported product method.', 'METHOD_NOT_ALLOWED', 405);
