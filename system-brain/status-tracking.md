# WTG System Brain Status Tracking

Last updated: 2026-05-14T06:36:00Z

---

## Phase 01 — Core Foundation Intelligence Mapping

Status: Completed initial foundation extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `core-foundation-map.md` | Overall system map, responsibilities, flow, couplings, final assumptions/uncertainties |
| `architecture-overview.md` | Architecture model, patterns, boundaries, naming, deployment assumptions |
| `dependency-map.md` | Runtime dependency graphs, route dependencies, intent-to-engine map |
| `runtime-bootstrap.md` | Bootstrap order, config/env usage, direct route and pipeline initialization |
| `folder-intelligence.md` | Folder ownership, dangerous structures, dead/legacy signals |
| `shared-services-map.md` | Shared backend/frontend service inventory and global state map |
| `architectural-risks.md` | Mandatory risk register and dangerous coupling analysis |

### Work Performed

- Inventoried `WTG-SYSTEM-BRAIN/` and confirmed no previous findings existed.
- Inspected active project structure under `wtg  live/`.
- Traced public entrypoint, Heart bootstrap, REST dispatch, intent pipeline, Brain bootstrap, body engine dispatch, frontend bootstraps, shared services, payment config, and deployment assumptions.
- Documented architecture, dependencies, runtime flow, module ownership, coupling risks, and recommended next tracing targets.

### Non-Goals Honored

- No product architecture modifications.
- No runtime code rewrites.
- No optimization or fixes applied.
- Documentation-only intelligence extraction.

### Next Phase Candidate

Recommended Phase 02: API Contract and Runtime Route Trace — map every frontend call and external API path to backend dispatcher, controller/service, auth requirement, database tables, and response shape.

---

## Phase 02 — Auth + User System Intelligence Mapping

Status: Completed documentation-only auth intelligence extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `auth-system-map.md` | End-to-end auth reality map covering login, registration, logout, guest, OTP, Google, roles, and operational flow |
| `auth-runtime-flow.md` | Runtime path from frontend storage through API clients, dispatchers, middleware, and downstream modules |
| `role-permission-map.md` | Role hierarchy, RBAC implementation, ownership checks, admin bypass, and role transition behavior |
| `auth-api-map.md` | Auth endpoint inventory and protected API surface map |
| `session-token-analysis.md` | Access token, refresh token, blacklist, expiry, storage, and revalidation analysis |
| `auth-security-risks.md` | Mandatory security risk register with WHERE/WHY/root cause/impact/future risk detail |
| `auth-db-relations.md` | Auth-related DB tables, indexes, constraints, and relationship map |
| `auth-dependency-map.md` | Backend/frontend/env/cross-module dependencies and uncertainty register |

### Work Performed

- Traced `/api/auth/*` routing through `heart/index.php` and `heart/api/auth/router.php`.
- Traced email/password login, email registration, legacy registration, OTP send/verify, Google login, guest login, refresh, logout, and `/me`.
- Traced `AuthMiddleware` and `JWT` behavior including bearer extraction, signature/expiry validation, role checks, admin bypass, and token blacklist lookup.
- Traced customer, vendor, and admin frontend token storage, route guards, API clients, unauthorized handling, and logout behavior.
- Traced major protected flows across admin, shopping, service, vendor, payment, content/feed, and alerts surfaces.
- Traced auth-related DB structures in `database-structure.sql`, including `users`, `vendors`, `refresh_tokens`, `token_blacklist`, and `otps`.
- Documented security risks, stale-token behavior, RBAC weaknesses, middleware inconsistencies, refresh-token gaps, guest uncertainty, and scale bottlenecks.

### Non-Goals Honored

- No auth code was changed.
- No middleware was modified.
- No permissions were redesigned.
- No architecture was patched.
- No optimization or security fix was applied.
- Documentation-only trace/map/analyze output was produced.

### Important Phase 02 Findings

- Auth is JWT-centered with bearer tokens stored in browser `localStorage` across customer, vendor, and admin surfaces.
- Refresh tokens exist server-side, but automatic frontend refresh/revalidation was not traced.
- Logout revocation is only consistently backend-called by admin; customer/vendor logout only clears local state.
- `AuthMiddleware::requireRole()` includes a global admin bypass.
- Role changes are not automatically reflected in existing JWTs.
- OTP verify appears to pass an absolute timestamp as a JWT duration, creating a likely overlong token lifetime.
- Permissions are distributed across controllers instead of a centralized policy system.
- Every protected request depends on DB availability for token blacklist checks.

### Next Phase Candidate

Recommended Phase 03: API Contract and Runtime Route Trace — map every frontend call and Heart intent to backend route, controller/service, auth requirement, request body, response shape, DB tables, and ownership guarantees.

---

## Phase 03 — Customer Flow Intelligence Mapping

Status: Completed documentation-only customer lifecycle intelligence extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `customer-flow-map.md` | End-to-end customer journey map covering onboarding, guest browsing, products, services, auth gates, detail/search gaps, and operational reality |
| `customer-runtime-flow.md` | Runtime SPA route/state/API behavior for Home, login, orders, bookings, account, polling, errors, and retries |
| `customer-api-map.md` | Customer frontend API calls, customer-relevant backend APIs, protected surfaces, and API gaps |
| `checkout-lifecycle.md` | Cart, checkout, address, payment, order placement, confirmation, and tracking lifecycle |
| `customer-state-map.md` | Customer state storage, transitions, order/booking status maps, notification state, and hidden state zones |
| `customer-mobile-analysis.md` | Mobile-oriented behavior, mobile friction points, and mobile uncertainty areas |
| `customer-risk-analysis.md` | Mandatory customer risk register with WHERE/WHY/root cause/connected systems/user/mobile/scale/future impact |
| `customer-dependency-map.md` | Customer dependency graph, journey dependencies, coupling map, and uncertainty register |

### Work Performed

