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
        $attrs = array_merge($attrs, ['is_parent' => true, 'brand' => $brand, 'product_line' => $line, 'product_group' => $group]);

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
}
