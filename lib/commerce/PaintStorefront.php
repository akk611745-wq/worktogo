<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — PaintStorefront  (Paint PB4)
//  The CUSTOMER read-model for the paint shop: the per-city ON/OFF module switch,
//  the paint home (7 families + brands + search, grouped one card per parent
//  product), and the product page (variant chips + pack selector with ONE
//  server-computed price per pack). Seller identity is hidden (paint unit rule) —
//  the customer sees WorkToGo. Honours PB14 pincode serviceability and the S2
//  seller-available / city / stock gates. Customer price is products.price (the
//  margin is already baked in at approval); seller base / margin are NEVER exposed.
//
//  Read-only + additive. Reuses PaintCatalogue (families/brands), Serviceability,
//  OrderabilityResolver::sellerAvailableSql. No engine edit; the cart/checkout
//  reuse the existing /api/shop endpoints (a pack SKU is an ordinary product row).
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/PaintCatalogue.php';
require_once __DIR__ . '/Serviceability.php';
require_once __DIR__ . '/OrderabilityResolver.php';

class PaintStorefront
{
    /** Is the paint shop switched ON for this city? Global flag OR per-city allow-list. */
    public static function shopEnabled(PDO $db, ?int $cityId): bool
    {
        try {
            $rows = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('commerce_shop_enabled','commerce_shop_enabled_cities')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $global = isset($rows['commerce_shop_enabled']) && in_array((string)$rows['commerce_shop_enabled'], ['1', 'true', 'yes', 'on'], true);
            if ($global) return true;
            if ($cityId !== null && !empty($rows['commerce_shop_enabled_cities'])) {
                $list = json_decode((string)$rows['commerce_shop_enabled_cities'], true);
                if (is_array($list) && in_array((int)$cityId, array_map('intval', $list), true)) return true;
            }
        } catch (\Throwable $e) { /* default off */ }
        return false;
    }

