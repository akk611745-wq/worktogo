-- ============================================================================
--  WorkToGo V2 — Commerce Phase 1: Store foundation (dual-store, flexible
--  shop-types, motherboard fit)
--  Migration: 2026_08_22_001_commerce_store_foundation
-- ----------------------------------------------------------------------------
--  PURPOSE (COMMERCE/commerce-02-gap-map.md §2 + §5; founder intent)
--    Lay the clean data-model foundation for the separate Commerce engine so
--    the customer storefront, the commerce admin control-room, the dual-store
--    model, and the native delivery engine all plug in later WITHOUT touching
--    the production-grade order core (OrderService/CartService/ProductService)
--    and WITHOUT re-touching schema for city scoping.
--
--    This migration is ADDITIVE + NULLABLE + BACKFILLED only. It does NOT change
--    the grain or columns the never-touch shopping code reads today, so that
--    code keeps working unchanged; new commerce code will use the new columns.
--
--  WHAT THIS DELIVERS
--    1. `store_types` — the FLEXIBLE, admin-addable shop-type taxonomy. A new
--       shop kind (vegetable seller, kirana, clothing, electronics, pharmacy,
--       anything) is a ROW an admin inserts — ZERO code change, ever. slug/name
--       are varchar (never an enum), so no ALTER is needed to add a type.
--    2. `stores` — the shop itself. Supports the DUAL model in one column
--       (ownership = company | vendor): a company dark-store (own inventory) vs
--       a local vendor/seller store (partner shopkeeper). Every store is
--       city-scoped (city_id) and owned via the SHARED identity (users/vendors)
--       — many independent sellers, each with their own store.
--    3. Alignment of the existing, production-grade commerce tables to the
--       motherboard: `store_id` + `city_id` on products/orders/order_items/
--       inventory/warehouses/deliveries, `city_id` on cart. Product photos use
--       the existing polymorphic `media` table (owner_type='product') — no
--       schema change needed there (documented below).
--    4. RBAC for a COMMERCE TEAM: new store/inventory permissions + a
--       `commerce_admin` role (plus `catalog_manager` / `dispatch_ops` stubs),
--       and re-asserts super_admin's all-access so today's admin is unaffected.
--
--  DUAL-STORE + CITY SCOPING (how discovery works)
--    A customer in city C sees products whose store is active AND in city C.
--    Company dark-stores (ownership='company') are ranked first via stores.priority
--    where they serve the area; where no dark-store covers the area (rural), local
--    vendor stores (ownership='vendor') fill in. All of this is a QUERY over
--    stores.city_id / ownership / priority — no per-type code branches.
--
--  FLEXIBLE SHOP-TYPES (how ANY shop works without code)
--    stores.store_type_id -> store_types.id. store_types is pure data. Product
--    categorisation continues to use the existing data-driven `categories` table
--    (type='product'|'both', hierarchical via parent_id) — also admin-addable.
--    So the taxonomy hierarchy is: store_type (data) -> store -> product ->
--    category (data). Nothing about a shop type is hardcoded.
--
--  MOTHERBOARD FIT (what this connects to — all already in V2)
--    * users/vendors  — shared identity (stores.owner_vendor_id/owner_user_id).
--    * cities/city_id — migration 2026_07_04_001 (this extends city_id to the
--      commerce tables it skipped).
--    * media          — migration 2026_07_04_003 (polymorphic; product/store
--      photos are media rows, no change).
--    * wallet_transactions.reference_type — migration 2026_07_04_004 (the
--      order/booking collision fix; already wired in LedgerEngine — unchanged here).
--    * RBAC           — migration 2026_08_13_001 (this adds the commerce group).
--
--  DEPENDS ON (apply order via migrate.php filename-date order handles this):
--    2026_07_04_001 (cities), 2026_07_04_003 (media), 2026_08_13_001 (rbac).
--
--  SAFETY / IDEMPOTENCY
--    * New tables: CREATE TABLE IF NOT EXISTS (FKs inline; re-run safe).
--    * Alignment: ADD COLUMN IF NOT EXISTS / ADD KEY IF NOT EXISTS; FK adds
--      guarded via information_schema (MariaDB, mirrors migration 001).
--    * Seeds: INSERT ... ON DUPLICATE KEY UPDATE (store_types, permissions,
--      roles) and INSERT IGNORE ... SELECT (join tables, backfills), all keyed
--      by unique/NOT-EXISTS so a re-run is a no-op.
--    * No DROP, no NOT NULL on existing columns, no data loss, no change to the
--      grain the never-touch order code depends on (inventory stays one row per
--      product; store_id there is descriptive only).
--    * InnoDB / utf8mb4. DO NOT run against prod from here — apply on STAGING
--      first, run the VERIFICATION block, then schedule prod.
-- ============================================================================


