<?php

/**
 * BrainCore v3 — DecisionModel
 * PHP 7.2+ compatible (named args, match, str_contains removed)
 *
 *   CACHE: APCu shared memory with static array fallback.
 *   RETURN: resolve() returns { decision, from_cache }.
 *           Cache hits NOT logged → prevents DB bloat.
 *   LEARNING: HabitModel always active. Bypass at score >= 3.
 */

class DecisionModel
{
    private static $localCache = [];

    const CACHE_TTL    = 10;
    const CACHE_PREFIX = 'bc_';

    public static function resolve(array $context): array
    {
        // ── 1. Cache check ─────────────────────────────────────
        $cacheKey = self::makeCacheKey($context);
        $cached   = self::getFromCache($cacheKey);

        if ($cached !== null) {
            $src = $cached['source'] ?? '';
            if (strpos($src, 'rule_engine') !== false) {
                $cached['source'] = 'rule_engine_cached';
            } elseif (strpos($src, 'habit_engine') !== false) {
                $cached['source'] = 'habit_engine_cached';
            } else {
                $cached['source'] = 'fallback_cached';
            }
            return ['decision' => $cached, 'from_cache' => true];
        }

        $userId   = $context['user_id']   ?? null;
        $clientId = $context['client_id'] ?? 'unknown';

        // ── 2. User effective score injection ──────────────────
        if ($userId) {
            $effectiveScores = UserProfileModel::getEffectiveScores($userId, $clientId);

            if (!empty($effectiveScores)) {
                $topEffective = (float) reset($effectiveScores);
                $topCategory  = (string) array_key_first($effectiveScores);

                $categorySource = $context['category_source'] ?? 'caller';
                if ($topEffective >= 3 && $categorySource !== 'caller') {
                    $context['category']             = $topCategory;
                    $context['category_source']      = 'user_preference_decayed';
                    $context['user_effective_score'] = $topEffective;
                } elseif ($topEffective >= 3 && empty($context['category'])) {
                    $context['category']             = $topCategory;
                    $context['category_source']      = 'user_preference_decayed';
                    $context['user_effective_score'] = $topEffective;
                } else {
                    $context['user_effective_score'] = $topEffective;
                }
            }
        }

        // ── 3. Habit Engine — fast path ────────────────────────
        $habitAction = HabitModel::lookup($context);
        if ($habitAction !== null) {
            $decision = array_merge($habitAction, ['context' => self::contextSnapshot($context)]);
            self::storeInCache($cacheKey, $decision);
            return ['decision' => $decision, 'from_cache' => false];
        }

        // ── 4. RuleEngine ──────────────────────────────────────
        DecisionLogger::debug('engine.start', [
            'client'   => $clientId,
            'location' => $context['location']   ?? null,
            'category' => $context['category']   ?? null,
            'tod'      => $context['time_of_day'] ?? null,
            'dow'      => $context['day_of_week'] ?? null,
            'tz'       => $context['timezone']   ?? 'UTC',
        ]);

        $engineResult = RuleEngine::evaluate($context);
        $matched      = $engineResult['matched'];
        $invalidRules = $engineResult['invalid_rules'];

        // ── 5. Log invalid rules ───────────────────────────────
        foreach ($invalidRules as $badRule) {
            ImprovementModel::storeImprovement(
                $clientId,
                $context,
                'invalid_rule',
                $badRule['rule_id'],
                "Unparseable condition_exp: {$badRule['bad_condition']}"
            );
        }

        // ── 6. Log no-match ────────────────────────────────────
        if ($matched === null && empty($invalidRules)) {
            ImprovementModel::storeImprovement(
                $clientId,
                $context,
                'no_rule_match'
            );

            if (class_exists('AlertEngine')) {
                AlertEngine::onSystemAnomaly($clientId, $context);
            }
        }

        // ── 7. Build decision ──────────────────────────────────
        if ($matched !== null) {
            DecisionLogger::info('decision.matched', [
                'client' => $clientId,
                'rule'   => $matched['rule_name'] ?? null,
            ]);
            $decision = array_merge($matched, ['context' => self::contextSnapshot($context)]);
        } else {
            DecisionLogger::warning('decision.no_match', [
                'client'   => $clientId,
                'location' => $context['location'] ?? null,
                'category' => $context['category'] ?? null,
            ]);
            $decision = self::fallbackDecision($context);
        }

        // ── 8. Train Habit Engine after rule match ─────────────
        if ($matched !== null) {
            HabitModel::record($context, $matched);
        }

        // ── 9. Cache + return ──────────────────────────────────
        self::storeInCache($cacheKey, $decision);
        return ['decision' => $decision, 'from_cache' => false];
    }

    public static function log(
        string  $clientId,
        array   $inputContext,
        array   $output,
        $ruleId = null
    ): string {
        $db = getDB();
        $id = self::generateUuid();

        $snapshotJson = json_encode($inputContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sql = "
            INSERT INTO brain_decisions
                (id, client_id, rule_id, input_snapshot, input_data, output_data, status, created_at)
            VALUES
                (:id, :client_id, :rule_id, :input_snapshot, :input_data, :output_data, 'auto', NOW())
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':id'             => $id,
            ':client_id'      => $clientId,
            ':rule_id'        => $ruleId,
            ':input_snapshot' => $snapshotJson,
            ':input_data'     => $snapshotJson,
            ':output_data'    => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $id;
    }

    // ── PRIVATE ────────────────────────────────────────────────

    private static function fallbackDecision(array $context): array
    {
        $fallback = FallbackResponse::build($context, 'no_rule_match');
        return array_merge($fallback, ['context' => self::contextSnapshot($context)]);
    }

    private static function contextSnapshot(array $context): array
    {
        return [
            'client_id'            => $context['client_id']            ?? null,
            'location'             => $context['location']             ?? null,
            'category'             => $context['category']             ?? null,
            'category_source'      => $context['category_source']      ?? 'caller',
            'timezone'             => $context['timezone']             ?? 'UTC',
            'time_of_day'          => $context['time_of_day']          ?? null,
            'day_of_week'          => $context['day_of_week']          ?? null,
            'device_type'          => $context['device_type']          ?? null,
            'user_effective_score' => $context['user_effective_score'] ?? null,
        ];
    }

    private static function makeCacheKey(array $context): string
    {
        ksort($context);
        return self::CACHE_PREFIX . md5(serialize($context));
    }

    private static function getFromCache(string $key)
    {
        if (function_exists('apcu_fetch')) {
            $value = apcu_fetch($key, $success);
            return $success ? $value : null;
        }

        if (!isset(self::$localCache[$key])) return null;
        $entry = self::$localCache[$key];
        if (time() > $entry['expires_at']) {
            unset(self::$localCache[$key]);
            return null;
        }
        return $entry['result'];
    }

    private static function storeInCache(string $key, array $result): void
    {
        if (function_exists('apcu_store')) {
            apcu_store($key, $result, self::CACHE_TTL);
            return;
        }

        self::$localCache[$key] = [
            'result'     => $result,
            'expires_at' => time() + self::CACHE_TTL,
        ];
    }

    private static function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
