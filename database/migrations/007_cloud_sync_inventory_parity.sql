-- ============================================================
-- DJ CRM Software — Cloud Migration 007: Products & Inventory Sync Parity
-- Safe, Idempotent, Additive, Non-Destructive
-- ============================================================

-- 1. Products Parity
ALTER TABLE `products` 
    ADD COLUMN IF NOT EXISTS `sync_uuid` VARCHAR(64) NULL AFTER `uuid`,
    ADD COLUMN IF NOT EXISTS `sync_version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `sync_status`;

-- 2. Stock Movements Parity
ALTER TABLE `stock_movements`
    ADD COLUMN IF NOT EXISTS `uuid` VARCHAR(64) NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `idempotency_key` VARCHAR(100) NULL AFTER `notes`,
    ADD COLUMN IF NOT EXISTS `sync_status` VARCHAR(20) NOT NULL DEFAULT 'synced' AFTER `idempotency_key`,
    ADD COLUMN IF NOT EXISTS `sync_version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `sync_status`;

-- 3. Indexes for Search & Delta Pull
CREATE INDEX IF NOT EXISTS `idx_products_sync_ver` ON `products` (`business_id`, `sync_version`);
CREATE INDEX IF NOT EXISTS `idx_products_barcode` ON `products` (`business_id`, `barcode`);
CREATE INDEX IF NOT EXISTS `idx_products_sku` ON `products` (`business_id`, `sku`);
CREATE INDEX IF NOT EXISTS `idx_stock_mov_sync_ver` ON `stock_movements` (`business_id`, `sync_version`);
CREATE INDEX IF NOT EXISTS `idx_stock_mov_idemp` ON `stock_movements` (`business_id`, `idempotency_key`);
CREATE INDEX IF NOT EXISTS `idx_stock_mov_uuid` ON `stock_movements` (`business_id`, `sync_uuid`);
