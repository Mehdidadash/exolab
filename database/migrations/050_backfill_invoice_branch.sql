-- Migration 050: backfill branch on doctor_invoices without a branch.
-- Faktoor-haye ghadimi ke ghabl az ezafe shodan-e branch model bi branch sakhte
-- shodand ra be shobe-ye doctor-e khod nesbat midahim ta dar amare modir shobe hesab shavand.
UPDATE doctor_invoices i
JOIN users u ON u.id = i.doctor_id
SET i.branch_id = u.branch_id
WHERE i.branch_id IS NULL AND u.branch_id IS NOT NULL;
