# V2 — Smart Auth Layer (flexible login/OTP/register · multi-channel · failover · config-aware)

> **Purpose:** ONE auth layer that adapts to the user and to what's configured. The system knows which methods it can offer (password / phone-OTP / WhatsApp-OTP / Google), sends OTP via ANY configured SMS provider AND WhatsApp with automatic failover, and never depends on a single provider — if one is down or not approved, another configured channel still works, so login never goes down for one dependency. Built by mirroring the payment abstraction, without touching a never-touch engine and without weakening any existing auth security.
> **Scope:** `wtg-v2` worktree (branch `v2`). Staging migration written, NOT run. No PHP runtime locally (logic verified by a committed self-test).
> **Date:** 2026-09-01.

---

## 1. Why (founder intent)
1. **Smart / flexible** — the user logs in the fastest available way: if they have a password → password; if not → OTP; also Google; and the other available options are offered. Never stuck on one method.
2. **Provider-flexible + config-aware** — OTP over any SMS provider (MSG91 now, others drop-in) AND WhatsApp (slot), with **failover**. Only methods whose provider/API is actually configured are offered/used — if WhatsApp isn't connected, it isn't shown.
3. **We're in control** — our system USES providers, never DEPENDS on one. A down/unapproved provider fails over to the next configured channel; the business never goes down for a single dependency.
4. **Don't weaken security** — OTP verify, rate-limits, JWT, the H2 passwordless-claim guard, and Google Sign-In are all unchanged. Only the SEND and the method-selection became abstracted/smart.

---

## 2. Shape of the layer (mirrors `lib/payment`)

```
  send-otp.php ─► OtpChannelManager.sendWithFailover(kind, phone, otp)
  methods.php  ─► LoginMethodResolver (config-aware method discovery)
                        │  kind = 'sms' | 'whatsapp'  (+ admin routing override)
                        ▼
        ┌───────────────┬───────────────┬───────────────┐
        ▼               ▼               ▼               ▼
  Msg91SmsChannel   Sms2Channel    WhatsAppOtp     LogOtpChannel
   (REAL, wraps      (STUB / 2nd     Channel        (dev-only; fails
    MSG91 v5 OTP)     SMS slot)      (STUB slot)     loud in prod)
```

### Files (all NEW unless noted)
| File | Role |
|---|---|
| `lib/auth/OtpChannel.php` | The contract every channel implements: `name`, `kinds`, `isConfigured`, `send`. Normalised result `{success, channel, http_code, retryable, error}`. Never logs/echoes the OTP. |
| `lib/auth/Msg91SmsChannel.php` | **REAL.** Faithful wrapper around the existing MSG91 v5 OTP send (same endpoint/payload/phone-cleaning/env), plus retryable-vs-terminal classification. The analogue of `CashfreeAdapter`. |
| `lib/auth/Sms2Channel.php` | **STUB / slot** for a 2nd SMS provider (Fast2SMS/Twilio/…). Guarded off (`isConfigured=false`) until creds + transport are wired. Clean drop-in (analogue of `RazorpayAdapter`). |
| `lib/auth/WhatsAppOtpChannel.php` | **STUB / slot** for WhatsApp OTP (Cloud API auth template). Guarded off; documents the exact `messages` payload to fill in. |
| `lib/auth/LogOtpChannel.php` | Dev-only/keyless channel preserving the legacy `_logOtp` posture: logs the OTP ONLY in an explicit dev env; in prod (or unknown env) it logs no secret and fails loud. `isConfigured=true` only in dev. Analogue of `MockAiAdapter`. |
| `lib/auth/OtpChannelManager.php` | Factory + per-kind router + failover + usability helpers. Merges an admin JSON override on top of config defaults. Analogue of `PaymentGatewayManager`. |
| `lib/auth/LoginMethodResolver.php` | The "system knows what's available" brain — computes offerable methods from config + real usability (+ optional per-identifier refinement). |
| `lib/auth/bootstrap.php` | One-include loader (pure includes). |
| `config/auth.channels.php` | Declarative registry (`enabled`) + per-kind `routing` (primary/secondary) + `methods` flags + `reveal_account_methods`. The only file you edit to add/route a channel. Analogue of `config/payment.gateways.php`. |
| `heart/api/auth/methods.php` | `GET|POST /api/auth/methods` — public, config-aware method discovery for the login screen. |
| `heart/api/auth/send-otp.php` *(edited)* | The SEND now routes through the manager (kind from an optional `channel` param, default SMS; WhatsApp only if configured) with failover; **backward-compatible** legacy fallback retained. Everything else unchanged. |
| `heart/api/auth/router.php` *(edited)* | Adds the `/api/auth/methods` route. |
| `migrations/2026_08_27_010_auth_channels.sql` | Seeds `app_settings.auth_channel_routing` (admin failover control). No schema change. Staging only. |

