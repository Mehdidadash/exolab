-- 094: تعیین تکلیف کیس‌های الاینرِ قدیمی
--
-- زمینه:
--   قبل از اضافه‌شدن «تحویل مرحله‌ای الاینر»، مرحله‌ها به‌صورت کیس جداگانه با
--   خدمت «الاینر شفاف» (site_prices.id = 17) ثبت می‌شدند و تعداد مرحله در quantity.
--   همچنین برای کیس‌های «هر دو فک»، تعداد ۲ گرفته می‌شد و مبلغ طرح درمان دو برابر
--   فاکتور می‌شد (در حالی که هزینهٔ طرح درمان برای یک/دو فک یکسان است).
--
-- این مهاجرت:
--   ۱) ستون‌های لازم را می‌سازد: cases.archived_at و case_aligner_deliveries.legacy_imported
--   ۲) تعداد کل مراحل هر فک را برای کیس‌های شناخته‌شده می‌گذارد
--   ۳) هر «کیس مرحله» را به یک «تحویل مرحله» تبدیل می‌کند (با تاریخ/مبلغ/فاکتور خودش)
--      و خودِ کیس مرحله را آرشیو می‌کند (از لیست‌ها پنهان می‌شود؛ فاکتورها دست‌نخورده)
--   ۴) مبلغ طرح درمان کیس‌های دو فک را در موارد فاکتورنشده/پرداخت‌نشده اصلاح می‌کند
--
-- ⚠️ تحویل‌های واردشده legacy_imported = 1 دارند تا هرگز دوباره فاکتور نشوند.
-- ⚠️ کیس 1538 (که کاربر قصد حذفش را دارد) دست نمی‌خورد.

SET NAMES utf8mb4;

-- ─── ۱) ستون‌ها ───
ALTER TABLE cases ADD COLUMN archived_at DATETIME NULL;
ALTER TABLE case_aligner_deliveries ADD COLUMN legacy_imported TINYINT(1) NOT NULL DEFAULT 0;

-- ─── ۲) تعداد کل مراحل (فقط کیس‌هایی که کاربر اعلام کرده) ───
UPDATE cases SET aligner_upper_stages = 18
 WHERE id = 1153 AND aligner_upper_stages IS NULL;

UPDATE cases SET aligner_lower_stages = 17
 WHERE id = 1155 AND aligner_lower_stages IS NULL;

UPDATE cases SET aligner_upper_stages = 10, aligner_lower_stages = 10
 WHERE id = 1250 AND (aligner_upper_stages IS NULL OR aligner_upper_stages = 0);

UPDATE cases SET aligner_upper_stages = 12, aligner_lower_stages = 7
 WHERE id = 1482 AND (aligner_upper_stages IS NULL OR aligner_upper_stages = 0);

UPDATE cases SET aligner_lower_stages = 12
 WHERE id = 1483 AND aligner_lower_stages IS NULL;

UPDATE cases SET aligner_upper_stages = 15, aligner_lower_stages = 18
 WHERE id = 1484 AND (aligner_upper_stages IS NULL OR aligner_upper_stages = 0);

-- ─── ۳) تبدیل کیس‌های مرحله به «تحویل مرحله» ───
-- (هر INSERT فقط اگر از قبل نبود؛ تکرارِ اجرا داده را دوباره نمی‌سازد)
INSERT INTO case_aligner_deliveries
    (case_id, delivered_date, upper_from, upper_to, lower_from, lower_to,
     upper_qty, lower_qty, unit_price, amount, service_id, invoice_id, note, legacy_imported, created_at)
SELECT 1153, '2026-08-25', 1, 1, NULL, NULL, 1, 0, 1200000, 1200000, 17, 39,
       'انتقال از کیس مرحله #1154', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM case_aligner_deliveries WHERE case_id = 1153 AND note = 'انتقال از کیس مرحله #1154');

INSERT INTO case_aligner_deliveries
    (case_id, delivered_date, upper_from, upper_to, lower_from, lower_to,
     upper_qty, lower_qty, unit_price, amount, service_id, invoice_id, note, legacy_imported, created_at)
SELECT 1153, '2026-08-25', 2, 6, NULL, NULL, 5, 0, 1200000, 6000000, 17, 52,
       'انتقال از کیس مرحله #1426', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM case_aligner_deliveries WHERE case_id = 1153 AND note = 'انتقال از کیس مرحله #1426');

INSERT INTO case_aligner_deliveries
    (case_id, delivered_date, upper_from, upper_to, lower_from, lower_to,
     upper_qty, lower_qty, unit_price, amount, service_id, invoice_id, note, legacy_imported, created_at)
SELECT 1155, '2026-08-25', NULL, NULL, 1, 1, 0, 1, 1200000, 1200000, 17, 39,
       'انتقال از کیس مرحله #1156', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM case_aligner_deliveries WHERE case_id = 1155 AND note = 'انتقال از کیس مرحله #1156');

-- ۸ مرحله برای فک پایین اعلام شده؛ ۶ مرحله فاکتور شده و ۲ مرحلهٔ بعدی در همان نوبت
-- تحویل شده ولی ردیف فاکتور ندارد → بازه تا ۸ کشیده شده با مبلغ فاکتورشده.
INSERT INTO case_aligner_deliveries
    (case_id, delivered_date, upper_from, upper_to, lower_from, lower_to,
     upper_qty, lower_qty, unit_price, amount, service_id, invoice_id, note, legacy_imported, created_at)
