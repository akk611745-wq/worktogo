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
require_once __DIR__ . '/MarginEngine.php';     // PB10b: margin on the colour-price total
require_once __DIR__ . '/CommercePayment.php';  // PB10b: advance intent
require_once __DIR__ . '/CustomTintBand.php';   // PB10c: price band + repeat-lock

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

        // PB10c REPEAT-LOCK: a shade this customer was already charged for prices
        // instantly from their locked per-litre — same seller, no seller re-ask.
        $lock = CustomTintBand::lockFor($db, $userId, $brand, $code);
        if ($lock && $litres > 0 && $lock['vendor_id']) {
            $final  = round($lock['final_per_litre'] * $litres, 2);
            $colour = $lock['colour_per_litre'] !== null ? round($lock['colour_per_litre'] * $litres, 2) : null;
            $db->prepare(
                "INSERT INTO commerce_tint_quotes (user_id, customer_ref, brand, shade_code, shade_name, product_id, litres, pack_size, pack_unit, mode, estimate, colour_price, margin, final_price, locked_per_litre, colour_per_litre, status, vendor_id, store_id, city_id, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'priced', ?,?,?, NOW(), NOW())"
            )->execute([
                $userId, $c['customer_ref'] ?? null, $brand, $code, $shade['shade_name'] ?? null,
                isset($c['product_id']) && $c['product_id'] !== '' ? (int)$c['product_id'] : null,
                $litres, $c['pack_size'] ?? null, $c['pack_unit'] ?? null, $mode, $final,
                $colour, $colour !== null ? round($final - $colour, 2) : null, $final,
                round($lock['final_per_litre'], 4), $lock['colour_per_litre'] !== null ? round($lock['colour_per_litre'], 4) : null,
                $lock['vendor_id'], $lock['store_id'], $cityId,
            ]);
            $qid = (int)$db->lastInsertId();
            return ['ok' => true, 'quote_id' => $qid, 'estimate' => $final, 'final_price' => $final, 'vendor_id' => $lock['vendor_id'], 'status' => 'priced', 'locked' => true];
        }

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
        // PB10b: run the colour price through the SAME MarginEngine (margin on the
        // total), then cap by the admin ceiling (× the estimate) so it can't balloon.
        $priced = self::finalize($db, $q, round($colourPrice, 2));
        $litres = (float)($q['litres'] ?? 0);
        $colourPerL = $litres > 0 ? round($colourPrice / $litres, 4) : null;
        // PB10c: a per-litre far above the band is an OUTLIER — not shown to the
        // customer. Record it, flag it for ops, and reroute to the next seller.
        if ($priced['per_litre'] !== null && CustomTintBand::isOutlier($db, $q['brand'], $q['shade_code'], (float)$priced['per_litre'])) {
            $db->prepare("UPDATE commerce_tint_quotes SET colour_price=?, base_type=COALESCE(?, base_type), outlier_flag=1, updated_at=NOW() WHERE id=?")
               ->execute([round($colourPrice, 2), $baseType !== null && $baseType !== '' ? $baseType : null, $quoteId]);
            try { CommerceEventBus::emit($db, 'tint_price_outlier', ['order_id' => null, 'actor_type' => 'seller', 'actor_id' => $vendorId, 'payload' => ['quote_id' => $quoteId, 'brand' => $q['brand'], 'shade_code' => $q['shade_code'], 'per_litre' => $priced['per_litre']]]); } catch (\Throwable $e) {}
            $rr = self::reroute($db, $quoteId);
            return ['ok' => true, 'quote_id' => $quoteId, 'outlier' => true, 'rerouted' => !empty($rr['rerouted']), 'status' => self::get($db, $quoteId)['status'] ?? 'quoted'];
        }
        $db->prepare("UPDATE commerce_tint_quotes SET colour_price=?, base_type=COALESCE(?, base_type), final_price=?, margin=?, locked_per_litre=?, colour_per_litre=?, outlier_flag=0, status='priced', updated_at=NOW() WHERE id=?")
           ->execute([round($colourPrice, 2), $baseType !== null && $baseType !== '' ? $baseType : null, $priced['final_price'], $priced['margin'], $priced['per_litre'], $colourPerL, $quoteId]);
        return ['ok' => true, 'quote_id' => $quoteId, 'status' => 'priced'] + $priced;
    }

    /** Admin ceiling multiple (× the estimate) that caps the custom-tint final price. */
    public static function ceilingMult(PDO $db): float
    {
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_tint_ceiling_mult' LIMIT 1"); $s->execute(); $v = $s->fetchColumn();
              if ($v !== false && is_numeric($v) && (float)$v > 0) return (float)$v; } catch (\Throwable $e) {}
        return 2.0;
    }

    /**
     * PB10b pricing: MarginEngine on the colour price, then the admin ceiling.
     * @return array{colour_price:float, margin:float, final_price:float, per_litre:?float, ceiling:float, ceiling_applied:bool}
     */
    public static function finalize(PDO $db, array $q, float $colourPrice): array
    {
        $mp = MarginEngine::priceFromBase($db, $colourPrice);
        $customer = (float)($mp['customer_price'] ?? $colourPrice);
        $litres = (float)($q['litres'] ?? 0);
        $estimate = (float)($q['estimate'] ?? 0);
        $base = $estimate > 0 ? $estimate : $colourPrice;
        $ceiling = round($base * self::ceilingMult($db), 2);
        $capped = $customer > $ceiling;
        $final = $capped ? $ceiling : round($customer, 2);
        $margin = round(max(0.0, $final - $colourPrice), 2);
        return ['colour_price' => $colourPrice, 'margin' => $margin, 'final_price' => $final,
                'per_litre' => $litres > 0 ? round($final / $litres, 4) : null, 'ceiling' => $ceiling, 'ceiling_applied' => $capped];
    }

    /** The ONE final-price popup for the customer (after the seller priced it). */
    public static function customerFinal(PDO $db, int $quoteId, int $userId): ?array
    {
        $q = self::get($db, $quoteId);
        if (!$q || (int)$q['user_id'] !== $userId) return null;
        if ($q['final_price'] === null) return ['quote_id' => $quoteId, 'status' => $q['status'], 'ready' => false];
        $pct = CommercePayment::advancePct($db);
        $final = (float)$q['final_price'];
        $advance = round($final * $pct / 100, 2);
        return [
            'quote_id'   => $quoteId, 'ready' => true, 'status' => $q['status'],
            'brand' => $q['brand'], 'shade_code' => $q['shade_code'], 'shade_name' => $q['shade_name'],
            'litres' => (float)$q['litres'], 'final_price' => $final,
            'advance_pct' => $pct, 'advance_amount' => $advance, 'balance_at_door' => round($final - $advance, 2),
            'consent_required' => CustomTint::consentRequired($db),
            'non_returnable' => true,
            'note' => 'Custom-tinted shades are made to order and can’t be returned.',
        ];
    }

    /** Create the tint order (parent + seller sub) with the margin recorded for settlement. */
    private static function createTintOrder(PDO $db, array $q): int
    {
        $final = (float)$q['final_price']; $colour = (float)$q['colour_price'];
        $num = 'TINT-' . $q['id'] . '-' . substr((string)time(), -6);
        $db->prepare("INSERT INTO orders (order_number,user_id,city_id,status,payment_status,payment_method,subtotal,total,notes,shipping_address,delivery_status,ledger_status,created_at,updated_at)
                      VALUES (?,?,?, 'confirmed','unpaid','online',?,?, 'custom-tint (non-returnable)','{}','offered','pending',NOW(),NOW())")
           ->execute([$num, $q['user_id'], $q['city_id'], $final, $final]);
        $parent = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,seller_subtotal,total,notes,shipping_address,delivery_status,ledger_status,created_at,updated_at)
                      VALUES (?,?,?,?,?,?, 'confirmed','unpaid','online',?,?,?, 'custom-tint','{}','accepted','pending',NOW(),NOW())")
           ->execute([$num . '-S', $parent, $q['user_id'], $q['vendor_id'], $q['store_id'], $q['city_id'], $final, $colour, $final]);
        return $parent;
    }

    /**
     * Customer accepts the final price → create the order, take the advance intent,
     * record the repeat-lock, raise the confirm-call flag. Consent is mandatory.
     * @return array{ok:bool, reason?:string, order_id?:int, advance?:float, balance?:float, final_price?:float}
     */
    public static function accept(PDO $db, int $quoteId, int $userId, bool $consent): array
    {
        $q = self::get($db, $quoteId);
        if (!$q || (int)$q['user_id'] !== $userId) return ['ok' => false, 'reason' => 'not_found'];
        if ($q['status'] !== 'priced' || $q['final_price'] === null) return ['ok' => false, 'reason' => 'not_priced', 'state' => $q['status']];
        if (CustomTint::consentRequired($db) && !$consent) return ['ok' => false, 'reason' => 'consent_required'];

        $orderId = self::createTintOrder($db, $q);
        $db->prepare("UPDATE commerce_tint_quotes SET status='ordered', order_id=?, consent=?, updated_at=NOW() WHERE id=?")
           ->execute([$orderId, $consent ? 1 : 0, $quoteId]);

        // Advance intent (admin %), balance collected at the door (PB5). Margin is
        // recorded via the sub-order's seller_subtotal → settlement splits it.
        $adv = CommercePayment::recordAdvanceIntent($db, $orderId);

        // Repeat-lock log (so a future repeat of this shade goes back to this seller).
        CustomTint::recordMade($db, $userId, $q['customer_ref'] ?? null, $q['brand'], $q['shade_code'], $q['base_type'] ?? null, (int)$q['vendor_id'], $orderId);

        // Confirm-call flag (ops) for a custom order / high value.
        if (CustomTint::needsConfirmCall($db, (float)$q['final_price'], true)) {
            try { CommerceEventBus::raiseFlag($db, $orderId, 'confirm_call', 'custom-tint order needs a confirm call'); } catch (\Throwable $e) {}
        }
        try { CommerceEventBus::emit($db, 'order_created', ['order_id' => $orderId, 'actor_type' => 'customer', 'actor_id' => $userId, 'payload' => ['custom_tint' => true, 'quote_id' => $quoteId]]); } catch (\Throwable $e) {}

        return ['ok' => true, 'order_id' => $orderId, 'advance' => (float)($adv['advance'] ?? 0), 'balance' => (float)($adv['balance'] ?? 0), 'final_price' => (float)$q['final_price']];
    }

    /**
     * Making has begun (after the advance is captured) → record 'making_started'
     * so the advance becomes non-refundable (the PB16 CommerceCancellation rule).
     */
    public static function startMaking(PDO $db, int $quoteId): array
    {
        $q = self::get($db, $quoteId);
        if (!$q || !$q['order_id']) return ['ok' => false, 'reason' => 'no_order'];
        // Making may begin only once the advance is actually captured, so the
        // advance can't be made non-refundable before the customer has paid it.
        $adv = CommercePayment::advanceStatus($db, (int)$q['order_id']);
        if (!$adv || $adv['status'] !== 'captured') return ['ok' => false, 'reason' => 'advance_not_captured'];
        RoutingEngine::recordSignal($db, (int)$q['order_id'], (int)$q['order_id'], $q['store_id'] !== null ? (int)$q['store_id'] : null, $q['vendor_id'] !== null ? (int)$q['vendor_id'] : null, 'making_started');
        // The 'making_started' signal is the source of truth (PB16 reads it); the
        // quote stays 'ordered'. updated_at is bumped so dashboards reflect it.
        $db->prepare("UPDATE commerce_tint_quotes SET updated_at=NOW() WHERE id=?")->execute([$quoteId]);
        return ['ok' => true, 'order_id' => (int)$q['order_id'], 'making' => true];
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
