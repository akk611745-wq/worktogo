<?php
// ─────────────────────────────────────────────────────────────
//  WorkToGo — ProductController  (Production v3.0)
//
//  v3.0 changes:
//    [1] update(): price must be >= 0 (was missing, could allow -ve prices)
//    [2] Vendor always resolved from JWT — no body injection possible
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../helpers/shopping.helpers.php';
require_once __DIR__ . '/ProductService.php';

class ProductController
{
    private ProductService $service;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db      = $db;
        $this->service = new ProductService($db);
    }

    // ── GET /api/products ─────────────────────────────────────
    public function index(): void
    {
        try {
            se_ok($this->service->list($_GET));
        } catch (\Throwable $e) {
            error_log('[ProductController::index] ' . $e->getMessage());
            se_fail('Failed to load products', 500);
        }
    }

    // ── GET /api/products/{id} ────────────────────────────────
    public function show(int $id): void
    {
        try {
            $product = $this->service->detail($id);
            if (!$product) {
                se_fail('Product not found', 404);
                return;
            }
            se_ok($product);
        } catch (\Throwable $e) {
            error_log('[ProductController::show] ' . $e->getMessage());
            se_fail('Failed to load product', 500);
        }
    }

    // ── POST /api/products ────────────────────────────────────
    public function create(): void
    {
        $auth   = AuthMiddleware::requireRole(ROLE_VENDOR_SHOPPING, ROLE_ADMIN);
        $userId = $auth['user_id'];
        $body   = se_json_body();

        $name  = trim($body['name'] ?? '');
        $price = isset($body['price']) ? (float)$body['price'] : null;

        if ($name === '') {
            se_fail('name is required', 422);
            return;
        }
        if ($price === null || $price < 0) {
            se_fail('price must be a number >= 0', 422);
            return;
        }

        // SECURITY: vendor always resolved from authenticated user — never from body
        $vendorId = $this->resolveVendorId($userId);
        if (!$vendorId) {
            se_fail('Vendor account required. Your account is not linked to an active vendor.', 403);
            return;
        }

        try {
            $this->db->beginTransaction();

            $slug      = $this->makeSlug($name);
            $salePrice = (isset($body['sale_price']) && $body['sale_price'] !== '') ? (float)$body['sale_price'] : null;
            $catId     = (isset($body['category_id']) && $body['category_id'])      ? (int)$body['category_id'] : null;
            $sku       = trim($body['sku']         ?? '') ?: null;
            $unit      = trim($body['unit']        ?? '') ?: null;
            $shortDesc = trim($body['short_desc']  ?? '') ?: null;
            $desc      = trim($body['description'] ?? '') ?: null;
            $images    = (isset($body['images']) && is_array($body['images'])) ? json_encode($body['images']) : null;
            $status    = in_array($body['status'] ?? '', ['draft', 'active', 'inactive'], true)
                         ? $body['status'] : 'draft';

            if ($salePrice !== null && $salePrice >= $price) {
                $this->db->rollBack();
                se_fail('sale_price must be less than price', 422);
                return;
            }

            $this->db->prepare("
                INSERT INTO products
                    (vendor_id, category_id, name, slug, description, short_desc,
                     sku, price, sale_price, images, unit, status, created_at, updated_at)
                VALUES
                    (:vendor, :cat, :name, :slug, :desc, :sdesc,
                     :sku, :price, :sale, :images, :unit, :status, NOW(), NOW())
            ")->execute([
                ':vendor' => $vendorId, ':cat'    => $catId,      ':name'   => $name,
                ':slug'   => $slug,     ':desc'   => $desc,        ':sdesc'  => $shortDesc,
                ':sku'    => $sku,      ':price'  => $price,       ':sale'   => $salePrice,
                ':images' => $images,   ':unit'   => $unit,        ':status' => $status,
            ]);

            $productId = (int)$this->db->lastInsertId();

            $initQty = max(0, (int)($body['quantity'] ?? 0));
            $this->db->prepare(
                "INSERT INTO inventory (product_id, quantity, reserved, track_inventory)
                 VALUES (:pid, :qty, 0, 1)"
            )->execute([':pid' => $productId, ':qty' => $initQty]);

            // Task 1: Insert variations if provided
            if (isset($body['variations']) && is_array($body['variations'])) {
                $stmtVar = $this->db->prepare("
                    INSERT INTO product_variations (product_id, sku, attributes, price, compare_price, stock_quantity)
                    VALUES (:pid, :sku, :attrs, :price, :compare, :stock)
                ");
                foreach ($body['variations'] as $v) {
                    $vSku = trim($v['sku'] ?? '');
                    if ($vSku === '') {
                        $this->db->rollBack();
                        se_fail('Variation sku is required', 422);
                        return;
                    }
                    // Validate SKU uniqueness across variations
                    $chk = $this->db->prepare("SELECT id FROM product_variations WHERE sku = ? LIMIT 1");
                    $chk->execute([$vSku]);
                    if ($chk->fetchColumn()) {
                        $this->db->rollBack();
                        se_fail('Variation SKU already exists: ' . $vSku, 409);
                        return;
                    }
                    $stmtVar->execute([
                        ':pid' => $productId,
                        ':sku' => $vSku,
                        ':attrs' => isset($v['attributes']) ? json_encode($v['attributes']) : '{}',
                        ':price' => isset($v['price']) ? (float)$v['price'] : (float)$price,
                        ':compare' => isset($v['compare_price']) && $v['compare_price'] !== '' ? (float)$v['compare_price'] : null,
                        ':stock' => isset($v['stock_quantity']) ? (int)$v['stock_quantity'] : 0
                    ]);
                }
            }

            $this->db->commit();

            se_ok(['product_id' => $productId, 'slug' => $slug, 'status' => $status]);

        } catch (\PDOException $e) {
            try { $this->db->rollBack(); } catch (\Throwable $rb) {}
            if ($e->getCode() === '23000') {
                se_fail('A product with this name or SKU already exists', 409);
            } else {
                error_log('[ProductController::create] ' . $e->getMessage());
                se_fail('Failed to create product', 500);
            }
        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable $rb) {}
            error_log('[ProductController::create] ' . $e->getMessage());
            se_fail($e->getMessage(), 500);
        }
    }

    // ── PUT /api/products/{id} ────────────────────────────────
    public function update(int $id): void
    {
        $auth   = AuthMiddleware::requireRole(ROLE_VENDOR_SHOPPING, ROLE_ADMIN);
        $userId = $auth['user_id'];
        $body   = se_json_body();

        $vendorId = $this->resolveVendorId($userId);
        if (!$vendorId) {
            se_fail('Vendor account required', 403);
            return;
        }

        try {
            // Ownership check
            $stmt = $this->db->prepare(
                "SELECT id FROM products
                 WHERE id = :id AND vendor_id = :vid AND deleted_at IS NULL
                 LIMIT 1"
            );
            $stmt->execute([':id' => $id, ':vid' => $vendorId]);
            if (!$stmt->fetch()) {
                se_fail('Product not found', 404);
                return;
            }

            $allowed = ['name', 'description', 'short_desc', 'price', 'sale_price',
                        'category_id', 'sku', 'unit', 'images', 'status', 'is_featured'];
            $sets = [];
            $bind = [':id' => $id, ':vid' => $vendorId];

            foreach ($allowed as $col) {
                if (!array_key_exists($col, $body)) continue;
                $val = $body[$col];
                if ($col === 'images' && is_array($val))                          { $val = json_encode($val); }
                if (in_array($col, ['price', 'sale_price'], true) && $val !== null) { $val = (float)$val; }
                if ($col === 'is_featured')                                         { $val = (int)(bool)$val; }
                $sets[]        = "$col = :$col";
                $bind[":$col"] = $val;
            }

            if (empty($sets)) {
                se_fail('No valid fields to update', 422);
                return;
            }

            // v3.0 FIX: price must be >= 0 on update
            if (isset($bind[':price']) && (float)$bind[':price'] < 0) {
                se_fail('price must be >= 0', 422);
                return;
            }

            // sale_price must be less than price when both present
            if (isset($bind[':price']) && isset($bind[':sale_price']) &&
                $bind[':sale_price'] !== null &&
                (float)$bind[':sale_price'] >= (float)$bind[':price']) {
                se_fail('sale_price must be less than price', 422);
                return;
            }

            $sets[] = 'updated_at = NOW()';
            $this->db->prepare(
                'UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id AND vendor_id = :vid'
            )->execute($bind);

            // Update inventory quantity if provided
            if (array_key_exists('quantity', $body)) {
                $qty = max(0, (int)$body['quantity']);
                $this->db->prepare("
                    INSERT INTO inventory (product_id, quantity, reserved)
                    VALUES (:pid, :qty, 0)
                    ON DUPLICATE KEY UPDATE quantity = :qty2
                ")->execute([':pid' => $id, ':qty' => $qty, ':qty2' => $qty]);
            }

            // Task 1: Update variations if provided
            if (isset($body['variations']) && is_array($body['variations'])) {
                $stmtVar = $this->db->prepare("
                    INSERT INTO product_variations (product_id, sku, attributes, price, compare_price, stock_quantity)
                    VALUES (:pid, :sku, :attrs, :price, :compare, :stock)
                    ON DUPLICATE KEY UPDATE 
                        attributes = VALUES(attributes),
                        price = VALUES(price),
                        compare_price = VALUES(compare_price),
                        stock_quantity = VALUES(stock_quantity)
                ");
                foreach ($body['variations'] as $v) {
                    $vSku = trim($v['sku'] ?? '');
                    if ($vSku === '') {
                        se_fail('Variation sku is required', 422);
                        return;
                    }
                    
                    // Note: Since sku is UNIQUE across the table, ON DUPLICATE KEY UPDATE works perfectly.
                    // But if this sku exists for another product, we should prevent taking it over if that's not allowed,
                    // However ON DUPLICATE KEY UPDATE will just update it. To be safe, let's verify ownership of the sku if we can,
                    // or just let it update if unique constraint is hit on sku.
                    // Actually, if sku is UNIQUE, `product_id` is part of the INSERT, if it exists, it will update `product_id`?
                    // Wait, we shouldn't change `product_id`. So let's check first.
                    $chk = $this->db->prepare("SELECT product_id FROM product_variations WHERE sku = ? LIMIT 1");
                    $chk->execute([$vSku]);
                    $existingPid = $chk->fetchColumn();
                    if ($existingPid && $existingPid != $id) {
                        se_fail('Variation SKU already exists for another product: ' . $vSku, 409);
                        return;
                    }

                    $stmtVar->execute([
                        ':pid' => $id,
                        ':sku' => $vSku,
                        ':attrs' => isset($v['attributes']) ? json_encode($v['attributes']) : '{}',
                        ':price' => isset($v['price']) ? (float)$v['price'] : (isset($bind[':price']) ? (float)$bind[':price'] : 0),
                        ':compare' => isset($v['compare_price']) && $v['compare_price'] !== '' ? (float)$v['compare_price'] : null,
                        ':stock' => isset($v['stock_quantity']) ? (int)$v['stock_quantity'] : 0
                    ]);
                }
            }

            se_ok(['updated' => true]);

        } catch (\Throwable $e) {
            error_log('[ProductController::update] ' . $e->getMessage());
            se_fail('Failed to update product', 500);
        }
    }

    // ── DELETE /api/products/{id} ─────────────────────────────
    public function delete(int $id): void
    {
        $auth   = AuthMiddleware::requireRole(ROLE_VENDOR_SHOPPING, ROLE_ADMIN);
        $userId = $auth['user_id'];

        $vendorId = $this->resolveVendorId($userId);
        if (!$vendorId) {
            se_fail('Vendor account required', 403);
            return;
        }

        try {
            $stmt = $this->db->prepare("
                UPDATE products
                SET deleted_at = NOW(), status = 'archived', updated_at = NOW()
                WHERE id = :id AND vendor_id = :vid AND deleted_at IS NULL
            ");
            $stmt->execute([':id' => $id, ':vid' => $vendorId]);

            if ($stmt->rowCount() === 0) {
                se_fail('Product not found', 404);
                return;
            }
            se_ok(['deleted' => true]);

        } catch (\Throwable $e) {
            error_log('[ProductController::delete] ' . $e->getMessage());
            se_fail('Failed to delete product', 500);
        }
    }

    // ── GET /api/vendor/products ──────────────────────────────
    public function vendorList(): void
    {
        $auth   = AuthMiddleware::requireRole(ROLE_VENDOR_SHOPPING, ROLE_ADMIN);
        $userId = $auth['user_id'];

        $vendorId = $this->resolveVendorId($userId);
        if (!$vendorId) {
            se_fail('Vendor account required', 403);
            return;
        }

        try {
            $page   = max(1, (int)($_GET['page']  ?? 1));
            $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $status = isset($_GET['status']) ? trim($_GET['status']) : null;

            $where = ['p.vendor_id = :vid', 'p.deleted_at IS NULL'];
            $bind  = [':vid' => $vendorId];

            if ($status) {
                $allowedStatuses = ['draft', 'active', 'inactive', 'archived'];
                if (!in_array($status, $allowedStatuses, true)) {
                    se_fail('Invalid status filter', 422);
                    return;
                }
                $where[]         = 'p.status = :status';
                $bind[':status'] = $status;
            }
            $whereSQL = 'WHERE ' . implode(' AND ', $where);

            $cStmt = $this->db->prepare(
                "SELECT COUNT(*) FROM products p
                 LEFT JOIN inventory i ON i.product_id = p.id
                 $whereSQL"
            );
            $cStmt->execute($bind);
            $total = (int)$cStmt->fetchColumn();

            $stmt = $this->db->prepare("
                SELECT
                    p.id, p.name, p.price, p.sale_price, p.status,
                    p.is_featured, p.rating, p.total_sold, p.images, p.sku, p.created_at,
                    COALESCE(i.quantity, 0)                AS stock_quantity,
                    COALESCE(i.reserved, 0)                AS reserved_qty,
                    COALESCE(i.quantity - i.reserved, 0)   AS available_qty,
                    COALESCE(i.low_stock_alert, 5)         AS low_stock_alert
                FROM products p
                LEFT JOIN inventory i ON i.product_id = p.id
                $whereSQL
                ORDER BY p.created_at DESC
                LIMIT :lim OFFSET :off
            ");
            foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $products = array_map(function ($r) {
                $imgs  = $r['images'] ? json_decode($r['images'], true) : [];
                $thumb = null;
                if (is_array($imgs) && !empty($imgs)) {
                    $thumb = is_string($imgs[0]) ? $imgs[0] : ($imgs[0]['url'] ?? null);
                }
                $avail = (int)$r['available_qty'];
                return [
                    'id'              => (int)$r['id'],
                    'name'            => $r['name'],
                    'price'           => (float)$r['price'],
                    'sale_price'      => $r['sale_price'] !== null ? (float)$r['sale_price'] : null,
                    'status'          => $r['status'],
                    'is_featured'     => (bool)$r['is_featured'],
                    'rating'          => $r['rating'] !== null ? (float)$r['rating'] : null,
                    'total_sold'      => (int)$r['total_sold'],
                    'sku'             => $r['sku'],
                    'image'           => $thumb,
                    'stock_quantity'  => (int)$r['stock_quantity'],
                    'reserved_qty'    => (int)$r['reserved_qty'],
                    'available_qty'   => $avail,
                    'is_low_stock'    => $avail <= (int)$r['low_stock_alert'],
                    'created_at'      => $r['created_at'],
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));

            se_ok([
                'products'   => $products,
                'pagination' => se_paginate($total, $page, $limit),
            ]);

        } catch (\Throwable $e) {
            error_log('[ProductController::vendorList] ' . $e->getMessage());
            se_fail('Failed to load vendor products', 500);
        }
    }

    // ── GET /api/product/{id} ─────────────────────────────────
    public function getProductWithVariations(int $id): void
    {
        try {
            $product = $this->service->detail($id);
            if (!$product) {
                se_fail('Product not found', 404);
                return;
            }

            $stmt = $this->db->prepare("
                SELECT sku, attributes, price, compare_price, stock_quantity, is_active
                FROM product_variations
                WHERE product_id = :pid AND is_active = 1
            ");
            $stmt->execute([':pid' => $id]);
            $variations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode attributes JSON
            foreach ($variations as &$v) {
                $v['attributes'] = json_decode($v['attributes'], true);
                $v['price'] = (float)$v['price'];
                $v['compare_price'] = $v['compare_price'] !== null ? (float)$v['compare_price'] : null;
                $v['stock_quantity'] = (int)$v['stock_quantity'];
                $v['is_active'] = (bool)$v['is_active'];
            }
            unset($v);

            $product['variations'] = $variations;

            se_ok($product);
        } catch (\Throwable $e) {
            error_log('[ProductController::getProductWithVariations] ' . $e->getMessage());
            se_fail('Failed to load product with variations', 500);
        }
    }

    // ── PUT /api/vendor/product/variation/{id}/stock ──────────
    public function updateVariationStock(int $variationId): void
    {
        $auth   = AuthMiddleware::requireRole(ROLE_VENDOR_SHOPPING, ROLE_ADMIN);
        $userId = $auth['user_id'];
        $body   = se_json_body();

        $vendorId = $this->resolveVendorId($userId);
        if (!$vendorId) {
            se_fail('Vendor account required', 403);
            return;
        }

        if (!isset($body['stock_quantity'])) {
            se_fail('stock_quantity is required', 422);
            return;
        }

        $newStock = max(0, (int)$body['stock_quantity']);

        try {
            $this->db->beginTransaction();

            // 1. Verify variation belongs to a product owned by this vendor
            // 2. SELECT FOR UPDATE on product_variations row (race condition prevention)
            $stmt = $this->db->prepare("
                SELECT pv.id
                FROM product_variations pv
                JOIN products p ON p.id = pv.product_id
                WHERE pv.id = :vid AND p.vendor_id = :vendor_id AND p.deleted_at IS NULL
                FOR UPDATE
            ");
            $stmt->execute([':vid' => $variationId, ':vendor_id' => $vendorId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                se_fail('Variation not found or access denied', 404);
                return;
            }

            // 3. UPDATE stock_quantity
            $upd = $this->db->prepare("UPDATE product_variations SET stock_quantity = :stock WHERE id = :vid");
            $upd->execute([':stock' => $newStock, ':vid' => $variationId]);

            $this->db->commit();

            // 4. Return success
            se_ok([
                'success' => true,
                'variation_id' => $variationId,
                'new_stock_quantity' => $newStock
            ]);
        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable $rb) {}
            error_log('[ProductController::updateVariationStock] ' . $e->getMessage());
            se_fail('Failed to update variation stock', 500);
        }
    }

    // ── Private helpers ───────────────────────────────────────

    /**
     * SECURITY: Resolve vendor from authenticated user ONLY.
     * Never accept a vendor_id from user input.
     */
    private function resolveVendorId(int $userId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM vendors WHERE user_id = :uid AND status = 'active' LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    private function makeSlug(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        $base = $slug;
        $i    = 1;
        $max  = 20;
        while ($max-- > 0) {
            $stmt = $this->db->prepare("SELECT id FROM products WHERE slug = :slug LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            if (!$stmt->fetchColumn()) break;
            $slug = $base . '-' . $i++;
        }
        return $slug ?: 'product-' . uniqid();
    }
}
