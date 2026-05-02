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
    'ALTER TABLE `vendors` CHANGE COLUMN `vendor_type` `type` ENUM(''service'', ''shopping'') NOT NULL',
    'SELECT ''vendors.type already standardized or vendors.vendor_type missing'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
