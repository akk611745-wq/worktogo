<?php
/**
 * WORKTOGO — AGENT DEMAND DETECTION (CRON)
 * ---------------------------------------------------------------------------
 * "The system tells us where to grow" (agent-10 §2). Runs periodically (e.g.
 * hourly), aggregates the agent's own demand events (agent_demand_events) into
 * growth signals (agent_demand_signals), and when a (category, city) has HIGH
 * demand but LOW supply it raises an ADMIN ALERT through the EXISTING mechanism
 * — FcmNotifier::notifyRole('admin') (in-app feed + push) plus a row in the
 * shared `alerts` stream (same as the delivery watchdog). A cooldown stops it
 * spamming. The admin control-room reads agent_demand_signals as a growth list.
 *
 * Data-driven: the toggle (agent_demand_detect_enabled) and thresholds
 * (agent_demand_thresholds) live in app_settings — no hardcoding. Entirely
 * OUR-system-side: the external AI is NOT involved and no PII leaves the system.
 *
 * Mirrors cron/delivery_lifecycle.php: CLI or a matching CRON_SECRET over HTTP.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/helpers/Logger.php';
require_once __DIR__ . '/../core/helpers/Database.php';
require_once __DIR__ . '/../core/lib/PushSubscription.php';
require_once __DIR__ . '/../core/lib/FcmNotifier.php';
require_once __DIR__ . '/../core/agent/DemandSignals.php';

// ── Defensive access: allow CLI; over HTTP require a matching cron secret ────
if (php_sapi_name() !== 'cli') {
    $secret = getenv('CRON_SECRET') ?: getenv('MIGRATE_SECRET') ?: '';
    $given  = $_GET['secret'] ?? '';
    if ($secret === '' || !hash_equals($secret, (string) $given)) {
        http_response_code(403);
        die('Forbidden');
    }
}

$db = getDB();

$lockFile = __DIR__ . '/agent_demand_detect.lock';
$fp = fopen($lockFile, 'w+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    die("Agent demand detection is already running.\n");
}

$setting = function (string $key, string $default) use ($db): string {
    try {
        $st = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string) $v;
    } catch (\Throwable $e) {
        return $default;
    }
};
$truthy = static fn (string $v): bool => in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true);

try {
    // Passive internal analytics — gated by its own toggle only (no AI, no spend).
    if (!$truthy($setting('agent_demand_detect_enabled', '1'))) {
        echo json_encode(['success' => true, 'skipped' => 'demand detection disabled']) . "\n";
        exit;
    }
    $thresholds = json_decode($setting('agent_demand_thresholds', '{}'), true);
    $thresholds = is_array($thresholds) ? $thresholds : [];

    // The admin alert — reuse the EXISTING notification + alerts mechanisms.
    $notifyAdmin = function (array $signal) use ($db): void {
        $cat    = (string) $signal['category_key'];
        $scope  = (string) $signal['scope'];
        $asks   = (int) $signal['ask_count'];
        $users  = (int) $signal['distinct_users'];
        $supply = (int) $signal['supply_estimate'];
        $area   = trim((string) ($signal['area'] ?? ''));
        $cityId = (int) $signal['city_id'];
        $where  = $area !== '' ? $area : ($cityId > 0 ? "city #{$cityId}" : 'your area');
        $noun   = $scope === 'commerce' ? 'store(s)/product(s)' : 'vendor(s)';

        $title = "Growth signal: {$cat} demand, low supply";
        $msg   = "{$asks} asks from {$users} users for \"{$cat}\" ({$scope}) in {$where} — "
               . "only {$supply} {$noun} show up. Consider adding supply here.";

        // 1) In-app feed + push to every admin (existing FCM channel).
        FcmNotifier::notifyRole($db, 'admin', $title, $msg, [
            'type' => 'growth_signal',
            'url'  => '/admin/agent.html',
        ]);

        // 2) The shared system `alerts` stream (same table the delivery watchdog uses).
        try {
            $db->prepare(
                "INSERT INTO alerts (type, title, message, ref_type, ref_id, created_at)
                 VALUES ('system', ?, ?, 'none', NULL, NOW())"
            )->execute([mb_substr($title, 0, 120), mb_substr($msg, 0, 500)]);
        } catch (\Throwable $e) { /* alerts optional */ }
    };

    $summary = (new DemandSignals($db))->detect($thresholds, $notifyAdmin);

    echo json_encode(['success' => true, 'summary' => $summary]) . "\n";
} catch (\Throwable $e) {
    error_log('[cron/agent_demand_detect] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]) . "\n";
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
