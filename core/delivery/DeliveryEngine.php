<?php
// ============================================================================
//  DeliveryEngine — WorkToGo NATIVE delivery engine (Commerce Phase 5)
// ----------------------------------------------------------------------------
//  A clean, self-contained delivery brain built by COMPOSING the motherboard's
//  native pieces — NO external SwiftDeliver dependency:
//    • deliveries + driver_wallets tables            (order → delivery, rider cash)
//    • users.role='delivery' identity + delivery_riders (live availability/geo)
//    • delivery_rate_cards                            (admin-configurable pricing)
//    • GeoHelper Haversine                            (distance, store → customer)
//    • LedgerEngine::isDriverBlocked                  (rider cash-limit guard)
//
//  Responsibilities (its OWN job — nervous-system model):
//    quote()          price a delivery by distance from the city's rate card
//    createForOrder() on order placement: create + price a delivery, auto-assign
//                     the nearest available rider, mirror the fee to orders
//    assignNearest()  lock-safe nearest-online-rider assignment (skip-locked)
//    rider*()         accept / reject / pickup / deliver transitions
//    track()          a customer/panel tracking snapshot
//
//  It does NOT edit the never-touch order engine. It only writes its own
//  `deliveries` row and records the delivery fee on orders.delivery_fee (the
//  single column the ledger reads for rider payout) + mirrors delivery status to
//  orders.status so the customer's order timeline reflects delivery.
// ============================================================================

require_once __DIR__ . '/../helpers/GeoHelper.php';
require_once __DIR__ . '/../helpers/LedgerEngine.php';

