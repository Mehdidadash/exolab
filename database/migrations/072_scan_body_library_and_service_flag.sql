-- 072_scan_body_library_and_service_flag.sql
-- ۱) کتابخانهٔ اسکن‌بادی: هر نوع اسکن‌بادی می‌تواند یک «لینکِ دانلود» یا «فایل» داشته باشد
--    تا طراحان (و بقیهٔ کاربرهای مجاز) آن را دانلود کنند.
-- ۲) site_prices.requires_scan_body — خدماتی که هنگام ثبت کیس فیلدِ «نوع اسکن‌بادی» لازم دارند
--    (مثل اباتمنت کره‌ای/اروپایی و فیکسچر ایمپلنت). قابل تنظیم از prices.php.

-- ─────────────── ۱) کتابخانهٔ اسکن‌بادی ───────────────

ALTER TABLE `scan_body_types`
  ADD COLUMN IF NOT EXISTS `library_url`  VARCHAR(500) NULL AFTER `active`,
  ADD COLUMN IF NOT EXISTS `library_path` VARCHAR(500) NULL AFTER `library_url`,
  ADD COLUMN IF NOT EXISTS `library_name` VARCHAR(255) NULL AFTER `library_path`,
  ADD COLUMN IF NOT EXISTS `description`  TEXT NULL AFTER `library_name`;

-- ستونِ «نوع اسکن‌بادی» روی خودِ کیس (فقط برای خدماتی که requires_scan_body=1 دارند)
ALTER TABLE `cases`
  ADD COLUMN IF NOT EXISTS `scan_body_type_id` INT NULL AFTER `shade`;

-- ─────────────── ۲) پرچمِ خدمت ───────────────

ALTER TABLE `site_prices`
  ADD COLUMN IF NOT EXISTS `requires_scan_body` TINYINT(1) NOT NULL DEFAULT 0 AFTER `design_required`;

-- خدماتِ فعلی که اباتمنت/فیکسچر هستند (طبق اعلام کاربر: خدمتِ اباتمنت کره‌ای یا اروپایی).
-- با تطبیقِ عنوان (نه شناسه) تا روی دیتابیس‌های مختلف درست کار کند.
UPDATE `site_prices` SET `requires_scan_body` = 1
WHERE `title` LIKE '%اباتمنت%'
   OR `title` LIKE '%ابوتمنت%'
   OR `title` LIKE '%فیکسچر%';