-- ════════════════════════════════════════════════════════════════════════════
--  1. store_types — flexible, admin-addable shop-type taxonomy
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `store_types` (
  `id`                      bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                    varchar(120) NOT NULL COMMENT 'human label, e.g. "Vegetable Seller"',
  `slug`                    varchar(140) NOT NULL COMMENT 'machine key, e.g. "vegetables-fruits"',
  `description`             varchar(500) DEFAULT NULL,
  `icon_media_id`           bigint(20) UNSIGNED DEFAULT NULL COMMENT 'optional icon in the media table',
  `default_commission_rate` decimal(5,2) DEFAULT NULL COMMENT 'optional per-type commission default',
  `is_active`               tinyint(1) NOT NULL DEFAULT 1,
  `sort_order`              int(11) NOT NULL DEFAULT 0,
  `meta`                    longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at`              datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`              datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_store_types_slug` (`slug`),
  KEY `idx_store_types_active` (`is_active`, `sort_order`),
  CONSTRAINT `fk_store_types_icon_media`
    FOREIGN KEY (`icon_media_id`) REFERENCES `media` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a starter set of common Indian retail shop types. These are DATA, not
-- code — the admin can add/rename/deactivate any of them, and add brand-new
-- types, from the panel. Idempotent via unique slug.
INSERT INTO `store_types` (`slug`, `name`, `description`, `sort_order`) VALUES
  ('grocery-kirana',    'Grocery / Kirana',      'Everyday grocery and kirana provisions',            10),
  ('vegetables-fruits', 'Vegetables & Fruits',   'Fresh vegetables and fruits',                       20),
  ('dairy-bakery',      'Dairy & Bakery',        'Milk, dairy, bread and bakery',                     30),
  ('meat-fish',         'Meat & Fish',           'Fresh meat, poultry and fish',                      40),
  ('pharmacy-wellness', 'Pharmacy & Wellness',   'Medicines, wellness and personal care',             50),
  ('clothing-apparel',  'Clothing & Apparel',    'Clothes, footwear and accessories',                 60),
  ('electronics-mobile','Electronics & Mobile',  'Electronics, mobiles and accessories',              70),
  ('home-kitchen',      'Home & Kitchen',        'Home, kitchen and household goods',                 80),
  ('stationery-books',  'Stationery & Books',    'Stationery, books and supplies',                    90),
  ('beauty-cosmetics',  'Beauty & Cosmetics',    'Beauty, cosmetics and grooming',                   100),
  ('hardware-tools',    'Hardware & Tools',      'Hardware, tools and building supplies',            110),
  ('food-restaurant',   'Restaurant / Food',     'Prepared food and restaurant items',               120)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`);


