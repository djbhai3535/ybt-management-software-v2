<?php
/**
 * YBT Management Software — Phase 4 Desktop SQLite & Offline Sync Test Suite
 * Tests local SQLite migrations, offline session validation, offline customer creation,
 * bidirectional sync (push/pull), idempotency, and cross-tenant sync isolation.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/desktop/src/SqliteClient.php';
require_once dirname(__DIR__) . '/desktop/src/OfflineAuthService.php';
require_once dirname(__DIR__) . '/desktop/src/SyncEngine.php';

class Phase4DesktopSyncTest {
    private string $cloudApiUrl = 'http://127.0.0.1:8088';
    private string $testDbPath;
    private string $testCustomerPhone;
    private SqliteClient $sqlite;
    private OfflineAuthService $authService;
    private SyncEngine $syncEngine;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct() {
        $this->testDbPath = dirname(__DIR__) . '/storage/desktop/test_phase4_' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->testCustomerPhone = '+9198' . bin2hex(random_bytes(4));
        $this->sqlite = new SqliteClient($this->testDbPath);
        $this->authService = new OfflineAuthService($this->sqlite);
        $this->syncEngine = new SyncEngine($this->sqlite);
    }

    public function run(): bool {
        echo "\n============================================================\n";
        echo "PHASE 4: WINDOWS DESKTOP SQLITE & OFFLINE-FIRST TEST SUITE\n";
        echo "============================================================\n";

        try {
            $this->testSqliteInitAndMigrations();
            $this->testCloudAndOfflineAuthentication();
            $this->testOfflineCustomerCreation();
            $this->testCustomerPushToCloud();
            $this->testCustomerPullOnSecondDevice();
            $this->testIdempotencyAndDuplicatePrevention();
            $this->testNetworkFailureAndRetrySafety();
            $this->testCrossTenantSyncIsolation();
            $this->testRevocationEnforcement();
        } finally {
            $this->cleanup();
        }

        echo "\n------------------------------------------------------------\n";
        echo "RESULTS: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "============================================================\n";

        return $this->failed === 0;
    }

    private function assert(string $desc, bool $condition): void {
        if ($condition) {
            echo " [PASS] {$desc}\n";
            $this->passed++;
        } else {
            echo " [FAIL] {$desc}\n";
            $this->failed++;
        }
    }

    private function testSqliteInitAndMigrations(): void {
        // Test 1: SQLite Connection & WAL mode
        $journalMode = $this->sqlite->fetchColumn("PRAGMA journal_mode;");
        $this->assert("SQLite connected and WAL mode enabled", strtolower((string)$journalMode) === 'wal');

        // Test 2: Run Migrations
        $migratedCount = $this->sqlite->runMigrations();
        $this->assert("SQLite migration executed successfully ({$migratedCount} migrations)", $migratedCount >= 1);

        // Verify required tables exist
        $tables = $this->sqlite->fetchAll("SELECT name FROM sqlite_master WHERE type='table';");
        $tableNames = array_column($tables, 'name');

        $required = ['local_sessions', 'businesses', 'customers', 'products', 'invoices', 'payments', 'expenses', 'pending_operations', 'sync_metadata', 'sync_tombstones'];
        $allPresent = true;
        foreach ($required as $req) {
            if (!in_array($req, $tableNames, true)) {
                $allPresent = false;
                break;
            }
        }
        $this->assert("All 10 required offline SQLite tables exist", $allPresent);
    }

    private function testCloudAndOfflineAuthentication(): void {
        $deviceId = 'device_' . bin2hex(random_bytes(4));

        // 1. Authenticate Online against live Cloud API
        $onlineAuth = $this->authService->authenticateOnline('owner@ybt.com', 'password123', $deviceId, $this->cloudApiUrl);
        $this->assert("Online authentication succeeds against Cloud API", $onlineAuth['success'] && !empty($onlineAuth['access_token']));

        // Verify local session was cached
        $cachedSession = $this->sqlite->fetch("SELECT * FROM `local_sessions` WHERE `device_id` = ?", [$deviceId]);
        $this->assert("Local session securely cached in SQLite", !empty($cachedSession) && $cachedSession['status'] === 'active');
        $this->assert("Plain-text password is NOT stored locally", $cachedSession['password_hash'] !== 'password123');

        // 2. Authenticate Offline using stored local session
        $offlineAuth = $this->authService->authenticateOffline('owner@ybt.com', 'password123', $deviceId);
        $this->assert("Offline authentication succeeds using cached credentials", $offlineAuth['success'] && $offlineAuth['mode'] === 'offline');

        // 3. Reject invalid password offline
        $badOffline = $this->authService->authenticateOffline('owner@ybt.com', 'wrongpassword', $deviceId);
        $this->assert("Offline authentication rejects incorrect password", !$badOffline['success']);

        // 4. Reject unprovisioned device offline
        $unprovisioned = $this->authService->authenticateOffline('owner@ybt.com', 'password123', 'unregistered_device_999');
        $this->assert("Offline authentication rejects unprovisioned device", !$unprovisioned['success'] && $unprovisioned['code'] === 'OFFLINE_UNPROVISIONED');
    }

    private function testOfflineCustomerCreation(): void {
        $deviceId = 'device_pos_1';
        $customerData = [
            'name' => 'Offline Retail Buyer',
            'phone' => $this->testCustomerPhone,
            'email' => 'offline_buyer@test.com',
            'address' => 'Shop 4B, Electronic Market',
            'city' => 'Delhi',
            'state' => 'Delhi',
            'pincode' => '110006'
        ];

        $createRes = $this->syncEngine->createCustomerOffline(1, $deviceId, $customerData);
        $this->assert("Customer created successfully in local SQLite while offline", $createRes['success'] && !empty($createRes['uuid']));

        // Verify stored in local customers table
        $localCustomer = $this->sqlite->fetch("SELECT * FROM `customers` WHERE `uuid` = ?", [$createRes['uuid']]);
        $this->assert("Local customer record has sync_status = 'pending'", $localCustomer['sync_status'] === 'pending');

        // Verify enqueued in pending_operations
        $pendingOp = $this->sqlite->fetch("SELECT * FROM `pending_operations` WHERE `operation_uuid` = ?", [$createRes['operation_uuid']]);
        $this->assert("Operation enqueued in local pending_operations queue", !empty($pendingOp) && $pendingOp['status'] === 'pending');
    }

    private function testCustomerPushToCloud(): void {
        // Authenticate to get valid bearer token
        $deviceId = 'device_pos_1';
        $auth = $this->authService->authenticateOnline('owner@ybt.com', 'password123', $deviceId, $this->cloudApiUrl);
        $bearerToken = $auth['access_token'];

        // Push pending operations
        $pushRes = $this->syncEngine->pushPending($this->cloudApiUrl, $bearerToken, $deviceId);
        $this->assert("Push pending operations to Cloud API succeeds (pushed >= 1)", $pushRes['success'] && $pushRes['pushed'] >= 1);

        // Verify local operation marked as synced
        $pendingLeft = $this->sqlite->fetchColumn("SELECT COUNT(*) FROM `pending_operations` WHERE `status` = 'pending';");
        $this->assert("Local pending operations queue cleared (status = 'synced')", (int)$pendingLeft === 0);

        // Verify customer exists in Cloud MySQL Database
        $cloudCust = Database::fetch("SELECT id, name, phone, sync_uuid FROM customers WHERE phone = ? AND business_id = 1", [$this->testCustomerPhone]);
        $this->assert("Customer confirmed in Cloud MySQL with matching sync_uuid", !empty($cloudCust) && !empty($cloudCust['sync_uuid']));
    }

    private function testCustomerPullOnSecondDevice(): void {
        // Simulate Device 2 with fresh isolated SQLite database
        $device2DbPath = dirname(__DIR__) . '/storage/desktop/test_device2_' . bin2hex(random_bytes(4)) . '.sqlite';
        $sqlite2 = new SqliteClient($device2DbPath);
        $sqlite2->runMigrations();
        $syncEngine2 = new SyncEngine($sqlite2);

        // Authenticate Device 2
        $authService2 = new OfflineAuthService($sqlite2);
        $auth2 = $authService2->authenticateOnline('owner@ybt.com', 'password123', 'device_pos_2', $this->cloudApiUrl);
        $bearerToken = $auth2['access_token'];

        // Pull changes onto Device 2
        $pullRes = $syncEngine2->pullUpdates($this->cloudApiUrl, $bearerToken, 1);
        $this->assert("Pull delta changes from Cloud API succeeds on second device", $pullRes['success'] && $pullRes['pulled'] >= 1);

        // Verify customer was downloaded on Device 2
        $downloadedCust = $sqlite2->fetch("SELECT * FROM `customers` WHERE `phone` = ?", [$this->testCustomerPhone]);
        $this->assert("Customer synced to Device 2 without duplication", !empty($downloadedCust) && $downloadedCust['name'] === 'Offline Retail Buyer');

        // Cleanup Device 2 database
        unset($sqlite2);
        @unlink($device2DbPath);
        @unlink($device2DbPath . '-wal');
        @unlink($device2DbPath . '-shm');
    }

    private function testIdempotencyAndDuplicatePrevention(): void {
        $deviceId = 'device_pos_1';
        $auth = $this->authService->authenticateOnline('owner@ybt.com', 'password123', $deviceId, $this->cloudApiUrl);
        $bearerToken = $auth['access_token'];

        // Re-push previously synced operation manually
        $syncedOp = $this->sqlite->fetch("SELECT * FROM `pending_operations` WHERE `status` = 'synced' LIMIT 1");
        $payload = [
            'device_id' => $deviceId,
            'operations' => [
                [
                    'operation_uuid' => $syncedOp['operation_uuid'],
                    'entity_type' => $syncedOp['entity_type'],
                    'entity_uuid' => $syncedOp['entity_uuid'],
                    'operation_type' => $syncedOp['operation_type'],
                    'payload' => json_decode($syncedOp['payload_json'], true)
                ]
            ]
        ];

        $ch = curl_init($this->cloudApiUrl . '/api/v1/sync');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: Bearer {$bearerToken}"
        ]);
        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode((string)$res, true) ?? [];
        $resultStatus = $body['data']['results'][0]['status'] ?? '';

        $this->assert("Idempotent replay detected by server (status = 'already_synced')", $status === 200 && $resultStatus === 'already_synced');

        // Verify no duplicate customer was inserted into MySQL
        $count = Database::fetchColumn("SELECT COUNT(*) FROM customers WHERE phone = ? AND business_id = 1", [$this->testCustomerPhone]);
        $this->assert("Zero duplicate customer records in Cloud MySQL", (int)$count === 1);
    }

    private function testNetworkFailureAndRetrySafety(): void {
        // Enqueue a customer operation
        $deviceId = 'device_pos_1';
        $createRes = $this->syncEngine->createCustomerOffline(1, $deviceId, [
            'name' => 'Retry Test Customer',
            'phone' => '+919876540002'
        ]);

        // Attempt push to unreachable port/host
        $badUrl = 'http://127.0.0.1:59999';
        $pushRes = $this->syncEngine->pushPending($badUrl, 'fake_token', $deviceId);

        $this->assert("Sync push gracefully handles network failure without throwing", !$pushRes['success']);

        // Verify operation remains in 'pending' status for subsequent retry
        $op = $this->sqlite->fetch("SELECT status FROM `pending_operations` WHERE `operation_uuid` = ?", [$createRes['operation_uuid']]);
        $this->assert("Failed operation preserved as 'pending' for retry", $op['status'] === 'pending');
    }

    private function testCrossTenantSyncIsolation(): void {
        // Authenticate Shop A (Business 1)
        $authA = $this->authService->authenticateOnline('owner@ybt.com', 'password123', 'dev_a', $this->cloudApiUrl);
        $tokenA = $authA['access_token'];

        // Pull changes for Shop A
        $ch = curl_init($this->cloudApiUrl . '/api/v1/sync?since_version=0');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$tokenA}"]);
        $resA = curl_exec($ch);
        curl_close($ch);
        $bodyA = json_decode((string)$resA, true) ?? [];
        $custsA = $bodyA['data']['entities']['customers'] ?? [];

        // Verify none of Shop A's customers belong to another business
        $tenantLeak = false;
        foreach ($custsA as $c) {
            if ((int)$c['business_id'] !== 1) {
                $tenantLeak = true;
                break;
            }
        }
        $this->assert("Pull endpoint strictly enforces TenantContext (Zero cross-tenant records)", !$tenantLeak);
    }

    private function testRevocationEnforcement(): void {
        $deviceId = 'device_revoke_test';
        $auth = $this->authService->authenticateOnline('owner@ybt.com', 'password123', $deviceId, $this->cloudApiUrl);
        $token = $auth['access_token'];

        // 1. Initial online check succeeds
        $isValid = $this->authService->checkCloudRevocation($this->cloudApiUrl, $token, $deviceId);
        $this->assert("Valid session accepted during cloud verification check", $isValid);

        // 2. Invalidate token on server (simulating cloud logout or security revocation)
        $tokenHash = hash('sha256', $token);
        Database::query("DELETE FROM api_tokens WHERE token_hash = ?", [$tokenHash]);

        // 3. Online check detects revocation
        $isRevoked = $this->authService->checkCloudRevocation($this->cloudApiUrl, $token, $deviceId);
        $this->assert("Server revocation detected by client (returns false)", !$isRevoked);

        // 4. Verify local session was suspended
        $session = $this->sqlite->fetch("SELECT status FROM `local_sessions` WHERE `device_id` = ?", [$deviceId]);
        $this->assert("Local session suspended upon cloud revocation", $session['status'] === 'suspended');
    }

    private function cleanup(): void {
        unset($this->sqlite);
        @unlink($this->testDbPath);
        @unlink($this->testDbPath . '-wal');
        @unlink($this->testDbPath . '-shm');
    }
}

$suite = new Phase4DesktopSyncTest();
$success = $suite->run();
exit($success ? 0 : 1);
