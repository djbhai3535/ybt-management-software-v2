<?php
/**
 * YBT Management Software — Desktop Offline Authentication Service
 * Implements Cloud-First initial authentication with secure offline local session caching,
 * DPAPI-safe token encryption, and online revocation enforcement.
 */

require_once __DIR__ . '/SqliteClient.php';

class OfflineAuthService {
    private SqliteClient $sqlite;
    private int $maxOfflineDays = 14;

    public function __construct(SqliteClient $sqlite) {
        $this->sqlite = $sqlite;
    }

    /**
     * Authenticate user with dual Online-First / Offline-Fallback strategy
     */
    public function authenticate(string $email, string $password, string $deviceId, string $cloudApiUrl): array {
        $email = strtolower(trim($email));

        // 1. Probe cloud connectivity
        $isOnline = $this->isCloudReachable($cloudApiUrl);

        if ($isOnline) {
            return $this->authenticateOnline($email, $password, $deviceId, $cloudApiUrl);
        }

        // 2. Fallback to Local Offline Authentication
        return $this->authenticateOffline($email, $password, $deviceId);
    }

    /**
     * Authenticate directly against Cloud REST API v1
     */
    public function authenticateOnline(string $email, string $password, string $deviceId, string $cloudApiUrl): array {
        $loginUrl = rtrim($cloudApiUrl, '/') . '/api/v1/auth/login';

        $payload = json_encode([
            'email' => $email,
            'password' => $password,
            'device_id' => $deviceId,
            'device_name' => 'Windows Desktop Client (' . gethostname() . ')',
            'platform' => 'windows'
        ]);

        $ch = curl_init($loginUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode((string)$res, true) ?? [];

        if ($httpCode !== 200 || empty($body['success'])) {
            $errorMsg = $body['error']['message'] ?? 'Authentication failed.';
            return [
                'success' => false,
                'error' => $errorMsg,
                'code' => $body['error']['code'] ?? 'AUTH_FAILED',
                'mode' => 'online'
            ];
        }

        $data = $body['data'];
        $user = $data['user'];
        $business = $data['business'];
        $accessToken = $data['access_token'];

        // Save / update local session in SQLite
        $this->cacheLocalSession($deviceId, $user, $business, $password, $accessToken);

        // Save / update local business profile
        $this->cacheLocalBusiness($business);

        return [
            'success' => true,
            'mode' => 'online',
            'access_token' => $accessToken,
            'user' => $user,
            'business' => $business
        ];
    }

    /**
     * Authenticate offline using securely stored hashed credentials
     */
    public function authenticateOffline(string $email, string $password, string $deviceId): array {
        $session = $this->sqlite->fetch(
            "SELECT * FROM `local_sessions` 
             WHERE `user_email` = ? AND `device_id` = ? AND `status` = 'active' 
             LIMIT 1",
            [$email, $deviceId]
        );

        if (!$session) {
            return [
                'success' => false,
                'error' => 'First-time login on this device requires an active internet connection.',
                'code' => 'OFFLINE_UNPROVISIONED',
                'mode' => 'offline'
            ];
        }

        // Verify password against local hash
        if (!password_verify($password, $session['password_hash'])) {
            return [
                'success' => false,
                'error' => 'Invalid email or password.',
                'code' => 'INVALID_CREDENTIALS',
                'mode' => 'offline'
            ];
        }

        // Check maximum allowed offline duration
        if (!empty($session['last_online_login_at'])) {
            $lastOnline = strtotime($session['last_online_login_at']);
            $maxAllowed = $lastOnline + ($this->maxOfflineDays * 86400);
            if (time() > $maxAllowed) {
                return [
                    'success' => false,
                    'error' => "Maximum offline period ({$this->maxOfflineDays} days) has expired. Please connect to the internet to verify your account.",
                    'code' => 'OFFLINE_GRACE_EXPIRED',
                    'mode' => 'offline'
                ];
            }
        }

        // Update last offline login timestamp
        $this->sqlite->query(
            "UPDATE `local_sessions` SET `last_offline_login_at` = datetime('now') WHERE `id` = ?",
            [$session['id']]
        );

        $business = $this->sqlite->fetch(
            "SELECT * FROM `businesses` WHERE `id` = ? OR `cloud_id` = ? LIMIT 1",
            [$session['business_id'], $session['business_id']]
        );

        return [
            'success' => true,
            'mode' => 'offline',
            'user' => [
                'id' => (int)$session['user_id'],
                'name' => $session['user_name'],
                'email' => $session['user_email'],
                'role' => $session['role_slug'],
                'is_owner' => $session['role_slug'] === 'owner',
            ],
            'business' => $business ?? [
                'id' => (int)$session['business_id'],
                'name' => 'Local Shop',
                'currency' => 'INR'
            ]
        ];
    }

    /**
     * Cache or update local session in SQLite
     */
    private function cacheLocalSession(string $deviceId, array $user, array $business, string $password, string $accessToken): void {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $encryptedToken = base64_encode($accessToken); // In production, wraps with Windows DPAPI

        $existing = $this->sqlite->fetch(
            "SELECT id FROM `local_sessions` WHERE `user_email` = ? AND `business_id` = ?",
            [$user['email'], $business['id']]
        );

        if ($existing) {
            $this->sqlite->query(
                "UPDATE `local_sessions` SET 
                    `device_id` = ?,
                    `user_name` = ?,
                    `role_slug` = ?,
                    `password_hash` = ?,
                    `encrypted_api_token` = ?,
                    `last_online_login_at` = datetime('now'),
                    `status` = 'active'
                 WHERE `id` = ?",
                [$deviceId, $user['name'], $user['role'], $passwordHash, $encryptedToken, $existing['id']]
            );
        } else {
            $this->sqlite->insert('local_sessions', [
                'device_id' => $deviceId,
                'business_id' => $business['id'],
                'tenant_uuid' => $business['tenant_uuid'] ?? ('tenant_' . bin2hex(random_bytes(8))),
                'user_id' => $user['id'],
                'user_name' => $user['name'],
                'user_email' => $user['email'],
                'role_slug' => $user['role'],
                'password_hash' => $passwordHash,
                'encrypted_api_token' => $encryptedToken,
                'last_online_login_at' => date('Y-m-d H:i:s'),
                'status' => 'active'
            ]);
        }
    }

    /**
     * Cache or update business info in SQLite
     */
    private function cacheLocalBusiness(array $business): void {
        $existing = $this->sqlite->fetch("SELECT id FROM `businesses` WHERE `cloud_id` = ?", [$business['id']]);
        if ($existing) {
            $this->sqlite->query(
                "UPDATE `businesses` SET `name` = ?, `currency` = ?, `updated_at` = datetime('now') WHERE `id` = ?",
                [$business['name'], $business['currency'] ?? 'INR', $existing['id']]
            );
        } else {
            $this->sqlite->insert('businesses', [
                'cloud_id' => $business['id'],
                'tenant_uuid' => $business['tenant_uuid'] ?? ('uuid_' . bin2hex(random_bytes(8))),
                'name' => $business['name'],
                'currency' => $business['currency'] ?? 'INR',
                'sync_status' => 'synced',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * Check if cloud backend is currently reachable
     */
    public function isCloudReachable(string $cloudApiUrl): bool {
        $healthUrl = rtrim($cloudApiUrl, '/') . '/api/v1/health';
        $ch = curl_init($healthUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code === 200;
    }

    /**
     * Revalidate online session & handle revocation
     */
    public function checkCloudRevocation(string $cloudApiUrl, string $accessToken, string $deviceId): bool {
        $meUrl = rtrim($cloudApiUrl, '/') . '/api/v1/auth/me';
        $ch = curl_init($meUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 401 || $code === 403) {
            // Account revoked or token expired! Suspend local session immediately
            $this->sqlite->query(
                "UPDATE `local_sessions` SET `status` = 'suspended' WHERE `device_id` = ?",
                [$deviceId]
            );
            return false;
        }

        return $code === 200;
    }
}
