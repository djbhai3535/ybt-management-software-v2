-- 014_schema_clean_pilot_parity.sql
-- Synchronizes desktop SQLite schema with cloud MySQL clean_pilot_schema

-- Invoices canonical columns
ALTER TABLE `invoices` ADD COLUMN `total_amount` DECIMAL(15,2) DEFAULT 0.00;
ALTER TABLE `invoices` ADD COLUMN `paid_amount` DECIMAL(15,2) DEFAULT 0.00;
ALTER TABLE `invoices` ADD COLUMN `balance_amount` DECIMAL(15,2) DEFAULT 0.00;
ALTER TABLE `invoices` ADD COLUMN `issue_date` DATE DEFAULT NULL;
ALTER TABLE `invoices` ADD COLUMN `tax_amount` DECIMAL(15,2) DEFAULT 0.00;
ALTER TABLE `invoices` ADD COLUMN `discount_amount` DECIMAL(15,2) DEFAULT 0.00;

-- Quotations canonical columns
ALTER TABLE `quotations` ADD COLUMN `total_amount` DECIMAL(15,2) DEFAULT 0.00;
ALTER TABLE `quotations` ADD COLUMN `issue_date` DATE DEFAULT NULL;
ALTER TABLE `quotations` ADD COLUMN `valid_until` DATE DEFAULT NULL;
ALTER TABLE `quotations` ADD COLUMN `tax_amount` DECIMAL(15,2) DEFAULT 0.00;
ALTER TABLE `quotations` ADD COLUMN `discount_amount` DECIMAL(15,2) DEFAULT 0.00;

-- Expenses canonical columns
ALTER TABLE `expenses` ADD COLUMN `is_deleted` INTEGER DEFAULT 0;
ALTER TABLE `expenses` ADD COLUMN `payee` TEXT DEFAULT NULL;
ALTER TABLE `expenses` ADD COLUMN `reference_number` TEXT DEFAULT NULL;

-- Payments canonical columns
ALTER TABLE `payments` ADD COLUMN `reference_number` TEXT DEFAULT NULL;
