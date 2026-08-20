-- 021_side_outsourcing.sql
-- Side outsourcing: a normal case (we do the main work) may have PART of the work
-- performed by another lab (partner/outsource/customer) that we owe money to.
-- Example: we fabricate 5 zirconia crowns, but give 1 jaw to a partner lab to
-- 3D-print the cast. We are therefore indebted to that lab for the print cost.
ALTER TABLE cases ADD COLUMN IF NOT EXISTS outsourced_lab_id INT NULL AFTER lab_id;
ALTER TABLE cases ADD COLUMN IF NOT EXISTS outsourced_service_id INT NULL AFTER outsourced_lab_id;
ALTER TABLE cases ADD COLUMN IF NOT EXISTS outsourced_qty INT NOT NULL DEFAULT 0 AFTER outsourced_service_id;
