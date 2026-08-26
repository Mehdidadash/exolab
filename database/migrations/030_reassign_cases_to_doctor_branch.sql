-- 030_reassign_cases_to_doctor_branch.sql
-- Fix legacy cases: a case should be owned by the branch of its doctor
-- (doctors belong to a branch → their cases follow). Only touch cases that
-- are NOT shared/outsourced (source_branch_id IS NULL) and whose current
-- branch_id differs from their doctor's branch.
UPDATE cases c
JOIN users u ON c.doctor_id = u.id
SET c.branch_id = u.branch_id
WHERE u.role = 'doctor'
  AND u.branch_id IS NOT NULL
  AND c.source_branch_id IS NULL
  AND c.branch_id <> u.branch_id;
