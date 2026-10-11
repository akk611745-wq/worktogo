-- ============================================================================
--  WorkToGo Commerce — S11: RBAC SEATS + commerce permission catalog + audit
--  Migration: 2026_10_06_011_commerce_rbac_seats
-- ----------------------------------------------------------------------------
--  PURPOSE (commerce-system-blueprint §R; FINAL scope — founder-free seats):
--    The RBAC engine already exists (migrations/2026_08_13_001_rbac_foundation:
--    roles / permissions / role_permissions / user_roles + core/helpers/Permission
--    + AuthMiddleware::requirePermission). It was dormant for commerce because the
--    ops/admin backends (S7 OpsConsole, S8 AdminControlRoom, S9 settlement/returns)
--    had no HTTP surface and no commerce permission keys. This migration ACTIVATES
--    RBAC for commerce by seeding:
--      1. the commerce permission catalog (engine='commerce'),
--      2. five SEATS (roles): ops / finance / seller-ops / delivery-ops /
--         unit-operator — each granted a least-privilege starter set,
--      3. super_admin → every new commerce permission (belt-and-braces; the helper
--         also treats super_admin / legacy users.role='admin' as all-access),
--      4. a UNIFORM audit trail table the thin HTTP layer writes for every
--         privileged commerce action (who / seat / perm / action / target / outcome).
--
--  SAFETY / IDEMPOTENCY
--    * CREATE TABLE IF NOT EXISTS; INSERT … ON DUPLICATE KEY / INSERT IGNORE on the
--      unique slug / perm_key / (role,permission) keys — re-run safe, never clobbers
--      an admin-edited grant.  ADDITIVE only; touches no existing row's data.
--    * 10.4-safe: no COMMENT-after-CHECK, no CHECK constraints.
-- ============================================================================

-- ── 1. Commerce permission catalog (engine-tagged, dot-namespaced) ──────────
INSERT INTO `permissions` (`perm_key`, `label`, `engine`, `group_label`) VALUES
  ('commerce.ops.view',       'View commerce ops console',             'commerce', 'Commerce Ops'),
  ('commerce.ops.manage',     'Act on commerce ops tickets / holds',   'commerce', 'Commerce Ops'),
  ('commerce.order.override',  'Force order status / cancel (override)','commerce', 'Commerce Ops'),
  ('commerce.delivery.manage','Reassign rider / delivery ops',         'commerce', 'Commerce Delivery'),
  ('commerce.returns.manage', 'Approve / receive / refund returns',    'commerce', 'Commerce Returns'),
  ('commerce.config.view',    'View control-room config + audit',      'commerce', 'Commerce Admin'),
  ('commerce.config.manage',  'Change control-room config',            'commerce', 'Commerce Admin'),
  ('commerce.killswitch',     'Pause / resume (kill-switches)',        'commerce', 'Commerce Admin'),
  ('commerce.seller.manage',  'Seller-ops: pause / resume a seller',   'commerce', 'Commerce Seller'),
  ('commerce.finance.view',   'View money dashboard / accounting',     'commerce', 'Commerce Finance'),
  ('commerce.payout.manage',  'Run / mark payouts, settle balances',   'commerce', 'Commerce Finance')
ON DUPLICATE KEY UPDATE
  `label` = VALUES(`label`), `engine` = VALUES(`engine`), `group_label` = VALUES(`group_label`);

-- ── 2. Seats (roles) — data-only; the founder assigns users later ───────────
INSERT INTO `roles` (`slug`, `name`, `description`, `is_system`, `is_active`) VALUES
  ('commerce_ops',          'Commerce Ops',        'Runs the commerce exception queue: tickets, holds, cancels, returns.', 0, 1),
  ('commerce_finance',      'Commerce Finance',    'Commerce money side: payouts, settlement, refunds, dashboards.',       0, 1),
  ('commerce_seller_ops',   'Commerce Seller Ops', 'Manages sellers: availability / pause-resume, seller health.',         0, 1),
  ('commerce_delivery_ops', 'Commerce Delivery Ops','Delivery exceptions: rider reassignment and delivery ops.',           0, 1),
  ('commerce_unit_operator','Commerce Unit Operator','Runs one Unit (category-scoped): its config + kill-switch.',         0, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `is_active` = VALUES(`is_active`);

-- ── 3. super_admin → every commerce permission (explicit; helper also bypasses) ─
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'super_admin' AND p.`perm_key` LIKE 'commerce.%';

-- ── 4. Seat → least-privilege starter permission sets (founder can edit later) ─
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'commerce_ops' AND p.`perm_key` IN (
  'commerce.ops.view','commerce.ops.manage','commerce.returns.manage','commerce.order.override','commerce.config.view'
);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'commerce_finance' AND p.`perm_key` IN (
  'commerce.finance.view','commerce.payout.manage','commerce.returns.manage','commerce.config.view'
);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'commerce_seller_ops' AND p.`perm_key` IN (
  'commerce.ops.view','commerce.seller.manage','commerce.config.view'
);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'commerce_delivery_ops' AND p.`perm_key` IN (
  'commerce.ops.view','commerce.delivery.manage'
);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'commerce_unit_operator' AND p.`perm_key` IN (
  'commerce.ops.view','commerce.config.view','commerce.killswitch'
);

-- ── 5. Uniform commerce admin/ops audit trail (every privileged HTTP action) ─
CREATE TABLE IF NOT EXISTS `commerce_admin_audit` (
  `id`             bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id`  int          NULL,
  `actor_role`     varchar(64)  NULL,
  `perm_key`       varchar(80)  NULL,
  `action`         varchar(80)  NOT NULL,
  `http_method`    varchar(8)   NULL,
  `route`          varchar(191) NULL,
  `target_type`    varchar(40)  NULL,
  `target_id`      varchar(64)  NULL,
  `request_summary` text        NULL,
  `outcome`        varchar(16)  NOT NULL DEFAULT 'ok',
  `created_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_caa_actor` (`actor_user_id`),
  KEY `idx_caa_action` (`action`),
  KEY `idx_caa_created` (`created_at`)
) ENGINE=InnoDB;
