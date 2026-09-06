-- 039_case_files_file_type.sql
-- نوع فایل آپلودی (اسکن خام / طراحی نهایی / عکس بیمار / HTML طراحی / سایر)
ALTER TABLE case_files ADD COLUMN IF NOT EXISTS file_type VARCHAR(30) NULL AFTER description;
