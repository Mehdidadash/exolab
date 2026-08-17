-- 019_doctor_gallery.sql
-- Doctor profile photos with captions (work style / taste).
-- Visible to designers, admins, and internal staff – NOT to the doctor themself.
CREATE TABLE IF NOT EXISTS doctor_gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    caption TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (doctor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
