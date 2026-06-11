-- Add address + updated_at to users table for profile persistence
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS address TEXT NULL AFTER name,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
