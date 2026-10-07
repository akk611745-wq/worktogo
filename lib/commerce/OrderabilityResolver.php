<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — OrderabilityResolver  (S2)
//  commerce-system-blueprint §C. The single source of truth for whether a product
//  can be ordered RIGHT NOW. Four layers, in order:
//     0. Kill-switch   — global commerce_enabled (admin control room; configurable)
//     1. Catalogue     — product exists, active, not deleted
//     2. Seller/store  — store active + in the customer's city + seller ON/BUSY gate
//                        (ON=is_online & not busy; company stores always pass)
//     3. Stock         — (quantity - reserved) > 0, or backorder allowed
//     4. Unit rule     — hook for unit_config (substitution/min-order/risk); passes
//                        until the unit-config layer is built (blueprint §M)
//
//  check() is used for single-product actions (cart-add, checkout, detail).
//  sellerAvailableSql() is the SAME seller gate as a SQL fragment, so the browse
//  list query stays in sync without per-row PHP. No dependency on the never-touch
//  shopping engine. All thresholds/switches are configurable (DB), never hardcoded.
// ─────────────────────────────────────────────────────────────────────────────

class OrderabilityResolver
{
    /** Global kill-switch (app_settings.commerce_enabled). Absent/unreadable ⇒ enabled. */
    public static function commerceEnabled(PDO $db): bool
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'commerce_enabled' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            if ($v === false) return true; // not configured yet
            return !in_array((string)$v, ['0', 'false', 'no', ''], true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** Admin kill-switch sets (app_settings.commerce_paused). Lightweight; decoupled from AdminControlRoom. */
    public static function pausedScopes(PDO $db): array
    {
        $out = ['categories' => [], 'cities' => [], 'stores' => []];
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_paused' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            $j = $v ? json_decode($v, true) : null;
            if (is_array($j)) foreach (['categories', 'cities', 'stores'] as $k) if (!empty($j[$k])) $out[$k] = array_map('intval', $j[$k]);
        } catch (\Throwable $e) {}
        return $out;
    }

    /**
     * Seller-availability gate as a SQL predicate (for the browse list query).
     * $s = stores alias, $v = vendors alias (joined on the store's owner vendor,
     * or product.vendor_id which equals it for vendor stores). Company stores pass.
     */
    public static function sellerAvailableSql(string $s = 's', string $v = 'v'): string
    {
        return "({$s}.ownership = 'company' OR "
             . "({$v}.is_online = 1 AND ({$v}.busy_until IS NULL OR {$v}.busy_until <= NOW())))";
    }

    /**
     * Per-product 4-layer check.
     * @return array{orderable:bool, reason:string, layer:int}
     */
    public static function check(PDO $db, int $productId, ?int $cityId): array
    {
        // Layer 0 — global kill-switch
        if (!self::commerceEnabled($db)) {
            return ['orderable' => false, 'reason' => 'commerce_paused', 'layer' => 0];
        }

        $sql = "SELECT p.id, p.category_id AS p_cat,
                       p.status  AS p_status, p.deleted_at AS p_deleted,
                       s.id      AS store_id, s.status AS s_status, s.deleted_at AS s_deleted,
                       s.city_id AS s_city,   s.ownership,
                       v.is_online,
                       (v.busy_until IS NOT NULL AND v.busy_until > NOW()) AS v_busy,
                       i.quantity, i.reserved, i.allow_backorder
                  FROM products p
                  LEFT JOIN stores    s ON s.id = p.store_id
                  LEFT JOIN vendors   v ON v.id = s.owner_vendor_id
                  LEFT JOIN inventory i ON i.product_id = p.id
                 WHERE p.id = ? LIMIT 1";
        $st = $db->prepare($sql);
        $st->execute([$productId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        // Layer 1 — catalogue
        if (!$r)                                                return ['orderable' => false, 'reason' => 'not_found',         'layer' => 1];
        if ($r['p_status'] !== 'active' || $r['p_deleted'] !== null)
                                                                return ['orderable' => false, 'reason' => 'inactive_product', 'layer' => 1];

        // Layer 2 — seller / store active + city + ON/OFF/BUSY
        if (empty($r['store_id']))                              return ['orderable' => false, 'reason' => 'no_store',         'layer' => 2];
        // Admin kill-switches (unit / zone / seller) — checked BEFORE store status so a
        // kill-switch reports as such (AdminControlRoom::pause writes these).
        $paused = self::pausedScopes($db);
        if ($r['p_cat'] !== null && in_array((int)$r['p_cat'], $paused['categories'], true))
                                                                return ['orderable' => false, 'reason' => 'unit_paused',    'layer' => 2];
        if ($r['s_city'] !== null && in_array((int)$r['s_city'], $paused['cities'], true))
                                                                return ['orderable' => false, 'reason' => 'zone_paused',    'layer' => 2];
        if (in_array((int)$r['store_id'], $paused['stores'], true))
                                                                return ['orderable' => false, 'reason' => 'seller_paused',  'layer' => 2];
        if ($r['s_status'] !== 'active' || $r['s_deleted'] !== null)
                                                                return ['orderable' => false, 'reason' => 'store_inactive',  'layer' => 2];
        if ($r['s_city'] !== null && $cityId !== null && (int)$r['s_city'] !== (int)$cityId)
                                                                return ['orderable' => false, 'reason' => 'out_of_area',     'layer' => 2];
        if ($r['ownership'] !== 'company') {
            if ((int)$r['is_online'] !== 1)                     return ['orderable' => false, 'reason' => 'seller_off',      'layer' => 2];
            if ((int)$r['v_busy'] === 1)                        return ['orderable' => false, 'reason' => 'seller_busy',     'layer' => 2];
        }

        // Layer 3 — stock
        $available = (int)$r['quantity'] - (int)$r['reserved'];
        if ($available <= 0 && (int)$r['allow_backorder'] !== 1)
                                                                return ['orderable' => false, 'reason' => 'out_of_stock',    'layer' => 3];

        // Layer 4 — unit rule (unit_config hook; passes until §M is built)
        return ['orderable' => true, 'reason' => 'ok', 'layer' => 4];
    }

    /** Friendly, seller-hidden customer message for a non-orderable reason. */
    public static function message(string $reason): string
    {
        switch ($reason) {
            case 'commerce_paused': return 'Ordering is temporarily paused. Please try again shortly.';
            case 'out_of_area':     return 'This item isn’t available in your area.';
            case 'out_of_stock':    return 'This item is out of stock.';
            case 'seller_off':
            case 'seller_busy':
            case 'store_inactive':  return 'This item isn’t available right now.';
            default:                return 'This item isn’t available.';
        }
    }
}
