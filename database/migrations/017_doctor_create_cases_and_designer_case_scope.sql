-- 017_doctor_create_cases_and_designer_case_scope.sql
-- Doctors can create their own cases via a restricted case form.
-- Designers see only the cases they are assigned to (not all cases),
-- but they can access the shared uploaded files in the uploads section.
UPDATE roles
SET permissions = JSON_ARRAY('view_own_cases', 'view_own_invoices', 'view_own_payments', 'view_case_files', 'create_cases')
WHERE name = 'doctor';

UPDATE roles
SET permissions = JSON_ARRAY('view_assigned_cases', 'upload_design_files', 'edit_case_status')
WHERE name = 'designer';
