-- 059_fix_design_fees_sep.sql
-- اصلاح هزینهٔ طراحیِ ذخیره‌شده روی کیس‌ها (شهریور ۱۴۰۵)
--
-- دو دسته خرابی:
--   الف) هزینهٔ طراحی صفر شده بود چون برای «طراح ۲۶ + خدمتِ بدون نرخ» چیزی پیدا نمی‌شد
--        (نرخ عمومی از مهاجرت 058 اضافه شد).
--   ب) مقدارهای دستی/قدیمی که با «نرخ هر واحد × تعداد» نمی‌خواندند (بازماندهٔ باگِ
--        محاسبهٔ هزینهٔ طراحی با تعدادِ ۱ در نسخهٔ قبلی فرم).
--
-- قاعده: design_fee = نرخ هر واحد × تعداد
--   طراح ۲۶: عمومی ۱۶۰٬۰۰۰ | خدمت ۱۴ = ۶۰٬۰۰۰ | خدمت ۱۵ = ۹۰٬۰۰۰
--   خدمات بدون طراحی (۱۱ پست NPG، ۱۲/۱۳ پرینت کست، ۱۷ الاینر) → ۰ و بدون طراح
--
-- استثناها که دست‌نخورده می‌مانند:
--   * کیس‌های داخل فاکتور طراح (designer_invoice_id IS NOT NULL) — مثل ۱۱۲۳ که ۶ واحد
--     طراحی داشته و در فاکتور #۲ رفته است.
--   * کیس‌های بدون طراح یا با طراح «مدیر سایت» (طراحی توسط خودِ مدیر، بدون هزینه).

-- ── الف) کیس‌هایی که هزینهٔ طراحی صفر داشتند ──
UPDATE cases SET design_fee = 800000,   updated_at = NOW() WHERE id = 1178;  -- svc1 × 5
UPDATE cases SET design_fee = 640000,   updated_at = NOW() WHERE id = 1180;  -- svc2 × 4
UPDATE cases SET design_fee = 320000,   updated_at = NOW() WHERE id = 1219;  -- svc2 × 2
UPDATE cases SET design_fee = 480000,   updated_at = NOW() WHERE id = 1217;  -- svc2 × 3
UPDATE cases SET design_fee = 480000,   updated_at = NOW() WHERE id = 1218;  -- svc2 × 3

-- ── ب) مقدارهای دستی/قدیمی غلط (نرخ هر واحد × تعداد) ──
UPDATE cases SET design_fee = 640000,   updated_at = NOW() WHERE id = 1160;  -- svc18 × 4
UPDATE cases SET design_fee = 320000,   updated_at = NOW() WHERE id = 1165;  -- svc1 × 2
UPDATE cases SET design_fee = 160000,   updated_at = NOW() WHERE id = 1167;  -- svc1 × 1
UPDATE cases SET design_fee = 480000,   updated_at = NOW() WHERE id = 1169;  -- svc1 × 3
UPDATE cases SET design_fee = 320000,   updated_at = NOW() WHERE id = 1170;  -- svc1 × 2
UPDATE cases SET design_fee = 1280000,  updated_at = NOW() WHERE id = 1171;  -- svc1 × 8
UPDATE cases SET design_fee = 800000,   updated_at = NOW() WHERE id = 1175;  -- svc1 × 5
UPDATE cases SET design_fee = 320000,   updated_at = NOW() WHERE id = 1199;  -- svc2 × 2

-- ── ج) خدمات بدون طراحی: هزینه صفر و بدون طراح ──
-- (انتخاب طراح برای این خدمات اشتباه کاربر است؛ pست NPG / پرینت کست / الاینر شفاف)
UPDATE cases
SET design_fee = 0, designer_id = NULL, updated_at = NOW()
WHERE service_id IN (11, 12, 13, 17)
  AND designer_id IS NOT NULL
  AND designer_id <> 1;          -- طراح «مدیر سایت» در کیس‌های قدیمی دست‌نخورده بماند

-- کیس ۱۲۰۳ (پست NPG) هزینهٔ طراحی ۱۶۰٬۰۰۰ داشت → ۰ میشود (طراحش هم در بالا پاک شد)
UPDATE cases SET design_fee = 0, updated_at = NOW() WHERE id = 1203;
