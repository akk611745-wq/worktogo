<?php
/**
 * WORKTOGO — AGENT REMINDER DELIVERY (CRON)
 * ---------------------------------------------------------------------------
 * The nervous system's first reflex (agent-10 §1). Runs every ~1–5 min, finds
 * due `agent_reminders` and DELIVERS them through the EXISTING notification
 * channel (FcmNotifier → in-app feed + FCM push), then reschedules recurring
 * ones and completes one-offs. Idempotent (each row is claimed before delivery)
 * and lock-safe (flock), so overlapping runs never double-fire.
 *
 * Data-driven: the global kill-switch (agent_enabled), the delivery toggle
 * (agent_reminders_enabled) and the batch size (agent_reminders_batch) all live
 * in app_settings — no hardcoding. Entirely OUR-system-side: the external AI is
 * NOT involved and no user data leaves the building.
 *
 * Mirrors cron/delivery_lifecycle.php: CLI or a matching CRON_SECRET over HTTP.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/helpers/Logger.php';       // FcmNotifier logs in dev/log mode
require_once __DIR__ . '/../core/helpers/Database.php';
require_once __DIR__ . '/../core/lib/PushSubscription.php';
require_once __DIR__ . '/../core/lib/FcmNotifier.php';
require_once __DIR__ . '/../core/agent/ReminderDelivery.php';

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

$lockFile = __DIR__ . '/agent_reminders.lock';
$fp = fopen($lockFile, 'w+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    die("Agent reminder delivery is already running.\n");
}

/** Read an app_settings value defensively (missing/unreadable ⇒ default). */
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
    // Respect the global kill-switch AND the delivery toggle.
    if (!$truthy($setting('agent_enabled', '0'))) {
        echo json_encode(['success' => true, 'skipped' => 'agent disabled']) . "\n";
        exit;
    }
    if (!$truthy($setting('agent_reminders_enabled', '1'))) {
        echo json_encode(['success' => true, 'skipped' => 'reminder delivery disabled']) . "\n";
        exit;
    }
    $batch = max(1, (int) $setting('agent_reminders_batch', '200'));

    // Deliver through the EXISTING notification channel (in-app feed + FCM push).
    // notifyUser is void; it always writes the in-app notification row (push is
    // best-effort / log-mode when no service account) — treat a clean return as
    // delivered, an exception as a failure.
    $deliver = function (int $userId, string $title, string $body, array $data) use ($db): bool {
        if ($userId <= 0) { return false; }
        FcmNotifier::notifyUser($db, $userId, $title, $body, $data);
        return true;
    };

    $summary = (new ReminderDelivery($db))->run($deliver, $batch);

    echo json_encode(['success' => true, 'summary' => $summary]) . "\n";
} catch (\Throwable $e) {
    error_log('[cron/agent_reminders] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]) . "\n";
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
