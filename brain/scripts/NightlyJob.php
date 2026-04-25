#!/usr/bin/env php
<?php
/**
 * BrainCore — NightlyJob (CLI Cron Script)
 * CLI ONLY. Refuses HTTP. See comments for cron setup.
 *
 * Tasks:
 *   1. HabitModel::applyDecay()  — decay + prune dead habits
 *   2. Purge old brain_events    — default 90-day retention
 *   3. Purge old brain_decisions — default 90-day retention
 *   4. Purge resolved alerts     — 30-day retention
 *
 * Cron (Hostinger):
 *   0 2 * * * /usr/bin/php /home/public_html/braincore/scripts/NightlyJob.php >> /home/public_html/braincore/logs/nightly.log 2>&1
 *
 * Usage:
 *   php scripts/NightlyJob.php
 *   php scripts/NightlyJob.php --dry-run
 */

// ─── CLI GUARD ────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "403 Forbidden: NightlyJob may only run from the command line.\n";
    exit(1);
}

$dryRun = in_array('--dry-run', $argv ?? [], true);

// ─── Bootstrap ────────────────────────────────────────────────
define('BRAINCORE_ROOT', dirname(__DIR__));
require_once BRAINCORE_ROOT . '/config/env.php';
require_once BRAINCORE_ROOT . '/config/autoload.php';

function bc_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

bc_log('NightlyJob started' . ($dryRun ? ' [DRY-RUN]' : ''));
$t = microtime(true);

// ── TASK 1: Habit decay ────────────────────────────────────────
bc_log('TASK 1: Habit Engine decay');
try {
    if (class_exists('HabitModel')) {
        if (!$dryRun) {
            $r = HabitModel::applyDecay();
            bc_log("  OK — decayed={$r['decayed']}, pruned={$r['removed']}");
        } else {
            bc_log('  [dry-run] Would call HabitModel::applyDecay()');
        }
    } else {
        bc_log('  SKIP — HabitModel not loaded');
    }
} catch (Throwable $e) { bc_log('  ERROR: ' . $e->getMessage()); }

// ── TASK 2: Purge brain_events ────────────────────────────────
bc_log('TASK 2: Purge old brain_events');
try {
    $days = max(1, (int)(getenv('EVENT_RETENTION_DAYS') ?: 90));
    if (!$dryRun) {
        $st = getDB()->prepare('DELETE FROM brain_events WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)');
        $st->execute([':d' => $days]);
        bc_log("  OK — purged {$st->rowCount()} rows older than {$days}d");
    } else { bc_log("  [dry-run] Would purge events > {$days}d"); }
} catch (Throwable $e) { bc_log('  ERROR: ' . $e->getMessage()); }

// ── TASK 3: Purge brain_decisions ─────────────────────────────
bc_log('TASK 3: Purge old brain_decisions');
try {
    $days = max(1, (int)(getenv('DECISION_RETENTION_DAYS') ?: 90));
    if (!$dryRun) {
        $st = getDB()->prepare('DELETE FROM brain_decisions WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)');
        $st->execute([':d' => $days]);
        bc_log("  OK — purged {$st->rowCount()} rows older than {$days}d");
    } else { bc_log("  [dry-run] Would purge decisions > {$days}d"); }
} catch (Throwable $e) { bc_log('  ERROR: ' . $e->getMessage()); }

// ── TASK 4: Purge resolved alerts ─────────────────────────────
bc_log('TASK 4: Purge resolved brain_alerts');
try {
    if (!$dryRun) {
        $st = getDB()->prepare("DELETE FROM brain_alerts WHERE status='resolved' AND resolved_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $st->execute();
        bc_log("  OK — purged {$st->rowCount()} resolved alerts");
    } else { bc_log('  [dry-run] Would purge resolved alerts > 30d'); }
} catch (Throwable $e) { bc_log('  ERROR: ' . $e->getMessage()); }

bc_log('NightlyJob done in ' . round(microtime(true) - $t, 3) . 's');
exit(0);
