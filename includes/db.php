<?php
/**
 * YBT Management Software — Database Connection (PDO Singleton)
 * Seamlessly connects to Cloud MySQL or falls back to embedded SQLite in desktop offline mode.
 */

class Database {
    private static ?PDO $instance = null;
    private static string $driver = 'mysql';

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $config = require YBT_ROOT . '/config/config.php';

            // Check if SQLite desktop database path is available
            $sqlitePath = getenv('YBT_SQLITE_PATH') 
                ?: (getenv('APPDATA') ? str_replace('\\', '/', getenv('APPDATA')) . '/YBT Management Software/storage/desktop/ybt_local.sqlite' : null);
            if (!$sqlitePath || !file_exists($sqlitePath)) {
                $candidate = YBT_ROOT . '/storage/desktop/ybt_local.sqlite';
                if (file_exists($candidate)) {
                    $sqlitePath = $candidate;
                }
            }

            // Test if MySQL is reachable (quick timeout)
            $tryMysql = true;
            if (getenv('APP_ENV') === 'desktop' && !empty($sqlitePath) && file_exists($sqlitePath)) {
                $fp = @fsockopen($config['db_host'], (int)$config['db_port'], $errno, $errstr, 0.15);
                if (!is_resource($fp)) {
                    $tryMysql = false;
                } else {
                    fclose($fp);
                }
            }

