-- Migration 011: Lab billing system
-- Adds case_type (direction of billing) and lab_price_overrides table

SET NAMES utf8mb4;

-- case_type: 'doctor' = normal doctor case
--            'lab_in'  = case brought by partner lab (we do work, lab pays us) → income
--            'lab_out' = case outsourced to lab (lab does work, we pay lab)  → expense
ALTER TABLE `cases`
  ADD COLUMN `case_type` ENUM('doctor','lab_in','lab_out') NOT NULL DEFAULT 'doctor' AFTER `lab_id`,
  ADD KEY `idx_cases_case_type` (`case_type`);

-- Lab-specific price overrides (used when billing a lab, not the doctor)
CREATE TABLE IF NOT EXISTS `lab_price_overrides` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `lab_id` INT NOT NULL,
  `service_id` INT NOT NULL,
  `custom_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lab_service` (`lab_id`, `service_id`),
  KEY `idx_lpo_lab` (`lab_id`),
  KEY `idx_lpo_service` (`service_id`),
  CONSTRAINT `fk_lpo_lab` FOREIGN KEY (`lab_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lpo_service` FOREIGN KEY (`service_id`) REFERENCES `site_prices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
