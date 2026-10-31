-- ============================================================
-- YBT Management Software — SQLite Local Offline Schema
-- Migration: 001_sqlite_initial_schema.sql
-- Compatible with SQLite 3 (WAL mode)
-- ============================================================

-- 1. Local Authenticated Sessions
CREATE TABLE IF NOT EXISTS `local_sessions` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `device_id` TEXT NOT NULL,
    `business_id` INTEGER NOT NULL,
    `tenant_uuid` TEXT NOT NULL,
    `user_id` INTEGER NOT NULL,
    `user_name` TEXT NOT NULL,
    `user_email` TEXT NOT NULL,
    `role_slug` TEXT NOT NULL,
    `password_hash` TEXT NOT NULL,
    `encrypted_api_token` TEXT NOT NULL,
    `offline_access_allowed` INTEGER DEFAULT 1,
    `last_online_login_at` TEXT,
    `last_offline_login_at` TEXT,
    `expires_at` TEXT,
    `status` TEXT DEFAULT 'active'
);
CREATE UNIQUE INDEX IF NOT EXISTS `idx_local_sessions_email` ON `local_sessions` (`user_email`, `business_id`);
CREATE INDEX IF NOT EXISTS `idx_local_sessions_lookup` ON `local_sessions` (`device_id`, `status`);

-- 2. Local Businesses Table
CREATE TABLE IF NOT EXISTS `businesses` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `cloud_id` INTEGER UNIQUE,
    `tenant_uuid` TEXT NOT NULL UNIQUE,
    `name` TEXT NOT NULL,
    `legal_name` TEXT,
    `type` TEXT,
    `phone` TEXT,
    `whatsapp` TEXT,
    `address` TEXT,
    `city` TEXT,
    `state` TEXT,
    `country` TEXT DEFAULT 'India',
    `currency` TEXT DEFAULT 'INR',
    `invoice_prefix` TEXT DEFAULT 'INV-',
    `gstin` TEXT,
    `sync_status` TEXT DEFAULT 'synced',
    `updated_at` TEXT
);
CREATE INDEX IF NOT EXISTS `idx_businesses_uuid` ON `businesses` (`tenant_uuid`);

-- 3. Local Customers Table
CREATE TABLE IF NOT EXISTS `customers` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `uuid` TEXT NOT NULL UNIQUE,
    `cloud_id` INTEGER,
    `business_id` INTEGER NOT NULL,
    `name` TEXT NOT NULL,
    `phone` TEXT,
    `email` TEXT,
    `address` TEXT,
    `city` TEXT,
    `state` TEXT,
    `pincode` TEXT,
    `gstin` TEXT,
    `notes` TEXT,
    `sync_status` TEXT DEFAULT 'pending',
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_customers_biz_phone` ON `customers` (`business_id`, `phone`);
CREATE INDEX IF NOT EXISTS `idx_customers_uuid` ON `customers` (`uuid`);
CREATE INDEX IF NOT EXISTS `idx_customers_sync` ON `customers` (`sync_status`);

-- 4. Local Products Table
CREATE TABLE IF NOT EXISTS `products` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `uuid` TEXT NOT NULL UNIQUE,
    `cloud_id` INTEGER,
    `business_id` INTEGER NOT NULL,
    `name` TEXT NOT NULL,
    `category` TEXT,
    `sku` TEXT,
    `barcode` TEXT,
    `purchase_price` REAL DEFAULT 0.00,
    `selling_price` REAL DEFAULT 0.00,
    `tax_rate` REAL DEFAULT 0.00,
    `stock_quantity` INTEGER DEFAULT 0,
    `min_stock_alert` INTEGER DEFAULT 5,
    `unit` TEXT DEFAULT 'pcs',
    `sync_status` TEXT DEFAULT 'synced',
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_products_biz_barcode` ON `products` (`business_id`, `barcode`);
CREATE INDEX IF NOT EXISTS `idx_products_uuid` ON `products` (`uuid`);

