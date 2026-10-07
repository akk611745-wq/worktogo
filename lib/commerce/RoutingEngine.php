<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — RoutingEngine  (S3)
//  commerce-system-blueprint §D. DETERMINISTIC supply routing (rule-based, no AI):
//     eligible sellers (ON + in-range + stock + in-city)
//        → rank: company-first → nearest → priority
//        → single-shop basket (whole basket from one store) else soft-split
//        → offer → accept / (timeout|reject) → reroute to next → company fallback.
//  Every routing event is logged to commerce_routing_signals = the Brain's future
//  fuel (ranking stays off until real data exists). All values are DB-config
//  (offer timeout default 120s, retries, radius) — nothing hardcoded. The Brain
//  will later swap in behind this same interface with no core rewrite.
//
//  Reuses OrderabilityResolver's seller gate. No dependency on the never-touch
//  shopping engine.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/OrderabilityResolver.php';
require_once __DIR__ . '/CommerceEventBus.php';

class RoutingEngine
{
    /** Admin-tunable routing config (app_settings) with safe defaults. */
    public static function config(PDO $db): array
    {
        $def = ['offer_timeout_sec' => 120, 'max_retries' => 3, 'default_radius_km' => 8.0];
        $map = [
            'commerce_offer_timeout_sec' => 'offer_timeout_sec',
            'commerce_offer_max_retries' => 'max_retries',
            'commerce_default_radius_km' => 'default_radius_km',
        ];
        try {
            $in = "'" . implode("','", array_keys($map)) . "'";
            $rows = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ($in)")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($map as $key => $prop) {
                if (isset($rows[$key]) && $rows[$key] !== '') {
                    $def[$prop] = $prop === 'default_radius_km' ? (float)$rows[$key] : (int)$rows[$key];
                }
            }
        } catch (\Throwable $e) { /* defaults */ }
        return $def;
    }

    /** Great-circle distance in km, or null when either point is unknown. */
    public static function distanceKm(?float $aLat, ?float $aLng, ?float $bLat, ?float $bLng): ?float
    {
        if ($aLat === null || $aLng === null || $bLat === null || $bLng === null) return null;
        $R = 6371.0;
        $dLat = deg2rad($bLat - $aLat); $dLng = deg2rad($bLng - $aLng);
        $x = sin($dLat / 2) ** 2 + cos(deg2rad($aLat)) * cos(deg2rad($bLat)) * sin($dLng / 2) ** 2;
        return round($R * 2 * atan2(sqrt($x), sqrt(1 - $x)), 3);
    }

    /** Deterministic order: company-first → nearest (nulls last) → priority desc → id asc. */
    private static function rank(array $a, array $b): int
    {
        $ac = $a['ownership'] === 'company' ? 0 : 1;
        $bc = $b['ownership'] === 'company' ? 0 : 1;
        if ($ac !== $bc) return $ac <=> $bc;
        $ad = $a['distance_km'] ?? INF; $bd = $b['distance_km'] ?? INF;
        if ($ad != $bd) return $ad <=> $bd;
        if ((int)$a['priority'] !== (int)$b['priority']) return (int)$b['priority'] <=> (int)$a['priority'];
        return (int)$a['store_id'] <=> (int)$b['store_id'];
    }

    /**
     * Eligible stores that can supply $sku (qty) to a customer in $cityId near
     * ($lat,$lng), ranked. Respects the S2 seller gate + serviceable range.
     */
    public static function eligibleStores(PDO $db, string $matchKey, int $qty, ?int $cityId, ?float $lat, ?float $lng, ?int $excludeStoreId = null): array
    {
        if (!OrderabilityResolver::commerceEnabled($db) || $matchKey === '') return [];
        $cfg = self::config($db);
        // Match equivalent offers across stores by match_key (sku is unique per offer).
        $sql = "SELECT s.id AS store_id, s.owner_vendor_id AS vendor_id, s.ownership, s.priority,
                       s.lat, s.lng, s.service_radius_km, p.id AS product_id,
                       (i.quantity - i.reserved) AS avail, i.allow_backorder
                  FROM products p
                  JOIN stores s    ON s.id = p.store_id
                  LEFT JOIN vendors v ON v.id = s.owner_vendor_id
                  JOIN inventory i ON i.product_id = p.id
                 WHERE p.match_key = :key AND p.status='active' AND p.deleted_at IS NULL
                   AND s.status='active' AND s.deleted_at IS NULL
                   AND (s.city_id IS NULL OR s.city_id = :city)
                   AND ((i.quantity - i.reserved) >= :qty OR i.allow_backorder = 1)
                   AND " . OrderabilityResolver::sellerAvailableSql('s', 'v');
        $st = $db->prepare($sql);
        $st->bindValue(':key', $matchKey);
        $st->bindValue(':city', $cityId, $cityId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':qty', $qty, PDO::PARAM_INT);
        $st->execute();

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($excludeStoreId !== null && (int)$r['store_id'] === (int)$excludeStoreId) continue;
            $dist = self::distanceKm($lat, $lng, $r['lat'] !== null ? (float)$r['lat'] : null, $r['lng'] !== null ? (float)$r['lng'] : null);
            $radius = $r['service_radius_km'] !== null ? (float)$r['service_radius_km'] : (float)$cfg['default_radius_km'];
            if ($dist !== null && $dist > $radius) continue; // out of serviceable range
            $out[] = [
                'store_id'    => (int)$r['store_id'],
                'vendor_id'   => $r['vendor_id'] !== null ? (int)$r['vendor_id'] : null,
                'product_id'  => (int)$r['product_id'],
                'ownership'   => $r['ownership'],
                'priority'    => (int)$r['priority'],
                'distance_km' => $dist,
                'avail'       => (int)$r['avail'],
            ];
        }
        usort($out, [self::class, 'rank']);
        return $out;
    }

    /**
     * Plan a basket: prefer the WHOLE basket from ONE store (single pickup); else
     * soft-split each item to its best eligible store; items with no store are
     * unfulfilled.
     * @param array $items  [['sku'=>..,'qty'=>..], ...]
     * @return array{mode:string, assignments:array<int,array>, unfulfilled:array}
     */
    public static function planBasket(PDO $db, array $items, ?int $cityId, ?float $lat, ?float $lng): array
    {
        $perItem = []; $meta = [];
        foreach ($items as $it) {
            $list = self::eligibleStores($db, (string)$it['match_key'], (int)$it['qty'], $cityId, $lat, $lng);
            $perItem[(string)$it['match_key']] = $list;
            foreach ($list as $s) $meta[$s['store_id']] = $s;
        }
        $skus = array_keys($perItem);

        // Single-shop: a store eligible for EVERY sku.
        $full = [];
        foreach ($meta as $sid => $s) {
            $covers = true;
            foreach ($skus as $sku) {
                $has = false;
                foreach ($perItem[$sku] as $cand) if ($cand['store_id'] === $sid) { $has = true; break; }
                if (!$has) { $covers = false; break; }
            }
            if ($covers) $full[] = $s;
        }
        if (!empty($full) && !empty($skus)) {
            usort($full, [self::class, 'rank']);
            $sid = $full[0]['store_id'];
            return ['mode' => 'single', 'assignments' => [$sid => $items], 'unfulfilled' => []];
        }

        // Soft-split: each item → its best store.
        $assignments = []; $unfulfilled = [];
        foreach ($items as $it) {
            $list = $perItem[(string)$it['match_key']];
            if (empty($list)) { $unfulfilled[] = $it; continue; }
            $sid = $list[0]['store_id'];
            $assignments[$sid][] = $it;
        }
        $mode = empty($unfulfilled) ? 'split' : (empty($assignments) ? 'none' : 'partial');
        return ['mode' => $mode, 'assignments' => $assignments, 'unfulfilled' => $unfulfilled];
    }

    /** Append a routing signal (Brain fuel). */
    public static function recordSignal(PDO $db, int $orderId, ?int $subOrderId, ?int $storeId, ?int $vendorId, string $event, ?int $responseSec = null, ?array $meta = null): void
    {
        $db->prepare("INSERT INTO commerce_routing_signals (order_id, sub_order_id, store_id, vendor_id, event, response_sec, meta, created_at)
                      VALUES (?,?,?,?,?,?,?,NOW())")
           ->execute([$orderId, $subOrderId, $storeId, $vendorId, $event, $responseSec, $meta !== null ? json_encode($meta) : null]);
    }

    /** Entry point from checkout: mark each sub-order OFFERED + log the offer. */
    public static function onOrderPlaced(PDO $db, int $parentOrderId, ?float $lat = null, ?float $lng = null): int
    {
        $subs = $db->prepare("SELECT id, store_id, vendor_id FROM orders WHERE parent_order_id = ?");
        $subs->execute([$parentOrderId]);
        $n = 0;
        foreach ($subs->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $db->prepare("UPDATE orders SET delivery_status='offered', updated_at=NOW() WHERE id=?")->execute([(int)$s['id']]);
            self::recordSignal($db, $parentOrderId, (int)$s['id'], $s['store_id'] !== null ? (int)$s['store_id'] : null, $s['vendor_id'] !== null ? (int)$s['vendor_id'] : null, 'offered');
            CommerceEventBus::emit($db, 'order_offered', ['order_id' => $parentOrderId, 'sub_order_id' => (int)$s['id'], 'to_state' => 'offered']);
            $n++;
        }
        return $n;
    }

    /**
     * Reroute a failed/timed-out sub-order to the next eligible store that can
     * supply ALL its items (company fallback last). Returns the outcome.
     */
    public static function rerouteOnFailure(PDO $db, int $subOrderId): array
    {
        $so = $db->prepare("SELECT id, parent_order_id, store_id, city_id FROM orders WHERE id=? LIMIT 1");
        $so->execute([$subOrderId]);
        $sub = $so->fetch(PDO::FETCH_ASSOC);
        if (!$sub) return ['rerouted' => false, 'reason' => 'sub_not_found'];

        $its = $db->prepare("SELECT p.match_key AS mk, oi.quantity AS qty FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id=?");
        $its->execute([$subOrderId]);
        $items = $its->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items)) return ['rerouted' => false, 'reason' => 'no_items'];

        $cityId = $sub['city_id'] !== null ? (int)$sub['city_id'] : null;
        // candidate stores = those eligible for the FIRST item, excluding the current store,
        // then confirmed to cover every item (matched across stores by match_key).
        $cands = self::eligibleStores($db, (string)$items[0]['mk'], (int)$items[0]['qty'], $cityId, null, null, (int)$sub['store_id']);
        foreach ($cands as $cand) {
            $coversAll = true;
            foreach ($items as $it) {
                $ok = false;
                foreach (self::eligibleStores($db, (string)$it['mk'], (int)$it['qty'], $cityId, null, null, (int)$sub['store_id']) as $e) {
                    if ($e['store_id'] === $cand['store_id']) { $ok = true; break; }
                }
                if (!$ok) { $coversAll = false; break; }
            }
            if (!$coversAll) continue;
            $db->prepare("UPDATE orders SET store_id=?, vendor_id=?, delivery_status='offered', updated_at=NOW() WHERE id=?")
               ->execute([$cand['store_id'], $cand['vendor_id'], $subOrderId]);
            self::recordSignal($db, (int)$sub['parent_order_id'], $subOrderId, $cand['store_id'], $cand['vendor_id'], 'rerouted');
            CommerceEventBus::emit($db, 'order_rerouted', ['order_id' => (int)$sub['parent_order_id'], 'sub_order_id' => $subOrderId, 'to_state' => 'offered', 'payload' => ['store_id' => $cand['store_id']]]);
            return ['rerouted' => true, 'store_id' => $cand['store_id'], 'reason' => 'ok'];
        }
        $db->prepare("UPDATE orders SET delivery_status='needs_ops', updated_at=NOW() WHERE id=?")->execute([$subOrderId]);
        self::recordSignal($db, (int)$sub['parent_order_id'], $subOrderId, $sub['store_id'] !== null ? (int)$sub['store_id'] : null, null, 'reroute_failed');
        CommerceEventBus::emit($db, 'reroute_failed', ['order_id' => (int)$sub['parent_order_id'], 'sub_order_id' => $subOrderId, 'to_state' => 'needs_ops', 'reason' => 'no alternative store']);
        return ['rerouted' => false, 'reason' => 'no_alternative'];
    }

    /**
     * Cron entry: any sub-order OFFERED longer than the configured timeout →
     * log 'timeout' + reroute. Time comparison runs in SQL (NOW()) — TZ-safe.
     * @return int number of stale offers processed
     */
    public static function expireStaleOffers(PDO $db): int
    {
        $timeout = (int)self::config($db)['offer_timeout_sec'];
        $q = $db->prepare("
            SELECT o.id, o.parent_order_id, o.store_id, o.vendor_id
              FROM orders o
             WHERE o.parent_order_id IS NOT NULL
               AND o.status = 'pending'
               AND o.delivery_status = 'offered'
               AND (SELECT MAX(crs.created_at) FROM commerce_routing_signals crs
                     WHERE crs.sub_order_id = o.id AND crs.event = 'offered')
                   < (NOW() - INTERVAL ? SECOND)
        ");
        $q->execute([$timeout]);
        $stale = $q->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        foreach ($stale as $s) {
            self::recordSignal($db, (int)$s['parent_order_id'], (int)$s['id'], $s['store_id'] !== null ? (int)$s['store_id'] : null, $s['vendor_id'] !== null ? (int)$s['vendor_id'] : null, 'timeout');
            CommerceEventBus::emit($db, 'order_timeout', ['order_id' => (int)$s['parent_order_id'], 'sub_order_id' => (int)$s['id'], 'reason' => 'seller did not accept in time']);
            self::rerouteOnFailure($db, (int)$s['id']);
            $n++;
        }
        return $n;
    }
}
