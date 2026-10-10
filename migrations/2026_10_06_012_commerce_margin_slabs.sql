-- ============================================================================
--  WorkToGo Commerce — S12: SLAB margin + growth-mode knob
--  Migration: 2026_10_06_012_commerce_margin_slabs
-- ----------------------------------------------------------------------------
--  PURPOSE (core-completion-plan §2 S12; CEO C.1/C.2):
--    Flat % margin breaks at the extremes (earns ~nothing on a ₹50 item, gouges
--    a ₹2 lakh order). Replace the platform default with an admin-configurable
--    SLAB by seller base — cheap → flat ₹, mid → %, high value → decreasing %
--    ("as the price rises the % falls — only then it's fair") — plus a GROWTH
--    multiplier that scales every margin live (start thin to win transactions,
--    raise gradually, no deploy). Loss-proof floor is UNCHANGED and still enforced
--    at checkout. MarginEngine's S1 methods are untouched; priceFromBase falls back
--    to the flat 3-tier when the slab is absent, so this is fully back-compatible.
--
--    Default slab seeded as a THIN starter above loss-proof (CEO: "patla fixed
--    slab, near-zero baad me") with the growth knob at 1.0. These earn nothing
--    until the seller catalogue (S16) records a seller base and checkout persists
--    orders.seller_subtotal — at which point margin begins to apply. Admin tunes
--    both keys live (AdminControlRoom allowlist).
--
--  SAFETY / IDEMPOTENCY
--    * INSERT IGNORE on the unique setting_key — a re-run never clobbers an
--      admin-tuned slab. ADDITIVE only; no column/table/engine changes.
--    * 10.4-safe: no COMMENT-after-CHECK, no CHECK, no new tables.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_margin_slabs',
   '{"enabled":true,"bands":[{"up_to":500,"flat":20},{"up_to":5000,"pct":8},{"up_to":20000,"pct":5},{"up_to":100000,"pct":3},{"pct":2}],"by_category":{}}',
   'json', 'Commerce margin SLAB by seller base (cheap=flat, mid=%, high=decreasing%); per-category override via by_category', 0),
  ('commerce_margin_multiplier', '1.0', 'number',
   'Commerce growth-mode knob: scales every computed margin live (1.0=full slab, 0=near-free)', 0);
