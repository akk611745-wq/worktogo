# WorkToGo — Alert & Notification Flow (verification + what's needed server-side)

> **Founder's question:** *"When a vendor gets a new job, or an admin needs to see a flag, or a customer's booking changes — does the alert actually fire? Is anything missing?"*
> **Answer up front:** The flow is **built and complete in code for in-app alerts** across all three roles. At launch everything is **in-app only** (a bell feed + an alert poller) — that works with **no extra keys**, needing only the DB tables + the assignment cron running. **Real phone/browser push** is a post-launch layer that switches on when the FCM keys are set. **One real code gap** was found: **auto-assigned jobs don't push to the vendor** (only a poll-based alert row). Details, evidence, and where-to-fix below.
> **Grounded in** real `wtg-v2` code @ 2026-09-06. **READ-ONLY verification — no logic changed.**

---

## 1. There are TWO notification channels (by design)

| # | Channel | Code | Storage | When it reaches the user |
|---|---|---|---|---|
| **1** | **In-app bell + FCM push** | `core/lib/FcmNotifier.php` (`notifyUser` / `notifyRole`) | `notifications` table **+** a Firebase push | The bell row is written **always**; the **push only sends if FCM is configured** (else it logs and returns). |
| **2** | **Alert feed** | direct `INSERT INTO alerts …`; served by `api/alerts/` (`AlertEngine`, `fetch`, `mark_seen`); polled by `assets/js/alerts.js` | `alerts` table | Shown when the app **polls** the alert feed. No push. |

**Key design fact (good news):** in channel 1, the in-app `notifications` row is inserted **independently of push** (`FcmNotifier::insertNotification` runs before the send). So **in-app notifications work at launch with zero FCM setup**; push is purely additive on top.

---

## 2. Who gets notified, when — the complete map (grounded in code)

| Event | Who | Channel | Fires push? | Evidence |
|---|---|---|:--:|---|
| **New booking placed** | **Admin** | 1 — `notifyRole(ADMIN,'New Booking Created')` | ✅ (if FCM on) | `body/service-engine/api/services/index.php:1565` |
| Booking placed **with an initial vendor** | **Vendor** | 1 — `notifyUser('New Booking Assigned')` | ✅ | `…/services/index.php:1577` |
| **Admin manually assigns** a booking | **Vendor** | 1 — `notifyUser('New Booking Assigned')` | ✅ | `heart/api/admin/index.php:2240, 2849` |
| **Vendor approved** by admin | **Vendor** | 1 — `notifyUser('Vendor Account Approved')` | ✅ | `heart/api/admin/index.php:1820` |
| **Vendor applies / vendor action** | **Admin** | 1 — `notifyRole(ADMIN,…)` | ✅ | `body/service-engine/api/vendors/index.php:117` |
| **Auto-assign finds a vendor** (the cron) | **Vendor** | **2 — `alerts` row only** | ❌ **(gap)** | `cron/auto_assign.php:181` |
| Auto-assign first attempt | **Customer** | 2 — `alerts` "Finding a Vendor" | ❌ | `cron/auto_assign.php:127` |
| Auto-assign finds **no vendor** → cancel | **Customer** | 2 — `alerts` "Order Failed" | ❌ | `cron/auto_assign.php:198` |
| **Vendor accepts** a job | **Vendor + Customer** | 2 — two `alerts` rows | ❌ | `api/assignments/accept.php:136, 143` |
| **Uncaught system error** | **Admin** | 2 — `alerts` "System Error" | ❌ | `heart/index.php:35` |
| **Payment failed** | **Admin** | 2 — `alerts` "Payment Failed" | ❌ | `lib/PaymentService.php:359` |
| **Agent demand signal** (cron) | **Admin** | 1 + 2 — `notifyRole('admin')` + `alerts` | ✅ | `cron/agent_demand_detect.php:84, 92` |
| **Agent reminders** (cron) | **User** | 1 — `notifyUser(…)` | ✅ | `cron/agent_reminders.php:76` |
| **Delivery lifecycle** (stuck/late) | Admin/rider | 2 — `alerts` | ❌ | `cron/delivery_lifecycle.php:64` *(dormant at launch)* |

