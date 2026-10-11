-- ============================================================================
--  WorkToGo Commerce — F1: routing source-preference knob
--  Migration: 2026_10_06_017_routing_source_preference
-- ----------------------------------------------------------------------------
--  PURPOSE (CEO F1): make the "best source" choice a rule-based, admin-tunable
--  knob instead of hardcoded. `commerce_routing_source_preference`:
--    'nearest'  (DEFAULT) — the original behaviour (company-first → nearest →
--                priority), so nothing changes unless an admin opts in;
--    'cheapest' — company-first → lowest offer price → nearest → priority;
--    'reliable' — company-first → highest accept ratio (from routing signals) →
--                nearest → priority.
--  company-first stays the invariant top key in every mode. (Full user-psychology
--  "Brain" ranking is later; this is the rule-based seed.)
--
--  SAFETY: INSERT IGNORE on the unique setting_key (keeps an admin value). Default
--    'nearest' = behaviour-preserving. Additive; no engine edit; B3-compliant.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_routing_source_preference', 'nearest', 'text',
   'Commerce routing source preference: nearest (default) | cheapest | reliable', 0);