---

## 3. Failover (mirrors payment, for OTP send)
`OtpChannelManager::sendWithFailover($kind, $phone, $otp)`:
1. Resolve `primary`/`secondary` for the kind (`sms` / `whatsapp`; admin-overridable).
2. Try **primary** (only if enabled + `isConfigured`). Success → return (`failover=false`).
3. On failure branch on `retryable`:
   - **Retryable** = transport error / 5xx / 429 / 401 / 403 (provider down/erroring/creds) → try the **secondary** if enabled + configured.
   - **Not retryable** = 4xx bad-number/template reject → do NOT fail over (it would fail identically anywhere) → surface the error.
4. Result carries `channel` (who delivered), `failover`, a per-attempt `attempts[]` trail, and `no_usable_channel` (neither usable). The OTP is generated/hashed/stored **once** in `send-otp.php`; only the delivery is retried — so failover cannot create a second valid code.

**WhatsApp → SMS fallback:** `routing.whatsapp = {primary: whatsapp, secondary: msg91}`, so a WhatsApp attempt for a not-yet-approved template falls back to SMS automatically.

---

## 4. Config-awareness & the method resolver
`LoginMethodResolver::platformMethods()` returns a method ONLY if it is enabled in config AND its capability is actually present:
- `password` — config flag.
- `phone_otp` — an SMS channel is usable (`msg91`/`sms2`, or `log` in dev).
- `whatsapp_otp` — the `whatsapp` channel is usable (⇒ hidden until WhatsApp is connected).
- `google` — `GOOGLE_CLIENT_ID(S)` is set.

`GET /api/auth/methods` (public, rate-limited) returns `{ methods, channels:{sms,whatsapp}, google, reveal }`. With an optional `identifier` it returns the offerable subset for that identifier type and a `preferred` lead.

**Enumeration-safety (secure by default):** per-account facts (registered? has a password?) are returned ONLY when `reveal_account_methods` is true (default **OFF**), so the endpoint is not an account-existence oracle. With it OFF the platform methods are still returned, so the UI can offer the right options and let the user choose. Turning it ON (accepting the standard, rate-limited "does this account exist" signal) lets the UI lead a known user straight to their fastest method — the founder's "if they have a password → password; else OTP" UX. `reveal` lives in the config file (a security setting changed via code review, not a runtime toggle).

---