- Traced customer SPA routes in `app/js/router.js` and page modules under `app/pages/`.
- Traced customer auth gates, guest browsing behavior, login/onboarding paths, session persistence, and local logout behavior.
- Traced Home product/service browsing, product order modal, service booking modal, orders page polling, bookings page polling, and account page behavior.
- Traced customer API client calls in `app/js/api.js` and mapped them to Heart intents or direct auth routes.
- Traced customer-relevant backend flows across shopping products, cart, orders, services/bookings, payment, vendors, alerts, and DB tables.
- Documented checkout lifecycle, cart persistence, address handling, COD-only current UI behavior, order/booking state transitions, notification inconsistencies, and hidden couplings.
- Documented customer friction points, mobile pain points, state mismatch risks, retry/failure behavior, scaling bottlenecks, and uncertainty zones.

### Non-Goals Honored

- No customer UX was redesigned.
- No frontend code was optimized.
- No APIs were rewritten.
- No business logic was changed.
- No validation was patched.
- No architecture was altered.
- Documentation-only trace/map/analyze output was produced.

### Important Phase 03 Findings

- Customer Home is public and supports guest browsing, but checkout/booking/history/account require login.
- Customer product ordering is a quick modal that silently uses backend cart before order creation; there is no visible cart page in the traced SPA.
- Checkout uses free-text delivery address despite an `addresses` table existing.
- Customer UI currently creates COD orders; separate online payment API exists but no traced customer SPA call uses it.
- Orders/bookings pages poll every 12 seconds and refresh on tab visibility, creating linear read-load growth.
- Orders page filtering uses status values that do not fully match backend order enum values.
- Notification button is placeholder in Home, while standalone AlertSystem is not visibly integrated and uses a mismatched token key.
- Search/filter/detail/favorites capabilities are partially backend-only or absent from the traced customer SPA.

### Next Phase Candidate

Recommended Phase 04: Commerce + Fulfillment Intelligence Mapping — deeply trace vendor assignment, order fulfillment, service job fulfillment, delivery dispatch, payment settlement, refunds, ledgers, alerts, and admin operational controls.

---

## Phase 04 — Commerce + Fulfillment Intelligence Mapping

Status: Completed documentation-only commerce and fulfillment lifecycle intelligence extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `commerce-fulfillment-map.md` | End-to-end product order fulfillment, service booking/job fulfillment, assignment, payment, refund, delivery, state, risk, and uncertainty map |
| `commerce-dependency-map.md` | Fulfillment dependency graph, route/intent map, data coupling map, and continuity notes from prior phases |
| `commerce-risk-analysis.md` | Focused commerce/fulfillment risk analysis with highest-risk findings and next phase recommendation |

### Work Performed

- Continued from existing WTG-SYSTEM-BRAIN continuity rather than restarting architecture assumptions.
- Reused Phase 01 route/bootstrap/dependency findings, Phase 02 auth/role findings, and Phase 03 customer checkout/lifecycle findings.
- Traced product order creation through cart loading, multi-vendor parent/child order split, item snapshots, inventory reservation, cart clearing, event hooks, and SwiftDeliver dispatch.
- Traced vendor order status transitions, delivery status changes, physical stock deduction, and reserved stock release behavior.
- Traced service booking creation through active service lookup, optional address validation, booking insert, optional payment creation, linked job creation, scoped booking listing, job status updates, and booking status mirroring.
- Traced auto assignment cron behavior including timeout handling, lock recovery, nearest-online-vendor selection, alerts, and no-vendor cancellation behavior.
- Traced direct assignment acceptance behavior including vendor profile resolution, entity status locking, accepted-assignment guard, entity mutation, and alerts.
- Traced payment and refund paths across `OrderService`, `/api/payment/create-order`, `PaymentService`, service booking payment calls, `RefundController`, transactions, and ledger refund call sites.
- Documented route/intent drift affecting vendor operations, parent/child order divergence, assignment-model conflict, payment fragmentation, dispatch uncertainty, transaction-bound refunds, and unresolved admin/vendor operational surfaces.

### Non-Goals Honored

- No fulfillment code was changed.
- No payment or refund logic was modified.
- No cron behavior was altered.
- No vendor/admin UX was redesigned.
- No dispatch or assignment logic was patched.
- Documentation-only trace/map/analyze output was produced.

### Important Phase 04 Findings

- Product order creation creates parent orders plus vendor sub-orders, but no parent status aggregation was traced when vendors update child order statuses.
- Product checkout is hard-locked to COD in `OrderService`, while separate online product payment APIs and service online payment paths still exist.
- Vendor frontend intents drift from AppRouter and engine switch names, creating a likely operational break for vendor order/job listing and status updates.
- Auto assignment targets parent product orders even though product checkout already creates vendor-specific sub-orders, creating a conceptual ownership conflict.
- Delivery dispatch is non-fatal and split between order creation and payment webhook paths.
- Refunds require transaction rows and therefore appear aligned primarily with online paid orders, not guaranteed COD orders.
- Service booking creates linked jobs and mirrors job status to booking status through the PATCH job path, but separate slot-reservation booking logic exists outside the main service request path.

### Next Phase Candidate

Recommended Phase 05: Vendor + Admin Operations Intelligence Mapping — deeply trace vendor frontend pages, admin operational dashboards, direct admin APIs, vendor profile/product/service management, admin order/refund/payment controls, and resolve the exact intent/API drift map without modifying runtime code.

---

## Phase 05 — Vendor + Admin Operations Intelligence Mapping

Status: Completed documentation-only vendor and admin operations intelligence extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `admin-operations-map.md` | Admin dashboard architecture, authority model, navigation surfaces, dashboard intelligence, intervention boundaries, and recovery behavior |
| `vendor-moderation-flow.md` | Vendor application, approval, rejection, activation/deactivation, relogin, restriction, and moderation uncertainty map |
| `admin-permission-map.md` | Admin permission hierarchy reality, frontend auth behavior, authority matrix, and hidden authority assumptions |
| `admin-api-map.md` | Admin route protection, endpoint inventory, frontend/backend drift, refund route conflict, and schema drift risks |
| `operational-control-map.md` | Operational control domains, decision propagation, event/audit propagation, and mobile/admin-vendor behavior |
| `escalation-recovery-analysis.md` | Escalation signals, traced recovery behavior, and hidden recovery gaps |
| `admin-risk-analysis.md` | Mandatory risk register for admin overreach, lifecycle bypass, vendor restriction gaps, route drift, audit weakness, and operational chaos risks |
| `operational-dependency-map.md` | Admin/vendor dependency graph and runtime dependency inventory |
| `admin-mobile-analysis.md` | Mobile admin/vendor operational behavior and mobile operational risks |

