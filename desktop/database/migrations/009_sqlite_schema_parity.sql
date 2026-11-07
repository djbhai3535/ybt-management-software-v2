-- ============================================================
-- YBT Management Software — Stage 4 Schema Parity & Khata Attachments
-- Migration: 009_sqlite_schema_parity.sql
-- Description: Adds product cost_price, created_by, and customer_ledger attachment columns
-- Idempotent & Non-Destructive
-- ============================================================

-- 1. Product cost_price & created_by parity
ALTER TABLE `products` ADD COLUMN `cost_price` REAL DEFAULT 0.00;
ALTER TABLE `products` ADD COLUMN `created_by` INTEGER DEFAULT NULL;

-- 2. Customer Ledger attachments & item details
ALTER TABLE `customer_ledger` ADD COLUMN `image_attachment` TEXT DEFAULT NULL;
ALTER TABLE `customer_ledger` ADD COLUMN `voice_attachment` TEXT DEFAULT NULL;
ALTER TABLE `customer_ledger` ADD COLUMN `item_details` TEXT DEFAULT NULL;
