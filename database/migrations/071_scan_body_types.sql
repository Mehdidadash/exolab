-- 071_scan_body_types.sql
-- انواع «اسکن‌بادی» (فیکسچر ایمپلنت): وقتی نوبتِ اسکن نیاز به اسکن‌بادی دارد،
-- باید نوع/سیستم اسکن‌بادی (مثلاً اویتا / انی‌ریج) هم انتخاب شود.
--
-- • جدولِ کاتالوگِ انواع اسکن‌بادی (افزودن/ویرایش/حذف از صفحهٔ مدیریت).
-- • ستونِ scan_body_type_id روی scan_appointments (فقط وقتی needs_scan_body=1 معنی دارد).

CREATE TABLE IF NOT EXISTS `scan_body_types` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scan_body_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- دادهٔ اولیه: دو نوع فعلی
INSERT INTO `scan_body_types` (`name`, `sort_order`, `active`) VALUES
  ('اویتا', 1, 1),
  ('انی ریج', 2, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ستونِ نوع اسکن‌بادی روی نوبت‌ها
ALTER TABLE `scan_appointments`
  ADD COLUMN IF NOT EXISTS `scan_body_type_id` INT NULL AFTER `needs_scan_body`;
