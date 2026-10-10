<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — SellerCatalogue  (S16)
//  core-completion-plan §2 S16. The seller lists an OFFER (their base price + stock)
//  and WorkToGo prices it: customer_price = seller_base + slab margin (S12). The
//  seller base is recorded (products.attributes.seller_base — reuses the existing
//  JSON column, no schema change) so that at checkout orders.seller_subtotal /
//  order_items.seller_base_price get populated and LedgerEngine (S1) splits the
//  margin exactly — i.e. the margin now EARNS end-to-end. New offers land as
//  'draft' (the verification gate); the existing admin approve flips them to
//  'active'. ProductService is read-only (no writer to reuse) and the shopping
//  engine is never-touch, so this writes products/inventory directly — additive,
//  no engine edit. Scoped to the seller's own vendor + store.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/MarginEngine.php';

class SellerCatalogue
{
    /** The seller's own store (ownership=vendor, owner_vendor_id=seller). */
    public static function resolveStoreId(PDO $db, int $vendorId): ?int
    {
        $s = $db->prepare("SELECT id FROM stores WHERE owner_vendor_id=? AND deleted_at IS NULL ORDER BY (status='active') DESC, id ASC LIMIT 1");
        $s->execute([$vendorId]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? null : (int)$v;
    }

    /** base price → [customer_price, margin] via the S12 slab (per category). */
    public static function priceFor(PDO $db, float $base, ?int $categoryId = null): array
    {
        $r = MarginEngine::priceFromBase($db, $base, null, null, null, null, $categoryId);
        return ['customer_price' => (float)$r['customer_price'], 'margin' => (float)$r['margin'], 'source' => $r['source']];
    }

    private static function slugify(int $vendorId, string $name): string
    {
        return 's' . $vendorId . '-' . substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), 0, 24) . '-' . substr(md5($name . microtime(true) . random_int(0, 1e9)), 0, 6);
    }

    /**
     * Create an offer. $data: name (req), base_price (req), stock (req), category_id?,
     * match_key?, unit? , attributes? (unit attrs merged with seller_base). Returns
     * the product_id + computed customer price. Lands as 'draft' (pending verify).
     */
    public static function createOffer(PDO $db, int $vendorId, array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        $base = (float)($data['base_price'] ?? 0);
        $stock = max(0, (int)($data['stock'] ?? 0));
        if ($name === '' || $base <= 0) return ['ok' => false, 'reason' => 'name_and_base_required'];

        $storeId = self::resolveStoreId($db, $vendorId);
        if ($storeId === null) return ['ok' => false, 'reason' => 'no_store'];

        $catId = isset($data['category_id']) && $data['category_id'] !== '' ? (int)$data['category_id'] : null;
        $cityId = (int)($db->query("SELECT city_id FROM stores WHERE id=" . (int)$storeId)->fetchColumn() ?: 0);
        $price = self::priceFor($db, $base, $catId);

        $attrs = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $attrs['seller_base'] = round($base, 2);     // the earning anchor
        $slug = self::slugify($vendorId, $name);
        $matchKey = isset($data['match_key']) && $data['match_key'] !== '' ? (string)$data['match_key'] : $slug;

        $db->prepare(
            "INSERT INTO products (vendor_id, store_id, city_id, category_id, name, slug, match_key, unit, price, attributes, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'draft', NOW(), NOW())"
        )->execute([$vendorId, $storeId, $cityId ?: null, $catId, $name, $slug, $matchKey, $data['unit'] ?? null, $price['customer_price'], json_encode($attrs)]);
        $pid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO inventory (product_id, quantity, reserved, low_stock_alert, track_inventory, allow_backorder, updated_at) VALUES (?,?,0,5,1,0,NOW())")
           ->execute([$pid, $stock]);

        return ['ok' => true, 'product_id' => $pid, 'status' => 'draft', 'seller_base' => round($base, 2), 'customer_price' => $price['customer_price'], 'margin' => $price['margin']];
    }

    /** Update an offer's base price / attrs (recomputes customer price) — seller-scoped. */
    public static function updateOffer(PDO $db, int $vendorId, int $productId, array $data): array
    {
        $p = $db->prepare("SELECT id, category_id, attributes FROM products WHERE id=? AND vendor_id=? LIMIT 1");
        $p->execute([$productId, $vendorId]);
        $row = $p->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['ok' => false, 'reason' => 'not_found_or_not_yours'];

        $attrs = [];
        if (!empty($row['attributes'])) { $j = json_decode((string)$row['attributes'], true); if (is_array($j)) $attrs = $j; }
        if (is_array($data['attributes'] ?? null)) $attrs = array_merge($attrs, $data['attributes']);

        $base = isset($data['base_price']) ? (float)$data['base_price'] : (float)($attrs['seller_base'] ?? 0);
        if ($base <= 0) return ['ok' => false, 'reason' => 'base_required'];
        $attrs['seller_base'] = round($base, 2);
        $catId = isset($data['category_id']) && $data['category_id'] !== '' ? (int)$data['category_id'] : ($row['category_id'] !== null ? (int)$row['category_id'] : null);
        $price = self::priceFor($db, $base, $catId);

        $db->prepare("UPDATE products SET price=?, attributes=?, updated_at=NOW() WHERE id=? AND vendor_id=?")
           ->execute([$price['customer_price'], json_encode($attrs), $productId, $vendorId]);
        return ['ok' => true, 'product_id' => $productId, 'seller_base' => round($base, 2), 'customer_price' => $price['customer_price'], 'margin' => $price['margin']];
    }

    /** Set stock quantity (restock) — seller-scoped. */
    public static function setStock(PDO $db, int $vendorId, int $productId, int $qty): array
    {
        $own = $db->prepare("SELECT 1 FROM products WHERE id=? AND vendor_id=? LIMIT 1");
        $own->execute([$productId, $vendorId]);
        if (!$own->fetchColumn()) return ['ok' => false, 'reason' => 'not_found_or_not_yours'];
        $db->prepare("UPDATE inventory SET quantity=?, updated_at=NOW() WHERE product_id=?")->execute([max(0, $qty), $productId]);
        return ['ok' => true, 'product_id' => $productId, 'stock' => max(0, $qty)];
    }

    /** A seller's own offers (any status). */
    public static function listMine(PDO $db, int $vendorId): array
    {
        $q = $db->prepare(
            "SELECT p.id, p.name, p.price, p.status, p.category_id, p.attributes,
                    (i.quantity - i.reserved) AS available_qty
               FROM products p LEFT JOIN inventory i ON i.product_id=p.id
              WHERE p.vendor_id=? AND p.deleted_at IS NULL ORDER BY p.updated_at DESC"
        );
        $q->execute([$vendorId]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $base = null; if (!empty($r['attributes'])) { $j = json_decode((string)$r['attributes'], true); if (is_array($j) && isset($j['seller_base'])) $base = (float)$j['seller_base']; }
            $out[] = ['product_id' => (int)$r['id'], 'name' => $r['name'], 'status' => $r['status'], 'seller_base' => $base, 'customer_price' => (float)$r['price'], 'available_qty' => (int)$r['available_qty']];
        }
        return $out;
    }

    /** Admin verification: flip a draft offer live (reuses the product status gate). */
    public static function approve(PDO $db, int $productId): bool
    {
        $db->prepare("UPDATE products SET status='active', updated_at=NOW() WHERE id=? AND status='draft'")->execute([$productId]);
        return true;
    }

    /** The recorded seller base for a product (null when not a seller-catalogue offer). */
    public static function sellerBaseOf(PDO $db, int $productId): ?float
    {
        $s = $db->prepare("SELECT attributes FROM products WHERE id=? LIMIT 1");
        $s->execute([$productId]);
        $raw = $s->fetchColumn();
        if ($raw) { $j = json_decode((string)$raw, true); if (is_array($j) && isset($j['seller_base'])) return (float)$j['seller_base']; }
        return null;
    }

    /**
     * THE earning wiring: after an order is created, record the seller base per line
     * (order_items.seller_base_price) and per (sub-)order (orders.seller_subtotal),
     * from each product's recorded base. LedgerEngine (S1) then splits the margin as
     * platform = subtotal − seller_subtotal. An order whose items have no recorded
     * base keeps seller_subtotal NULL (commission back-compat). Idempotent.
     */
    public static function persistSellerBase(PDO $db, int $parentOrderId): array
    {
        $orders = $db->prepare("SELECT id FROM orders WHERE id=? OR parent_order_id=?");
        $orders->execute([$parentOrderId, $parentOrderId]);
        $touched = 0; $totalSeller = 0.0;
        foreach ($orders->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            $oid = (int)$oid;
            $its = $db->prepare("SELECT id, product_id, quantity FROM order_items WHERE order_id=?");
            $its->execute([$oid]);
            $rows = $its->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) continue;
            $sum = 0.0; $allHaveBase = true;
            foreach ($rows as $it) {
                $base = self::sellerBaseOf($db, (int)$it['product_id']);
                if ($base === null) { $allHaveBase = false; $db->prepare("UPDATE order_items SET seller_base_price=NULL WHERE id=?")->execute([(int)$it['id']]); continue; }
                $db->prepare("UPDATE order_items SET seller_base_price=? WHERE id=?")->execute([round($base, 2), (int)$it['id']]);
                $sum += $base * (int)$it['quantity'];
            }
            if ($allHaveBase) {
                $db->prepare("UPDATE orders SET seller_subtotal=? WHERE id=?")->execute([round($sum, 2), $oid]);
                $totalSeller += $sum; $touched++;
            } else {
                $db->prepare("UPDATE orders SET seller_subtotal=NULL WHERE id=?")->execute([$oid]);
            }
        }
        return ['orders_set' => $touched, 'seller_subtotal_total' => round($totalSeller, 2)];
    }
}
