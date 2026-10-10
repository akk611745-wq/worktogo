<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — DeliveryPricing  (S13)
//  core-completion-plan §2 S13; CEO C.3. Builds the ONE final, server-side price
//  by composing the S12 margin with the delivery fee under a DELIVERY-ABSORB
//  policy, so the customer never sees a jarring "₹25 delivery on a ₹50 item" and
//  never watches a price assemble on screen (no flicker — one computed number):
//    • high-margin item → WorkToGo eats the fee from its margin → FREE delivery.
//    • cheap item (margin can't carry the fee) → eat what the margin allows above
//      the loss-proof floor, HIDE part of the rest inside the product price, and
//      SHOW only a small delivery line.
//  Loss-proof is preserved: WorkToGo's kept margin (margin − eaten) must stay ≥
//  the floor (reuses MarginEngine::lossProofOk). Rider pay stays distance-based
//  and value-blind (S6) — untouched here. Area delivery rates are cached for
//  speed; a live quote runs only on a cache miss / edge. No engine is edited;
//  DeliveryEngine is called, not modified.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/MarginEngine.php';

class DeliveryPricing
{
    private static function setting(PDO $db, string $key, $def)
    {
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$key]); $v = $s->fetchColumn(); return ($v === false || $v === null || $v === '') ? $def : $v; }
        catch (\Throwable $e) { return $def; }
    }

    /** Absorb policy (app_settings.commerce_delivery_absorb). */
    public static function absorbPolicy(PDO $db): array
    {
        $default = ['enabled' => true, 'hide_ratio' => 0.5, 'absorb_cap_pct' => 100.0, 'min_shown_delivery' => 0.0];
        $raw = self::setting($db, 'commerce_delivery_absorb', null);
        if (is_string($raw)) { $j = json_decode($raw, true); if (is_array($j)) return array_merge($default, $j); }
        return $default;
    }

    public static function lossFloor(PDO $db): float
    {
        return (float)self::setting($db, 'commerce_loss_proof_floor', 0);
    }

    /**
     * Pure absorb math (no DB) — testable in isolation.
     *   eaten  = absorb? min(fee, max(0, margin − floor) × cap) : 0   (WorkToGo pays this from margin)
     *   remaining = fee − eaten ; hidden = remaining × hide_ratio (baked into product price)
     *   shown_delivery = remaining − hidden   (the only delivery the customer sees)
     * WorkToGo keeps margin − eaten (must stay ≥ floor → loss_proof_ok).
     */
    public static function absorb(float $base, float $margin, float $fee, float $floor, array $policy, array $marginPolicy): array
    {
        $fee = max(0.0, $fee);
        $enabled = !empty($policy['enabled']);
        $cap = max(0.0, min(1.0, (float)($policy['absorb_cap_pct'] ?? 100) / 100.0));
        $hideRatio = max(0.0, min(1.0, (float)($policy['hide_ratio'] ?? 0.5)));
        $minShown = max(0.0, (float)($policy['min_shown_delivery'] ?? 0.0));

        $headroom = max(0.0, $margin - $floor);
        $eaten = $enabled ? min($fee, $headroom * $cap) : 0.0;
        $eaten = round($eaten, 2);
        $remaining = round($fee - $eaten, 2);

        $hidden = round($remaining * $hideRatio, 2);
        $shown  = round($remaining - $hidden, 2);
        if ($shown < $minShown) { $hidden = round($remaining - $minShown, 2); $shown = $minShown; }
        if ($hidden < 0) { $hidden = 0.0; $shown = $remaining; }

        $productPrice = MarginEngine::customerPrice($base, round($margin + $hidden, 2), $marginPolicy);
        $total = round($productPrice + $shown, 2);
        $net = round($margin - $eaten, 2);

        return [
            'base'            => round($base, 2),
            'margin'          => round($margin, 2),
            'delivery_fee'    => $fee,
            'absorbed'        => $eaten,          // paid by WorkToGo out of margin
            'hidden_in_price' => $hidden,         // paid by customer, inside product price
            'shown_delivery'  => $shown,          // paid by customer, visible delivery line
            'product_price'   => $productPrice,   // base + margin + hidden
            'total'           => $total,          // the ONE number the customer pays
            'free_delivery'   => $shown <= 0.0001,
            'wtg_net_margin'  => $net,
            'loss_proof_ok'   => MarginEngine::lossProofOk($margin, $eaten, $floor),
        ];
    }

    /**
     * THE one-shot server price for one line: seller base (+ optional overrides) +
     * category + a delivery fee → the full composed breakdown. The storefront calls
     * this once and renders the result — no on-screen assembly, no flicker.
     */
    public static function composeFinalPrice(
        PDO $db, float $base, ?int $categoryId, float $fee,
        ?float $productPct = null, ?float $productFlat = null, ?float $categoryPct = null, ?float $categoryFlat = null
    ): array {
        $marginRes = MarginEngine::priceFromBase($db, $base, $productPct, $productFlat, $categoryPct, $categoryFlat, $categoryId);
        $out = self::absorb($base, (float)$marginRes['margin'], $fee, self::lossFloor($db), self::absorbPolicy($db), MarginEngine::policy($db));
        $out['margin_source'] = $marginRes['source'];
        $out['growth_multiplier'] = $marginRes['multiplier'];
        return $out;
    }

    public static function rateTtlSec(PDO $db): int
    {
        return max(0, (int)self::setting($db, 'commerce_delivery_rate_ttl_sec', 900));
    }

    /**
     * Area delivery-rate cache: return the cached quote for $areaKey when fresh
     * (within TTL, compared in SQL NOW() for TZ-safety), else run $live() and store
     * it. $live returns the quote array (must carry a numeric 'price'). Adds a
     * 'cached' boolean. Fully defensive — a cache hiccup falls back to a live quote.
     */
    public static function cachedAreaFee(PDO $db, string $areaKey, callable $live, ?int $ttlSec = null): array
    {
        $ttl = $ttlSec ?? self::rateTtlSec($db);
        try {
            if ($ttl > 0) {
                $s = $db->prepare("SELECT payload FROM commerce_delivery_rate_cache
                                    WHERE area_key = ? AND cached_at > (NOW() - INTERVAL ? SECOND) LIMIT 1");
                $s->execute([$areaKey, $ttl]);
                $p = $s->fetchColumn();
                if ($p !== false && $p !== null) {
                    $j = json_decode((string)$p, true);
                    if (is_array($j)) { $j['cached'] = true; return $j; }
                }
            }
        } catch (\Throwable $e) { /* fall through to live */ }

        $quote = $live();
        if (!is_array($quote)) $quote = [];
        try {
            $db->prepare("REPLACE INTO commerce_delivery_rate_cache (area_key, fee, available, payload, cached_at)
                          VALUES (?,?,?,?, NOW())")
               ->execute([$areaKey, (float)($quote['price'] ?? 0), !empty($quote['available']) ? 1 : 0, json_encode($quote)]);
        } catch (\Throwable $e) { /* cache write best-effort */ }
        $quote['cached'] = false;
        return $quote;
    }
}
