# Phase 07 — Service Mobile Analysis

Generated: 2026-05-12T16:40:00Z

## Customer Mobile Behavior

- Customer Home uses a modal for service booking.
- Booking modal uses native `datetime-local`, but it is not tied to provider availability.
- Notes field doubles as address/special instructions.
- After booking success, customer is routed to bookings page.

Mobile risks:

- Customer can select any future datetime, even when vendor has no availability.
- No guided address capture or GPS/map selection for service location.
- No slot picker, provider ETA, reschedule, cancel, or contact action traced.
- Status visibility may fail due `service:list_bookings` intent mismatch.

## Provider Mobile/Web Behavior

- Vendor panel is web-based and role-gated.
- Service vendors land on `bookings.php` after login.
- `bookings.php` is titled Jobs and supports polling every 8 seconds.
- UI offers accept/reject/status update buttons through intent API wrappers.

Mobile risks:
- Provider operations depend on polling, not push/websocket.
- If tab sleeps/backgrounds on mobile, realtime job acceptance can be delayed.
- Accept/reject statuses may not match backend statuses.
- No location/route/arrival/start checklist UI traced.
- No availability setup UI was traced in vendor frontend despite backend route existing.

## Operational Mobile Weaknesses

| Weakness | Impact | Confidence |
|---|---|---|
| Polling every 8 seconds | Battery/data use and delayed updates under mobile throttling. | High |
| No push notification guarantee | Providers may miss new jobs. | High |
| No availability slot UI traced | Providers cannot easily manage schedules from panel. | Medium |
| No map/location workflow | Assignment quality and arrival tracking weak. | High |
| No in-job checklist/photos/OTP | Service completion proof absent. | High |
| No customer/provider chat | Operational coordination outside platform. | High |

## Future Mobile Requirements
- Canonical service job API direct routes or fixed intent mapping.
- Push notifications for assignment requests and status changes.
- Slot picker based on `vendor_availability_slots` and reservations.
- Mobile provider availability calendar.
- Job workflow screen with accept, travel, arrive, start, complete, cancel/no-show.
- Location capture and service address validation.
- Completion proof/audit trail.

## Uncertainty Reporting
- No native mobile app was traced.
- Browser testing was not performed; analysis is based on code structure and UI/API behavior.
