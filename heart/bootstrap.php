<?php
/**
 * Heart Bootstrap
 * Loads foundational dependencies in required order
 */

declare(strict_types=1);

$system_root = dirname(__DIR__);

// 1. App Configuration
$appConfig = require_once $system_root . '/config/app.php';

// 2. JWT Helper
require_once $system_root . '/core/helpers/JWT.php';

// 3. Response Helper
require_once $system_root . '/core/helpers/Response.php';

// 4. Auth Middleware
require_once $system_root . '/heart/middleware/AuthMiddleware.php';

// 5. DB Connection Helper
require_once $system_root . '/core/helpers/Database.php';

return $appConfig;
