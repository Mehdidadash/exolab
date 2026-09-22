-- 062_site_prices_short_name.sql
-- «نام اختصاری» خدمات: در جدول کیس‌ها و چاپ برچسب به‌جای عنوان کامل استفاده می‌شود
-- تا متن‌ها کوتاه بمانند و چیدمان به‌هم نخورد. قابل ویرایش از صفحهٔ «قیمت‌ها» است.
--
-- مقادیر پیشنهادی برای خدمات فعلی:
--   #1  روکش زیرکونیا مولتی لیر            ML
--   #2  روکش زیرکونیای پایه ایمپلنت        IM_ML
--   #3  لمینیت ای-مکس                      EMX
--   #4  مریلند بریج زیرکونیا               M_Bri
--   #5  کاستوم اباتمنت کره‌ای               Cu_KR
--   #6  کاستوم اباتمنت اروپایی             Cu_EU
--   #7  روکش موقت PMMA                     PMMA
--   #8  نایت گارد سخت                       Ni_Gu_H
--   #9  نایت گارد نرم                       Ni_Gu_S
--   #10 تری بلیچینگ                         T_BL
--   #11 پست NPG                             NPG
--   #12 پرینت کست یک فک کامل                Pr_C_F
--   #13 پرینت کست یک نیم فک                 Pr_C_H
--   #14 فریم زیرکونیا روی دندان            FR_T
--   #15 فریم زیرکونیا روی اباتمنت          FR_Ab
--   #16 طرح درمان الاینر شفاف              ALi_PL
--   #17 الاینر شفاف                         ALi
--   #18 لیرینگ روی فریم زیرکونیا            LAY
--   #19 روکش PFM                            PFM
--   #20 سرجیکال گاید                        SG
--   #21 طراحی لبخند                         SMD
--   #22 تیتانیوم بار پرینت‌شونده             TI_BAR
ALTER TABLE site_prices
    ADD COLUMN IF NOT EXISTS short_name VARCHAR(24) NULL AFTER title;

UPDATE site_prices SET short_name = CASE id
    WHEN 1  THEN 'ML'
    WHEN 2  THEN 'IM_ML'
    WHEN 3  THEN 'EMX'
    WHEN 4  THEN 'M_Bri'
    WHEN 5  THEN 'Cu_KR'
    WHEN 6  THEN 'Cu_EU'
    WHEN 7  THEN 'PMMA'
    WHEN 8  THEN 'Ni_Gu_H'
    WHEN 9  THEN 'Ni_Gu_S'
    WHEN 10 THEN 'T_BL'
    WHEN 11 THEN 'NPG'
    WHEN 12 THEN 'Pr_C_F'
    WHEN 13 THEN 'Pr_C_H'
    WHEN 14 THEN 'FR_T'
    WHEN 15 THEN 'FR_A'
    WHEN 16 THEN 'ALi_PL'
    WHEN 17 THEN 'ALi'
    WHEN 18 THEN 'LAY'
    WHEN 19 THEN 'PFM'
    WHEN 20 THEN 'S_Gu'
    WHEN 21 THEN 'SMi_De'
    WHEN 22 THEN 'TI_BAR'
    ELSE NULL
END
WHERE short_name IS NULL OR short_name = '';
