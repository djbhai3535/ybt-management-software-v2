-- ============================================================
-- YBT Management Software — Stage 3 Customer Khata / Ledger & Payment Automation
-- Migration: 008_sqlite_stage3_khata_ledger.sql
-- Description: Customer opening balance, ledger audit trail, running balance, payment methods
-- Idempotent & Non-Destructive
-- ============================================================

-- 1. Customer Khata Balance Attributes
ALTER TABLE `customers` ADD COLUMN `opening_balance` REAL DEFAULT 0.00;
ALTER TABLE `customers` ADD COLUMN `opening_balance_type` TEXT DEFAULT 'debit';
ALTER TABLE `customers` ADD COLUMN `current_balance` REAL DEFAULT 0.00;

-- 2. Customer Ledger Entries (Khata Book) Table
CREATE TABLE IF NOT EXISTS `customer_ledger` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `uuid` TEXT NOT NULL UNIQUE,
    `business_id` INTEGER NOT NULL,
    `customer_id` INTEGER NOT NULL,
    `entry_type` TEXT NOT NULL, -- 'opening_balance', 'invoice', 'payment', 'advance_payment', 'invoice_cancelled', 'adjustment', 'reversal'
    `reference_type` TEXT DEFAULT NULL, -- 'invoice', 'payment', 'manual'
    `reference_id` INTEGER DEFAULT NULL,
    `reference_number` TEXT DEFAULT NULL,
    `entry_date` TEXT NOT NULL,
    `debit` REAL DEFAULT 0.00, -- You gave (آپ نے دیے) / Invoiced / Debit
    `credit` REAL DEFAULT 0.00, -- You got (آپ کو ملے) / Payment / Credit
    `running_balance` REAL DEFAULT 0.00, -- Balance after this transaction
    `payment_method` TEXT DEFAULT NULL, -- 'cash', 'bank_transfer', 'jazzcash', 'easypaisa', 'raast', etc.
    `description` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `idempotency_key` TEXT UNIQUE DEFAULT NULL, -- Prevents duplicate entries on network retries or sync
    `sync_uuid` TEXT DEFAULT NULL,
    `sync_status` TEXT DEFAULT 'pending',
    `created_by` INTEGER DEFAULT NULL,
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS `idx_customer_ledger_cust_date` ON `customer_ledger` (`business_id`, `customer_id`, `entry_date`, `id`);
CREATE INDEX IF NOT EXISTS `idx_customer_ledger_ref` ON `customer_ledger` (`business_id`, `reference_type`, `reference_id`);
CREATE INDEX IF NOT EXISTS `idx_customer_ledger_uuid` ON `customer_ledger` (`uuid`);
CREATE INDEX IF NOT EXISTS `idx_customer_ledger_idemp` ON `customer_ledger` (`idempotency_key`);