-- 5. Local Invoices Table
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `uuid` TEXT NOT NULL UNIQUE,
    `cloud_id` INTEGER,
    `business_id` INTEGER NOT NULL,
    `customer_id` INTEGER,
    `customer_uuid` TEXT,
    `invoice_number` TEXT NOT NULL,
    `client_temp_number` TEXT,
    `invoice_date` TEXT NOT NULL,
    `subtotal` REAL DEFAULT 0.00,
    `tax_amount` REAL DEFAULT 0.00,
    `discount_amount` REAL DEFAULT 0.00,
    `grand_total` REAL DEFAULT 0.00,
    `paid_amount` REAL DEFAULT 0.00,
    `payment_status` TEXT DEFAULT 'unpaid',
    `notes` TEXT,
    `sync_status` TEXT DEFAULT 'pending',
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_invoices_biz_num` ON `invoices` (`business_id`, `invoice_number`);
CREATE INDEX IF NOT EXISTS `idx_invoices_uuid` ON `invoices` (`uuid`);

-- 6. Local Payments Table
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `uuid` TEXT NOT NULL UNIQUE,
    `cloud_id` INTEGER,
    `business_id` INTEGER NOT NULL,
    `invoice_id` INTEGER,
    `invoice_uuid` TEXT,
    `customer_id` INTEGER,
    `payment_method` TEXT NOT NULL,
    `amount` REAL NOT NULL,
    `transaction_reference` TEXT,
    `payment_date` TEXT NOT NULL,
    `sync_status` TEXT DEFAULT 'pending',
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_payments_biz` ON `payments` (`business_id`);
CREATE INDEX IF NOT EXISTS `idx_payments_uuid` ON `payments` (`uuid`);

-- 7. Local Expenses Table
CREATE TABLE IF NOT EXISTS `expenses` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `uuid` TEXT NOT NULL UNIQUE,
    `cloud_id` INTEGER,
    `business_id` INTEGER NOT NULL,
    `category` TEXT NOT NULL,
    `amount` REAL NOT NULL,
    `description` TEXT,
    `payment_method` TEXT DEFAULT 'cash',
    `expense_date` TEXT NOT NULL,
    `sync_status` TEXT DEFAULT 'pending',
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_expenses_biz` ON `expenses` (`business_id`);
CREATE INDEX IF NOT EXISTS `idx_expenses_uuid` ON `expenses` (`uuid`);

-- 8. Local Pending Sync Operations Queue
CREATE TABLE IF NOT EXISTS `pending_operations` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `operation_uuid` TEXT NOT NULL UNIQUE,
    `business_id` INTEGER NOT NULL,
    `device_id` TEXT NOT NULL,
    `entity_type` TEXT NOT NULL,
    `entity_uuid` TEXT NOT NULL,
    `operation_type` TEXT NOT NULL,
    `payload_json` TEXT NOT NULL,
    `retry_count` INTEGER DEFAULT 0,
    `status` TEXT DEFAULT 'pending',
    `error_message` TEXT,
    `created_at` TEXT NOT NULL,
    `synced_at` TEXT
);
CREATE INDEX IF NOT EXISTS `idx_pending_ops_biz_status` ON `pending_operations` (`business_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_pending_ops_entity` ON `pending_operations` (`entity_type`, `entity_uuid`);

-- 9. Local Sync Metadata Key-Value Store
CREATE TABLE IF NOT EXISTS `sync_metadata` (
    `key` TEXT PRIMARY KEY,
    `value` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL
);

-- 10. Local Sync Tombstones Table
CREATE TABLE IF NOT EXISTS `sync_tombstones` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `uuid` TEXT NOT NULL UNIQUE,
    `business_id` INTEGER NOT NULL,
    `entity_type` TEXT NOT NULL,
    `entity_uuid` TEXT NOT NULL,
    `deleted_at` TEXT NOT NULL,
    `sync_status` TEXT DEFAULT 'pending'
);
CREATE INDEX IF NOT EXISTS `idx_tombstones_biz` ON `sync_tombstones` (`business_id`, `sync_status`);
