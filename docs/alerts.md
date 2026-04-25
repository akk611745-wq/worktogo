# Alert System — Integration Reference

The Alert System is now fully integrated into the production environment.

## File Locations
- **API Core:** `/public_html/api/alerts/`
- **Frontend JS:** `/public_html/assets/js/alerts.js`
- **Database Schema:** `/database/alerts_schema.sql`
- **Cleanup Job:** `/public_html/api/alerts/cleanup.php`

---

## PHP Integration (Backend)

Add these lines to trigger alerts from any service:

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/api/alerts/AlertEngine.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/helpers/Database.php';

$pdo = Database::getConnection();
$engine = new AlertEngine($pdo);

// Example: Notify vendor of new order
$engine->createAlert([
    'vendor_id' => $vendorId,
    'type'      => 'order_new',
    'title'     => 'New order #' . $orderId,
    'message'   => 'A customer placed a new order. Tap to review.',
    'ref_type'  => 'order',
    'ref_id'    => $orderId,
]);
```

---

## JS Integration (Frontend)

Import the module and start polling. The system handles authentication automatically via the Authorization header.

```javascript
import AlertSystem from '/assets/js/alerts.js';

const alerts = new AlertSystem({ 
    role: 'user' // or 'vendor'
});
alerts.start();

// Listen for new alerts
window.addEventListener('alert:new', (e) => {
    const alert = e.detail;
    console.log('New alert received:', alert);
});
```

---

## Security
- **Endpoints:** All public endpoints (`fetch.php`, `mark_seen.php`) require a valid JWT via the `Authorization: Bearer <token>` header.
- **Internal Create:** The `create.php` endpoint is protected by a secret key. Use the `HTTP_X_INTERNAL_KEY` header matching the `ALERT_INTERNAL_SECRET` environment variable.

---

## Cleanup
A cleanup script is provided at `/public_html/api/alerts/cleanup.php`. It should be run periodically (e.g., via cron) to remove old seen alerts.

```bash
0 3 * * * php /path/to/public_html/api/alerts/cleanup.php
```
