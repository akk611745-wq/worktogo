<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — StorefrontComposer  (S10)
//  commerce-system-blueprint §Q/§U. The customer-app "composer": the thin layer
//  that shapes what the shopper sees WITHOUT the app being seller-dependent or
//  hardcoded per category. Three jobs, all DB-config:
//    1. SELLER-HIDDEN — per-category policy (commerce_seller_visibility). When a
//       Unit hides the seller, the composer strips store/vendor identity and
//       presents "fulfilled by <platform>" so WorkToGo owns the relationship.
//    2. UNIT UI — per-category render config (commerce_unit_ui) the app reads so
//       a new Unit needs no front-end code.
//    3. RELATED — same-category, in-city, orderable products for the detail page
//       (reuses OrderabilityResolver's seller-available predicate + the engine
//       formatter passed in; never forks ProductService).
//  Pure-read + pure-compose; it never writes and never touches an engine class.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/OrderabilityResolver.php';

class StorefrontComposer
{
    private static function setting(PDO $db, string $key, $def)
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null || $v === '') ? $def : $v;
        } catch (\Throwable $e) { return $def; }
    }
    private static function json(PDO $db, string $key, array $def): array
    {
        $raw = self::setting($db, $key, null);
        if (!is_string($raw)) return $def;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : $def;
    }

    /** Brand shown when the seller is hidden (app_settings.commerce_platform_label). */
    public static function platformLabel(PDO $db): string
    {
        $v = self::setting($db, 'commerce_platform_label', 'WorkToGo');
        return is_string($v) && $v !== '' ? $v : 'WorkToGo';
    }

    /**
     * Per-category seller-identity policy → 'shown' | 'hidden'.
     * commerce_seller_visibility = {"default":"shown","hidden":[cat...],"shown":[cat...]}.
     * An explicit per-category list wins over the default (hidden list checked first).
     */
    public static function sellerVisibility(PDO $db, ?int $categoryId): string
    {
        $cfg = self::json($db, 'commerce_seller_visibility', ['default' => 'shown', 'hidden' => [], 'shown' => []]);
        $default = (($cfg['default'] ?? 'shown') === 'hidden') ? 'hidden' : 'shown';
        if ($categoryId !== null) {
            $hidden = array_map('intval', $cfg['hidden'] ?? []);
            $shown  = array_map('intval', $cfg['shown']  ?? []);
            if (in_array((int)$categoryId, $hidden, true)) return 'hidden';
            if (in_array((int)$categoryId, $shown,  true)) return 'shown';
        }
        return $default;
    }

    public static function isSellerHidden(PDO $db, ?int $categoryId): bool
    {
        return self::sellerVisibility($db, $categoryId) === 'hidden';
    }

    /**
     * Shape a single already-formatted product for the customer app. Adds
     * `seller_hidden` + `fulfilled_by`; when the Unit hides the seller, strips
     * store/vendor identity so the client cannot show it. Never removes price,
     * stock or image fields. Idempotent. $categoryId lets a caller override the
     * product's own category_id (e.g. related queries that already know it).
     */
    public static function composeProduct(PDO $db, array $p, ?int $categoryId = null): array
    {
        $cat = $categoryId ?? (isset($p['category_id']) ? (int)$p['category_id'] : null);
        $hidden = self::isSellerHidden($db, $cat);
        $label = self::platformLabel($db);
        $p['seller_hidden'] = $hidden;
        if ($hidden) {
            $p['fulfilled_by'] = $label;
            // Strip every seller-identifying field the browse/detail responses carry.
            $p['store_name']  = $label;
            $p['vendor_name'] = null;
            $p['is_company']  = null;        // the shopper sees one fulfiller: the platform
            if (isset($p['store']) && is_array($p['store'])) {
                $p['store'] = [
                    'name'       => $label,
                    'is_company' => null,
                    'locality'   => $p['store']['locality'] ?? null, // area is useful + non-identifying
                    'logo_url'   => null,
                ];
            }
        } else {
            $p['fulfilled_by'] = $p['store_name'] ?? $label;
        }
        return $p;
    }

    /** Per-Unit customer-app UI config + the labels the app needs to render founder-free. */
    public static function unitUi(PDO $db): array
    {
        $ui = self::json($db, 'commerce_unit_ui', ['default' => ['display_mode' => 'grid', 'add_label' => 'ADD', 'show_unit' => true], 'categories' => []]);
        $vis = self::json($db, 'commerce_seller_visibility', ['default' => 'shown', 'hidden' => [], 'shown' => []]);
        return [
            'ui'                       => $ui,
            'platform_label'           => self::platformLabel($db),
            'seller_visibility'        => $vis,
            'related_limit'            => (int)self::setting($db, 'commerce_related_limit', 8),
        ];
    }

    /**
     * Related products for a detail page: same category, same city, active,
     * in stock, seller-available (ON + not BUSY; company always) — the exact
     * orderable predicate the browse uses — excluding the product itself.
     * $formatter is the engine's own ProductService::formatProduct (reused, not
     * forked); each row is then seller-composed.
     */
    public static function related(PDO $db, int $productId, int $cityId, ?int $limit = null, ?callable $formatter = null): array
    {
        if (!OrderabilityResolver::commerceEnabled($db)) return [];
        $lim = $limit ?? (int)self::setting($db, 'commerce_related_limit', 8);
        $lim = max(1, min(24, (int)$lim));

        // The product's category (NULL category ⇒ no related rather than a random dump).
        $cs = $db->prepare("SELECT category_id FROM products WHERE id=? LIMIT 1");
        $cs->execute([$productId]);
        $catId = $cs->fetchColumn();
        if ($catId === false || $catId === null) return [];

        $where = [
            'p.id <> :self', 'p.category_id = :cat', "p.status='active'", 'p.deleted_at IS NULL',
            "s.status='active'", 's.deleted_at IS NULL', 's.city_id = :city',
            '((i.quantity - i.reserved) > 0 OR i.allow_backorder = 1)',
            OrderabilityResolver::sellerAvailableSql('s', 'v'),
        ];
        $sql = "SELECT p.id, p.uuid, p.name, p.short_desc, p.price, p.sale_price, p.images, p.category_id,
                       p.vendor_id, p.is_featured, p.rating, p.total_reviews, p.total_sold, p.status, p.unit,
                       (i.quantity - i.reserved) AS available_qty, i.allow_backorder,
                       p.store_id, s.name AS store_name, s.ownership, v.business_name AS vendor_name
                  FROM products p
                  JOIN inventory i ON i.product_id = p.id
                  JOIN stores s ON s.id = p.store_id
                  LEFT JOIN vendors v ON v.id = s.owner_vendor_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY (s.ownership='company') DESC, p.is_featured DESC, p.rating DESC, p.total_sold DESC
                 LIMIT $lim";
        $st = $db->prepare($sql);
        $st->bindValue(':self', $productId, PDO::PARAM_INT);
        $st->bindValue(':cat', (int)$catId, PDO::PARAM_INT);
        $st->bindValue(':city', $cityId, PDO::PARAM_INT);
        $st->execute();

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $p = $formatter ? (array)$formatter($row) : $row;
            $p['store_id']   = (int)$row['store_id'];
            $p['store_name'] = $row['store_name'];
            $p['is_company'] = ($row['ownership'] === 'company');
            if (!isset($p['category_id'])) $p['category_id'] = (int)$row['category_id'];
            $out[] = self::composeProduct($db, $p, (int)$catId);
        }
        return $out;
    }
}