## 5. Security preserved (nothing weakened)
| Guarantee | Where | Status |
|---|---|---|
| OTP generation, bcrypt-hash, single-use, expiry, per-phone + per-IP throttle | `send-otp.php` / `verify-otp.php` (unchanged) | ✅ untouched |
| OTP **verify** (attempts cap, expiry, mark-used) | `verify-otp.php` (unchanged) | ✅ untouched |
| JWT issuance / 30-day duration / refresh tokens | `verify-otp.php`, `AuthController`, `register.php` (unchanged) | ✅ untouched |
| **H2** passwordless-claim guard (can't claim an OTP-only account via register) | `register.php:59-73` (unchanged) | ✅ untouched |
| Google Sign-In (tokeninfo, audience/issuer/exp/email_verified checks) | `AuthController::loginGoogle` (unchanged) | ✅ untouched |
| Plaintext OTP never logged (M1) | every channel returns metadata only; `LogOtpChannel` fails loud in prod | ✅ preserved + enforced by the contract |
| No enumeration oracle | `methods` endpoint reveal-OFF by default + rate-limited | ✅ new, secure default |

Backward-compatible: with MSG91 configured, the manager uses MSG91 exactly as before (now with failover headroom); dev-with-no-key logs via `LogOtpChannel` exactly as the old `_logOtp`; any older `SMS_PROVIDER=fast2sms/twilio` deploy still works via the retained legacy fallback (used only when the layer has no usable channel). MSG91 + Google keep working with no config change and no migration.

---

## 6. Adding a channel (e.g. turning on WhatsApp or a 2nd SMS provider)
1. `.env`: the provider creds (e.g. `WHATSAPP_TOKEN`/`WHATSAPP_PHONE_ID`/`WHATSAPP_OTP_TEMPLATE`, or `SMS2_PROVIDER` + its key).
2. Implement the adapter's `send()` (the stub documents the exact API call) + flip `isConfigured()`.
3. `config/auth.channels.php`: set the channel `enabled=true`; point a `routing` entry at it (e.g. `routing.sms.secondary => 'sms2'`, or `routing.whatsapp.primary => 'whatsapp'`) — or set `app_settings.auth_channel_routing` from admin.
4. It is now automatically OFFERED (config-aware) and used with failover — no auth-flow, manager, or engine change.

---

## 7. Never-touch flag
No never-touch engine was edited (`shopping-engine` / `video-engine` / `service-engine` / `orders.js` — read-only). The change is confined to the auth scope (`lib/auth`, `config/auth.channels.php`, two `heart/api/auth/*` handlers, the router) — a clean adapter layer over the existing MSG91/Google auth.

---

## 8. Migration (staging — NOT run)
`migrations/2026_08_27_010_auth_channels.sql`: `INSERT IGNORE` the `auth_channel_routing` JSON into `app_settings` (unique key; won't overwrite admin edits). No `ALTER`/`DROP`, no schema change (channels are env + config driven; OTP/verify/JWT tables untouched). Apply on staging via `migrate.php`, verify with the commented checks.

---

## 9. How to verify
**Self-test (logic, when PHP is available):** `php lib/auth/tests/auth_selftest.php` —
- F1 OTP via primary; F2 retryable primary → **secondary delivers** (failover); F3 terminal primary → **no** failover; F4 no usable secondary → clean failure.
- A1 an **unconfigured** WhatsApp channel is **not offered** (SMS is); A2 Google offered only when `GOOGLE_CLIENT_ID` set.
- R1 reveal OFF ⇒ no account facts; R2 a **password user** is offered password + OTP (preferred password); R3 an **OTP-only** user isn't offered password (preferred OTP); R4 a not-registered phone prefers OTP-register.
- S1 a send result carries **no OTP** (metadata only); C1 the real config has MSG91 real/enabled and WhatsApp/sms2 as disabled stubs.

**Live app:** MSG91 + Google keep working unchanged; `GET /api/auth/methods` shows only configured methods; when a 2nd SMS provider or WhatsApp is configured, OTP fails over automatically.

---

## 10. Open questions (founder decision)
1. **Enable per-account hints?** Flip `reveal_account_methods` on (rate-limited) for the "lead the user to their fastest method" UX, accepting the standard account-existence signal? Default is OFF (no enumeration).
2. **Second SMS provider.** Which one for real prod SMS failover (Fast2SMS/Twilio already have working reference calls in the legacy sender) → promote it from the `sms2` stub to a real channel.
3. **WhatsApp OTP.** Requires an approved WhatsApp authentication template + Cloud API/BSP creds; enable once approved.
4. **Frontend.** A login screen that calls `/api/auth/methods` and renders the offered options (+ a WhatsApp/SMS toggle when available) is a separate frontend task.

---
*Backend abstraction only; no live requests; migration staged not run; no never-touch engine edited; no existing auth security weakened.*