    /** Admin: set the shop ON/OFF for a city (per-city allow-list). */
    public static function setShopEnabled(PDO $db, ?int $cityId, bool $on): array
    {
        if ($cityId === null) return ['ok' => false, 'reason' => 'city_required'];
        $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_shop_enabled_cities' LIMIT 1");
        $s->execute();
        $cur = $s->fetchColumn();
        $list = $cur ? (json_decode((string)$cur, true) ?: []) : [];
        $list = array_values(array_unique(array_map('intval', is_array($list) ? $list : [])));
        if ($on) { if (!in_array($cityId, $list, true)) $list[] = $cityId; }
        else     { $list = array_values(array_filter($list, fn($c) => $c !== $cityId)); }
        $val = json_encode($list);
        $db->prepare("INSERT INTO app_settings (setting_key, setting_value, value_type, label, is_public)
                      VALUES ('commerce_shop_enabled_cities', ?, 'json', 'City IDs where the paint shop module is ON', 0)
                      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$val]);
        return ['ok' => true, 'cities' => $list, 'enabled' => $on];
    }

    /** The 7 paint family categories. */
    public static function families(PDO $db): array { return PaintCatalogue::paintCategories($db); }

    /** The brand list (admin-editable). */
    public static function brands(PDO $db): array { return PaintCatalogue::brands($db); }

    /** Paint-area coverage rate (sqft per litre) for the coverage calculator. */
    public static function coverageSqftPerLitre(PDO $db): float
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_coverage_sqft_per_litre' LIMIT 1");
            $s->execute(); $v = $s->fetchColumn();
            if ($v !== false && is_numeric($v) && (float)$v > 0) return (float)$v;
        } catch (\Throwable $e) {}
        return 140.0; // typical 1 L ≈ 130–150 sqft / coat
    }

    /** Paint category id list, for the "only paint units" filter. */
    private static function familyIds(PDO $db): array
    {
        return array_map(fn($c) => (int)$c['id'], self::families($db));
    }

    private static function firstImage($imagesJson): ?string
    {
        if (!$imagesJson) return null;
        $j = json_decode((string)$imagesJson, true);
        if (is_string($j)) return $j;
        if (is_array($j) && $j) { $f = $j[0]; return is_array($f) ? ($f['url'] ?? null) : (string)$f; }
        return null;
    }

    /**
     * Paint home: one card per parent product, paint families only, serviceable
     * for the pincode. Each card carries the cheapest in-area pack price + stock.
     * @return array<int,array>
     */
    public static function home(PDO $db, ?int $cityId, ?string $pincode, array $f = []): array
    {
        if (!OrderabilityResolver::commerceEnabled($db)) return [];
        $fams = self::familyIds($db);
        if (!$fams) return [];
        $famIn = implode(',', $fams);
        $where = ["p.status='active'", 'p.deleted_at IS NULL', "s.status='active'", 's.deleted_at IS NULL',
                  's.city_id = :city', "pa.category_id IN ($famIn)",
                  '((i.quantity - i.reserved) > 0 OR i.allow_backorder = 1)',
                  OrderabilityResolver::sellerAvailableSql('s', 'v')];
        $bind = [':city' => $cityId];
        if (!empty($f['family'])) { $where[] = 'pa.category_id = :fam'; $bind[':fam'] = (int)$f['family']; }
        if (!empty($f['brand']))  { $where[] = "JSON_VALUE(pa.attributes,'$.brand') = :brand"; $bind[':brand'] = (string)$f['brand']; }
        if (!empty($f['q']))      { $where[] = '(pa.name LIKE :q1 OR JSON_VALUE(pa.attributes,\'$.brand\') LIKE :q2)'; $bind[':q1'] = '%' . trim((string)$f['q']) . '%'; $bind[':q2'] = $bind[':q1']; }
        $sql = "SELECT p.id AS sku_id, p.price, p.store_id, (i.quantity - i.reserved) AS avail, i.allow_backorder,
                       pa.id AS parent_id, pa.name AS parent_name, pa.category_id, pa.images AS parent_images, pa.attributes AS parent_attrs,
                       c.name AS family_name
                  FROM products p
                  JOIN products pa ON pa.id = CAST(JSON_VALUE(p.attributes,'$.parent_id') AS UNSIGNED)
                  JOIN inventory i ON i.product_id = p.id
                  JOIN stores s    ON s.id = p.store_id
                  LEFT JOIN vendors v ON v.id = s.owner_vendor_id
                  LEFT JOIN categories c ON c.id = pa.category_id
                 WHERE " . implode(' AND ', $where);
        $st = $db->prepare($sql);
        foreach ($bind as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // PB14 serviceability by pincode (filter the stores present).
        if ($pincode) {
            $keep = array_flip(Serviceability::filterStoreIds($db, array_values(array_unique(array_map(fn($r) => (int)$r['store_id'], $rows))), $cityId, $pincode));
            $rows = array_values(array_filter($rows, fn($r) => isset($keep[(int)$r['store_id']])));
        }
        // Group by parent → one card, cheapest in-area pack + any-in-stock.
        $cards = [];
        foreach ($rows as $r) {
            $pid = (int)$r['parent_id'];
            $avail = ((int)$r['avail'] > 0) || (int)$r['allow_backorder'] === 1;
            if (!isset($cards[$pid])) {
                $attrs = json_decode((string)$r['parent_attrs'], true) ?: [];
                $cards[$pid] = [
                    'parent_id'   => $pid,
                    'name'        => $r['parent_name'],
                    'brand'       => $attrs['brand'] ?? null,
                    'family_id'   => (int)$r['category_id'],
                    'family'      => $r['family_name'],
                    'image'       => self::firstImage($r['parent_images']),
                    'from_price'  => (float)$r['price'],
                    'in_stock'    => $avail,
                ];
            } else {
                if ((float)$r['price'] < $cards[$pid]['from_price']) $cards[$pid]['from_price'] = (float)$r['price'];
                if ($avail) $cards[$pid]['in_stock'] = true;
            }
        }
        return array_values($cards);
    }

    /**
     * Paint product page: parent + variants + orderable packs (ONE server price
     * each). Seller hidden; customer price only. Null when the product isn't a
     * paint parent or isn't serviceable here.
     */
    public static function product(PDO $db, int $parentId, ?int $cityId, ?string $pincode): ?array
    {
        $pp = $db->prepare("SELECT id, name, category_id, images, attributes, status FROM products
                             WHERE id=? AND JSON_VALUE(attributes,'$.is_parent')='1' LIMIT 1");
        $pp->execute([$parentId]);
        $parent = $pp->fetch(PDO::FETCH_ASSOC);
        if (!$parent) return null;
        $pAttrs = json_decode((string)$parent['attributes'], true) ?: [];
        $famName = null;
        try { $c = $db->prepare("SELECT name FROM categories WHERE id=? LIMIT 1"); $c->execute([$parent['category_id']]); $famName = $c->fetchColumn() ?: null; } catch (\Throwable $e) {}

        // Active packs in this city, with their variant link + stock + price.
        $sk = $db->prepare(
            "SELECT p.id AS product_id, p.price, p.weight, p.store_id, p.attributes,
                    (i.quantity - i.reserved) AS avail, i.allow_backorder
               FROM products p
               JOIN inventory i ON i.product_id = p.id
               JOIN stores s    ON s.id = p.store_id
               LEFT JOIN vendors v ON v.id = s.owner_vendor_id
              WHERE JSON_VALUE(p.attributes,'$.parent_id') = ? AND p.status='active' AND p.deleted_at IS NULL
                AND s.status='active' AND s.deleted_at IS NULL AND (s.city_id = ? OR s.city_id IS NULL)
                AND " . OrderabilityResolver::sellerAvailableSql('s', 'v') . "
              ORDER BY CAST(JSON_VALUE(p.attributes,'$.pack_size') AS DECIMAL(10,2))"
        );
        $sk->execute([(string)$parentId, $cityId]);
        $rows = $sk->fetchAll(PDO::FETCH_ASSOC);
        if ($pincode) {
            $keep = array_flip(Serviceability::filterStoreIds($db, array_values(array_unique(array_map(fn($r) => (int)$r['store_id'], $rows))), $cityId, $pincode));
            $rows = array_values(array_filter($rows, fn($r) => isset($keep[(int)$r['store_id']])));
        }
        $packs = []; $variants = [];
        foreach ($rows as $r) {
            $a = json_decode((string)$r['attributes'], true) ?: [];
            $vkey = ($a['variant_type'] ?? 'v') . '|' . ($a['label'] ?? '');
            if (!isset($variants[$vkey])) {
                $variants[$vkey] = [
                    'key'          => $vkey,
                    'variant_type' => $a['variant_type'] ?? null,
                    'label'        => $a['label'] ?? 'Standard',
                    'shade_mode'   => $a['shade_mode'] ?? 'ready',
                    'finish'       => $a['finish'] ?? null,
                ];
            }
            $avail = ((int)$r['avail'] > 0) || (int)$r['allow_backorder'] === 1;
            $size = (string)($a['pack_size'] ?? ''); $unit = (string)($a['pack_unit'] ?? 'L');
            $packs[] = [
                'product_id'  => (int)$r['product_id'],
                'variant_key' => $vkey,
                'pack_label'  => trim($size . ' ' . $unit),
                'pack_size'   => $size,
                'pack_unit'   => $unit,
                'price'       => (float)$r['price'],   // ONE server price (customer); no JS recompute
                'weight_kg'   => $r['weight'] !== null ? (float)$r['weight'] : null,
                'in_stock'    => $avail,
            ];
        }
        if (!$packs) return null; // nothing orderable here
        return [
            'parent_id' => (int)$parent['id'],
            'name'      => $parent['name'],
            'brand'     => $pAttrs['brand'] ?? null,
            'line'      => $pAttrs['product_line'] ?? null,
            'family'    => $famName,
            'image'     => self::firstImage($parent['images']),
            'variants'  => array_values($variants),
            'packs'     => $packs,
            'custom_tint' => false, // PB10 — stays hidden
        ];
    }
}
