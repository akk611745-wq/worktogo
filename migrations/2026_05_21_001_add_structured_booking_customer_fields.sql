ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS booking_mode VARCHAR(20) NULL AFTER payment_method,
  ADD COLUMN IF NOT EXISTS customer_name VARCHAR(100) NULL AFTER notes,
  ADD COLUMN IF NOT EXISTS customer_mobile VARCHAR(15) NULL AFTER customer_name,
  ADD COLUMN IF NOT EXISTS customer_locality VARCHAR(100) NULL AFTER customer_mobile,
  ADD COLUMN IF NOT EXISTS customer_address TEXT NULL AFTER customer_locality,
  ADD COLUMN IF NOT EXISTS vendor_route VARCHAR(20) NULL AFTER customer_address;

CREATE INDEX IF NOT EXISTS idx_bookings_booking_mode ON bookings (booking_mode);
CREATE INDEX IF NOT EXISTS idx_bookings_customer_mobile ON bookings (customer_mobile);
