-- 075_site_prices_default_design_fee.sql
-- افزودن «نرخ طراحی پیش‌فرض» به هر خدمت.
--
-- مفهوم: اگر برای یک خدمت، نرخِ طراحیِ اختصاصی (design_fee) برای طراحِ انتخابی وجود
-- نداشت، فی طراحی از این عدد استفاده می‌کند. این عدد «فی هر واحد» است (نه جمع کل).
--
-- مقدار پیش‌فرض NULL = بدون پیش‌فرض (رفتار قبلی: نرخ نامشخص و طراحی ۰ می‌ماند).
ALTER TABLE `site_prices`
  ADD COLUMN `default_design_fee` DECIMAL(15,2) NULL DEFAULT NULL AFTER `extra_unit_price`;
