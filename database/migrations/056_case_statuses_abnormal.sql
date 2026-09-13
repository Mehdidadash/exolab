-- 056_case_statuses_abnormal.sql
-- وضعیت‌های «غیرعادی»: تغییر وضعیت کیس به این‌ها به کاربران مرتبط اعلان می‌فرستد.
-- (علاوه بر وضعیت‌هایی که sort_order آنها بیشتر از ۳۰ است.)
ALTER TABLE case_statuses
    ADD COLUMN IF NOT EXISTS is_abnormal TINYINT(1) NOT NULL DEFAULT 0 AFTER color;