### Work Performed

- Continued from Phase 04 findings without modifying runtime code.
- Traced admin shell/navigation, config endpoints, shared API/auth modules, and page-level operational calls.
- Traced Heart admin dispatch and the monolithic `/api/admin/*` route file.
- Traced vendor application submission, duplicate handling, admin approval/rejection/status changes, and vendor panel role guard behavior.
- Traced admin user authority, vendor moderation authority, order intervention, delivery assignment, refund approval/rejection, settings/config controls, log/activity access, and audit logging points.
- Documented route conflicts, endpoint drift, schema vocabulary drift, localStorage auth key inconsistency, direct SQL intervention risks, and recovery gaps.

### Non-Goals Honored

- No admin architecture was modified.
- No moderation logic was rewritten.
- No permissions were patched.
- No dashboards were redesigned.
- No workflows or business rules were changed.
- Documentation-only trace/map/analyze output was produced.

### Important Phase 05 Findings

- Backend admin authority is effectively a single `ROLE_ADMIN` gate with no traced scoped admin permissions.
- Admin vendor approval mutates both `vendors.status` and `users.role`, but vendor deactivation only changes `vendors.status` and does not revoke role/token.
- Vendor panel guard checks local token/user role, not live vendor status.
- Admin order status/cancel routes directly update `orders.status`, bypassing `OrderService` lifecycle side effects such as inventory release and status transition rules.
- Admin frontend exposes several controls whose backend routes were not traced or do not match: product/service status toggles, system toggles, emergency stop, delivery detail, delivery settings save, and some config writes.
- `/api/admin/refunds` likely conflicts with admin router dispatch before RefundController can handle it, and the refunds page falls back to generated stub data.
- Admin queries show schema drift risks around `customer_id`, `rider_id`, `payments`, `merchants`, and current `user_id`, `driver_id`, `transactions`, `vendors` schema.
- Log purge gives admins ability to remove operational evidence; no immutable audit table was traced.

### Next Phase Candidate

Recommended Phase 06: Data Model + Schema Contract Intelligence Mapping — deeply trace live schema, migrations, table/column drift, legacy vocabulary (`merchant/rider/customer_id/payments`) versus active runtime contracts, foreign keys, indexes, data lifecycle, and cross-module schema risk.

---

## Phase 06 — Data Model + Schema Contract Intelligence Mapping

Status: Completed documentation-only database/schema contract intelligence extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `database-schema-map.md` | Baseline schema inventory, table ownership, high-risk tables, soft-delete behavior, missing runtime tables, and uncertainty register |
| `schema-contract-analysis.md` | Runtime schema contracts, major drift findings, contract confidence matrix, and future migration dangers |
| `table-relationship-map.md` | Explicit FK map, runtime-only assumptions, relationship diagrams, nullable-chain risks, duplicate ownership risks |
| `runtime-query-analysis.md` | Runtime read/write query patterns, high-write tables, join maps, bottleneck risks, and index assumptions |
| `schema-drift-analysis.md` | Table/column vocabulary drift register including vendor, delivery, payment, merchant/rider/customer legacy drift |
| `db-scaling-risks.md` | High-growth tables, query bottlenecks, operational scaling risks, and multi-city DB risks |
| `data-integrity-risks.md` | Mandatory data corruption, weak integrity, orphan, auditability, and lifecycle bypass risk register |
| `migration-risk-analysis.md` | Dangerous migration areas, break scenarios, and required pre-migration intelligence |
| `cross-module-db-coupling.md` | Cross-module table coupling, hidden dependency chains, and maintainability impact |
| `db-dependency-map.md` | Module-to-table dependency matrix and what-breaks-if-key-tables-change map |

### Work Performed

- Traced `wtg  live/database-structure.sql` for baseline tables, columns, indexes, auto-increments, and foreign keys.
- Traced migrations affecting auth identity nullability, vendor type naming, service fields, category fields, booking/delivery timestamps, and vendor/auth contract alignment.
- Traced runtime SQL across shopping cart/order/product flows, service booking/job flows, vendor/admin operations, payment/refund surfaces, delivery/assignment surfaces, alerts, and Brain models.
- Mapped explicit foreign keys and runtime-only relationship assumptions, including polymorphic references and missing FK protection.
- Documented schema drift around `vendors.vendor_type` vs `vendors.type`, `driver_id` vs `rider_id`, `transactions` vs `payments`, `vendors` vs `merchants`, `users.id` vs `customer_id`, and missing admin/Brain tables.
- Documented high-write/high-risk tables, nullable chains, duplicate ownership risks, soft-delete behavior, query bottleneck assumptions, scaling risks, and migration danger zones.

### Non-Goals Honored

- No schema was modified.
- No migrations were changed.
- No relations or indexes were patched.
- No queries were optimized.
- No database contract was normalized or redesigned.
- Documentation-only trace/map/analyze output was produced.

### Important Phase 06 Findings

- WorkToGo's real schema contract is a combination of baseline SQL dump, migrations, and embedded runtime SQL rather than one canonical model.
- Vendor schema drift (`vendor_type` vs `type`) is a central migration and runtime-break risk.
- Admin routes still reference legacy/non-baseline schema vocabulary including `customer_id`, `rider_id`, `payments`, `merchants`, `riders`, `merchant_id`, and `system_config`.
- Product checkout uses parent/child rows in the same `orders` table; parent status aggregation from vendor child status updates was not traced.
- Inventory is maintained as mutable counters (`quantity`, `reserved`) without an immutable movement ledger.
- Product variation stock is stored separately from checkout inventory reservation and is not traced as part of order inventory enforcement.
- Service booking creates booking + job, but main service request does not create a slot reservation; slot reservation is a separate path.
- `transactions`, `auto_assignments`, `alerts`, `followers`, and several Brain references use polymorphic relationships without FK protection.
- Delivery schema has severe driver/rider naming drift and status vocabulary mismatch.
- Future migrations are dangerous unless production schema state, orphan data, direct SQL references, and runtime route usage are validated first.

