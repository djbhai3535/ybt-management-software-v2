<?php
/**
 * YBT Management Software — Backup Service
 * Creates structured SQL / JSON data backups saved in protected storage
 * Properly handles parent and child relational queries per business.
 */

class BackupService {
    /**
     * Create database backup file
     */
    public static function createBackup(int $businessId, int $userId): array {
        $backupDir = YBT_ROOT . '/storage/backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        $filename = 'ybt_backup_' . date('Ymd_His') . '.sql';
        $filepath = $backupDir . '/' . $filename;

        $tables = [
            'businesses', 'customers', 'products', 'leads', 'tasks',
            'quotations', 'quotation_items', 'invoices', 'invoice_items',
            'payments', 'payment_allocations', 'expense_categories', 'expenses',
            'roles', 'role_permissions', 'users', 'activity_logs'
        ];

        $handle = fopen($filepath, 'w');
        if (!$handle) {
            throw new Exception("Unable to open backup file for writing: {$filepath}");
        }

        fwrite($handle, "-- YBT Management Software Database Backup\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Version: " . APP_VERSION . "\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            // Relational scoping for tables without direct business_id column
            if ($table === 'businesses') {
                $sql = "SELECT * FROM `businesses` WHERE id = ?";
            } elseif ($table === 'quotation_items') {
                $sql = "SELECT qi.* FROM `quotation_items` qi JOIN `quotations` q ON qi.quotation_id = q.id WHERE q.business_id = ?";
            } elseif ($table === 'invoice_items') {
                $sql = "SELECT ii.* FROM `invoice_items` ii JOIN `invoices` i ON ii.invoice_id = i.id WHERE i.business_id = ?";
            } elseif ($table === 'payment_allocations') {
                $sql = "SELECT pa.* FROM `payment_allocations` pa JOIN `payments` p ON pa.payment_id = p.id WHERE p.business_id = ?";
            } elseif ($table === 'role_permissions') {
                $sql = "SELECT rp.* FROM `role_permissions` rp JOIN `roles` r ON rp.role_id = r.id WHERE r.business_id = ?";
            } else {
                $sql = "SELECT * FROM `{$table}` WHERE business_id = ?";
            }

            $rows = Database::fetchAll($sql, [$businessId]);
            if (empty($rows)) continue;

            fwrite($handle, "-- Table: `{$table}`\n");
            foreach ($rows as $row) {
                $cols = array_map(function($c) { return "`{$c}`"; }, array_keys($row));
                $vals = array_map(function($v) {
                    if ($v === null) return "NULL";
                    return Database::getInstance()->quote((string)$v);
                }, array_values($row));

                fwrite($handle, "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ") ON DUPLICATE KEY UPDATE `id` = `id`;\n");
            }
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        $size = filesize($filepath);

        // Record in backup_records table
        Database::insert('backup_records', [
            'business_id' => $businessId,
            'filename' => $filename,
            'file_path' => $filepath,
            'size_bytes' => $size,
            'status' => 'completed',
            'created_by' => $userId
        ]);

        ActivityLog::log($businessId, $userId, 'backup_created', 'backup', 0, "Created system backup {$filename} (" . formatFileSize($size) . ")");

        return [
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => $size
        ];
    }

    /**
     * List all available backups
     */
    public static function listBackups(int $businessId): array {
        return Database::fetchAll(
            "SELECT * FROM backup_records WHERE business_id = ? ORDER BY created_at DESC",
            [$businessId]
        );
    }
}
