# Escalation + Recovery Analysis

Generated: 2026-05-12T08:15:00Z

---

## 1. Traced Escalation Signals

| Signal | Detection | Admin recovery surface | Evidence | Confidence |
|---|---|---|---|---|
| Stuck orders | Dashboard: active orders not updated for 60 minutes | Manual order status/cancel page | `wtg  live/heart/api/admin/index.php:93`, `wtg  live/admin/orders.html:200` | High |
| Failed payments | Dashboard transaction failed count | Payments/refunds pages, no direct retry traced | `wtg  live/heart/api/admin/index.php:107`, `wtg  live/admin/payments.html:106` | Medium |
| Refund pending | Dashboard wallet/order refund counts | Refund approve/reject UI/controller | `wtg  live/heart/api/admin/index.php:115`, `wtg  live/core/controllers/RefundController.php:176` | Medium |
| Driver cash blocked | Dashboard driver_wallets threshold | Delivery assignment blocks selected driver | `wtg  live/heart/api/admin/index.php:77`, `wtg  live/heart/api/admin/index.php:562` | Medium-high |
| Cash collection mismatch | Dashboard expected COD vs cash-in-hand | No specific recovery action traced | `wtg  live/heart/api/admin/index.php:165`, `wtg  live/heart/api/admin/index.php:188` | Medium |

---

## 2. Recovery Behavior

Admin recovery is mostly manual and direct:

- For stuck orders, admin can set arbitrary order status or cancel.
- For delivery issues, admin can insert a delivery assignment and set order processing.
- For refunds, controller uses transaction state checks and gateway refund call.
- For settings/system, UI exposes toggles and emergency stop, but matching backend routes are not fully implemented.

Evidence: `wtg  live/admin/orders.html:200`, `wtg  live/heart/api/admin/index.php:642`, `wtg  live/admin/delivery.html:153`, `wtg  live/heart/api/admin/index.php:567`, `wtg  live/core/controllers/RefundController.php:197`, `wtg  live/admin/system.html:210`, `wtg  live/admin/system.html:254`.

Operational impact: recovery can restore visible states but may skip domain side effects like inventory release, payment settlement, assignment reconciliation, alerts, or parent/child order aggregation.

Confidence: High.

---

## 3. Hidden Recovery Gaps

| Gap | WHERE | WHY | ROOT CAUSE | CONNECTED SYSTEMS | OPERATIONAL IMPACT | SCALE IMPACT | MAINTAINABILITY IMPACT | FUTURE RISK |
|---|---|---|---|---|---|---|---|---|
| Manual order cancel bypasses OrderService | Admin order cancel | Direct status update only | Admin route does not call domain service | Orders, inventory, cart/order service, refunds | Reserved stock may not be released | Stock drift grows with manual ops | Duplicate lifecycle rules | Hard-to-debug inventory inconsistencies |
| Delivery assign payload mismatch | Admin delivery page/API | UI sends `orderId/agentId`; API expects `order_id/driver_id` | Frontend/backend contract drift | Delivery, orders, LedgerEngine | Assignment can fail validation | More manual dispatch failures | Endpoint constants hide mismatch | Delivery recovery unreliable |
| Refund admin route ordering | Heart admin route before refund route | `/api/admin/refunds` may be swallowed by admin router | Dispatch order conflict | RefundController, admin refunds page | Refund page may fall back to stub data | Finance ops lose live view | Route families overlap | Fake operational confidence |
| Emergency stop absent backend | System page | UI calls endpoint not traced in admin router | UI-first control without backend implementation | System config, engines | Emergency control may fail | Incident response blocked | Dangerous placeholder control | Operators assume halt exists |
| Logs purge can erase audit | Admin logs | Delete old files directly | No immutable audit store | Logs, Logger, compliance | Evidence loss | Audit gap grows | Hard to prove operations | Abuse/incident forensics weak |