### Next Phase Candidate

Recommended Phase 07: Service Engine + Operational Lifecycle Intelligence Mapping — deeply trace service booking, provider assignment, scheduling/availability, job lifecycle, service operations, and Urban Company-style scaling risks.

---

## Phase 07 — Service Engine + Operational Lifecycle Intelligence Mapping

Status: Completed documentation-only service engine and operational lifecycle intelligence extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `service-engine-map.md` | Service runtime architecture, entities, category/provider/service creation model, booking-to-job design, and uncertainty register |
| `booking-lifecycle-map.md` | Customer booking sequence, booking/job states, alternate slot booking flow, cancellation/refund gaps, and divergence risks |
| `provider-assignment-analysis.md` | Direct vs auto assignment models, cron behavior, manual acceptance behavior, retry/deadlock analysis, and assignment conflict risks |
| `service-runtime-flow.md` | Customer/vendor/admin runtime flows, intent/REST route map, failure handling, and intent dispatcher drift |
| `job-lifecycle-analysis.md` | Job state sources, status route behavior, completion logic, escalation gaps, and lifecycle risk analysis |
| `service-api-map.md` | Customer, vendor, assignment, analytics, and admin service API map with auth, handlers, tables, and gaps |
| `service-scaling-risks.md` | Urban Company-style complexity gaps, provider overload risks, multi-provider conflicts, multi-city limitations, and query/data scaling risks |
| `operational-service-risks.md` | Mandatory operational risk register for slot bypass, booking/job divergence, provider assignment conflict, payment/refund weakness, retry gaps, notifications |
| `service-mobile-analysis.md` | Customer/provider mobile web behavior, polling limitations, provider operation weaknesses, and future mobile requirements |
| `service-dependency-map.md` | Module/table dependency matrix, what-breaks-if-service-logic-changes map, hidden dependencies, and dependency chains |

### Work Performed

- Continued from Phase 06 schema findings without modifying runtime behavior.
- Traced service engine internal dispatcher, Heart intent registry, direct Heart route dispatch, customer API calls, customer booking UI, customer bookings page, vendor job UI, vendor shared API layer, and vendor realtime polling behavior.
- Traced service listing/detail, service creation/update/delete, service booking creation, booking listing/detail, job status update, vendor job list/detail, vendor availability, slot checking, alternate slot-reservation booking controller, service analytics, auto assignment cron, and assignment acceptance.
- Documented service category structure, vendor/provider ownership, booking-to-job contract, availability/slot logic, assignment behavior, payment dependencies, alerts/notification behavior, admin visibility, mobile behavior, scaling limits, and uncertainty zones.

### Non-Goals Honored

- No service architecture was changed.
- No booking logic was rewritten.
- No assignment logic was patched.
- No scheduling or pricing rules were optimized.
- No operational workflow behavior was altered.
- Documentation-only trace/map/analyze output was produced.

### Important Phase 07 Findings

- Main customer service booking creates a booking and linked job but does not check vendor availability or create a slot reservation.
- Slot and reservation logic exists separately in `SlotController` and `BookingController`, but the alternate booking controller route was not traced in Heart direct dispatch.
- Service jobs are created with the service owner's vendor ID, while auto assignment can later offer the same job to a nearest active online vendor, creating provider ownership ambiguity.
- Auto assignment vendor search does not filter by service category, skill, service ownership, or vendor type; it filters active/online and distance only.
- Assignment acceptance updates job status/vendor directly and does not mirror status back to the linked booking.
- Auto assignment no-vendor failure cancels the job but does not mirror the booking to cancelled in the traced cron path.
- Customer booking APIs use `service:list_bookings`, but the service-engine internal dispatcher implements `service:vendor_bookings`, creating intent drift.
- Vendor job UI uses `vendor:list_jobs` and `vendor:update_job_status` intents, while service engine dispatcher does not visibly implement those cases; direct REST job endpoints do exist.
- Frontend service/vendor status vocabulary (`accepted`, `rejected`, `confirmed`, `delivered`) does not fully align with backend job and booking status contracts (`assigned`, `in_progress`, `completed`, `cancelled`).
- Service payment and refund lifecycle is fragmented: booking online payment initiation is traced, but booking transaction/refund completion is not.
- Urban Company-style scaling is risky without canonical provider assignment, slot integration, location capture, status state machine, refund/payout rules, provider workload controls, and push notifications.

### Next Phase Candidate

Recommended Phase 08: Payment, Ledger, Refund + Financial Reconciliation Intelligence Mapping — deeply trace `transactions`, Cashfree/payment helpers, booking/order payment divergence, COD/online behavior, refunds, ledger/wallet writes, provider/driver/platform settlement assumptions, and financial audit gaps.

---

## Phase 08 — Delivery + Location System Intelligence Mapping

Status: Completed documentation-only delivery and location intelligence extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `delivery-system-map.md` | End-to-end delivery system reality map across WorkToGo PHP, SwiftDeliver Node, manual admin assignment, vendor/provider auto-assignment, and known contradictions |
| `dispatch-lifecycle-map.md` | Dispatch models, lifecycle diagrams, retry/recovery behavior, and dispatch state risks |
| `delivery-runtime-flow.md` | Runtime delivery entry points, state propagation, dependencies, offline/reconnect behavior, and API execution paths |
| `location-logic-analysis.md` | Location sources, distance logic, city/area/zone handling, radius behavior, and multi-city location ambiguity |
| `delivery-api-map.md` | PHP delivery/admin APIs and SwiftDeliver APIs with auth, behavior, drift, and permission risks |
| `delivery-state-analysis.md` | Status vocabulary map, state transitions, order/delivery divergence, cancellation behavior, and consistency risks |
| `multi-city-scaling-risks.md` | Multi-city dangers around missing city partitioning, radius enforcement, city fallback, and global admin/rider operations |
| `delivery-operational-risks.md` | Mandatory operational risk register for assignment inconsistency, rider/driver drift, delivery/order divergence, tracking, recovery, cancellation, and fulfillment sync |
| `delivery-mobile-analysis.md` | Rider/customer/vendor mobile delivery behavior, WebSocket/location limitations, offline/reconnect weaknesses, and tracking limitations |
| `delivery-dependency-map.md` | Delivery dependency graph, runtime/table dependency matrix, hidden couplings, and what-breaks-if-delivery-changes map |
| `delivery-mobile-runtime-uncertainties.md` | Explicit uncertainty register for partially traced delivery flows, hidden dependencies, runtime assumptions, tracking ambiguity, and scaling uncertainty |

