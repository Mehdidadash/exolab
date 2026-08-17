-- 015_designer_case_scope.sql
-- Designers should only see cases where they are assigned as the designer,
-- and be able to view profiles of doctors/clinics linked to those cases.
UPDATE roles
SET permissions = JSON_ARRAY('view_assigned_cases', 'upload_design_files', 'edit_case_status')
WHERE name = 'designer';
