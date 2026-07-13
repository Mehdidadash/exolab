-- Migration 003: No-op (FK constraints already reference `users` table correctly)
-- All foreign keys were verified to reference `users` table instead of `doctors`.
-- This migration exists only as a placeholder for versioning consistency.
SELECT 'Migration 003: No action needed - all FKs already reference users table' AS status;
