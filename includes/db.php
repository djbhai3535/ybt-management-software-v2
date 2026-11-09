<?php
/**
 * YBT Management Software — Database Connection (PDO Singleton)
 * Seamlessly connects to Cloud MySQL or falls back to embedded SQLite in desktop offline mode.
 */

class Database {
    private static ?PDO $instance = null;
    private static string $driver = 'mysql';
    private static array $tableColumnCache = [];

    /**
     * Inspect and cache column names for a given table
     */
    public static function getTableColumns(string $table): array {
        $table = trim(str_replace('`', '', $table));
        if (isset(self::$tableColumnCache[$table])) {
            return self::$tableColumnCache[$table];
        }

        $cols = [];
        try {
            if (self::getDriver() === 'sqlite') {
                $rows = self::fetchAll("PRAGMA table_info(`{$table}`)");
                foreach ($rows as $r) {
                    if (!empty($r['name'])) {
                        $cols[strtolower($r['name'])] = true;
                    }
                }
            } else {
                $rows = self::fetchAll("SHOW COLUMNS FROM `{$table}`");
                foreach ($rows as $r) {
                    if (!empty($r['Field'])) {
                        $cols[strtolower($r['Field'])] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        self::$tableColumnCache[$table] = $cols;
        return $cols;
    }

    /**
     * Automatically normalize and cross-map fields across schema variations
     */
    public static function normalizeTableData(string $table, array $data): array {
        $cleanTable = strtolower(trim(str_replace('`', '', $table)));

        if ($cleanTable === 'invoices') {
            if (isset($data['grand_total']) && !isset($data['total_amount'])) {
                $data['total_amount'] = $data['grand_total'];
            } elseif (isset($data['total_amount']) && !isset($data['grand_total'])) {
                $data['grand_total'] = $data['total_amount'];
            }
            if (isset($data['amount_paid']) && !isset($data['paid_amount'])) {
                $data['paid_amount'] = $data['amount_paid'];
            } elseif (isset($data['paid_amount']) && !isset($data['amount_paid'])) {
                $data['amount_paid'] = $data['paid_amount'];
            }
            if (isset($data['outstanding']) && !isset($data['balance_amount'])) {
                $data['balance_amount'] = $data['outstanding'];
            } elseif (isset($data['balance_amount']) && !isset($data['outstanding'])) {
                $data['outstanding'] = $data['balance_amount'];
            }
            if (isset($data['invoice_date']) && !isset($data['issue_date'])) {
                $data['issue_date'] = $data['invoice_date'];
            } elseif (isset($data['issue_date']) && !isset($data['invoice_date'])) {
                $data['invoice_date'] = $data['issue_date'];
            }
            if (isset($data['tax_total']) && !isset($data['tax_amount'])) {
                $data['tax_amount'] = $data['tax_total'];
            } elseif (isset($data['tax_amount']) && !isset($data['tax_total'])) {
                $data['tax_total'] = $data['tax_amount'];
            }
            if (isset($data['discount_total']) && !isset($data['discount_amount'])) {
                $data['discount_amount'] = $data['discount_total'];
            } elseif (isset($data['discount_amount']) && !isset($data['discount_total'])) {
                $data['discount_total'] = $data['discount_amount'];
            }
            if (isset($data['uuid']) && !isset($data['sync_uuid'])) {
                $data['sync_uuid'] = $data['uuid'];
            } elseif (isset($data['sync_uuid']) && !isset($data['uuid'])) {
                $data['uuid'] = $data['sync_uuid'];
            }
        } elseif ($cleanTable === 'invoice_items' || $cleanTable === 'quotation_items') {
            if (isset($data['description']) && !isset($data['item_name'])) {
                $data['item_name'] = $data['description'];
            }
            if (isset($data['description']) && !isset($data['item_description'])) {
                $data['item_description'] = $data['description'];
            }
            if (isset($data['item_name']) && !isset($data['description'])) {
                $data['description'] = $data['item_name'];
            }
            if (isset($data['line_total']) && !isset($data['total_amount'])) {
                $data['total_amount'] = $data['line_total'];
            } elseif (isset($data['total_amount']) && !isset($data['line_total'])) {
                $data['line_total'] = $data['total_amount'];
            }
        } elseif ($cleanTable === 'quotations') {
            if (isset($data['grand_total']) && !isset($data['total_amount'])) {
                $data['total_amount'] = $data['grand_total'];
            } elseif (isset($data['total_amount']) && !isset($data['grand_total'])) {
                $data['grand_total'] = $data['total_amount'];
            }
            if (isset($data['quotation_date']) && !isset($data['issue_date'])) {
                $data['issue_date'] = $data['quotation_date'];
            } elseif (isset($data['issue_date']) && !isset($data['quotation_date'])) {
                $data['quotation_date'] = $data['issue_date'];
            }
            if (isset($data['validity_date']) && !isset($data['valid_until'])) {
                $data['valid_until'] = $data['validity_date'];
            } elseif (isset($data['valid_until']) && !isset($data['validity_date'])) {
                $data['validity_date'] = $data['valid_until'];
            }
            if (isset($data['tax_total']) && !isset($data['tax_amount'])) {
                $data['tax_amount'] = $data['tax_total'];
            } elseif (isset($data['tax_amount']) && !isset($data['tax_total'])) {
                $data['tax_total'] = $data['tax_amount'];
            }
            if (isset($data['discount_total']) && !isset($data['discount_amount'])) {
                $data['discount_amount'] = $data['discount_total'];
            } elseif (isset($data['discount_amount']) && !isset($data['discount_total'])) {
                $data['discount_total'] = $data['discount_amount'];
            }
        } elseif ($cleanTable === 'payments') {
            if (isset($data['method']) && !isset($data['payment_method'])) {
                $data['payment_method'] = $data['method'];
            } elseif (isset($data['payment_method']) && !isset($data['method'])) {
                $data['method'] = $data['payment_method'];
            }
            if (isset($data['reference']) && !isset($data['reference_number'])) {
                $data['reference_number'] = $data['reference'];
            } elseif (isset($data['transaction_reference']) && !isset($data['reference_number'])) {
                $data['reference_number'] = $data['transaction_reference'];
            } elseif (isset($data['reference_number']) && !isset($data['reference'])) {
                $data['reference'] = $data['reference_number'];
            }
            if (isset($data['uuid']) && !isset($data['sync_uuid'])) {
                $data['sync_uuid'] = $data['uuid'];
            } elseif (isset($data['sync_uuid']) && !isset($data['uuid'])) {
                $data['uuid'] = $data['sync_uuid'];
            }
        } elseif ($cleanTable === 'expenses') {
            if (isset($data['method']) && !isset($data['payment_method'])) {
                $data['payment_method'] = $data['method'];
            } elseif (isset($data['payment_method']) && !isset($data['method'])) {
                $data['method'] = $data['payment_method'];
            }
            if (isset($data['vendor']) && !isset($data['payee'])) {
                $data['payee'] = $data['vendor'];
            } elseif (isset($data['payee']) && !isset($data['vendor'])) {
                $data['vendor'] = $data['payee'];
            }
            if (isset($data['reference']) && !isset($data['reference_number'])) {
                $data['reference_number'] = $data['reference'];
            } elseif (isset($data['reference_number']) && !isset($data['reference'])) {
                $data['reference'] = $data['reference_number'];
            }
            if (isset($data['notes']) && !isset($data['description'])) {
                $data['description'] = $data['notes'];
            } elseif (isset($data['description']) && !isset($data['notes'])) {
                $data['notes'] = $data['description'];
            }
            if (isset($data['uuid']) && !isset($data['sync_uuid'])) {
                $data['sync_uuid'] = $data['uuid'];
            } elseif (isset($data['sync_uuid']) && !isset($data['uuid'])) {
                $data['uuid'] = $data['sync_uuid'];
            }
        } elseif ($cleanTable === 'products') {
            if (isset($data['cost_price']) && !isset($data['purchase_price'])) {
                $data['purchase_price'] = $data['cost_price'];
            } elseif (isset($data['purchase_price']) && !isset($data['cost_price'])) {
                $data['cost_price'] = $data['purchase_price'];
            }
            if (isset($data['selling_price']) && !isset($data['sale_price'])) {
                $data['sale_price'] = $data['selling_price'];
            } elseif (isset($data['sale_price']) && !isset($data['selling_price'])) {
                $data['selling_price'] = $data['sale_price'];
            }
            if (isset($data['uuid']) && !isset($data['sync_uuid'])) {
                $data['sync_uuid'] = $data['uuid'];
            } elseif (isset($data['sync_uuid']) && !isset($data['uuid'])) {
                $data['uuid'] = $data['sync_uuid'];
            }
        } elseif ($cleanTable === 'businesses') {
            if (isset($data['default_currency']) && !isset($data['currency'])) {
                $data['currency'] = $data['default_currency'];
            } elseif (isset($data['currency']) && !isset($data['default_currency'])) {
                $data['default_currency'] = $data['currency'];
            }
            if (isset($data['gstin']) && !isset($data['tax_number'])) {
                $data['tax_number'] = $data['gstin'];
            } elseif (isset($data['tax_number']) && !isset($data['gstin'])) {
                $data['gstin'] = $data['tax_number'];
            }
        } elseif ($cleanTable === 'customers') {
            if (isset($data['billing_city']) && !isset($data['city'])) {
                $data['city'] = $data['billing_city'];
            } elseif (isset($data['city']) && !isset($data['billing_city'])) {
                $data['billing_city'] = $data['city'];
            }
            if (isset($data['billing_address_line1']) && !isset($data['address'])) {
                $data['address'] = $data['billing_address_line1'];
            } elseif (isset($data['billing_address']) && !isset($data['address'])) {
                $data['address'] = $data['billing_address'];
            } elseif (isset($data['address'])) {
                if (!isset($data['billing_address_line1'])) {
                    $data['billing_address_line1'] = $data['address'];
                }
                if (!isset($data['billing_address'])) {
                    $data['billing_address'] = $data['address'];
                }
            }
            if (isset($data['current_balance']) && !isset($data['balance'])) {
                $data['balance'] = $data['current_balance'];
            } elseif (isset($data['balance']) && !isset($data['current_balance'])) {
                $data['current_balance'] = $data['balance'];
            }
            if (isset($data['uuid']) && !isset($data['sync_uuid'])) {
                $data['sync_uuid'] = $data['uuid'];
            } elseif (isset($data['sync_uuid']) && !isset($data['uuid'])) {
                $data['uuid'] = $data['sync_uuid'];
            }
        } elseif ($cleanTable === 'ai_provider_configs') {
            if (isset($data['provider_type']) && !isset($data['provider_slug'])) {
                $data['provider_slug'] = $data['provider_type'];
            } elseif (isset($data['provider_slug']) && !isset($data['provider_type'])) {
                $data['provider_type'] = $data['provider_slug'];
            }
            if (isset($data['base_url']) && !isset($data['api_endpoint'])) {
                $data['api_endpoint'] = $data['base_url'];
            } elseif (isset($data['api_endpoint']) && !isset($data['base_url'])) {
                $data['base_url'] = $data['api_endpoint'];
            }
            if (isset($data['model_id']) && !isset($data['model_name'])) {
                $data['model_name'] = $data['model_id'];
            } elseif (isset($data['model_name']) && !isset($data['model_id'])) {
                $data['model_id'] = $data['model_name'];
            }
        }

        // Filter against existing table columns to prevent Unknown column errors
        $existingCols = self::getTableColumns($cleanTable);
        if (!empty($existingCols)) {
            $filtered = [];
            foreach ($data as $col => $val) {
                if (isset($existingCols[strtolower($col)])) {
                    $filtered[$col] = $val;
                }
            }
            return $filtered;
        }

        return $data;
    }

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
                        if (defined('YBT_API_REQUEST')) {
                            http_response_code(500);
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode([
                                'success' => false,
                                'error' => [
                                    'code' => 'DATABASE_CONNECTION_ERROR',
                                    'message' => 'Database connection error. Please check configuration.'
                                ]
                            ]);
                            exit;
                        }
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

    public static function driver(): string {
        return self::getDriver();
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

        // Normalize schema field aliases and filter against existing table columns
        $paramsOrData = self::normalizeTableData($table, $paramsOrData);

        // Auto-compatibility for sync & schema fields
        $syncTables = ['payments', 'invoices', 'customers', 'products', 'expenses', 'users', 'staff_invitations'];
        if (in_array(strtolower($table), $syncTables, true)) {
            if (!isset($paramsOrData['uuid']) && !isset($paramsOrData['sync_uuid'])) {
                $genUuid = function_exists('generateUuidV4') 
                    ? generateUuidV4() 
                    : sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
                $paramsOrData['uuid'] = $genUuid;
                $paramsOrData['sync_uuid'] = $genUuid;
            }
            if (!isset($paramsOrData['created_at'])) {
                $paramsOrData['created_at'] = date('Y-m-d H:i:s');
            }
            if (!isset($paramsOrData['updated_at'])) {
                $paramsOrData['updated_at'] = date('Y-m-d H:i:s');
            }
        }

        // Final column filter after default injection
        $existingCols = self::getTableColumns($table);
        if (!empty($existingCols)) {
            $filtered = [];
            foreach ($paramsOrData as $c => $v) {
                if (isset($existingCols[strtolower($c)])) {
                    $filtered[$c] = $v;
                }
            }
            $paramsOrData = $filtered;
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

        // Normalize schema field aliases and filter against existing table columns
        $data = self::normalizeTableData($table, $data);
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
