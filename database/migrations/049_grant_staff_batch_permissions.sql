-- 049_grant_staff_batch_permissions.sql
-- کارمندان و منشی‌های شعبه نیز بتوانند کیس‌ها را با ستون چک‌باکس انتخاب کنند و
-- از دکمه‌های گروهی (پرینت برچسب و تغییر وضعیت گروهی) استفاده کنند.
-- (اجرای idempotent: اگر مجوز از قبل موجود بود، تغییری ایجاد نمی‌شود.)
UPDATE roles
SET permissions = REPLACE(permissions, '"]', '","batch_print_labels","batch_update_status"]')
WHERE name IN ('staff', 'secretary')
  AND permissions IS NOT NULL
  AND permissions NOT LIKE '%batch_print_labels%';
