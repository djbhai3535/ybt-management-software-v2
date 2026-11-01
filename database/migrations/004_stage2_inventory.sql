-- ============================================================
-- YBT Management Software — Stage 2 Mobile Parts Inventory & Stock Automation
-- Migration: 004_stage2_inventory.sql (MySQL Cloud)
-- ============================================================

ALTER TABLE `products` 
    ADD COLUMN `brand` VARCHAR(100) NULL DEFAULT NULL AFTER `category`,
    ADD COLUMN `model` VARCHAR(100) NULL DEFAULT NULL AFTER `brand`,
    ADD COLUMN `part_type` VARCHAR(100) NULL DEFAULT 'Display / Folder' AFTER `model`,
    ADD COLUMN `supplier_name` VARCHAR(150) NULL DEFAULT NULL AFTER `min_stock_alert`,
    ADD COLUMN `allow_negative_stock` TINYINT(1) DEFAULT 0 AFTER `supplier_name`;

ALTER TABLE `invoices`
    ADD COLUMN `stock_deducted` TINYINT(1) DEFAULT 0 AFTER `status`,
    ADD COLUMN `stock_restored` TINYINT(1) DEFAULT 0 AFTER `stock_deducted`;

CREATE TABLE IF NOT EXISTS `stock_movements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `business_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `type` ENUM('purchase', 'sale', 'return_customer', 'return_supplier', 'adjustment', 'cancellation_restore') NOT NULL,
    `quantity` DECIMAL(12, 3) NOT NULL,
    `quantity_before` DECIMAL(12, 3) NOT NULL,
    `quantity_after` DECIMAL(12, 3) NOT NULL,
    `reference_type` VARCHAR(50) NULL DEFAULT NULL,
    `reference_id` INT UNSIGNED NULL DEFAULT NULL,
    `reference_number` VARCHAR(100) NULL DEFAULT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `sync_uuid` VARCHAR(64) NULL DEFAULT NULL,
    INDEX `idx_stock_mov_biz_prod` (`business_id`, `product_id`),
    INDEX `idx_stock_mov_ref` (`reference_type`, `reference_id`),
    INDEX `idx_stock_mov_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
