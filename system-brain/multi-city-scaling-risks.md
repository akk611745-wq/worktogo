# Phase 08 — Multi-City Scaling Risks

## Current Multi-City Reality

The traced delivery/location system is effectively single-city/fallback-city oriented. City exists in settings and addresses, but dispatch and assignment are primarily coordinate/fallback driven and do not enforce city, area, zone, or serviceability boundaries.

Evidence:
- `platform_city` setting exists (`wtg  live/database-structure.sql:86`).
- SwiftDeliver has one `city_lat` and one `city_lng` setting (`swiftdeliver-main/src/db/database.ts:253`).
- WorkToGo auto assignment does not filter city or apply loaded radius (`wtg  live/cron/auto_assign.php:18`, `wtg  live/cron/auto_assign.php:134`).
- SwiftDeliver broadcasts to all online riders if no one is in radius (`swiftdeliver-main/server.ts:342`).

Confidence: High.

## Scaling Danger Areas

### 1. City is not an operational partition

WHERE: PHP auto assignment and SwiftDeliver rider broadcasts.
WHY: vendor/rider selection is not constrained by city/zone; city center is a global setting.
ROOT CAUSE: no traced `city_id`, `zone_id`, or service area contract in delivery assignment.
CONNECTED SYSTEMS: vendors, riders, orders, settings, admin dashboards.
OPERATIONAL IMPACT: wrong-city vendors/riders can receive/accept work when coordinates are missing or fallback logic triggers.
SCALE IMPACT: every added city increases assignment ambiguity.
MAINTAINABILITY IMPACT: adding city filters later requires schema/API/UI changes.
FUTURE RISK: city launch can create cross-city fulfillment failures.
Confidence: High.

### 2. Radius settings are inconsistently applied

WHERE: PHP `vendor_search_radius` loaded but unused; SwiftDeliver applies `max_delivery_km * 2` then falls back to all riders.
Evidence: PHP setting load (`wtg  live/cron/auto_assign.php:18`); PHP query no radius condition (`wtg  live/cron/auto_assign.php:134`); Node radius multiplier (`swiftdeliver-main/server.ts:324`); fallback all riders (`swiftdeliver-main/server.ts:342`).
OPERATIONAL IMPACT: configured service radius may not match runtime behavior.
SCALE IMPACT: radius controls cannot reliably isolate city operations.
Confidence: High.

### 3. Geocoding fallback creates city-center clustering

WHERE: SwiftDeliver `/api/dispatch` and `/api/orders`.
Evidence: dispatch fallback to city center (`swiftdeliver-main/server.ts:954`); manual order fallback with random city offset (`swiftdeliver-main/server.ts:1092`).
OPERATIONAL IMPACT: orders with bad addresses can appear near city center.
SCALE IMPACT: dispatch load and SLA metrics cluster incorrectly.
Confidence: High.

### 4. WorkToGo checkout dispatch sends minimal payload

WHERE: PHP dispatch sends only order and delivery IDs.
Evidence: payload (`wtg  live/body/shopping-engine/api/orders/OrderService.php:1002`).
OPERATIONAL IMPACT: SwiftDeliver constructs generic customer/address fields (`WTG Order #`, `WTG delivery #`) unless rich payload is provided by other paths.
Evidence: Node payload defaults (`swiftdeliver-main/server.ts:941`, `swiftdeliver-main/server.ts:943`).
SCALE IMPACT: real routing/tracking quality degrades for multi-city because address/geocode context may be missing.
Confidence: High.

### 5. Admin dashboards are global

WHERE: WorkToGo admin delivery list and SwiftDeliver admin statistics.
Evidence: PHP delivery list has status/page only (`wtg  live/heart/api/admin/index.php:698`); SwiftDeliver stats aggregate all orders/riders (`swiftdeliver-main/server.ts:1324`).
OPERATIONAL IMPACT: city managers cannot isolate local backlog in traced APIs.
SCALE IMPACT: operational visibility degrades as cities increase.
Confidence: High.

## Required Future Inspection Before Multi-City Expansion

1. Confirm live production schema for `deliveries` (`driver_id` vs `rider_id`).
2. Confirm whether WorkToGo dispatch payload is enriched elsewhere in production.
3. Trace SwiftDeliver React rider/admin UI reconnect and location update intervals.
4. Trace deployment environment variables per city.
5. Trace whether cron is scheduled and at what cadence.
6. Inspect live data for vendor/rider coordinate completeness.
7. Validate whether any unlisted migrations add zones, city IDs, or rider service areas.

