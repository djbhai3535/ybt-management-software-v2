<?php
/**
 * YBT Management Software — Desktop SQLite Client & Local Migration Engine
 * Connects to local embedded SQLite database, enables WAL mode, and manages schema migrations.
 */

class SqliteClient {
    private ?PDO $pdo = null;
    private string $dbPath;

    public function __construct(?string $customPath = null) {
        if ($customPath !== null) {
            $this->dbPath = $customPath;
        } elseif ($envPath = getenv('YBT_SQLITE_PATH')) {
            $this->dbPath = str_replace('\\', '/', $envPath);
        } elseif ($appData = getenv('APPDATA')) {
            $storageDir = str_replace('\\', '/', $appData) . '/YBT Management Software/storage/desktop';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0755, true);
            }
            $this->dbPath = $storageDir . '/ybt_local.sqlite';
        } else {
            $storageDir = dirname(__DIR__, 2) . '/storage/desktop';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0755, true);
            }
            $this->dbPath = $storageDir . '/ybt_local.sqlite';
        }

        $this->connect();
    }

    private function connect(): void {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $this->dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Performance & Reliability pragmas
        $this->pdo->exec("PRAGMA journal_mode = WAL;");
        $this->pdo->exec("PRAGMA foreign_keys = ON;");
        $this->pdo->exec("PRAGMA synchronous = NORMAL;");
        $this->pdo->exec("PRAGMA temp_store = MEMORY;");

        // MySQL Cross-Compatibility Functions for SQLite
        $this->pdo->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'));
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
        $this->pdo->sqliteCreateFunction('IF', fn($cond, $a, $b) => $cond ? $a : $b);
        $this->pdo->sqliteCreateFunction('TIMESTAMPDIFF', function($unit, $from, $to) {
            $t1 = is_numeric($from) ? (int)$from : strtotime((string)$from);
            $t2 = is_numeric($to) ? (int)$to : strtotime((string)$to);
            $diffSec = $t2 - $t1;
            $unit = strtoupper(trim((string)$unit));
            return match ($unit) {
                'SECOND' => $diffSec,
                'MINUTE' => (int)($diffSec / 60),
                'HOUR'   => (int)($diffSec / 3600),
                'DAY'    => (int)($diffSec / 86400),
                default  => $diffSec,
            };
        });
        $this->pdo->sqliteCreateFunction('DATEDIFF', function($to, $from) {
            $t1 = is_numeric($from) ? (int)$from : strtotime((string)$from);
            $t2 = is_numeric($to) ? (int)$to : strtotime((string)$to);
            return (int)(($t2 - $t1) / 86400);
        });
        $this->pdo->sqliteCreateFunction('CONCAT', fn(...$args) => implode('', $args));
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function getDbPath(): string {
        return $this->dbPath;
    }

    /**
     * Run all SQLite migrations from migrations directory
     */
    public function runMigrations(): int {
        $migrationsDir = dirname(__DIR__) . '/database/migrations';
        if (!is_dir($migrationsDir)) {
            return 0;
        }

        // Migration tracker table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `_sqlite_migrations` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `migration` TEXT NOT NULL UNIQUE,
                `applied_at` TEXT NOT NULL
            );
        ");

        $applied = $this->pdo->query("SELECT migration FROM `_sqlite_migrations`")->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($migrationsDir . '/*.sql');
        sort($files);

        $executed = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (str_contains($name, 'rollback')) {
                continue;
            }
            if (!in_array($name, $applied, true)) {
                $sql = file_get_contents($file);
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $stmtSql) {
                    if (empty($stmtSql)) continue;
                    try {
                        $this->pdo->exec($stmtSql);
                    } catch (\PDOException $pe) {
                        $msg = strtolower($pe->getMessage());
                        if (!str_contains($msg, 'duplicate column') && !str_contains($msg, 'already exists')) {
                            throw $pe;
                        }
                    }
                }

                $stmt = $this->pdo->prepare("INSERT INTO `_sqlite_migrations` (migration, applied_at) VALUES (?, datetime('now'))");
                $stmt->execute([$name]);
                $executed++;
            }
        }

        // Ensure default demo accounts are seeded safely if not present
        $this->seedDemoAccounts();

        return $executed;
    }

    /**
     * Safely seed demo accounts (Owner & Manager) without modifying existing user data
     */
    public function seedDemoAccounts(): void {
        try {
            // Check if local_sessions table exists
            $tableExists = $this->fetchColumn("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='local_sessions'");
            if (!$tableExists) {
                return;
            }

            // 1. Seed demo business if none exists
            $businessCount = (int)$this->fetchColumn("SELECT COUNT(*) FROM `businesses`");
            if ($businessCount === 0) {
                $this->insert('businesses', [
                    'id' => 1,
                    'cloud_id' => 1,
                    'tenant_uuid' => 'tenant-demo-ybt-001',
                    'name' => 'YBT Technologies (Demo)',
                    'legal_name' => 'YBT Technologies Pvt Ltd',
                    'type' => 'Retail / Technology',
                    'phone' => '+91 98201 12345',
                    'sync_status' => 'synced'
                ]);
            }

            // 2. Seed owner demo account if not exists
            $owner = $this->fetch("SELECT id FROM `local_sessions` WHERE `user_email` = 'owner@ybt.com'");
            if (!$owner) {
                $this->insert('local_sessions', [
                    'device_id' => 'desktop_default',
                    'business_id' => 1,
                    'tenant_uuid' => 'tenant-demo-ybt-001',
                    'user_id' => 1,
                    'user_name' => 'Vikram Malhotra (Owner)',
                    'user_email' => 'owner@ybt.com',
                    'role_slug' => 'owner',
                    'password_hash' => '$2y$10$v9bn2FQbgixG4ardHYPxZ.i6Q5YCn6XI1wGKaKYKbwa/zZycfFPs6', // password123
                    'encrypted_api_token' => 'offline_token_owner_demo',
                    'offline_access_allowed' => 1,
                    'last_online_login_at' => date('Y-m-d H:i:s'),
                    'expires_at' => date('Y-m-d H:i:s', time() + (365 * 86400)),
                    'status' => 'active'
                ]);
            }

            // 3. Seed manager demo account if not exists
            $manager = $this->fetch("SELECT id FROM `local_sessions` WHERE `user_email` = 'manager@ybt.com'");
            if (!$manager) {
                $this->insert('local_sessions', [
                    'device_id' => 'desktop_default',
                    'business_id' => 1,
                    'tenant_uuid' => 'tenant-demo-ybt-001',
                    'user_id' => 2,
                    'user_name' => 'Rohan Verma (Manager)',
                    'user_email' => 'manager@ybt.com',
                    'role_slug' => 'manager',
                    'password_hash' => '$2y$10$v9bn2FQbgixG4ardHYPxZ.i6Q5YCn6XI1wGKaKYKbwa/zZycfFPs6', // password123
                    'encrypted_api_token' => 'offline_token_manager_demo',
                    'offline_access_allowed' => 1,
                    'last_online_login_at' => date('Y-m-d H:i:s'),
                    'expires_at' => date('Y-m-d H:i:s', time() + (365 * 86400)),
                    'status' => 'active'
                ]);
            }

            // 4. Ensure demo users exist in `users` table for staff/manager queries
            $usersTableExists = $this->fetchColumn("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'");
            if ($usersTableExists) {
                $ownerUser = $this->fetch("SELECT id FROM `users` WHERE `email` = 'owner@ybt.com'");
                if (!$ownerUser) {
                    $this->insert('users', [
                        'id' => 1,
                        'business_id' => 1,
                        'role_id' => 1,
                        'name' => 'Vikram Malhotra',
                        'email' => 'owner@ybt.com',
                        'phone' => '+91 98201 12345',
                        'password' => '$2y$10$v9bn2FQbgixG4ardHYPxZ.i6Q5YCn6XI1wGKaKYKbwa/zZycfFPs6',
                        'is_owner' => 1,
                        'is_active' => 1,
                        'verification_status' => 'verified',
                        'onboarding_completed' => 1
                    ]);
                }

                $mgrUser = $this->fetch("SELECT id FROM `users` WHERE `email` = 'manager@ybt.com'");
                if (!$mgrUser) {
                    $this->insert('users', [
                        'id' => 2,
                        'business_id' => 1,
                        'role_id' => 2,
                        'name' => 'Rohan Verma',
                        'email' => 'manager@ybt.com',
                        'phone' => '+91 98202 54321',
                        'password' => '$2y$10$v9bn2FQbgixG4ardHYPxZ.i6Q5YCn6XI1wGKaKYKbwa/zZycfFPs6',
                        'is_owner' => 0,
                        'is_active' => 1,
                        'verification_status' => 'verified',
                        'onboarding_completed' => 1
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('seedDemoAccounts notice: ' . $e->getMessage());
        }
    }

    public function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array {
        $res = $this->query($sql, $params)->fetch();
        return $res ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = []) {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function insert(string $table, array $data): int {
        $cols = array_keys($data);
        $placeholders = array_fill(0, count($cols), '?');

        $sql = sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s)",
            $table,
            implode(', ', array_map(fn($c) => "`{$c}`", $cols)),
            implode(', ', $placeholders)
        );

        $this->query($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = "`{$col}` = ?";
            $params[] = $val;
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE {$where}";
        $stmt = $this->query($sql, array_merge($params, $whereParams));
        return $stmt->rowCount();
    }
}
