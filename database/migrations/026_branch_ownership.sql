-- 026_branch_ownership.sql
-- Per-branch ownership for bank accounts, lab price overrides, and designers.
-- Also a grant table for cross-branch doctor access (شعبه مالک می‌تواند دسترسی بدهد).

ALTER TABLE bank_accounts ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;
ALTER TABLE lab_price_overrides ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;
ALTER TABLE outsource_rates ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;

-- Attach existing data to the main branch (1)
UPDATE bank_accounts SET branch_id = 1 WHERE branch_id IS NULL;
UPDATE lab_price_overrides SET branch_id = 1 WHERE branch_id IS NULL;
UPDATE outsource_rates SET branch_id = 1 WHERE branch_id IS NULL;

-- Doctors & designers without an explicit branch belong to the main branch (1)
UPDATE users SET branch_id = 1 WHERE role = 'doctor' AND (branch_id IS NULL OR branch_id = '');
UPDATE users SET branch_id = 1 WHERE is_designer = 1 AND (branch_id IS NULL OR branch_id = '');

-- Cross-branch doctor access grants:
-- the OWNING branch grants another branch visibility of a specific doctor's
-- cases/invoices/payments. Ownership itself = users.branch_id of the doctor.
CREATE TABLE IF NOT EXISTS branch_doctor_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    doctor_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (branch_id, doctor_id),
    INDEX (doctor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
