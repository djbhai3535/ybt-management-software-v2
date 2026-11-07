<?php
/**
 * YBT Management Software — REST API v1 Invoices Endpoint
 * Handles server-authoritative sequential numbering and collision-resistant offline reconciliation.
 */

require_once YBT_ROOT . '/services/invoice-service.php';

$method = $_SERVER['REQUEST_METHOD'];
$tenantId = TenantContext::id();
$userId = (int)($authenticatedUser['user_id'] ?? $authenticatedUser['id'] ?? 1);

$parts = array_values(array_filter(explode('/', trim($route, '/'))));
$invoiceId = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : 0;

if ($method === 'GET') {
    if ($invoiceId > 0) {
        $inv = Database::fetch(
            "SELECT i.*, c.name as customer_name, c.company_name, c.email as customer_email, c.phone as customer_phone
             FROM invoices i
             JOIN customers c ON i.customer_id = c.id
             WHERE i.id = ? AND i.business_id = ?",
            [$invoiceId, $tenantId]
        );
        if (!$inv) {
            apiError('Invoice not found.', 'NOT_FOUND', 404);
        }
        $inv['items'] = Database::fetchAll(
            "SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC",
            [$invoiceId]
        );
        apiResponse($inv);
    } else {
        $invoices = Database::fetchAll(
            "SELECT i.*, 
                    i.total_amount as grand_total, 
                    i.paid_amount as amount_paid, 
                    i.balance_amount as outstanding, 
                    i.issue_date as invoice_date, 
                    c.name as customer_name, c.company_name
             FROM invoices i
             JOIN customers c ON i.customer_id = c.id
             WHERE i.business_id = ?
             ORDER BY i.issue_date DESC, i.id DESC
             LIMIT 50",
            [$tenantId]
        );
        apiResponse($invoices);
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $customerId = (int)($input['customer_id'] ?? 0);
    $items = $input['items'] ?? [];
    $syncUuid = trim($input['sync_uuid'] ?? '') ?: generateUuidV4();
    $clientTempNumber = trim($input['client_temp_number'] ?? '');

    if ($customerId <= 0) {
        apiError('Valid customer_id is required.', 'VALIDATION_FAILED', 422);
    }

    if (empty($items)) {
        apiError('Invoice must contain at least one line item.', 'VALIDATION_FAILED', 422);
    }

    // Idempotency check: if sync_uuid was already processed, return existing invoice
    $existing = Database::fetch(
        "SELECT id, invoice_number, total_amount as grand_total, status FROM invoices WHERE business_id = ? AND sync_uuid = ?",
        [$tenantId, $syncUuid]
    );

    if ($existing) {
        apiResponse([
            'invoice_id' => (int)$existing['id'],
            'canonical_invoice_number' => $existing['invoice_number'],
            'client_temp_number' => $clientTempNumber,
            'grand_total' => (float)$existing['grand_total'],
            'status' => $existing['status'],
        ], 200, ['idempotent_replay' => true]);
    }

    // Create Invoice with Server-Authoritative Sequential Number
    $newInvoiceId = InvoiceService::create($tenantId, [
        'customer_id' => $customerId,
        'invoice_date' => $input['invoice_date'] ?? date('Y-m-d'),
        'due_date' => $input['due_date'] ?? date('Y-m-d', strtotime('+15 days')),
        'discount_total' => (float)($input['discount_total'] ?? 0),
        'notes' => trim($input['notes'] ?? ''),
        'status' => $input['status'] ?? 'sent',
    ], $items, $userId);

    // Attach sync metadata and client temporary reference
    try {
        Database::query(
            "UPDATE invoices SET sync_uuid = ?, client_temp_number = ?, sync_version = sync_version + 1, client_updated_at = NOW() WHERE id = ?",
            [$syncUuid, $clientTempNumber ?: null, $newInvoiceId]
        );
    } catch (Throwable $t) {}

    $created = Database::fetch("SELECT * FROM invoices WHERE id = ?", [$newInvoiceId]);

    apiResponse([
        'invoice_id' => (int)$created['id'],
        'sync_uuid' => $syncUuid,
        'canonical_invoice_number' => $created['invoice_number'],
        'client_temp_number' => $clientTempNumber,
        'grand_total' => (float)$created['grand_total'],
        'status' => $created['status'],
    ], 201);
}

apiError('Unsupported invoice method.', 'METHOD_NOT_ALLOWED', 405);
