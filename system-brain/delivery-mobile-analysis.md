# Phase 08 — Delivery Mobile Analysis

## Rider Mobile Runtime Behavior Traced

SwiftDeliver is the only clearly traced rider/mobile delivery runtime.

Traced behavior:
- WebSocket endpoint `/ws` supports authenticated rider/admin/vendor realtime messages (`swiftdeliver-main/server.ts:218`).
- Rider `LOCATION_UPDATE` / `RIDER_UPDATE` updates rider `lat/lng` and broadcasts to admins (`swiftdeliver-main/server.ts:243`).
- Server emits ping every 25 seconds (`swiftdeliver-main/server.ts:257`).
- Rider can set `online/offline/busy` and submit lat/lng via `/api/riders/status` (`swiftdeliver-main/server.ts:769`).
- When a rider goes online, server sends up to 5 pending unassigned orders (`swiftdeliver-main/server.ts:776`).
- Rider self-accept is first-come/first-serve (`swiftdeliver-main/server.ts:805`).

Confidence: High for backend behavior; Medium for mobile UI specifics.

## Mobile Offline/Reconnect Weaknesses

### 1. Location drift

WHERE: rider location updates rely on active WebSocket messages or status posts.
WHY: no durable mobile background tracking mechanism was traced.
ROOT CAUSE: browser/mobile web constraints and in-memory WebSocket realtime model.
CONNECTED SYSTEMS: rider app, admin map, tracking page, assignment distance.
OPERATIONAL IMPACT: rider location can become stale after app sleep, tab close, or network loss.
SCALE IMPACT: dispatch accuracy worsens with more riders/orders.
MAINTAINABILITY IMPACT: future native app/background tracking would require new protocol and storage rules.
Evidence: WebSocket update handling (`swiftdeliver-main/server.ts:243`).
Confidence: Medium-High.

### 2. Missed order broadcasts

WHERE: `AVAILABLE_ORDER` events are pushed over WebSocket; replay is only partially handled when rider goes online.
WHY: event delivery is transient.
ROOT CAUSE: no durable per-rider available-order queue was traced.
CONNECTED SYSTEMS: WebSocket clients, pending order background retry, rider online status.
OPERATIONAL IMPACT: riders can miss orders during reconnect gaps.
SCALE IMPACT: retry broadcasts increase load and noise.
Evidence: broadcast (`swiftdeliver-main/server.ts:335`); online replay limit 5 (`swiftdeliver-main/server.ts:776`).
Confidence: High.

### 3. Status update during weak network

WHERE: rider status and order status are HTTP writes to Node only.
WHY: no offline queue/client retry semantics were traced.
ROOT CAUSE: web app relies on online request success.
CONNECTED SYSTEMS: rider dashboard, status route, assignment route, PHP sync.
OPERATIONAL IMPACT: pickup/delivery can happen physically while system state remains stale.
SCALE IMPACT: support escalations increase under poor connectivity zones.
Evidence: status update route (`swiftdeliver-main/server.ts:1125`).
Confidence: Medium.

## Customer Mobile Tracking

Tracking endpoint returns order plus rider location when tracking enabled.
Evidence: `GET /api/track/:token` (`swiftdeliver-main/server.ts:479`).

Limitations:
- Tracking token is SwiftDeliver-local.
- WorkToGo checkout dispatch does not visibly persist returned tracking token.
- Rider location is last-known from WebSocket/status updates.

Operational impact: customer tracking can be absent or stale even when delivery exists.
Confidence: High.

## Vendor Mobile Coordination

Vendor can mark order ready in SwiftDeliver; if an assignment exists, rider is notified.
Evidence: ready endpoint (`swiftdeliver-main/server.ts:650`).

Limitations:
- WorkToGo vendor sub-order status and SwiftDeliver vendor readiness are separate paths.
- If no rider assignment exists, ready signal only broadcasts to admin.
Evidence: assignment conditional (`swiftdeliver-main/server.ts:659`).

Operational impact: vendor ready state may not reach a rider if rider assignment has not occurred.
Confidence: High.

