-- 067_fix_default_designer_flag.sql
-- مشکل: «طراح پیش‌فرض» در فرم کیس انتخاب نمی‌شد (گزینهٔ «بدون طراح» می‌ماند).
--
-- علت: فرمِ کاربر (user_form.php/save_user.php) پرچم `is_default_designer` را روی
-- **هر** کاربری ست می‌کرد — حتی کلینیک/لابراتوار. در نتیجه:
--   ۱) پرچم از طراحِ واقعی برداشته می‌شد،
--   ۲) `getDefaultDesigner()` (که `is_designer = 1` هم می‌خواهد) خالی برمی‌گشت.
-- کد اصلاح شد (پرچم فقط برای کاربرانِ `is_designer = 1` و پاک‌سازی از بقیه).
-- این مایگریشن داده را یک‌بار درست می‌کند.

-- ۱) پرچم را از هر کاربرِ غیرطراح بردار
UPDATE users SET is_default_designer = 0 WHERE is_designer = 0;

-- ۲) اگر هیچ طراحِ پیش‌فرضی باقی نمانده، اولین طراحِ فعال را پیش‌فرض کن
UPDATE users
SET is_default_designer = 1
WHERE is_designer = 1
  AND active = 1
  AND id = (
      SELECT id FROM (
          SELECT id FROM users
          WHERE is_designer = 1 AND active = 1
          ORDER BY (role = 'designer') DESC, id ASC
          LIMIT 1
      ) AS pick
  )
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE is_default_designer = 1 LIMIT 1) AS already);
