<?php
/**
 * YBT Management Software — REST API v1 Business & Shop Profile
 * Supports Multi-Country (Pakistan, India, International) with regional presets.
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
                gstin, tax_number, tax_label, gst_registered, default_currency, currency,
                bank_name, account_holder, account_number, ifsc_code, upi_id, wallet_id, wallet_type,
                invoice_prefix, invoice_next_number, status, onboarding_step, created_at
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
    $type = trim($input['type'] ?? 'Mobile Shop & Service Center');
    $address = trim($input['address_line1'] ?? '');
    $city = trim($input['city'] ?? '');
    $state = trim($input['state'] ?? '');
    $postalCode = trim($input['postal_code'] ?? '');
    $country = trim($input['country'] ?? 'Pakistan');
    $currency = strtoupper(trim($input['default_currency'] ?? $input['currency'] ?? 'PKR'));
    $invoicePrefix = trim($input['invoice_prefix'] ?? 'INV-');
    $invoiceNextNumber = max(1, (int)($input['invoice_next_number'] ?? 1001));
    $taxNumber = trim($input['tax_number'] ?? $input['gstin'] ?? '');
    $taxLabel = trim($input['tax_label'] ?? 'NTN / STRN');

    $bankName = trim($input['bank_name'] ?? '');
    $accountHolder = trim($input['account_holder'] ?? $legalName);
    $accountNumber = trim($input['account_number'] ?? '');
    $ifscCode = trim($input['ifsc_code'] ?? '');
    $walletId = trim($input['wallet_id'] ?? $input['upi_id'] ?? '');
    $walletType = trim($input['wallet_type'] ?? 'Raast / Digital Wallet');

    if ($legalName === '' || $phone === '') {
        apiError('Shop name and phone are required.', 'VALIDATION_FAILED', 422);
    }

    $now = date('Y-m-d H:i:s');

    Database::query(
        "UPDATE businesses SET 
            name = ?,
            legal_name = ?,
            phone = ?,
            whatsapp = ?,
            type = ?,
            industry = ?,
            address_line1 = ?,
            city = ?,
            state = ?,
            postal_code = ?,
            country = ?,
            default_currency = ?,
            currency = ?,
            invoice_prefix = ?,
            invoice_next_number = ?,
            tax_number = ?,
            gstin = ?,
            tax_label = ?,
            gst_registered = ?,
            bank_name = ?,
            account_holder = ?,
            account_number = ?,
            ifsc_code = ?,
            upi_id = ?,
            wallet_id = ?,
            wallet_type = ?,
            updated_at = ?
         WHERE id = ?",
        [
            $legalName,
            $legalName,
            $phone,
            $whatsapp,
            $type,
            $type,
            $address,
            $city,
            $state,
            $postalCode,
            $country,
            $currency,
            $currency,
            $invoicePrefix,
            $invoiceNextNumber,
            $taxNumber,
            $taxNumber,
            $taxLabel,
            !empty($taxNumber) ? 1 : 0,
            $bankName,
            $accountHolder,
            $accountNumber,
            $ifscCode,
            $walletId,
            $walletId,
            $walletType,
            $now,
            $tenantId
        ]
    );

    // Mask account number in activity log
    $masked = !empty($accountNumber) ? substr($accountNumber, 0, 2) . '****' . substr($accountNumber, -4) : 'none';
    ActivityLog::log($tenantId, (int)($authenticatedUser['user_id'] ?? $authenticatedUser['id']), 'business_profile_updated', 'business', $tenantId, "Shop profile updated: {$legalName} (Bank: {$bankName}, Account: {$masked})");

    $updated = Database::fetch(
        "SELECT id, tenant_uuid, name, legal_name, owner_name, type, industry,
                email, phone, whatsapp, address_line1, city, state, postal_code, country,
                gstin, tax_number, tax_label, gst_registered, default_currency, currency,
                bank_name, account_holder, account_number, ifsc_code, upi_id, wallet_id, wallet_type,
                invoice_prefix, invoice_next_number, status, onboarding_step
         FROM businesses WHERE id = ?",
        [$tenantId]
    );

    apiResponse([
        'message' => 'Shop profile updated successfully.',
        'business' => $updated
    ]);
}

apiError('Invalid business endpoint.', 'NOT_FOUND', 404);
