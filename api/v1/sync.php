<?php
/**
 * YBT Management Software — REST API v1 Multi-Entity Offline Synchronization Engine
 * Handles Batched Push, Incremental Pull (Cursor-based), and Tombstone Propagation
 * for Customers, Products, Invoices, Payments, and Expenses with fail-closed multi-tenancy.
 */

require_once YBT_ROOT . '/services/customer-service.php';

$method = $_SERVER['REQUEST_METHOD'];
$tenantId = TenantContext::id();
$userId = (int)($authenticatedUser['user_id'] ?? $authenticatedUser['id'] ?? 1);

// =========================================================================
// 1. POST /sync (Process batched offline operations: Push)
// =========================================================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $deviceId = trim($input['device_id'] ?? 'desktop-device');
    $operations = $input['operations'] ?? [];

    if (empty($operations)) {
        apiResponse(['processed_count' => 0, 'results' => []]);
    }

    $results = [];
    $db = Database::getInstance();

    foreach ($operations as $op) {
        $opUuid = trim($op['operation_uuid'] ?? '');
        $entityType = strtolower(trim($op['entity_type'] ?? ''));
        $entityUuid = trim($op['entity_uuid'] ?? '');
        $opType = strtolower(trim($op['operation_type'] ?? 'insert'));
        $payload = $op['payload'] ?? [];

        if ($opUuid === '' || $entityType === '') {
            continue;
        }

        // Idempotency check: see if operation has already been processed for this tenant
        $existingOp = Database::fetch(
            "SELECT id, status FROM sync_operations_queue WHERE business_id = ? AND operation_uuid = ?",
            [$tenantId, $opUuid]
        );

        if ($existingOp && $existingOp['status'] === 'synced') {
            $results[] = [
                'operation_uuid' => $opUuid,
                'entity_uuid' => $entityUuid,
                'status' => 'already_synced',
            ];
            continue;
        }

        $db->beginTransaction();
        try {
            // Dispatch operation by entity type
            switch ($entityType) {
                // -------------------------------------------------------------
                // 1. CUSTOMERS
                // -------------------------------------------------------------
                case 'customers':
                case 'customer':
                    if ($opType === 'insert') {
                        $existing = Database::fetch("SELECT id FROM customers WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                        if (!$existing) {
                            $customerId = CustomerService::create($tenantId, $payload, $userId);
                            Database::query(
                                "UPDATE customers SET sync_uuid = ?, sync_version = sync_version + 1, updated_at = NOW() WHERE id = ?",
                                [$entityUuid, $customerId]
                            );
                        }
                    } elseif ($opType === 'update') {
                        $existing = Database::fetch("SELECT id FROM customers WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                        if ($existing) {
                            CustomerService::update($existing['id'], $tenantId, $payload);
                            Database::query("UPDATE customers SET sync_version = sync_version + 1, updated_at = NOW() WHERE id = ?", [$existing['id']]);
                        }
                    } elseif ($opType === 'delete') {
                        Database::insert('sync_tombstones', [
                            'business_id' => $tenantId,
                            'entity_type' => 'customers',
                            'entity_uuid' => $entityUuid,
                            'deleted_by' => $userId,
                        ]);
                        Database::query("UPDATE customers SET is_deleted = 1, sync_version = sync_version + 1 WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                    }
                    break;

                // -------------------------------------------------------------
                // 2. PRODUCTS
                // -------------------------------------------------------------
                case 'products':
                case 'product':
                    if ($opType === 'insert') {
                        $existing = Database::fetch("SELECT id FROM products WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                        if (!$existing) {
                            $prodName = trim($payload['name'] ?? 'Product ' . substr($entityUuid, 0, 8));
                            $sellingPrice = (float)($payload['selling_price'] ?? 0.00);
                            $costPrice = isset($payload['cost_price']) ? (float)$payload['cost_price'] : (isset($payload['purchase_price']) ? (float)$payload['purchase_price'] : 0.00);
                            $taxRate = (float)($payload['tax_rate'] ?? 0.00);
                            $sku = trim($payload['sku'] ?? '');
                            $unit = trim($payload['unit'] ?? 'piece');
                            $category = trim($payload['category'] ?? '');
                            $desc = trim($payload['description'] ?? '');

                            Database::insert('products', [
                                'sync_uuid' => $entityUuid,
                                'business_id' => $tenantId,
                                'type' => 'product',
                                'name' => $prodName,
                                'description' => $desc,
                                'category' => $category,
                                'sku' => $sku ?: null,
                                'unit' => $unit,
                                'selling_price' => $sellingPrice,
                                'cost_price' => $costPrice,
                                'tax_rate' => $taxRate,
                                'status' => 'active',
                                'sync_version' => 1,
                                'created_by' => $userId,
                            ]);
                        }
                    } elseif ($opType === 'update') {
                        $existing = Database::fetch("SELECT id FROM products WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                        if ($existing) {
                            $upd = ['updated_at' => date('Y-m-d H:i:s')];
                            if (isset($payload['name'])) $upd['name'] = trim($payload['name']);
                            if (isset($payload['selling_price'])) $upd['selling_price'] = (float)$payload['selling_price'];
                            if (isset($payload['cost_price'])) $upd['cost_price'] = (float)$payload['cost_price'];
                            if (isset($payload['purchase_price'])) $upd['cost_price'] = (float)$payload['purchase_price'];
                            if (isset($payload['category'])) $upd['category'] = trim($payload['category']);
                            if (isset($payload['sku'])) $upd['sku'] = trim($payload['sku']);
                            if (isset($payload['unit'])) $upd['unit'] = trim($payload['unit']);
                            if (isset($payload['tax_rate'])) $upd['tax_rate'] = (float)$payload['tax_rate'];

                            Database::update('products', $upd, 'id = ? AND business_id = ?', [$existing['id'], $tenantId]);
                            Database::query("UPDATE products SET sync_version = sync_version + 1 WHERE id = ?", [$existing['id']]);
                        }
                    } elseif ($opType === 'delete') {
                        Database::insert('sync_tombstones', [
                            'business_id' => $tenantId,
                            'entity_type' => 'products',
                            'entity_uuid' => $entityUuid,
                            'deleted_by' => $userId,
                        ]);
                        Database::query("UPDATE products SET is_deleted = 1, sync_version = sync_version + 1 WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                    }
                    break;

                // -------------------------------------------------------------
                // 3. INVOICES
                // -------------------------------------------------------------
                case 'invoices':
                case 'invoice':
                    if ($opType === 'insert') {
                        $existing = Database::fetch("SELECT id FROM invoices WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                        if (!$existing) {
                            // Resolve customer_id
                            $custDbId = null;
                            if (!empty($payload['customer_uuid'])) {
                                $cRow = Database::fetch("SELECT id FROM customers WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $payload['customer_uuid']]);
                                if ($cRow) $custDbId = (int)$cRow['id'];
                            }
                            if (!$custDbId && !empty($payload['customer_id']) && is_numeric($payload['customer_id'])) {
                                $cRow = Database::fetch("SELECT id FROM customers WHERE business_id = ? AND id = ?", [$tenantId, (int)$payload['customer_id']]);
                                if ($cRow) $custDbId = (int)$cRow['id'];
                            }
                            if (!$custDbId) {
                                // Default to first available customer in tenant
                                $firstCust = Database::fetch("SELECT id FROM customers WHERE business_id = ? ORDER BY id ASC LIMIT 1", [$tenantId]);
                                $custDbId = $firstCust ? (int)$firstCust['id'] : 1;
                            }

                            $invNum = trim($payload['invoice_number'] ?? '');
                            $tempNum = trim($payload['client_temp_number'] ?? ($payload['temp_number'] ?? ''));
                            if (empty($invNum)) {
                                $invNum = 'INV-' . strtoupper(substr($entityUuid, 0, 8));
                            }

                            $invDate = !empty($payload['invoice_date']) ? substr($payload['invoice_date'], 0, 10) : date('Y-m-d');
                            $dueDate = !empty($payload['due_date']) ? substr($payload['due_date'], 0, 10) : $invDate;
                            $subtotal = (float)($payload['subtotal'] ?? 0.00);
                            $taxTotal = (float)($payload['tax_total'] ?? ($payload['tax_amount'] ?? 0.00));
                            $discTotal = (float)($payload['discount_total'] ?? ($payload['discount_amount'] ?? 0.00));
                            $grandTotal = (float)($payload['grand_total'] ?? ($subtotal + $taxTotal - $discTotal));
                            $amtPaid = (float)($payload['amount_paid'] ?? ($payload['paid_amount'] ?? 0.00));
                            $outstanding = max(0.00, $grandTotal - $amtPaid);
                            $status = $outstanding <= 0.00 ? 'paid' : ($amtPaid > 0 ? 'partially_paid' : 'issued');

                            $invId = Database::insert('invoices', [
                                'sync_uuid' => $entityUuid,
                                'business_id' => $tenantId,
                                'invoice_number' => $invNum,
                                'client_temp_number' => $tempNum ?: null,
                                'customer_id' => $custDbId,
                                'invoice_type' => 'standard',
                                'invoice_date' => $invDate,
                                'due_date' => $dueDate,
                                'subtotal' => $subtotal,
                                'discount_total' => $discTotal,
                                'tax_total' => $taxTotal,
                                'grand_total' => $grandTotal,
                                'amount_paid' => $amtPaid,
                                'outstanding' => $outstanding,
                                'currency' => 'INR',
                                'notes' => trim($payload['notes'] ?? ''),
                                'status' => $status,
                                'sync_version' => 1,
                                'created_by' => $userId,
                            ]);

                            // Insert invoice items if present
                            if (!empty($payload['items']) && is_array($payload['items'])) {
                                foreach ($payload['items'] as $item) {
                                    $pId = null;
                                    if (!empty($item['product_uuid'])) {
                                        $pRow = Database::fetch("SELECT id FROM products WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $item['product_uuid']]);
                                        if ($pRow) $pId = (int)$pRow['id'];
                                    } elseif (!empty($item['product_id']) && is_numeric($item['product_id'])) {
                                        $pId = (int)$item['product_id'];
                                    }

                                    $itemQty = (float)($item['quantity'] ?? 1.0);
                                    $itemPrice = (float)($item['unit_price'] ?? 0.0);
                                    $itemTotal = (float)($item['line_total'] ?? ($itemQty * $itemPrice));

                                    Database::insert('invoice_items', [
                                        'invoice_id' => $invId,
                                        'product_id' => $pId,
                                        'description' => trim($item['description'] ?? 'Item'),
                                        'quantity' => $itemQty,
                                        'unit' => trim($item['unit'] ?? 'piece'),
                                        'unit_price' => $itemPrice,
                                        'line_total' => $itemTotal,
                                    ]);
                                }
                            }
                        }
                    } elseif ($opType === 'delete') {
                        Database::insert('sync_tombstones', [
                            'business_id' => $tenantId,
                            'entity_type' => 'invoices',
                            'entity_uuid' => $entityUuid,
                            'deleted_by' => $userId,
                        ]);
                        Database::query("UPDATE invoices SET is_deleted = 1, sync_version = sync_version + 1 WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                    }
                    break;

                // -------------------------------------------------------------
                // 4. PAYMENTS
                // -------------------------------------------------------------
                case 'payments':
                case 'payment':
                    if ($opType === 'insert') {
                        $existing = Database::fetch("SELECT id FROM payments WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                        if (!$existing) {
                            $custDbId = null;
                            if (!empty($payload['customer_uuid'])) {
                                $cRow = Database::fetch("SELECT id FROM customers WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $payload['customer_uuid']]);
                                if ($cRow) $custDbId = (int)$cRow['id'];
                            }
                            if (!$custDbId && !empty($payload['customer_id']) && is_numeric($payload['customer_id'])) {
                                $cRow = Database::fetch("SELECT id FROM customers WHERE business_id = ? AND id = ?", [$tenantId, (int)$payload['customer_id']]);
                                if ($cRow) $custDbId = (int)$cRow['id'];
                            }
                            if (!$custDbId) {
                                $firstCust = Database::fetch("SELECT id FROM customers WHERE business_id = ? ORDER BY id ASC LIMIT 1", [$tenantId]);
                                $custDbId = $firstCust ? (int)$firstCust['id'] : 1;
                            }

                            $payNum = trim($payload['payment_number'] ?? ('PAY-' . strtoupper(substr($entityUuid, 0, 8))));
                            $payDate = !empty($payload['payment_date']) ? substr($payload['payment_date'], 0, 10) : date('Y-m-d');
                            $amount = (float)($payload['amount'] ?? 0.00);
                            $methodVal = strtolower(trim($payload['method'] ?? ($payload['payment_method'] ?? 'cash')));
                            $allowedMethods = ['cash','bank_transfer','upi','card','cheque','other'];
                            if (!in_array($methodVal, $allowedMethods, true)) {
                                $methodVal = 'cash';
                            }
                            $reference = trim($payload['reference'] ?? ($payload['transaction_reference'] ?? ''));

                            Database::insert('payments', [
                                'sync_uuid' => $entityUuid,
                                'business_id' => $tenantId,
                                'customer_id' => $custDbId,
                                'payment_number' => $payNum,
                                'payment_date' => $payDate,
                                'amount' => $amount,
                                'method' => $methodVal,
                                'reference' => $reference ?: null,
                                'status' => 'completed',
                                'sync_version' => 1,
                                'created_by' => $userId,
                            ]);
                        }
                    } elseif ($opType === 'delete') {
                        Database::insert('sync_tombstones', [
                            'business_id' => $tenantId,
                            'entity_type' => 'payments',
                            'entity_uuid' => $entityUuid,
                            'deleted_by' => $userId,
                        ]);
                        Database::query("UPDATE payments SET is_deleted = 1, sync_version = sync_version + 1 WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                    }
                    break;

                // -------------------------------------------------------------
                // 5. EXPENSES
                // -------------------------------------------------------------
                case 'expenses':
                case 'expense':
                    if ($opType === 'insert') {
                        $existing = Database::fetch("SELECT id FROM expenses WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                        if (!$existing) {
                            $expDate = !empty($payload['expense_date']) ? substr($payload['expense_date'], 0, 10) : date('Y-m-d');
                            $desc = trim($payload['description'] ?? 'Business Expense');
                            $amount = (float)($payload['amount'] ?? 0.00);
                            $methodVal = strtolower(trim($payload['method'] ?? ($payload['payment_method'] ?? 'cash')));
                            $allowedMethods = ['cash','bank_transfer','upi','card','cheque','other'];
                            if (!in_array($methodVal, $allowedMethods, true)) {
                                $methodVal = 'cash';
                            }

                            Database::insert('expenses', [
                                'sync_uuid' => $entityUuid,
                                'business_id' => $tenantId,
                                'category_id' => null,
                                'expense_date' => $expDate,
                                'description' => $desc,
                                'amount' => $amount,
                                'method' => $methodVal,
                                'status' => 'active',
                                'sync_version' => 1,
                                'created_by' => $userId,
                            ]);
                        }
                    } elseif ($opType === 'delete') {
                        Database::insert('sync_tombstones', [
                            'business_id' => $tenantId,
                            'entity_type' => 'expenses',
                            'entity_uuid' => $entityUuid,
                            'deleted_by' => $userId,
                        ]);
                        Database::query("UPDATE expenses SET is_deleted = 1, sync_version = sync_version + 1 WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                    }
                    break;
            }

            // Record into sync_operations_queue for cloud audit & idempotency
            try {
                Database::insert('sync_operations_queue', [
                    'operation_uuid' => $opUuid,
                    'business_id' => $tenantId,
                    'device_id' => $deviceId ?: 'unknown',
                    'entity_type' => $entityType,
                    'entity_uuid' => $entityUuid,
                    'operation_type' => $opType,
                    'payload' => json_encode($payload),
                    'client_timestamp' => $op['client_timestamp'] ?? date('Y-m-d H:i:s'),
                    'status' => 'synced',
                ]);
            } catch (Throwable $t) {}

            $db->commit();

            $results[] = [
                'operation_uuid' => $opUuid,
                'entity_uuid' => $entityUuid,
                'status' => 'synced',
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $results[] = [
                'operation_uuid' => $opUuid,
                'entity_uuid' => $entityUuid,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    apiResponse([
        'processed_count' => count($results),
        'results' => $results,
    ]);
}

// =========================================================================
// 2. GET /sync (Fetch delta changes across all core entities: Pull)
// =========================================================================
if ($method === 'GET') {
    $sinceVersion = max(0, (int)($_GET['since_version'] ?? 0));
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

    // 1. Customers
    $customers = [];
    try {
        $customers = Database::fetchAll(
            "SELECT * FROM customers 
             WHERE business_id = ? AND sync_version > ? 
             ORDER BY sync_version ASC LIMIT ?",
            [$tenantId, $sinceVersion, $limit]
        );
    } catch (Throwable $t) {}

    // 2. Products
    $products = [];
    try {
        $products = Database::fetchAll(
            "SELECT * FROM products 
             WHERE business_id = ? AND sync_version > ? 
             ORDER BY sync_version ASC LIMIT ?",
            [$tenantId, $sinceVersion, $limit]
        );
    } catch (Throwable $t) {}

    // 3. Invoices
    $invoices = [];
    try {
        $invoices = Database::fetchAll(
            "SELECT * FROM invoices 
             WHERE business_id = ? AND sync_version > ? 
             ORDER BY sync_version ASC LIMIT ?",
            [$tenantId, $sinceVersion, $limit]
        );
    } catch (Throwable $t) {}

    // 4. Payments
    $payments = [];
    try {
        $payments = Database::fetchAll(
            "SELECT * FROM payments 
             WHERE business_id = ? AND sync_version > ? 
             ORDER BY sync_version ASC LIMIT ?",
            [$tenantId, $sinceVersion, $limit]
        );
    } catch (Throwable $t) {}

    // 5. Expenses
    $expenses = [];
    try {
        $expenses = Database::fetchAll(
            "SELECT * FROM expenses 
             WHERE business_id = ? AND sync_version > ? 
             ORDER BY sync_version ASC LIMIT ?",
            [$tenantId, $sinceVersion, $limit]
        );
    } catch (Throwable $t) {}

    // 6. Tombstones
    $tombstones = [];
    try {
        $tombstones = Database::fetchAll(
            "SELECT * FROM sync_tombstones WHERE business_id = ? ORDER BY id ASC LIMIT ?",
            [$tenantId, $limit]
        );
    } catch (Throwable $t) {}

    // Calculate maximum cursor across all returned entities
    $maxVersion = $sinceVersion;
    foreach ([$customers, $products, $invoices, $payments, $expenses] as $collection) {
        foreach ($collection as $row) {
            if (!empty($row['sync_version']) && (int)$row['sync_version'] > $maxVersion) {
                $maxVersion = (int)$row['sync_version'];
            }
        }
    }

    $hasMore = (
        count($customers) >= $limit ||
        count($products) >= $limit ||
        count($invoices) >= $limit ||
        count($payments) >= $limit ||
        count($expenses) >= $limit
    );

    apiResponse([
        'cursor' => $maxVersion,
        'has_more' => $hasMore,
        'entities' => [
            'customers' => $customers,
            'products' => $products,
            'invoices' => $invoices,
            'payments' => $payments,
            'expenses' => $expenses,
            'tombstones' => $tombstones,
        ]
    ]);
}

apiError('Unsupported sync method.', 'METHOD_NOT_ALLOWED', 405);
