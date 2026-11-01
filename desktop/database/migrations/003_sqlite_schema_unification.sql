-- ============================================================
-- YBT Management Software — SQLite Schema Unification & Mirroring
-- Migration: 003_sqlite_schema_unification.sql
-- Description: Brings SQLite schema into full parity with MySQL schema
-- Idempotent & Non-Destructive: Safe for existing and fresh databases
-- ============================================================

-- 1. Roles Table
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `name` TEXT NOT NULL,
    `slug` TEXT NOT NULL,
    `description` TEXT,
    `is_system` INTEGER DEFAULT 0,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS `idx_roles_biz_slug` ON `roles` (`business_id`, `slug`);

-- 2. Permissions Table
CREATE TABLE IF NOT EXISTS `permissions` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `key` TEXT NOT NULL UNIQUE,
    `group_name` TEXT NOT NULL,
    `label` TEXT NOT NULL,
    `description` TEXT,
    `is_sensitive` INTEGER DEFAULT 0
);
CREATE UNIQUE INDEX IF NOT EXISTS `idx_permissions_key` ON `permissions` (`key`);

-- 3. Role Permissions Table
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id` INTEGER NOT NULL,
    `permission_id` INTEGER NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
);

-- 4. Staff Invitations Table
CREATE TABLE IF NOT EXISTS `staff_invitations` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `role_id` INTEGER NOT NULL,
    `email` TEXT NOT NULL,
    `name` TEXT,
    `token_hash` TEXT NOT NULL UNIQUE,
    `invited_by` INTEGER NOT NULL,
    `status` TEXT DEFAULT 'pending',
    `expires_at` TEXT NOT NULL,
    `accepted_at` TEXT,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`invited_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS `idx_staff_inv_token` ON `staff_invitations` (`token_hash`);
CREATE INDEX IF NOT EXISTS `idx_staff_inv_biz` ON `staff_invitations` (`business_id`, `status`);

-- 5. Payment Allocations Table
CREATE TABLE IF NOT EXISTS `payment_allocations` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `payment_id` INTEGER NOT NULL,
    `invoice_id` INTEGER NOT NULL,
    `amount` REAL NOT NULL,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (`payment_id`, `invoice_id`),
    FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS `idx_pa_pay_inv` ON `payment_allocations` (`payment_id`, `invoice_id`);

-- 6. Quotations Table
CREATE TABLE IF NOT EXISTS `quotations` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `quotation_number` TEXT NOT NULL,
    `customer_id` INTEGER NOT NULL,
    `quotation_date` TEXT NOT NULL,
    `validity_date` TEXT,
    `subtotal` REAL DEFAULT 0.00,
    `tax_total` REAL DEFAULT 0.00,
    `discount_total` REAL DEFAULT 0.00,
    `grand_total` REAL DEFAULT 0.00,
    `notes` TEXT,
    `terms` TEXT,
    `status` TEXT DEFAULT 'draft',
    `converted_invoice_id` INTEGER,
    `business_snapshot` TEXT,
    `customer_snapshot` TEXT,
    `is_demo` INTEGER DEFAULT 0,
    `created_by` INTEGER,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (`business_id`, `quotation_number`),
    FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
);
CREATE INDEX IF NOT EXISTS `idx_quotations_cust` ON `quotations` (`customer_id`);
CREATE INDEX IF NOT EXISTS `idx_quotations_status` ON `quotations` (`status`);

-- 7. Quotation Items Table
CREATE TABLE IF NOT EXISTS `quotation_items` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `quotation_id` INTEGER NOT NULL,
    `product_id` INTEGER,
    `description` TEXT NOT NULL,
    `quantity` REAL DEFAULT 1.000,
    `unit` TEXT DEFAULT 'piece',
    `unit_price` REAL DEFAULT 0.00,
    `discount_percent` REAL DEFAULT 0.00,
    `discount_amount` REAL DEFAULT 0.00,
    `tax_rate` REAL DEFAULT 0.00,
    `tax_amount` REAL DEFAULT 0.00,
    `hsn_sac` TEXT,
    `line_total` REAL DEFAULT 0.00,
    `sort_order` INTEGER DEFAULT 0,
    FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS `idx_quotation_items_qid` ON `quotation_items` (`quotation_id`);

