# Phase 09 — Event Propagation Analysis

## Event Systems Found

WorkToGo uses several independent event-like systems:

1. **Alerts table events** — DB rows in `alerts`, consumed by polling frontend modules.
2. **Brain events** — `brain_events` and decision/action/feedback models for personalization and Brain alerts.
3. **Payment webhooks** — Cashfree callback events processed by `PaymentService`.
4. **Delivery webhooks** — SwiftDeliver callback to PHP delivery status endpoint.
5. **SwiftDeliver WebSocket events** — in-memory realtime messages to riders/admin/vendors.
6. **Vendor polling realtime** — frontend polling detection of new orders/bookings, not server push.
7. **Cron/background events** — PHP auto assignment, alert cleanup, story expiry, Brain nightly jobs.

No canonical event bus, queue, or durable outbox was traced.

## Alert Event Propagation

```mermaid
flowchart TD
  Producer[Order/Cron/Assignment/Payment/Admin] --> A[INSERT alerts]
  A --> Fetch[/api/alerts/fetch.php]
  Fetch --> Browser[AlertSystem polling]
  Browser --> Seen[/api/alerts/mark_seen.php]
```

Evidence:
- AlertEngine creates alert rows with dedup guard (`wtg  live/api/alerts/AlertEngine.php:50`, `wtg  live/api/alerts/AlertEngine.php:75`).
- Fetch is delta-based since timestamp (`wtg  live/api/alerts/AlertEngine.php:120`, `wtg  live/api/alerts/AlertEngine.php:150`).
- Mark seen validates recipient column (`wtg  live/api/alerts/AlertEngine.php:201`).
- Frontend AlertSystem polls (`wtg  live/assets/js/alerts.js:57`, `wtg  live/assets/js/alerts.js:204`).
- Alert config defines 3s vendor and 7s user polling (`wtg  live/api/alerts/config.php:11`).

Runtime dependency: `alerts` table, browser localStorage token, polling interval, server timestamps.
Operational impact: alerts are eventually observed by polling; no push guarantee.
Scale implications: polling load grows linearly with active users/vendors.
Maintainability impact: alert producers write rows directly in many places instead of a shared event publisher.
Confidence: High.

## Vendor Realtime Event Propagation

Vendor panel realtime is polling-based, not WebSocket.

Evidence:
- RealtimeEngine description says polling-based (`wtg  live/vendor/shared/realtime.js:1`).
- Polling core fetches items and compares against `_knownIds` (`wtg  live/vendor/shared/realtime.js:219`).
- First run seeds known IDs and suppresses notifications for existing items (`wtg  live/vendor/shared/realtime.js:231`).
- New items trigger notification/sound/popup/callback (`wtg  live/vendor/shared/realtime.js:240`).
- Vendor orders page starts realtime polling (`wtg  live/vendor/orders.php:39`).

Runtime dependency: fetch function correctness, browser tab running, poll interval, local in-memory known ID set.
Operational impact: page reload resets known IDs; events missed while browser closed are not replayed as popups.
Scale implications: every active vendor tab repeatedly hits list endpoints.
Maintainability impact: event detection is client-local and not shared with alerts.
Confidence: High.

## Brain Event Propagation

Brain event endpoint stores event and updates profile; Brain alert engine can create non-blocking alerts from event patterns.

Evidence:
- Brain routes include `/api/event` (`wtg  live/brain/routes.php:25`).
- EventController stores event and updates user score (`wtg  live/brain/Controllers/EventController.php:42`, `wtg  live/brain/Controllers/EventController.php:54`).
- Brain AlertEngine wraps methods in try/catch and checks delivery delay/high demand (`wtg  live/brain/engine/AlertEngine.php:6`, `wtg  live/brain/engine/AlertEngine.php:95`, `wtg  live/brain/engine/AlertEngine.php:162`).
- UserProfileModel documents event-based score updates (`wtg  live/brain/models/UserProfileModel.php:109`).

Runtime dependency: Brain API key, `brain_events`, `brain_profiles`, database availability.
Operational impact: Brain failures should not block Heart pipeline when called via BrainConnector; direct Brain event calls may fail independently.
Scale implications: Brain event counting and suggestion/rule engines can become DB-heavy.
Maintainability impact: Brain events are separate from operational alerts and order events.
Confidence: High.

## SwiftDeliver WebSocket Event Propagation

SwiftDeliver maintains in-memory WebSocket clients and broadcasts JSON messages.

Evidence:
- WebSocket server path `/ws` and client set (`swiftdeliver-main/server.ts:218`, `swiftdeliver-main/server.ts:221`).
- Rider location/order messages handled (`swiftdeliver-main/server.ts:237`).
- Broadcast helpers for admins, all riders, specific users, order events, notifications (`swiftdeliver-main/server.ts:261`, `swiftdeliver-main/server.ts:265`, `swiftdeliver-main/server.ts:269`, `swiftdeliver-main/server.ts:273`, `swiftdeliver-main/server.ts:287`).

Runtime dependency: single Node process memory, JWT validation, open WebSocket connections.
Operational impact: events are not durable; disconnected clients miss them unless separate polling/list APIs recover state.
Scale implications: in-memory broadcasts do not scale across multiple Node instances without shared pub/sub.
Maintainability impact: event names are string literals and not shared with PHP.
Confidence: High.

## Event Propagation Failure Modes

| Failure | Where | Behavior | Confidence |
|---|---|---|---|
| Alert duplicate burst | AlertEngine dedup window | duplicate alert suppressed | High |
| Alert frontend offline | AlertSystem polling | no event until next successful poll; server has row | High |
| Vendor realtime tab closed | RealtimeEngine | new items not popped; first open seeds known IDs | High |
| Brain event update fails | EventController try/catch for profile update | event still stored; profile may not update | High |
| SwiftDeliver WS disconnect | WebSocket memory | event missed; no durable WS replay traced | High |
| Delivery webhook failure | Node notifyPhpWebhook catch | logs error only | High |
| Payment webhook bad business event | webhook returns 200 on process failure | Cashfree stops retry for known-bad events | High |

## Major Finding — Events Are Fragmented, Not Orchestrated

WHERE: alerts, Brain events, SwiftDeliver WS, payment/delivery webhooks, frontend polling.
WHY: each subsystem implements its own event pattern.
ROOT CAUSE: no traced central event bus/outbox/queue or shared event contract.
CONNECTED SYSTEMS: customer/vendor/admin UI, Brain, delivery, payment, shopping, service.
OPERATIONAL IMPACT: events can be duplicated, missed, or reflected in one UI but not another.
SCALE IMPACT: polling and in-memory broadcasts limit realtime scalability.
MAINTAINABILITY IMPACT: future event changes require searching many independent producers/consumers.
FUTURE RISK: async consistency failures will grow as modules integrate more tightly.
Confidence: High.

