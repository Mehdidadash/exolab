-- 042_fix_design_fees.sql
-- اصلاح «هزینه‌ی طراحی» کیس‌هایی که به‌دلیل باگِ وابسته به ترتیبِ انتخاب خدمت/دندان،
-- به‌اشتباه صفر یا نرخِ واحد (به‌جای نرخ × تعداد) ثبت شده‌اند.
-- (فیکس کد در panel/cases.php اعمال شده است؛ این مهاجرت داده‌های موجود را اصلاح می‌کند.)
-- کلیدِ تطبیق: نام بیمار + تاریخ دریافت + پزشک — در هر محیطی که دیتابیس منتقل شود کار می‌کند.
UPDATE cases SET design_fee = 600000,  updated_at = NOW() WHERE patient_name = 'خانوم حسینی'    AND received_date = '2026-08-06' AND doctor_id = 3;
UPDATE cases SET design_fee = 160000,  updated_at = NOW() WHERE patient_name = 'خانوم فتحی'     AND received_date = '2026-08-08' AND doctor_id = 3;
UPDATE cases SET design_fee = 1280000, updated_at = NOW() WHERE patient_name = 'خانوم محمدزاده' AND received_date = '2026-08-09' AND doctor_id = 25;
UPDATE cases SET design_fee = 480000,  updated_at = NOW() WHERE patient_name = 'حسین ماضی'      AND received_date = '2026-08-09' AND doctor_id = 16;
UPDATE cases SET design_fee = 160000,  updated_at = NOW() WHERE patient_name = 'امین بابایی'    AND received_date = '2026-08-10' AND doctor_id = 22;
UPDATE cases SET design_fee = 960000,  updated_at = NOW() WHERE patient_name = 'مریم فیضی زاده' AND received_date = '2026-08-08' AND doctor_id = 3;  -- کیس خاص: ۶ واحد طراحی
UPDATE cases SET design_fee = 480000,  updated_at = NOW() WHERE patient_name = 'حبیبه پناهی'    AND received_date = '2026-08-15' AND doctor_id = 12;
