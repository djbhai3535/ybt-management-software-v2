<?php
/**
 * YBT Management Software — REST API v1 Business & Shop Profile
 */

$method = $_SERVER['REQUEST_METHOD'];
$subRoute = substr($route, strlen('/business'));
$subRoute = '/' . trim($subRoute, '/');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$tenantId = TenantContext::id();

// 1. GET /business/profile
if ($subRoute === '/profile' && $method === 'GET') {
    $business = Database::fetch(
        "SELECT id, tenant_uuid, name, legal_name, owner_name, type, industry,
                email, phone, whatsapp, address_line1, city, state, postal_code, country,
                gstin, gst_registered, default_currency, invoice_prefix, status, onboarding_step, created_at
         FROM businesses WHERE id = ?",
        [$tenantId]
    );

    if (!$business) {
        apiError('Business profile not found.', 'NOT_FOUND', 404);
    }

    apiResponse(['business' => $business]);
}

// 2. PUT /business/profile
if ($subRoute === '/profile' && ($method === 'PUT' || $method === 'PATCH')) {
    if (empty($authenticatedUser['is_owner'])) {
        apiError('Only the business owner can update shop settings.', 'FORBIDDEN', 403);
    }

    $legalName = trim($input['legal_name'] ?? $input['name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $whatsapp = trim($input['whatsapp'] ?? '');
    $type = trim($input['type'] ?? '');
    $address = trim($input['address_line1'] ?? '');
    $city = trim($input['city'] ?? '');
    $state = trim($input['state'] ?? '');
    $postalCode = trim($input['postal_code'] ?? '');
    $country = trim($input['country'] ?? 'India');
    $currency = trim($input['default_currency'] ?? 'INR');
    $invoicePrefix = trim($input['invoice_prefix'] ?? 'INV-');
    $gstin = trim($input['gstin'] ?? '');

    if ($legalName === '' || $phone === '') {
        apiError('Shop name and phone are required.', 'VALIDATION_FAILED', 422);
    }

    Database::query(
        "UPDATE businesses SET 
            name = ?,
            legal_name = ?,
            phone = ?,
            whatsapp = ?,
            type = IF(? = '', type, ?),
            industry = IF(? = '', industry, ?),
            address_line1 = ?,
            city = ?,
            state = ?,
            postal_code = ?,
            country = ?,
            default_currency = ?,
            invoice_prefix = ?,
            gstin = ?,
            gst_registered = ?,
            updated_at = NOW()
         WHERE id = ?",
        [
            $legalName,
            $legalName,
            $phone,
            $whatsapp,
            $type, $type,
            $type, $type,
            $address,
            $city,
            $state,
            $postalCode,
            $country,
            $currency,
            $invoicePrefix,
            $gstin,
            !empty($gstin) ? 1 : 0,
            $tenantId
        ]
    );

    ActivityLog::log($tenantId, (int)$authenticatedUser['user_id'], 'business_profile_updated', 'business', $tenantId, 'Shop profile updated via API');

    $updated = Database::fetch(
        "SELECT id, tenant_uuid, name, legal_name, owner_name, type, industry,
                email, phone, whatsapp, address_line1, city, state, postal_code, country,
                gstin, gst_registered, default_currency, invoice_prefix, status, onboarding_step
         FROM businesses WHERE id = ?",
        [$tenantId]
    );

    apiResponse([
        'message' => 'Shop profile updated successfully.',
        'business' => $updated
    ]);
}

apiError('Invalid business endpoint.', 'NOT_FOUND', 404);
