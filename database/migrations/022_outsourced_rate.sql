-- 022_outsourced_rate.sql
-- Per-case side-outsourcing rate (manual / auto-filled from outsource_rates).
-- NULL means "fall back to the outsource_rates table lookup at billing time".
ALTER TABLE cases ADD COLUMN IF NOT EXISTS outsourced_rate DECIMAL(15,2) NULL AFTER outsourced_qty;
