# Phase 07 — Service Dependency Map

Generated: 2026-05-12T16:40:00Z

## Module Dependencies

| Service Capability | Depends On | Tables | Risk |
|---|---|---|---|
| Service browsing | Heart/service engine, service controller, vendors/categories | `services`, `vendors`, `categories` | Migrated service columns required. |
| Service creation | Auth role, vendor profile, service schema | `users`, `vendors`, `services` | Vendor status filter inconsistency. |
| Booking creation | Auth, active service, optional address, payment helper | `services`, `bookings`, `jobs`, `addresses` | No availability check in main flow. |
| Job tracking | Job status endpoint, vendor ownership | `jobs`, `bookings`, `vendors` | State mirror only in one path. |
| Availability | Slot controller, vendor profile | `vendor_availability_slots`, `booking_slot_reservations` | Not integrated into main booking. |
| Assignment | Cron, platform settings, vendor geo, alerts | `auto_assignments`, `jobs`, `bookings`, `addresses`, `vendors`, `alerts` | Capability/type/location gaps. |
| Vendor panel | Vendor auth, API intents, realtime polling | `jobs`, `bookings`, `services`, `users` | Intent dispatcher drift. |
| Analytics | Vendor auth, transactions | `bookings`, `transactions`, `followers` | Booking transactions uncertain. |
| Admin service visibility | Admin router | `services`, `bookings` counts | Limited controls traced. |

## What Breaks If Service Logic Changes

| Change | Breakage |
|---|---|
| Require slot reservation in booking | Existing customer booking UI does not send slot fields and main path lacks reservation insert. |
| Change job status values | Vendor UI filters/actions and booking mirror mapping break. |
| Change booking status values | Customer bookings page, vendor analytics, booking mirror, admin stats break. |
| Make provider assignment pooled | Current service rows are vendor-owned and booking inserts vendor immediately. |
| Require address/location | Customer UI does not send `address_id`; notes are free text. |
| Enforce payment before job creation | Current booking creates job immediately even for unpaid online booking after payment order creation. |
| Add provider skill matching | Current vendor search ignores service/category/skill. |
| Add reschedule/cancel | Need slot release, booking/job status sync, refund handling, alerts. |

## Runtime Dependency Chains

- Customer booking chain: `app/pages/home.js` -> `app/js/api.js` -> Heart intent `service:create_booking` -> `heart/router.php` -> `EngineLoader` -> `body/service-engine/index.php` -> `api/services/index.php` -> `services/bookings/jobs`.
- Vendor job chain: `vendor/bookings.php` -> `vendor/shared/api.js` intent `vendor:list_jobs` / `vendor:update_job_status` -> Heart router -> service engine/direct route ambiguity.
- Assignment chain: `cron/auto_assign.php` -> `auto_assignments` -> `alerts` -> vendor UI/realtime -> `api/assignments/accept.php` -> `jobs/orders` -> `alerts`.

## Hidden Operational Dependencies

- `platform_settings` controls assignment enablement/timeout/radius.
- `vendors.lat/lng/is_online/status` controls assignment candidate pool.
- `addresses.lat/lng` are assumed by cron for job location, but Phase 06 baseline address schema did not show lat/lng columns.
- `transactions.reference_type='booking'` is assumed by service analytics.
- `alerts` table is the operational notification bridge.

## Uncertainty Reporting
- Whether `addresses` has lat/lng in production is uncertain.
- Whether vendor job intent calls reach direct REST handlers is uncertain.
- Whether assignment is intended for vendor-owned service jobs is ambiguous.
