-- ============================================================
-- DJ CRM Software — Desktop Migration 013: Products & Inventory Sync Parity
-- Safe, Idempotent, Additive, Non-Destructive
-- ============================================================

-- 1. Add missing sync columns to products
ALTER TABLE products ADD COLUMN sync_version INTEGER DEFAULT 1;
ALTER TABLE products ADD COLUMN sync_uuid TEXT DEFAULT NULL;

-- 2. Add missing sync columns to stock_movements
ALTER TABLE stock_movements ADD COLUMN uuid TEXT DEFAULT NULL;
ALTER TABLE stock_movements ADD COLUMN idempotency_key TEXT DEFAULT NULL;
ALTER TABLE stock_movements ADD COLUMN sync_status TEXT DEFAULT 'synced';
ALTER TABLE stock_movements ADD COLUMN sync_version INTEGER DEFAULT 1;
ALTER TABLE stock_movements ADD COLUMN movement_type TEXT DEFAULT NULL;

-- 3. Products Sync & Search Enhancements
CREATE INDEX IF NOT EXISTS idx_products_sync_ver ON products (business_id, sync_version);
CREATE INDEX IF NOT EXISTS idx_products_sync_stat ON products (business_id, sync_status);
CREATE INDEX IF NOT EXISTS idx_products_uuid ON products (uuid);
CREATE INDEX IF NOT EXISTS idx_products_barcode ON products (business_id, barcode);
CREATE INDEX IF NOT EXISTS idx_products_sku ON products (business_id, sku);
CREATE INDEX IF NOT EXISTS idx_products_name ON products (business_id, name);
CREATE INDEX IF NOT EXISTS idx_products_category ON products (business_id, category);

-- 4. Stock Movements Sync & Idempotency Enhancements
CREATE INDEX IF NOT EXISTS idx_stock_mov_sync_uuid ON stock_movements (business_id, sync_uuid);
CREATE INDEX IF NOT EXISTS idx_stock_mov_biz_prod ON stock_movements (business_id, product_id);
CREATE INDEX IF NOT EXISTS idx_stock_mov_date ON stock_movements (business_id, created_at);

-- 5. Universal Search Optimization Indexes
CREATE INDEX IF NOT EXISTS idx_customers_search_phone ON customers (business_id, phone);
CREATE INDEX IF NOT EXISTS idx_customers_search_name ON customers (business_id, name);
CREATE INDEX IF NOT EXISTS idx_invoices_search_num ON invoices (business_id, invoice_number);
CREATE INDEX IF NOT EXISTS idx_cust_ledger_search_desc ON customer_ledger (business_id, description);
