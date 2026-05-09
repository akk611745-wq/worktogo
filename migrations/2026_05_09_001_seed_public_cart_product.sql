-- Seed one active, publicly visible shopping product for cart-flow retesting.
-- Idempotent: keyed by deterministic user phone/email, vendor slug, product slug, and product sku.

SET @seed_phone := '+910000000002';
SET @seed_email := 'shopping-seed@worktogo.in';
SET @vendor_slug := 'admin-shopping-vendor';
SET @product_slug := 'wtg-test-visible-product';
SET @product_sku := 'WTG-TEST-VISIBLE-001';

INSERT INTO users (name, phone, email, role, status, auth_type, created_at, updated_at)
VALUES ('WorkToGo Shopping Seed', @seed_phone, @seed_email, 'vendor_shopping', 'active', 'email', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  role = 'vendor_shopping',
  status = 'active',
  auth_type = 'email',
  deleted_at = NULL,
  updated_at = NOW();

SET @seed_user_id := (
  SELECT id
  FROM users
  WHERE phone = @seed_phone OR email = @seed_email
  ORDER BY id
  LIMIT 1
);

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

SET @vendor_sql := CASE
  WHEN @has_type > 0 THEN
    'INSERT INTO vendors (user_id, business_name, slug, `type`, status, created_at, updated_at)
     VALUES (?, ''WorkToGo Admin Store'', ?, ''shopping'', ''active'', NOW(), NOW())
     ON DUPLICATE KEY UPDATE
       business_name = VALUES(business_name),
       `type` = ''shopping'',
       status = ''active'',
       updated_at = NOW()'
  WHEN @has_vendor_type > 0 THEN
    'INSERT INTO vendors (user_id, business_name, slug, vendor_type, status, created_at, updated_at)
     VALUES (?, ''WorkToGo Admin Store'', ?, ''shopping'', ''active'', NOW(), NOW())
     ON DUPLICATE KEY UPDATE
       business_name = VALUES(business_name),
       vendor_type = ''shopping'',
       status = ''active'',
       updated_at = NOW()'
  ELSE
    'SELECT ''vendors type column not found'' AS message'
END;

PREPARE vendor_stmt FROM @vendor_sql;
EXECUTE vendor_stmt USING @seed_user_id, @vendor_slug;
DEALLOCATE PREPARE vendor_stmt;

SET @vendor_id := (
  SELECT id
  FROM vendors
  WHERE slug = @vendor_slug
  LIMIT 1
);

INSERT INTO products (
  vendor_id,
  category_id,
  name,
  slug,
  description,
  short_desc,
  sku,
  price,
  sale_price,
  cost_price,
  images,
  images_json,
  attributes,
  tags,
  weight,
  unit,
  status,
  is_featured,
  rating,
  total_reviews,
  total_sold,
  meta,
  created_at,
  updated_at,
  deleted_at
)
VALUES (
  @vendor_id,
  NULL,
  'WTG Test Visible Product',
  @product_slug,
  'Seed product for cart flow retest',
  'Seed product for cart testing',
  @product_sku,
  199.00,
  NULL,
  NULL,
  '[]',
  NULL,
  NULL,
  NULL,
  NULL,
  'piece',
  'active',
  1,
  0.00,
  0,
  0,
  NULL,
  NOW(),
  NOW(),
  NULL
)
ON DUPLICATE KEY UPDATE
  vendor_id = VALUES(vendor_id),
  name = VALUES(name),
  description = VALUES(description),
  short_desc = VALUES(short_desc),
  price = VALUES(price),
  sale_price = NULL,
  cost_price = NULL,
  images = '[]',
  images_json = NULL,
  attributes = NULL,
  tags = NULL,
  weight = NULL,
  unit = VALUES(unit),
  status = 'active',
  is_featured = 1,
  deleted_at = NULL,
  updated_at = NOW();

SET @product_id := (
  SELECT id
  FROM products
  WHERE slug = @product_slug OR sku = @product_sku
  ORDER BY id
  LIMIT 1
);

INSERT INTO inventory (
  product_id,
  warehouse_id,
  quantity,
  reserved,
  low_stock_alert,
  track_inventory,
  allow_backorder,
  updated_at
)
VALUES (@product_id, NULL, 25, 0, 5, 1, 0, NOW())
ON DUPLICATE KEY UPDATE
  quantity = 25,
  reserved = 0,
  low_stock_alert = 5,
  track_inventory = 1,
  allow_backorder = 0,
  updated_at = NOW();
