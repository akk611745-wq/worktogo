ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS client_request_id VARCHAR(120) NULL AFTER booking_number,
  ADD COLUMN IF NOT EXISTS lifecycle_state VARCHAR(40) NULL AFTER booking_mode,
  ADD COLUMN IF NOT EXISTS assignment_state VARCHAR(40) NULL AFTER lifecycle_state,
  ADD COLUMN IF NOT EXISTS vendor_response_status VARCHAR(40) NULL AFTER assignment_state,
  ADD COLUMN IF NOT EXISTS operational_timeline JSON NULL AFTER notes;

CREATE UNIQUE INDEX IF NOT EXISTS uniq_bookings_client_request_id ON bookings (client_request_id);
