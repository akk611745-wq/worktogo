<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — MarginEngine  (S1)
//  commerce-system-blueprint §E. Shared-Core library (lib/commerce), commerce-
//  specific. Pure pricing logic: resolve the 3-tier margin, compute the customer
//  price from the seller's base, and enforce the loss-proof floor.
//
//  MONEY MODEL = MARGIN (reseller), NOT commission:
//      customer_price = seller_base + margin   (WorkToGo keeps `margin`)
//
//  Deliberately has NO dependency on the never-touch shopping engine. The
//  storefront adapter will call this at checkout to (a) show the customer the
//  final price and (b) persist the seller base (orders.seller_subtotal /
//  order_items.seller_base_price) so LedgerEngine can split margin exactly.
// ─────────────────────────────────────────────────────────────────────────────

class MarginEngine
{
    /** Platform default policy from app_settings.commerce_margin_policy (JSON). */
    public static function policy(PDO $db): array
    {
        $defaults = [
            'platform_margin_pct'  => 0.0,
            'platform_margin_flat' => 0.0,
            'min_margin_flat'      => 0.0,
            'rounding'             => 'none', // 'none' (2dp) | 'nearest_1' (whole rupee)
        ];
        try {
            $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'commerce_margin_policy' LIMIT 1");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            if ($raw) {
                $json = json_decode((string)$raw, true);
                if (is_array($json)) {
                    return array_merge($defaults, $json);
                }
            }
        } catch (\Throwable $e) {
            // schema not migrated / setting absent → safe defaults (no margin)
        }
        return $defaults;
    }

    /**
     * Resolve the effective margin for one product line.
     * Override precedence: product ?? category ?? platform  (NOT additive).
     */
    public static function effectiveMargin(
        ?float $productPct, ?float $productFlat,
        ?float $categoryPct, ?float $categoryFlat,
        array $policy
    ): array {
        $pct  = $productPct  ?? $categoryPct  ?? (float)$policy['platform_margin_pct'];
        $flat = $productFlat ?? $categoryFlat ?? (float)$policy['platform_margin_flat'];
        return ['pct' => (float)$pct, 'flat' => (float)$flat];
    }

    /** The money WorkToGo keeps, with the per-policy minimum floor applied. */
    public static function marginAmount(float $sellerBase, float $pct, float $flat, array $policy): float
    {
        $raw = $sellerBase * $pct / 100.0 + $flat;
        $min = (float)($policy['min_margin_flat'] ?? 0.0);
        return round(max($raw, $min), 2);
    }

    /** Final price the customer pays, rounded per policy. */
    public static function customerPrice(float $sellerBase, float $marginAmount, array $policy): float
    {
        $price = $sellerBase + $marginAmount;
        return (($policy['rounding'] ?? 'none') === 'nearest_1')
            ? round($price, 0)
            : round($price, 2);
    }

    /**
     * Convenience: seller base + the three margin inputs → [customer_price, margin].
     */
    public static function price(
        float $sellerBase,
        ?float $productPct, ?float $productFlat,
        ?float $categoryPct, ?float $categoryFlat,
        array $policy
    ): array {
        $m   = self::effectiveMargin($productPct, $productFlat, $categoryPct, $categoryFlat, $policy);
        $amt = self::marginAmount($sellerBase, $m['pct'], $m['flat'], $policy);
        return [
            'margin'         => $amt,
            'customer_price' => self::customerPrice($sellerBase, $amt, $policy),
        ];
    }

    /**
     * LOSS-PROOF FLOOR: WorkToGo's margin on the order must cover its own cost
     * exposure (e.g. delivery it absorbs) by at least `$floor`. Returns false
     * when the order would lose money and must be rejected at checkout.
     */
    public static function lossProofOk(float $marginAmount, float $platformCostExposure, float $floor): bool
    {
        return round($marginAmount - $platformCostExposure, 2) >= $floor;
    }

    // ── S12: SLAB margin + growth knob ──────────────────────────────────────────
    //  Flat % breaks at the extremes (earns nothing on a ₹50 item, gouges a ₹2L
    //  order), so margin becomes an admin-configurable SLAB by seller base:
    //    cheap → a flat rupee amount · mid → a % · high value → a decreasing %.
    //  A growth MULTIPLIER scales every computed margin live (start thin to win
    //  transactions, raise gradually) — no deploy. Loss-proof floor is unchanged
    //  and still enforced at checkout. All back-compat: the S1 methods above are
    //  untouched, and with no slab configured priceFromBase falls back to the flat
    //  3-tier, so nothing that doesn't opt in changes.

    /** Slab config (app_settings.commerce_margin_slabs). Disabled by default. */
    public static function slabConfig(PDO $db): array
    {
        $default = ['enabled' => false, 'bands' => [], 'by_category' => []];
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_margin_slabs' LIMIT 1");
            $s->execute();
            $raw = $s->fetchColumn();
            if ($raw) { $j = json_decode((string)$raw, true); if (is_array($j)) return array_merge($default, $j); }
        } catch (\Throwable $e) { /* defaults */ }
        return $default;
    }

    /** Growth-mode knob (app_settings.commerce_margin_multiplier); 1.0 = full slab, 0 = near-free. */
    public static function growthMultiplier(PDO $db): float
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_margin_multiplier' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            if ($v !== false && $v !== null && $v !== '') return max(0.0, (float)$v);
        } catch (\Throwable $e) { /* default */ }
        return 1.0;
    }

    /**
     * Raw margin from the slab for a given seller base: picks the first band whose
     * `up_to` is absent (catch-all) or >= base (inclusive), using the category's
     * own bands if `by_category[categoryId]` is set, else the default bands. A band
     * is `{"up_to":N,"flat":F}` or `{"up_to":N,"pct":P}` (last band omits up_to).
     * Returns null when the slab is disabled/empty so the caller falls back to flat.
     */
    public static function slabMargin(float $sellerBase, array $slab, ?int $categoryId = null): ?float
    {
        if (empty($slab['enabled'])) return null;
        $bands = $slab['bands'] ?? [];
        if ($categoryId !== null && !empty($slab['by_category'][(string)$categoryId]['bands'])) {
            $bands = $slab['by_category'][(string)$categoryId]['bands'];
        }
        if (!is_array($bands) || !$bands) return null;
        foreach ($bands as $b) {
            $hasCap = array_key_exists('up_to', $b) && $b['up_to'] !== null;
            if (!$hasCap || $sellerBase <= (float)$b['up_to']) {
                if (array_key_exists('flat', $b)) return (float)$b['flat'];
                if (array_key_exists('pct', $b))  return $sellerBase * (float)$b['pct'] / 100.0;
                return 0.0;
            }
        }
        return null; // no catch-all band matched → fall back to flat
    }

    /**
     * The one pricing entry the storefront/seller catalogue uses: seller base →
     * [margin, customer_price] applying (1) an explicit product/category override
     * if present (the exact-price escape hatch, 3-tier precedence preserved), else
     * (2) the slab if enabled, else (3) the platform flat; then (4) the growth
     * multiplier; then (5) the per-policy minimum floor. Loss-proof is enforced
     * separately at checkout (needs the delivery exposure), via lossProofOk().
     */
    public static function priceFromBase(
        PDO $db, float $sellerBase,
        ?float $productPct = null, ?float $productFlat = null,
        ?float $categoryPct = null, ?float $categoryFlat = null,
        ?int $categoryId = null
    ): array {
        $policy = self::policy($db);
        $slab   = self::slabConfig($db);
        $mult   = self::growthMultiplier($db);

        $override = ($productPct !== null || $productFlat !== null || $categoryPct !== null || $categoryFlat !== null);
        $source = 'flat';
        $raw = null;

        if (!$override) {
            $raw = self::slabMargin($sellerBase, $slab, $categoryId);
            if ($raw !== null) $source = 'slab';
        }
        if ($raw === null) {
            $m = self::effectiveMargin($productPct, $productFlat, $categoryPct, $categoryFlat, $policy);
            $raw = $sellerBase * $m['pct'] / 100.0 + $m['flat'];
            if ($source !== 'slab') $source = $override ? 'override' : 'flat';
        }

        $raw *= $mult;                                            // growth knob
        $min = (float)($policy['min_margin_flat'] ?? 0.0);
        $margin = round(max($raw, $min), 2);                      // minimum floor
        return [
            'margin'         => $margin,
            'customer_price' => self::customerPrice($sellerBase, $margin, $policy),
            'source'         => $source,
            'multiplier'     => $mult,
        ];
    }
}
