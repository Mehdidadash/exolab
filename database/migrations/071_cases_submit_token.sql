-- 071_cases_submit_token.sql
-- جلوگیری از «ایجاد کیس تکراری» وقتی اینترنت وسط ثبت‌نام قطع و وصل می‌شود.
--
-- سناریوی باگ:
--   ۱) کاربر فرمِ کیس جدید را پر می‌کند و «ذخیره» را می‌زند.
--   ۲) سرور کیس را INSERT می‌کند ولی پاسخ به مرورگر نمی‌رسد (قطع شدن اینترنت).
--   ۳) مرورگر/کاربر دوباره «ذخیره» را می‌زند → سرور یک کیسِ **دوم** می‌سازد
--      که فایل‌ها و ادامهٔ فرایند روی کیسِ اول مانده است ⇒ دو کیس ناقص/تکراری.
--
-- راه‌حل: «توکنِ یکتای ارسال» (idempotency key).
--   • مرورگر هنگام **باز کردن** فرمِ کیس جدید یک توکنِ تصادفی می‌سازد و همراهِ
--     درخواست می‌فرستد (و در صورت خطای شبکه، همان توکن را دوباره می‌فرستد).
--   • سرور پیش از INSERT بررسی می‌کند که این توکن قبلاً ثبت نشده باشد؛ اگر شده
--     باشد، همان کیسِ ساخته‌شده برگردانده می‌شود (نه کیسِ جدید).
--   • UNIQUE index تضمین می‌کند حتی در درخواست‌های هم‌زمان هم دو کیس ساخته نشود.
--
-- ⚠️ ستون NULL-پذیر است تا کیس‌های قدیمی و مسیرهای برنامه‌ای (بدون توکن) دست‌نخورده بمانند.
--    ایندکسِ یکتا در MySQL چند مقدار NULL را مجاز می‌داند، پس محدودیتی ایجاد نمی‌کند.
ALTER TABLE cases ADD COLUMN IF NOT EXISTS submit_token VARCHAR(64) NULL AFTER source_branch_id;

-- ایندکس یکتا (اگر از قبل نباشد). MySQL از «ADD UNIQUE INDEX IF NOT EXISTS» پشتیبانی
-- نمی‌کند، پس شرطِ وجود را از information_schema می‌خوانیم.
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cases'
      AND INDEX_NAME = 'uniq_cases_submit_token'
);
SET @sql_idx := IF(@has_idx = 0,
    'ALTER TABLE cases ADD UNIQUE INDEX uniq_cases_submit_token (submit_token)',
    'SELECT 1');
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;
