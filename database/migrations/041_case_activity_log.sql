-- 041_case_activity_log.sql
-- لاگ فعالیت‌های هر کیس: ایجاد، ویرایش، آپلود فایل، دانلود فایل، مشاهده‌ی صفحه، کامنت، تغییر وضعیت.
-- هر ردیف: چه کسی (user_id) چه زمانی (created_at) چه کاری (action) با چه جزئیاتی (details) انجام داده.
CREATE TABLE IF NOT EXISTS case_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    user_id INT NULL,
    action VARCHAR(40) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cal_case_time (case_id, created_at),
    INDEX idx_cal_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
