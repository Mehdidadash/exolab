-- 038_case_files_uploader.sql
-- ثبت آپلودکننده برای فایل‌های کیس (برای نمایش اسم/حجم/تاریخ آپلود)
ALTER TABLE case_files ADD COLUMN IF NOT EXISTS uploader_id INT NULL AFTER size;
