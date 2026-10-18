<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — Serviceability  (Paint PB14)
//  commerce-system-blueprint — area/pincode precision on top of the S2 city +
//  radius gate. "Can we deliver to THIS address?" answered server-side so a
//  customer only sees/orders products a seller delivers to, caught at browse AND
//  at checkout (never at dispatch).
//
//  Two scopes in commerce_serviceability:
//    city   → a pincode the PLATFORM serves in a city.
//    seller → a pincode a store delivers to (+ optional radius_km override).
//  Fallbacks keep a single-city launch working with zero config:
//    • city with NO 'city' pincode rows  → the whole city is served.
//    • seller with NO 'seller' pincode rows → the existing store radius gate
//      (RoutingEngine/eligibleStores) still decides; pincode adds precision only
//      where an admin has entered it.
//  No pincode supplied → ok (we fall back to the city-level gate elsewhere);
//  precision applies only once the customer gives a pincode.
//
//  Additive; consulted by OrderabilityResolver::check() and the shop adapter.
//  No engine edit.
// ─────────────────────────────────────────────────────────────────────────────

class Serviceability
{
    /** Normalize to a 6-digit Indian pincode, or '' when absent/invalid. */
    public static function normalizePincode($raw): string
    {
        $p = preg_replace('/\D+/', '', (string)$raw);
        return (strlen((string)$p) === 6) ? (string)$p : '';
    }

    /** Pull a pincode out of a posted shipping-address array under common keys. */
    public static function pincodeFromAddress($addr): string
    {
        if (!is_array($addr)) return '';
        foreach (['pincode', 'postal_code', 'postcode', 'zip', 'zipcode', 'pin'] as $k) {
            if (!empty($addr[$k])) { $n = self::normalizePincode($addr[$k]); if ($n !== '') return $n; }
        }
        return '';
    }

    /** Does the PLATFORM serve this pincode in the city? (No city rows ⇒ whole city served.) */
    public static function cityServes(PDO $db, ?int $cityId, string $pincode): bool
    {
        if ($pincode === '') return true;
        try {
            $c = $db->prepare("SELECT COUNT(*) FROM commerce_serviceability WHERE scope='city' AND active=1 AND pincode IS NOT NULL AND (city_id = ? OR city_id IS NULL)");
            $c->execute([$cityId]);
            if ((int)$c->fetchColumn() === 0) return true; // no pincode list for this city → served whole
            $m = $db->prepare("SELECT COUNT(*) FROM commerce_serviceability WHERE scope='city' AND active=1 AND pincode = ? AND (city_id = ? OR city_id IS NULL)");
            $m->execute([$pincode, $cityId]);
            return (int)$m->fetchColumn() > 0;
        } catch (\Throwable $e) { return true; } // table missing / error → don't block
    }

    /** Does THIS store deliver to the pincode? (No seller pincode rows ⇒ radius fallback ⇒ true here.) */
    public static function sellerServes(PDO $db, int $storeId, string $pincode): bool
    {
        if ($pincode === '' || $storeId <= 0) return true;
        try {
            $c = $db->prepare("SELECT COUNT(*) FROM commerce_serviceability WHERE scope='seller' AND active=1 AND pincode IS NOT NULL AND store_id = ?");
            $c->execute([$storeId]);
            if ((int)$c->fetchColumn() === 0) return true; // no explicit list → leave it to the radius gate
            $m = $db->prepare("SELECT COUNT(*) FROM commerce_serviceability WHERE scope='seller' AND active=1 AND pincode = ? AND store_id = ?");
            $m->execute([$pincode, $storeId]);
            return (int)$m->fetchColumn() > 0;
        } catch (\Throwable $e) { return true; }
    }

    /**
     * Full gate for one store + city + pincode.
     * @return array{ok:bool, reason:string}  reason ∈ ok|no_pincode|area_not_served|seller_not_in_area
     */
    public static function check(PDO $db, ?int $storeId, ?int $cityId, ?string $pincode): array
    {
        $pin = self::normalizePincode($pincode);
        if ($pin === '') return ['ok' => true, 'reason' => 'no_pincode'];
        if (!self::cityServes($db, $cityId, $pin)) return ['ok' => false, 'reason' => 'area_not_served'];
        if ($storeId !== null && !self::sellerServes($db, (int)$storeId, $pin)) return ['ok' => false, 'reason' => 'seller_not_in_area'];
        return ['ok' => true, 'reason' => 'ok'];
    }

