# Phase 12 — Scaling Evolution Roadmap

Generated: 2026-05-14T06:36:00Z

---

## 1. Scaling Verdict

WorkToGo's first scaling barrier is MySQL load, followed by PHP worker saturation, polling read amplification, local filesystem assumptions, and human/admin recovery overload. Multi-city scaling is not primarily an infrastructure problem; it is a state ownership, partitioning, and operational control problem.

---

## 2. What Breaks First by Growth Type

| Growth Type | First Failure | Second Failure | Hidden Risk |
|---|---|---|---|
| More active users | Polling/auth DB reads | PHP worker saturation | localStorage stale session confusion |
| More orders | Inventory/order row locks | Delivery dispatch gaps | parent/child status drift |
| More services | Booking/job/provider ambiguity | Slot contention | no canonical provider workload model |
| More vendors | Intent/API drift | Admin moderation overload | stale vendor role/status claims |
| More riders/deliveries | SwiftDeliver/PHP state drift | WebSocket scaling | no durable delivery events |
| More cities | Global queries and assignment | Admin scope confusion | cross-city data/operations leakage |
| More media | Local disk growth | Backup/CDN gaps | upload security/storage risk |

---

## 3. Scaling Phases

### Phase A — Controlled Single-City Stability

Goal: ensure current architecture survives pilot traffic.

Required intelligence/hardening targets:

- Contract registry for routes/intents/statuses.
- DB index and hot-query review.
- Payment/delivery webhook monitoring.
- Cron assignment health checks.
- Admin incident playbook.
- Backup/restore validation.

### Phase B — Operational Scale Within One City

Goal: support more vendors/orders without changing core architecture recklessly.

Targets:

- Read models for dashboards/polling.
- Queue/outbox blueprint for dispatch/payment/notifications.
- Central logs and monitoring.
- Finance reconciliation reports.
- Vendor/provider workload visibility.

### Phase C — Horizontal Runtime Readiness

Goal: prepare for multiple PHP/Node instances.

Targets:

- Replace local cron locks with distributed locks.
- Move uploads to object storage.
- Centralize logs.
- Use shared cache/session/rate-limit substrate.
- Add WebSocket pub/sub or single authoritative realtime gateway.
- Ensure webhook idempotency across nodes.

### Phase D — Multi-City Readiness

Goal: add city/zone partitioning as operational truth.

Targets:

- City/zone fields and query scoping across vendors, services, orders, delivery, admin.
- City-scoped admin roles and dashboards.
- City-aware assignment radius and provider capability filtering.
- Delivery region partitioning and rider assignment rules.
- City-level capacity and SLA metrics.

### Phase E — Domain Evolution

Goal: extract or rewrite only after contracts are canonical.

Targets:

- Finance domain service.
- Service scheduling domain service.
- Delivery synchronization service.
- Admin operations service layer.
- Event/outbox infrastructure.

---

## 4. Multi-City Readiness Analysis

Current readiness: Low.

Why:

- Global admin views and queries are common.
- Assignment uses location proximity but lacks traced city/zone partitioning.
- Vendor/provider matching lacks service category, skill, vendor type, and radius enforcement in some flows.
- SwiftDeliver fallback and broadcast behavior is not city-safe.
- Role/permission model has no city-scoped admin boundary.

Safe next evolution:

1. City/zone as a first-class operational boundary.
2. City-aware query filters everywhere operational data is listed or assigned.
3. City-scoped admin permissions.
4. City-level delivery and provider capacity controls.
5. City-specific rollout gates.

---

## 5. Mobile Runtime Readiness Analysis

Current readiness: Medium-low.

Why:

- Browser/mobile web flows exist.
- Polling creates battery/network load.
- WebSocket delivery events are non-durable.
- Offline/reconnect semantics are weak.
- Frontend localStorage session can drift from live status.
- Media/uploads and maps/location behavior require deeper mobile hardening.

Safe next evolution:

- Push notification architecture instead of polling-heavy realtime.
- Offline-safe state refresh on app resume.
- Token refresh/revalidation strategy.
- Mobile-optimized media delivery.
- Durable delivery/order event replay for app reconnect.

---

## 6. Infrastructure Evolution Readiness

Current readiness: Low-medium.

Primary blockers:

- Local logs/cache/uploads/cron locks.
- Optional APCu/Redis behavior.
- Unknown PHP worker/resource limits.
- Unknown backup/restore/log shipping.
- WebSocket memory state in SwiftDeliver.
- External service retry/idempotency assumptions.

Safe infrastructure path:

1. Central observability.
2. Object storage for uploads.
3. Shared cache/rate-limit substrate.
4. Distributed locks for cron/assignment.
5. Queue/outbox for external calls.
6. DB scaling/index/read-model plan.
7. Pub/sub for WebSocket scaling.

