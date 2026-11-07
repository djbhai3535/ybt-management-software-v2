-- ============================================================
-- DJ CRM Software — Desktop Migration 012: Customer Ledger Sync Parity
-- Safe, Idempotent, Non-Destructive
-- ============================================================

ALTER TABLE customer_ledger ADD COLUMN sync_version INTEGER DEFAULT 1;

CREATE INDEX IF NOT EXISTS idx_customer_ledger_sync_ver ON customer_ledger (business_id, sync_version);
CREATE INDEX IF NOT EXISTS idx_customer_ledger_sync_stat ON customer_ledger (business_id, sync_status);
CREATE INDEX IF NOT EXISTS idx_customer_ledger_sync_uuid ON customer_ledger (business_id, sync_uuid);
