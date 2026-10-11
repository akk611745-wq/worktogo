<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CoverageCalculator  (Paint Unit P2: the moat)
//  paint-unit-complete-pack.md D.3 + F.2/F.3. Turns a room/area into an
//  ESTIMATED paint requirement (litres → packs) and serves the prep/situation
//  guide. Two liability rules are baked in and PROVEN:
//    (1) ALWAYS an estimate — every result carries the "Estimated requirement —
//        varies with surface, shade and application" disclaimer.
//    (2) NEVER double-apply a 2-coat coverage. Manufacturer coverage already
//        states the area 1 L covers at `basis_coats` coats; we scale only by
//        (recommended_coats / basis_coats), so the default 2-coat→2-coat case
//        divides once and never multiplies by 2 again (F.2).
//  Pure math + config (buffer %, disclaimer from app_settings). No DB writes,
//  no engine edit. The customer app (paint-calculator) and /api/coverage call
//  this single source of truth so the number never drifts.
// ─────────────────────────────────────────────────────────────────────────────

class CoverageCalculator
{
    public const DISCLAIMER = 'Estimated requirement — varies with surface, shade and application. Darker shades and rough or porous surfaces may need more.';
    private const DEFAULT_BUFFER_PCT = 10.0;   // wastage/touch-up buffer (F.2 ×1.10)
    private const SQM_TO_SQFT = 10.7639;

