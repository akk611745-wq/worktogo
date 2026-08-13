-- ============================================================================
--  WorkToGo V2 — Phase 2: Role & Permission foundation (data-driven RBAC)
--  Migration: 2026_08_13_001_rbac_foundation
-- ----------------------------------------------------------------------------
--  PURPOSE (V2-VISION "one system, many role-scoped windows"; founder intent:
--    create roles + assign permissions from the panel with ZERO code change)
--
--    Today the whole admin panel is gated by a single check: users.role='admin'
--    (config/app.php ROLE_ADMIN; AuthMiddleware::requireRole). That does not
--    scale to Category Head / Ops Manager / Expert / Finance / Moderator / a
--    future AI agent — each needing its own scoped "window" of what it can see
--    and do. This lays the DATA-DRIVEN foundation so those windows plug in later
--    with no schema rewrite and no code change to add a role.
--
--    FOUNDATION ONLY. No per-role dashboard UI is built here. Nothing about
--    today's admin behaviour changes: the existing admin user is mapped to a
--    'super_admin' role that holds every permission, and the permission-check
--    helper treats users.role='admin' as all-access regardless of these tables.
--
--  MODEL (classic, boring-on-purpose RBAC — the shape every team scales into)
--    roles            — a named bundle of access (super_admin, category_head…)
--    permissions      — the granular capability CATALOG ('media.approve',
--                       'bookings.assign', 'payments.view'), each engine-tagged
--                       so service/shopping/video/delivery share one vocabulary.
--    role_permissions — which permissions a role grants (many-to-many).
--    user_roles       — which roles a user holds (many-to-many), each assignment
--                       optionally SCOPED to a category or city (Category Head =
--                       'category_head' scoped to category X; city ops = scoped
--                       to a city). Scope is stored now; enforcement can tighten
--                       later without a schema change.
--
--    Permissions are a fixed, code-referenced catalog (a key only "means"
--    something because code calls Permission::userHas($u,'media.approve')).
--    ROLES are fully data-driven: the founder composes them from the catalog in
--    the panel. Adding "Regional Manager" later = insert a role + pick its
--    permissions + assign users — no migration, no deploy.
--
--  WHY THIS WON'T NEED A REWRITE
--    * permissions.engine tags every capability (core|service|shopping|video|
--      delivery) so the SAME table serves all three engines when they activate —
--      no per-engine permission table.
--    * perm_key / role.slug are varchar (not enum) — new capabilities/roles are
--      rows, never ALTERs.
--    * user_roles.scope_type/scope_id are polymorphic (no FK on scope_id — matches
--      the repo's media.owner_id / wallet_transactions pattern) so a role can be
--      scoped to a category, a city, or globally with one column pair.
--    * A user can hold MANY roles (junction, not a users.role swap) so the legacy
--      users.role enum keeps working untouched for auth while RBAC layers on top.
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS; seeds use INSERT … ON
--    DUPLICATE KEY UPDATE (roles/permissions, keyed by unique slug/perm_key) and
--    INSERT IGNORE … SELECT (join tables, keyed by their composite PK/UNIQUE), so
--    re-running is a no-op. FKs live inside each CREATE. InnoDB / utf8mb4.
--    DO NOT run against prod from here — apply on STAGING, verify, then prod.
-- ============================================================================

-- ── 1. roles — a named bundle of access ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        varchar(100) NOT NULL COMMENT 'human label, e.g. "Category Head"',
  `slug`        varchar(64)  NOT NULL COMMENT 'machine key, e.g. "category_head"',
  `description` varchar(255) DEFAULT NULL,
  `is_system`   tinyint(1)   NOT NULL DEFAULT 0 COMMENT '1 = built-in (super_admin); cannot be deleted / slug locked',
  `is_active`   tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_roles_slug` (`slug`),
  KEY `idx_roles_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. permissions — the granular, engine-tagged capability CATALOG ─────────
CREATE TABLE IF NOT EXISTS `permissions` (
  `id`          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `perm_key`    varchar(80)  NOT NULL COMMENT 'code-referenced key, e.g. "media.approve"',
  `label`       varchar(150) NOT NULL COMMENT 'human description for the panel',
  `engine`      varchar(24)  NOT NULL DEFAULT 'core' COMMENT 'core|service|shopping|video|delivery',
  `group_label` varchar(64)  NOT NULL DEFAULT 'General' COMMENT 'UI grouping, e.g. "Media", "Payments"',
  `is_active`   tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_permissions_key` (`perm_key`),
  KEY `idx_permissions_engine` (`engine`),
  KEY `idx_permissions_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. role_permissions — which permissions a role grants (M2M) ─────────────
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`       bigint(20) UNSIGNED NOT NULL,
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `idx_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role`
    FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rp_permission`
    FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. user_roles — which roles a user holds, optionally scoped (M2M) ───────
--    scope_type/scope_id are app-enforced (no FK on scope_id): 'global' (scope_id
--    0) = everywhere; 'category' = scope_id is a categories.id; 'city' = a
--    cities.id. A user can hold the same role scoped to several categories.
CREATE TABLE IF NOT EXISTS `user_roles` (
  `id`                   bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`              bigint(20) UNSIGNED NOT NULL,
  `role_id`              bigint(20) UNSIGNED NOT NULL,
  `scope_type`           varchar(24) NOT NULL DEFAULT 'global' COMMENT 'global|category|city',
  `scope_id`             bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'category_id / city_id; 0 for global',
  `assigned_by_user_id`  bigint(20) UNSIGNED DEFAULT NULL,
  `created_at`           datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_role_scope` (`user_id`, `role_id`, `scope_type`, `scope_id`),
  KEY `idx_ur_role` (`role_id`),
  KEY `idx_ur_scope` (`scope_type`, `scope_id`),
  CONSTRAINT `fk_ur_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ur_role`
    FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ur_assigned_by`
    FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SEED — baseline roles, the permission catalog, and their wiring
-- ============================================================================

-- ── 5. Roles: one system super_admin + five data-only stubs to assign later ─
INSERT INTO `roles` (`slug`, `name`, `description`, `is_system`, `is_active`) VALUES
  ('super_admin',   'Super Admin',        'Full access to everything (the existing admin).',                 1, 1),
  ('category_head', 'Category Head',      'Runs one category: its vendors, bookings, media and packages.',   0, 1),
  ('ops_manager',   'Operations Manager', 'Day-to-day operations: assignment, follow-up, area coverage.',    0, 1),
  ('expert',        'Expert / Agent',     'Field agent who does expert site visits from their phone.',       0, 1),
  ('finance',       'Finance / Payments', 'Payments, refunds and revenue — money-side only.',                0, 1),
  ('moderator',     'Content Moderator',  'Approves vendor media and moderates content.',                    0, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `is_system` = VALUES(`is_system`);

-- ── 6. Permission catalog (granular, engine-tagged). Grow this list to add ──
--    capabilities; a key only takes effect where code calls Permission::userHas.
INSERT INTO `permissions` (`perm_key`, `label`, `engine`, `group_label`) VALUES
  -- Core / platform
  ('dashboard.view',     'View the admin dashboard',            'core',    'Dashboard'),
  ('users.view',         'View users',                          'core',    'Users'),
  ('users.manage',       'Create / edit / block users',         'core',    'Users'),
  ('vendors.view',       'View vendors',                        'core',    'Vendors'),
  ('vendors.manage',     'Approve / reject / verify vendors',   'core',    'Vendors'),
  ('roles.view',         'View roles & permissions',            'core',    'Access Control'),
  ('roles.manage',       'Create roles & assign permissions',   'core',    'Access Control'),
  ('settings.view',      'View system settings',                'core',    'Settings'),
  ('settings.manage',    'Change system settings',              'core',    'Settings'),
  ('logs.view',          'View logs & activity',                'core',    'Logs'),
  -- Service engine
  ('bookings.view',      'View bookings',                       'service', 'Bookings'),
  ('bookings.assign',    'Assign / reassign bookings',          'service', 'Bookings'),
  ('bookings.manage',    'Change booking state / ops',          'service', 'Bookings'),
  ('services.view',      'View services',                       'service', 'Services'),
  ('services.manage',    'Create / edit services & pricing',    'service', 'Services'),
  ('categories.view',    'View categories',                     'service', 'Categories'),
  ('categories.manage',  'Create / edit categories',            'service', 'Categories'),
  ('packages.view',      'View packages',                       'service', 'Packages'),
  ('packages.manage',    'Create / edit packages',              'service', 'Packages'),
  ('expert_visit.view',  'View expert visits',                  'service', 'Expert Visit'),
  ('expert_visit.manage','Configure / manage expert visits',    'service', 'Expert Visit'),
  ('media.view',         'View vendor media',                   'service', 'Media'),
  ('media.approve',      'Approve / reject vendor media',       'service', 'Media'),
  ('followup.view',      'View follow-up center',               'service', 'Follow-up'),
  ('followup.manage',    'Act on follow-ups',                   'service', 'Follow-up'),
  ('area.view',          'View area intelligence',              'service', 'Area'),
  ('area.manage',        'Manage area / locality config',       'service', 'Area'),
  -- Money side
  ('payments.view',      'View payments',                       'core',    'Payments'),
  ('payments.manage',    'Manage payments / settlement',        'core',    'Payments'),
  ('refunds.view',       'View refunds',                        'core',    'Payments'),
  ('refunds.manage',     'Approve / reject refunds',            'core',    'Payments'),
  ('revenue.view',       'View revenue / P&L',                  'core',    'Revenue'),
  -- Shopping engine (future)
  ('orders.view',        'View orders',                         'shopping','Orders'),
  ('orders.manage',      'Manage orders',                       'shopping','Orders'),
  ('products.view',      'View products',                       'shopping','Products'),
  ('products.manage',    'Manage products',                     'shopping','Products'),
  -- Video engine (future)
  ('video.view',         'View video content',                  'video',   'Video'),
  ('video.manage',       'Manage video content',                'video',   'Video'),
  ('content.moderate',   'Moderate content',                    'video',   'Moderation'),
  -- Delivery engine (future)
  ('delivery.view',      'View deliveries',                     'delivery','Delivery'),
  ('delivery.manage',    'Manage deliveries',                   'delivery','Delivery')
ON DUPLICATE KEY UPDATE
  `label` = VALUES(`label`), `engine` = VALUES(`engine`), `group_label` = VALUES(`group_label`);

-- ── 7. super_admin → EVERY permission (kept explicit; the helper also treats
--        super_admin / legacy users.role='admin' as all-access as a belt-and-braces).
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
JOIN `permissions` p
WHERE r.`slug` = 'super_admin';

-- ── 8. Stub roles → sensible starter permission sets (founder can edit later) ─
--    category_head: runs one category end-to-end (scope applied via user_roles).
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'category_head' AND p.`perm_key` IN (
  'dashboard.view','categories.view','vendors.view','bookings.view','bookings.assign',
  'media.view','media.approve','packages.view','followup.view','area.view'
);

--    ops_manager: assignment, follow-up, area coverage, expert-visit ops.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'ops_manager' AND p.`perm_key` IN (
  'dashboard.view','bookings.view','bookings.assign','bookings.manage',
  'followup.view','followup.manage','vendors.view','area.view','area.manage',
  'expert_visit.view','expert_visit.manage'
);

--    expert: field agent — sees their visits/bookings and their own media only.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'expert' AND p.`perm_key` IN (
  'bookings.view','expert_visit.view','media.view'
);

--    finance: money side only.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'finance' AND p.`perm_key` IN (
  'dashboard.view','payments.view','payments.manage','refunds.view','refunds.manage','revenue.view'
);

--    moderator: media + content moderation.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE r.`slug` = 'moderator' AND p.`perm_key` IN (
  'media.view','media.approve','content.moderate','vendors.view'
);

-- ── 9. Map EVERY existing admin (users.role='admin') to super_admin, global ──
--        scope. Robust across environments (not hardcoded to user id 1).
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`, `scope_type`, `scope_id`)
SELECT u.`id`, r.`id`, 'global', 0
FROM `users` u
JOIN `roles` r ON r.`slug` = 'super_admin'
WHERE u.`role` = 'admin';

-- ============================================================================
--  VERIFICATION (run after applying)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'roles';            -- expect roles/permissions/role_permissions/user_roles
--  SELECT COUNT(*) FROM permissions;    -- expect 41
--  -- super_admin holds every permission:
--  SELECT (SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id=rp.role_id
--            WHERE r.slug='super_admin')
--       = (SELECT COUNT(*) FROM permissions) AS super_admin_has_all;   -- expect 1
--  -- the existing admin is a super_admin:
--  SELECT u.email, r.slug FROM user_roles ur
--    JOIN users u ON u.id=ur.user_id JOIN roles r ON r.id=ur.role_id
--    WHERE u.role='admin';               -- expect each admin -> super_admin
--  -- a stub role has a scoped-down set (proves granularity):
--  SELECT p.perm_key FROM role_permissions rp
--    JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id
--    WHERE r.slug='finance' ORDER BY p.perm_key;   -- expect the 6 money perms only
-- ============================================================================
