-- 070_fix_branch_receivable_charset.sql
-- رفع اشکال کاراکترست جداول فاکتور طلب از شعبه.
--
-- علت: مهاجرت 031 تنها مهاجرتی بود که ENGINE/CHARSET را مشخص نکرده بود، پس
-- MariaDB/MySQL جداول را با کاراکترست پیش‌فرض سرور (latin1) ساخت. با وجود
-- SET NAMES utf8mb4، متن فارسی هنگام درج به latin1 تبدیل شد و هر کاراکتر
-- غیرقابل‌تبدیل به «?» تبدیل شد (بایت 0x3F روی دیسک).
--
-- ⚠️ داده‌های ازدست‌رفته (کاراکترهای «?») با هیچ تبدیل کاراکترستی برنمی‌گردند؛
--    این مهاجرت فقط ساختار جدول را درست می‌کند تا مشکل تکرار نشود.
--
-- نکته: MODIFY روی ستون‌ها لازم نیست؛ CONVERT خودش collation همه‌ی ستون‌های
--       متنی را به کاراکترست مقصد تغییر می‌دهد.

ALTER TABLE branch_receivables          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE branch_receivable_items     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE branch_receivable_payments  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