**Verdict:** every role is covered for the launch (service) journey — admin sees new bookings, vendor actions, system/payment problems, demand; vendor sees new/assigned/accepted jobs; customer sees finding-vendor / accepted / failed. **The in-app flow is complete.**

---

## 3. The ONE real gap — auto-assigned jobs don't push to the vendor

- **What:** `cron/auto_assign.php`, when it assigns a job to a vendor, writes an **`alerts`** row (`:181`) but **never calls `FcmNotifier`**. So an auto-assigned job produces **no push and no bell-feed (`notifications`) row** — only a poll-based alert.
- **Consequence:** a vendor whose app is **closed** gets **no push** for an auto-assigned job (even once FCM is live); they see it only when the app next polls the alert feed. Manual/initial assignment (channel 1) *does* push — so the behaviour is inconsistent between the two assignment paths.
- **Why it matters:** at launch, **auto-assignment is the primary path**, so this is the path most likely to need a real-time nudge.
- **Where to fix (later — do NOT change now; it's cron logic on the assignment path):** in `cron/auto_assign.php`, right after the `alerts` insert at `:181`, also resolve the vendor's `user_id` and call `FcmNotifier::notifyUser($vendorUserId, 'New Request', "New {$type} #{$id}…", […])` — mirroring how `heart/api/admin/index.php` already notifies on manual assign. One added call; reuses the existing, tested notifier. **Effort: S. Post-launch or a small pre-launch batch, founder-approved.**

### Smaller notes (not blockers)
- **Admin isn't specifically alerted when auto-assign fails to find a vendor** — only the customer gets "Order Failed" (`auto_assign.php:198`). Admin still sees every booking (the create-time `notifyRole`) and the cancelled state on the dashboard. *(Optional: add an admin alert on assignment-failure.)*
- **Customer status pushes for start/complete** aren't wired (accept is). At launch push is off anyway; customers see live status by opening the booking (it polls). *(Post-launch, with push.)*

---

## 4. What's needed SERVER-SIDE for notifications to work

| Need | For | Detail |
|---|---|---|
| **Migrate the tables** | Both channels | `notifications` + `fcm_tokens` are created by repo migrations (`2026_06_22_001`, `2026_06_21_001`). ⚠️ **The `alerts` table is NOT in the repo migrations** — it's in the inherited (V1) schema. **Verify it exists on the target DB** (fold into the T4 index/verify pass). If it's missing, `auto_assign` and error paths will fatal on insert. |
| **Schedule the crons** | Vendor-assignment alerts, reminders | If `cron/auto_assign.php` isn't scheduled, **jobs never auto-assign and the vendor "New Request" alert never fires.** Schedule it (~every 1 min), plus `agent_reminders.php`, `agent_demand_detect.php` (and `delivery_lifecycle.php`, dormant). Gate over HTTP with `CRON_SECRET`, or run on the CLI. |
| **(Post-launch) FCM keys for real push** | Channel-1 push | Set server `FCM_SERVICE_ACCOUNT_PATH` (service-account JSON) + `FCM_PROJECT_ID`, and client `FCM_API_KEY` + `FCM_VAPID_KEY` + `FCM_SENDER_ID` + `FCM_APP_ID`. Until set, `FcmNotifier` logs "FCM (log mode, no service account)" and only the **in-app** rows appear (intended for the launch). |

> **At launch (push OFF):** notifications appear **inside the app** — the bell feed (`notifications`) and the alert poller (`alerts`). Vendors/admins should keep the panel open (or open it) to see new work in near-real-time. This is fine for a pilot; wiring FCM is the post-launch upgrade to true background push.

*Companion to `LAUNCH/server-day-checklist.md` (the notification smoke-test is step 6-D) and `MAP/system-map.md` (§C "notifications").*
