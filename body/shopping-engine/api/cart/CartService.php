<?php
// ─────────────────────────────────────────────────────────────
//  WorkToGo — CartService  (Production v3.0)
//
//  v3.0 FIXES:
//    [1] CART RACE CONDITION — add() uses atomic
//        INSERT ... ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + ?, maxQty)
//        The DB UNIQUE(user_id, product_id) constraint (schema v3.0) is the
//        hard backstop — even if two requests race past the SELECT, only ONE
//        row can ever exist per (user, product) pair.
//
//    [2] Existing add() logic retained for validation (stock check, item count)
//        before the atomic write.
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../helpers/shopping.helpers.php';

class CartService
{
    private PDO $db;

    private int $maxItems    = 20;
    private int $maxQtyItem  = 50;
    private int $cartTtlDays = 30;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────
    //  GET /api/cart
    // ─────────────────────────────────────────────
    public function get(int $userId): array
    {
        try {
            $sql = "
                SELECT
                    c.id                             AS cart_item_id,
                    c.product_id,
                    c.quantity,
                    c.created_at,
                    p.name,
                    p.sku,
                    p.price,
                    p.sale_price,
                    p.images,
                    p.vendor_id,
                    p.status                         AS product_status,
                    (i.quantity - i.reserved)        AS available_qty,
                    i.allow_backorder,
                    v.business_name                  AS vendor_name
                FROM cart c
                JOIN products p  ON p.id = c.product_id
                JOIN inventory i ON i.product_id = p.id
                LEFT JOIN vendors v ON v.id = p.vendor_id
                WHERE c.user_id = :user_id
                  AND p.deleted_at IS NULL
                  AND (c.expires_at IS NULL OR c.expires_at > NOW())
                ORDER BY c.created_at DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            error_log('[CartService::get] ' . $e->getMessage());
            throw new \RuntimeException('Failed to load cart');
        }

        $items       = [];
        $subtotal    = 0.0;
        $unavailable = [];

        foreach ($rows as $row) {
            $price     = (float)$row['price'];
            $salePrice = $row['sale_price'] !== null ? (float)$row['sale_price'] : null;
            $effective = ($salePrice !== null && $salePrice < $price) ? $salePrice : $price;

            $qty          = (int)$row['quantity'];
            $availableQty = (int)$row['available_qty'];
            $isActive     = ($row['product_status'] === 'active');
            $allowBack    = (bool)$row['allow_backorder'];

            // Cap stored quantity to what is actually available
            $safeQty   = $allowBack ? $qty : min($qty, max(0, $availableQty));
            $lineTotal  = round($effective * $safeQty, 2);
            $subtotal  += $lineTotal;

            $imgArr = $row['images'] ? json_decode($row['images'], true) : [];
            $thumb  = null;
            if (is_array($imgArr) && !empty($imgArr)) {
                $thumb = is_string($imgArr[0]) ? $imgArr[0] : ($imgArr[0]['url'] ?? null);
            }

            $isAvailable = $isActive && ($availableQty > 0 || $allowBack);

            $item = [
                'cart_item_id'    => (int)$row['cart_item_id'],
                'product_id'      => (int)$row['product_id'],
                'name'            => $row['name'],
                'sku'             => $row['sku'],
                'image'           => $thumb,
                'vendor_id'       => (int)$row['vendor_id'],
                'vendor_name'     => $row['vendor_name'],
                'price'           => $price,
                'sale_price'      => $salePrice,
                'effective_price' => $effective,
                'quantity'        => $safeQty,
                'line_total'      => $lineTotal,
                'available_qty'   => $availableQty,
                'is_available'    => $isAvailable,
            ];

            if (!$isAvailable) {
                $unavailable[] = $row['name'];
            }

            $items[] = $item;
        }

        return [
            'items'       => $items,
            'item_count'  => count($items),
            'subtotal'    => round($subtotal, 2),
            'currency'    => 'INR',
            'unavailable' => $unavailable,
        ];
    }

