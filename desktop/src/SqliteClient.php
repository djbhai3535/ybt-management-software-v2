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
                $this->pdo->exec($sql);

                $stmt = $this->pdo->prepare("INSERT INTO `_sqlite_migrations` (migration, applied_at) VALUES (?, datetime('now'))");
                $stmt->execute([$name]);
                $executed++;
            }
        }

        return $executed;
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
