# Operational Dependency Map

Generated: 2026-05-12T08:15:00Z

---

## 1. Admin/Vendor Operational Dependencies

```mermaid
flowchart TD
  AdminPages[admin HTML pages] --> AdminConfig[admin/config.js]
  AdminPages --> SharedAdminAPI[admin/shared/api.js]
  AdminPages --> AdminAuth[admin/shared/auth.js]
  SharedAdminAPI --> Heart[heart/index.php]
  Heart --> AdminRouter[heart/api/admin/index.php]
  Heart --> RefundController[RefundController]
  AdminRouter --> Users[(users)]
  AdminRouter --> Vendors[(vendors)]
  AdminRouter --> Orders[(orders)]
  AdminRouter --> Deliveries[(deliveries)]
  AdminRouter --> Products[(products)]
  AdminRouter --> Logs[(logs)]
  AdminRouter --> Settings[(app_settings/system_config)]
  CustomerApply[VendorApplyModal] --> PublicVendorsAPI[/api/vendors]
  PublicVendorsAPI --> Vendors
  PublicVendorsAPI --> Users
  VendorPanel[vendor panel] --> VendorAuth[vendor/shared/auth.js]
  VendorPanel --> VendorAPI[vendor/shared/api.js]
  VendorAPI --> Heart
```

Evidence: `wtg  live/admin/shared/api.js:6`, `wtg  live/admin/shared/auth.js:5`, `wtg  live/heart/index.php:195`, `wtg  live/heart/index.php:237`, `wtg  live/app/pages/vendor-apply-modal.js:59`, `wtg  live/body/service-engine/api/vendors/index.php:42`, `wtg  live/vendor/shared/api.js:7`, `wtg  live/vendor/shared/auth.js:6`.

---

## 2. Runtime Dependency Inventory

| Dependency | Used by | Failure effect | Evidence |
|---|---|---|---|
| Admin JWT in `wtg_admin_token` | Shared admin API | Admin pages redirect/fail on 401/403 | `wtg  live/admin/shared/api.js:13`, `wtg  live/admin/shared/api.js:62` |
| `admin_token` legacy key | Refund page | Refund page may reject valid shared admin session | `wtg  live/admin/refunds.html:284` |
| Heart `/api/admin` route order | All admin routes | Refund route family conflict | `wtg  live/heart/index.php:195`, `wtg  live/heart/index.php:237` |
| Logger/log storage | Audit/activity | Purge can delete evidence | `wtg  live/heart/api/admin/index.php:603` |
| app_settings | SettingsController, Brain public settings | Missing table breaks settings | `wtg  live/core/controllers/SettingsController.php:19`, `wtg  live/brain/BrainCore.php:33` |
| platform_settings | Auto assignment/delivery pricing | Admin delivery settings UI does not match traced backend write route | `wtg  live/cron/auto_assign.php:19`, `wtg  live/core/helpers/DeliveryPricingEngine.php:25`, `wtg  live/admin/system.html:224` |
| system_config | Admin system-config endpoint | UI route mismatch for PATCH system config | `wtg  live/heart/api/admin/index.php:861`, `wtg  live/admin/system.html:242` |

