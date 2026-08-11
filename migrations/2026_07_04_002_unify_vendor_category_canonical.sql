-- ============================================================================
--  WorkToGo V2 — Phase 1: unify vendor<->category storage (canonical junction)
--  Migration: 2026_07_04_002_unify_vendor_category_canonical
-- ----------------------------------------------------------------------------
--  PROBLEM (audit-02 / audit-06)
--    "Which categories does a vendor serve" was stored THREE conflicting ways:
--      1. services.category_id + services.vendor_id  (what the resolver used)
--      2. vendors.category_id                        (loose int, no FK, single)
--      3. vendor_categories junction                 (dual-written mirror, barely read)
--    They drifted, so a plumber (e.g. "Shiva Kashyap") surfaced in EVERY category.
--
--  CANONICAL DECISION: vendor_categories (the M:N junction) is now the single
--    source of truth. It is the only one that natively models many-categories-
--    per-vendor and it already has a proper FK to categories (created by
--    2026_06_15_001_vendor_categories_junction). The code in this same commit
--    reads/writes ONLY this table for membership.
--
--  WHAT THIS MIGRATION DOES
--    Reconciles the DELIBERATE truth into vendor_categories:
--      - existing vendor_categories rows are kept as-is, AND
--      - vendors.category_id (the explicit admin/profile selection) is imported.
--    It intentionally does NOT bulk-import services.category_id — see the WARNING
--    block below — because those rows include the 2026_06_14 seed pollution that
--    CAUSED the "vendor in every category" bug. Importing them would re-create it.
--
--  DORMANT, NOT DROPPED (per task): vendors.category_id and the services rows are
--    left in place. The code still WRITES vendors.category_id as a backward-compat
--    "primary category" hint, but no longer treats it (or services rows) as the
--    source of truth. A later cleanup phase can drop vendors.category_id once every
--    reader is confirmed off it.
--
--  SAFETY / IDEMPOTENCY: INSERT IGNORE against the (vendor_id, category_id) PK —
--    safe to re-run; never duplicates, never deletes. No schema change here.
--    Do NOT run against prod from here; apply on STAGING first, verify, then prod.
-- ============================================================================


-- ── 1. Import the explicit vendors.category_id selection into the junction ───
--    JOIN categories guards the loose int(11) vendors.category_id against
--    pointing at a non-existent category (it has no FK of its own).
INSERT IGNORE INTO `vendor_categories` (`vendor_id`, `category_id`)
SELECT v.id, v.category_id
FROM `vendors` v
JOIN `categories` c ON c.id = v.category_id
WHERE v.category_id IS NOT NULL
  AND v.category_id > 0;


-- ── 2. (existing vendor_categories rows need no action — already canonical) ──


-- ============================================================================
--  OPTIONAL — DO NOT RUN BLINDLY  (left commented on purpose)
-- ----------------------------------------------------------------------------
--  Importing membership from services rows RE-IMPORTS the 2026_06_14 seed
--  pollution: vendors that have active seeded services across every category
--  would be re-inserted into every category, re-creating the exact
--  "vendor shows in all categories" bug this migration fixes.
--
--  Only run this for vendors whose real multi-category truth is known/curated
--  (e.g. scope it with an explicit AND s.vendor_id IN (...) allow-list), never
--  as a blanket import.
--
--  INSERT IGNORE INTO `vendor_categories` (`vendor_id`, `category_id`)
--  SELECT DISTINCT s.vendor_id, s.category_id
--  FROM `services` s
--  JOIN `categories` c ON c.id = s.category_id
--  WHERE s.vendor_id IS NOT NULL
--    AND s.category_id IS NOT NULL
--    AND s.status = 'active';
-- ============================================================================


-- ============================================================================
--  VERIFICATION (run after applying; expected results noted)
-- ----------------------------------------------------------------------------
--  -- Every vendor with an explicit category now has a junction row:
--  SELECT COUNT(*) FROM vendors v
--   WHERE v.category_id IS NOT NULL AND v.category_id > 0
--     AND NOT EXISTS (SELECT 1 FROM vendor_categories vc
--                      WHERE vc.vendor_id = v.id AND vc.category_id = v.category_id);
--  -- expect 0
--
--  -- A specific vendor's canonical categories (replace 123):
--  SELECT vc.category_id, c.name
--    FROM vendor_categories vc JOIN categories c ON c.id = vc.category_id
--   WHERE vc.vendor_id = 123;
--  -- expect exactly the categories that vendor actually serves
--
--  -- Vendors still with NO junction rows (legacy / never opted in) — these stay
--  -- unrestricted in the resolver until a category is assigned to them:
--  SELECT v.id, v.business_name FROM vendors v
--   WHERE NOT EXISTS (SELECT 1 FROM vendor_categories vc WHERE vc.vendor_id = v.id);
-- ============================================================================