-- 8. Expense Categories Table
CREATE TABLE IF NOT EXISTS `expense_categories` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `name` TEXT NOT NULL,
    `color` TEXT DEFAULT '#64748B',
    `is_demo` INTEGER DEFAULT 0,
    UNIQUE (`business_id`, `name`),
    FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
);

-- 9. Invoice Items Table
CREATE TABLE IF NOT EXISTS `invoice_items` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `invoice_id` INTEGER NOT NULL,
    `product_id` INTEGER,
    `description` TEXT NOT NULL,
    `quantity` REAL DEFAULT 1.000,
    `unit` TEXT DEFAULT 'piece',
    `unit_price` REAL DEFAULT 0.00,
    `discount_percent` REAL DEFAULT 0.00,
    `discount_amount` REAL DEFAULT 0.00,
    `tax_rate` REAL DEFAULT 0.00,
    `tax_amount` REAL DEFAULT 0.00,
    `hsn_sac` TEXT,
    `line_total` REAL DEFAULT 0.00,
    `sort_order` INTEGER DEFAULT 0,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS `idx_invoice_items_iid` ON `invoice_items` (`invoice_id`);

-- 10. Backup Records Table
CREATE TABLE IF NOT EXISTS `backup_records` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `type` TEXT DEFAULT 'full',
    `filename` TEXT NOT NULL,
    `file_path` TEXT NOT NULL,
    `size_bytes` INTEGER DEFAULT 0,
    `status` TEXT DEFAULT 'completed',
    `notes` TEXT,
    `created_by` INTEGER,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
);

-- 11. Email Verifications Table
CREATE TABLE IF NOT EXISTS `email_verifications` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `business_id` INTEGER NOT NULL,
    `email` TEXT NOT NULL,
    `token_hash` TEXT NOT NULL UNIQUE,
    `token_type` TEXT DEFAULT 'register',
    `expires_at` TEXT NOT NULL,
    `used_at` TEXT,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS `idx_email_verif_token` ON `email_verifications` (`token_hash`);

-- 12. Password Resets Table
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `email` TEXT NOT NULL,
    `token_hash` TEXT NOT NULL UNIQUE,
    `expires_at` TEXT NOT NULL,
    `used_at` TEXT,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS `idx_pwd_reset_token` ON `password_resets` (`token_hash`);

