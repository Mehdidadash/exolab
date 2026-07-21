-- Migration 006: Add parent_id for sub-cases, clinic_id for doctor-clinic hierarchy

SET NAMES utf8mb4;

-- Add parent_id to cases (for sub-cases like custom abutment + abutment crown)
ALTER TABLE `cases`
  ADD COLUMN `parent_id` INT DEFAULT NULL AFTER `id`,
  ADD KEY `idx_cases_parent_id` (`parent_id`),
  ADD CONSTRAINT `fk_cases_parent` FOREIGN KEY (`parent_id`) REFERENCES `cases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- Add clinic_id to users (for clinic → doctor hierarchy)
ALTER TABLE `users`
  ADD COLUMN `clinic_id` INT DEFAULT NULL AFTER `role`,
  ADD KEY `idx_users_clinic_id` (`clinic_id`),
  ADD CONSTRAINT `fk_users_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- Add a new 'clinic' role for clinic accounts
-- (The role already exists in the code as an option in the user form)
