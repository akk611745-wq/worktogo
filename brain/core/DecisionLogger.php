<?php

/**
 * BrainCore - DecisionLogger
 *
 * Structured file-based logger for BrainCore decision pipeline.
 *
 * ─── WHAT THIS LOGS ──────────────────────────────────────────
 *
 *   Every log entry is a JSON line written to LOG_FILE (.env).
 *   Log level controlled by LOG_LEVEL (.env): error|warning|info|debug
 *
 *   Levels (least → most verbose):
 *     error   → only hard errors (DB fail, auth fail)
 *     warning → errors + unexpected states (invalid rule, no match)
 *     info    → warnings + decision outcomes (match/fallback)
 *     debug   → everything including full context snapshots
 *
 * ─── LOG FORMAT ──────────────────────────────────────────────
 *
 *   Each line is a JSON object:
 *   {
 *     "ts":       "2026-04-06T10:23:45+05:30",
 *     "level":    "info",
 *     "event":    "decision.matched",
 *     "client":   "worktogo",
 *     "rule":     "evening_boost",
 *     "source":   "rule_engine",
 *     "context":  {...},   // only at debug level
 *     "output":   {...}    // only at debug level
 *   }
 *
 * ─── USAGE ───────────────────────────────────────────────────
 *
 *   DecisionLogger::info('decision.matched', ['client' => 'worktogo', 'rule' => 'evening_boost']);
 *   DecisionLogger::warning('decision.no_match', ['client' => 'worktogo']);
 *   DecisionLogger::error('db.connection_failed', ['message' => $e->getMessage()]);
 *   DecisionLogger::debug('context.built', ['context' => $context]);
 */

class DecisionLogger
{
    private const LEVELS = ['error' => 0, 'warning' => 1, 'info' => 2, 'debug' => 3];

    /**
     * Log at INFO level.
     */
    public static function info(string $event, array $data = []): void
    {
        self::write('info', $event, $data);
    }

    /**
     * Log at WARNING level.
     */
    public static function warning(string $event, array $data = []): void
    {
        self::write('warning', $event, $data);
    }

    /**
     * Log at ERROR level.
     */
    public static function error(string $event, array $data = []): void
    {
        self::write('error', $event, $data);
    }

    /**
     * Log at DEBUG level — includes full context/output dumps.
     */
    public static function debug(string $event, array $data = []): void
    {
        self::write('debug', $event, $data);
    }

    // ──────────────────────────────────────────────────────────

    private static function write(string $level, string $event, array $data): void
    {
        // Check configured log level
        $configuredLevel = strtolower(trim(getenv('LOG_LEVEL') ?: 'error'));

        $configuredRank = self::LEVELS[$configuredLevel] ?? 0;
        $messageRank    = self::LEVELS[$level]           ?? 0;

        if ($messageRank > $configuredRank) {
            return; // Below configured threshold — skip
        }

        $entry = array_merge([
            'ts'    => date('c'),  // ISO 8601
            'level' => $level,
            'event' => $event,
        ], $data);

        $line    = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $logFile = __DIR__ . '/../' . (getenv('LOG_FILE') ?: 'logs/braincore.log');

        // Suppress errors — logger must never crash the main request
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
