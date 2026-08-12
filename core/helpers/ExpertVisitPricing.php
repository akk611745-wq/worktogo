<?php
/**
 * ExpertVisitPricing — WorkToGo V2 expert-visit fee resolver (admin-controlled).
 *
 * Resolves the applicable expert-visit fee for a (category, city) pair using the
 * most-specific active override, falling back to the global default in
 * app_settings. This is the reusable read the customer pay-flow (next task) will
 * consume — it never writes and never touches the existing inspection flow.
 *
 * PRECEDENCE (most specific wins):
 *     category + city   >   category   >   city   >   global default
 *
 * Storage:
 *   - overrides:  expert_visit_pricing (category_id / city_id nullable, amount,
 *                 is_free, is_active)  — migration 2026_07_04_007
 *   - global:     app_settings.expert_visit_fee (number) + expert_visit_free (bool)
 *
 * Every path is defensive: if the table or settings are missing (migration not
 * applied), it degrades to a sane global default so callers never break.
 *
 * @return array{is_free:bool, amount:float, source:string}
 *         source ∈ 'category_city' | 'category' | 'city' | 'global'
 */
class ExpertVisitPricing
{
    /** Last-resort default if even app_settings is unavailable. */
    const HARD_DEFAULT_FEE = 299.0;

    /** @var bool|null cached table-existence flag for this request */
    private static $tableExists = null;

    /**
     * Resolve the expert-visit fee for a category/city.
     * Pass null (or 0) for an unknown category/city — it degrades to the
     * less-specific scopes / global default automatically.
     */
    public static function resolve(PDO $db, ?int $categoryId = null, ?int $cityId = null): array
    {
        $cat  = ($categoryId && $categoryId > 0) ? $categoryId : null;
        $city = ($cityId && $cityId > 0) ? $cityId : null;

        try {
            if (self::hasTable($db)) {
                $sql = "SELECT amount, is_free,
                            CASE
                              WHEN category_id = :cat1 AND city_id = :city1 THEN 4
                              WHEN category_id = :cat2 AND city_id IS NULL   THEN 3
                              WHEN category_id IS NULL AND city_id = :city2  THEN 2
                              ELSE 0
                            END AS prio
                        FROM expert_visit_pricing
                        WHERE is_active = 1
                          AND (is_free = 1 OR amount IS NOT NULL)
                          AND (category_id = :cat3 OR category_id IS NULL)
                          AND (city_id = :city3 OR city_id IS NULL)
                        HAVING prio > 0
                        ORDER BY prio DESC, id DESC
                        LIMIT 1";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':cat1' => $cat, ':city1' => $city,
                    ':cat2' => $cat,
                    ':city2' => $city,
                    ':cat3' => $cat, ':city3' => $city,
                ]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $isFree = (int)$row['is_free'] === 1;
                    $prio   = (int)$row['prio'];
                    $source = $prio === 4 ? 'category_city' : ($prio === 3 ? 'category' : 'city');
                    return [
                        'is_free' => $isFree,
                        'amount'  => $isFree ? 0.0 : (float)$row['amount'],
                        'source'  => $source,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // fall through to the global default
        }

        return self::globalDefault($db);
    }

    /** The global default (app_settings). Public so admin UIs can read it. */
    public static function globalDefault(PDO $db): array
    {
        $isFree = false;
        $amount = self::HARD_DEFAULT_FEE;
        try {
            $stmt = $db->prepare(
                "SELECT setting_key, setting_value FROM app_settings
                  WHERE setting_key IN ('expert_visit_fee', 'expert_visit_free')"
            );
            $stmt->execute();
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[$r['setting_key']] = $r['setting_value'];
            }
            if (array_key_exists('expert_visit_free', $map)) {
                $isFree = in_array(strtolower(trim((string)$map['expert_visit_free'])), ['1', 'true', 'yes', 'on'], true);
            }
            if (array_key_exists('expert_visit_fee', $map) && $map['expert_visit_fee'] !== '') {
                $amount = (float)$map['expert_visit_fee'];
            }
        } catch (\Throwable $e) {
            $amount = self::HARD_DEFAULT_FEE;
        }
        return ['is_free' => $isFree, 'amount' => $isFree ? 0.0 : $amount, 'source' => 'global'];
    }

    /** Does the overrides table exist yet? Cached per request. */
    public static function hasTable(PDO $db): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expert_visit_pricing'"
            );
            $stmt->execute();
            return self::$tableExists = ((int)$stmt->fetchColumn() > 0);
        } catch (\Throwable $e) {
            return self::$tableExists = false;
        }
    }
}
