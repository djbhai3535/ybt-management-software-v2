<?php
/**
 * YBT Management Software — REST API v1 Offline Synchronization Engine
 * Handles Batched Push, Incremental Pull (Cursor-based), and Tombstone Propagation.
 */

$method = $_SERVER['REQUEST_METHOD'];
$tenantId = TenantContext::id();
$userId = (int)($authenticatedUser['user_id'] ?? $authenticatedUser['id'] ?? 1);

// 1. POST /sync/push (Process batched offline operations)
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $deviceId = trim($input['device_id'] ?? '');
    $operations = $input['operations'] ?? [];

    if (empty($operations)) {
        apiResponse(['processed_count' => 0, 'results' => []]);
    }

    $results = [];
    $db = Database::getInstance();

    foreach ($operations as $op) {
        $opUuid = trim($op['operation_uuid'] ?? '');
        $entityType = trim($op['entity_type'] ?? '');
        $entityUuid = trim($op['entity_uuid'] ?? '');
        $opType = trim($op['operation_type'] ?? 'insert');
        $payload = $op['payload'] ?? [];

        if ($opUuid === '' || $entityType === '') {
            continue;
        }

        // Check if operation was already recorded in queue
        $existingOp = Database::fetch(
            "SELECT id, status FROM sync_operations_queue WHERE business_id = ? AND operation_uuid = ?",
            [$tenantId, $opUuid]
        );

        if ($existingOp && $existingOp['status'] === 'synced') {
            $results[] = [
                'operation_uuid' => $opUuid,
                'status' => 'already_synced',
            ];
            continue;
        }

        $db->beginTransaction();
        try {
            $serverStatus = 'synced';

            // Process based on entity type
            if ($entityType === 'customers' && $opType === 'insert') {
                $customerId = CustomerService::create($tenantId, $payload, $userId);
                try {
                    Database::query(
                        "UPDATE customers SET sync_uuid = ?, sync_version = sync_version + 1 WHERE id = ?",
                        [$entityUuid, $customerId]
                    );
                } catch (Throwable $t) {}
            } elseif ($entityType === 'customers' && $opType === 'delete') {
                // Record tombstone
                try {
                    Database::insert('sync_tombstones', [
                        'business_id' => $tenantId,
                        'entity_type' => 'customers',
                        'entity_uuid' => $entityUuid,
                        'deleted_by' => $userId,
                    ]);
                    Database::query("UPDATE customers SET is_deleted = 1 WHERE business_id = ? AND sync_uuid = ?", [$tenantId, $entityUuid]);
                } catch (Throwable $t) {}
            }

            // Record into sync_operations_queue
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
                'status' => 'synced',
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $results[] = [
                'operation_uuid' => $opUuid,
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

// 2. GET /sync/pull (Fetch delta changes since cursor)
if ($method === 'GET') {
    $sinceVersion = max(0, (int)($_GET['since_version'] ?? 0));
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

    // Fetch updated customers
    $customers = [];
    try {
        $customers = Database::fetchAll(
            "SELECT * FROM customers 
             WHERE business_id = ? AND sync_version > ? 
             ORDER BY sync_version ASC LIMIT ?",
            [$tenantId, $sinceVersion, $limit]
        );
    } catch (Throwable $t) {}

    // Fetch updated invoices
    $invoices = [];
    try {
        $invoices = Database::fetchAll(
            "SELECT * FROM invoices 
             WHERE business_id = ? AND sync_version > ? 
             ORDER BY sync_version ASC LIMIT ?",
            [$tenantId, $sinceVersion, $limit]
        );
    } catch (Throwable $t) {}

    // Fetch tombstones
    $tombstones = [];
    try {
        $tombstones = Database::fetchAll(
            "SELECT * FROM sync_tombstones WHERE business_id = ? ORDER BY id ASC LIMIT ?",
            [$tenantId, $limit]
        );
    } catch (Throwable $t) {}

    // Calculate maximum cursor across returned entities
    $maxVersion = $sinceVersion;
    foreach ($customers as $c) {
        if (!empty($c['sync_version']) && $c['sync_version'] > $maxVersion) {
            $maxVersion = (int)$c['sync_version'];
        }
    }
    foreach ($invoices as $i) {
        if (!empty($i['sync_version']) && $i['sync_version'] > $maxVersion) {
            $maxVersion = (int)$i['sync_version'];
        }
    }

    apiResponse([
        'cursor' => $maxVersion,
        'has_more' => (count($customers) >= $limit || count($invoices) >= $limit),
        'entities' => [
            'customers' => $customers,
            'invoices' => $invoices,
            'tombstones' => $tombstones,
        ]
    ]);
}

apiError('Unsupported sync method.', 'METHOD_NOT_ALLOWED', 405);
