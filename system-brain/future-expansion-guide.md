# Phase 12 — Future Expansion Guide

Generated: 2026-05-14T06:36:00Z

---

## 1. Expansion Principle

Future WorkToGo features must be classified by the state domains they touch. The riskiest features are those crossing payment, delivery, booking, admin, vendor eligibility, and shared schema tables. Safe expansion requires contract and state ownership awareness before implementation.

---

## 2. Safe Versus Risky Future Features

| Feature Type | Risk | Why |
|---|---|---|
| Product browsing/display improvements | Lower | Product read path is comparatively cohesive |
| Cart UI improvements | Lower-medium | Cart service bounded, but checkout chain is risky |
| Static content/help/onboarding | Lower | Minimal operational state |
| Vendor profile display | Medium | Vendor status/type/role coupling |
| Vendor order/job operations | High | Intent drift and lifecycle status drift |
| Online payment expansion | Very High | Finance domain fragmentation |
| Refund/wallet/payout features | Very High | Ledger/reconciliation/audit gaps |
| Delivery tracking enhancements | High | Split PHP/SwiftDeliver truth |
| Multi-city launch | Very High | Missing city/zone operational partitioning |
| Admin permission features | High | Current admin model broad and monolithic |
| Push notifications/realtime | High | Fragmented events, polling, WS non-durability |
| Service scheduling/availability | Very High | Booking/job/slot/provider ambiguity |
| Native mobile app | High | Requires durable state refresh, push, offline handling |

---

## 3. Feature Intake Checklist

Every future feature must answer:

1. Which canonical state domain owns this feature?
2. Which tables will be read or written?
3. Does it use direct REST, Heart intent pipeline, or both?
4. Which frontend clients call it?
5. Which auth/role/vendor/admin boundary applies?
6. Which statuses or string intents are introduced?
7. What side effects happen after DB commit?
8. What happens if Cashfree/SwiftDeliver/Brain/cron fails?
9. Does admin override need to trigger lifecycle side effects?
10. Does the feature work in multi-city and mobile reconnect scenarios?

---

## 4. Rewrite-Pressure Zones

| Zone | Pressure | Expansion Guidance |
|---|---|---|
| Finance/payment/refund/ledger | Very High | Do not add major finance features before reconciliation design |
| Service booking/jobs/slots | High | Do not add complex scheduling before lifecycle unification |
| Admin API | High | Avoid adding broad logic to monolithic file without decomposition map |
| Vendor API contract | High | Freeze/validate intents before vendor expansion |
| Runtime router architecture | High | Avoid adding duplicate direct REST and intent paths for same action |
| Delivery synchronization | Medium-high | Add event/idempotency layer before advanced tracking/dispatch |
| Config/deployment layer | Medium-high | Document env/runtime assumptions before deployment expansion |

---

## 5. Future Architecture Recommendations

### Near-term

- Contract registry for route/intent/status/response vocabulary.
- State ownership map enforced in review.
- Finance and delivery reconciliation reports.
- Admin audit and side-effect tracking.

### Mid-term

- Durable outbox for payment, delivery, assignment, and notifications.
- Read models for dashboards and polling-heavy endpoints.
- Shared cache/rate-limit substrate.
- Centralized logging and operational metrics.
- Service lifecycle state machine.

### Long-term

- Finance domain service.
- Delivery synchronization service.
- Service scheduling/provider marketplace service.
- Scoped admin operations layer.
- Multi-city partitioned data model.
- Mobile push/reconnect event platform.

---

## 6. What Future Engineers and AI Systems Must Remember

- WorkToGo's file structure looks modular, but shared DB tables and route contracts are the real integration boundaries.
- Never infer canonical truth from a single controller.
- Admin routes may bypass normal lifecycle behavior.
- Direct REST and intent pipeline can produce different behavior.
- External delivery and payment state must be reconciled, not assumed.
- Frontend localStorage state is not authoritative.
- Polling and WebSockets are visibility mechanisms, not durable event systems.
- Local filesystem usage is infrastructure debt, not just implementation detail.

