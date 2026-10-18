<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CustomTintQuote  (Paint PB10a)
//  The request→quote lifecycle for a made-to-order tinted shade, on top of the
//  existing CustomTint spine (brand-code guard, repeat→same-seller, consent).
//  PB10a: validate the brand+code, give an instant ESTIMATE, route the request
//  to a seller as a quote (status 'quoted'), and let the quote-timeout sweep
//  reroute it to the next seller when the seller doesn't answer in
//  commerce_quote_timeout_sec (reusing RoutingEngine::quoteTimeoutSec, the PB6
//  seam). Pricing/advance = PB10b; price band + repeat-lock = PB10c.
//  Dormant until a brand is enabled. Reuses OrderabilityResolver's seller gate;
//  no engine edit.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/CustomTint.php';
require_once __DIR__ . '/RoutingEngine.php';
require_once __DIR__ . '/OrderabilityResolver.php';
require_once __DIR__ . '/CommerceEventBus.php';

class CustomTintQuote
{
    /** Fallback estimate ₹/litre until the PB10c band has data. */
    public static function estimatePerLitre(PDO $db): float
    {
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_tint_estimate_per_litre' LIMIT 1"); $s->execute(); $v = $s->fetchColumn();
              if ($v !== false && is_numeric($v) && (float)$v > 0) return (float)$v; } catch (\Throwable $e) {}
        return 250.0;
    }

    /**
     * Instant estimate for a shade + litres. PB10c overrides per_litre with the
     * band median for (brand, shade_code) when enough quotes exist.
     * @return array{ok:bool, approx:float, per_litre:float, source:string, note:string}
     */
    public static function estimate(PDO $db, string $brand, string $code, float $litres): array
    {
        $perL = self::estimatePerLitre($db); $source = 'default';
        if (class_exists('CustomTintBand')) {                       // PB10c
            $m = CustomTintBand::median($db, $brand, $code);
            if ($m !== null && $m > 0) { $perL = $m; $source = 'band_median'; }
        }
        $litres = max(0.0, $litres);
        return ['ok' => true, 'approx' => round($perL * $litres, 2), 'per_litre' => round($perL, 2),
                'source' => $source, 'note' => 'Approx only — the exact price comes from the painter’s quote.'];
    }

    /** Sellers who can tint this brand (carry it, in-city, available), company-first. */
    public static function candidateSellers(PDO $db, string $brand, ?int $cityId, array $exclude = []): array
    {
        $sql = "SELECT DISTINCT p.vendor_id, p.store_id, s.ownership
                  FROM products p
                  JOIN stores s ON s.id = p.store_id
                  LEFT JOIN vendors v ON v.id = s.owner_vendor_id
                 WHERE JSON_VALUE(p.attributes,'$.is_parent')='1'
                   AND JSON_VALUE(p.attributes,'$.brand') = :brand
                   AND p.deleted_at IS NULL AND s.status='active' AND s.deleted_at IS NULL
                   AND (s.city_id = :city OR s.city_id IS NULL)
                   AND " . OrderabilityResolver::sellerAvailableSql('s', 'v') . "
                 ORDER BY (s.ownership='company') DESC, p.vendor_id";
        try {
            $st = $db->prepare($sql);
            $st->bindValue(':brand', $brand);
            $st->bindValue(':city', $cityId, $cityId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $st->execute();
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (in_array((int)$r['vendor_id'], array_map('intval', $exclude), true)) continue;
                $out[] = ['vendor_id' => (int)$r['vendor_id'], 'store_id' => $r['store_id'] !== null ? (int)$r['store_id'] : null];
            }
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    /**
     * Create a quote request. Validates the brand is enabled + the code exists in
     * that brand, routes to the first seller (the same seller for a repeat repaint),
     * records the estimate, and emits a 'quote_offered' signal.
     * @param array $c user_id, customer_ref, brand, shade_code, product_id, litres, pack_size, pack_unit, mode, city_id
     * @return array{ok:bool, reason?:string, quote_id?:int, estimate?:float, vendor_id?:int, status?:string}
     */
    public static function request(PDO $db, array $c): array
    {
        $brand = trim((string)($c['brand'] ?? '')); $code = trim((string)($c['shade_code'] ?? ''));
        if (!CustomTint::brandEnabled($db, $brand)) return ['ok' => false, 'reason' => 'brand_not_enabled'];
        if ($code === '' || !CustomTint::brandHasCode($db, $brand, $code)) return ['ok' => false, 'reason' => 'shade_code_not_in_brand'];

        $shade = CustomTint::resolveShade($db, $brand, $code);
        $mode  = CustomTint::modeRule((string)($c['mode'] ?? 'new'))['mode'];
        $userId = isset($c['user_id']) ? (int)$c['user_id'] : null;
        $cityId = isset($c['city_id']) && $c['city_id'] !== '' ? (int)$c['city_id'] : null;
        $litres = (float)($c['litres'] ?? 0);

        // Routing: a repeat repaint pins the seller who made it before; else first candidate.
        $vendorId = CustomTint::routeFirstSeller($db, $userId, $brand, $code, $mode);
        $storeId = null;
        $cands = self::candidateSellers($db, $brand, $cityId);
        if ($vendorId !== null) { foreach ($cands as $cd) if ($cd['vendor_id'] === $vendorId) { $storeId = $cd['store_id']; break; } }
        if ($vendorId === null || $storeId === null) {
            if (!$cands) return ['ok' => false, 'reason' => 'no_seller'];
            $vendorId = $cands[0]['vendor_id']; $storeId = $cands[0]['store_id'];
        }

        $est = self::estimate($db, $brand, $code, $litres)['approx'];
        $db->prepare(
            "INSERT INTO commerce_tint_quotes (user_id, customer_ref, brand, shade_code, shade_name, product_id, litres, pack_size, pack_unit, mode, estimate, status, vendor_id, store_id, city_id, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, 'quoted', ?,?,?, NOW(), NOW())"
        )->execute([
            $userId, $c['customer_ref'] ?? null, $brand, $code, $shade['shade_name'] ?? null,
            isset($c['product_id']) && $c['product_id'] !== '' ? (int)$c['product_id'] : null,
            $litres, $c['pack_size'] ?? null, $c['pack_unit'] ?? null, $mode, $est, $vendorId, $storeId, $cityId,
        ]);
        $qid = (int)$db->lastInsertId();
        try { CommerceEventBus::emit($db, 'quote_offered', ['order_id' => null, 'actor_type' => 'system', 'payload' => ['quote_id' => $qid, 'brand' => $brand, 'shade_code' => $code, 'vendor_id' => $vendorId]]); } catch (\Throwable $e) {}
        return ['ok' => true, 'quote_id' => $qid, 'estimate' => $est, 'vendor_id' => $vendorId, 'status' => 'quoted'];
    }

    /** One quote scoped to its owner. */
    public static function get(PDO $db, int $quoteId): ?array
    {
        $s = $db->prepare("SELECT * FROM commerce_tint_quotes WHERE id=? LIMIT 1"); $s->execute([$quoteId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** A customer's quotes (status feed). */
    public static function myQuotes(PDO $db, int $userId): array
    {
        $s = $db->prepare("SELECT id, brand, shade_code, shade_name, litres, mode, estimate, final_price, status, created_at FROM commerce_tint_quotes WHERE user_id=? ORDER BY id DESC LIMIT 50");
        $s->execute([$userId]); return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The seller's open quote box: shade code + what the customer wants (base_type blank — seller fills). */
    public static function sellerInbox(PDO $db, int $vendorId): array
    {
        $s = $db->prepare("SELECT id, brand, shade_code, shade_name, litres, pack_size, pack_unit, mode, estimate, base_type, created_at
                             FROM commerce_tint_quotes WHERE vendor_id=? AND status='quoted' ORDER BY created_at ASC");
        $s->execute([$vendorId]); return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Seller answers the quote box: records the raw COLOUR price + the base he
     * read from his shade book, moves it to 'priced'. The MarginEngine math +
     * ceiling + the customer's final-price popup are PB10b. Ownership enforced.
     */
    public static function sellerRespond(PDO $db, int $quoteId, int $vendorId, float $colourPrice, ?string $baseType = null): array
    {
        $q = self::get($db, $quoteId);
        if (!$q) return ['ok' => false, 'reason' => 'not_found'];
        if ((int)$q['vendor_id'] !== $vendorId) return ['ok' => false, 'reason' => 'not_yours'];
        if ($q['status'] !== 'quoted') return ['ok' => false, 'reason' => 'not_open', 'state' => $q['status']];
        if ($colourPrice <= 0) return ['ok' => false, 'reason' => 'bad_price'];
        $db->prepare("UPDATE commerce_tint_quotes SET colour_price=?, base_type=COALESCE(?, base_type), status='priced', updated_at=NOW() WHERE id=?")
           ->execute([round($colourPrice, 2), $baseType !== null && $baseType !== '' ? $baseType : null, $quoteId]);
        return ['ok' => true, 'quote_id' => $quoteId, 'status' => 'priced'];
    }

    /** Move a quote to the next eligible seller (or to ops if none). */
    public static function reroute(PDO $db, int $quoteId): array
    {
        $q = self::get($db, $quoteId);
        if (!$q || $q['status'] !== 'quoted') return ['rerouted' => false, 'reason' => 'not_open'];
        $cands = self::candidateSellers($db, $q['brand'], $q['city_id'] !== null ? (int)$q['city_id'] : null, [(int)$q['vendor_id']]);
        if (!$cands) {
            $db->prepare("UPDATE commerce_tint_quotes SET status='needs_ops', updated_at=NOW() WHERE id=?")->execute([$quoteId]);
            return ['rerouted' => false, 'reason' => 'no_alternative', 'status' => 'needs_ops'];
        }
        $next = $cands[0];
        $db->prepare("UPDATE commerce_tint_quotes SET vendor_id=?, store_id=?, reroute_count=reroute_count+1, updated_at=NOW() WHERE id=?")
           ->execute([$next['vendor_id'], $next['store_id'], $quoteId]);
        try { CommerceEventBus::emit($db, 'quote_offered', ['order_id' => null, 'actor_type' => 'system', 'payload' => ['quote_id' => $quoteId, 'rerouted_to' => $next['vendor_id']]]); } catch (\Throwable $e) {}
        return ['rerouted' => true, 'vendor_id' => $next['vendor_id']];
    }

    /**
     * Cron sweep: quotes the seller didn't price within the quote timeout reroute
     * to the next seller (reuses RoutingEngine::quoteTimeoutSec — the PB6 seam).
     * @return int number rerouted / flagged
     */
    public static function expireStaleQuotes(PDO $db): int
    {
        $timeout = RoutingEngine::quoteTimeoutSec($db);
        try {
            $q = $db->prepare("SELECT id FROM commerce_tint_quotes WHERE status='quoted' AND updated_at < (NOW() - INTERVAL ? SECOND)");
            $q->execute([$timeout]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) { return 0; }
        $n = 0;
        foreach ($ids as $id) { $r = self::reroute($db, (int)$id); if (!empty($r['rerouted']) || ($r['status'] ?? '') === 'needs_ops') $n++; }
        return $n;
    }

    /** Pending quotes for the ops queue (PB9 feed; price-outlier joins in PB10c). */
    public static function opsQueue(PDO $db): array
    {
        try {
            $s = $db->query("SELECT id, brand, shade_code, litres, estimate, status, vendor_id, reroute_count, updated_at
                               FROM commerce_tint_quotes WHERE status='needs_ops' ORDER BY updated_at ASC");
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return []; }
    }
}
