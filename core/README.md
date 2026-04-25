# WorkToGo CORE — Production Backend

## Structure

```
public_html/
├── index.php              ← Heart: single entry point
├── .htaccess              ← Apache rewrite rules
├── .env                   ← Your secrets (never commit this)
├── config/
│   ├── env.php            ← .env loader
│   ├── db.php             ← PDO connection
│   └── auth.config.php    ← JWT + role constants
├── helpers/
│   ├── response.helper.php
│   ├── jwt.helper.php
│   ├── logger.helper.php
│   └── validator.helper.php
├── middleware/
│   └── auth.middleware.php
├── api/
│   ├── auth/              ← register, login, logout, me, refresh, otp
│   ├── admin/             ← admin panel endpoints
│   └── search/            ← unified search
├── database/
│   └── schema.sql         ← Run this first
├── logs/                  ← Auto-created, writable
└── engines/               ← Drop engine folders here
```

## Deployment Steps

### 1. Upload
Upload the contents of this ZIP to your `public_html/` directory.

### 2. Fill .env
Edit `.env` and replace ALL placeholder values:
- `DB_NAME`, `DB_USER`, `DB_PASS` — your MySQL credentials
- `JWT_SECRET` — generate: `php -r "echo bin2hex(random_bytes(32));"`
- `APP_URL` — your actual domain

### 3. Run Database Schema
```sql
-- In phpMyAdmin or MySQL CLI:
source database/schema.sql
```

### 4. Set Permissions
```bash
chmod 755 public_html/
chmod 644 public_html/.env
chmod 775 public_html/logs/
```

### 5. Test Health Endpoint
```
GET https://yourdomain.com/api/health
```
Expected:
```json
{
  "success": true,
  "data": {
    "service": "WorkToGo",
    "status": "ok",
    "database": "ok"
  }
}
```

### 6. Default Admin Account
- Phone: `+910000000000`
- Password: `Admin@1234`
- **Change this immediately after first login.**

---

## API Reference

### Auth
| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | /api/auth/register | ✗ | Register with phone + password |
| POST | /api/auth/login | ✗ | Login, returns JWT |
| GET | /api/auth/me | ✓ | Current user profile |
| POST | /api/auth/logout | ✓ | Invalidate token |
| POST | /api/auth/refresh | ✗ | Refresh access token |
| POST | /api/auth/otp/send | ✗ | Send OTP to phone |
| POST | /api/auth/otp/verify | ✗ | Verify OTP, returns JWT |

### Core
| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | /api/health | ✗ | System health check |
| GET | /api/search?q= | ✗ | Search across engines |

### Admin (ROLE_ADMIN required)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/admin/stats | Platform summary |
| GET | /api/admin/users | List all users |
| GET | /api/admin/users/{id} | User detail |
| PATCH | /api/admin/users/{id} | Update role/status |
| DELETE | /api/admin/logs | Purge old log files |

---

## Adding Engines

Drop engine folders as siblings:
```
public_html/
├── index.php          (CORE)
├── service-engine/    (Service Engine)
└── shopping-engine/   (Shopping Engine)
```

CORE auto-detects them via `SERVICE_ENGINE_DIR` in `.env`.

---

## Security Checklist Before Go-Live
- [ ] Replace JWT_SECRET with a real 64-char hex value
- [ ] Change default admin password
- [ ] Set APP_DEBUG=false
- [ ] Set CORS_ORIGIN to your actual frontend domain
- [ ] Set SMS_PROVIDER to a real provider (msg91/fast2sms/twilio)
- [ ] Verify logs/ folder is not web-accessible (`.htaccess` blocks it)
- [ ] Enable HTTPS on your domain
