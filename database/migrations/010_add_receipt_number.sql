-- Migration 010: Add receipt_number to cases

SET NAMES utf8mb4;

ALTER TABLE `cases`
  ADD COLUMN `receipt_number` VARCHAR(20) DEFAULT NULL AFTER `patient_name`,
  ADD KEY `idx_cases_receipt_number` (`receipt_number`);
