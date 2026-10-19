-- 074_design_fee_to_unit_rate.sql
-- تبدیل معنای ستون `cases.design_fee` از «جمع کل هزینهٔ طراحی» به «فی (نرخ هر واحد) طراحی».
--
-- پیش از این تغییر: design_fee = فی طراحی × تعداد (جمع کل).
-- از این پس:          design_fee = نرخ هر واحد طراحی (مثل `unit_price`)،
--                     و جمع کل در هر جای لازم با `design_fee * quantity` محاسبه می‌شود.
--
-- ⚠️ قابل بازگشت نیست مگر از بکاپ — قبل از اجرا بکاپ بگیرید.
-- migrate.php هر فایل را فقط یک‌بار اجرا می‌کند (`_migrations`)، پس UPDATE فقط یک‌بار اعمال می‌شود.
--
-- ⛔ هشدارِ ایمنی (باگِ رفع‌شده): این UPDATE «تقسیم» است و idempotent نیست. اگر دو بار
--    اجرا شود، design_fee دوباره تقسیم می‌شود (نرخ طراحی بی‌معنی می‌شود). پس:
--      ۱) ستونِ نشانه `design_fee_is_unit` به‌عنوان نگهبان اضافه می‌شود.
--      ۲) شرطِ `design_fee_is_unit = 0` فقط ردیف‌های تبدیل‌نشده را هدف می‌گیرد.
--      ۳) در `migrate.php` برای این فایل چکِ «Already present» ثبت شد تا اگر اسکیما
--         از قبل ستون نشانه را داشت، فایل اصلاً اجرا نشود.
--
-- 🔧 اصلاح کیس ۱۳۶۸: قبالً version قدیمی این فایل ردیف‌های «قبلاً تبدیل‌شده» را دوباره
--    تقسیم نمی‌کرد چون ستون نشانه نبود؛ ولی چون migrate.php این فایل را «چندبار» اجرا
--    کرده بود، نرخ طراحی روی کیس‌های بعد از مهاجرتِ دستی دوباره تقسیم شد. کیس ۱۳۶۸
--    (طراح ۲۶، خدمت ۱، ۱۲ واحد) مستقیماً در مهاجرت ۰۷۶ اصلاح شد.

-- ۱) ستونِ نشانه تا مهاجرت (در صورت اجرای دستی) دوباره داده را تقسیم نکند.
ALTER TABLE `cases`
  ADD COLUMN `design_fee_is_unit` TINYINT(1) NOT NULL DEFAULT 0 AFTER `design_fee`;

-- ۲) تبدیل «جمع» → «فی»: design_fee = design_fee / GREATEST(quantity, 1)
--    فقط ردیف‌هایی که هنوز «جمع» هستند (design_fee_is_unit = 0) — نه ردیف‌های قبلاً تبدیل‌شده.
UPDATE `cases`
SET `design_fee` = ROUND(`design_fee` / GREATEST(`quantity`, 1), 2)
WHERE `design_fee` > 0
  AND `quantity` > 0
  AND `design_fee_is_unit` = 0;

-- ۳) نشان‌دار کردن همهٔ ردیف‌ها به‌عنوان «فی».
UPDATE `cases` SET `design_fee_is_unit` = 1 WHERE `design_fee_is_unit` = 0;
