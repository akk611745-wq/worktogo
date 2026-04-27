-- Fix users table phone column to be nullable
ALTER TABLE users MODIFY COLUMN phone VARCHAR(20) NULL;
