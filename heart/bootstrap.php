<?php
/**
 * Heart Bootstrap
 * Loads foundational dependencies in required order
 */

declare(strict_types=1);

$system_root = dirname(__DIR__);

// ─────────────────────────────────────────────────────────────────────────────
// 0. Load .env file (pure-PHP, no Composer dependency required)
//    Reads $system_root/.env and injects every KEY=VALUE pair into:
//      • $_ENV['KEY']
//      • $_SERVER['KEY']
//      • putenv('KEY=VALUE')   ← makes getenv('KEY') work everywhere
//    Rules:
//      • Lines starting with # are comments and are skipped.
//      • Blank lines are skipped.
//      • Inline comments after the value are stripped.
//      • Surrounding quotes (" or ') around the value are stripped.
//      • Already-set environment variables are NOT overwritten
//        (host-level SetEnv / system exports take precedence).
// ─────────────────────────────────────────────────────────────────────────────
(static function (string $envFile): void {
    if (!is_file($envFile)) {
        // .env is optional in environments that inject vars at the OS/server level.
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and blank lines
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Must contain '='
        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value);

        // Strip inline comment (only when value is not quoted)
        if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
            $commentPos = strpos($value, ' #');
            if ($commentPos !== false) {
                $value = trim(substr($value, 0, $commentPos));
            }
        }

        // Strip surrounding quotes
        if (
            strlen($value) >= 2 &&
            (
                ($value[0] === '"'  && $value[-1] === '"')  ||
                ($value[0] === "'"  && $value[-1] === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        // Do NOT overwrite values already set by the host environment
        if (getenv($name) !== false) {
            continue;
        }

        putenv("{$name}={$value}");
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }
})($system_root . '/.env');
// ─────────────────────────────────────────────────────────────────────────────

// 1. App Configuration  (reads JWT_SECRET, APP_ENV, etc. via getenv — now populated)
$appConfig = require_once $system_root . '/config/app.php';

// 2. JWT Helper
require_once $system_root . '/core/helpers/JWT.php';

// 3. Response Helper
require_once $system_root . '/core/helpers/Response.php';

// 4. Auth Middleware
require_once $system_root . '/heart/middleware/AuthMiddleware.php';

// 5. DB Connection Helper  (reads DB_HOST, DB_NAME, DB_USER, DB_PASS via getenv — now populated)
require_once $system_root . '/core/helpers/Database.php';

return $appConfig;
