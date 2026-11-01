-- ============================================================
-- YBT Management Software — Stage 2 Mobile Parts Inventory & Stock Automation
-- Migration: 007_sqlite_stage2_inventory.sql
-- Description: Mobile phone parts attributes, stock movements audit trail, and invoice stock deduction flags
-- Idempotent & Non-Destructive
-- ============================================================

-- 1. Products Mobile Spare-Parts Attributes
ALTER TABLE `products` ADD COLUMN `brand` TEXT DEFAULT NULL;
ALTER TABLE `products` ADD COLUMN `model` TEXT DEFAULT NULL;
ALTER TABLE `products` ADD COLUMN `part_type` TEXT DEFAULT 'Display / Folder';
ALTER TABLE `products` ADD COLUMN `supplier_name` TEXT DEFAULT NULL;
ALTER TABLE `products` ADD COLUMN `allow_negative_stock` INTEGER DEFAULT 0;

-- 2. Invoice Stock Deducted & Restored Idempotency Flags
ALTER TABLE `invoices` ADD COLUMN `stock_deducted` INTEGER DEFAULT 0;
ALTER TABLE `invoices` ADD COLUMN `stock_restored` INTEGER DEFAULT 0;

-- 3. Stock Movements Audit Trail Table
CREATE TABLE IF NOT EXISTS `stock_movements` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `product_id` INTEGER NOT NULL,
    `type` TEXT NOT NULL, -- 'purchase', 'sale', 'return_customer', 'return_supplier', 'adjustment', 'cancellation_restore'
    `quantity` REAL NOT NULL,
    `quantity_before` REAL NOT NULL,
    `quantity_after` REAL NOT NULL,
    `reference_type` TEXT DEFAULT NULL, -- 'invoice', 'purchase', 'adjustment', 'return'
    `reference_id` INTEGER DEFAULT NULL,
    `reference_number` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INTEGER DEFAULT NULL,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    `sync_uuid` TEXT DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS `idx_stock_mov_biz_prod` ON `stock_movements` (`business_id`, `product_id`);
CREATE INDEX IF NOT EXISTS `idx_stock_mov_ref` ON `stock_movements` (`reference_type`, `reference_id`);
CREATE INDEX IF NOT EXISTS `idx_stock_mov_date` ON `stock_movements` (`created_at`);