    /**
     * Filter a candidate store-id list down to the ones serviceable for a pincode.
     * Returns the list unchanged when no pincode is given. Used by the browse list.
     */
    public static function filterStoreIds(PDO $db, array $storeIds, ?int $cityId, ?string $pincode): array
    {
        $pin = self::normalizePincode($pincode);
        if ($pin === '' || !$storeIds) return $storeIds;
        if (!self::cityServes($db, $cityId, $pin)) return []; // platform doesn't serve this pincode at all
        $ids = array_values(array_unique(array_map('intval', $storeIds)));
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            // stores that DO have an explicit seller pincode list
            $h = $db->prepare("SELECT DISTINCT store_id FROM commerce_serviceability WHERE scope='seller' AND active=1 AND pincode IS NOT NULL AND store_id IN ($in)");
            $h->execute($ids);
            $haveList = array_map('intval', $h->fetchAll(PDO::FETCH_COLUMN));
            if (!$haveList) return $ids; // none constrained by pincode → radius fallback keeps all
            // of those, the ones that serve this pincode
            $in2 = implode(',', array_fill(0, count($haveList), '?'));
            $m = $db->prepare("SELECT DISTINCT store_id FROM commerce_serviceability WHERE scope='seller' AND active=1 AND pincode = ? AND store_id IN ($in2)");
            $m->execute(array_merge([$pin], $haveList));
            $match = array_map('intval', $m->fetchAll(PDO::FETCH_COLUMN));
            $haveSet = array_flip($haveList); $matchSet = array_flip($match);
            // keep: stores without a list, plus listed stores that match the pincode
            return array_values(array_filter($ids, fn($id) => !isset($haveSet[$id]) || isset($matchSet[$id])));
        } catch (\Throwable $e) { return $ids; }
    }

    /** Customer-facing message for a non-serviceable reason (seller-hidden). */
    public static function message(string $reason): string
    {
        switch ($reason) {
            case 'area_not_served':    return 'We don’t deliver to your area yet.';
            case 'seller_not_in_area': return 'This seller doesn’t deliver to your pincode.';
            default:                   return 'We don’t deliver here yet.';
        }
    }

    // ── Admin config (CRUD) ─────────────────────────────────────────────────

    /** List configured areas for a city: platform pincodes + each seller's area. */
    public static function listAreas(PDO $db, ?int $cityId = null): array
    {
        $city = []; $seller = [];
        try {
            if ($cityId !== null) {
                $c = $db->prepare("SELECT id, pincode, active, created_at FROM commerce_serviceability WHERE scope='city' AND (city_id = ? OR city_id IS NULL) ORDER BY pincode");
                $c->execute([$cityId]);
            } else {
                $c = $db->query("SELECT id, city_id, pincode, active, created_at FROM commerce_serviceability WHERE scope='city' ORDER BY pincode");
            }
            $city = $c->fetchAll(PDO::FETCH_ASSOC);
            $sqlS = "SELECT sv.id, sv.store_id, sv.pincode, sv.radius_km, sv.active, sv.created_at, s.name AS store_name
                       FROM commerce_serviceability sv LEFT JOIN stores s ON s.id = sv.store_id
                      WHERE sv.scope='seller'" . ($cityId !== null ? " AND (s.city_id = ? OR s.city_id IS NULL)" : "") . " ORDER BY sv.store_id, sv.pincode";
            $st = $db->prepare($sqlS);
            $st->execute($cityId !== null ? [$cityId] : []);
            $seller = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { /* empty */ }
        return ['city' => $city, 'seller' => $seller];
    }

    /** Add a serviceable area row. $scope='city'|'seller'. Returns the new id. */
    public static function addArea(PDO $db, string $scope, ?int $cityId, ?int $storeId, ?string $pincode, ?float $radiusKm = null): array
    {
        $scope = $scope === 'seller' ? 'seller' : 'city';
        $pin = $pincode !== null ? self::normalizePincode($pincode) : '';
        if ($scope === 'city') {
            if ($pin === '') return ['ok' => false, 'reason' => 'bad_pincode'];
            if ($cityId === null) return ['ok' => false, 'reason' => 'city_required'];
        } else {
            if ($storeId === null || $storeId <= 0) return ['ok' => false, 'reason' => 'store_required'];
            if ($pin === '' && $radiusKm === null) return ['ok' => false, 'reason' => 'pincode_or_radius_required'];
        }
        $db->prepare("INSERT INTO commerce_serviceability (scope, city_id, store_id, pincode, radius_km, active, created_at) VALUES (?,?,?,?,?,1,NOW())")
           ->execute([$scope, $cityId, $storeId, $pin !== '' ? $pin : null, $radiusKm]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId()];
    }

    /** Deactivate (soft-remove) an area row. */
    public static function removeArea(PDO $db, int $id): array
    {
        $db->prepare("UPDATE commerce_serviceability SET active=0 WHERE id=?")->execute([$id]);
        return ['ok' => true, 'id' => $id];
    }

    // ── "Notify me" demand list ─────────────────────────────────────────────

    /** Save a customer's expand-here request (pincode + phone). */
    public static function saveServiceRequest(PDO $db, string $pincode, string $phone, ?int $cityId = null): array
    {
        $pin = self::normalizePincode($pincode);
        $ph  = preg_replace('/[^\d+]/', '', (string)$phone);
        if ($pin === '') return ['ok' => false, 'reason' => 'bad_pincode'];
        if (strlen($ph) < 7) return ['ok' => false, 'reason' => 'bad_phone'];
        $db->prepare("INSERT INTO commerce_service_requests (pincode, phone, city_id, created_at) VALUES (?,?,?,NOW())")
           ->execute([$pin, $ph, $cityId]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId()];
    }

    /** Admin demand list: pincodes people asked for, most-wanted first. */
    public static function demandList(PDO $db, int $limit = 200): array
    {
        try {
            $s = $db->prepare("SELECT pincode, COUNT(*) AS requests, COUNT(DISTINCT phone) AS people, MAX(created_at) AS last_at
                                 FROM commerce_service_requests GROUP BY pincode ORDER BY requests DESC, last_at DESC LIMIT ?");
            $s->bindValue(1, $limit, PDO::PARAM_INT);
            $s->execute();
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return []; }
    }
}