### Work Performed

- Continued from prior schema, service, commerce, customer, auth, and operations intelligence without modifying runtime code.
- Traced WorkToGo PHP delivery APIs, admin delivery assignment/listing routes, order dispatch hooks, vendor order fulfillment status transitions, payment/ledger delivery coupling, auto-assignment cron, assignment acceptance, geospatial helpers, and baseline schema contracts.
- Traced SwiftDeliver Node/Railway server runtime including SQLite schema, dispatch intake, rider self-accept flow, WebSocket location updates, rider status handling, tracking endpoint, vendor ready endpoint, order status transitions, retry jobs, notifications, and admin reassignment.
- Mapped delivery lifecycles, dispatch models, assignment behavior, status transitions, tracking flow, location/city/radius behavior, mobile/offline behavior, and cross-system synchronization risks.
- Documented driver/rider vocabulary drift, WorkToGo/SwiftDeliver state divergence, parent/child order delivery ambiguity, no-zone multi-city scaling risks, dispatch retry gaps, cancellation divergence, and fulfillment synchronization risks.

### Non-Goals Honored

- No delivery architecture was modified.
- No dispatch logic was rewritten.
- No tracking optimization was performed.
- No assignment behavior was altered.
- No location handling was redesigned.
- No delivery workflow or operational rule was patched.
- Documentation-only trace/map/analyze output was produced.

### Important Phase 08 Findings

- WorkToGo delivery is split between PHP/MySQL delivery rows and SwiftDeliver Node/SQLite rider operations; there is no single canonical delivery truth.
- Product checkout dispatch is non-fatal after order commit, so orders can exist without successful delivery-network creation.
- SwiftDeliver `/api/dispatch` sends WorkToGo a webhook status of `assigned` before any rider self-accepts, so WorkToGo can show processing while no rider owns the order.
- Node status transitions for pickup/delivered/cancelled/failed were traced locally, but a callback to WorkToGo PHP was not traced in the status update route.
- MySQL baseline `deliveries` uses `driver_id`, while PHP webhook/admin listing code references `rider_id`, creating severe schema/vocabulary drift.
- WorkToGo vendor/provider auto-assignment is not rider dispatch; it assigns vendors/jobs/orders via `auto_assignments` and can conflict conceptually with delivery assignment terminology.
- PHP auto-assignment loads `vendor_search_radius` but the traced nearest-vendor query does not apply the radius and does not filter by city, vendor type, service category, or zone.
- SwiftDeliver uses one city-center fallback (`city_lat`, `city_lng`) and broadcasts to all online riders if none are in radius, making multi-city scaling dangerous.
- WorkToGo checkout dispatch sends only `order_id` and `delivery_id` to SwiftDeliver in the traced automatic path, so customer/address/vendor/tracking fidelity depends on defaults or untraced enrichment.
- Tracking is SwiftDeliver-local; automatic WorkToGo checkout dispatch does not visibly persist the returned tracking token.
- Cancellation, delivery completion, vendor fulfillment, inventory release, rider earnings, WorkToGo order status, and SwiftDeliver status are distributed across separate paths with no traced canonical state machine.

### Next Phase Candidate

Recommended Phase 09: Payment, Ledger, Refund + Financial Reconciliation Intelligence Mapping — deeply trace `transactions`, Cashfree/payment helpers, COD vs online divergence, refunds, ledger/wallet writes, provider/vendor/driver/platform settlement assumptions, delivery-payment coupling, and financial audit gaps.

---

## Phase 09 — API + Runtime + Event System Intelligence Mapping

Status: Completed documentation-only API, runtime, and event orchestration intelligence extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `api-architecture-map.md` | API architecture reality map covering direct REST, Heart intent pipeline, SwiftDeliver runtime, route boundaries, frontend client boundaries, and contract risks |
| `runtime-orchestration-map.md` | Heart runtime lifecycle, engine/Brain/priority orchestration, direct REST bypass behavior, transaction boundaries, and orchestration risks |
| `event-propagation-analysis.md` | Event system inventory covering alerts, Brain events, payment/delivery webhooks, SwiftDeliver WebSockets, vendor polling, and cron/background events |
| `webhook-runtime-analysis.md` | Cashfree, SwiftDeliver, and Brain integration webhook behavior, verification, idempotency, retries, and failure risks |
| `state-synchronization-map.md` | State synchronization domains and flows for orders, bookings/jobs, payment, delivery, alerts, Brain, frontend session, and polling/realtime surfaces |
| `runtime-failure-analysis.md` | Failure handling, retry/recovery behavior, deadlock/lock risks, bottlenecks, and log-only recovery gaps |
| `api-contract-drift.md` | Contract drift register for direct REST vs intent routes, schema/API drift, auth token drift, status drift, and frontend/backend mismatch |
| `realtime-scaling-risks.md` | Polling/WebSocket realtime scaling risks, missed event replay gaps, mobile coordination weaknesses, and future realtime limits |
| `runtime-dependency-map.md` | Runtime dependency graph, critical dependencies, auth coupling, what-breaks-if-runtime-changes map, and hidden assumptions |
| `async-processing-analysis.md` | Async/background processing inventory, queue assumptions, idempotency protections, weak idempotency areas, and async consistency risks |
| `runtime-uncertainty-register.md` | Explicit uncertainty register for partially traced runtime flows, hidden dependencies, sync ambiguity, realtime uncertainty, and future inspection areas |

