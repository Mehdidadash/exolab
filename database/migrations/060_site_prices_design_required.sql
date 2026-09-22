-- 060_site_prices_design_required.sql
-- بعضی خدمات اصلاً طراحی ندارند؛ برای این خدمات، کیس به‌صورت پیش‌فرض «بدون طراح»
-- ثبت می‌شود و هزینهٔ طراحی صفر می‌ماند (فرم کیس دیگر طراح پیش‌فرض را انتخاب نمی‌کند).
--
-- خدمات بدون طراحی (تأیید کاربر):
--   #11 پست NPG
--   #12 پرینت کست یک فک کامل
--   #13 پرینت کست یک نیم فک
--   #17 الاینر شفاف
ALTER TABLE site_prices
    ADD COLUMN IF NOT EXISTS design_required TINYINT(1) NOT NULL DEFAULT 1 AFTER hide_on_site;

UPDATE site_prices SET design_required = 0 WHERE id IN (11, 12, 13, 17);
