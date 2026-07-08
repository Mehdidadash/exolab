-- Migration 001: create case-related tables and safely convert service prices

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `case_statuses` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_case_statuses_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `case_statuses` (`id`, `name`) VALUES
  (1, 'Registered'),
  (2, 'In Progress'),
  (3, 'Ready'),
  (4, 'Delivered'),
  (5, 'Cancelled')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

CREATE TABLE IF NOT EXISTS `cases` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `doctor_id` INT NOT NULL,
  `service_id` INT NOT NULL,
  `patient_name` VARCHAR(255) DEFAULT NULL,
  `location_type` ENUM('teeth','upper','lower','both') DEFAULT NULL,
  `teeth` VARCHAR(100) DEFAULT NULL,
  `shade` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(15,2) DEFAULT NULL,
  `total_price` DECIMAL(15,2) DEFAULT NULL,
  `received_date` DATE DEFAULT NULL,
  `invoice_id` INT DEFAULT NULL,
  `status_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cases_doctor_id` (`doctor_id`),
  KEY `idx_cases_service_id` (`service_id`),
  KEY `idx_cases_invoice_id` (`invoice_id`),
  KEY `idx_cases_status_id` (`status_id`),
  KEY `idx_cases_received_date` (`received_date`),
  CONSTRAINT `fk_cases_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_cases_service` FOREIGN KEY (`service_id`) REFERENCES `site_prices` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_cases_status` FOREIGN KEY (`status_id`) REFERENCES `case_statuses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `doctor_price_overrides` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `doctor_id` INT NOT NULL,
  `service_id` INT NOT NULL,
  `custom_price` DECIMAL(15,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doctor_price_overrides_doctor_service` (`doctor_id`, `service_id`),
  KEY `idx_doctor_price_overrides_doctor_id` (`doctor_id`),
  KEY `idx_doctor_price_overrides_service_id` (`service_id`),
  CONSTRAINT `fk_doctor_price_overrides_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_doctor_price_overrides_service` FOREIGN KEY (`service_id`) REFERENCES `site_prices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `case_status_history` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `case_id` INT NOT NULL,
  `status_id` INT NOT NULL,
  `changed_by_admin_id` INT DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_case_status_history_case_id` (`case_id`),
  KEY `idx_case_status_history_status_id` (`status_id`),
  KEY `idx_case_status_history_changed_at` (`changed_at`),
  CONSTRAINT `fk_case_status_history_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_case_status_history_status` FOREIGN KEY (`status_id`) REFERENCES `case_statuses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safe migration from VARCHAR to DECIMAL for site_prices.price
ALTER TABLE `site_prices`
  MODIFY COLUMN `price` DECIMAL(15,2) NULL;

UPDATE `site_prices`
SET `price` = CASE
  WHEN `price` IS NULL THEN 0.00
  WHEN TRIM(CAST(`price` AS CHAR)) = '' THEN 0.00
  ELSE CAST(REGEXP_REPLACE(TRIM(CAST(`price` AS CHAR)), '[^0-9.-]', '') AS DECIMAL(15,2))
END;

ALTER TABLE `site_prices`
  MODIFY COLUMN `price` DECIMAL(15,2) NOT NULL DEFAULT 0.00;