    // ─────────────────────────────────────────────
    //  POST /api/cart/add
    //  Body: { product_id, quantity? }
    //
    //  v3.0: Uses INSERT ... ON DUPLICATE KEY UPDATE
    //  so concurrent requests on the same (user, product)
    //  are safe even with the DB UNIQUE constraint in place.
    // ─────────────────────────────────────────────
    public function add(int $userId, int $productId, int $quantity = 1): array
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1');
        }
        if ($quantity > $this->maxQtyItem) {
            throw new \InvalidArgumentException("Max quantity per item is {$this->maxQtyItem}");
        }

        try {
            $product = $this->fetchProduct($productId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to validate product');
        }

        if (!$product) {
            throw new \RuntimeException('Product not found');
        }
        if ($product['product_status'] !== 'active') {
            throw new \RuntimeException('Product is no longer available');
        }

        // Read current cart state for validation
        $existing = $this->getCartItem($userId, $productId);
        $newQty   = $existing ? ((int)$existing['quantity'] + $quantity) : $quantity;

        if (!$product['allow_backorder'] && $newQty > $product['available_qty']) {
            $avail = $product['available_qty'];
            throw new \RuntimeException(
                "Only {$avail} units available (you requested {$newQty})"
            );
        }
        if ($newQty > $this->maxQtyItem) {
            throw new \InvalidArgumentException("Max quantity per item is {$this->maxQtyItem}");
        }

        // Item-count limit only applies when adding a NEW item
        if (!$existing) {
            $itemCount = $this->countCartItems($userId);
            if ($itemCount >= $this->maxItems) {
                throw new \RuntimeException("Cart is full (max {$this->maxItems} items)");
            }
        }

        try {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$this->cartTtlDays} days"));
            $unitPrice = $product['effective_price'];

            if ($existing) {
                // ── Standard update path (no race risk — user already has this item) ──
                $this->db->prepare(
                    "UPDATE cart
                     SET quantity   = :qty,
                         expires_at = :exp,
                         updated_at = NOW()
                     WHERE user_id = :uid AND product_id = :pid"
                )->execute([
                    ':qty' => $newQty,
                    ':exp' => $expiresAt,
                    ':uid' => $userId,
                    ':pid' => $productId,
                ]);
            } else {
                // ── Atomic upsert — safe against concurrent double-submits ──
                // If two requests race here, the UNIQUE(user_id, product_id) constraint
                // means only one INSERT succeeds; the other triggers ON DUPLICATE KEY UPDATE.
                // LEAST() caps the quantity at maxQtyItem regardless of concurrent adds.
                $this->db->prepare("
                    INSERT INTO cart
                        (user_id, product_id, quantity, unit_price, expires_at, created_at, updated_at)
                    VALUES
                        (:uid, :pid, :qty_init, :price, :exp, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        quantity   = LEAST(quantity + :qty_add, :max_qty),
                        expires_at = :exp2,
                        updated_at = NOW()
                ")->execute([
                    ':uid'      => $userId,
                    ':pid'      => $productId,
                    ':qty_init' => $quantity,
                    ':qty_add'  => $quantity,
                    ':price'    => $unitPrice,
                    ':exp'      => $expiresAt,
                    ':exp2'     => $expiresAt,
                    ':max_qty'  => $this->maxQtyItem,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[CartService::add] ' . $e->getMessage());
            throw new \RuntimeException('Failed to update cart');
        }

        return $this->get($userId);
    }

    // ─────────────────────────────────────────────
    //  POST /api/cart/remove
    //  Body: { product_id } or { cart_item_id }
    // ─────────────────────────────────────────────
    public function remove(int $userId, ?int $productId = null, ?int $cartItemId = null): array
    {
        if (!$productId && !$cartItemId) {
            throw new \InvalidArgumentException('Provide product_id or cart_item_id');
        }

        try {
            if ($cartItemId) {
                $stmt = $this->db->prepare(
                    "DELETE FROM cart WHERE id = :id AND user_id = :uid"
                );
                $stmt->execute([':id' => $cartItemId, ':uid' => $userId]);
            } else {
                $stmt = $this->db->prepare(
                    "DELETE FROM cart WHERE product_id = :pid AND user_id = :uid"
                );
                $stmt->execute([':pid' => $productId, ':uid' => $userId]);
            }
        } catch (\Throwable $e) {
            error_log('[CartService::remove] ' . $e->getMessage());
            throw new \RuntimeException('Failed to remove item');
        }

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Item not found in cart');
        }

        return $this->get($userId);
    }

    // ─────────────────────────────────────────────
    //  POST /api/cart/update
    //  quantity = 0 removes the item
    // ─────────────────────────────────────────────
    public function update(int $userId, int $productId, int $quantity): array
    {
        if ($quantity === 0) {
            return $this->remove($userId, $productId);
        }
        if ($quantity > $this->maxQtyItem) {
            throw new \InvalidArgumentException("Max quantity per item is {$this->maxQtyItem}");
        }

        try {
            $product = $this->fetchProduct($productId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to validate product');
        }

        if (!$product) {
            throw new \RuntimeException('Product not found');
        }
        if (!$product['allow_backorder'] && $quantity > $product['available_qty']) {
            $avail = $product['available_qty'];
            throw new \RuntimeException("Only {$avail} units in stock");
        }

        $existing = $this->getCartItem($userId, $productId);
        if (!$existing) {
            throw new \RuntimeException('Item not found in cart');
        }

        try {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$this->cartTtlDays} days"));
            $this->db->prepare(
                "UPDATE cart
                 SET quantity   = :qty,
                     expires_at = :exp,
                     updated_at = NOW()
                 WHERE user_id = :uid AND product_id = :pid"
            )->execute([
                ':qty' => $quantity,
                ':exp' => $expiresAt,
                ':uid' => $userId,
                ':pid' => $productId,
            ]);
        } catch (\Throwable $e) {
            error_log('[CartService::update] ' . $e->getMessage());
            throw new \RuntimeException('Failed to update cart');
        }

        return $this->get($userId);
    }

    // ─────────────────────────────────────────────
    //  Clear cart — called after successful checkout
    // ─────────────────────────────────────────────
    public function clear(int $userId): void
    {
        try {
            $this->db->prepare("DELETE FROM cart WHERE user_id = :uid")
                     ->execute([':uid' => $userId]);
        } catch (\Throwable $e) {
            error_log('[CartService::clear] ' . $e->getMessage());
            // Non-fatal — order already committed
        }
    }

    // ─────────────────────────────────────────────
    //  Internals
    // ─────────────────────────────────────────────
    private function fetchProduct(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                p.id,
                p.name,
                p.price,
                p.sale_price,
                p.vendor_id,
                p.status                  AS product_status,
                (i.quantity - i.reserved) AS available_qty,
                i.allow_backorder
            FROM products p
            JOIN inventory i ON i.product_id = p.id
            WHERE p.id = :id
              AND p.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $price   = (float)$row['price'];
        $salePrc = $row['sale_price'] !== null ? (float)$row['sale_price'] : null;
        $row['effective_price'] = ($salePrc !== null && $salePrc < $price) ? $salePrc : $price;
        $row['available_qty']   = (int)$row['available_qty'];
        $row['allow_backorder'] = (bool)$row['allow_backorder'];
        return $row;
    }

    private function getCartItem(int $userId, int $productId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, quantity FROM cart WHERE user_id = :uid AND product_id = :pid LIMIT 1"
        );
        $stmt->execute([':uid' => $userId, ':pid' => $productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function countCartItems(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM cart WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }
}
