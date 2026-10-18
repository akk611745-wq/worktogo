<?php
/**
 * WORKTOGO — COMMERCE LIFECYCLE ENGINE (CRON)   [Paint PB6]
 * ---------------------------------------------------------------------------
 * The commerce routing watchdog. Runs every ~minute and closes the offer loop
 * when a seller does NOT respond:
 *   A. Stale OFFERS   → RoutingEngine::expireStaleOffers  (seller didn't accept
 *                        in commerce_offer_timeout_sec, default 120s → next seller
 *                        → company fallback).
 *   B. Stale QUOTES   → RoutingEngine::expireStaleQuotes  (custom-tint quote not
 *                        priced in commerce_quote_timeout_sec, default 180s = 3min
 *                        → reroute). PB6 seam used by PB10; safely a no-op until
 *                        the quote flow emits 'quote_offered' signals.
 *
 * Separate from cron/delivery_lifecycle.php (that one is the RIDER watchdog and
 * is NOT edited here). Both reroute paths are idempotent — a sub-order is only
 * picked up while still 'offered', and rerouteOnFailure moves it on — so running
 * every minute is safe. An flock lock guarantees two runs never overlap.
 *
 * Run by hand:   php cron/commerce_lifecycle.php
 * Over HTTP:     cron/commerce_lifecycle.php?secret=<CRON_SECRET>
 * Windows local: cron/commerce_lifecycle.bat  (Task Scheduler, every 1 min)
 * Host (PB13):   * * * * * php /var/www/worktogo/cron/commerce_lifecycle.php
 * All thresholds are admin-tunable in app_settings; nothing hardcoded.
 */

// Load .env so the cron is runnable BY HAND locally (and via Task Scheduler).
// Guarded: only fills vars not already in the environment, so a real server
// whose environment is already populated is untouched.
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
        if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
}

require_once __DIR__ . '/../core/helpers/Database.php';
require_once __DIR__ . '/../lib/commerce/RoutingEngine.php';
require_once __DIR__ . '/../lib/commerce/CommerceCodGuard.php';

// ── Defensive access: allow CLI; over HTTP require a matching cron secret ────
if (php_sapi_name() !== 'cli') {
    $secret = getenv('CRON_SECRET') ?: getenv('MIGRATE_SECRET') ?: '';
    $given  = $_GET['secret'] ?? '';
    if ($secret === '' || !hash_equals($secret, (string)$given)) {
        http_response_code(403);
        die("Forbidden");
    }
}

$db = getDB();

// ── Non-overlap lock (flock): a slow run never collides with the next tick ───
$lockFile = __DIR__ . '/commerce_lifecycle.lock';
$fp = fopen($lockFile, 'w+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    die("Commerce lifecycle is already running.\n");
}

$summary = ['offers_expired' => 0, 'quotes_expired' => 0, 'abandoned_cleaned' => 0];

try {
    // Master switch (admin-tunable). Absent table/row → default ON.
    $enabled = true;
    try {
        $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_lifecycle_enabled' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v !== false && $v !== '') $enabled = filter_var($v, FILTER_VALIDATE_BOOLEAN);
    } catch (\Throwable $e) { /* default ON */ }
    if (!$enabled) { echo json_encode(['success' => true, 'skipped' => 'disabled']) . "\n"; return; }

    // A. Stale seller offers → reroute to next seller → company fallback.
    $summary['offers_expired'] = RoutingEngine::expireStaleOffers($db);

    // B. Stale custom-tint quotes → reroute (PB6 seam for PB10).
    $summary['quotes_expired'] = RoutingEngine::expireStaleQuotes($db);

    // C. PB12: abandoned unpaid online orders → cancel + release the held stock.
    $summary['abandoned_cleaned'] = CommerceCodGuard::cleanupAbandoned($db);

    echo json_encode(['success' => true, 'summary' => $summary]) . "\n";
} catch (\Throwable $e) {
    error_log('[cron/commerce_lifecycle] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]) . "\n";
} finally {
    flock($fp, LOCK_UN); fclose($fp);
}
