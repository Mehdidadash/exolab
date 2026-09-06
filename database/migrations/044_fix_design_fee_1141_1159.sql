-- 044_fix_design_fee_1141_1159.sql
-- اصلاح هزینه‌ی طراحی دو کیس که در اصلاح قبلی (مهاجرت 042) جا افتاده بودند:
--   #1141 اکرم قدیری  (تعداد ۲ × ۱۶۰٬۰۰۰ = ۳۲۰٬۰۰۰)
--   #1159 علیرضا آتشین (تعداد ۳ × ۱۶۰٬۰۰۰ = ۴۸۰٬۰۰۰)
-- (مقدار قبلی، نرخِ واحد بود نه نرخ × تعداد — همان باگِ ترتیب انتخاب.)
UPDATE cases SET design_fee = 320000, updated_at = NOW() WHERE patient_name = 'اکرم قدیری'   AND received_date = '2026-08-19' AND doctor_id = 21;
UPDATE cases SET design_fee = 480000, updated_at = NOW() WHERE patient_name = 'علیرضا آتشین' AND received_date = '2026-08-26' AND doctor_id = 16;
