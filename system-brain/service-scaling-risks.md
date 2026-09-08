# Phase 07 — Service Scaling Risks

Generated: 2026-05-12T16:40:00Z

## Urban Company-Style Complexity Gap

Current WorkToGo service engine supports a simple vendor-owned service booking model. Urban Company-style operations require package variants, skill-qualified provider pools, real-time provider workload balancing, geo service areas, rescheduling/cancellation policies, arrival/start/completion verification, chat/calls, reviews, quality scoring, and city/zone pricing. These are absent or partial.

## Scaling Bottlenecks

| Area | Current Behavior | Scaling Pain | Confidence |
|---|---|---|---|
| Booking list | `LIMIT 50`, role-scoped, ordered by created date. | No full pagination; history disappears for active users/vendors. | High |
| Vendor job list | Reads all matching jobs ordered by created date. | No limit/pagination; provider panel slows over time. | High |
| Availability | Weekly slots replaced wholesale; checks count reservations. | No holidays, exception days, buffers, duration conflict, or travel time. | High |
| Assignment | Haversine scan over online active vendors. | No service capability filtering or spatial strategy. | High |
| Status sync | Booking/job mirrored only in one endpoint. | More states create divergence. | High |
| Notifications | Alerts plus polling. | Polling load grows linearly. | High |
| Payments | Booking payment path fragmented. | Refund/payout reconciliation complexity. | High |

## Provider Overload Risks

- `vendor_availability_slots.max_bookings` controls capacity, but main booking ignores slots.
- Direct booking always assigns service owner regardless of current workload.
- Auto assignment filters online/active vendors but not current job count or provider capacity.
- No traced provider calendar exceptions, break times, travel time, job duration conflict logic, or daily max workload.

## Multi-Provider Conflict Risks

- Services are vendor-owned, but auto-assignment can reassign jobs to another vendor.
- Assignment search does not check whether a vendor offers the booked service.
- `jobs.vendor_id` meaning is ambiguous: service owner at creation, assigned provider after acceptance, or candidate owner.
- Booking retains original `bookings.vendor_id` unless assignment accept updates only job vendor, creating provider mismatch.

## Multi-City Scaling Risks

- Booking location is optional; customer UI uses notes as address/instructions.
- Auto assignment defaults to coordinates `(0,0)` if no saved address lat/lng exists.
- Vendors have `lat/lng` but no city/service-area contract enforced in assignment.
- Services/categories are global; no city-specific service availability or pricing traced.

## Future Expansion Limitations

| Expansion | Current Limitation | Confidence |
|---|---|---|
| Rescheduling | No traced endpoint; slot reservations not tied to main booking. | High |
| Cancellation fees | No cancellation policy table/logic. | High |
| Refund automation | No booking refund lifecycle traced. | High |
| Provider matching by skill | Vendor search ignores service/category/skill. | High |
| Add-ons/packages | `services` has one `base_price` and duration. | High |
| Provider payout | No service job settlement traced. | Medium-High |
| Quality control | Reviews table absent/disabled in analytics. | High |
| City expansion | No normalized city/service area enforcement. | High |
| Mobile provider app | Vendor frontend is web/polling and API intents drift. | High |

## Query and Data Scaling Risks
- `bookings` needs composite access by `vendor_id/status/created_at` and `user_id/status/created_at`.
- `jobs` needs vendor/status/created sorting and assignment lock indexes.
- `booking_slot_reservations` has vendor/date key but main booking flow bypasses it.
- `transactions` booking analytics rely on `reference_type/reference_id/status/created_at`.

## Uncertainty Reporting
- Actual DB indexes after production migrations were not queried live.
- Real data volumes and cron frequency are unknown.
- External mobile app behavior is not present.