-- 13. AI Provider Configs Table
CREATE TABLE IF NOT EXISTS `ai_provider_configs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `provider_type` TEXT NOT NULL,
    `display_name` TEXT NOT NULL,
    `base_url` TEXT,
    `api_key_encrypted` TEXT,
    `model_id` TEXT,
    `organization_id` TEXT,
    `custom_headers` TEXT,
    `connect_timeout` INTEGER DEFAULT 10,
    `request_timeout` INTEGER DEFAULT 60,
    `max_tokens` INTEGER DEFAULT 4096,
    `temperature` REAL DEFAULT 0.70,
    `is_active` INTEGER DEFAULT 0,
    `is_default` INTEGER DEFAULT 0,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
);

-- AI Conversations Table
CREATE TABLE IF NOT EXISTS `ai_conversations` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `title` TEXT DEFAULT 'New Conversation',
    `provider_config_id` INTEGER,
    `is_archived` INTEGER DEFAULT 0,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS `idx_ai_conv_user` ON `ai_conversations` (`user_id`);

-- AI Messages Table
CREATE TABLE IF NOT EXISTS `ai_messages` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `conversation_id` INTEGER NOT NULL,
    `role` TEXT NOT NULL,
    `content` TEXT,
    `tool_calls` TEXT,
    `tool_results` TEXT,
    `metadata` TEXT,
    `tokens_used` INTEGER,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS `idx_ai_msg_conv` ON `ai_messages` (`conversation_id`);

-- ============================================================
-- 14. Column Additions for Full Parity with MySQL Schema
-- ============================================================

-- Products table column parity
ALTER TABLE `products` ADD COLUMN `status` TEXT DEFAULT 'active';
ALTER TABLE `products` ADD COLUMN `description` TEXT;

-- Payments table column parity
ALTER TABLE `payments` ADD COLUMN `payment_number` TEXT;
ALTER TABLE `payments` ADD COLUMN `method` TEXT DEFAULT 'cash';
ALTER TABLE `payments` ADD COLUMN `reference` TEXT;
ALTER TABLE `payments` ADD COLUMN `notes` TEXT;
ALTER TABLE `payments` ADD COLUMN `status` TEXT DEFAULT 'completed';
ALTER TABLE `payments` ADD COLUMN `created_by` INTEGER;

-- Customers table column parity
ALTER TABLE `customers` ADD COLUMN `billing_address` TEXT;
ALTER TABLE `customers` ADD COLUMN `billing_city` TEXT;
ALTER TABLE `customers` ADD COLUMN `billing_state` TEXT;
ALTER TABLE `customers` ADD COLUMN `billing_pincode` TEXT;
ALTER TABLE `customers` ADD COLUMN `shipping_address` TEXT;
ALTER TABLE `customers` ADD COLUMN `shipping_city` TEXT;
ALTER TABLE `customers` ADD COLUMN `shipping_state` TEXT;
ALTER TABLE `customers` ADD COLUMN `shipping_pincode` TEXT;
ALTER TABLE `customers` ADD COLUMN `status` TEXT DEFAULT 'active';

-- Invoices table column parity
ALTER TABLE `invoices` ADD COLUMN `place_of_supply` TEXT DEFAULT 'State';
ALTER TABLE `invoices` ADD COLUMN `cgst_total` REAL DEFAULT 0.00;
ALTER TABLE `invoices` ADD COLUMN `sgst_total` REAL DEFAULT 0.00;
ALTER TABLE `invoices` ADD COLUMN `igst_total` REAL DEFAULT 0.00;
ALTER TABLE `invoices` ADD COLUMN `tax_total` REAL DEFAULT 0.00;
ALTER TABLE `invoices` ADD COLUMN `terms` TEXT;
ALTER TABLE `invoices` ADD COLUMN `billing_address` TEXT;
ALTER TABLE `invoices` ADD COLUMN `shipping_address` TEXT;
ALTER TABLE `invoices` ADD COLUMN `created_by` INTEGER;

-- Tasks table column parity
ALTER TABLE `tasks` ADD COLUMN `assigned_to` INTEGER;
ALTER TABLE `tasks` ADD COLUMN `created_by` INTEGER;

-- Businesses table column parity
ALTER TABLE `businesses` ADD COLUMN `email` TEXT;
ALTER TABLE `businesses` ADD COLUMN `owner_name` TEXT;
ALTER TABLE `businesses` ADD COLUMN `status` TEXT DEFAULT 'active';
ALTER TABLE `businesses` ADD COLUMN `onboarding_step` INTEGER DEFAULT 3;
ALTER TABLE `businesses` ADD COLUMN `bank_name` TEXT;
ALTER TABLE `businesses` ADD COLUMN `account_holder` TEXT;
ALTER TABLE `businesses` ADD COLUMN `account_number` TEXT;
ALTER TABLE `businesses` ADD COLUMN `ifsc_code` TEXT;
ALTER TABLE `businesses` ADD COLUMN `bank_branch` TEXT;
ALTER TABLE `businesses` ADD COLUMN `upi_id` TEXT;
ALTER TABLE `businesses` ADD COLUMN `pan` TEXT;
ALTER TABLE `businesses` ADD COLUMN `website` TEXT;
ALTER TABLE `businesses` ADD COLUMN `tagline` TEXT;
ALTER TABLE `businesses` ADD COLUMN `address_line1` TEXT;
ALTER TABLE `businesses` ADD COLUMN `address_line2` TEXT;
ALTER TABLE `businesses` ADD COLUMN `landmark` TEXT;
ALTER TABLE `businesses` ADD COLUMN `postal_code` TEXT;
ALTER TABLE `businesses` ADD COLUMN `invoice_next_number` INTEGER DEFAULT 1001;
ALTER TABLE `businesses` ADD COLUMN `quotation_prefix` TEXT DEFAULT 'QT-';
ALTER TABLE `businesses` ADD COLUMN `quotation_next_number` INTEGER DEFAULT 1001;
ALTER TABLE `businesses` ADD COLUMN `default_due_days` INTEGER DEFAULT 15;
ALTER TABLE `businesses` ADD COLUMN `default_notes` TEXT;
ALTER TABLE `businesses` ADD COLUMN `default_terms` TEXT;

-- Expenses table column parity
ALTER TABLE `expenses` ADD COLUMN `category_id` INTEGER;
ALTER TABLE `expenses` ADD COLUMN `vendor` TEXT;
ALTER TABLE `expenses` ADD COLUMN `method` TEXT DEFAULT 'cash';
ALTER TABLE `expenses` ADD COLUMN `reference` TEXT;
ALTER TABLE `expenses` ADD COLUMN `created_by` INTEGER;

-- Users table column parity
ALTER TABLE `users` ADD COLUMN `email_verified_at` TEXT;
ALTER TABLE `users` ADD COLUMN `verification_status` TEXT DEFAULT 'verified';
ALTER TABLE `users` ADD COLUMN `onboarding_completed` INTEGER DEFAULT 1;

-- ============================================================
-- 15. System Seed Data
-- ============================================================

-- Seed System Permissions
INSERT OR IGNORE INTO `permissions` (`id`, `key`, `group_name`, `label`, `description`, `is_sensitive`) VALUES
(1, 'dashboard.view', 'Dashboard', 'View Dashboard', 'Access dashboard analytics', 0),
(2, 'customers.view', 'Customers', 'View Customers', 'View customer list and details', 0),
(3, 'customers.create', 'Customers', 'Create Customers', 'Add new customers', 0),
(4, 'customers.edit', 'Customers', 'Edit Customers', 'Modify customer details', 0),
(5, 'customers.archive', 'Customers', 'Archive Customers', 'Archive customer records', 0),
(6, 'customers.export', 'Customers', 'Export Customers', 'Export customer data', 0),
(7, 'leads.view', 'Leads', 'View Leads', 'View leads and pipeline', 0),
(8, 'leads.create', 'Leads', 'Create Leads', 'Add new leads', 0),
(9, 'leads.edit', 'Leads', 'Edit Leads', 'Modify lead details', 0),
(10, 'leads.archive', 'Leads', 'Archive Leads', 'Archive lead records', 0),
(11, 'leads.change_stage', 'Leads', 'Change Lead Stage', 'Move leads between pipeline stages', 0),
(12, 'products.view', 'Products', 'View Products', 'View products and services', 0),
(13, 'products.create', 'Products', 'Create Products', 'Add new products/services', 0),
(14, 'products.edit', 'Products', 'Edit Products', 'Modify product details', 0),
(15, 'products.archive', 'Products', 'Archive Products', 'Archive product records', 0),
(16, 'products.edit_prices', 'Products', 'Edit Prices', 'Modify product pricing', 1),
(17, 'tasks.view', 'Tasks', 'View Tasks', 'View task list', 0),
(18, 'tasks.create', 'Tasks', 'Create Tasks', 'Add new tasks', 0),
(19, 'tasks.edit', 'Tasks', 'Edit Tasks', 'Modify task details', 0),
(20, 'tasks.assign', 'Tasks', 'Assign Tasks', 'Assign tasks to managers', 0),
(21, 'tasks.complete', 'Tasks', 'Complete Tasks', 'Mark tasks as completed', 0),
(22, 'quotations.view', 'Quotations', 'View Quotations', 'View quotation list', 0),
(23, 'quotations.create', 'Quotations', 'Create Quotations', 'Create new quotations', 0),
(24, 'quotations.edit', 'Quotations', 'Edit Quotations', 'Modify quotation details', 0),
(25, 'quotations.approve', 'Quotations', 'Approve Quotations', 'Approve quotations', 1),
(26, 'quotations.archive', 'Quotations', 'Archive Quotations', 'Archive quotation records', 0),
(27, 'invoices.view', 'Invoices', 'View Invoices', 'View invoice list and details', 0),
(28, 'invoices.create', 'Invoices', 'Create Invoices', 'Create draft invoices', 0),
(29, 'invoices.issue', 'Invoices', 'Issue Invoices', 'Issue invoices to customers', 1),
(30, 'invoices.void', 'Invoices', 'Void Invoices', 'Void/cancel invoices', 1),
(31, 'invoices.print', 'Invoices', 'Print Invoices', 'Print invoices', 0),
(32, 'invoices.export', 'Invoices', 'Export Invoices', 'Export invoice data', 0),
(33, 'payments.view', 'Payments', 'View Payments', 'View payment records', 0),
(34, 'payments.record', 'Payments', 'Record Payments', 'Record new payments', 1),
(35, 'payments.allocate', 'Payments', 'Allocate Payments', 'Allocate payments to invoices', 1),
(36, 'payments.reverse', 'Payments', 'Reverse Payments', 'Reverse payment records', 1),
(37, 'expenses.view', 'Expenses', 'View Expenses', 'View expense records', 0),
(38, 'expenses.create', 'Expenses', 'Create Expenses', 'Record new expenses', 0),
(39, 'expenses.edit', 'Expenses', 'Edit Expenses', 'Modify expense records', 0),
(40, 'expenses.archive', 'Expenses', 'Archive Expenses', 'Archive expense records', 0),
(41, 'reports.view', 'Reports', 'View Reports', 'Access report section', 0),
(42, 'reports.export', 'Reports', 'Export Reports', 'Export report data', 0),
(43, 'ai.access', 'AI', 'Access AI', 'Use AI assistant', 0),
(44, 'ai.search', 'AI', 'AI Search', 'Use AI to search records', 0),
(45, 'ai.financial', 'AI', 'AI Financial Data', 'Allow AI to access financial data', 1),
(46, 'ai.propose_actions', 'AI', 'AI Action Proposals', 'Allow AI to prepare action proposals', 1),
(47, 'settings.access', 'Settings', 'Access Settings', 'View settings panel', 0),
(48, 'settings.edit', 'Settings', 'Edit Settings', 'Modify settings', 1),
(49, 'managers.view', 'Managers', 'View Managers', 'View manager accounts', 0),
(50, 'managers.manage', 'Managers', 'Manage Managers', 'Create/edit manager accounts', 1),
(51, 'backup.create', 'Backup', 'Create Backup', 'Create data backups', 1),
(52, 'backup.download', 'Backup', 'Download Backup', 'Download backup files', 1),
(53, 'backup.restore', 'Backup', 'Restore Backup', 'Restore from backup', 1),
(54, 'backup.reset', 'Backup', 'Reset Data', 'Reset/clear data', 1);

-- Ensure default business exists so foreign keys are valid
INSERT OR IGNORE INTO `businesses` (`id`, `tenant_uuid`, `name`, `sync_status`) VALUES
(1, 'tenant-demo-ybt-001', 'YBT Technologies (Demo)', 'synced');

-- Seed System Roles for default business
INSERT OR IGNORE INTO `roles` (`id`, `business_id`, `name`, `slug`, `description`, `is_system`) VALUES
(1, 1, 'Business Owner', 'owner', 'Full administrative authority and mutation approval', 1),
(2, 1, 'Operations Manager', 'manager', 'Day-to-day operations, invoicing, client management', 1),
(3, 1, 'Shop Staff', 'staff', 'Point of sale, customer queries, and repair intake', 1);

-- Seed Default Expense Categories
INSERT OR IGNORE INTO `expense_categories` (`id`, `business_id`, `name`, `color`) VALUES
(1, 1, 'Office & Rent', '#0284c7'),
(2, 1, 'Cloud & Hosting Infrastructure', '#8b5cf6'),
(3, 1, 'Software Subscriptions & Tools', '#10b981'),
(4, 1, 'Travel & Client Meetings', '#f59e0b'),
(5, 1, 'General Operations & Supplies', '#64748b');

-- Seed Manager Default Role Permissions
INSERT OR IGNORE INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(2, 1), (2, 2), (2, 3), (2, 4), (2, 12), (2, 13), (2, 14), (2, 27), (2, 28), (2, 31), (2, 33), (2, 34), (2, 37), (2, 38);
