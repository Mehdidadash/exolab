-- 093: طراح پیش‌فرضِ هر خدمت
--
-- چرا؟
--   «طراح پیش‌فرض» فقط در سطح پزشک (users.default_designer_id) و شعبه
--   (branches.default_designer_id) و سراسری (users.is_default_designer) بود.
--   برای بعضی خدمات (مثلاً هر خدمتی که همیشه به یک طراح خاص می‌رود) لازم است
--   خودِ خدمت طراح پیش‌فرض داشته باشد، یا برعکس: هیچ طراح پیش‌فرضی انتخاب نشود.
--
-- مقادیر ستون:
--   NULL  → قاعدهٔ عادی (پزشک → شعبه → سراسری)
--   0     → برای این خدمت طراح پیش‌فرض انتخاب نشود
--   >0    → همان طراح (users.is_designer = 1)
--
-- نکته: panel/db.php → ensureSitePricesDefaultDesignerColumn() همین ستون را هنگام
--        بوت هم می‌سازد؛ اجرای این فایل روی دیتابیسِ به‌روز بی‌خطر است.

SET NAMES utf8mb4;

ALTER TABLE site_prices ADD COLUMN default_designer_id INT NULL DEFAULT NULL AFTER default_design_fee;
