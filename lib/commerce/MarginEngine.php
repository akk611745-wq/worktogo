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
}