SELECT 1155, '2026-10-21', NULL, NULL, 2, 8, 0, 7, 1200000, 6000000, 17, 52,
       'انتقال از کیس مرحله #1425 (۷ مرحله در یک نوبت؛ ۲ مرحله بدون ردیف فاکتور)', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM case_aligner_deliveries WHERE case_id = 1155 AND note LIKE 'انتقال از کیس مرحله #1425%');

INSERT INTO case_aligner_deliveries
    (case_id, delivered_date, upper_from, upper_to, lower_from, lower_to,
     upper_qty, lower_qty, unit_price, amount, service_id, invoice_id, note, legacy_imported, created_at)
SELECT 1250, '2026-09-20', 1, 2, 1, 2, 2, 2, 1200000, 4800000, 17, 39,
       'انتقال از کیس مرحله #1264', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM case_aligner_deliveries WHERE case_id = 1250 AND note = 'انتقال از کیس مرحله #1264');

INSERT INTO case_aligner_deliveries
    (case_id, delivered_date, upper_from, upper_to, lower_from, lower_to,
     upper_qty, lower_qty, unit_price, amount, service_id, invoice_id, note, legacy_imported, created_at)
SELECT 1250, '2026-10-21', 3, 6, 3, 6, 4, 4, 1200000, 4800000, 17, 52,
       'انتقال از کیس مرحله #1424', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM case_aligner_deliveries WHERE case_id = 1250 AND note = 'انتقال از کیس مرحله #1424');

INSERT INTO case_aligner_deliveries
    (case_id, delivered_date, upper_from, upper_to, lower_from, lower_to,
     upper_qty, lower_qty, unit_price, amount, service_id, invoice_id, note, legacy_imported, created_at)
SELECT 1405, '2026-10-21', 1, 2, NULL, NULL, 2, 0, 1200000, 2400000, 17, 52,
       'انتقال از کیس مرحله #1423', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM case_aligner_deliveries WHERE case_id = 1405 AND note = 'انتقال از کیس مرحله #1423');

INSERT INTO case_aligner_deliveries
    (case_id, delivered_date, upper_from, upper_to, lower_from, lower_to,
     upper_qty, lower_qty, unit_price, amount, service_id, invoice_id, note, legacy_imported, created_at)
SELECT 1482, '2026-10-28', 1, 2, 1, 2, 2, 2, 1200000, 4800000, 17, 80,
       'انتقال از کیس مرحله #1519', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM case_aligner_deliveries WHERE case_id = 1482 AND note = 'انتقال از کیس مرحله #1519');

INSERT INTO case_aligner_deliveries
    (case_id, delivered_date, upper_from, upper_to, lower_from, lower_to,
     upper_qty, lower_qty, unit_price, amount, service_id, invoice_id, note, legacy_imported, created_at)
SELECT 1483, '2026-10-28', NULL, NULL, 1, 1, 0, 1, 1200000, 2400000, 17, 80,
       'انتقال از کیس مرحله #1520 (در فاکتور ۲ مرحله محاسبه شده)', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM case_aligner_deliveries WHERE case_id = 1483 AND note LIKE 'انتقال از کیس مرحله #1520%');

INSERT INTO case_aligner_deliveries
    (case_id, delivered_date, upper_from, upper_to, lower_from, lower_to,
     upper_qty, lower_qty, unit_price, amount, service_id, invoice_id, note, legacy_imported, created_at)
SELECT 1484, '2026-10-28', 1, 1, 1, 1, 1, 1, 1200000, 0, 17, NULL,
       'تحویل گذشته — رکورد فاکتور ندارد', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM case_aligner_deliveries WHERE case_id = 1484 AND note = 'تحویل گذشته — رکورد فاکتور ندارد');

-- ─── ۴) آرشیوِ کیس‌های مرحله (فاکتورها دست‌نخورده می‌مانند) ───
UPDATE cases SET archived_at = NOW()
 WHERE id IN (1154, 1156, 1264, 1423, 1424, 1425, 1426, 1519, 1520)
   AND archived_at IS NULL;

-- ─── ۵) اصلاح مبلغ طرح درمانِ دو فک (فقط فاکتورنشده/پرداخت‌نشده) ───
-- ۱۴۸۲ و ۱۴۸۴ در فاکتور ۸۰ (پرداخت‌نشده) هستند → هم ردیف فاکتور هم جمع فاکتور اصلاح می‌شود.
UPDATE cases SET quantity = 1, total_price = unit_price
 WHERE id IN (1482, 1484, 1735) AND (quantity <> 1 OR total_price <> unit_price);

UPDATE invoice_items SET quantity = 1, total_amount = unit_price
 WHERE invoice_id = 80 AND case_id IN (1482, 1484) AND quantity <> 1;

UPDATE doctor_invoices i
   SET total_amount = (SELECT COALESCE(SUM(ii.total_amount), 0) FROM invoice_items ii WHERE ii.invoice_id = i.id)
 WHERE i.id = 80;
