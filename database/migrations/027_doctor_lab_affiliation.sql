-- 027_doctor_lab_affiliation.sql
-- Add lab affiliation to users (like clinic_id): a doctor can belong to a lab
-- as a sub-member, so labs (like clinics) can manage their subordinate doctors.
ALTER TABLE users ADD COLUMN IF NOT EXISTS lab_id INT NULL AFTER clinic_id;
