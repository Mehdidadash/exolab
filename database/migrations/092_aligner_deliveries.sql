-- 092: کیس الاینر شفاف — طرح درمان (یک‌بار) + تحویل مرحله‌ای
--
-- چرا؟
--   برای «طرح درمان الاینر شفاف»، درمان چند مرحله‌ای است و هر بار چند مرحله
--   (برای فک بالا و/یا پایین) تحویل داده می‌شود و همان تاریخ باید در فاکتور
--   همان ماه بیاید. زیرکیس‌سازی برای هر مرحله لیست کیس‌ها را شلوغ می‌کرد،
--   بنابراین یک کیس می‌ماند و «تحویل‌ها» در یک جدول جدا ثبت می‌شوند.
--
-- همراهِ این مهاجرت:
--   • site_prices.is_aligner               → خدمت «طرح درمان الاینر» است
--   • site_prices.aligner_stage_service_id → خدمتِ هر مرحله («الاینر شفاف»)
--   • site_prices.fixed_qty                → تعداد همیشه ۱ (یک فک یا دو فک، مبلغ یکسان)
--   • cases.aligner_upper_stages / lower   → تعداد کل مراحل هر فک
--
-- توجه: panel/db.php → ensureAlignerSchema() همین کار را هنگام بوت هم انجام
--        می‌دهد (برای دیتابیس‌های قدیمی)، پس اجرای این فایل روی دیتابیسِ
--        به‌روز بی‌خطر و بی‌اثر است.

SET NAMES utf8mb4;

-- ─── ستون‌های قیمت ───
ALTER TABLE site_prices ADD COLUMN is_aligner TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE site_prices ADD COLUMN aligner_stage_service_id INT NULL;
ALTER TABLE site_prices ADD COLUMN fixed_qty TINYINT(1) NOT NULL DEFAULT 0;

-- ─── ستون‌های کیس ───
ALTER TABLE cases ADD COLUMN aligner_upper_stages SMALLINT NULL;
ALTER TABLE cases ADD COLUMN aligner_lower_stages SMALLINT NULL;

-- ─── دفترچهٔ تحویل مراحل ───
CREATE TABLE IF NOT EXISTS case_aligner_deliveries (
    id INT NOT NULL AUTO_INCREMENT,
    case_id INT NOT NULL,
    delivered_date DATE NOT NULL,
    upper_from SMALLINT NULL,
    upper_to SMALLINT NULL,
    lower_from SMALLINT NULL,
    lower_to SMALLINT NULL,
    upper_qty SMALLINT NOT NULL DEFAULT 0,
    lower_qty SMALLINT NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    service_id INT NULL,
    invoice_id INT NULL,
    invoice_item_id INT NULL,
    note VARCHAR(255) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cad_case (case_id),
    KEY idx_cad_date (delivered_date),
    KEY idx_cad_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── علامت‌گذاری خدمات الاینر ───
UPDATE site_prices
   SET fixed_qty = 1
 WHERE title LIKE '%طرح درمان%' AND title LIKE '%الاینر%';

UPDATE site_prices
   SET is_aligner = 1
 WHERE title LIKE '%طرح درمان%' AND title LIKE '%الاینر%';

UPDATE site_prices
   SET aligner_stage_service_id = (
       SELECT id FROM (
           SELECT id FROM site_prices
            WHERE title LIKE '%الاینر%' AND title NOT LIKE '%طرح درمان%'
            ORDER BY id ASC LIMIT 1
       ) t
   )
 WHERE is_aligner = 1
   AND (aligner_stage_service_id IS NULL OR aligner_stage_service_id = 0);
