<?php

/**
 * BrainCore - AlertEngine
 * PHP 7.2+ compatible
 *
 * Non-blocking alert system. Every public method is wrapped
 * in try/catch — failures here NEVER affect the caller.
 *
 * Alert types: delivery_delay, high_demand_location,
 *              action_failure, repeated_failure, system_anomaly
 */

class AlertEngine
{
    const DELIVERY_DELAY_MINUTES  = 30;
    const VENDOR_DEMAND_THRESHOLD = 20;
    const FAILURE_THRESHOLD       = 3;
    const ANOMALY_THRESHOLD       = 10;

    // ── HOOK 1: Called from ActionEngine::execute() ───────────

    public static function onActionFailure(
        string $clientId,
        string $actionType,
        string $errorMsg,
        string $decisionId = ''
    ): void {
        try {
            AlertModel::create(
                $clientId,
                'action_failure',
                'low',
                "Action [{$actionType}] failed: " . substr($errorMsg, 0, 300),
                [
                    'action_type' => $actionType,
                    'decision_id' => $decisionId,
                    'error'       => $errorMsg,
                ]
            );

            $failCount = self::countRecentFailures($clientId);

            if ($failCount >= self::FAILURE_THRESHOLD) {
                AlertModel::create(
                    $clientId,
                    'repeated_failure',
                    'high',
                    "Repeated action failures: {$failCount} in the last hour for client [{$clientId}]. Immediate review required.",
                    [
                        'failure_count'    => $failCount,
                        'last_action_type' => $actionType,
                        'threshold'        => self::FAILURE_THRESHOLD,
                    ]
                );
            }

        } catch (\Throwable $e) {
            error_log('[AlertEngine::onActionFailure] ' . $e->getMessage());
        }
    }

    // ── HOOK 2: Called from DecisionModel::resolve() ──────────

    public static function onSystemAnomaly(string $clientId, array $context): void
    {
        try {
            $missCount = self::countRecentNoRuleMatches($clientId);

            if ($missCount >= self::ANOMALY_THRESHOLD) {
                AlertModel::create(
                    $clientId,
                    'system_anomaly',
                    'medium',
                    "High no_rule_match rate: {$missCount} unmatched decisions in the last hour for client [{$clientId}]. Rules may need updating.",
                    [
                        'miss_count'     => $missCount,
                        'threshold'      => self::ANOMALY_THRESHOLD,
                        'sample_context' => [
                            'location'    => $context['location']    ?? null,
                            'category'    => $context['category']    ?? null,
                            'time_of_day' => $context['time_of_day'] ?? null,
                        ],
                    ]
                );
            }

        } catch (\Throwable $e) {
            error_log('[AlertEngine::onSystemAnomaly] ' . $e->getMessage());
        }
    }

    // ── HOOK 3: Called from EventController::store() ──────────

    public static function onEventStored(string $clientId, array $eventData): void
    {
        try {
            $waitMinutes = isset($eventData['wait_minutes'])
                ? (int) $eventData['wait_minutes']
                : null;

            if ($waitMinutes !== null && $waitMinutes >= self::DELIVERY_DELAY_MINUTES) {
                $location = $eventData['location'] ?? 'unknown';
                AlertModel::create(
                    $clientId,
                    'delivery_delay',
                    'high',
                    "Delivery delay detected: {$waitMinutes} min wait at [{$location}]. Threshold is " . self::DELIVERY_DELAY_MINUTES . " min.",
                    $eventData
                );
            }

            if (!empty($eventData['location'])) {
                self::checkVendorDemand($clientId, $eventData);
            }

        } catch (\Throwable $e) {
            error_log('[AlertEngine::onEventStored] ' . $e->getMessage());
        }
    }

    // ── PRIVATE ───────────────────────────────────────────────

    private static function countRecentFailures(string $clientId): int
    {
        try {
            $db = getDB();
            $st = $db->prepare("
                SELECT COUNT(*) AS cnt
                FROM   brain_actions
                WHERE  client_id   = :client_id
                  AND  status      = 'failed'
                  AND  created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $st->execute([':client_id' => $clientId]);
            return (int) $st->fetch(PDO::FETCH_ASSOC)['cnt'];
        } catch (\Throwable $e) {
            error_log('[AlertEngine::countRecentFailures] ' . $e->getMessage());
            return 0;
        }
    }

    private static function countRecentNoRuleMatches(string $clientId): int
    {
        try {
            $db = getDB();
            $st = $db->prepare("
                SELECT COUNT(*) AS cnt
                FROM   brain_improvements
                WHERE  client_id   = :client_id
                  AND  reason      = 'no_rule_match'
                  AND  created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $st->execute([':client_id' => $clientId]);
            return (int) $st->fetch(PDO::FETCH_ASSOC)['cnt'];
        } catch (\Throwable $e) {
            error_log('[AlertEngine::countRecentNoRuleMatches] ' . $e->getMessage());
            return 0;
        }
    }

    private static function checkVendorDemand(string $clientId, array $eventData): void
    {
        try {
            $location = $eventData['location'];
            $db = getDB();
            $st = $db->prepare("
                SELECT COUNT(*) AS cnt
                FROM   brain_events
                WHERE  client_id   = :client_id
                  AND  location    = :location
                  AND  created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $st->execute([':client_id' => $clientId, ':location' => $location]);
            $demandCount = (int) $st->fetch(PDO::FETCH_ASSOC)['cnt'];

            if ($demandCount >= self::VENDOR_DEMAND_THRESHOLD) {
                AlertModel::create(
                    $clientId,
                    'high_demand_location',
                    'medium',
                    "High demand ({$demandCount} events/hr) at [{$location}] but no active vendor signal detected.",
                    [
                        'location'     => $location,
                        'demand_count' => $demandCount,
                        'threshold'    => self::VENDOR_DEMAND_THRESHOLD,
                        'event_type'   => $eventData['event_type'] ?? null,
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log('[AlertEngine::checkVendorDemand] ' . $e->getMessage());
        }
    }
}
