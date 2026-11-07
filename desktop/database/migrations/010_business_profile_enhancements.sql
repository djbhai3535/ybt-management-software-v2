-- Migration 010: Business Profile Enhancements & Logo Storage
-- Safe, non-destructive schema update for DJ CRM Software

ALTER TABLE businesses ADD COLUMN logo_url TEXT DEFAULT NULL;
ALTER TABLE businesses ADD COLUMN category TEXT DEFAULT NULL;
