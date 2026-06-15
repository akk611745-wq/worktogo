-- WorkToGo — Seed initial services for pilot launch
-- Creates one active service per category, linked to vendor Aakash (id=11).
-- Safe: INSERT only, no schema changes.
--
-- Verified from live DB dump (2026-06-14):
--   categories: id 1=Electrician, 2=Plumber, 3=Painting, 4=Waterproofing, 5=CCTV
--   vendor id=11 (Aakash), status='active', type='service'
--   services table: zero rows (AUTO_INCREMENT reset to 6 from prior test data)
--
-- base_price=0.00 means call-for-quote. Vendor/admin can update via panel.
-- inspection_price (app_settings id=9, value=299) applies to inspection booking
-- mode only and is separate from services.base_price.

INSERT INTO `services` (`vendor_id`, `category_id`, `name`, `slug`, `base_price`, `duration_minutes`, `status`, `created_at`) VALUES
(11, 1, 'Electrician Service',    'electrician-aakash',    0.00, 60,  'active', NOW()),
(11, 2, 'Plumbing Service',       'plumbing-aakash',       0.00, 60,  'active', NOW()),
(11, 3, 'Painting Service',       'painting-aakash',       0.00, 120, 'active', NOW()),
(11, 4, 'Waterproofing Service',  'waterproofing-aakash',  0.00, 120, 'active', NOW()),
(11, 5, 'CCTV Installation',      'cctv-aakash',           0.00, 90,  'active', NOW());
