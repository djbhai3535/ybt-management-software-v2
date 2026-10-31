<?php
/**
 * YBT Management Software — Desktop Offline-First Sync Engine
 * Manages local pending operations, push batching, delta pull processing,
 * and conflict-free idempotency between SQLite and Cloud REST API v1.
 */

require_once __DIR__ . '/SqliteClient.php';

class SyncEngine {
    private SqliteClient $sqlite;

    public function __construct(SqliteClient $sqlite) {
        $this->sqlite = $sqlite;
    }

    /**
     * Create a customer record offline inside SQLite and enqueue pending sync operation
     */
    public function createCustomerOffline(int $businessId, string $deviceId, array $data): array {
        $customerUuid = $this->generateUuid();
        $operationUuid = $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $db = $this->sqlite->getPdo();
        $db->beginTransaction();

        try {
            // 1. Insert into local SQLite customers table
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

            // 2. Enqueue in local pending_operations queue
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
     * Push pending operations to Cloud API v1
     */
    public function pushPending(string $cloudApiUrl, string $bearerToken, string $deviceId): array {
        $pending = $this->sqlite->fetchAll(
            "SELECT * FROM `pending_operations` WHERE `status` = 'pending' ORDER BY `id` ASC LIMIT 50"
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

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

                if ($opRecord && $opRecord['entity_type'] === 'customers') {
                    $this->sqlite->query(
                        "UPDATE `customers` SET `sync_status` = 'synced', `updated_at` = datetime('now') WHERE `uuid` = ?",
                        [$opRecord['entity_uuid']]
                    );
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
     * Pull delta changes from Cloud API v1 and apply to local SQLite
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

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
        $customers = $data['entities']['customers'] ?? [];

        $applied = 0;
        $db = $this->sqlite->getPdo();
        $db->beginTransaction();

        try {
            foreach ($customers as $c) {
                $cloudId = (int)$c['id'];
                $uuid = $c['sync_uuid'] ?: ('cloud_cust_' . $cloudId);

                // Check if customer already exists locally
                $existing = $this->sqlite->fetch(
                    "SELECT id FROM `customers` WHERE `uuid` = ? OR `cloud_id` = ?",
                    [$uuid, $cloudId]
                );

                if ($existing) {
                    // Update existing local record
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
                    // Insert new customer locally
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
