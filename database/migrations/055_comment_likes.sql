-- 055_comment_likes.sql
-- لایک کامنت‌ها: هر کاربر می‌تواند هر کامنت را یک‌بار لایک کند.
CREATE TABLE IF NOT EXISTS comment_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comment_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_comment_user (comment_id, user_id),
    KEY idx_comment_likes_comment (comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