            if ($tryMysql) {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $config['db_host'],
                    $config['db_port'],
                    $config['db_name'],
                    $config['db_charset']
                );
                try {
                    self::$instance = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                    ]);
                    self::$driver = 'mysql';
                    return self::$instance;
                } catch (\PDOException $e) {
                    if (empty($sqlitePath) || !file_exists($sqlitePath)) {
                        if ($config['debug']) {
                            throw $e;
                        }
                        error_log('Database connection failed: ' . $e->getMessage());
                        die('Database connection error. Please check configuration.');
                    }
                }
            }

            // Fallback to local embedded SQLite database (Desktop Offline Mode)
            if (!empty($sqlitePath)) {
                $sqliteDir = dirname($sqlitePath);
                if (!is_dir($sqliteDir)) {
                    @mkdir($sqliteDir, 0755, true);
                }

                self::$instance = new PDO('sqlite:' . $sqlitePath, null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::$instance->exec("PRAGMA journal_mode = WAL;");
                self::$instance->exec("PRAGMA foreign_keys = ON;");
                self::$instance->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'));
                self::$instance->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
                self::$instance->sqliteCreateFunction('IF', fn($cond, $a, $b) => $cond ? $a : $b);
                self::$instance->sqliteCreateFunction('TIMESTAMPDIFF', function($unit, $from, $to) {
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
                self::$instance->sqliteCreateFunction('DATEDIFF', function($to, $from) {
                    $t1 = is_numeric($from) ? (int)$from : strtotime((string)$from);
                    $t2 = is_numeric($to) ? (int)$to : strtotime((string)$to);
                    return (int)(($t2 - $t1) / 86400);
                });
                self::$instance->sqliteCreateFunction('CONCAT', fn(...$args) => implode('', $args));
                self::$driver = 'sqlite';

                // Ensure local schema migrations and demo accounts are up to date
                $sqliteClientPath = YBT_ROOT . '/desktop/src/SqliteClient.php';
                if (file_exists($sqliteClientPath)) {
                    require_once $sqliteClientPath;
                    try {
                        $client = new SqliteClient($sqlitePath);
                        $client->runMigrations();
                    } catch (\Throwable $mErr) {
                        error_log('SQLite auto-migration notice: ' . $mErr->getMessage());
                    }
                }

                return self::$instance;
            }
        }
        return self::$instance;
    }

    public static function getDriver(): string {
        if (self::$instance === null) {
            self::getInstance();
        }
        return self::$driver;
    }

    /**
     * Shorthand query helper
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Fetch single row
     */
    public static function fetch(string $sql, array $params = []): ?array {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    /**
     * Fetch single column value
     */
    public static function fetchColumn(string $sql, array $params = []) {
        return self::query($sql, $params)->fetchColumn();
    }

    /**
     * Insert and return last insert ID
     * Supports both table name + key-value array OR raw SQL + bound parameters
     */
    public static function insert(string $tableOrSql, array $paramsOrData = []): int {
        $trimmed = ltrim($tableOrSql);
        if (stripos($trimmed, 'INSERT ') === 0) {
            self::query($tableOrSql, $paramsOrData);
            return (int)self::getInstance()->lastInsertId();
        }

        // Table-name format: Database::insert('table_name', ['col' => 'val'])
        $table = str_replace('`', '``', $tableOrSql);

        // Auto-compatibility on SQLite for sync & schema fields
        if (self::getDriver() === 'sqlite') {
            $syncTables = ['payments', 'invoices', 'customers', 'products', 'expenses', 'users', 'staff_invitations'];
            if (in_array($table, $syncTables, true)) {
                if (!isset($paramsOrData['uuid'])) {
                    $paramsOrData['uuid'] = function_exists('generateUuidV4') 
                        ? generateUuidV4() 
                        : sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
                }
                if (!isset($paramsOrData['created_at'])) {
                    $paramsOrData['created_at'] = date('Y-m-d H:i:s');
                }
                if (!isset($paramsOrData['updated_at'])) {
                    $paramsOrData['updated_at'] = date('Y-m-d H:i:s');
                }
                if ($table === 'businesses') {
                if (isset($paramsOrData['default_currency']) && !isset($paramsOrData['currency'])) {
                    $paramsOrData['currency'] = $paramsOrData['default_currency'];
                } elseif (isset($paramsOrData['currency']) && !isset($paramsOrData['default_currency'])) {
                    $paramsOrData['default_currency'] = $paramsOrData['currency'];
                }
                if (isset($paramsOrData['gstin']) && !isset($paramsOrData['tax_number'])) {
                    $paramsOrData['tax_number'] = $paramsOrData['gstin'];
                } elseif (isset($paramsOrData['tax_number']) && !isset($paramsOrData['gstin'])) {
                    $paramsOrData['gstin'] = $paramsOrData['tax_number'];
                }
            }
            if ($table === 'payments') {
                    if (isset($paramsOrData['method']) && !isset($paramsOrData['payment_method'])) {
                        $paramsOrData['payment_method'] = $paramsOrData['method'];
                    } elseif (isset($paramsOrData['payment_method']) && !isset($paramsOrData['method'])) {
                        $paramsOrData['method'] = $paramsOrData['payment_method'];
                    }
                    if (isset($paramsOrData['reference']) && !isset($paramsOrData['transaction_reference'])) {
                        $paramsOrData['transaction_reference'] = $paramsOrData['reference'];
                    } elseif (isset($paramsOrData['transaction_reference']) && !isset($paramsOrData['reference'])) {
                        $paramsOrData['reference'] = $paramsOrData['transaction_reference'];
                    }
                }
                if ($table === 'expenses') {
                    if (isset($paramsOrData['category_id']) && !isset($paramsOrData['category'])) {
                        try {
                            $catName = self::fetch("SELECT name FROM expense_categories WHERE id = ?", [$paramsOrData['category_id']]);
                            $paramsOrData['category'] = $catName['name'] ?? 'General';
                        } catch (\Throwable $e) {
                            $paramsOrData['category'] = 'General';
                        }
                    } elseif (isset($paramsOrData['category']) && !isset($paramsOrData['category_id'])) {
                        try {
                            $cat = self::fetch("SELECT id FROM expense_categories WHERE name = ?", [$paramsOrData['category']]);
                            $paramsOrData['category_id'] = $cat['id'] ?? 1;
                        } catch (\Throwable $e) {
                            $paramsOrData['category_id'] = 1;
                        }
                    }
                    if (!isset($paramsOrData['category'])) {
                        $paramsOrData['category'] = 'General';
                    }
                    if (isset($paramsOrData['method']) && !isset($paramsOrData['payment_method'])) {
                        $paramsOrData['payment_method'] = $paramsOrData['method'];
                    } elseif (isset($paramsOrData['payment_method']) && !isset($paramsOrData['method'])) {
                        $paramsOrData['method'] = $paramsOrData['payment_method'];
                    }
                }
            }
        }

        $columns = array_keys($paramsOrData);
        if (empty($columns)) {
            throw new InvalidArgumentException("Cannot insert empty dataset into {$tableOrSql}.");
        }

        $escapedCols = array_map(fn($col) => '`' . str_replace('`', '``', (string)$col) . '`', $columns);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $escapedCols),
            implode(', ', $placeholders)
        );

        self::query($sql, array_values($paramsOrData));
        return (int)self::getInstance()->lastInsertId();
    }

    /**
     * Update rows in table with key-value data and WHERE condition
     */
    public static function update(string $table, array $data, string $where = '', array $whereParams = []): int {
        if (empty($data)) {
            return 0;
        }

        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $setParts[] = '`' . str_replace('`', '``', (string)$col) . '` = ?';
            $params[] = $val;
        }

        $escapedTable = str_replace('`', '``', $table);
        $sql = sprintf('UPDATE `%s` SET %s', $escapedTable, implode(', ', $setParts));

        if (!empty($where)) {
            $sql .= ' WHERE ' . $where;
            foreach ($whereParams as $wp) {
                $params[] = $wp;
            }
        }

        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete rows from table with required WHERE condition
     */
    public static function delete(string $table, string $where, array $whereParams = []): int {
        if (empty(trim($where))) {
            throw new InvalidArgumentException('WHERE clause is strictly required for Database::delete.');
        }

        $escapedTable = str_replace('`', '``', $table);
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $escapedTable, $where);
        $stmt = self::query($sql, $whereParams);
        return $stmt->rowCount();
    }

    /**
     * Get last inserted ID
     */
    public static function lastInsertId(): string {
        return self::getInstance()->lastInsertId();
    }

    /**
     * Check if currently in an active transaction
     */
    public static function inTransaction(): bool {
        return self::getInstance()->inTransaction();
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool {
        return self::getInstance()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(): bool {
        return self::getInstance()->rollBack();
    }
}