### Work Performed

- Continued from prior system intelligence without modifying runtime behavior.
- Traced `heart/index.php` bootstrap, direct REST dispatcher boundaries, global guards, route ordering, fallback to Heart pipeline, and error handling.
- Traced `heart/router.php` intent pipeline lifecycle including JSON validation, rate limiting, context building, route resolution, Reflex cache, Brain connector, engine dispatch, priority resolution, response building, and cache storage.
- Traced engine orchestration through `EngineLoader`, including internal include calls, external cURL calls, parallel cURL multi, headers, payloads, response parsing, timeouts, and failed engine handling.
- Traced Brain orchestration including optional route-level usage, internal/HTTP calls, circuit breaker, fallback behavior, and action/webhook propagation.
- Traced frontend runtime clients for customer SPA API, customer hash router polling, vendor direct API and polling realtime, admin shared API/auth, and shared alert polling behavior.
- Traced webhook behavior for Cashfree payment callbacks, SwiftDeliver delivery callbacks, and Brain integration webhooks.
- Traced event-like behavior across alerts, Brain events, vendor polling, SwiftDeliver WebSockets, cron/background scripts, and post-commit side effects.
- Documented synchronization gaps, runtime failure handling, idempotency protections, missing queues/outboxes, polling/reconnect weaknesses, contract drift, hidden dependencies, and realtime scaling limitations.

### Non-Goals Honored

- No APIs were rewritten.
- No runtime optimization was performed.
- No events were patched.
- No queues were redesigned.
- No request handling was altered.
- No websocket/realtime behavior was changed.
- No runtime architecture was modified.
- Documentation-only trace/map/analyze output was produced.

### Important Phase 09 Findings

- WorkToGo has two active PHP API models: direct REST dispatch in `heart/index.php` and intent-based orchestration in `heart/router.php`; direct REST bypasses Reflex cache, Brain, engine priority resolution, and pipeline response shaping.
- Heart intent runtime is synchronous orchestration with optional Brain, optional Reflex cache, engine dispatch, priority resolution, and best-effort cache storage; no durable central event bus was traced.
- Engine calls can be internal PHP includes or external cURL calls; multi-engine HTTP dispatch uses `curl_multi`, while local engine includes mutate `$_SERVER` and `GLOBALS['HEART_PAYLOAD']`.
- Brain is optional and circuit-broken only when APCu is available; without APCu, circuit/rate-limit behavior degrades.
- Event propagation is fragmented across alert table polling, Brain events, Cashfree webhook, SwiftDeliver webhook, SwiftDeliver in-memory WebSockets, vendor polling detection, and cron/background scripts.
- Vendor panel “realtime” is polling with a local known-ID set, not server push; existing items on first load are seeded and do not trigger popups.
- Customer orders/bookings auto-refresh via SPA polling and visibility refresh; polling errors are silently retried on the next interval.
- Cashfree webhook has signature verification and partial idempotency, but returns HTTP 200 for known processing failures, requiring internal log monitoring.
- SwiftDeliver delivery webhook applies status directly without a traced durable webhook-event ledger or monotonic transition guard.
- No durable queue, message broker, central outbox, failed-event table, or replayable event stream was traced in the PHP runtime.
- API contract drift exists across direct REST, intent registry, engine handlers, frontend clients, schema vocabulary, token key usage, and external delivery runtime.
- Runtime recovery is largely log-based or polling/cron-based; many post-commit side effects can fail after user-visible success.

### Next Phase Candidate

Recommended Phase 10: Payment, Ledger, Refund + Financial Reconciliation Intelligence Mapping — deeply trace `transactions`, Cashfree/payment helpers, COD vs online divergence, refunds, ledger/wallet writes, provider/vendor/driver/platform settlement assumptions, delivery-payment coupling, financial idempotency, and audit/reconciliation gaps.

---

## Phase 10 — Security + Performance + Scalability Intelligence Mapping

Status: Completed documentation-only security, performance, and scalability intelligence extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `security-boundary-map.md` | Auth, role, admin, webhook, upload, and enforcement boundary map with trace-backed risk findings |
| `attack-surface-analysis.md` | API/webhook/upload/cron/polling attack-surface inventory and abuse-vector analysis |
| `runtime-bottleneck-analysis.md` | Runtime bottleneck map covering auth DB dependency, Heart pipeline, cache/rate controls, admin queries, checkout writes |
| `scalability-risk-map.md` | Scale boundaries, multi-city/horizontal scaling limits, high-write contention zones, operational scale risks |
| `performance-hotspots.md` | Query, polling, checkout, booking, admin, alert, and mobile performance hotspot analysis |
| `synchronization-scale-risks.md` | Polling/realtime, post-commit side effects, delivery/payment synchronization, parent/child order drift risk analysis |
| `operational-failure-scenarios.md` | Production failure scenarios and what fails first under DB, delivery, webhook, cron, admin, and media stress |
| `mobile-performance-analysis.md` | Mobile-specific polling, media, checkout, and network/battery performance risks |
| `infrastructure-assumption-map.md` | Environment, filesystem, APCu/Redis, proxy/CORS, MySQL, Cashfree, SwiftDeliver, and deployment assumption map |
| `recovery-disaster-analysis.md` | Recovery mechanisms, disaster weaknesses, outbox/replay gaps, backup/log/media uncertainty analysis |
| `phase-10-security-performance-scalability-intelligence.md` | Cross-domain synthesis of vulnerabilities, collapse points, infrastructure assumptions, and uncertainty register |

### Work Performed

