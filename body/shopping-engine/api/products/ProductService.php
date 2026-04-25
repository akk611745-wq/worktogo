<?php
// ─────────────────────────────────────────────────────────────
//  WorkToGo — ProductService  (Production v3.0)
//
//  v3.0 changes:
//    [1] detail(): vendor phone removed from public response (PII)
//    [2] detail(): v_phone removed from SELECT query
//    [3] Search / available formula unchanged from v2.0
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../helpers/shopping.helpers.php';

class ProductService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────
    //  LIST  GET /api/products
    // ─────────────────────────────────────────────
    public function list(array $params): array
    {
        $page       = max(1, (int)($params['page']        ?? 1));
        $limit      = min(50, max(1, (int)($params['limit']      ?? 20)));
        $offset     = ($page - 1) * $limit;
        $categoryId = isset($params['category_id']) ? (int)$params['category_id'] : null;
        $vendorId   = isset($params['vendor_id'])   ? (int)$params['vendor_id']   : null;
        $featured   = isset($params['featured'])    ? (bool)(int)$params['featured'] : null;
        $search     = isset($params['search'])      ? trim($params['search'])       : null;
        $sortBy     = isset($params['sort'])        ? trim($params['sort'])         : 'default';

        $where = [
            "p.status = 'active'",
            'p.deleted_at IS NULL',
            '(i.quantity - i.reserved) > 0',
        ];
        $bind = [];

        if ($categoryId !== null) {
            $where[]              = 'p.category_id = :category_id';
            $bind[':category_id'] = $categoryId;
        }
        if ($vendorId !== null) {
            $where[]            = 'p.vendor_id = :vendor_id';
            $bind[':vendor_id'] = $vendorId;
        }
        if ($featured !== null) {
            $where[]          = 'p.is_featured = :featured';
            $bind[':featured'] = (int)$featured;
        }
        if ($search !== null && $search !== '') {
            if (strlen($search) >= 3) {
                $where[]         = 'MATCH(p.name, p.description) AGAINST(:search IN BOOLEAN MODE)';
                $bind[':search'] = $search;
            } else {
                $where[]              = '(p.name LIKE :search_like OR p.short_desc LIKE :search_like)';
                $bind[':search_like'] = '%' . $search . '%';
            }
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $orderSQL = match ($sortBy) {
            'price_asc'  => 'ORDER BY COALESCE(p.sale_price, p.price) ASC',
            'price_desc' => 'ORDER BY COALESCE(p.sale_price, p.price) DESC',
            'rating'     => 'ORDER BY p.rating DESC, p.total_reviews DESC',
            'newest'     => 'ORDER BY p.created_at DESC',
            'popular'    => 'ORDER BY p.total_sold DESC',
            default      => ($search && strlen($search) >= 3)
                ? 'ORDER BY MATCH(p.name, p.description) AGAINST(:search_order IN BOOLEAN MODE) DESC, p.is_featured DESC'
                : 'ORDER BY p.is_featured DESC, p.rating DESC',
        };

        try {
            $countSQL = "
                SELECT COUNT(*)
                FROM products p
                JOIN inventory i ON i.product_id = p.id
                $whereSQL
            ";
            $countStmt = $this->db->prepare($countSQL);
            $countStmt->execute($bind);
            $total = (int)$countStmt->fetchColumn();

            $sql = "
                SELECT
                    p.id,
                    p.uuid,
                    p.name,
                    p.short_desc,
                    p.price,
                    p.sale_price,
                    p.images,
                    p.category_id,
                    p.vendor_id,
                    p.is_featured,
                    p.rating,
                    p.total_reviews,
                    p.total_sold,
                    p.status,
                    p.unit,
                    (i.quantity - i.reserved) AS available_qty,
                    i.quantity                AS stock_quantity,
                    i.reserved                AS reserved_qty,
                    i.low_stock_alert,
                    v.business_name           AS vendor_name
                FROM products p
                JOIN inventory i ON i.product_id = p.id
                LEFT JOIN vendors v ON v.id = p.vendor_id
                $whereSQL
                $orderSQL
                LIMIT :limit OFFSET :offset
            ";

            $fetchBind = $bind;
            if ($search && strlen($search) >= 3 && $sortBy === 'default') {
                $fetchBind[':search_order'] = $search;
            }

            $stmt = $this->db->prepare($sql);
            foreach ($fetchBind as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $products = array_map([$this, 'formatProduct'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        } catch (\Throwable $e) {
            error_log('[ProductService::list] ' . $e->getMessage());
            throw new \RuntimeException('Failed to load products');
        }

        return [
            'products'   => $products,
            'pagination' => se_paginate($total, $page, $limit),
        ];
    }

    // ─────────────────────────────────────────────
    //  DETAIL  GET /api/products/{id}
    //
    //  v3.0: vendor.phone removed — PII, not needed in public API
    // ─────────────────────────────────────────────
    public function detail(int $id): ?array
    {
        try {
            $sql = "
                SELECT
                    p.id,
                    p.uuid,
                    p.name,
                    p.description,
                    p.short_desc,
                    p.price,
                    p.sale_price,
                    p.images,
                    p.images_json,
                    p.sku,
                    p.weight,
                    p.unit,
                    p.attributes,
                    p.tags,
                    p.category_id,
                    p.vendor_id,
                    p.is_featured,
                    p.rating,
                    p.total_reviews,
                    p.total_sold,
                    p.status,
                    (i.quantity - i.reserved) AS available_qty,
                    i.quantity                AS stock_quantity,
                    i.reserved                AS reserved_qty,
                    i.allow_backorder,
                    i.low_stock_alert,
                    v.id            AS v_id,
                    v.business_name AS v_name,
                    v.status        AS v_status
                FROM products p
                JOIN inventory i ON i.product_id = p.id
                LEFT JOIN vendors v ON v.id = p.vendor_id
                WHERE p.id = :id
                  AND p.status = 'active'
                  AND p.deleted_at IS NULL
                LIMIT 1
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            error_log('[ProductService::detail] ' . $e->getMessage());
            throw new \RuntimeException('Failed to load product');
        }

        if (!$row) {
            return null;
        }

        $product = $this->formatProduct($row);

        $product['description']     = $row['description'];
        $product['short_desc']      = $row['short_desc'];
        $product['sku']             = $row['sku'];
        $product['weight']          = $row['weight'] !== null ? (float)$row['weight'] : null;
        $product['unit']            = $row['unit'];
        $product['total_sold']      = (int)$row['total_sold'];
        $product['allow_backorder'] = (bool)$row['allow_backorder'];
        $product['low_stock_alert'] = (int)$row['low_stock_alert'];
        $product['is_low_stock']    = (int)$row['available_qty'] <= (int)$row['low_stock_alert'];

        $product['attributes'] = $row['attributes']
            ? (json_decode($row['attributes'], true) ?? [])
            : [];
        $product['tags'] = $row['tags']
            ? (json_decode($row['tags'], true) ?? [])
            : [];

        $product['images'] = $this->parseImages($row['images'] ?? null, $row['images_json'] ?? null);

        // v3.0: phone removed — vendor contact is PII and not needed publicly
        $product['vendor'] = [
            'id'     => (int)$row['v_id'],
            'name'   => $row['v_name'],
            'status' => $row['v_status'],
        ];

        return $product;
    }

    // ─────────────────────────────────────────────
    //  Normalise a DB row → API shape
    // ─────────────────────────────────────────────
    public function formatProduct(array $row): array
    {
        $price     = (float)$row['price'];
        $salePrice = (isset($row['sale_price']) && $row['sale_price'] !== null)
            ? (float)$row['sale_price']
            : null;
        $effective = ($salePrice !== null && $salePrice < $price) ? $salePrice : $price;

        $availableQty = (int)($row['available_qty'] ?? 0);
        $thumb        = $this->getThumb($row['images'] ?? null);

        return [
            'id'              => (int)$row['id'],
            'uuid'            => $row['uuid'] ?? null,
            'name'            => $row['name'],
            'price'           => $price,
            'sale_price'      => $salePrice,
            'effective_price' => $effective,
            'image'           => $thumb,
            'available_qty'   => $availableQty,
            'in_stock'        => $availableQty > 0,
            'category_id'     => $row['category_id'] ? (int)$row['category_id'] : null,
            'vendor_id'       => (int)$row['vendor_id'],
            'vendor_name'     => $row['vendor_name'] ?? null,
            'is_featured'     => (bool)($row['is_featured'] ?? false),
            'rating'          => $row['rating'] !== null ? (float)$row['rating'] : null,
            'total_reviews'   => (int)($row['total_reviews'] ?? 0),
            'unit'            => $row['unit'] ?? null,
        ];
    }

    private function parseImages(?string $imagesJson, ?string $legacyJson): array
    {
        if ($imagesJson) {
            $decoded = json_decode($imagesJson, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }
        if ($legacyJson) {
            $decoded = json_decode($legacyJson, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function getThumb(?string $imagesJson): ?string
    {
        if (!$imagesJson) {
            return null;
        }
        $arr = json_decode($imagesJson, true);
        if (is_array($arr) && !empty($arr)) {
            return is_string($arr[0]) ? $arr[0] : ($arr[0]['url'] ?? null);
        }
        return null;
    }
}
