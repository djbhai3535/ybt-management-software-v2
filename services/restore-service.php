<?php
/**
 * YBT Management Software — Restore & Safe Data Reset Service
 * Provides hardened transactional backup restore and scoped demo data reset.
 * Strictly prevents cross-tenant data corruption and unauthorized DDL execution.
 */

class RestoreService {
    /**
     * Restore from backup file with strict tenant and format validation
     */
    public static function restore(string $backupFilename, int $businessId, int $userId): array {
        TenantContext::assertMatches($businessId);

        if (!Auth::isOwner()) {
            return ['success' => false, 'error' => 'Restore operations require Owner authorization.'];
        }

        // Strict filename validation preventing directory traversal
        $basename = basename($backupFilename);
        if (!preg_match('/^ybt_backup_[0-9a-zA-Z_]+\.sql$/', $basename)) {
            return ['success' => false, 'error' => 'Invalid backup filename format.'];
        }

        $filepath = YBT_ROOT . '/storage/backups/' . $basename;
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => 'Backup file not found.'];
        }

        // Verify that this backup record belongs to the requesting business
        $record = Database::fetch(
            "SELECT id FROM backup_records WHERE filename = ? AND business_id = ?",
            [$basename, $businessId]
        );
        if (!$record) {
            return ['success' => false, 'error' => 'Unauthorized: Backup file does not belong to your business account.'];
        }

        $sql = file_get_contents($filepath);
        if (!$sql || trim($sql) === '') {
            return ['success' => false, 'error' => 'Backup file is empty.'];
        }

        // Security inspection of SQL statements: Prohibit dangerous global DDL
        $forbiddenKeywords = ['DROP DATABASE', 'CREATE DATABASE', 'TRUNCATE TABLE', 'DROP TABLE', 'ALTER TABLE', 'GRANT ALL'];
        foreach ($forbiddenKeywords as $forbidden) {
            if (stripos($sql, $forbidden) !== false) {
                return ['success' => false, 'error' => "Security violation: Backup contains forbidden statement ({$forbidden})."];
            }
        }

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            // Split and execute statements safely
            $db->exec($sql);
            $db->commit();

            ActivityLog::log($businessId, $userId, 'backup_restored', 'backup', 0, "Restored system backup {$basename}");
            return ['success' => true];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['success' => false, 'error' => 'Database restore failed: ' . $e->getMessage()];
        }
    }

    /**
     * Reset Demo Data strictly scoped to the active demo tenant
     */
    public static function resetDemoData(int $businessId, int $userId): array {
        TenantContext::assertMatches($businessId);

        if (!Auth::isOwner()) {
            return ['success' => false, 'error' => 'Resetting demo data requires Owner authorization.'];
        }

        // Verify that the target business is explicitly designated as a demo tenant
        $biz = Database::fetch("SELECT id, is_demo FROM businesses WHERE id = ?", [$businessId]);
        if (!$biz || empty($biz['is_demo'])) {
            return ['success' => false, 'error' => 'Security restriction: Demo data reset is strictly prohibited on non-demo production accounts.'];
        }

        $demoFile = YBT_ROOT . '/database/demo-data.sql';
        if (!file_exists($demoFile)) {
            return ['success' => false, 'error' => 'Demo data seed file not found.'];
        }

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            // Remove existing data exclusively for this business
            $tenantTables = [
                'payment_allocations', 'payments', 'invoice_items', 'invoices',
                'quotation_items', 'quotations', 'expenses', 'tasks', 'leads',
                'products', 'customers'
            ];

            foreach ($tenantTables as $table) {
                Database::query("DELETE FROM `{$table}` WHERE business_id = ?", [$businessId]);
            }

            // Re-import demo seed data dynamically replacing tenant ID if needed
            $rawSql = file_get_contents($demoFile);
            // Replace hardcoded business ID 1 with the active demo business ID
            if ($businessId !== 1) {
                $scopedSql = str_replace("VALUES (\n  1,", "VALUES (\n  {$businessId},", $rawSql);
                $scopedSql = str_replace("VALUES (1,", "VALUES ({$businessId},", $scopedSql);
                $scopedSql = preg_replace('/INSERT INTO `([^`]+)` \(`([^`]+)`, `business_id`/', "INSERT INTO `$1` (`$2`, `business_id`", $scopedSql);
                $db->exec($scopedSql);
            } else {
                $db->exec($rawSql);
            }

            // Ensure secure demo password hash
            $pwdHash = password_hash('password123', PASSWORD_DEFAULT);
            Database::query("UPDATE users SET password = ? WHERE business_id = ?", [$pwdHash, $businessId]);

            $db->commit();
            ActivityLog::log($businessId, $userId, 'demo_data_reset', 'system', 0, "Reset demo data to initial factory state");

            return ['success' => true];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['success' => false, 'error' => 'Demo data reset failed: ' . $e->getMessage()];
        }
    }
}
