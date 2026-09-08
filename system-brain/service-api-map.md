# Phase 07 — Service API Map

Generated: 2026-05-12T16:40:00Z

## Customer Service APIs

| API / Intent | Method | Auth | Handler | Tables | Confidence |
|---|---|---|---|---|---|
| `service:list_services` | POST intent | Public/optional | service engine -> `/api/services` | `services`, `vendors` | High |
| `/api/services` | GET | Public | service controller | `services`, `vendors`, `categories` | High |
| `/api/services/{id}` | GET | Public | service controller | `services`, `vendors`, `categories` | High |
| `service:create_booking` | POST intent | Required | service engine -> `/api/service/request` | `services`, `bookings`, `jobs`, `addresses` | High |
| `/api/service/request` | POST | Required | service controller | `services`, `bookings`, `jobs`, optional payment | High |
| `service:list_bookings` | POST intent | Required | AppRouter only; missing service-engine case | uncertain | High drift |
| `/api/service/bookings` | GET | Required | service controller | `bookings`, `services`, `vendors` | High |
| `/api/service/bookings/{id}` | GET | Required | service controller | `bookings`, `services`, `vendors`, `jobs` | High |

## Vendor Service APIs

| API / Intent | Method | Auth | Handler | Tables | Confidence |
|---|---|---|---|---|---|
| `/api/services` | POST | `vendor_service` | service controller | `vendors`, `services` | High |
| `/api/services/{id}` | PUT | `vendor_service` | service controller | `vendors`, `services` | High |
| `/api/services/{id}` | DELETE | `vendor_service` | service controller | `vendors`, `services` | High |
| `/api/vendor/jobs` | GET | `vendor_service`/admin | vendor service controller | `jobs`, `bookings`, `services`, `users` | High |
| `/api/vendor/jobs/{id}` | GET | `vendor_service`/admin | vendor service controller | `jobs`, `bookings`, `services`, `users`, `vendors` | High |
| `/api/jobs/{id}/status` | PATCH | `vendor_service`/admin | service controller | `jobs`, `bookings` | High |
| `/api/vendor/availability` | POST | `vendor_service`/admin | SlotController | `vendor_availability_slots` | High |
| `/api/vendor/availability/{vendorId}` | GET | Direct route | SlotController | `vendor_availability_slots`, `booking_slot_reservations` | High |
| `/api/booking/check-slot` | POST | Required | SlotController | `vendor_availability_slots`, `booking_slot_reservations` | High |
| `vendor:list_jobs` | POST intent | Vendor | AppRouter has route; service engine lacks case | uncertain | Medium-High |
| `vendor:update_job_status` | POST intent | Vendor | Not found in service engine dispatcher | uncertain | Medium-High |

## Assignment and Analytics APIs

| API | Method | Auth | Tables | Notes | Confidence |
|---|---|---|---|---|---|
| `cron/auto_assign.php` | cron | system | `platform_settings`, `auto_assignments`, `jobs`, `bookings`, `orders`, `addresses`, `vendors`, `alerts` | Creates assignment invitations. | High |
| `/api/assignments/accept.php` | POST | vendor | `auto_assignments`, `jobs`/`orders`, `alerts`, `vendors` | Vendor accepts assignment. | High |
| `/api/vendor/service-analytics/stats` | GET | service vendor/admin | `bookings`, `transactions`, `followers`, `vendors` | Booking stats. | High |
| `/api/vendor/service-analytics/bookings` | GET | service vendor/admin | `bookings`, `transactions` | Booking chart. | High |
| `/api/admin/services` | GET | admin | `services` | Lists services only. | High |

## API Gaps

- No traced customer booking cancel endpoint.
- No traced reschedule endpoint.
- No traced admin/provider manual assignment endpoint for service jobs.
- No traced booking refund endpoint.
- No traced slot reservation in main customer booking API.
- No traced provider availability management page in vendor frontend.
- No traced implementation for `service:service_status` or `service:get_recommendations`.
