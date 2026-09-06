-- 040_role_permissions.sql
-- طراح و لابراتوارها می‌توانند فاکتورها و پرداخت‌های خودشان را ببینند (فقط مشاهده، بدون ویرایش).
-- (ادمین‌ها و کارمندان مالی همچنان همه را می‌بینند.)
UPDATE roles SET permissions = '["view_assigned_cases","upload_design_files","edit_case_status","view_own_invoices","view_own_payments"]' WHERE name = 'designer';
UPDATE roles SET permissions = '["view_assigned_cases","view_case_files","view_own_invoices","view_own_payments"]' WHERE name IN ('outsource_lab','customer_lab','partner_lab','lab');
