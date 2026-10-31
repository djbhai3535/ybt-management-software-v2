<?php
/**
 * YBT Management Software — Desktop Offline-First Sync Engine
 * Manages local pending operations, push batching, delta pull processing,
 * and conflict-free idempotency between SQLite and Cloud REST API v1
 * for Customers, Products, Invoices, Payments, and Expenses.
 */

require_once __DIR__ . '/SqliteClient.php';

class SyncEngine {
    private SqliteClient $sqlite;

    public function __construct(SqliteClient $sqlite) {
        $this->sqlite = $sqlite;
    }

    /**
     * 1. Create a customer offline inside SQLite and enqueue pending sync operation
     */
    public function createCustomerOffline(int $businessId, string $deviceId, array $data): array {
        $customerUuid = $this->generateUuid();
        $operationUuid = $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $db = $this->sqlite->getPdo();
        $db->beginTransaction();

        try {
            $localId = $this->sqlite->insert('customers', [
                'uuid' => $customerUuid,
                'business_id' => $businessId,
                'name' => trim($data['name']),
                'phone' => trim($data['phone'] ?? ''),
                'email' => strtolower(trim($data['email'] ?? '')),
                'address' => trim($data['address'] ?? ''),
                'city' => trim($data['city'] ?? ''),
                'state' => trim($data['state'] ?? ''),
                'pincode' => trim($data['pincode'] ?? ''),
                'gstin' => strtoupper(trim($data['gstin'] ?? '')),
                'notes' => trim($data['notes'] ?? ''),
                'sync_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now
            ]);

            $payload = [
                'name' => trim($data['name']),
                'phone' => trim($data['phone'] ?? ''),
                'email' => strtolower(trim($data['email'] ?? '')),
                'billing_address_line1' => trim($data['address'] ?? ''),
                'billing_city' => trim($data['city'] ?? ''),
                'billing_state' => trim($data['state'] ?? ''),
                'billing_postal_code' => trim($data['pincode'] ?? ''),
                'gstin' => strtoupper(trim($data['gstin'] ?? '')),
                'notes' => trim($data['notes'] ?? '')
            ];

            $this->sqlite->insert('pending_operations', [
                'operation_uuid' => $operationUuid,
                'business_id' => $businessId,
                'device_id' => $deviceId,
                'entity_type' => 'customers',
                'entity_uuid' => $customerUuid,
                'operation_type' => 'insert',
                'payload_json' => json_encode($payload),
                'status' => 'pending',
                'created_at' => $now
            ]);

            $db->commit();

            return [
                'success' => true,
                'local_id' => $localId,
                'uuid' => $customerUuid,
                'operation_uuid' => $operationUuid,
                'sync_status' => 'pending'
            ];
        } catch (Throwable $t) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Offline customer creation error: ' . $t->getMessage());
            return ['success' => false, 'error' => $t->getMessage()];
        }
    }

    /**
     * 2. Create a product offline inside SQLite and enqueue pending sync operation
     */
    public function createProductOffline(int $businessId, string $deviceId, array $data): array {
        $productUuid = $this->generateUuid();
        $operationUuid = $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $db = $this->sqlite->getPdo();
        $db->beginTransaction();

        try {
            $localId = $this->sqlite->insert('products', [
                'uuid' => $productUuid,
                'business_id' => $businessId,
                'name' => trim($data['name']),
                'category' => trim($data['category'] ?? 'General'),
                'sku' => trim($data['sku'] ?? ''),
                'barcode' => trim($data['barcode'] ?? ''),
                'purchase_price' => (float)($data['purchase_price'] ?? ($data['cost_price'] ?? 0.00)),
                'selling_price' => (float)($data['selling_price'] ?? 0.00),
                'tax_rate' => (float)($data['tax_rate'] ?? 0.00),
                'stock_quantity' => (int)($data['stock_quantity'] ?? 0),
                'min_stock_alert' => (int)($data['min_stock_alert'] ?? 5),
                'unit' => trim($data['unit'] ?? 'pcs'),
                'sync_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now
            ]);

            $payload = [
                'name' => trim($data['name']),
                'category' => trim($data['category'] ?? 'General'),
                'sku' => trim($data['sku'] ?? ''),
                'barcode' => trim($data['barcode'] ?? ''),
                'cost_price' => (float)($data['purchase_price'] ?? ($data['cost_price'] ?? 0.00)),
                'selling_price' => (float)($data['selling_price'] ?? 0.00),
                'tax_rate' => (float)($data['tax_rate'] ?? 0.00),
                'unit' => trim($data['unit'] ?? 'pcs'),
                'description' => trim($data['description'] ?? '')
            ];

            $this->sqlite->insert('pending_operations', [
                'operation_uuid' => $operationUuid,
                'business_id' => $businessId,
                'device_id' => $deviceId,
                'entity_type' => 'products',
                'entity_uuid' => $productUuid,
                'operation_type' => 'insert',
                'payload_json' => json_encode($payload),
                'status' => 'pending',
                'created_at' => $now
            ]);

            $db->commit();

            return [
                'success' => true,
                'local_id' => $localId,
                'uuid' => $productUuid,
                'operation_uuid' => $operationUuid,
                'sync_status' => 'pending'
            ];
        } catch (Throwable $t) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Offline product creation error: ' . $t->getMessage());
            return ['success' => false, 'error' => $t->getMessage()];
        }
    }

    /**
     * 3. Create an invoice offline inside SQLite and enqueue pending sync operation
     */
    public function createInvoiceOffline(int $businessId, string $deviceId, array $data, array $items = []): array {
        $invoiceUuid = $this->generateUuid();
        $operationUuid = $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $db = $this->sqlite->getPdo();
        $db->beginTransaction();

        try {
            $subtotal = (float)($data['subtotal'] ?? 0.00);
            $taxAmount = (float)($data['tax_amount'] ?? ($data['tax_total'] ?? 0.00));
            $discountAmount = (float)($data['discount_amount'] ?? ($data['discount_total'] ?? 0.00));
            $grandTotal = (float)($data['grand_total'] ?? ($subtotal + $taxAmount - $discountAmount));
            $paidAmount = (float)($data['paid_amount'] ?? ($data['amount_paid'] ?? 0.00));
            $paymentStatus = $paidAmount >= $grandTotal ? 'paid' : ($paidAmount > 0 ? 'partially_paid' : 'unpaid');
            $invNum = trim($data['invoice_number'] ?? ('INV-' . strtoupper(substr($invoiceUuid, 0, 8))));

            $localId = $this->sqlite->insert('invoices', [
                'uuid' => $invoiceUuid,
                'business_id' => $businessId,
                'customer_id' => !empty($data['customer_id']) ? (int)$data['customer_id'] : null,
                'customer_uuid' => trim($data['customer_uuid'] ?? ''),
                'invoice_number' => $invNum,
                'client_temp_number' => trim($data['client_temp_number'] ?? $invNum),
                'invoice_date' => !empty($data['invoice_date']) ? substr($data['invoice_date'], 0, 10) : date('Y-m-d'),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'payment_status' => $paymentStatus,
                'notes' => trim($data['notes'] ?? ''),
                'sync_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now
            ]);

            $payload = [
                'customer_uuid' => trim($data['customer_uuid'] ?? ''),
                'customer_id' => !empty($data['customer_id']) ? (int)$data['customer_id'] : null,
                'invoice_number' => $invNum,
                'client_temp_number' => trim($data['client_temp_number'] ?? $invNum),
                'invoice_date' => !empty($data['invoice_date']) ? substr($data['invoice_date'], 0, 10) : date('Y-m-d'),
                'subtotal' => $subtotal,
                'tax_total' => $taxAmount,
                'discount_total' => $discountAmount,
                'grand_total' => $grandTotal,
                'amount_paid' => $paidAmount,
                'notes' => trim($data['notes'] ?? ''),
                'items' => $items
            ];

            $this->sqlite->insert('pending_operations', [
                'operation_uuid' => $operationUuid,
                'business_id' => $businessId,
                'device_id' => $deviceId,
                'entity_type' => 'invoices',
                'entity_uuid' => $invoiceUuid,
                'operation_type' => 'insert',
                'payload_json' => json_encode($payload),
                'status' => 'pending',
                'created_at' => $now
            ]);

            $db->commit();

            return [
                'success' => true,
                'local_id' => $localId,
                'uuid' => $invoiceUuid,
                'operation_uuid' => $operationUuid,
                'sync_status' => 'pending'
            ];
        } catch (Throwable $t) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Offline invoice creation error: ' . $t->getMessage());
            return ['success' => false, 'error' => $t->getMessage()];
        }
    }

    /**
     * 4. Record a payment offline inside SQLite and enqueue pending sync operation
     */
    public function recordPaymentOffline(int $businessId, string $deviceId, array $data): array {
        $paymentUuid = $this->generateUuid();
        $operationUuid = $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $db = $this->sqlite->getPdo();
        $db->beginTransaction();

        try {
            $amount = (float)($data['amount'] ?? 0.00);
            $method = strtolower(trim($data['method'] ?? ($data['payment_method'] ?? 'cash')));

            $localId = $this->sqlite->insert('payments', [
                'uuid' => $paymentUuid,
                'business_id' => $businessId,
                'invoice_id' => !empty($data['invoice_id']) ? (int)$data['invoice_id'] : null,
                'invoice_uuid' => trim($data['invoice_uuid'] ?? ''),
                'customer_id' => !empty($data['customer_id']) ? (int)$data['customer_id'] : null,
                'payment_method' => $method,
                'amount' => $amount,
                'transaction_reference' => trim($data['reference'] ?? ($data['transaction_reference'] ?? '')),
                'payment_date' => !empty($data['payment_date']) ? substr($data['payment_date'], 0, 10) : date('Y-m-d'),
                'sync_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now
            ]);

            $payload = [
                'customer_uuid' => trim($data['customer_uuid'] ?? ''),
                'customer_id' => !empty($data['customer_id']) ? (int)$data['customer_id'] : null,
                'invoice_uuid' => trim($data['invoice_uuid'] ?? ''),
                'amount' => $amount,
                'method' => $method,
                'reference' => trim($data['reference'] ?? ($data['transaction_reference'] ?? '')),
                'payment_date' => !empty($data['payment_date']) ? substr($data['payment_date'], 0, 10) : date('Y-m-d'),
            ];

            $this->sqlite->insert('pending_operations', [
                'operation_uuid' => $operationUuid,
                'business_id' => $businessId,
                'device_id' => $deviceId,
                'entity_type' => 'payments',
                'entity_uuid' => $paymentUuid,
                'operation_type' => 'insert',
                'payload_json' => json_encode($payload),
                'status' => 'pending',
                'created_at' => $now
            ]);

            $db->commit();

            return [
                'success' => true,
                'local_id' => $localId,
                'uuid' => $paymentUuid,
                'operation_uuid' => $operationUuid,
                'sync_status' => 'pending'
            ];
        } catch (Throwable $t) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Offline payment record error: ' . $t->getMessage());
            return ['success' => false, 'error' => $t->getMessage()];
        }
    }

    /**
     * 5. Record an expense offline inside SQLite and enqueue pending sync operation
     */
    public function recordExpenseOffline(int $businessId, string $deviceId, array $data): array {
        $expenseUuid = $this->generateUuid();
        $operationUuid = $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $db = $this->sqlite->getPdo();
        $db->beginTransaction();

        try {
            $amount = (float)($data['amount'] ?? 0.00);
            $category = trim($data['category'] ?? 'General Expense');
            $desc = trim($data['description'] ?? '');
            $method = strtolower(trim($data['method'] ?? ($data['payment_method'] ?? 'cash')));

            $localId = $this->sqlite->insert('expenses', [
                'uuid' => $expenseUuid,
                'business_id' => $businessId,
                'category' => $category,
                'amount' => $amount,
                'description' => $desc,
                'payment_method' => $method,
                'expense_date' => !empty($data['expense_date']) ? substr($data['expense_date'], 0, 10) : date('Y-m-d'),
                'sync_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now
            ]);

            $payload = [
                'category' => $category,
                'amount' => $amount,
                'description' => $desc,
                'method' => $method,
                'expense_date' => !empty($data['expense_date']) ? substr($data['expense_date'], 0, 10) : date('Y-m-d'),
            ];

            $this->sqlite->insert('pending_operations', [
                'operation_uuid' => $operationUuid,
                'business_id' => $businessId,
                'device_id' => $deviceId,
                'entity_type' => 'expenses',
                'entity_uuid' => $expenseUuid,
                'operation_type' => 'insert',
                'payload_json' => json_encode($payload),
                'status' => 'pending',
                'created_at' => $now
            ]);

            $db->commit();

            return [
                'success' => true,
                'local_id' => $localId,
                'uuid' => $expenseUuid,
                'operation_uuid' => $operationUuid,
                'sync_status' => 'pending'
            ];
        } catch (Throwable $t) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Offline expense record error: ' . $t->getMessage());
            return ['success' => false, 'error' => $t->getMessage()];
        }
    }

    /**
     * Push all pending offline operations to Cloud REST API v1
     */
    public function pushPending(string $cloudApiUrl, string $bearerToken, string $deviceId): array {
        $pending = $this->sqlite->fetchAll(
            "SELECT * FROM `pending_operations` WHERE `status` = 'pending' ORDER BY `id` ASC LIMIT 100"
        );

        if (empty($pending)) {
            return ['success' => true, 'pushed' => 0, 'results' => []];
        }

        $opsPayload = [];
        foreach ($pending as $p) {
            $opsPayload[] = [
                'operation_uuid' => $p['operation_uuid'],
                'entity_type' => $p['entity_type'],
                'entity_uuid' => $p['entity_uuid'],
                'operation_type' => $p['operation_type'],
                'payload' => json_decode($p['payload_json'], true) ?? [],
                'client_timestamp' => $p['created_at']
            ];
        }

        $url = rtrim($cloudApiUrl, '/') . '/api/v1/sync';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'device_id' => $deviceId,
            'operations' => $opsPayload
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer {$bearerToken}",
            "X-Device-Id: {$deviceId}"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode((string)$res, true) ?? [];
        if ($status !== 200 || empty($body['success'])) {
            return [
                'success' => false,
                'error' => $body['error']['message'] ?? 'Sync push failed with HTTP ' . $status,
                'pushed' => 0
            ];
        }

        $results = $body['data']['results'] ?? [];
        $syncedCount = 0;

        foreach ($results as $r) {
            $opUuid = $r['operation_uuid'];
            $st = $r['status'] ?? '';

            if ($st === 'synced' || $st === 'already_synced') {
                $opRecord = $this->sqlite->fetch(
                    "SELECT entity_type, entity_uuid FROM `pending_operations` WHERE `operation_uuid` = ?",
                    [$opUuid]
                );

                $this->sqlite->query(
                    "UPDATE `pending_operations` SET `status` = 'synced', `synced_at` = datetime('now') WHERE `operation_uuid` = ?",
                    [$opUuid]
                );

                if ($opRecord) {
                    $table = strtolower($opRecord['entity_type']);
                    $validTables = ['customers', 'products', 'invoices', 'payments', 'expenses'];
                    if (in_array($table, $validTables, true)) {
                        $this->sqlite->query(
                            "UPDATE `{$table}` SET `sync_status` = 'synced', `updated_at` = datetime('now') WHERE `uuid` = ?",
                            [$opRecord['entity_uuid']]
                        );
                    }
                }
                $syncedCount++;
            }
        }

        return [
            'success' => true,
            'pushed' => $syncedCount,
            'results' => $results
        ];
    }

    /**
     * Pull incremental updates across all 5 core entities from Cloud API v1
     */
    public function pullUpdates(string $cloudApiUrl, string $bearerToken, int $businessId): array {
        $metaRow = $this->sqlite->fetch("SELECT value FROM `sync_metadata` WHERE `key` = 'last_sync_version'");
        $sinceVersion = $metaRow ? (int)$metaRow['value'] : 0;

        $url = rtrim($cloudApiUrl, '/') . '/api/v1/sync?since_version=' . $sinceVersion;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            "Authorization: Bearer {$bearerToken}"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode((string)$res, true) ?? [];
        if ($status !== 200 || empty($body['success'])) {
            return [
                'success' => false,
                'error' => $body['error']['message'] ?? 'Sync pull failed with HTTP ' . $status,
                'pulled' => 0
            ];
        }

        $data = $body['data'];
        $newCursor = (int)($data['cursor'] ?? $sinceVersion);
        $entities = $data['entities'] ?? [];

        $applied = 0;
        $db = $this->sqlite->getPdo();
        $db->beginTransaction();

        try {
            // 1. Process Customers
            foreach ($entities['customers'] ?? [] as $c) {
                $cloudId = (int)$c['id'];
                $uuid = $c['sync_uuid'] ?: ('cloud_cust_' . $cloudId);

                $existing = $this->sqlite->fetch(
                    "SELECT id FROM `customers` WHERE `uuid` = ? OR `cloud_id` = ?",
                    [$uuid, $cloudId]
                );

                if ($existing) {
                    $this->sqlite->update('customers', [
                        'cloud_id' => $cloudId,
                        'name' => $c['name'],
                        'phone' => $c['phone'] ?? '',
                        'email' => $c['email'] ?? '',
                        'address' => $c['billing_address_line1'] ?? '',
                        'city' => $c['billing_city'] ?? '',
                        'state' => $c['billing_state'] ?? '',
                        'pincode' => $c['billing_postal_code'] ?? '',
                        'gstin' => $c['gstin'] ?? '',
                        'sync_status' => 'synced',
                        'updated_at' => $c['updated_at'] ?? date('Y-m-d H:i:s')
                    ], 'id = ?', [$existing['id']]);
                } else {
                    $this->sqlite->insert('customers', [
                        'uuid' => $uuid,
                        'cloud_id' => $cloudId,
                        'business_id' => $businessId,
                        'name' => $c['name'],
                        'phone' => $c['phone'] ?? '',
                        'email' => $c['email'] ?? '',
                        'address' => $c['billing_address_line1'] ?? '',
                        'city' => $c['billing_city'] ?? '',
                        'state' => $c['billing_state'] ?? '',
                        'pincode' => $c['billing_postal_code'] ?? '',
                        'gstin' => $c['gstin'] ?? '',
                        'sync_status' => 'synced',
                        'created_at' => $c['created_at'] ?? date('Y-m-d H:i:s'),
                        'updated_at' => $c['updated_at'] ?? date('Y-m-d H:i:s')
                    ]);
                }
                $applied++;
            }

            // 2. Process Products
            foreach ($entities['products'] ?? [] as $p) {
                $cloudId = (int)$p['id'];
                $uuid = $p['sync_uuid'] ?: ('cloud_prod_' . $cloudId);

                $existing = $this->sqlite->fetch(
                    "SELECT id FROM `products` WHERE `uuid` = ? OR `cloud_id` = ?",
                    [$uuid, $cloudId]
                );

                if ($existing) {
                    $this->sqlite->update('products', [
                        'cloud_id' => $cloudId,
                        'name' => $p['name'],
                        'category' => $p['category'] ?? 'General',
                        'sku' => $p['sku'] ?? '',
                        'selling_price' => (float)$p['selling_price'],
                        'purchase_price' => (float)($p['cost_price'] ?? 0.0),
                        'tax_rate' => (float)($p['tax_rate'] ?? 0.0),
                        'unit' => $p['unit'] ?? 'pcs',
                        'sync_status' => 'synced',
                        'updated_at' => $p['updated_at'] ?? date('Y-m-d H:i:s')
                    ], 'id = ?', [$existing['id']]);
                } else {
                    $this->sqlite->insert('products', [
                        'uuid' => $uuid,
                        'cloud_id' => $cloudId,
                        'business_id' => $businessId,
                        'name' => $p['name'],
                        'category' => $p['category'] ?? 'General',
                        'sku' => $p['sku'] ?? '',
                        'barcode' => '',
                        'purchase_price' => (float)($p['cost_price'] ?? 0.0),
                        'selling_price' => (float)$p['selling_price'],
                        'tax_rate' => (float)($p['tax_rate'] ?? 0.0),
                        'unit' => $p['unit'] ?? 'pcs',
                        'sync_status' => 'synced',
                        'created_at' => $p['created_at'] ?? date('Y-m-d H:i:s'),
                        'updated_at' => $p['updated_at'] ?? date('Y-m-d H:i:s')
                    ]);
                }
                $applied++;
            }

            // 3. Process Invoices
            foreach ($entities['invoices'] ?? [] as $inv) {
                $cloudId = (int)$inv['id'];
                $uuid = $inv['sync_uuid'] ?: ('cloud_inv_' . $cloudId);

                $existing = $this->sqlite->fetch(
                    "SELECT id FROM `invoices` WHERE `uuid` = ? OR `cloud_id` = ?",
                    [$uuid, $cloudId]
                );

                if ($existing) {
                    $this->sqlite->update('invoices', [
                        'cloud_id' => $cloudId,
                        'invoice_number' => $inv['invoice_number'],
                        'invoice_date' => $inv['invoice_date'],
                        'subtotal' => (float)$inv['subtotal'],
                        'tax_amount' => (float)$inv['tax_total'],
                        'discount_amount' => (float)$inv['discount_total'],
                        'grand_total' => (float)$inv['grand_total'],
                        'paid_amount' => (float)$inv['amount_paid'],
                        'payment_status' => $inv['status'],
                        'sync_status' => 'synced',
                        'updated_at' => $inv['updated_at'] ?? date('Y-m-d H:i:s')
                    ], 'id = ?', [$existing['id']]);
                } else {
                    $this->sqlite->insert('invoices', [
                        'uuid' => $uuid,
                        'cloud_id' => $cloudId,
                        'business_id' => $businessId,
                        'customer_id' => (int)$inv['customer_id'],
                        'invoice_number' => $inv['invoice_number'],
                        'client_temp_number' => $inv['client_temp_number'] ?? $inv['invoice_number'],
                        'invoice_date' => $inv['invoice_date'],
                        'subtotal' => (float)$inv['subtotal'],
                        'tax_amount' => (float)$inv['tax_total'],
                        'discount_amount' => (float)$inv['discount_total'],
                        'grand_total' => (float)$inv['grand_total'],
                        'paid_amount' => (float)$inv['amount_paid'],
                        'payment_status' => $inv['status'],
                        'notes' => $inv['notes'] ?? '',
                        'sync_status' => 'synced',
                        'created_at' => $inv['created_at'] ?? date('Y-m-d H:i:s'),
                        'updated_at' => $inv['updated_at'] ?? date('Y-m-d H:i:s')
                    ]);
                }
                $applied++;
            }

            // 4. Process Payments
            foreach ($entities['payments'] ?? [] as $pay) {
                $cloudId = (int)$pay['id'];
                $uuid = $pay['sync_uuid'] ?: ('cloud_pay_' . $cloudId);

                $existing = $this->sqlite->fetch(
                    "SELECT id FROM `payments` WHERE `uuid` = ? OR `cloud_id` = ?",
                    [$uuid, $cloudId]
                );

                if ($existing) {
                    $this->sqlite->update('payments', [
                        'cloud_id' => $cloudId,
                        'customer_id' => (int)$pay['customer_id'],
                        'payment_method' => $pay['method'],
                        'amount' => (float)$pay['amount'],
                        'transaction_reference' => $pay['reference'] ?? '',
                        'payment_date' => $pay['payment_date'],
                        'sync_status' => 'synced',
                        'updated_at' => $pay['updated_at'] ?? date('Y-m-d H:i:s')
                    ], 'id = ?', [$existing['id']]);
                } else {
                    $this->sqlite->insert('payments', [
                        'uuid' => $uuid,
                        'cloud_id' => $cloudId,
                        'business_id' => $businessId,
                        'customer_id' => (int)$pay['customer_id'],
                        'payment_method' => $pay['method'],
                        'amount' => (float)$pay['amount'],
                        'transaction_reference' => $pay['reference'] ?? '',
                        'payment_date' => $pay['payment_date'],
                        'sync_status' => 'synced',
                        'created_at' => $pay['created_at'] ?? date('Y-m-d H:i:s'),
                        'updated_at' => $pay['updated_at'] ?? date('Y-m-d H:i:s')
                    ]);
                }
                $applied++;
            }

            // 5. Process Expenses
            foreach ($entities['expenses'] ?? [] as $exp) {
                $cloudId = (int)$exp['id'];
                $uuid = $exp['sync_uuid'] ?: ('cloud_exp_' . $cloudId);

                $existing = $this->sqlite->fetch(
                    "SELECT id FROM `expenses` WHERE `uuid` = ? OR `cloud_id` = ?",
                    [$uuid, $cloudId]
                );

                if ($existing) {
                    $this->sqlite->update('expenses', [
                        'cloud_id' => $cloudId,
                        'amount' => (float)$exp['amount'],
                        'description' => $exp['description'] ?? '',
                        'payment_method' => $exp['method'] ?? 'cash',
                        'expense_date' => $exp['expense_date'],
                        'sync_status' => 'synced',
                        'updated_at' => $exp['updated_at'] ?? date('Y-m-d H:i:s')
                    ], 'id = ?', [$existing['id']]);
                } else {
                    $this->sqlite->insert('expenses', [
                        'uuid' => $uuid,
                        'cloud_id' => $cloudId,
                        'business_id' => $businessId,
                        'category' => 'General',
                        'amount' => (float)$exp['amount'],
                        'description' => $exp['description'] ?? '',
                        'payment_method' => $exp['method'] ?? 'cash',
                        'expense_date' => $exp['expense_date'],
                        'sync_status' => 'synced',
                        'created_at' => $exp['created_at'] ?? date('Y-m-d H:i:s'),
                        'updated_at' => $exp['updated_at'] ?? date('Y-m-d H:i:s')
                    ]);
                }
                $applied++;
            }

            // Update local sync cursor
            $this->sqlite->query(
                "INSERT INTO `sync_metadata` (`key`, `value`, `updated_at`) 
                 VALUES ('last_sync_version', ?, datetime('now'))
                 ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`, `updated_at` = datetime('now')",
                [(string)$newCursor]
            );

            $db->commit();

            return [
                'success' => true,
                'pulled' => $applied,
                'cursor' => $newCursor
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Sync pull application error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function generateUuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
