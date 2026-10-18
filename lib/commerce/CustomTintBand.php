<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CustomTintBand  (Paint PB10c)
//  The price band per (brand, shade_code), computed live from the priced quotes
//  in commerce_tint_quotes. Powers: the PB10a estimate median, outlier detection
//  (a quote far above the band is hidden + rerouted + surfaced to ops), and the
//  per-litre repeat-lock (a customer's re-order of the same shade prices instantly
//  from the rate they were last charged, same seller). Read-only maths; no engine.
// ─────────────────────────────────────────────────────────────────────────────

class CustomTintBand
{
    private static function num(PDO $db, string $key, float $def): float
    {
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$key]); $v = $s->fetchColumn();
              if ($v !== false && is_numeric($v)) return (float)$v; } catch (\Throwable $e) {}
        return $def;
    }
    public static function minSamples(PDO $db): int { return (int)self::num($db, 'commerce_tint_band_min_samples', 3); }
    public static function outlierPct(PDO $db): float { return self::num($db, 'commerce_tint_band_outlier_pct', 50); }

    /** Per-litre sample set for a (brand, shade): non-outlier priced/ordered quotes. */
    private static function perLitres(PDO $db, string $brand, string $code): array
    {
        try {
            $s = $db->prepare("SELECT locked_per_litre FROM commerce_tint_quotes
                                WHERE brand=? AND shade_code=? AND outlier_flag=0 AND locked_per_litre IS NOT NULL
                                  AND status IN ('priced','ordered','making')");
            $s->execute([$brand, $code]);
            return array_values(array_filter(array_map('floatval', $s->fetchAll(PDO::FETCH_COLUMN)), fn($x) => $x > 0));
        } catch (\Throwable $e) { return []; }
    }

    /** @return array{count:int, median:?float, min:?float, max:?float} */
    public static function stats(PDO $db, string $brand, string $code): array
    {
        $v = self::perLitres($db, $brand, $code);
        if (!$v) return ['count' => 0, 'median' => null, 'min' => null, 'max' => null];
        sort($v); $n = count($v); $mid = intdiv($n, 2);
        $median = ($n % 2) ? $v[$mid] : ($v[$mid - 1] + $v[$mid]) / 2;
        return ['count' => $n, 'median' => round($median, 4), 'min' => round($v[0], 4), 'max' => round($v[$n - 1], 4)];
    }

    /** Band median per-litre, or null until there's any data (PB10a estimate uses this). */
    public static function median(PDO $db, string $brand, string $code): ?float
    {
        return self::stats($db, $brand, $code)['median'];
    }

    /** Is this per-litre an outlier? Only once the band has enough samples. */
    public static function isOutlier(PDO $db, string $brand, string $code, float $perLitre): bool
    {
        $st = self::stats($db, $brand, $code);
        if ($st['count'] < self::minSamples($db) || $st['median'] === null || $st['median'] <= 0) return false;
        return $perLitre > $st['median'] * (1 + self::outlierPct($db) / 100);
    }

    /**
     * The customer's locked rate for a shade they were already charged for — the
     * most recent ORDERED quote for (user, brand, code). Null if none.
     * @return array{final_per_litre:float, colour_per_litre:?float, vendor_id:?int, store_id:?int}|null
     */
    public static function lockFor(PDO $db, ?int $userId, string $brand, string $code): ?array
    {
        if (!$userId) return null;
        try {
            $s = $db->prepare("SELECT locked_per_litre, colour_per_litre, vendor_id, store_id
                                 FROM commerce_tint_quotes
                                WHERE user_id=? AND brand=? AND shade_code=? AND status='ordered'
                                  AND locked_per_litre IS NOT NULL
                                ORDER BY id DESC LIMIT 1");
            $s->execute([$userId, $brand, $code]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return [
                'final_per_litre'  => (float)$r['locked_per_litre'],
                'colour_per_litre' => $r['colour_per_litre'] !== null ? (float)$r['colour_per_litre'] : null,
                'vendor_id'        => $r['vendor_id'] !== null ? (int)$r['vendor_id'] : null,
                'store_id'         => $r['store_id'] !== null ? (int)$r['store_id'] : null,
            ];
        } catch (\Throwable $e) { return null; }
    }

    /** Outliers for the PB9 ops queue (hidden-from-customer prices awaiting review). */
    public static function opsOutliers(PDO $db): array
    {
        try {
            $s = $db->query("SELECT id, brand, shade_code, litres, estimate, vendor_id, updated_at
                               FROM commerce_tint_quotes WHERE outlier_flag=1 AND status NOT IN ('ordered','cancelled')
                              ORDER BY updated_at ASC");
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return []; }
    }
}
