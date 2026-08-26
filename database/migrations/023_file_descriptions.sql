-- 023_file_descriptions.sql
-- Allow descriptions/notes on uploaded files (case files + standalone user uploads).
ALTER TABLE case_files ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER original_name;
ALTER TABLE user_uploads ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER original_name;
