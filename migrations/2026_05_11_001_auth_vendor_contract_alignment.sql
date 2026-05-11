-- Controlled production stabilization: auth identity normalization + vendor schema alignment
-- Safe to review before execution. Do not run until deploy approval.

-- Normalize identity sentinels before tightening application behavior.
UPDATE users SET phone = NULL WHERE phone = '';
UPDATE users SET email = NULL WHERE email = '';

-- Ensure nullable identity columns for email-only and guest auth flows.
ALTER TABLE users MODIFY COLUMN phone VARCHAR(20) NULL;
ALTER TABLE users MODIFY COLUMN email VARCHAR(191) NULL;

-- Align vendors schema to production contract: vendors.type.
SET @has_vendor_type := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vendors'
      AND COLUMN_NAME = 'vendor_type'
);

SET @has_type := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vendors'
      AND COLUMN_NAME = 'type'
);

SET @sql := IF(
    @has_vendor_type > 0 AND @has_type = 0,
    'ALTER TABLE vendors CHANGE COLUMN vendor_type type ENUM(''service'', ''shopping'') NOT NULL',
    'SELECT ''vendors.type already aligned or vendors.vendor_type absent'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

