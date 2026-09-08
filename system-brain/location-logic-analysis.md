# Phase 08 — Location Logic Analysis

## Location Sources

| Source | Fields | Used by | Evidence | Confidence |
|---|---|---|---|---|
| WorkToGo `addresses` | city/state/postal code; baseline lacks lat/lng | service job auto-assign query expects lat/lng | `wtg  live/database-structure.sql:30`, `wtg  live/cron/auto_assign.php:116` | High for mismatch |
| WorkToGo `orders.shipping_address` JSON | free text or structured JSON, optional `lat`/`lng` | PHP auto assignment | `wtg  live/body/shopping-engine/api/orders/OrderService.php:73`, `wtg  live/cron/auto_assign.php:124` | High |
| WorkToGo `vendors.lat/lng` | vendor/provider coordinates | PHP nearest vendor/provider assignment | `wtg  live/database-structure.sql:813`, `wtg  live/cron/auto_assign.php:134` | High |
| Heart context location | request/header lat/lng/city/state/pincode | Brain/intent context | `wtg  live/heart/pipeline/ContextBuilder.php:99` | High |
| SwiftDeliver `settings.city_lat/city_lng` | city center fallback | geocoding fallback, rider/vendor defaults | `swiftdeliver-main/src/db/database.ts:253`, `swiftdeliver-main/server.ts:939` | High |
| SwiftDeliver `riders.lat/lng` | rider current location | distance filtering/tracking | `swiftdeliver-main/src/db/database.ts:56`, `swiftdeliver-main/server.ts:243` | High |
| SwiftDeliver `orders.lat/lng` | drop coordinates | tracking and rider broadcast | `swiftdeliver-main/src/db/database.ts:102`, `swiftdeliver-main/server.ts:950` | High |

## Distance Logic

### WorkToGo PHP quote pricing

Uses `GeoHelper::calculateDistance()` Haversine-style formula and `DeliveryPricingEngine` settings.

Evidence: `GeoHelper` (`wtg  live/core/helpers/GeoHelper.php:18`); pricing settings and calculation (`wtg  live/core/helpers/DeliveryPricingEngine.php:21`, `wtg  live/core/helpers/DeliveryPricingEngine.php:47`).

Operational impact: quote endpoint requires all four coordinates and rejects zero-like missing values.
Scale impact: pricing is simple point-to-point, no city, traffic, batching, or zone logic.
Confidence: High.

### WorkToGo PHP vendor auto-assignment distance

Uses inline SQL spherical distance against `vendors.lat/lng` and orders by nearest.

Evidence: nearest vendor query (`wtg  live/cron/auto_assign.php:134`).

Important finding: `vendor_search_radius` is loaded but not applied to the SQL query.
Evidence: setting loaded (`wtg  live/cron/auto_assign.php:18`) but no `HAVING distance <= radius` or equivalent in selection (`wtg  live/cron/auto_assign.php:134`).

Operational impact: vendor/provider assignment can cross city boundaries if all vendors are in one table.
Scale impact: multi-city expansion is dangerous; nearest globally may be in a different city if data/coordinates are bad.
Maintainability impact: admin-configured radius creates false operational confidence.
Confidence: High.

### SwiftDeliver distance logic

Uses Haversine `getDistance()` and `max_delivery_km * 2` for rider broadcast filtering.

Evidence: distance function (`swiftdeliver-main/server.ts:65`); rider broadcast radius uses max delivery km times two (`swiftdeliver-main/server.ts:324`).

Operational impact: when no riders are in range, system broadcasts to all online riders anyway.
Evidence: fallback broadcast to all (`swiftdeliver-main/server.ts:342`).
Scale impact: in multi-city mode, fallback can notify riders outside the intended city.
Confidence: High.

## City/Area/Zone Handling

### City

WorkToGo has `platform_city` in app settings and city in address data, but delivery dispatch/assignment does not enforce city boundaries in the traced paths.

Evidence: app setting `platform_city` (`wtg  live/database-structure.sql:86`); address `city` (`wtg  live/database-structure.sql:30`); SwiftDeliver city center settings (`swiftdeliver-main/src/db/database.ts:253`).

Operational impact: city is mostly descriptive/fallback, not a hard routing boundary.
Scale impact: multi-city scaling is high risk until city scoping is enforced in dispatch, vendor assignment, rider broadcast, and admin views.
Confidence: High.

### Area/Zone

No first-class delivery `area` or `zone` table/assignment constraint was traced for delivery operations.

Evidence: searches found city/address/settings and coordinates, but no canonical delivery zone table or route-level zone enforcement in traced delivery files.

Operational impact: operations cannot limit riders/vendors to operational zones using existing traced logic.
Scale impact: zone-based expansion, surge, batching, serviceability, and SLA controls are not supported by traced delivery runtime.
Confidence: Medium-High.

## Location Ambiguity Risks

### Free-text customer address without coordinates

WHERE: customer order can store `shipping_address` as `{"address":"..."}` without lat/lng.
WHY/root cause: `OrderService` accepts string delivery address and JSON-encodes it without geocoding.
Evidence: `OrderService` address handling (`wtg  live/body/shopping-engine/api/orders/OrderService.php:89`).
CONNECTED SYSTEMS: PHP auto assignment, SwiftDeliver dispatch, customer UI.
OPERATIONAL IMPACT: PHP auto assignment falls back to `0,0` for orders with no lat/lng.
Evidence: order address lat/lng fallback (`wtg  live/cron/auto_assign.php:127`).
SCALE IMPACT: nearest vendor may be selected relative to the Gulf of Guinea if fallback reaches query.
MAINTAINABILITY IMPACT: different paths interpret address JSON differently.
FUTURE RISK: city expansion will fail without structured geocoding and serviceability validation.
Confidence: High.

### SwiftDeliver fallback to city center

WHERE: `/api/dispatch` uses payload lat/lng; if absent, attempts geocode; if geocode fails, uses city center.
Evidence: fallback logic (`swiftdeliver-main/server.ts:950`, `swiftdeliver-main/server.ts:954`, `swiftdeliver-main/server.ts:957`).
OPERATIONAL IMPACT: rider distance and tracking may represent city center rather than customer address.
SCALE IMPACT: all ungeocoded orders cluster at the same city center under failures.
Confidence: High.

## Multi-City Danger Summary

1. No hard city scope in PHP nearest vendor assignment.
2. No zone table or delivery area enforcement traced.
3. SwiftDeliver has one `city_lat/city_lng` setting, not per-city settings.
4. Fallback broadcasts to all online riders when no rider is within range.
5. Admin delivery lists do not filter city/zone in traced routes.
6. Address geocoding is optional and can silently fallback.

