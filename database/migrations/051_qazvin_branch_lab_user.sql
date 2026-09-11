-- 051_qazvin_branch_lab_user.sql
--
-- اصلاح مدل «لابراتوارِ شعبه» (طبق توضیح کاربر):
--   * هر شعبه یک «لابراتوارِ خودِ شعبه» دارد که شعب دیگر برای برون‌سپاری بین‌شعبه‌ای
--     (lab_in / lab_out / برون‌سپاری جانبی) آن را انتخاب می‌کنند:
--        - شعبهٔ مرکزی (branch 1) = کاربر ۲۷ «لابراتوار مرکزی»   (از مایگریشن ۰۳۳)
--        - شعبهٔ قزوین (branch 2) = «لابراتوار شعبهٔ قزوین»  ← در این مایگریشن ساخته می‌شود
--   * محمد محمدی (کاربر ۲۹) یک «لابراتوار همکارِ بیرونی» است که معمولاً با قزوین کار
--     می‌کند و متعلق به هیچ شعبه‌ای نیست. بنابراین branch_id آن باید NULL باشد تا مثل
--     سایر لابراتوارهای بیرونی (ساجدی/امین) در لیست همه دیده شود و از دیدِ شعبهٔ قزوین
--     حذف نشود. (قبلاً اشتباهاً به شعبهٔ قزوین نسبت داده شده بود.)
--
-- رمز پیش‌فرض حساب «لابراتوار شعبهٔ قزوین»: QazvinLab@1405  (بعد از ورود تغییر دهید)

-- 1) ساخت لابراتوارِ شعبهٔ قزوین (branch 2) اگر وجود ندارد
INSERT INTO users (username, password_hash, full_name, role, branch_id, active, created_at, updated_at)
SELECT 'qazvin_lab', '$2y$10$SuuWnE2VQlrE6t6XupchZOwf01g6GsMccuVmkFTJOAwXshFODuSHC', 'لابراتوار شعبهٔ قزوین', 'outsource_lab', 2, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM users
    WHERE role IN ('outsource_lab','customer_lab','partner_lab','lab')
      AND branch_id = 2 AND active = 1
);

-- 2) محمد محمدی (کاربر ۲۹) = لابراتوار همکارِ بیرونی → بدون شعبه
UPDATE users
SET branch_id = NULL
WHERE id = 29
  AND role = 'partner_lab';
