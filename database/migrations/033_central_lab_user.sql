-- 033_central_lab_user.sql
-- Create a lab user representing the CENTRAL branch (شعبه اصلی، branch 1) so it
-- can be selected as the partner lab in the case form (lab_in / lab_out / side
-- outsourcing) by every branch and by the root admin.
-- Default password for this account: CentralLab@1405 (change it after first login).
INSERT INTO users (username, password_hash, full_name, role, branch_id, active, created_at, updated_at)
SELECT 'central_lab', '$2y$10$bHvgL8OXZ.bonANyUOahMuIeH1TJoNV7EgatqIfEAZ1GOK8kr5ama', 'لابراتوار مرکزی', 'outsource_lab', 1, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM users
    WHERE role IN ('outsource_lab','customer_lab','partner_lab','lab')
      AND branch_id = 1 AND active = 1 AND full_name = 'لابراتوار مرکزی'
);
