-- 069_user_uploads_rel_path.sql
-- «آپلود پوشه» در صفحهٔ uploads.php: مسیر نسبیِ فایل داخل پوشه‌ای که کاربر انتخاب کرده.
-- برای فایل‌های تکی (آپلود معمولی) NULL می‌ماند.
-- مثل case_files.rel_path فقط برای نمایش/گروه‌بندی است و هرگز به فایل‌سیستم نمی‌رسد.
ALTER TABLE user_uploads ADD COLUMN IF NOT EXISTS rel_path VARCHAR(500) NULL AFTER original_name;
