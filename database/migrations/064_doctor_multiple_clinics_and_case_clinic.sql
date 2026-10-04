-- 064_doctor_multiple_clinics_and_case_clinic.sql
-- ۱) یک پزشک می‌تواند هم‌زمان عضو چند کلینیک باشد (مثلاً در دو کلینیک کار می‌کند).
--    جدول پیوند `user_clinics` عضویت‌های اضافی را نگه می‌دارد و ستون `users.clinic_id`
--    به‌عنوان «کلینیک اصلی» (پیش‌فرضِ کیس‌های جدید) باقی می‌ماند.
-- ۲) هر کیس می‌تواند «کلینیکِ صاحب کار» داشته باشد (`cases.clinic_id`) تا فاکتور
--    کلینیکی و گزارش‌ها مستقل از تغییرِ بعدیِ کلینیکِ پزشک درست بمانند.

CREATE TABLE IF NOT EXISTS user_clinics (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    clinic_id  INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_clinic (user_id, clinic_id),
    KEY idx_uc_user (user_id),
    KEY idx_uc_clinic (clinic_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- انتقالِ عضویت‌های فعلی (users.clinic_id) به جدول پیوند
INSERT IGNORE INTO user_clinics (user_id, clinic_id)
SELECT id, clinic_id FROM users WHERE clinic_id IS NOT NULL AND clinic_id > 0;

-- کلینیکِ هر کیس (قابل انتخاب در فرم کیس) -------------------------------------
ALTER TABLE cases ADD COLUMN IF NOT EXISTS clinic_id INT NULL AFTER doctor_id;

-- پرکردنِ کیس‌های قبلی از روی کلینیکِ فعلیِ پزشکشان (فقط یک‌بار و برای مقادیر خالی)
UPDATE cases c
JOIN users u ON u.id = c.doctor_id
SET c.clinic_id = u.clinic_id
WHERE c.clinic_id IS NULL AND u.clinic_id IS NOT NULL AND u.clinic_id > 0;
