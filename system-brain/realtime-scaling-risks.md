# Phase 09 — Realtime Scaling Risks

## Realtime Mechanisms Found

| Mechanism | Location | Type | Durability | Evidence |
|---|---|---|---|---|
| Customer orders/bookings auto-refresh | customer SPA router | polling | no local event history | `wtg  live/app/js/router.js:108` |
| Vendor realtime engine | vendor shared JS | polling + local known IDs | in-memory only | `wtg  live/vendor/shared/realtime.js:219` |
| AlertSystem | shared frontend alerts | polling `alerts` table | DB rows durable until cleanup | `wtg  live/assets/js/alerts.js:204` |
| SwiftDeliver WS | Node server | WebSocket push | in-memory clients only | `swiftdeliver-main/server.ts:218` |
| SwiftDeliver notification table | Node SQLite | DB + WebSocket push | SQLite durable | `swiftdeliver-main/server.ts:178`, `swiftdeliver-main/server.ts:287` |

## Scaling Risks

### Polling read amplification
WHERE: customer orders/bookings, vendor realtime, alerts.
WHY: each active browser tab schedules independent polling.
ROOT CAUSE: no shared push/event stream for WorkToGo PHP surfaces.
CONNECTED SYSTEMS: shopping orders, service bookings/jobs, alerts, vendor panels, customer SPA.
OPERATIONAL IMPACT: DB reads grow linearly with active tabs.
SCALE IMPACT: mobile users and vendors left logged in can create continuous background load.
MAINTAINABILITY IMPACT: each module has its own poll interval and error behavior.
FUTURE RISK: real-time expectations will overload PHP/DB before websocket architecture exists.
Evidence: customer polling (`wtg  live/app/js/router.js:113`); vendor polling (`wtg  live/vendor/shared/realtime.js:299`); alert polling (`wtg  live/assets/js/alerts.js:61`).
Confidence: High.

### In-memory WebSocket limitation
WHERE: SwiftDeliver Node WebSocket clients set.
WHY: clients are held in process memory and broadcasts iterate the local set.
ROOT CAUSE: no shared Redis/pubsub or multi-instance event broker traced.
CONNECTED SYSTEMS: riders, vendors, admins, tracking, notifications.
OPERATIONAL IMPACT: events only reach clients connected to the same Node instance.
SCALE IMPACT: horizontal scaling breaks realtime unless sticky sessions or pub/sub are added.
MAINTAINABILITY IMPACT: deployment topology becomes part of runtime correctness.
FUTURE RISK: multi-city/multi-instance delivery scaling will miss events.
Evidence: `clients = new Set()` (`swiftdeliver-main/server.ts:221`); broadcast helpers iterate clients (`swiftdeliver-main/server.ts:261`).
Confidence: High.

### Missed event replay gap
WHERE: vendor realtime and SwiftDeliver WS.
WHY: realtime popup/event state is local memory; disconnected clients miss transient push.
ROOT CAUSE: no durable per-user event cursor was traced for WorkToGo realtime; SwiftDeliver notifications exist but WS messages are separate.
CONNECTED SYSTEMS: vendor orders/bookings, rider order availability, admin updates.
OPERATIONAL IMPACT: users may only see final list state, not event notifications.
SCALE IMPACT: reconnect storms can generate repeated polling/list loads.
MAINTAINABILITY IMPACT: push and polling semantics differ by surface.
FUTURE RISK: mobile background operation will be unreliable.
Confidence: High.

## Mobile Runtime Coordination Risks

1. Customer SPA polling skips hidden tabs but refreshes on visibility return, causing burst refresh when many users reopen app.
2. Vendor realtime polling continues after page load unless stopped; visibility handling was not traced in vendor realtime.
3. SwiftDeliver rider WebSocket depends on active mobile web connection; background OS throttling can stale rider location.
4. AlertSystem uses polling and local token key assumptions, which can fail silently.

## Future Realtime Limitations

- No WorkToGo-wide WebSocket/SSE server was traced.
- No durable event cursor per user/vendor/rider was traced.
- No central notification/event contract spans PHP and SwiftDeliver.
- No fanout queue/pubsub was traced.
- No backpressure strategy was traced for polling or WebSocket broadcasts.

