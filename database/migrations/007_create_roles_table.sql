-- Migration 007: Create roles table with permissions

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `permissions` TEXT DEFAULT NULL COMMENT 'JSON array of permission keys',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default roles (based on current hardcoded permissions in auth.php)
INSERT INTO `roles` (`name`, `label`, `permissions`) VALUES
('admin', 'مدیر سیستم', '["*"]'),
('doctor', 'دندانپزشک', '["view_own_cases","view_own_invoices","view_own_payments","view_case_files"]'),
('staff', 'کارمند', '["view_all_cases","create_cases","edit_cases","edit_case_status","upload_files"]'),
('secretary', 'منشی', '["view_all_cases","create_cases","edit_cases","edit_case_status","upload_files","delete_files"]'),
('designer', 'طراح', '["view_all_cases","upload_design_files","edit_case_status"]'),
('technician', 'تکنیسین', '["view_all_cases","update_case_status","view_invoices"]'),
('operator', 'اپراتور دستگاه', '["view_all_cases","update_case_status"]'),
('powder', 'پودرگذار', '["view_all_cases"]'),
('courier', 'پیک', '["view_all_cases"]'),
('finance', 'امور مالی', '["view_all_cases","view_invoices","view_payments"]'),
('outsource_lab', 'لابراتوار برونسپاری', '["view_assigned_cases","view_case_files"]'),
('customer_lab', 'لابراتوار مشتری', '["view_assigned_cases","view_case_files"]'),
('partner_lab', 'لابراتوار همکار', '["view_assigned_cases","view_case_files"]'),
('clinic', 'کلینیک', '["view_clinic_cases","view_clinic_invoices","view_clinic_payments","view_case_files"]')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `permissions` = VALUES(`permissions`);
