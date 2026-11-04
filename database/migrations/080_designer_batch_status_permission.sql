-- 080_designer_batch_status_permission.sql
-- «تغییر وضعیت گروهی» برای طراح‌ها در صفحهٔ کیس‌ها فعال شود.
--
-- نکته: مجوزِ glance در صفحه فقط نمایش/عدم‌نمایش دکمه را کنترل می‌کند؛ اعتبارسنجی
-- واقعی در batch_update_status.php انجام می‌شود (canUserSetStatus؛ طراح فقط
-- وضعیت‌های [۹، ۱۰، ۲۰، ۲۱] را می‌تواند بگذارد) و دسترسی به هر کیس جداگانه
-- با userCanViewCase بررسی می‌شود.
--
-- این کوئری idempotent است: اگر مجوز از قبل باشد یا آرایه خالی باشد، تکراری اضافه نمی‌کند.
UPDATE roles
   SET permissions = CASE
        WHEN permissions IS NULL OR permissions = '' THEN '["batch_update_status"]'
        ELSE REPLACE(permissions, ']', ',"batch_update_status"]')
       END
 WHERE name = 'designer'
   AND (permissions IS NULL OR permissions NOT LIKE '%batch_update_status%');
