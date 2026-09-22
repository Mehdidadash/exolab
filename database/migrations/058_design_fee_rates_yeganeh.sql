-- 058_design_fee_rates_yeganeh.sql
-- نرخ‌های هزینهٔ طراحی «محمد علی یگانه» (designer #26) — پاییز ۱۴۰۵
--
-- مشکل: فقط نرخِ *اختصاصی* خدمات ۱ / ۱۴ / ۱۵ ثبت بود و **نرخ عمومی (بدون خدمت)**
-- وجود نداشت؛ در نتیجه برای هر خدمت دیگری (مثل ۲ روکش پایه ایمپلنت، ۱۸ لیرینگ)
-- نرخ «نامشخص» می‌شد، فرم کیس مقدار ۰ می‌گذاشت و بی‌سروصدا ۰ ذخیره می‌شد.
--
-- نرخ‌های تأییدشدهٔ کاربر:
--   عمومی (همهٔ خدمات) = ۱۶۰٬۰۰۰  →  روکش پایه دندان، پایه ایمپلنت، لیرینگ، ...
--   خدمت ۱۴ (فریم زیرکونیا روی دندان) = ۶۰٬۰۰۰  (بدون تغییر)
--   خدمت ۱۵ (فریم زیرکونیا روی اباتمنت) = ۹۰٬۰۰۰  (قبلاً ۸۰٬۰۰۰ بود)
--   خدمت ۱ (روکش زیرکونیا مولتی لیر) = ۱۶۰٬۰۰۰  (بدون تغییر)
--
-- قیمت‌ها هم در price_links (منبع اصلی) و هم در جدول قدیمی doctor_price_overrides
-- به‌روزرسانی می‌شوند تا مسیرهای دوگانه یکسان بمانند.

-- ── ۱) نرخ عمومی ۱۶۰٬۰۰۰ (service_id = NULL) ──
UPDATE price_links
SET price = 160000, updated_at = NOW()
WHERE price_type = 'design_fee'
  AND provider_type = 'designer' AND provider_id = 26
  AND service_id IS NULL;

INSERT INTO price_links
    (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active, created_at, updated_at)
SELECT NULL, 'designer', 26, 'branch', 1, 160000, 'design_fee', 1, 'نرخ عمومی طراحی — پرداخت توسط شعبه اصلی', 1, NOW(), NOW()
FROM (SELECT 1) AS d
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT id FROM price_links WHERE price_type = 'design_fee' AND provider_type = 'designer' AND provider_id = 26 AND service_id IS NULL) AS x
);

UPDATE doctor_price_overrides
SET custom_price = 160000, updated_at = NOW()
WHERE doctor_id = 26 AND price_type = 'design_fee' AND service_id IS NULL;

INSERT INTO doctor_price_overrides (doctor_id, branch_id, price_type, service_id, custom_price, created_at, updated_at)
SELECT 26, 1, 'design_fee', NULL, 160000, NOW(), NOW()
FROM (SELECT 1) AS d
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT id FROM doctor_price_overrides WHERE doctor_id = 26 AND price_type = 'design_fee' AND service_id IS NULL) AS x
);

-- ── ۲) خدمت ۱۵ (فریم زیرکونیا روی اباتمنت): ۸۰٬۰۰۰ → ۹۰٬۰۰۰ ──
UPDATE price_links
SET price = 90000, updated_at = NOW()
WHERE price_type = 'design_fee' AND provider_id = 26 AND service_id = 15;

UPDATE doctor_price_overrides
SET custom_price = 90000, updated_at = NOW()
WHERE doctor_id = 26 AND price_type = 'design_fee' AND service_id = 15;
