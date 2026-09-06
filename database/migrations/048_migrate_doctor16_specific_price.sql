-- 048_migrate_doctor16_specific_price.sql
-- ردیف قدیمیِ «قیمت service» پزشک ۱۶ (مهدی آرزوبخش — svc1 = ۱٬۹۵۰٬۰۰۰) به جدول یکپارچه‌ی
-- price_links به‌صورت «قیمت اختصاصی» منتقل می‌شود تا در نقشه‌ی قیمت دیده و ویرایش شود
-- (ارائه‌دهنده = شعبه مرکزی ۱ که به این پزشک کار می‌دهد؛ دریافت‌کننده = پزشک ۱۶).
-- ردیف‌های قدیمیِ مشابه روی کاربران ۱۳ و ۱۸ (svc1/svc18) از قبل با لینک‌های #۷/#۹/#۱۰
-- در نقشه بازنمایی شده‌اند، بنابراین ردیف تکراری ساخته نمی‌شود.
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 1, 'branch', 1, 'doctor', 16, 1950000, 'specific', 1, 'قیمت اختصاصی پزشک ۱۶ (svc1) — منتقل از جدول قدیمی', 1
WHERE NOT EXISTS (
    SELECT 1 FROM price_links
    WHERE service_id = 1 AND provider_type = 'branch' AND provider_id = 1
      AND receiver_type = 'doctor' AND receiver_id = 16 AND active = 1
);

-- اطمینان از همگام بودن ردیف قدیمیِ پشتیبان (بدون تغییر در رفتار فاکتور)
UPDATE doctor_price_overrides
SET custom_price = 1950000, updated_at = NOW()
WHERE doctor_id = 16 AND price_type = 'service' AND service_id = 1;
