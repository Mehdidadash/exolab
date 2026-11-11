-- 090_cases_urgent.sql
--
-- «فوری» بودنِ کیس — اولویتِ طراحی
-- کاربر می‌تواند در فرمِ ایجاد/ویرایش کیس آن را «فوری» علامت بزند تا طراح
-- در ستون اولِ لیست کیس‌ها و در صفحهٔ کیس نشانِ 🔥 «فوری» را ببیند و کار را
-- در اولویتِ طراحی قرار دهد.
--
-- is_urgent = 1 → فوری ، 0 → عادی
-- is_urgent_at / is_urgent_by = زمان و کاربری که علامت زده (برای گزارش/پیگیری)

ALTER TABLE cases
    ADD COLUMN IF NOT EXISTS is_urgent TINYINT(1) NOT NULL DEFAULT 0 AFTER status_id,
    ADD COLUMN IF NOT EXISTS is_urgent_at DATETIME NULL AFTER is_urgent,
    ADD COLUMN IF NOT EXISTS is_urgent_by INT NULL AFTER is_urgent_at;

ALTER TABLE cases ADD INDEX IF NOT EXISTS idx_cases_is_urgent (is_urgent);
