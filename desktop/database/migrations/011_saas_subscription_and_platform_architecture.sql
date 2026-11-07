-- DJ CRM Software — Migration 011: SaaS Subscription Engine, Plans & Platform Settings
-- Safe, non-destructive migration preserving all existing businesses and financial records.

CREATE TABLE IF NOT EXISTS subscription_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    badge TEXT DEFAULT NULL,
    description TEXT,
    price_monthly REAL DEFAULT 0.00,
    price_annual REAL DEFAULT 0.00,
    currency TEXT DEFAULT 'PKR',
    max_users INTEGER DEFAULT 1,
    max_customers INTEGER DEFAULT 500,
    max_invoices_per_month INTEGER DEFAULT 100,
    ai_enabled INTEGER DEFAULT 0,
    ai_monthly_limit INTEGER DEFAULT 0,
    offline_sync_enabled INTEGER DEFAULT 1,
    features_json TEXT,
    is_active INTEGER DEFAULT 1,
    sort_order INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS business_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    business_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    billing_cycle TEXT DEFAULT 'monthly',
    status TEXT DEFAULT 'active',
    amount_paid REAL DEFAULT 0.00,
    starts_at TEXT,
    expires_at TEXT,
    payment_method TEXT,
    payment_reference TEXT,
    proof_attachment TEXT,
    approved_by INTEGER DEFAULT NULL,
    approved_at TEXT DEFAULT NULL,
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id),
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
);

CREATE TABLE IF NOT EXISTS platform_settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    description TEXT,
    is_secret INTEGER DEFAULT 0,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Seed Default Configurable Plans
INSERT OR IGNORE INTO subscription_plans (id, slug, name, badge, description, price_monthly, price_annual, currency, max_users, max_customers, max_invoices_per_month, ai_enabled, ai_monthly_limit, offline_sync_enabled, features_json, is_active, sort_order)
VALUES 
(1, 'basic', 'Basic Starter', 'Starter', 'Essential POS, customer khata ledger, and product billing for small shops.', 1500.00, 15000.00, 'PKR', 1, 300, 150, 0, 0, 1, '["POS & Quick Billing","Customer Khata Ledger","Product Catalog","Daily Cash Book","Thermal Receipt Printing","Offline Desktop Mode"]', 1, 1),
(2, 'standard', 'Standard Business', 'Popular', 'Full commercial operations with multi-staff roles, expense tracking, and reports.', 3000.00, 30000.00, 'PKR', 3, 1500, 1000, 0, 0, 1, '["Everything in Basic","Up to 3 Staff Accounts","Role-Based Permissions","Expense Management","Profit & Loss Reports","Customer PDF Statements","WhatsApp Invoicing","Stock Inventory Alerts"]', 1, 2),
(3, 'premium', 'Premium Growth', 'Pro', 'Advanced retail management with Central AI Assistant, multi-device sync, and priority support.', 5500.00, 55000.00, 'PKR', 8, 5000, 5000, 1, 500, 1, '["Everything in Standard","Up to 8 Staff Accounts","AI Digital Manager Access","Smart Financial Insights","Priority Offline-Online Sync","Custom Shop Logo Branding","Data Backup Automation","Priority WhatsApp Support"]', 1, 3),
(4, 'enterprise', 'Enterprise Custom', 'VIP', 'Unlimited platform capabilities for multi-counter retailers, distributors, and wholesalers.', 10000.00, 100000.00, 'PKR', 25, 20000, 20000, 1, 2500, 1, '["Everything in Premium","Up to 25 Users / Multi-Counter","Dedicated Central AI Quotas","Advanced API Integrations","Custom Invoice Layouts","Full Tenant Telemetry","Dedicated SLA Account Manager"]', 1, 4);

-- Seed Central Platform Settings (Owner-controlled)
INSERT OR IGNORE INTO platform_settings (key, value, description, is_secret)
VALUES
('whatsapp_support_number', '923000000000', 'Central WhatsApp Support hotline for all shopkeepers', 0),
('platform_name', 'DJ CRM Software', 'Official platform brand name', 0),
('trial_period_days', '14', 'Default trial period for newly registered shops', 0),
('owner_bank_name', 'Meezan Bank Limited', 'Owner bank name for receiving subscription fees', 0),
('owner_account_title', 'DJ CRM Software Services', 'Owner bank account title for receiving subscription fees', 0),
('owner_account_number', '01020304050607', 'Owner bank account number for receiving subscription fees', 0),
('owner_iban', 'PK00MEZN01020304050607', 'Owner IBAN for receiving subscription fees', 0),
('owner_easypaisa', '03001234567', 'Owner EasyPaisa mobile account for subscriptions', 0),
('owner_jazzcash', '03001234567', 'Owner JazzCash mobile account for subscriptions', 0),
('owner_raast_id', '03001234567', 'Owner Raast ID for instant fee transfers', 0),
('owner_upi_id', 'djcrm@bank', 'Owner UPI ID for international/subcontinent subscription payments', 0),
('central_ai_provider', 'google_gemini', 'Platform central AI engine type', 0),
('central_ai_model', 'gemini-1.5-flash', 'Default model for central AI Digital Manager', 0),
('central_ai_base_url', 'https://generativelanguage.googleapis.com/v1beta', 'Central API endpoint', 0);
