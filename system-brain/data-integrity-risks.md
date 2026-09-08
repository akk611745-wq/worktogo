# Phase 06 — Data Integrity Risks

Generated: 2026-05-12T16:20:00Z

## Mandatory Risk Register

### 1. Admin direct order mutation bypasses domain side effects

- Where: admin order status/cancel endpoints directly update `orders.status`.
- Why: admin router does not call `OrderService` lifecycle methods.
- Root cause: operational control shortcuts.
- Connected systems: orders, order_items, inventory, delivery, alerts, refunds, customer/vendor UI.
- Operational impact: inventory reservations may not release, child orders may not synchronize, parent status may mislead users.
- Scale impact: stuck inventory and inconsistent order states accumulate with admin interventions.
- Maintainability impact: future status rules must be duplicated in admin unless centralized.
- Future risk: irreversible stock/accounting corruption after incident response.
- Confidence: High.

### 2. Polymorphic references can orphan

- Where: `transactions`, `alerts`, `auto_assignments`, `followers`, `wallet_transactions`.
- Why: type/id pairs cannot use normal FK constraints.
- Root cause: flexible polymorphic modeling without validation/cleanup layer.
- Connected systems: payment/refund, assignment, notification, feed, ledger.
- Operational impact: stale references can trigger wrong alerts, failed refunds, duplicate assignments.
- Scale impact: orphan cleanup becomes expensive and risky.
- Maintainability impact: migrations require custom validators.
- Future risk: data corruption hidden until operational edge cases.
- Confidence: High.

### 3. Inventory counters lack immutable ledger

- Where: `inventory.quantity`, `inventory.reserved`, `products.total_sold`.
- Why: counters mutate directly on checkout/cancel/deliver/product update.
- Root cause: no inventory movement/history table traced.
- Connected systems: product management, cart, checkout, delivery, admin product deletes.
- Operational impact: cannot audit why stock changed; manual correction risk.
- Scale impact: high contention and difficult reconciliation.
- Maintainability impact: future inventory features require reconstructing missing history.
- Future risk: oversell/undersell disputes cannot be reliably investigated.
- Confidence: High.

### 4. Parent/child order state can diverge

- Where: `orders.parent_order_id` model.
- Why: customer sees parent, vendors mutate children, delivery attaches to parent.
- Root cause: no traced aggregate status updater.
- Connected systems: customer orders, vendor orders, admin, delivery, payment/refund.
- Operational impact: user may see pending while vendor child delivered, or admin cancels parent while children remain active.
- Scale impact: multi-vendor order complexity grows with vendor count per order.
- Maintainability impact: every status feature must update multiple rows.
- Future risk: refunds/settlements applied to wrong order level.
- Confidence: High.

### 5. Slot reservation is not guaranteed for service booking

- Where: main service request inserts booking/job but no `booking_slot_reservations` row.
- Why: slot logic is in separate controller path.
- Root cause: booking lifecycle split across endpoints.
- Connected systems: service booking, vendor availability, jobs, auto assignment.
- Operational impact: double-booking risk if frontend does not use reservation path.
- Scale impact: vendor capacity conflict increases with demand.
- Maintainability impact: future scheduling changes must reconcile two flows.
- Future risk: calendar correctness cannot be guaranteed.
- Confidence: High.

### 6. Missing/weak auditability

- Where: admin changes, inventory changes, status transitions, settings changes.
- Why: `logs` exists but no immutable audit table; admins can purge logs.
- Root cause: operational evidence stored as mutable/deletable logs.
- Connected systems: admin operations, refunds, order status, vendor moderation, settings.
- Operational impact: hard to investigate abuse or mistakes.
- Scale impact: larger teams require stronger accountability.
- Maintainability impact: future compliance features need new audit schema.
- Future risk: disputes cannot be proven from database history.
- Confidence: High.

### 7. Duplicate identity/permission ownership

- Where: `users.role` and `vendors.status/type`.
- Why: admin approval sets both, deactivation only sets vendor status.
- Root cause: role and vendor state are separate sources of truth.
- Connected systems: auth, vendor panel, admin moderation, products/services/orders/jobs.
- Operational impact: users can retain vendor role after vendor deactivation; JWTs can remain stale.
- Scale impact: moderation gaps widen with more vendors/admins.
- Maintainability impact: permission checks must include both role and vendor status everywhere.
- Future risk: inactive vendors retain write access through endpoints that only check role and resolve vendor loosely.
- Confidence: High.

## Orphaned Data Risks

- `wallet_transactions.order_id` has no FK.
- `orders.delivery_id` has no FK.
- `transactions.reference_id` has no FK.
- `alerts.ref_id` has no FK.
- `followers.target_id` has no FK.
- `brain_*` client/action/decision references mostly lack FKs.
- Soft-deleted `users/vendors/products/services` leave historical references active by design, but active queries filter inconsistently.

## Data Corruption Risk Summary

| Risk | Severity | Confidence |
|---|---:|---:|
| Inventory reserved stuck after admin cancel/status | Critical | High |
| Parent/child order mismatch | High | High |
| Delivery `driver_id/rider_id` mismatch | High | High |
| Payment/refund orphan via polymorphic transactions | High | High |
| Service double booking | High | High |
| Variation stock ignored by checkout | Medium-High | High |
| Brain analytics schema mismatch | Medium | Medium |