    /** Config overrides (app_settings.commerce_coverage_config JSON). Default-safe. */
    public static function config(?PDO $db): array
    {
        $out = ['buffer_pct' => self::DEFAULT_BUFFER_PCT, 'disclaimer' => self::DISCLAIMER];
        if ($db === null) return $out;
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_coverage_config' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            if ($v) { $j = json_decode((string)$v, true); if (is_array($j)) {
                if (isset($j['buffer_pct']) && is_numeric($j['buffer_pct'])) $out['buffer_pct'] = (float)$j['buffer_pct'];
                if (!empty($j['disclaimer'])) $out['disclaimer'] = (string)$j['disclaimer'];
            } }
        } catch (\Throwable $e) { /* default-safe */ }
        return $out;
    }

    /** Convert an area value to square feet. */
    public static function toSqft(float $value, string $unit = 'sqft'): float
    {
        $u = strtolower(trim($unit));
        if ($u === 'sqm' || $u === 'm2' || $u === 'sq_m') return round($value * self::SQM_TO_SQFT, 2);
        return round($value, 2);
    }

    /**
     * Paintable wall area (sq.ft) from room dimensions (feet).
     * walls = 2·(L+W)·H; + ceiling (L·W) if included; − openings (doors/windows).
     */
    public static function wallArea(float $lengthFt, float $widthFt, float $heightFt, bool $includeCeiling = false, float $openingsSqft = 0.0): float
    {
        $walls = 2 * ($lengthFt + $widthFt) * $heightFt;
        $ceil  = $includeCeiling ? ($lengthFt * $widthFt) : 0.0;
        $net   = $walls + $ceil - max(0.0, $openingsSqft);
        return round(max(0.0, $net), 2);
    }

    /**
     * Litres needed for an area. coveragePerL = manufacturer area/L at basisCoats.
     * litres = (area / coveragePerL) · (recommendedCoats / basisCoats) · (1 + buffer).
     * The default basis=2, recommended=2 → divides once, NEVER ×2 again.
     */
    public static function litres(float $netAreaSqft, float $coveragePerL, int $basisCoats = 2, int $recommendedCoats = 2, float $bufferPct = self::DEFAULT_BUFFER_PCT): float
    {
        if ($coveragePerL <= 0 || $basisCoats <= 0 || $recommendedCoats <= 0 || $netAreaSqft <= 0) return 0.0;
        $raw = ($netAreaSqft / $coveragePerL) * ($recommendedCoats / $basisCoats);
        return round($raw * (1 + $bufferPct / 100), 2);
    }

    /**
     * Pack plan: prefer the single smallest pack that covers `litres`; if it
     * exceeds the largest pack, stack the largest then cover the remainder.
     * @return array{packs: array<string,int>, purchased_litres: float}|null
     */
    public static function packPlan(float $litres, array $packSizes): ?array
    {
        $sizes = array_values(array_unique(array_map('floatval', $packSizes)));
        sort($sizes);
        if ($litres <= 0 || empty($sizes)) return null;
        $largest = $sizes[count($sizes) - 1];
        $packs = [];
        $need = $litres;
        // stack the largest pack while the need clearly exceeds it
        while ($need > $largest + 1e-9) { $packs[(string)$largest] = ($packs[(string)$largest] ?? 0) + 1; $need -= $largest; }
        // cover the remainder with the smallest pack that is >= need
        $topper = $largest;
        foreach ($sizes as $s) { if ($s + 1e-9 >= $need) { $topper = $s; break; } }
        $packs[(string)$topper] = ($packs[(string)$topper] ?? 0) + 1;
        $purchased = 0.0; foreach ($packs as $size => $n) $purchased += (float)$size * $n;
        return ['packs' => $packs, 'purchased_litres' => round($purchased, 2)];
    }

    /**
     * Full estimate. $in keys: net_area_sqft OR (area_value + area_unit) OR
     * room dims (length_ft,width_ft,height_ft,include_ceiling,openings_sqft);
     * coverage_per_l, basis_coats(=2), recommended_coats(=2), pack_sizes[].
     * @return array estimate payload (always carries label + disclaimer)
     */
    public static function estimate(array $in, ?PDO $db = null): array
    {
        $cfg = self::config($db);
        $buffer = isset($in['buffer_pct']) && is_numeric($in['buffer_pct']) ? (float)$in['buffer_pct'] : (float)$cfg['buffer_pct'];

        // resolve net area
        if (isset($in['net_area_sqft'])) {
            $area = round((float)$in['net_area_sqft'], 2);
        } elseif (isset($in['length_ft'], $in['width_ft'], $in['height_ft'])) {
            $area = self::wallArea((float)$in['length_ft'], (float)$in['width_ft'], (float)$in['height_ft'], !empty($in['include_ceiling']), (float)($in['openings_sqft'] ?? 0));
        } elseif (isset($in['area_value'])) {
            $area = self::toSqft((float)$in['area_value'], (string)($in['area_unit'] ?? 'sqft'));
        } else {
            $area = 0.0;
        }

        $coverage = (float)($in['coverage_per_l'] ?? 0);
        $basis    = max(1, (int)($in['basis_coats'] ?? 2));
        $coats    = max(1, (int)($in['recommended_coats'] ?? 2));
        $packs    = is_array($in['pack_sizes'] ?? null) ? $in['pack_sizes'] : [];

        $litres = self::litres($area, $coverage, $basis, $coats, $buffer);
        $plan   = self::packPlan($litres, $packs);

        return [
            'net_area_sqft'     => $area,
            'coverage_per_l'    => $coverage,
            'basis_coats'       => $basis,
            'recommended_coats' => $coats,
            'buffer_pct'        => $buffer,
            'estimated_litres'  => $litres,
            'pack_plan'         => $plan,
            'label'             => 'Estimated requirement',
            'disclaimer'        => $cfg['disclaimer'],
            'assumptions'       => [
                'Manufacturer coverage used as stated (area per litre at ' . $basis . ' coat(s)); not multiplied again for coats.',
                'Includes a ' . rtrim(rtrim(number_format($buffer, 2, '.', ''), '0'), '.') . '% wastage/touch-up buffer.',
                'Surface prep, shade depth and application method change real usage.',
            ],
            'orderable_hint'    => $coverage <= 0 ? 'Enter the product coverage to estimate litres.' : null,
        ];
    }

    /** Prep + situation guide (D.3 / F.3). Advisory content, labelled. */
    public static function guide(): array
    {
        return [
            'prep' => [
                'new_wall' => ['Clean / let cure', 'Apply wall putty, sand smooth', 'Primer / sealer coat', 'Emulsion (usually 2 coats)'],
                'repaint'  => ['Clean, remove flaking paint', 'Spot-fill cracks with putty, spot-prime', 'Emulsion (1–2 coats; match existing)'],
            ],
            'situations' => [
                'interior'   => 'Interior emulsion; 2 coats typical. Washable lines resist stains better.',
                'exterior'   => 'Exterior emulsion / weatherproof; darker shades cover less — allow extra.',
                'wood_metal' => 'Use enamel (PU/oil) over the right primer; thin coats, let each dry.',
                'damp'       => 'Fix the source of damp first; use a damp-proof primer/putty before topcoat.',
            ],
            'notes' => [
                'Numbers here are estimates, never a promise — ' . self::DISCLAIMER,
                'Buy a little extra for touch-ups; keep the batch code for a future match.',
            ],
        ];
    }
}
