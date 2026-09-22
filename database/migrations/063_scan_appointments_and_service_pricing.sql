-- 063_scan_appointments_and_service_pricing.sql
-- ۱) نوبت‌دهی اسکن: هر نوبت = یک بازهٔ زمانی برای رفتن پیش یک پزشک (برای اسکن)
--    و این‌که آیا «اسکن بادی» باید همراه برده شود یا نه.
-- ۲) قیمت‌گذاری خدمات: تعداد دستی (الاینر) + قیمت پله‌ای (قیمت پایه + هر واحد اضافه).

CREATE TABLE IF NOT EXISTS scan_appointments (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    branch_id        INT NULL,
    doctor_id        INT NULL,
    case_id          INT NULL,
    patient_name     VARCHAR(160) NULL,
    title            VARCHAR(180) NULL,
    appt_date        DATE NOT NULL,
    start_time       TIME NOT NULL,
    end_time         TIME NULL,
    appt_type        VARCHAR(20) NOT NULL DEFAULT 'scan',
    needs_scan_body  TINYINT(1) NOT NULL DEFAULT 0,
    address          VARCHAR(255) NULL,
    phone            VARCHAR(30) NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'scheduled',
    notes            TEXT NULL,
    reminder_sent_at DATETIME NULL,
    created_by       INT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NULL,
    KEY idx_sa_date (appt_date),
    KEY idx_sa_doctor (doctor_id),
    KEY idx_sa_branch (branch_id),
    KEY idx_sa_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- قیمت‌گذاری خدمات -----------------------------------------------------------
--   qty_manual       : ۱ = کاربر می‌تواند «تعداد» کیس را دستی تغییر دهد (مثل الاینر)
--   base_units       : تعداد واحدهایی که «قیمت پایه» پوشش می‌دهد (پیش‌فرض ۱)
--   extra_unit_price : قیمت هر واحد اضافه (مبلغ کل = پایه + (تعداد − base_units) × این عدد)
ALTER TABLE site_prices
    ADD COLUMN IF NOT EXISTS qty_manual TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS base_units INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS extra_unit_price DECIMAL(15,2) NULL;

-- الاینر شفاف (#17): تعداد باید دستی باشد (مثلاً کیسی ۱۰ یا ۲۰ الاینر لازم دارد)
UPDATE site_prices SET qty_manual = 1, base_units = 1, extra_unit_price = 1200000 WHERE id = 17;

-- سرجیکال گاید (#20): یک دندان ۳٬۵۰۰٬۰۰۰ + هر دندان اضافه ۲۵۰٬۰۰۰
UPDATE site_prices SET price = 3500000, base_units = 1, extra_unit_price = 250000 WHERE id = 20;

-- تیتانیوم بار (#22): هر پایه ۴٬۰۰۰٬۰۰۰ (برای تخفیف چندپایه، «قیمت هر واحد اضافه» را کمتر کنید)
UPDATE site_prices SET base_units = 1, extra_unit_price = 4000000 WHERE id = 22;