- Continued from prior WTG System Brain phases without modifying runtime code.
- Traced JWT authentication, blacklist enforcement, admin bypass, role checks, stale-token behavior, admin APIs, service/shopping/delivery route protection, and legacy/controller consistency gaps.
- Traced direct REST dispatcher and Heart pipeline behavior, including CORS guards, content-length/JSON checks, rate limiting, Reflex cache, Brain connector, engine fanout, synchronous cURL/internal includes, and response/error behavior.
- Traced Cashfree webhook signature/timestamp verification, payment webhook transaction/idempotency logic, transaction insertion, failure acknowledgement behavior, and SwiftDeliver dispatch side effects.
- Traced SwiftDeliver delivery callback boundary, delivery/order status mutation, and lack of traced webhook event ledger or monotonic transition guard.
- Traced checkout, cart, inventory reservation/deduction, parent/vendor sub-order creation, order rate limit, booking/slot contention, alert polling, admin dashboard aggregates, cron auto-assignment, local filesystem cache/log/upload/lock assumptions, and recovery limitations.
- Documented security risks, performance hotspots, DB/write contention zones, polling/realtime scaling costs, hidden infrastructure assumptions, mobile risks, multi-city/horizontal scaling boundaries, and production failure scenarios.

### Non-Goals Honored

- No vulnerabilities were patched.
- No queries were optimized.
- No auth or permission model was redesigned.
- No caching, queue, webhook, or infrastructure behavior was changed.
- No runtime/deployment behavior was altered.
- Documentation-only trace/map/analyze output was produced.

### Important Phase 10 Findings

- WorkToGo security boundaries are real but fragmented across direct REST routes, Heart intents, legacy controllers, webhooks, cron, and external integrations.
- Every protected request depends on MySQL for token blacklist enforcement, making DB health a security and availability boundary.
- Admin has a broad superuser blast radius through global admin bypass and a monolithic admin API surface.
- JWT role/status claims can drift after role changes, vendor approval, suspension, or deactivation unless tokens are refreshed/revoked.
- Payment webhook has HMAC/timestamp/amount checks and row-lock idempotency, but no fully traced webhook-event ledger and known processing failures are acknowledged with HTTP 200.
- Delivery webhook uses shared-secret authentication and direct state mutation without traced replay/idempotency/monotonic transition protection.
- Checkout, inventory, booking slots, payment processing, alerts, and auto-assignment are DB-centered high-contention zones.
- Polling-based realtime creates linear DB and auth-read growth across customers, vendors, admins, and alerts.
- APCu-dependent rate limiting/circuit breaking can degrade or become per-node under missing APCu/horizontal scaling.
- Local filesystem assumptions for logs, Reflex file cache, uploads, and cron lock are major horizontal-scaling and disaster-recovery boundaries.
- MySQL fails first under growth, followed by PHP worker saturation from synchronous cURL/fanout and lock-heavy writes.
- Recovery is mostly log/manual/cron/provider-retry based; no durable queue, outbox, dead-letter table, or replayable event stream was traced.

### Next Phase Candidate

Recommended Phase 11: Financial Reconciliation + Settlement Intelligence Mapping — deeply trace transactions, refunds, ledgers, wallet writes, COD vs online divergence, provider/vendor/driver/platform settlement assumptions, reconciliation workflows, missing audit trails, and production finance failure modes.

---

## Phase 11 — Production Stability + Technical Debt Intelligence Mapping

Status: Completed documentation-only production stability and technical debt intelligence extraction.

### Completed Outputs

| File | Purpose |
|---|---|
| `technical-debt-map.md` | System-wide debt heat map, stable zones, major debt findings, and uncertainty register |
| `production-stability-analysis.md` | Production stability map, request/DB/filesystem/deployment stability findings, and first-failure analysis |
| `fragile-module-analysis.md` | Module-by-module stability classification and rewrite-pressure module findings |
| `hidden-coupling-analysis.md` | Hidden dependency/coupling map across frontend intents, Heart, engines, DB, admin, payment, delivery, and Brain |
| `maintainability-risk-map.md` | Maintainability risk matrix covering response drift, validation drift, frontend clients, and human dependency risks |
| `rewrite-pressure-analysis.md` | Rewrite pressure ranking and analysis for finance, service booking, admin, runtime, and delivery domains |
| `engineering-velocity-risks.md` | Development velocity collapse drivers, feature/debug/schema change risks, and human knowledge bottlenecks |
| `operational-collapse-scenarios.md` | Production collapse scenarios for vendor workflows, finance incidents, admin overload, and deployment/schema drift |
| `stabilization-priority-matrix.md` | Trace-based stabilization priority order for future safe evolution without prescribing runtime changes |
| `long-term-sustainability-analysis.md` | Long-term sustainability risks across architecture evolution, finance ownership, horizontal growth, and human ops |
| `phase-11-production-stability-technical-debt-intelligence.md` | Cross-domain synthesis of stable parts, dangerous parts, velocity risks, rewrite zones, and uncertainty register |

### Work Performed

- Continued from Phase 10 and prior system brain intelligence without modifying runtime behavior.
- Traced customer, vendor, and admin frontend API clients and their different route, token, timeout, and response assumptions.
- Traced Heart direct REST dispatcher, Heart intent pipeline, AppRouter intent registry, Body engine internal switches, and runtime superglobal mutation used by internal includes.
- Traced configuration sprawl across bootstrap, Heart entrypoint, DB config, engine config, deploy hook, migration runner, payment helpers, and `.htaccess` hosting assumptions.
- Traced fragile module zones including shopping orders, product catalog, service bookings/jobs/slots, admin API, payment/refund/ledger, delivery callbacks, Brain routes, cron auto-assignment, and migration/deployment tooling.
- Documented hidden coupling layers, runtime instability risks, operational instability risks, maintainability weaknesses, naming inconsistency impact, dead/legacy coexistence risks, deployment fragility, recovery instability, engineering velocity risks, future rewrite pressure, and long-term sustainability risks.

### Non-Goals Honored

- No architecture was rewritten.
- No technical debt was patched.
- No runtime optimization was performed.
- No modules, APIs, deployment behavior, or flows were altered.
- No cleanup or refactor was applied.
- Documentation-only trace/map/analyze output was produced.

### Important Phase 11 Findings

