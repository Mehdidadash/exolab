-- Migration 005: Add bank_account_id to doctor_invoices for PDF display
SET NAMES utf8mb4;

ALTER TABLE `doctor_invoices` 
  ADD COLUMN `bank_account_id` INT DEFAULT NULL AFTER `notes`,
  ADD KEY `fk_invoice_bank_account` (`bank_account_id`);