class DeliveryEngine
{
    /** Resolve the active rate card for a city (city card → default card → built-in). */
    public static function rateCard(PDO $db, ?int $cityId): array
    {
        $defaults = ['id' => null, 'base_fare' => 20.0, 'per_km_rate' => 8.0, 'min_fee' => 20.0,
                     'free_above_subtotal' => null, 'rider_share_pct' => 80.0, 'platform_margin_pct' => 20.0, 'max_distance_km' => null];
        try {
            $stmt = $db->prepare(
                "SELECT * FROM delivery_rate_cards
                  WHERE is_active = 1 AND (city_id = :c OR city_id IS NULL)
                  ORDER BY (city_id = :c2) DESC, id ASC LIMIT 1"
            );
            $stmt->execute([':c' => $cityId, ':c2' => $cityId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: $defaults;
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Price a delivery by distance (pickup → drop) from the city's rate card.
     * @return array{available:bool, distance_km:float, price:float, rider_payout:float,
     *               platform_fee:float, currency:string, rate_card_id:?int, reason?:string}
     */
    public static function quote(PDO $db, ?float $pLat, ?float $pLng, ?float $dLat, ?float $dLng, ?int $cityId, float $subtotal = 0.0): array
    {
        $card = self::rateCard($db, $cityId);
        $out = ['available' => false, 'distance_km' => 0.0, 'price' => 0.0, 'rider_payout' => 0.0,
                'platform_fee' => 0.0, 'currency' => 'INR', 'rate_card_id' => $card['id'] ? (int)$card['id'] : null];

        if ($pLat === null || $pLng === null || $dLat === null || $dLng === null) {
            // Location not known yet → give the base fare as an estimate, still available.
            $fee = round(max((float)$card['min_fee'], (float)$card['base_fare']), 2);
            return array_merge($out, ['available' => true, 'distance_km' => 0.0, 'price' => $fee,
                'rider_payout' => round($fee * (float)$card['rider_share_pct'] / 100, 2),
                'platform_fee' => round($fee * (float)$card['platform_margin_pct'] / 100, 2),
                'estimate' => true]);
        }

        $dist = round(GeoHelper::calculateDistance($pLat, $pLng, $dLat, $dLng, 'km'), 2);
        if (!empty($card['max_distance_km']) && $dist > (float)$card['max_distance_km']) {
            return array_merge($out, ['distance_km' => $dist, 'reason' => 'Outside delivery range for this store.']);
        }

        $fee = (float)$card['base_fare'] + $dist * (float)$card['per_km_rate'];
        $fee = max($fee, (float)$card['min_fee']);
        if ($card['free_above_subtotal'] !== null && $subtotal >= (float)$card['free_above_subtotal']) {
            $fee = 0.0;
        }
        $fee = round($fee, 2);
        return array_merge($out, [
            'available'    => true,
            'distance_km'  => $dist,
            'price'        => $fee,
            'rider_payout' => round($fee * (float)$card['rider_share_pct'] / 100, 2),
            'platform_fee' => round($fee * (float)$card['platform_margin_pct'] / 100, 2),
        ]);
    }

    /**
     * Server-authoritative quote: prices store → the city's SERVER-KNOWN drop
     * point (never client-sent coords), so the billed fee can't be tampered.
     * The drop point is the city centre today; a future address geocoder can
     * refine it here without changing callers (documented seam).
     */
    public static function quoteServerSide(PDO $db, ?float $storeLat, ?float $storeLng, ?int $cityId, float $subtotal = 0.0): array
    {
        [$dropLat, $dropLng] = self::cityCenter($db, $cityId);
        return self::quote($db, $storeLat, $storeLng, $dropLat, $dropLng, $cityId, $subtotal);
    }

    /** The city's server-known reference point (centre). Used for tamper-proof pricing. */
    private static function cityCenter(PDO $db, ?int $cityId): array
    {
        if (!$cityId) return [null, null];
        try {
            $s = $db->prepare("SELECT center_lat, center_lng FROM cities WHERE id = ? LIMIT 1");
            $s->execute([$cityId]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                isset($r['center_lat']) && $r['center_lat'] !== null ? (float)$r['center_lat'] : null,
                isset($r['center_lng']) && $r['center_lng'] !== null ? (float)$r['center_lng'] : null,
            ];
        } catch (\Throwable $e) { return [null, null]; }
    }

    /** Pickup store (geo + city) for an order — parent store, else first sub-order's store. */
    private static function orderPickup(PDO $db, int $orderId): ?array
    {
        $stmt = $db->prepare(
            "SELECT o.city_id,
                    COALESCE(o.store_id, (SELECT sub.store_id FROM orders sub
                        WHERE sub.parent_order_id = o.id AND sub.store_id IS NOT NULL LIMIT 1)) AS store_id
               FROM orders o WHERE o.id = ? LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $storeId = $row['store_id'] ? (int)$row['store_id'] : null;
        $lat = null; $lng = null;
        if ($storeId) {
            $s = $db->prepare("SELECT lat, lng FROM stores WHERE id = ? LIMIT 1");
            $s->execute([$storeId]);
            $srow = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            $lat = isset($srow['lat']) ? (float)$srow['lat'] : null;
            $lng = isset($srow['lng']) ? (float)$srow['lng'] : null;
        }
        return ['city_id' => $row['city_id'] ? (int)$row['city_id'] : null, 'store_id' => $storeId, 'lat' => $lat, 'lng' => $lng];
    }

    /**
     * Order placed → hand to delivery: create/price the delivery and auto-assign
     * the nearest available rider. Idempotent per order (one delivery row).
     * @return array delivery summary (id, price, status, rider_id|null, tracking_token)
     */
    public static function createForOrder(PDO $db, int $orderId, ?float $dropLat = null, ?float $dropLng = null, float $subtotal = 0.0): array
    {
        $pickup = self::orderPickup($db, $orderId);
        $cityId = $pickup['city_id'] ?? null;
        // M1 fix (audit §4): the BILLED fee is computed server-side (store → the
        // city's server-known drop point), NEVER from client-sent coords — so a
        // customer cannot shave the fee by faking a location near the store. The
        // client coords are kept only as a rider navigation hint (below).
        $q = self::quoteServerSide($db, $pickup['lat'] ?? null, $pickup['lng'] ?? null, $cityId, $subtotal);

        // Upsert one delivery per order.
        $ex = $db->prepare("SELECT id FROM deliveries WHERE order_id = ? LIMIT 1");
        $ex->execute([$orderId]);
        $deliveryId = (int)($ex->fetchColumn() ?: 0);
        $token = 'DLV' . strtoupper(bin2hex(random_bytes(6)));

        if ($deliveryId === 0) {
            // ETA estimate = prep time + travel (≈4 min/km ~ 15 km/h city riding).
            $eta = (int) round(10 + (float)($q['distance_km'] ?? 0) * 4);
            $db->prepare(
                "INSERT INTO deliveries
                    (order_id, store_id, city_id, rate_card_id, status, distance_km, price, rider_payout,
                     platform_fee, pickup_lat, pickup_lng, drop_lat, drop_lng, tracking_token, eta_minutes, created_at)
                 VALUES (:oid, :store, :city, :rc, 'pending', :dist, :price, :rp, :pf,
                     :plat, :plng, :dlat, :dlng, :tok, :eta, NOW())"
            )->execute([
                ':oid' => $orderId, ':store' => $pickup['store_id'] ?? null, ':city' => $cityId,
                ':rc' => $q['rate_card_id'], ':dist' => $q['distance_km'], ':price' => $q['price'],
                ':rp' => $q['rider_payout'], ':pf' => $q['platform_fee'],
                ':plat' => $pickup['lat'] ?? null, ':plng' => $pickup['lng'] ?? null,
                ':dlat' => $dropLat, ':dlng' => $dropLng, ':tok' => $token, ':eta' => $eta,
            ]);
            $deliveryId = (int)$db->lastInsertId();
        }

        // Record the delivery fee where the ledger reads rider payout (single column;
        // does NOT touch item totals / never-touch order logic).
        try { $db->prepare("UPDATE orders SET delivery_fee = ? WHERE id = ?")->execute([$q['price'], $orderId]); } catch (\Throwable $e) {}

        $rider = self::assignNearest($db, $deliveryId);

        return [
            'delivery_id'    => $deliveryId,
            'status'         => $rider ? 'assigned' : 'pending',
            'price'          => $q['price'],
            'distance_km'    => $q['distance_km'],
            'rider_id'       => $rider['rider_id'] ?? null,
            'tracking_token' => $token,
            'available'      => $q['available'],
        ];
    }

    /**
     * Lock-safe nearest-available-rider assignment (mirrors the auto_assign cron
     * pattern: FOR UPDATE SKIP LOCKED). Prefers the nearest online rider WITH a
     * recent location; falls back to the least-loaded online rider in the city.
     * @param int|null $excludeRiderId skip this rider (e.g. the one who just rejected)
     * @return array|null the assigned rider row, or null if none available
     */
    public static function assignNearest(PDO $db, int $deliveryId, ?int $excludeRiderId = null): ?array
    {
        try {
            $db->beginTransaction();

            $d = $db->prepare("SELECT id, city_id, pickup_lat, pickup_lng, status, driver_id FROM deliveries WHERE id = ? FOR UPDATE");
            $d->execute([$deliveryId]);
            $del = $d->fetch(PDO::FETCH_ASSOC);
            if (!$del || !in_array($del['status'], ['pending'], true)) { $db->rollBack(); return null; }

            $plat = $del['pickup_lat']; $plng = $del['pickup_lng'];
            $hasGeo = ($plat !== null && $plng !== null);

            // Candidate pool: online riders in the delivery's city, under capacity, locked.
            $sql = "SELECT r.id, r.rider_id, r.lat, r.lng, r.active_count, r.max_active
                      FROM delivery_riders r
                     WHERE r.is_online = 1
                       AND r.active_count < r.max_active
                       AND (:city IS NULL OR r.city_id = :city OR r.city_id IS NULL)"
                . ($excludeRiderId ? " AND r.rider_id <> :excl" : "");
            if ($hasGeo) {
                $sql .= " AND r.lat IS NOT NULL AND r.lng IS NOT NULL
                          ORDER BY (6371 * ACOS(LEAST(1, GREATEST(-1,
                              COS(RADIANS(:plat)) * COS(RADIANS(r.lat)) * COS(RADIANS(r.lng) - RADIANS(:plng))
                              + SIN(RADIANS(:plat)) * SIN(RADIANS(r.lat)))))) ASC, r.active_count ASC";
            } else {
                $sql .= " ORDER BY r.active_count ASC, r.last_ping_at DESC";
            }
            $sql .= " LIMIT 5 FOR UPDATE SKIP LOCKED";
            $st = $db->prepare($sql);
            $st->bindValue(':city', $del['city_id'], $del['city_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            if ($hasGeo) { $st->bindValue(':plat', $plat); $st->bindValue(':plng', $plng); }
            if ($excludeRiderId) { $st->bindValue(':excl', $excludeRiderId, PDO::PARAM_INT); }
            $st->execute();
            $candidates = $st->fetchAll(PDO::FETCH_ASSOC);

            // Pick the first candidate not blocked by the cash-in-hand limit.
            $chosen = null;
            foreach ($candidates as $c) {
                if (!\Core\Helpers\LedgerEngine::isDriverBlocked((int)$c['rider_id'])) { $chosen = $c; break; }
            }
            if (!$chosen) { $db->commit(); return null; } // no rider → stays pending for retry/manual

            $db->prepare("UPDATE deliveries SET driver_id = ?, status = 'assigned', assigned_at = NOW() WHERE id = ?")
               ->execute([(int)$chosen['rider_id'], $deliveryId]);
            $db->prepare("UPDATE delivery_riders SET active_count = active_count + 1 WHERE id = ?")->execute([(int)$chosen['id']]);

            $db->commit();
            return $chosen;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[DeliveryEngine::assignNearest] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Assign a SPECIFIC rider (admin backup/support). Lock-safe; validates the
     * rider is online, under capacity, and not cash-blocked. Delivery must be
     * pending. Returns the rider row or null.
     */
    public static function assignSpecific(PDO $db, int $deliveryId, int $riderId): ?array
    {
        try {
            $db->beginTransaction();
            $d = $db->prepare("SELECT id, status FROM deliveries WHERE id = ? FOR UPDATE");
            $d->execute([$deliveryId]);
            $del = $d->fetch(PDO::FETCH_ASSOC);
            if (!$del || $del['status'] !== 'pending') { $db->rollBack(); return null; }

            $r = $db->prepare("SELECT id, rider_id, active_count, max_active, is_online FROM delivery_riders WHERE rider_id = ? FOR UPDATE");
            $r->execute([$riderId]);
            $rider = $r->fetch(PDO::FETCH_ASSOC);
            if (!$rider || !$rider['is_online'] || $rider['active_count'] >= $rider['max_active']
                || \Core\Helpers\LedgerEngine::isDriverBlocked($riderId)) { $db->rollBack(); return null; }

            $db->prepare("UPDATE deliveries SET driver_id = ?, status = 'assigned', assigned_at = NOW(), flag = 'none' WHERE id = ?")
               ->execute([$riderId, $deliveryId]);
            $db->prepare("UPDATE delivery_riders SET active_count = active_count + 1 WHERE id = ?")->execute([(int)$rider['id']]);
            $db->commit();
            return $rider;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[DeliveryEngine::assignSpecific] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Admin reassignment / send-a-backup for an in-trouble delivery. Frees the
     * current rider, resets the delivery to pending, then assigns either a chosen
     * backup rider ($newRiderId) or the nearest DIFFERENT rider. Works for
     * pending / assigned / picked (physical handover of a picked order is manual).
     * @return array{status:string, rider_id:?int, reassigned:bool}
     */
    public static function adminReassign(PDO $db, int $deliveryId, ?int $newRiderId = null): array
    {
        $d = $db->prepare("SELECT id, status, driver_id FROM deliveries WHERE id = ? LIMIT 1");
        $d->execute([$deliveryId]);
        $del = $d->fetch(PDO::FETCH_ASSOC);
        if (!$del) throw new \RuntimeException('Delivery not found');
        if (!in_array($del['status'], ['pending', 'assigned', 'picked'], true)) {
            throw new \RuntimeException('This delivery cannot be reassigned');
        }
        $current = (int)($del['driver_id'] ?? 0);
        if ($current > 0) {
            $db->prepare("UPDATE delivery_riders SET active_count = GREATEST(0, active_count - 1) WHERE rider_id = ?")->execute([$current]);
        }
        $db->prepare("UPDATE deliveries SET status='pending', driver_id=NULL, assigned_at=NULL, accepted_at=NULL, picked_at=NULL, flag='none' WHERE id = ?")
           ->execute([$deliveryId]);

        $rider = $newRiderId
            ? self::assignSpecific($db, $deliveryId, $newRiderId)
            : self::assignNearest($db, $deliveryId, $current ?: null);
        return ['status' => $rider ? 'assigned' : 'pending', 'rider_id' => $rider['rider_id'] ?? null, 'reassigned' => (bool)$rider];
    }

    /** Mirror a delivery status onto the order so the customer timeline reflects it. */
    private static function mirrorOrder(PDO $db, int $orderId, string $deliveryStatus): void
    {
        $map = ['assigned' => 'processing', 'picked' => 'shipped', 'delivered' => 'delivered', 'cancelled' => 'cancelled'];
        if (!isset($map[$deliveryStatus])) return;
        try { $db->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$map[$deliveryStatus], $orderId]); } catch (\Throwable $e) {}
    }

    /** Load a delivery a rider owns (or throw). */
    private static function ownedDelivery(PDO $db, int $deliveryId, int $riderId): array
    {
        $st = $db->prepare("SELECT * FROM deliveries WHERE id = ? AND driver_id = ? LIMIT 1");
        $st->execute([$deliveryId, $riderId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) throw new \RuntimeException('Delivery not found or not assigned to you');
        return $d;
    }

    public static function riderAccept(PDO $db, int $deliveryId, int $riderId): array
    {
        $d = self::ownedDelivery($db, $deliveryId, $riderId);
        if ($d['status'] !== 'assigned') throw new \RuntimeException('Delivery is not in an acceptable state');
        $db->prepare("UPDATE deliveries SET accepted_at = NOW() WHERE id = ?")->execute([$deliveryId]);
        return ['status' => 'assigned', 'accepted' => true];
    }

    public static function riderReject(PDO $db, int $deliveryId, int $riderId): array
    {
        $d = self::ownedDelivery($db, $deliveryId, $riderId);
        if (!in_array($d['status'], ['assigned'], true)) throw new \RuntimeException('Cannot reject now');
        $db->prepare("UPDATE deliveries SET status='pending', driver_id=NULL, assigned_at=NULL, accepted_at=NULL, flag='none' WHERE id = ?")->execute([$deliveryId]);
        $db->prepare("UPDATE delivery_riders SET active_count = GREATEST(0, active_count - 1) WHERE rider_id = ?")->execute([$riderId]);
        $rider = self::assignNearest($db, $deliveryId, $riderId); // hand to a DIFFERENT nearest rider
        return ['status' => $rider ? 'assigned' : 'pending', 'reassigned' => (bool)$rider];
    }

    public static function riderPickup(PDO $db, int $deliveryId, int $riderId): array
    {
        $d = self::ownedDelivery($db, $deliveryId, $riderId);
        if ($d['status'] !== 'assigned') throw new \RuntimeException('Accept the delivery before pickup');
        $db->prepare("UPDATE deliveries SET status='picked', picked_at=NOW() WHERE id = ?")->execute([$deliveryId]);
        self::mirrorOrder($db, (int)$d['order_id'], 'picked');
        return ['status' => 'picked'];
    }

    public static function riderDeliver(PDO $db, int $deliveryId, int $riderId): array
    {
        $d = self::ownedDelivery($db, $deliveryId, $riderId);
        if ($d['status'] !== 'picked') throw new \RuntimeException('Pick up the order before delivering');
        $db->prepare("UPDATE deliveries SET status='delivered', delivered_at=NOW() WHERE id = ?")->execute([$deliveryId]);
        $db->prepare("UPDATE delivery_riders SET active_count = GREATEST(0, active_count - 1) WHERE rider_id = ?")->execute([$riderId]);
        self::mirrorOrder($db, (int)$d['order_id'], 'delivered');
        // Delivered = the authoritative completion → book the order money (vendor
        // earning + platform commission + rider payout + COD cash), idempotent and
        // collision-safe (reference_type='order'). Non-fatal: a settlement hiccup
        // must not un-deliver the order; the lifecycle cron retries unsettled ones.
        try { \Core\Helpers\LedgerEngine::processCommerceOrderSettlement((int)$d['order_id'], $db); }
        catch (\Throwable $e) { error_log('[DeliveryEngine::riderDeliver] settlement: ' . $e->getMessage()); }
        return ['status' => 'delivered'];
    }

    /** Ensure a delivery_riders row exists for a rider (first login). */
    public static function ensureRider(PDO $db, int $riderId, ?int $cityId = null): void
    {
        $db->prepare("INSERT IGNORE INTO delivery_riders (rider_id, city_id) VALUES (?, ?)")->execute([$riderId, $cityId]);
    }

    public static function riderSetOnline(PDO $db, int $riderId, bool $online): void
    {
        self::ensureRider($db, $riderId);
        $db->prepare("UPDATE delivery_riders SET is_online = ?, updated_at = NOW() WHERE rider_id = ?")->execute([$online ? 1 : 0, $riderId]);
    }

    public static function riderPing(PDO $db, int $riderId, float $lat, float $lng): void
    {
        self::ensureRider($db, $riderId);
        $db->prepare("UPDATE delivery_riders SET lat = ?, lng = ?, last_ping_at = NOW() WHERE rider_id = ?")->execute([$lat, $lng, $riderId]);
    }

    /** Customer/panel tracking snapshot for an order. */
    public static function track(PDO $db, int $orderId): ?array
    {
        $st = $db->prepare(
            "SELECT d.id, d.status, d.price, d.distance_km, d.eta_minutes, d.tracking_token,
                    d.assigned_at, d.picked_at, d.delivered_at,
                    u.name AS rider_name, u.phone AS rider_phone,
                    r.lat AS rider_lat, r.lng AS rider_lng
               FROM deliveries d
               LEFT JOIN users u ON u.id = d.driver_id
               LEFT JOIN delivery_riders r ON r.rider_id = d.driver_id
              WHERE d.order_id = ? ORDER BY d.id DESC LIMIT 1"
        );
        $st->execute([$orderId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) return null;
        return [
            'status'         => $d['status'],
            'price'          => $d['price'] !== null ? (float)$d['price'] : null,
            'distance_km'    => $d['distance_km'] !== null ? (float)$d['distance_km'] : null,
            'eta_minutes'    => $d['eta_minutes'] !== null ? (int)$d['eta_minutes'] : null,
            'rider'          => $d['rider_name'] ? ['name' => $d['rider_name'], 'phone' => $d['rider_phone'],
                                'lat' => $d['rider_lat'] !== null ? (float)$d['rider_lat'] : null,
                                'lng' => $d['rider_lng'] !== null ? (float)$d['rider_lng'] : null] : null,
            'assigned_at'    => $d['assigned_at'], 'picked_at' => $d['picked_at'], 'delivered_at' => $d['delivered_at'],
            'tracking_token' => $d['tracking_token'],
        ];
    }
}
