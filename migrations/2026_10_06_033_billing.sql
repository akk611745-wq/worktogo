-- ============================================================================
--  WorkToGo Commerce — Paint PB11: billing (GST-ready invoice + seller report)
--  Migration: 2026_10_06_033_billing
-- ----------------------------------------------------------------------------
--  PURPOSE: config for the customer invoice + seller report. All read-only over
--  existing order/ledger data; no new money path.
--   - commerce_gstin           : our GSTIN printed on the invoice (blank = hide).
--   - commerce_tax_rate_pct     : GST % to apply (0 = no tax line shown).
--   - commerce_invoice_business : JSON {name,address,email,phone} for the header.
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_gstin', '', 'text', 'Our GSTIN printed on customer invoices (blank hides it)', 0),
  ('commerce_tax_rate_pct', '0', 'number', 'GST rate % applied on invoices (0 = no tax line)', 0),
  ('commerce_invoice_business', '{"name":"WorkToGo","address":"Haldwani, Uttarakhand","email":"","phone":""}', 'json', 'Invoice header business details', 0);
