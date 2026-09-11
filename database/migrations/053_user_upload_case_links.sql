-- 053_user_upload_case_links.sql
-- کتابخانهٔ فایل مشترک: هر فایلِ آپلودشده در «آپلود فایل» (user_uploads) می‌تواند به
-- چند کیس وصل شود و در صفحهٔ همهٔ آن کیس‌ها دیده شود (مثلاً اسکنِ خام مشترک بین دو کیس).
CREATE TABLE IF NOT EXISTS user_upload_case_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    upload_id INT NOT NULL,
    case_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_upload_case (upload_id, case_id),
    KEY idx_case_id (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
