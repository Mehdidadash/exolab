-- 052_qazvin_branch_lab_user_ensure.sql
-- ساخته‌شدنِ «لابراتوار شعبهٔ قزوین» را تضمین می‌کند (اگر در ۰۵۱ به‌خاطر ترتیبِ دستورات
-- ساخته نشده بود). گارد روی username است تا با هر بار اجرا idempotent بماند.
-- رمز پیش‌فرض: QazvinLab@1405  (بعد از اولین ورود تغییر دهید)

-- 1) محمد محمدی (کاربر ۲۹) = لابراتوار همکارِ بیرونی (بدون شعبه) — idempotent
UPDATE users SET branch_id = NULL WHERE id = 29 AND role = 'partner_lab';

-- 2) ساختِ لابراتوار شعبهٔ قزوین (branch 2) اگر وجود ندارد
INSERT INTO users (username, password_hash, full_name, role, branch_id, active, created_at, updated_at)
SELECT 'qazvin_lab', '$2y$10$SuuWnE2VQlrE6t6XupchZOwf01g6GsMccuVmkFTJOAwXshFODuSHC', 'لابراتوار شعبهٔ قزوین', 'outsource_lab', 2, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE username = 'qazvin_lab'
);
