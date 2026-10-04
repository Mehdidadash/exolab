-- 068_case_files_rel_path.sql
-- «آپلود پوشه»: مسیر نسبیِ فایل داخل پوشه‌ای که کاربر انتخاب کرده است.
-- مثلاً اگر کاربر پوشهٔ «اسکن فک بالا» را آپلود کند و فایل در
-- «اسکن فک بالا/تصاویر/a.png» باشد، rel_path = 'اسکن فک بالا/تصاویر/a.png'.
-- برای فایل‌های تکی (آپلود معمولی) NULL می‌ماند.
ALTER TABLE case_files ADD COLUMN IF NOT EXISTS rel_path VARCHAR(500) NULL AFTER original_name;