- WorkToGo's strongest stability zones are small cohesive units: JWT helper, product catalog read service, cart service, and cache driver interface.
- The most fragile zones are payment/refund/ledger, service bookings/jobs/slots, admin API, vendor API contract, direct REST versus intent pipeline split, and local filesystem infrastructure assumptions.
- Technical debt is primarily architectural drift: multiple route models, multiple response envelopes, multiple payment helpers, duplicate booking paths, duplicated env parsing, and shared DB tables acting as cross-module APIs.
- Vendor operations are high risk because frontend intent names do not fully align with Heart/engine intent switches.
- Admin operations are high risk because one monolithic file controls many unrelated operational domains and queries live production tables.
- Financial logic has very high rewrite pressure due to distributed responsibilities across payment webhook, `PaymentService`, `Payment`, `RefundController`, and `LedgerEngine`.
- Service bookings have high rewrite pressure because slot-reservation booking and service-request/job booking coexist with different contracts.
- Engineering velocity will degrade around route tracing, schema changes, financial changes, vendor feature work, and production incident debugging.
- Long-term sustainability depends on future contract consolidation, domain ownership, schema ownership, recovery/event modeling, and infrastructure evolution.

### Next Phase Candidate

Recommended Phase 12: Financial Reconciliation + Settlement Intelligence Mapping — deeply trace transaction lifecycle, COD versus online divergence, refunds, wallet transactions, vendor/driver/platform settlement, ledger idempotency, reconciliation reports, finance admin operations, and auditability gaps.

---

## Phase 12 — Final Consolidated System Brain + Production Intelligence Synthesis

Status: Completed final consolidated documentation-only engineering intelligence synthesis.

### Completed Outputs

| File | Purpose |
|---|---|
| [`master-system-brain.md`](WTG-SYSTEM-BRAIN/master-system-brain.md) | Permanent consolidated WTG system brain, architecture truths, stable/dangerous zones, bottlenecks, scaling/stabilization order, and production maturity verdict |
| [`architecture-dna.md`](WTG-SYSTEM-BRAIN/architecture-dna.md) | Architecture DNA map covering subsystem responsibilities, runtime orchestration reality, coupling patterns, invariants, and future architecture direction |
| [`production-readiness-report.md`](WTG-SYSTEM-BRAIN/production-readiness-report.md) | Launch readiness analysis, production fragility synthesis, operational bottlenecks, and hardening order |
| [`stabilization-blueprint.md`](WTG-SYSTEM-BRAIN/stabilization-blueprint.md) | Safe stabilization matrix and phased hardening blueprint without prescribing runtime changes |
| [`critical-risk-register.md`](WTG-SYSTEM-BRAIN/critical-risk-register.md) | Consolidated critical systemic risk register with root cause, connected systems, propagation, impact, scale impact, priority, and evolution strategy |
| [`canonical-state-map.md`](WTG-SYSTEM-BRAIN/canonical-state-map.md) | Canonical state ownership map and synchronization weakness register for orders, payments, bookings, delivery, auth, alerts, and Brain state |
| [`scaling-evolution-roadmap.md`](WTG-SYSTEM-BRAIN/scaling-evolution-roadmap.md) | Scaling roadmap for single-city stability, operational scale, horizontal readiness, multi-city readiness, mobile readiness, and infrastructure evolution |
| [`future-expansion-guide.md`](WTG-SYSTEM-BRAIN/future-expansion-guide.md) | Future feature risk classification, rewrite-pressure zones, expansion checklist, and long-term architecture recommendations |
| [`engineering-operating-manual.md`](WTG-SYSTEM-BRAIN/engineering-operating-manual.md) | Onboarding and debugging manual for engineers, AI systems, and operators |
| [`final-production-readiness-score.md`](WTG-SYSTEM-BRAIN/final-production-readiness-score.md) | Evidence-based maturity scoring across backend, frontend, runtime, security, scalability, operations, maintainability, mobile, multi-city, production, and sustainability |

### Work Performed

- Consolidated prior system intelligence from all existing [`WTG-SYSTEM-BRAIN/`](WTG-SYSTEM-BRAIN/) phase outputs.
- Reused and synthesized the high-signal findings from architecture, auth, customer, commerce, vendor/admin, schema, service, delivery, API/runtime/events, security/performance/scalability, and production stability/technical debt phases.
- Cross-referenced stable zones, dangerous zones, recurring root-cause patterns, synchronization risks, competing truth layers, scaling barriers, operational bottlenecks, rewrite-pressure zones, and future evolution paths.
- Produced the final WorkToGo engineering brain, operating manual, state map, risk register, stabilization blueprint, scaling roadmap, expansion guide, and readiness scoring.

### Non-Goals Honored

- No architecture was modified.
- No systems were patched.
- No flows were altered.
- No modules were rewritten.
- No infrastructure was optimized.
- No runtime behavior was changed.
- Documentation-only consolidation, synthesis, cross-reference, mapping, analysis, and intelligence exposure were performed.

### Final Phase 12 Findings

- WorkToGo is a synchronous PHP/MySQL multi-sided marketplace/service/delivery platform with a Heart/Body/Brain/Core conceptual architecture and a DB-centered production truth layer.
- The strongest systems are small cohesive helpers and services such as JWT, product reads, cart, and cache abstraction.
- The most dangerous systems are payment/refund/ledger, service booking/job/slot lifecycle, admin monolith, vendor API contract, delivery synchronization, direct REST versus intent split, local filesystem infrastructure, and shared DB schema coupling.
- MySQL fails first at scale, followed by PHP worker saturation, polling amplification, local filesystem limits, and human/admin recovery overload.
- Synchronization fails where multiple truth layers coexist: parent/child orders, bookings/jobs/slots, payment/order/transaction/ledger state, PHP/SwiftDeliver delivery state, JWT/users/vendors identity state, and fragmented event systems.
- The safest stabilization order is contract registry, finance hardening, delivery synchronization, service lifecycle unification, admin boundary/audit, deployment/filesystem assumptions, DB ownership, then durable event/outbox recovery.
- The safest scaling order is single-city stability, operational read-model/observability improvements, horizontal infrastructure readiness, city/zone partitioning, then domain extraction.
- Final overall production readiness score is 46 / 100: controlled production pilot readiness, not mature scalable multi-city production readiness.

