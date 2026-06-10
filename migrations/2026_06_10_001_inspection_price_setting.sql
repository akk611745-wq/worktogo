-- Add inspection_price to app_settings so admin can control it from the panel
INSERT INTO app_settings (setting_key, setting_value, value_type, label, is_public, updated_at)
VALUES ('inspection_price', '299', 'number', 'Inspection Fee (₹)', 1, NOW())
ON DUPLICATE KEY UPDATE updated_at = updated_at;