-- ════════════════════════════════════════════════════════════════════════════
--  2. stores — the shop (dual model + flexible type + city-scoped + shared id)
-- ----------------------------------------------------------------------------
--  ownership is the SINGLE source of truth for the dual model:
--    'company' = company dark-store (own inventory; owner_vendor_id NULL)
--    'vendor'  = local vendor/seller store (owner_vendor_id set)
--  (i.e. "is_company_store" === ownership='company' — kept as one column, no
--   redundant boolean, to avoid the status-vs-flag drift audit-02 warned about.)
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `stores` (
  `id`                bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              char(36) NOT NULL DEFAULT uuid(),

  -- ── Flexible type + dual-model ownership ──────────────────────────────────
  `store_type_id`     bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK store_types (data-driven shop kind)',
  `ownership`         enum('company','vendor') NOT NULL DEFAULT 'vendor'
                        COMMENT 'company = dark-store (own inventory); vendor = local seller store',

  -- ── Shared-identity owner (many independent sellers) ──────────────────────
  `owner_vendor_id`   bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK vendors; NULL for company dark-stores',
  `owner_user_id`     bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK users; operator/contact (future direct-seller/API)',

  -- ── City scope (customer sees stores in their area) ───────────────────────
  `city_id`           bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK cities',

  -- ── Identity / presentation (photos via the media table) ──────────────────
  `name`              varchar(200) NOT NULL,
  `slug`              varchar(230) NOT NULL,
  `description`       text DEFAULT NULL,
  `logo_media_id`     bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK media',
  `banner_media_id`   bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK media',

  -- ── Commercial config ─────────────────────────────────────────────────────
  `status`            enum('draft','pending','active','suspended','closed') NOT NULL DEFAULT 'pending',
  `commission_rate`   decimal(5,2) DEFAULT NULL COMMENT 'per-store override; else store_type/vendor default',
  `min_order_amount`  decimal(12,2) DEFAULT NULL,
  `service_radius_km` decimal(6,2)  DEFAULT NULL COMMENT 'delivery/coverage radius for this store',

  -- ── Geo + address (near-me + delivery pickup) ─────────────────────────────
  `lat`               decimal(10,8) DEFAULT NULL,
  `lng`               decimal(11,8) DEFAULT NULL,
  `address_line`      varchar(500) DEFAULT NULL,
  `locality`          varchar(150) DEFAULT NULL,
  `pincode`           varchar(12)  DEFAULT NULL,

  -- ── Ranking + hours ───────────────────────────────────────────────────────
  `priority`          int(11) NOT NULL DEFAULT 0 COMMENT 'higher shows first; company dark-stores can be boosted',
  `is_featured`       tinyint(1) NOT NULL DEFAULT 0,
  `opens_at`          time DEFAULT NULL,
  `closes_at`         time DEFAULT NULL,

  -- ── Extensible metadata + timestamps + soft delete ────────────────────────
  `meta`              longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at`        datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`        datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at`        datetime DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_stores_slug` (`slug`),
  KEY `idx_stores_city_status`      (`city_id`, `status`),
  KEY `idx_stores_city_own_pri`     (`city_id`, `ownership`, `status`, `priority`),
  KEY `idx_stores_type`             (`store_type_id`),
  KEY `idx_stores_owner_vendor`     (`owner_vendor_id`),
  KEY `idx_stores_owner_user`       (`owner_user_id`),
  KEY `idx_stores_status`           (`status`),
  KEY `idx_stores_geo`              (`lat`, `lng`),
  CONSTRAINT `fk_stores_type`
    FOREIGN KEY (`store_type_id`)   REFERENCES `store_types` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stores_vendor`
    FOREIGN KEY (`owner_vendor_id`) REFERENCES `vendors` (`id`)     ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stores_user`
    FOREIGN KEY (`owner_user_id`)   REFERENCES `users` (`id`)       ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stores_city`
    FOREIGN KEY (`city_id`)         REFERENCES `cities` (`id`)      ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stores_logo`
    FOREIGN KEY (`logo_media_id`)   REFERENCES `media` (`id`)       ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stores_banner`
    FOREIGN KEY (`banner_media_id`) REFERENCES `media` (`id`)       ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════════════════
--  3. Align existing commerce tables to store_id + city_id (additive, nullable)
-- ----------------------------------------------------------------------------
--  These columns are NULLABLE and are NOT read by the current never-touch
--  shopping code, so adding them changes nothing today. New commerce code
--  (customer UI, admin, delivery) will populate and read them.
-- ════════════════════════════════════════════════════════════════════════════

-- products: which store sells it + which city (denormalised for fast discovery)
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `store_id` bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `vendor_id`,
  ADD COLUMN IF NOT EXISTS `city_id`  bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `store_id`,
  ADD KEY IF NOT EXISTS `idx_products_store`      (`store_id`),
  ADD KEY IF NOT EXISTS `idx_products_city`       (`city_id`),
  ADD KEY IF NOT EXISTS `idx_products_city_status`(`city_id`, `status`, `deleted_at`);

-- orders: fulfilling store (on vendor sub-orders) + city
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `store_id` bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `vendor_id`,
  ADD COLUMN IF NOT EXISTS `city_id`  bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `store_id`,
  ADD KEY IF NOT EXISTS `idx_orders_store` (`store_id`),
  ADD KEY IF NOT EXISTS `idx_orders_city`  (`city_id`);

-- order_items: store snapshot + city (city-scoped reporting without a join)
ALTER TABLE `order_items`
  ADD COLUMN IF NOT EXISTS `store_id` bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `vendor_id`,
  ADD COLUMN IF NOT EXISTS `city_id`  bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `store_id`,
  ADD KEY IF NOT EXISTS `idx_order_items_store` (`store_id`),
  ADD KEY IF NOT EXISTS `idx_order_items_city`  (`city_id`);

-- inventory: descriptive store/city (grain unchanged — still one row per product
--            so OrderService's WHERE product_id = ? keeps working exactly as-is;
--            per-store stock is a documented FUTURE extension, not done here).
ALTER TABLE `inventory`
  ADD COLUMN IF NOT EXISTS `store_id` bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `warehouse_id`,
  ADD COLUMN IF NOT EXISTS `city_id`  bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `store_id`,
  ADD KEY IF NOT EXISTS `idx_inventory_store` (`store_id`),
  ADD KEY IF NOT EXISTS `idx_inventory_city`  (`city_id`);

-- warehouses: which store + city (company dark-store stock locations)
ALTER TABLE `warehouses`
  ADD COLUMN IF NOT EXISTS `store_id` bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `vendor_id`,
  ADD COLUMN IF NOT EXISTS `city_id`  bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `store_id`,
  ADD KEY IF NOT EXISTS `idx_warehouses_store` (`store_id`),
  ADD KEY IF NOT EXISTS `idx_warehouses_city`  (`city_id`);

-- cart: city scope (transient; kept for city-correct availability checks)
ALTER TABLE `cart`
  ADD COLUMN IF NOT EXISTS `city_id` bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `product_id`,
  ADD KEY IF NOT EXISTS `idx_cart_city` (`city_id`);

-- deliveries: pickup store + city (preps the native delivery engine, no 2nd migration)
ALTER TABLE `deliveries`
  ADD COLUMN IF NOT EXISTS `store_id` bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `order_id`,
  ADD COLUMN IF NOT EXISTS `city_id`  bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `store_id`,
  ADD KEY IF NOT EXISTS `idx_deliveries_store` (`store_id`),
  ADD KEY IF NOT EXISTS `idx_deliveries_city`  (`city_id`);


-- ════════════════════════════════════════════════════════════════════════════
--  4. FK constraints on the aligned tables (guarded via information_schema)
-- ----------------------------------------------------------------------------
--  ON DELETE SET NULL everywhere: deactivate a store/city (status/is_active=0),
--  never hard-delete; if one ever is deleted, dependent rows keep their data.
-- ════════════════════════════════════════════════════════════════════════════

-- Helper pattern (repeated per FK): add only if a constraint of that name is absent.

-- products.store_id -> stores.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='products' AND CONSTRAINT_NAME='fk_products_store');
SET @sql := IF(@fk=0, 'ALTER TABLE `products` ADD CONSTRAINT `fk_products_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- products.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='products' AND CONSTRAINT_NAME='fk_products_city');
SET @sql := IF(@fk=0, 'ALTER TABLE `products` ADD CONSTRAINT `fk_products_city` FOREIGN KEY (`city_id`) REFERENCES `cities`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- orders.store_id -> stores.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='orders' AND CONSTRAINT_NAME='fk_orders_store');
SET @sql := IF(@fk=0, 'ALTER TABLE `orders` ADD CONSTRAINT `fk_orders_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- orders.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='orders' AND CONSTRAINT_NAME='fk_orders_city');
SET @sql := IF(@fk=0, 'ALTER TABLE `orders` ADD CONSTRAINT `fk_orders_city` FOREIGN KEY (`city_id`) REFERENCES `cities`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- order_items.store_id -> stores.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='order_items' AND CONSTRAINT_NAME='fk_order_items_store');
SET @sql := IF(@fk=0, 'ALTER TABLE `order_items` ADD CONSTRAINT `fk_order_items_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- order_items.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='order_items' AND CONSTRAINT_NAME='fk_order_items_city');
SET @sql := IF(@fk=0, 'ALTER TABLE `order_items` ADD CONSTRAINT `fk_order_items_city` FOREIGN KEY (`city_id`) REFERENCES `cities`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- inventory.store_id -> stores.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='inventory' AND CONSTRAINT_NAME='fk_inventory_store');
SET @sql := IF(@fk=0, 'ALTER TABLE `inventory` ADD CONSTRAINT `fk_inventory_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- inventory.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='inventory' AND CONSTRAINT_NAME='fk_inventory_city');
SET @sql := IF(@fk=0, 'ALTER TABLE `inventory` ADD CONSTRAINT `fk_inventory_city` FOREIGN KEY (`city_id`) REFERENCES `cities`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- warehouses.store_id -> stores.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='warehouses' AND CONSTRAINT_NAME='fk_warehouses_store');
SET @sql := IF(@fk=0, 'ALTER TABLE `warehouses` ADD CONSTRAINT `fk_warehouses_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- warehouses.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='warehouses' AND CONSTRAINT_NAME='fk_warehouses_city');
SET @sql := IF(@fk=0, 'ALTER TABLE `warehouses` ADD CONSTRAINT `fk_warehouses_city` FOREIGN KEY (`city_id`) REFERENCES `cities`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- cart.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='cart' AND CONSTRAINT_NAME='fk_cart_city');
SET @sql := IF(@fk=0, 'ALTER TABLE `cart` ADD CONSTRAINT `fk_cart_city` FOREIGN KEY (`city_id`) REFERENCES `cities`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- deliveries.store_id -> stores.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='deliveries' AND CONSTRAINT_NAME='fk_deliveries_store');
SET @sql := IF(@fk=0, 'ALTER TABLE `deliveries` ADD CONSTRAINT `fk_deliveries_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- deliveries.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='deliveries' AND CONSTRAINT_NAME='fk_deliveries_city');
SET @sql := IF(@fk=0, 'ALTER TABLE `deliveries` ADD CONSTRAINT `fk_deliveries_city` FOREIGN KEY (`city_id`) REFERENCES `cities`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ════════════════════════════════════════════════════════════════════════════
--  5. Backfill — make existing data consistent with the new model (idempotent)
-- ----------------------------------------------------------------------------
--  The platform is single-city today (Haldwani). Existing shopping vendors get
--  one vendor store each; their products link to it. Everything gets city_id.
-- ════════════════════════════════════════════════════════════════════════════

SET @haldwani := (SELECT `id` FROM `cities` WHERE `slug` = 'haldwani' LIMIT 1);

-- 5a. One vendor store per existing shopping vendor (guarded by NOT EXISTS).
INSERT INTO `stores` (`ownership`, `owner_vendor_id`, `owner_user_id`, `city_id`, `name`, `slug`, `status`, `created_at`, `updated_at`)
SELECT 'vendor', v.`id`, v.`user_id`,
       COALESCE(v.`city_id`, @haldwani),
       v.`business_name`,
       CONCAT(v.`slug`, '-store'),
       CASE v.`status` WHEN 'active' THEN 'active' ELSE 'pending' END,
       NOW(), NOW()
FROM `vendors` v
WHERE v.`type` = 'shopping'
  AND NOT EXISTS (SELECT 1 FROM `stores` s WHERE s.`owner_vendor_id` = v.`id`);

-- 5b. Link existing products to their vendor's store + set city.
UPDATE `products` p
  JOIN `vendors` v ON v.`id` = p.`vendor_id` AND v.`type` = 'shopping'
  JOIN `stores`  s ON s.`owner_vendor_id` = v.`id` AND s.`ownership` = 'vendor'
   SET p.`store_id` = s.`id`,
       p.`city_id`  = COALESCE(p.`city_id`, v.`city_id`, @haldwani)
 WHERE p.`store_id` IS NULL;

-- 5c. Any remaining products (no shopping vendor / no store yet) → Haldwani city.
UPDATE `products` SET `city_id` = @haldwani WHERE `city_id` IS NULL;

-- 5d. Orders / order_items: city = Haldwani; store from the (sub-order) vendor.
UPDATE `orders` o
  JOIN `stores` s ON s.`owner_vendor_id` = o.`vendor_id` AND s.`ownership` = 'vendor'
   SET o.`store_id` = s.`id`
 WHERE o.`store_id` IS NULL AND o.`vendor_id` IS NOT NULL;
UPDATE `orders` SET `city_id` = @haldwani WHERE `city_id` IS NULL;

UPDATE `order_items` oi
  JOIN `stores` s ON s.`owner_vendor_id` = oi.`vendor_id` AND s.`ownership` = 'vendor'
   SET oi.`store_id` = s.`id`
 WHERE oi.`store_id` IS NULL AND oi.`vendor_id` IS NOT NULL;
UPDATE `order_items` oi JOIN `orders` o ON o.`id` = oi.`order_id`
   SET oi.`city_id` = o.`city_id`
 WHERE oi.`city_id` IS NULL;

-- 5e. Inventory: store/city from the product it belongs to.
UPDATE `inventory` i
  JOIN `products` p ON p.`id` = i.`product_id`
   SET i.`store_id` = COALESCE(i.`store_id`, p.`store_id`),
       i.`city_id`  = COALESCE(i.`city_id`,  p.`city_id`, @haldwani)
 WHERE i.`store_id` IS NULL OR i.`city_id` IS NULL;

-- 5f. Warehouses: store from the vendor's store; city Haldwani.
UPDATE `warehouses` w
  JOIN `stores` s ON s.`owner_vendor_id` = w.`vendor_id` AND s.`ownership` = 'vendor'
   SET w.`store_id` = s.`id`
 WHERE w.`store_id` IS NULL AND w.`vendor_id` IS NOT NULL;
UPDATE `warehouses` SET `city_id` = @haldwani WHERE `city_id` IS NULL;

-- 5g. Cart + deliveries: city Haldwani; delivery store from its order.
UPDATE `cart` SET `city_id` = @haldwani WHERE `city_id` IS NULL;
UPDATE `deliveries` d JOIN `orders` o ON o.`id` = d.`order_id`
   SET d.`store_id` = COALESCE(d.`store_id`, o.`store_id`),
       d.`city_id`  = COALESCE(d.`city_id`,  o.`city_id`, @haldwani)
 WHERE d.`store_id` IS NULL OR d.`city_id` IS NULL;


-- ════════════════════════════════════════════════════════════════════════════
--  6. RBAC — commerce team permissions + roles (extends migration 2026_08_13_001)
-- ----------------------------------------------------------------------------
--  Reuses the existing engine-tagged permission catalog. Adds the store/inventory
--  capabilities that the shopping seed lacked, plus a commerce team role bundle.
-- ════════════════════════════════════════════════════════════════════════════

-- 6a. New granular permissions (idempotent by unique perm_key).
INSERT INTO `permissions` (`perm_key`, `label`, `engine`, `group_label`) VALUES
  ('stores.view',        'View stores',                         'shopping', 'Stores'),
  ('stores.manage',      'Create / edit stores (company + vendor)', 'shopping', 'Stores'),
  ('store_types.manage', 'Add / edit shop types',               'shopping', 'Stores'),
  ('inventory.view',     'View inventory / stock',              'shopping', 'Inventory'),
  ('inventory.manage',   'Adjust stock / receive inventory',    'shopping', 'Inventory')
ON DUPLICATE KEY UPDATE
  `label` = VALUES(`label`), `engine` = VALUES(`engine`), `group_label` = VALUES(`group_label`);

-- 6b. Commerce team roles (data-only; founder can edit membership/permissions later).
INSERT INTO `roles` (`slug`, `name`, `description`, `is_system`, `is_active`) VALUES
  ('commerce_admin',  'Commerce Admin',   'Runs the commerce engine end-to-end: stores, catalog, inventory, orders, delivery, revenue.', 0, 1),
  ('catalog_manager', 'Catalog Manager',  'Manages stores, products and inventory (no delivery/finance).',                                0, 1),
  ('dispatch_ops',    'Dispatch Ops',     'Delivery operations: dispatch, assignment and tracking.',                                       0, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`);

-- 6c. Re-assert super_admin → EVERY permission (so the NEW perms above are granted
--     to today's admin too; matches migration 2026_08_13_001 §7).
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p WHERE r.`slug` = 'super_admin';

-- 6d. commerce_admin → full commerce (shopping catalog/orders/stores/inventory + delivery + money view).
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'commerce_admin' AND p.`perm_key` IN (
  'dashboard.view',
  'stores.view','stores.manage','store_types.manage',
  'products.view','products.manage',
  'inventory.view','inventory.manage',
  'orders.view','orders.manage',
  'delivery.view','delivery.manage',
  'revenue.view','refunds.view','refunds.manage'
);

-- 6e. catalog_manager → stores + products + inventory only.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'catalog_manager' AND p.`perm_key` IN (
  'dashboard.view','stores.view','stores.manage','store_types.manage',
  'products.view','products.manage','inventory.view','inventory.manage'
);

-- 6f. dispatch_ops → delivery operations + read orders.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'dispatch_ops' AND p.`perm_key` IN (
  'dashboard.view','orders.view','delivery.view','delivery.manage'
);


-- ============================================================================
--  VERIFICATION (run after applying on staging; expected results noted)
-- ----------------------------------------------------------------------------
--  -- New tables:
--  SHOW TABLES LIKE 'store_types';                                  -- 1 row
--  SHOW TABLES LIKE 'stores';                                       -- 1 row
--  SELECT COUNT(*) FROM store_types;                                -- >= 12 (seeded, editable)
--
--  -- Alignment columns present:
--  SHOW COLUMNS FROM products   LIKE 'store_id';                    -- 1 row, Null=YES
--  SHOW COLUMNS FROM products   LIKE 'city_id';                     -- 1 row
--  SHOW COLUMNS FROM orders     LIKE 'store_id';                    -- 1 row
--  SHOW COLUMNS FROM deliveries LIKE 'city_id';                     -- 1 row
--
--  -- Backfill consistency (single-city today):
--  SELECT COUNT(*) FROM products    WHERE city_id IS NULL;          -- expect 0
--  SELECT COUNT(*) FROM orders      WHERE city_id IS NULL;          -- expect 0
--  SELECT COUNT(*) FROM order_items WHERE city_id IS NULL;          -- expect 0
--  -- Every shopping vendor has exactly one store; their products are linked:
--  SELECT v.id FROM vendors v WHERE v.type='shopping'
--    AND NOT EXISTS (SELECT 1 FROM stores s WHERE s.owner_vendor_id=v.id); -- expect 0 rows
--  SELECT COUNT(*) FROM products WHERE store_id IS NULL
--    AND vendor_id IN (SELECT id FROM vendors WHERE type='shopping');      -- expect 0
--
--  -- FKs present (17 new commerce FKs across the aligned tables + 2 new tables):
--  SELECT CONSTRAINT_NAME, TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS
--    WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY'
--      AND CONSTRAINT_NAME LIKE 'fk_%store%' OR CONSTRAINT_NAME LIKE 'fk_%_city';
--
--  -- RBAC: new perms + commerce role + super_admin still all-access:
--  SELECT COUNT(*) FROM permissions WHERE perm_key IN
--    ('stores.view','stores.manage','store_types.manage','inventory.view','inventory.manage'); -- 5
--  SELECT (SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id=rp.role_id
--            WHERE r.slug='super_admin') = (SELECT COUNT(*) FROM permissions);  -- expect 1 (still all)
--  SELECT p.perm_key FROM role_permissions rp
--    JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id
--    WHERE r.slug='commerce_admin' ORDER BY p.perm_key;              -- expect the 15 commerce perms
-- ============================================================================
