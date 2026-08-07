-- Migration 008: Add is_designer to users, designer_id to cases

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `is_designer` TINYINT(1) NOT NULL DEFAULT 0 AFTER `clinic_id`,
  ADD KEY `idx_users_is_designer` (`is_designer`);

ALTER TABLE `cases`
  ADD COLUMN `designer_id` INT DEFAULT NULL AFTER `lab_id`,
  ADD KEY `idx_cases_designer_id` (`designer_id`),
  ADD CONSTRAINT `fk_cases_designer` FOREIGN KEY (`designer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
