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
                        $existing = Database::fetch("SELECT id FROM products WHERE business_id = ? AND (sync_uuid = ? OR uuid = ?)", [$tenantId, $entityUuid, $entityUuid]);
                        if (!$existing) {
                            $prodName = trim($payload['name'] ?? 'Product ' . substr($entityUuid, 0, 8));
                            $sellingPrice = (float)($payload['selling_price'] ?? 0.00);
                            $costPrice = isset($payload['cost_price']) ? (float)$payload['cost_price'] : (isset($payload['purchase_price']) ? (float)$payload['purchase_price'] : 0.00);
                            $taxRate = (float)($payload['tax_rate'] ?? 0.00);
                            $sku = trim($payload['sku'] ?? '');
                            $barcode = trim($payload['barcode'] ?? '');
                            $unit = trim($payload['unit'] ?? 'pcs');
                            $category = trim($payload['category'] ?? '');
                            $partType = trim($payload['part_type'] ?? ($category ?: 'Display / Folder'));
                            $brand = trim($payload['brand'] ?? '');
                            $model = trim($payload['model'] ?? '');
                            $supplier = trim($payload['supplier_name'] ?? '');
                            $allowNeg = !empty($payload['allow_negative_stock']) ? 1 : 0;
                            $stockQty = (float)($payload['stock_quantity'] ?? 0.0);
                            $minStock = (int)($payload['min_stock_alert'] ?? 5);
                            $desc = trim($payload['description'] ?? '');

                            Database::insert('products', [
                                'uuid' => $entityUuid,
                                'sync_uuid' => $entityUuid,
                                'business_id' => $tenantId,
                                'name' => $prodName,
                                'brand' => $brand ?: null,
                                'model' => $model ?: null,
                                'part_type' => $partType,
                                'category' => $category ?: $partType,
                                'sku' => $sku ?: null,
                                'barcode' => $barcode ?: null,
                                'unit' => $unit,
                                'selling_price' => $sellingPrice,
                                'cost_price' => $costPrice,
                                'purchase_price' => $costPrice,
                                'tax_rate' => $taxRate,
                                'stock_quantity' => $stockQty,
                                'min_stock_alert' => $minStock,
                                'supplier_name' => $supplier ?: null,
                                'allow_negative_stock' => $allowNeg,
                                'description' => $desc ?: null,
                                'status' => $payload['status'] ?? 'active',
                                'sync_status' => 'synced',
                                'sync_version' => 1,
                                'created_by' => $userId,
                            ]);
                        }
                    } elseif ($opType === 'update') {
                        $existing = Database::fetch("SELECT id FROM products WHERE business_id = ? AND (sync_uuid = ? OR uuid = ?)", [$tenantId, $entityUuid, $entityUuid]);
                        if ($existing) {
                            $upd = ['updated_at' => date('Y-m-d H:i:s')];
                            if (isset($payload['name'])) $upd['name'] = trim($payload['name']);
                            if (isset($payload['selling_price'])) $upd['selling_price'] = (float)$payload['selling_price'];
                            if (isset($payload['cost_price'])) {
                                $upd['cost_price'] = (float)$payload['cost_price'];
                                $upd['purchase_price'] = (float)$payload['cost_price'];
                            }
                            if (isset($payload['purchase_price'])) {
                                $upd['cost_price'] = (float)$payload['purchase_price'];
                                $upd['purchase_price'] = (float)$payload['purchase_price'];
                            }
                            if (isset($payload['category'])) $upd['category'] = trim($payload['category']);
                            if (isset($payload['part_type'])) $upd['part_type'] = trim($payload['part_type']);
                            if (isset($payload['brand'])) $upd['brand'] = trim($payload['brand']) ?: null;
                            if (isset($payload['model'])) $upd['model'] = trim($payload['model']) ?: null;
                            if (isset($payload['sku'])) $upd['sku'] = trim($payload['sku']) ?: null;
                            if (isset($payload['barcode'])) $upd['barcode'] = trim($payload['barcode']) ?: null;
                            if (isset($payload['unit'])) $upd['unit'] = trim($payload['unit']);
                            if (isset($payload['tax_rate'])) $upd['tax_rate'] = (float)$payload['tax_rate'];
                            if (isset($payload['stock_quantity'])) $upd['stock_quantity'] = (float)$payload['stock_quantity'];
                            if (isset($payload['min_stock_alert'])) $upd['min_stock_alert'] = (int)$payload['min_stock_alert'];
                            if (isset($payload['supplier_name'])) $upd['supplier_name'] = trim($payload['supplier_name']) ?: null;
                            if (isset($payload['status'])) $upd['status'] = trim($payload['status']);

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

                // -------------------------------------------------------------
                // 6. CUSTOMER LEDGER (KHATA TRANSACTIONS: YOU GAVE / YOU GOT)
                // -------------------------------------------------------------
                case 'customer_ledger':
                case 'ledger':
                case 'khata':
                    if ($opType === 'insert') {
                        $idempKey = trim($payload['idempotency_key'] ?? '');
                        
                        // Financial Idempotency Check: check both sync_uuid and idempotency_key
                        $existing = null;
                        if ($idempKey !== '') {
                            $existing = Database::fetch(
                                "SELECT id FROM customer_ledger WHERE business_id = ? AND (sync_uuid = ? OR idempotency_key = ?)",
                                [$tenantId, $entityUuid, $idempKey]
                            );
                        } else {
                            $existing = Database::fetch(
                                "SELECT id FROM customer_ledger WHERE business_id = ? AND sync_uuid = ?",
                                [$tenantId, $entityUuid]
                            );
                        }

                        if (!$existing) {
                            // Resolve customer_id
                            $custDbId = null;
                            if (!empty($payload['customer_uuid'])) {
                                $cRow = Database::fetch("SELECT id FROM customers WHERE business_id = ? AND uuid = ?", [$tenantId, $payload['customer_uuid']]);
                                if ($cRow) $custDbId = (int)$cRow['id'];
                            }
                            if (!$custDbId && !empty($payload['customer_id']) && is_numeric($payload['customer_id'])) {
                                $cRow = Database::fetch("SELECT id FROM customers WHERE business_id = ? AND id = ?", [$tenantId, (int)$payload['customer_id']]);
                                if ($cRow) $custDbId = (int)$cRow['id'];
                            }
                            if (!$custDbId) {
                                throw new Exception("Customer referenced in ledger operation not found for this tenant.");
                            }

                            $entryType = trim($payload['entry_type'] ?? 'you_gave');
                            $entryDate = !empty($payload['entry_date']) ? substr($payload['entry_date'], 0, 10) : date('Y-m-d');
                            $debit = (float)($payload['debit'] ?? 0.00);
                            $credit = (float)($payload['credit'] ?? 0.00);

                            // Recalculate customer running balance authoritatively on server
                            $custRow = Database::fetch("SELECT current_balance FROM customers WHERE id = ? AND business_id = ?", [$custDbId, $tenantId]);
                            $currBal = $custRow ? (float)$custRow['current_balance'] : 0.00;
                            $newBal = $currBal + $debit - $credit;

                            Database::insert('customer_ledger', [
                                'uuid' => $entityUuid,
                                'sync_uuid' => $entityUuid,
                                'business_id' => $tenantId,
                                'customer_id' => $custDbId,
                                'entry_type' => $entryType,
                                'reference_type' => $payload['reference_type'] ?? null,
                                'reference_id' => !empty($payload['reference_id']) ? (int)$payload['reference_id'] : null,
                                'reference_number' => $payload['reference_number'] ?? null,
                                'entry_date' => $entryDate,
                                'debit' => $debit,
                                'credit' => $credit,
                                'running_balance' => $newBal,
                                'payment_method' => $payload['payment_method'] ?? 'cash',
                                'description' => trim($payload['description'] ?? ''),
                                'notes' => trim($payload['notes'] ?? ''),
                                'item_details' => $payload['item_details'] ?? null,
                                'image_attachment' => $payload['image_attachment'] ?? null,
                                'voice_attachment' => $payload['voice_attachment'] ?? null,
                                'idempotency_key' => $idempKey ?: null,
                                'sync_status' => 'synced',
                                'sync_version' => 1,
                                'created_by' => $userId,
                                'created_at' => $op['client_timestamp'] ?? date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);

                            // Update customer's current balance
                            Database::query(
                                "UPDATE customers SET current_balance = ?, updated_at = NOW() WHERE id = ? AND business_id = ?",
                                [$newBal, $custDbId, $tenantId]
                            );
                        }
                    } elseif ($opType === 'update' || $opType === 'delete') {
                        throw new Exception("Financial ledger entries are strictly append-only and cannot be updated or deleted. Please record an offsetting reversal entry.");
                    }
                    break;

                // -------------------------------------------------------------
                // 7. STOCK MOVEMENTS / INVENTORY
                // -------------------------------------------------------------
                case 'stock_movements':
                case 'stock_movement':
                case 'inventory':
                case 'stock':
                    if ($opType === 'insert') {
                        $idempKey = trim($payload['idempotency_key'] ?? '');

                        // Idempotency check: see if movement already processed
                        $existing = null;
                        if ($idempKey !== '') {
                            $existing = Database::fetch(
                                "SELECT id FROM stock_movements WHERE business_id = ? AND (sync_uuid = ? OR idempotency_key = ?)",
                                [$tenantId, $entityUuid, $idempKey]
                            );
                        } else {
                            $existing = Database::fetch(
                                "SELECT id FROM stock_movements WHERE business_id = ? AND sync_uuid = ?",
                                [$tenantId, $entityUuid]
                            );
                        }

                        if (!$existing) {
                            // Resolve product_id for this tenant
                            $prodDbId = null;
                            $currentProd = null;
                            if (!empty($payload['product_uuid'])) {
                                $pRow = Database::fetch(
                                    "SELECT id, stock_quantity, allow_negative_stock FROM products WHERE business_id = ? AND (uuid = ? OR sync_uuid = ?)",
                                    [$tenantId, $payload['product_uuid'], $payload['product_uuid']]
                                );
                                if ($pRow) {
                                    $prodDbId = (int)$pRow['id'];
                                    $currentProd = $pRow;
                                }
                            }
                            if (!$prodDbId && !empty($payload['product_id']) && is_numeric($payload['product_id'])) {
                                $pRow = Database::fetch(
                                    "SELECT id, stock_quantity, allow_negative_stock FROM products WHERE business_id = ? AND id = ?",
                                    [$tenantId, (int)$payload['product_id']]
                                );
                                if ($pRow) {
                                    $prodDbId = (int)$pRow['id'];
                                    $currentProd = $pRow;
                                }
                            }
                            if (!$prodDbId) {
                                throw new Exception("Product referenced in stock movement not found for this tenant.");
                            }

                            $movType = trim($payload['type'] ?? 'adjustment');
                            $deltaQty = (float)($payload['quantity'] ?? 0.0);
                            $currStock = (float)($currentProd['stock_quantity'] ?? 0.0);
                            $newStock = $currStock + $deltaQty;

                            // Conflict & Negative Stock Guard
                            if ($newStock < 0 && empty($currentProd['allow_negative_stock'])) {
                                throw new Exception("Conflict: Stock adjustment would result in invalid negative stock ({$newStock}) for product #{$prodDbId}.");
                            }

                            // Update product stock atomically
                            Database::query(
                                "UPDATE products SET stock_quantity = ?, sync_version = sync_version + 1, updated_at = NOW() WHERE id = ? AND business_id = ?",
                                [$newStock, $prodDbId, $tenantId]
                            );

                            Database::insert('stock_movements', [
                                'uuid' => $entityUuid,
                                'sync_uuid' => $entityUuid,
                                'business_id' => $tenantId,
                                'product_id' => $prodDbId,
                                'type' => $movType,
                                'quantity' => $deltaQty,
                                'quantity_before' => $currStock,
                                'quantity_after' => $newStock,
                                'reference_type' => $payload['reference_type'] ?? null,
                                'reference_id' => !empty($payload['reference_id']) ? (int)$payload['reference_id'] : null,
                                'reference_number' => $payload['reference_number'] ?? null,
                                'notes' => $payload['notes'] ?? null,
                                'idempotency_key' => $idempKey ?: null,
                                'sync_status' => 'synced',
                                'sync_version' => 1,
                                'created_by' => $userId,
                                'created_at' => $payload['created_at'] ?? date('Y-m-d H:i:s'),
                            ]);
                        }
                    } elseif ($opType === 'update' || $opType === 'delete') {
                        throw new Exception("Inventory stock movements are strictly append-only audit trail and cannot be modified or deleted directly.");
                    }
                    break;

                // -------------------------------------------------------------
                // 7. BUSINESS / SHOP PROFILE DEDUPLICATION
                // -------------------------------------------------------------
                case 'business':
                case 'businesses':
                    $biz = Database::fetch("SELECT id FROM businesses WHERE tenant_uuid = ? OR id = ?", [$entityUuid, $tenantId]);
                    if ($biz) {
                        Database::query(
                            "UPDATE businesses SET 
                                legal_name = COALESCE(?, legal_name),
                                name = COALESCE(?, name),
                                phone = COALESCE(?, phone),
                                whatsapp = COALESCE(?, whatsapp),
                                address_line1 = COALESCE(?, address_line1),
                                city = COALESCE(?, city),
                                state = COALESCE(?, state),
                                postal_code = COALESCE(?, postal_code),
                                country = COALESCE(?, country),
                                default_currency = COALESCE(?, default_currency),
                                currency = COALESCE(?, currency),
                                tax_number = COALESCE(?, tax_number),
                                gstin = COALESCE(?, gstin),
                                bank_name = COALESCE(?, bank_name),
                                account_holder = COALESCE(?, account_holder),
                                account_number = COALESCE(?, account_number),
                                ifsc_code = COALESCE(?, ifsc_code),
                                wallet_id = COALESCE(?, wallet_id),
                                wallet_type = COALESCE(?, wallet_type),
                                updated_at = datetime('now')
                             WHERE id = ?",
                            [
                                $payload['legal_name'] ?? null,
                                $payload['name'] ?? null,
                                $payload['phone'] ?? null,
                                $payload['whatsapp'] ?? null,
                                $payload['address_line1'] ?? null,
                                $payload['city'] ?? null,
                                $payload['state'] ?? null,
                                $payload['postal_code'] ?? null,
                                $payload['country'] ?? null,
                                $payload['default_currency'] ?? $payload['currency'] ?? null,
                                $payload['default_currency'] ?? $payload['currency'] ?? null,
                                $payload['tax_number'] ?? $payload['gstin'] ?? null,
                                $payload['tax_number'] ?? $payload['gstin'] ?? null,
                                $payload['bank_name'] ?? null,
                                $payload['account_holder'] ?? null,
                                $payload['account_number'] ?? null,
                                $payload['ifsc_code'] ?? null,
                                $payload['wallet_id'] ?? $payload['upi_id'] ?? null,
                                $payload['wallet_type'] ?? null,
                                $biz['id']
                            ]
                        );
                    }
                    break;
            }

            // Record into sync_operations_queue for cloud audit & idempotency with sensitive data masking
            try {
                $loggedPayload = $payload;
                if (isset($loggedPayload['account_number'])) {
                    $acc = (string)$loggedPayload['account_number'];
                    $loggedPayload['account_number'] = strlen($acc) > 4 ? substr($acc, 0, 2) . '****' . substr($acc, -4) : '****';
                }
                if (isset($loggedPayload['password'])) {
                    $loggedPayload['password'] = '********';
                }

                Database::insert('sync_operations_queue', [
                    'operation_uuid' => $opUuid,
                    'business_id' => $tenantId,
                    'device_id' => $deviceId ?: 'unknown',
                    'entity_type' => $entityType,
                    'entity_uuid' => $entityUuid,
                    'operation_type' => $opType,
                    'payload' => json_encode($loggedPayload),
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
            "SELECT i.*, 
                    i.total_amount as grand_total, 
                    i.paid_amount as amount_paid, 
                    i.balance_amount as outstanding, 
                    i.issue_date as invoice_date, 
                    i.tax_amount as tax_total, 
                    i.discount_amount as discount_total
             FROM invoices i
             WHERE i.business_id = ? AND i.sync_version > ? 
             ORDER BY i.sync_version ASC LIMIT ?",
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

    // 6. Customer Ledger
    $ledger = [];
    try {
        $ledger = Database::fetchAll(
            "SELECT l.*, c.uuid as customer_uuid
             FROM customer_ledger l
             LEFT JOIN customers c ON l.customer_id = c.id
             WHERE l.business_id = ? AND l.sync_version > ? 
             ORDER BY l.sync_version ASC, l.id ASC LIMIT ?",
            [$tenantId, $sinceVersion, $limit]
        );
    } catch (Throwable $t) {}

    // 7. Stock Movements
    $stockMovements = [];
    try {
        $stockMovements = Database::fetchAll(
            "SELECT m.*, p.uuid as product_uuid
             FROM stock_movements m
             LEFT JOIN products p ON m.product_id = p.id
             WHERE m.business_id = ? AND m.sync_version > ? 
             ORDER BY m.sync_version ASC, m.id ASC LIMIT ?",
            [$tenantId, $sinceVersion, $limit]
        );
    } catch (Throwable $t) {}

    // 8. Tombstones
    $tombstones = [];
    try {
        $tombstones = Database::fetchAll(
            "SELECT * FROM sync_tombstones WHERE business_id = ? ORDER BY id ASC LIMIT ?",
            [$tenantId, $limit]
        );
    } catch (Throwable $t) {}

    // Calculate maximum cursor across all returned entities
    $maxVersion = $sinceVersion;
    foreach ([$customers, $products, $invoices, $payments, $expenses, $ledger, $stockMovements] as $collection) {
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
        count($expenses) >= $limit ||
        count($stockMovements) >= $limit
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
            'customer_ledger' => $ledger,
            'stock_movements' => $stockMovements,
            'tombstones' => $tombstones,
        ]
    ]);
}

apiError('Unsupported sync method.', 'METHOD_NOT_ALLOWED', 405);
