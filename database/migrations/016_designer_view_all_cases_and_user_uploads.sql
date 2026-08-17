-- 016_designer_view_all_cases.sql
-- Designers now see ALL cases (they work across all doctors/clinics/labs).
-- Only doctors, clinics, and labs are scoped to their own cases.
UPDATE roles
SET permissions = JSON_ARRAY('view_all_cases', 'upload_design_files', 'edit_case_status')
WHERE name = 'designer';

-- Standalone uploads that may or may not belong to a case
CREATE TABLE IF NOT EXISTS user_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    case_id INT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NULL,
    size INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
