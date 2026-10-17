<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — PaintCatalogue  (Paint Unit PB1: Product→Variant→Pack SKU)
//  paint-unit-final-build-plan.md PB1. Models the agreed 3-level catalogue on
//  the EXISTING flat tables, touching no engine:
//    PRODUCT  = a `products` row — brand+line, ONE photo (products.images),
//               category, NOT orderable (status 'inactive', no inventory).
//    VARIANT  = a `product_variations` row — base / ready-shade / finish.
//    PACK SKU = a `products` row (status 'draft' → verify) — the ORDERABLE unit
//               the commerce engine already sells: own price (slab-margined),
//               own `inventory`, own `weight`, inherits the product's photo.
//  Reuses SellerCatalogue (pricing/store/base-capture), MarginEngine slab,
//  products.images/attributes/weight, product_variations, inventory. No schema
//  change (uses existing columns + attributes JSON for the parent/variant links).
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/SellerCatalogue.php';

class PaintCatalogue
{
    private static function slug(int $vendorId, string $name): string
    {
        return 'p' . $vendorId . '-' . substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), 0, 28) . '-' . substr(md5($name . microtime(true) . random_int(0, 1e9)), 0, 6);
    }

    private static function storeCity(PDO $db, int $vendorId): array
    {
        $storeId = SellerCatalogue::resolveStoreId($db, $vendorId);
        if ($storeId === null) return [null, null];
        $cityId = (int)($db->query("SELECT city_id FROM stores WHERE id=" . (int)$storeId)->fetchColumn() ?: 0);
        return [$storeId, $cityId ?: null];
    }

    /**
     * PRODUCT (brand + line, ONE photo). Not orderable on its own.
     * $data: brand (req), line (req), category_id (req for paint), photo (url, opt),
     *        attributes (opt). Returns parent product_id + group key.
     */
    public static function createProduct(PDO $db, int $vendorId, array $data): array
    {
        $brand = trim((string)($data['brand'] ?? ''));
        $line  = trim((string)($data['line'] ?? ($data['product_line'] ?? '')));
        if ($brand === '' || $line === '') return ['ok' => false, 'reason' => 'brand_and_line_required'];
        [$storeId, $cityId] = self::storeCity($db, $vendorId);
        if ($storeId === null) return ['ok' => false, 'reason' => 'no_store'];

        $catId = isset($data['category_id']) && $data['category_id'] !== '' ? (int)$data['category_id'] : null;
        $name  = $brand . ' ' . $line;
        $group = self::slug($vendorId, $name);
        $photo = isset($data['photo']) && $data['photo'] !== '' ? (string)$data['photo'] : null;
        $images = $photo ? json_encode([$photo]) : null;
        $attrs = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $attrs = array_merge($attrs, ['is_parent' => 1, 'brand' => $brand, 'product_line' => $line, 'product_group' => $group]);

        $db->prepare(
            "INSERT INTO products (vendor_id, store_id, city_id, category_id, name, slug, match_key, unit, price, images, weight, attributes, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,NULL,0.00,?,NULL,?, 'inactive', NOW(), NOW())"
        )->execute([$vendorId, $storeId, $cityId, $catId, $name, $group, $group, $images, json_encode($attrs)]);
        $pid = (int)$db->lastInsertId();
        return ['ok' => true, 'product_id' => $pid, 'group' => $group, 'photo' => $photo, 'brand' => $brand, 'line' => $line];
    }

    /**
     * VARIANT under a product: base / ready-shade / finish.
     * $data: variant_type (base|ready_shade|finish), label, shade_mode (ready|tintable),
     *        shade_code?, base_type?, finish?, custom_tintable?. Returns variation_id.
     */
    public static function addVariant(PDO $db, int $vendorId, int $parentProductId, array $data): array
    {
        $p = $db->prepare("SELECT id FROM products WHERE id=? AND vendor_id=? LIMIT 1");
        $p->execute([$parentProductId, $vendorId]);
        if (!$p->fetchColumn()) return ['ok' => false, 'reason' => 'parent_not_found_or_not_yours'];

        $attrs = [
            'variant_type'    => (string)($data['variant_type'] ?? 'ready_shade'),
            'label'           => trim((string)($data['label'] ?? '')),
            'shade_mode'      => (string)($data['shade_mode'] ?? 'ready'),
        ];
        foreach (['shade_code', 'base_type', 'finish', 'custom_tintable'] as $k) if (isset($data[$k]) && $data[$k] !== '') $attrs[$k] = $data[$k];
        $sku = 'V' . $parentProductId . '-' . substr(md5(json_encode($attrs) . microtime(true)), 0, 8);

        $db->prepare("INSERT INTO product_variations (product_id, sku, attributes, price, compare_price, stock_quantity, is_active, created_at) VALUES (?,?,?,0.00,NULL,0,1,NOW())")
           ->execute([$parentProductId, $sku, json_encode($attrs)]);
        return ['ok' => true, 'variation_id' => (int)$db->lastInsertId(), 'attributes' => $attrs];
    }

    /**
     * PACK SKU (orderable). $data: pack_size (req), pack_unit (L/kg), base_price (req),
     * stock, weight (kg for this pack). Priced by the slab (SellerCatalogue::priceFor).
     * Inherits the product photo + the variant's shade attributes. Lands as 'draft'.
     */
    public static function addPackSku(PDO $db, int $vendorId, int $parentProductId, int $variationId, array $data): array
    {
        $pp = $db->prepare("SELECT id, store_id, city_id, category_id, images, attributes FROM products WHERE id=? AND vendor_id=? LIMIT 1");
        $pp->execute([$parentProductId, $vendorId]);
        $parent = $pp->fetch(PDO::FETCH_ASSOC);
        if (!$parent) return ['ok' => false, 'reason' => 'parent_not_found_or_not_yours'];

        $vv = $db->prepare("SELECT attributes FROM product_variations WHERE id=? AND product_id=? LIMIT 1");
        $vv->execute([$variationId, $parentProductId]);
        $vraw = $vv->fetchColumn();
        if ($vraw === false) return ['ok' => false, 'reason' => 'variation_not_found'];
        $variant = json_decode((string)$vraw, true) ?: [];
        $pAttrs  = $parent['attributes'] ? (json_decode((string)$parent['attributes'], true) ?: []) : [];

        $base   = (float)($data['base_price'] ?? 0);
        $size   = trim((string)($data['pack_size'] ?? ''));
        $unit   = trim((string)($data['pack_unit'] ?? 'L'));
        $stock  = max(0, (int)($data['stock'] ?? 0));
        $weight = isset($data['weight']) && $data['weight'] !== '' ? round((float)$data['weight'], 3) : null;
        if ($base <= 0 || $size === '') return ['ok' => false, 'reason' => 'pack_size_and_base_required'];

        $catId = $parent['category_id'] !== null ? (int)$parent['category_id'] : null;
        $price = SellerCatalogue::priceFor($db, $base, $catId);

        $attrs = array_merge($variant, [
            'brand'          => $pAttrs['brand'] ?? null,
            'product_line'   => $pAttrs['product_line'] ?? null,
            'product_group'  => $pAttrs['product_group'] ?? null,
            'parent_id'      => $parentProductId,
            'variation_id'   => $variationId,
            'pack_size'      => $size,
            'pack_unit'      => $unit,
            'seller_base'    => round($base, 2),
        ]);
        unset($attrs['is_parent']);
        $name    = trim(($pAttrs['brand'] ?? '') . ' ' . ($pAttrs['product_line'] ?? '') . ' ' . $size . $unit);
        $slug    = self::slug($vendorId, $name);
        $matchKey = ($pAttrs['product_group'] ?? $slug) . '-' . ($variant['variant_type'] ?? 'v') . '-' . $size . $unit;

        $db->prepare(
            "INSERT INTO products (vendor_id, store_id, city_id, category_id, name, slug, match_key, unit, price, images, weight, attributes, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'draft', NOW(), NOW())"
        )->execute([$vendorId, $parent['store_id'], $parent['city_id'], $catId, $name, $slug, $matchKey, $unit, $price['customer_price'], $parent['images'], $weight, json_encode($attrs)]);
        $pid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO inventory (product_id, quantity, reserved, low_stock_alert, track_inventory, allow_backorder, updated_at) VALUES (?,?,0,5,1,0,NOW())")
           ->execute([$pid, $stock]);

        return ['ok' => true, 'product_id' => $pid, 'status' => 'draft', 'pack_size' => $size, 'pack_unit' => $unit,
                'seller_base' => round($base, 2), 'customer_price' => $price['customer_price'], 'weight' => $weight];
    }

    /** Read the product tree: parent + variants + orderable pack SKUs. */
    public static function productTree(PDO $db, int $vendorId, int $parentProductId): array
    {
        $pp = $db->prepare("SELECT id, name, category_id, images, attributes, status FROM products WHERE id=? AND vendor_id=? LIMIT 1");
        $pp->execute([$parentProductId, $vendorId]);
        $parent = $pp->fetch(PDO::FETCH_ASSOC);
        if (!$parent) return ['ok' => false, 'reason' => 'not_found'];
        $vs = $db->prepare("SELECT id, sku, attributes FROM product_variations WHERE product_id=? ORDER BY id");
        $vs->execute([$parentProductId]);
        $sk = $db->prepare("SELECT p.id, p.name, p.price, p.weight, p.status, (i.quantity - i.reserved) AS available_qty, p.attributes
                              FROM products p LEFT JOIN inventory i ON i.product_id=p.id
                             WHERE JSON_VALUE(p.attributes,'$.parent_id') = ? AND p.deleted_at IS NULL ORDER BY p.id");
        $sk->execute([(string)$parentProductId]);
        return ['ok' => true, 'product' => $parent, 'variants' => $vs->fetchAll(PDO::FETCH_ASSOC), 'skus' => $sk->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** Default per-litre density for the weight auto-suggest (paint ≈ 1.25 kg/L). */
    public const DENSITY_KG_PER_L = 1.25;

    /** Brand picker list (admin-editable commerce_paint_brands; sellers may also type a custom brand). */
    public static function brands(PDO $db): array
    {
        $default = ['Berger','Asian Paints','Nerolac','Dulux','Indigo','Nippon','Birla Opus','Jotun','Sherwin Williams','Kansai Nerolac','JSW Paints','Shalimar','Acro','Sheenlac'];
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_paint_brands' LIMIT 1");
            $s->execute(); $v = $s->fetchColumn();
            if ($v) { $j = json_decode((string)$v, true); if (is_array($j) && $j) return array_values(array_filter(array_map(fn($b)=>trim((string)$b),$j),fn($b)=>$b!=='')); }
        } catch (\Throwable $e) {}
        return $default;
    }

    /** The paint family categories (the 7). */
    public static function paintCategories(PDO $db): array
    {
        try {
            $q = $db->query("SELECT id, name, slug FROM categories WHERE slug LIKE 'paint-%' AND status='active' ORDER BY id");
            return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
    }

    /** Record a post-live price/stock change (audit; admin can review later). Best-effort. */
    public static function logChange(PDO $db, int $vendorId, int $productId, string $field, $old, $new): void
    {
        try {
            $db->prepare("INSERT INTO commerce_offer_changes (vendor_id, product_id, field, old_value, new_value, changed_at) VALUES (?,?,?,?,?,NOW())")
               ->execute([$vendorId, $productId, $field, (string)$old, (string)$new]);
        } catch (\Throwable $e) { /* audit is best-effort; never block the edit */ }
    }

    /** Edit a live/draft pack's STOCK any time (no re-approval); logs the change. */
    public static function updatePackStock(PDO $db, int $vendorId, int $skuId, int $qty): array
    {
        $cur = $db->prepare("SELECT i.quantity FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.id=? AND p.vendor_id=? LIMIT 1");
        $cur->execute([$skuId, $vendorId]);
        $old = $cur->fetchColumn();
        if ($old === false) return ['ok' => false, 'reason' => 'not_found_or_not_yours'];
        $res = SellerCatalogue::setStock($db, $vendorId, $skuId, $qty);
        if (!empty($res['ok'])) self::logChange($db, $vendorId, $skuId, 'stock', (int)$old, max(0, $qty));
        return $res;
    }

    /** The admin price-jump threshold % (commerce_price_jump_pct; default 30). */
    public static function priceJumpPct(PDO $db): float
    {
        try { $s=$db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_price_jump_pct' LIMIT 1"); $s->execute(); $v=$s->fetchColumn();
              if ($v!==false && is_numeric($v)) return max(0.0,(float)$v); } catch (\Throwable $e) {}
        return 30.0;
    }

    /**
     * Edit a pack's seller PRICE. A SMALL change (≤ threshold %, up or down) goes
     * live instantly (no re-approval) and is logged. A BIG jump beyond the
     * threshold does NOT go live — it is queued in commerce_price_reviews for
     * admin review (PB3a item 4). Stock edits are always instant (see updatePackStock).
     */
    public static function updatePackPrice(PDO $db, int $vendorId, int $skuId, float $newBase): array
    {
        $own = $db->prepare("SELECT 1 FROM products WHERE id=? AND vendor_id=? LIMIT 1");
        $own->execute([$skuId, $vendorId]);
        if (!$own->fetchColumn()) return ['ok' => false, 'reason' => 'not_found_or_not_yours'];
        $old = SellerCatalogue::sellerBaseOf($db, $skuId);
        $newBase = round($newBase, 2);
        if ($old !== null && $old > 0) {
            $jump = abs($newBase - $old) / $old * 100.0;
            if ($jump > self::priceJumpPct($db)) {
                try {
                    $db->prepare("INSERT INTO commerce_price_reviews (product_id, vendor_id, old_base, new_base, status, created_at) VALUES (?,?,?,?, 'pending', NOW())")
                       ->execute([$skuId, $vendorId, $old, $newBase]);
                } catch (\Throwable $e) {}
                self::logChange($db, $vendorId, $skuId, 'price_pending', $old, $newBase);
                return ['ok' => true, 'queued' => true, 'reason' => 'price_jump_review', 'old_base' => $old, 'new_base' => $newBase, 'threshold_pct' => self::priceJumpPct($db)];
            }
        }
        $res = SellerCatalogue::updateOffer($db, $vendorId, $skuId, ['base_price' => $newBase]);
        if (!empty($res['ok'])) self::logChange($db, $vendorId, $skuId, 'price', $old, $newBase);
        return $res + ['queued' => false];
    }

    /** "My products" — parents grouped with their pack SKUs (seller-scoped). */
    public static function myProducts(PDO $db, int $vendorId): array
    {
        $pp = $db->prepare("SELECT id, name, images, attributes, status FROM products WHERE vendor_id=? AND deleted_at IS NULL AND JSON_VALUE(attributes,'$.is_parent')='1' ORDER BY updated_at DESC");
        $pp->execute([$vendorId]);
        $out = [];
        foreach ($pp->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $attrs = $p['attributes'] ? (json_decode((string)$p['attributes'], true) ?: []) : [];
            $imgs = $p['images'] ? (json_decode((string)$p['images'], true) ?: []) : [];
            $sk = $db->prepare("SELECT p.id, p.name, p.price, p.status, p.attributes, (i.quantity - i.reserved) AS available_qty
                                  FROM products p LEFT JOIN inventory i ON i.product_id=p.id
                                 WHERE JSON_VALUE(p.attributes,'$.parent_id') = ? AND p.deleted_at IS NULL ORDER BY p.id");
            $sk->execute([(string)$p['id']]);
            $skus = [];
            foreach ($sk->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $sa = $s['attributes'] ? (json_decode((string)$s['attributes'], true) ?: []) : [];
                $skus[] = ['product_id' => (int)$s['id'], 'pack' => ($sa['pack_size'] ?? '') . ($sa['pack_unit'] ?? ''),
                           'seller_base' => $sa['seller_base'] ?? null, 'customer_price' => (float)$s['price'],
                           'stock' => (int)$s['available_qty'], 'status' => $s['status'], 'reject_reason' => $sa['reject_reason'] ?? null];
            }
            $out[] = ['product_id' => (int)$p['id'], 'name' => $p['name'], 'brand' => $attrs['brand'] ?? null,
                      'line' => $attrs['product_line'] ?? null, 'photo' => $imgs[0] ?? null, 'status' => $p['status'], 'skus' => $skus];
        }
        return $out;
    }

    // ── ADMIN verification (PB3a) ────────────────────────────────────────────
    /** Cross-vendor pending pack SKUs for the verification queue (+ dup flag). */
    public static function pendingProducts(PDO $db): array
    {
        $q = $db->query("SELECT p.id, p.vendor_id, p.name, p.price, p.weight, p.match_key, p.images, p.attributes, v.business_name,
                                (i.quantity - i.reserved) AS available_qty
                           FROM products p LEFT JOIN inventory i ON i.product_id=p.id LEFT JOIN vendors v ON v.id=p.vendor_id
                          WHERE p.status='draft' AND p.deleted_at IS NULL
                            AND JSON_VALUE(p.attributes,'$.parent_id') IS NOT NULL
                          ORDER BY p.created_at ASC");
        $dups = self::duplicateMatchKeys($db);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $a = $r['attributes'] ? (json_decode((string)$r['attributes'], true) ?: []) : [];
            $imgs = $r['images'] ? (json_decode((string)$r['images'], true) ?: []) : [];
            $out[] = ['product_id' => (int)$r['id'], 'vendor_id' => (int)$r['vendor_id'], 'vendor' => $r['business_name'],
                      'brand' => $a['brand'] ?? null, 'line' => $a['product_line'] ?? null, 'category_id' => null,
                      'pack' => ($a['pack_size'] ?? '') . ($a['pack_unit'] ?? ''), 'shade' => $a['label'] ?? ($a['shade_mode'] ?? null),
                      'seller_base' => $a['seller_base'] ?? null, 'customer_price' => (float)$r['price'], 'weight' => $r['weight'],
                      'stock' => (int)$r['available_qty'], 'photo' => $imgs[0] ?? null, 'match_key' => $r['match_key'],
                      'duplicate' => in_array($r['match_key'], $dups, true)];
        }
        return $out;
    }

    /** match_keys that appear for more than one vendor (PB17 duplicate candidates). */
    public static function duplicateMatchKeys(PDO $db): array
    {
        try {
            $q = $db->query("SELECT match_key FROM products WHERE match_key IS NOT NULL AND deleted_at IS NULL AND JSON_VALUE(attributes,'$.parent_id') IS NOT NULL GROUP BY match_key HAVING COUNT(DISTINCT vendor_id) > 1");
            return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)) ?: [];
        } catch (\Throwable $e) { return []; }
    }

    /** Full duplicate-candidate groups for the admin (same match_key, >1 vendor). */
    public static function duplicateCandidates(PDO $db): array
    {
        $out = [];
        foreach (self::duplicateMatchKeys($db) as $mk) {
            $q = $db->prepare("SELECT id, vendor_id, name, price, status FROM products WHERE match_key=? AND deleted_at IS NULL ORDER BY id");
            $q->execute([$mk]);
            $out[] = ['match_key' => $mk, 'members' => $q->fetchAll(PDO::FETCH_ASSOC)];
        }
        return $out;
    }

    /** Approve a pending pack SKU → live (parent stays non-orderable). */
    public static function approveProduct(PDO $db, int $skuId): array
    {
        $db->prepare("UPDATE products SET status='active', updated_at=NOW() WHERE id=? AND status='draft' AND JSON_VALUE(attributes,'$.parent_id') IS NOT NULL")->execute([$skuId]);
        return ['ok' => true, 'product_id' => $skuId, 'status' => 'active'];
    }

    /** Reject a pending pack SKU with a reason the seller will see (status chip). */
    public static function rejectProduct(PDO $db, int $skuId, string $reason): array
    {
        $s = $db->prepare("SELECT attributes FROM products WHERE id=? LIMIT 1"); $s->execute([$skuId]);
        $raw = $s->fetchColumn(); if ($raw === false) return ['ok' => false, 'reason' => 'not_found'];
        $a = $raw ? (json_decode((string)$raw, true) ?: []) : []; $a['reject_reason'] = mb_substr($reason, 0, 240);
        $db->prepare("UPDATE products SET status='inactive', attributes=?, updated_at=NOW() WHERE id=?")->execute([json_encode($a), $skuId]);
        return ['ok' => true, 'product_id' => $skuId, 'status' => 'inactive', 'reason' => $a['reject_reason']];
    }

    /** PB17 merge: point a product's match_key at the canonical product's → one listing. */
    public static function mergeMatchKey(PDO $db, int $productId, int $canonicalProductId): array
    {
        $c = $db->prepare("SELECT match_key FROM products WHERE id=? LIMIT 1"); $c->execute([$canonicalProductId]);
        $mk = $c->fetchColumn(); if ($mk === false || $mk === null) return ['ok' => false, 'reason' => 'canonical_not_found'];
        $db->prepare("UPDATE products SET match_key=?, updated_at=NOW() WHERE id=?")->execute([$mk, $productId]);
        return ['ok' => true, 'product_id' => $productId, 'match_key' => $mk];
    }

    /** Change-log for a product (edits + any price reviews). */
    public static function productChanges(PDO $db, int $productId): array
    {
        $out = ['changes' => [], 'price_reviews' => []];
        try { $q=$db->prepare("SELECT field, old_value, new_value, changed_at FROM commerce_offer_changes WHERE product_id=? ORDER BY id DESC LIMIT 100"); $q->execute([$productId]); $out['changes']=$q->fetchAll(PDO::FETCH_ASSOC); } catch (\Throwable $e) {}
        try { $q=$db->prepare("SELECT old_base, new_base, status, reason, created_at, reviewed_at FROM commerce_price_reviews WHERE product_id=? ORDER BY id DESC LIMIT 50"); $q->execute([$productId]); $out['price_reviews']=$q->fetchAll(PDO::FETCH_ASSOC); } catch (\Throwable $e) {}
        return $out;
    }

    /** Pending price-jump reviews (admin queue). */
    public static function priceReviews(PDO $db): array
    {
        try {
            $q = $db->query("SELECT r.id, r.product_id, r.vendor_id, r.old_base, r.new_base, r.created_at, p.name
                               FROM commerce_price_reviews r LEFT JOIN products p ON p.id=r.product_id
                              WHERE r.status='pending' ORDER BY r.created_at ASC");
            return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
    }

    /** Approve/reject a queued price jump. Approve → applies the new price live. */
    public static function reviewPrice(PDO $db, int $reviewId, bool $approve, ?string $reason, int $reviewerId): array
    {
        $s = $db->prepare("SELECT product_id, vendor_id, old_base, new_base, status FROM commerce_price_reviews WHERE id=? LIMIT 1");
        $s->execute([$reviewId]); $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'reason' => 'not_found'];
        if ($r['status'] !== 'pending') return ['ok' => false, 'reason' => 'already_reviewed'];
        if ($approve) {
            $res = SellerCatalogue::updateOffer($db, (int)$r['vendor_id'], (int)$r['product_id'], ['base_price' => (float)$r['new_base']]);
            if (empty($res['ok'])) return $res;
            self::logChange($db, (int)$r['vendor_id'], (int)$r['product_id'], 'price', $r['old_base'], $r['new_base']);
            $db->prepare("UPDATE commerce_price_reviews SET status='approved', reason=?, reviewed_at=NOW(), reviewed_by=? WHERE id=?")->execute([$reason, $reviewerId, $reviewId]);
            return ['ok' => true, 'applied' => true, 'customer_price' => $res['customer_price'] ?? null];
        }
        $db->prepare("UPDATE commerce_price_reviews SET status='rejected', reason=?, reviewed_at=NOW(), reviewed_by=? WHERE id=?")->execute([$reason, $reviewerId, $reviewId]);
        return ['ok' => true, 'applied' => false];
    }
}
