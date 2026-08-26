-- 024_branches.sql
-- Multi-branch / hierarchical lab system.

-- Branch table (شعبه)
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) NULL,
    owner_user_id INT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (parent_id),
    INDEX (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Users: branch membership. NULL = global / root admin.
ALTER TABLE users ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER role;

-- Cases: owner branch + source branch (partner branch for cross-branch outsourcing)
ALTER TABLE cases ADD COLUMN IF NOT EXISTS branch_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE cases ADD COLUMN IF NOT EXISTS source_branch_id INT NULL AFTER branch_id;

-- Per-branch price lists
ALTER TABLE site_prices ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;
ALTER TABLE doctor_price_overrides ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER doctor_id;

-- Financial records belong to a branch
ALTER TABLE doctor_invoices ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;
ALTER TABLE doctor_payments ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;
ALTER TABLE outsource_invoices ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;
ALTER TABLE designer_invoices ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;

-- Standalone uploads belong to a branch
ALTER TABLE user_uploads ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER user_id;

-- Seed the root branch (شعبه اصلی) and attach all existing data to it.
INSERT INTO branches (id, name, code, owner_user_id, active, created_at, updated_at)
SELECT 1, 'شعبه اصلی (اسلامشهر)', 'MAIN', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM branches WHERE id = 1);

UPDATE cases SET branch_id = 1 WHERE branch_id IS NULL OR branch_id = 0;
UPDATE site_prices SET branch_id = 1 WHERE branch_id IS NULL;
UPDATE doctor_price_overrides SET branch_id = 1 WHERE branch_id IS NULL;
UPDATE doctor_invoices SET branch_id = 1 WHERE branch_id IS NULL;
UPDATE doctor_payments SET branch_id = 1 WHERE branch_id IS NULL;
UPDATE outsource_invoices SET branch_id = 1 WHERE branch_id IS NULL;
UPDATE designer_invoices SET branch_id = 1 WHERE branch_id IS NULL;
UPDATE user_uploads SET branch_id = 1 WHERE branch_id IS NULL;
