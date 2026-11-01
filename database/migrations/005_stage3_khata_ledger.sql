-- ============================================================
-- YBT Management Software — Stage 3 Customer Khata / Ledger & Payment Automation
-- Migration: 005_stage3_khata_ledger.sql (MySQL Cloud Parity)
-- Idempotent & Non-Destructive
-- ============================================================

-- 1. Customer Khata Balance Attributes
SET @col1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'opening_balance');
SET @sql1 = IF(@col1 = 0, 'ALTER TABLE `customers` ADD COLUMN `opening_balance` DECIMAL(15,2) DEFAULT 0.00', 'SELECT 1');
PREPARE stmt1 FROM @sql1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

SET @col2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'opening_balance_type');
SET @sql2 = IF(@col2 = 0, 'ALTER TABLE `customers` ADD COLUMN `opening_balance_type` VARCHAR(20) DEFAULT "debit"', 'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET @col3 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'current_balance');
SET @sql3 = IF(@col3 = 0, 'ALTER TABLE `customers` ADD COLUMN `current_balance` DECIMAL(15,2) DEFAULT 0.00', 'SELECT 1');
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

-- 2. Customer Ledger Table
CREATE TABLE IF NOT EXISTS `customer_ledger` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid` VARCHAR(36) NOT NULL UNIQUE,
    `business_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `entry_type` VARCHAR(50) NOT NULL,
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id` INT UNSIGNED DEFAULT NULL,
    `reference_number` VARCHAR(100) DEFAULT NULL,
    `entry_date` DATE NOT NULL,
    `debit` DECIMAL(15,2) DEFAULT 0.00,
    `credit` DECIMAL(15,2) DEFAULT 0.00,
    `running_balance` DECIMAL(15,2) DEFAULT 0.00,
    `payment_method` VARCHAR(50) DEFAULT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `idempotency_key` VARCHAR(100) UNIQUE DEFAULT NULL,
    `sync_uuid` VARCHAR(36) DEFAULT NULL,
    `sync_status` VARCHAR(20) DEFAULT 'pending',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cust_ledger_cust_date` (`business_id`, `customer_id`, `entry_date`, `id`),
    INDEX `idx_cust_ledger_ref` (`business_id`, `reference_type`, `reference_id`),
    INDEX `idx_cust_ledger_uuid` (`uuid`),
    INDEX `idx_cust_ledger_idemp` (`idempotency_key`),
    FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
