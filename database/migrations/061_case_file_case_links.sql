-- 061_case_file_case_links.sql
-- «فایل مرتبط»: هر فایلِ کیس (case_files) می‌تواند به کیس‌های دیگری هم وصل شود و در
-- صفحهٔ همهٔ آن کیس‌ها دیده شود. فایل اصلی در جای خودش می‌ماند (کیس مبدأ) و این جدول
-- فقط پیوند (link) را نگه می‌دارد.
--
-- نمونه کاربرد: اسکن/طراحیِ یک کیس، برای کیسِ بعدیِ همان بیمار هم لازم است.
CREATE TABLE IF NOT EXISTS case_file_case_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_file_id INT NOT NULL,
    case_id INT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_casefile_case (case_file_id, case_id),
    KEY idx_cfcl_case (case_id),
    KEY idx_cfcl_file (case_file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
