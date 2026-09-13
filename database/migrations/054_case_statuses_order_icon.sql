-- 054_case_statuses_order_icon.sql
-- ترتیب نمایش، آیکون و رنگ برای وضعیت‌های کیس (ترتیب در همه‌ی select‌ها رعایت می‌شود).
ALTER TABLE case_statuses
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER name,
    ADD COLUMN IF NOT EXISTS icon VARCHAR(60) NULL AFTER sort_order,
    ADD COLUMN IF NOT EXISTS color VARCHAR(20) NULL AFTER icon;

-- مقدار اولیه‌ی ترتیب = id تا رفتار فعلی حفظ شود.
UPDATE case_statuses SET sort_order = id WHERE sort_order = 0 OR sort_order IS NULL;
