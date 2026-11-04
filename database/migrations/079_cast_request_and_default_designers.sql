-- 079_cast_request_and_default_designers.sql
-- ۱) «درخواست کست از طراح» روی کیس‌ها
-- ۲) «طراح پیش‌فرض اختصاصی» برای هر پزشک و هر شعبه

-- ─── ۱) درخواست کست ──────────────────────────────────────────────
-- case_cast_requested  = ۱ یعنی برای این کیس باید کست/مدل تهیه و به طراح ارسال شود
-- cast_requested_at    = زمان درخواست (برای مرتب‌سازی/گزارش)
-- case_cast_requested_by = کاربری که درخواست داده است
-- (هر ستون جداگانه است تا اگر بخشی روی هاست وجود داشت، اجرای دوباره خطا ندهد)
ALTER TABLE cases ADD COLUMN case_cast_requested TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE cases ADD COLUMN cast_requested_at DATETIME NULL;
ALTER TABLE cases ADD COLUMN cast_requested_by INT NULL;

-- ─── ۲) طراح پیش‌فرض اختصاصی ─────────────────────────────────────
-- users.default_designer_id  = طراح پیش‌فرضِ اختصاصیِ پزشک/کلینیک
-- branches.default_designer_id = طراح پیش‌فرضِ اختصاصیِ شعبه
-- زنجیرهٔ انتخاب در getDefaultDesignerFor():
--   پزشک → شعبهٔ پزشک → طراح پیش‌فرضِ سراسری (users.is_default_designer)
ALTER TABLE users ADD COLUMN default_designer_id INT NULL;
ALTER TABLE branches ADD COLUMN default_designer_id INT NULL;
