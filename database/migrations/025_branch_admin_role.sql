-- 025_branch_admin_role.sql
-- Role for a branch manager (مدیر شعبه): full permissions, but data scoped to their branch_id.
INSERT INTO roles (name, label, permissions, created_at, updated_at)
SELECT 'branch_admin', 'مدیر شعبه', '["*"]', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'branch_admin');

-- Add branch_admin to the users.role ENUM (so such users can exist)
ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','doctor','staff','secretary','designer','technician','operator','powder','courier','finance','outsource_lab','customer_lab','partner_lab','clinic','branch_admin') NOT NULL DEFAULT 'staff';

