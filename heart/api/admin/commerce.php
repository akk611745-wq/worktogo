<?php
// ============================================================================
//  /api/admin/commerce/* — Commerce control-room endpoints (Phase 3)
// ----------------------------------------------------------------------------
//  A DEDICATED, self-contained commerce admin API so a separate commerce team
//  owns its own file (not buried in the 2900-line admin monolith). Included from
//  heart/api/admin/index.php with a single require_once AFTER the shared
//  $auth = AuthMiddleware::requireRole(ROLE_ADMIN) gate, so every route here is
//  admin-authenticated; each route additionally enforces a granular COMMERCE
//  permission (stores.*, store_types.manage, products.*, inventory.*) seeded by
//  migration 2026_08_22_001. Non-matching requests simply fall through to the
//  rest of the admin router (this file has no notFound fallthrough).
//
//  SCOPE (this task): STORE TYPES, STORES (dual model), PRODUCTS, INVENTORY —
//  full data-driven CRUD. Everything is a row: any shop type / product / category
//  is addable from the panel with ZERO code change. City-scoped and dual-store
//  aware throughout.
//
//  NEVER-TOUCH HONORED: the shopping-engine order/catalog code is NOT edited or
//  called. Product/inventory WRITES are a clean admin-side adapter (a focused
//  INSERT mirroring the engine's shape) that sets store_id/city_id EXPLICITLY
//  from the chosen store; the Phase-2 alignment triggers remain a safety net.
//  Product photos use the unified `media` table (owner_type='product') and are
//  mirrored into products.images (JSON) so the never-touch ProductService/catalog
//  renders them with no change.
//
//  MONEY-SAFETY: nothing here writes wallet_transactions / totals / ledger. Order
//  money stays exactly as wired in LedgerEngine (reference_type collision fix).
//
//  Uses shared scope from index.php: $db (PDO), $auth, $method, $uri, and the
//  Permission / Validator / Response / Logger / Media / UploadHelper helpers.
// ============================================================================

// ── Local helpers (guarded so re-include / name-collision is impossible) ─────
if (!function_exists('commerce_slug')) {
    function commerce_slug(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = preg_replace('/-+/', '-', (string)$s);
        return trim((string)$s, '-');
    }
}
if (!function_exists('commerce_unique_slug')) {
    // Ensure a slug is unique in $table.$col; append -2, -3… on collision.
    function commerce_unique_slug(PDO $db, string $table, string $col, string $base): string {
        $base = $base !== '' ? $base : 'item';
        $slug = $base; $n = 1;
        $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` = ?");
        while (true) {
            $stmt->execute([$slug]);
            if ((int)$stmt->fetchColumn() === 0) return $slug;
            $n++; $slug = $base . '-' . $n;
            if ($n > 50) return $base . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        }
    }
}
if (!function_exists('commerce_num_or_null')) {
    function commerce_num_or_null($v, bool $int = false) {
        if ($v === '' || $v === null) return null;
        return $int ? (int)$v : (float)$v;
    }
}
if (!function_exists('commerce_company_vendor_id')) {
    // Every store backs onto a vendor identity so its products satisfy the
    // NOT-NULL products.vendor_id and its earnings have a wallet. A COMPANY
    // dark-store uses ONE canonical company vendor (find-or-create, slug
    // 'worktogo-company', 0% commission — the house keeps 100% of its own sales).
    function commerce_company_vendor_id(PDO $db, int $adminUserId): int {
        $stmt = $db->prepare("SELECT id FROM vendors WHERE slug = 'worktogo-company' LIMIT 1");
        $stmt->execute();
        $id = (int) $stmt->fetchColumn();
        if ($id > 0) return $id;
        $db->prepare(
            "INSERT INTO vendors (user_id, business_name, slug, type, status, commission_rate)
             VALUES (?, 'WorkToGo Company', 'worktogo-company', 'shopping', 'active', 0)"
        )->execute([$adminUserId]);
        return (int) $db->lastInsertId();
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  META — dropdown data for the forms (one call powers every select)
// ════════════════════════════════════════════════════════════════════════════
if ($method === 'GET' && $uri === '/api/admin/commerce/meta') {
    Permission::enforce($db, $auth, 'stores.view');
    try {
        $types = $db->query("SELECT id, name, slug FROM store_types WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        $cities = $db->query("SELECT id, name, slug FROM cities WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        $cats  = $db->query("SELECT id, name FROM categories WHERE (type = 'product' OR type = 'both') AND (is_active = 1 OR is_active IS NULL) ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $vendors = $db->query("SELECT id, business_name FROM vendors WHERE type = 'shopping' AND status <> 'rejected' ORDER BY business_name")->fetchAll(PDO::FETCH_ASSOC);
        Response::success([
            'store_types' => $types,
            'cities'      => $cities,
            'categories'  => $cats,
            'vendors'     => $vendors,
        ]);
    } catch (PDOException $e) {
        Response::error('Commerce tables are not available yet — run the commerce migrations on this environment.', 503);
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  STORE TYPES — flexible, admin-addable shop-type taxonomy (zero code)
// ════════════════════════════════════════════════════════════════════════════

// GET /api/admin/commerce/store-types — all (incl. inactive) + store counts
if ($method === 'GET' && $uri === '/api/admin/commerce/store-types') {
    Permission::enforce($db, $auth, 'stores.view');
    try {
        $rows = $db->query(
            "SELECT t.id, t.name, t.slug, t.description, t.is_active, t.sort_order,
                    (SELECT COUNT(*) FROM stores s WHERE s.store_type_id = t.id) AS store_count
               FROM store_types t ORDER BY t.sort_order ASC, t.name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id']; $r['is_active'] = (int)$r['is_active'];
            $r['sort_order'] = (int)$r['sort_order']; $r['store_count'] = (int)$r['store_count'];
        }
        Response::success(['store_types' => $rows]);
    } catch (PDOException $e) {
        Response::error('store_types table is not available yet — run the commerce migrations.', 503);
    }
}

// POST /api/admin/commerce/store-types — add a shop type
if ($method === 'POST' && $uri === '/api/admin/commerce/store-types') {
    Permission::enforce($db, $auth, 'store_types.manage');
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $v = Validator::make($in, ['name' => 'required|string|max:120']);
    if ($v->fails()) Response::validation($v->firstError(), $v->errors());
    $name = trim((string)$in['name']);
    $slug = commerce_slug($in['slug'] ?? '' ?: $name);
    if ($slug === '') Response::validation('Could not derive a valid slug from the name');
    try {
        $slug = commerce_unique_slug($db, 'store_types', 'slug', $slug);
        $db->prepare(
            "INSERT INTO store_types (name, slug, description, default_commission_rate, is_active, sort_order)
             VALUES (:name, :slug, :desc, :comm, :active, :sort)"
        )->execute([
            ':name' => $name, ':slug' => $slug,
            ':desc' => trim((string)($in['description'] ?? '')) ?: null,
            ':comm' => commerce_num_or_null($in['default_commission_rate'] ?? null),
            ':active' => array_key_exists('is_active', $in) ? ($in['is_active'] ? 1 : 0) : 1,
            ':sort' => (int)($in['sort_order'] ?? 0),
        ]);
        $id = (int)$db->lastInsertId();
        Logger::info('Commerce: store type added', ['admin_id' => $auth['user_id'], 'store_type_id' => $id, 'slug' => $slug]);
        Response::success(['id' => $id, 'name' => $name, 'slug' => $slug], 201, 'Shop type added');
    } catch (PDOException $e) {
        Response::error('Could not add shop type.', 500);
    }
}

// PATCH /api/admin/commerce/store-types/{id} — edit / activate / deactivate
if ($method === 'PATCH' && preg_match('#^/api/admin/commerce/store-types/(\d+)$#', $uri, $m)) {
    Permission::enforce($db, $auth, 'store_types.manage');
    $id = (int)$m[1];
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $chk = $db->prepare("SELECT id FROM store_types WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) Response::notFound('Shop type');
        $set = []; $bind = [];
        if (array_key_exists('name', $in) && trim((string)$in['name']) !== '') { $set[] = 'name = ?'; $bind[] = trim((string)$in['name']); }
        if (array_key_exists('description', $in)) { $set[] = 'description = ?'; $bind[] = trim((string)$in['description']) ?: null; }
        if (array_key_exists('default_commission_rate', $in)) { $set[] = 'default_commission_rate = ?'; $bind[] = commerce_num_or_null($in['default_commission_rate']); }
        if (array_key_exists('is_active', $in)) { $set[] = 'is_active = ?'; $bind[] = $in['is_active'] ? 1 : 0; }
        if (array_key_exists('sort_order', $in)) { $set[] = 'sort_order = ?'; $bind[] = (int)$in['sort_order']; }
        if (!$set) Response::validation('Nothing to update');
        $set[] = 'updated_at = NOW()'; $bind[] = $id;
        $db->prepare("UPDATE store_types SET " . implode(', ', $set) . " WHERE id = ?")->execute($bind);
        Logger::info('Commerce: store type updated', ['admin_id' => $auth['user_id'], 'store_type_id' => $id]);
        Response::success(['id' => $id], 200, 'Shop type updated');
    } catch (PDOException $e) {
        Response::error('Could not update shop type.', 500);
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  STORES — dual model (company dark-store + local vendor store), city-scoped
// ════════════════════════════════════════════════════════════════════════════

// GET /api/admin/commerce/stores — list (filters: city_id, ownership, status, store_type_id)
if ($method === 'GET' && $uri === '/api/admin/commerce/stores') {
    Permission::enforce($db, $auth, 'stores.view');
    try {
        $where = ['s.deleted_at IS NULL']; $bind = [];
        foreach (['city_id' => 'city_id', 'store_type_id' => 'store_type_id'] as $q => $col) {
            if (isset($_GET[$q]) && $_GET[$q] !== '') { $where[] = "s.$col = ?"; $bind[] = (int)$_GET[$q]; }
        }
        if (isset($_GET['ownership']) && in_array($_GET['ownership'], ['company','vendor'], true)) { $where[] = 's.ownership = ?'; $bind[] = $_GET['ownership']; }
        if (isset($_GET['status']) && $_GET['status'] !== '') { $where[] = 's.status = ?'; $bind[] = $_GET['status']; }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $stmt = $db->prepare(
            "SELECT s.id, s.name, s.slug, s.ownership, s.status, s.city_id, s.store_type_id,
                    s.owner_vendor_id, s.priority, s.lat, s.lng, s.locality, s.logo_media_id,
                    t.name AS type_name, c.name AS city_name, v.business_name AS vendor_name,
                    (SELECT public_url FROM media WHERE id = s.logo_media_id) AS logo_url,
                    (SELECT COUNT(*) FROM products p WHERE p.store_id = s.id AND p.deleted_at IS NULL) AS product_count
               FROM stores s
               LEFT JOIN store_types t ON t.id = s.store_type_id
               LEFT JOIN cities c      ON c.id = s.city_id
               LEFT JOIN vendors v     ON v.id = s.owner_vendor_id
               $whereSql
              ORDER BY s.ownership ASC, s.priority DESC, s.name ASC"
        );
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            foreach (['id','city_id','store_type_id','owner_vendor_id','priority','logo_media_id','product_count'] as $k) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
        }
        Response::success(['stores' => $rows]);
    } catch (PDOException $e) {
        Response::error('stores table is not available yet — run the commerce migrations.', 503);
    }
}

// GET /api/admin/commerce/stores/{id} — detail
if ($method === 'GET' && preg_match('#^/api/admin/commerce/stores/(\d+)$#', $uri, $m)) {
    Permission::enforce($db, $auth, 'stores.view');
    $id = (int)$m[1];
    try {
        $stmt = $db->prepare(
            "SELECT s.*, (SELECT public_url FROM media WHERE id = s.logo_media_id) AS logo_url
               FROM stores s WHERE s.id = ? AND s.deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) Response::notFound('Store');
        Response::success(['store' => $row]);
    } catch (PDOException $e) {
        Response::error('stores table is not available yet — run the commerce migrations.', 503);
    }
}

// POST /api/admin/commerce/stores — create (company or vendor)
if ($method === 'POST' && $uri === '/api/admin/commerce/stores') {
    Permission::enforce($db, $auth, 'stores.manage');
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $v = Validator::make($in, ['name' => 'required|string|max:200']);
    if ($v->fails()) Response::validation($v->firstError(), $v->errors());
    $ownership = (($in['ownership'] ?? 'vendor') === 'company') ? 'company' : 'vendor';
    $ownerVendorId = ($ownership === 'vendor') ? (int)($in['owner_vendor_id'] ?? 0) : 0;
    if ($ownership === 'vendor' && $ownerVendorId <= 0) {
        Response::validation('A local vendor store needs an owner vendor. Pick the seller, or create it as a company dark-store.');
    }
    $name = trim((string)$in['name']);
    $slug = commerce_slug($in['slug'] ?? '' ?: $name);
    try {
        // Resolve the store's backing vendor identity + operator user.
        if ($ownership === 'vendor') {
            $vchk = $db->prepare("SELECT user_id FROM vendors WHERE id = ? AND type = 'shopping' LIMIT 1");
            $vchk->execute([$ownerVendorId]);
            $vrow = $vchk->fetch(PDO::FETCH_ASSOC);
            if (!$vrow) Response::validation('Selected vendor not found or is not a shopping vendor.');
            $bindVendorId = $ownerVendorId;
            $bindUserId   = (int)($vrow['user_id'] ?? 0) ?: null;
        } else {
            // Company dark-store → the canonical company vendor (find-or-create).
            $bindVendorId = commerce_company_vendor_id($db, (int)$auth['user_id']);
            $bindUserId   = (int)$auth['user_id'];
        }
        $slug = commerce_unique_slug($db, 'stores', 'slug', $slug ?: 'store');
        $db->prepare(
            "INSERT INTO stores
                (store_type_id, ownership, owner_vendor_id, owner_user_id, city_id, name, slug,
                 description, status, commission_rate, min_order_amount, service_radius_km,
                 lat, lng, address_line, locality, pincode, priority, is_featured, created_at, updated_at)
             VALUES
                (:type, :own, :vendor, :ouser, :city, :name, :slug,
                 :desc, :status, :comm, :minord, :radius,
                 :lat, :lng, :addr, :loc, :pin, :prio, :feat, NOW(), NOW())"
        )->execute([
            ':type'   => commerce_num_or_null($in['store_type_id'] ?? null, true),
            ':own'    => $ownership,
            ':vendor' => $bindVendorId ?: null,
            ':ouser'  => $bindUserId,
            ':city'   => commerce_num_or_null($in['city_id'] ?? null, true),
            ':name'   => $name, ':slug' => $slug,
            ':desc'   => trim((string)($in['description'] ?? '')) ?: null,
            ':status' => in_array($in['status'] ?? '', ['draft','pending','active','suspended','closed'], true) ? $in['status'] : 'active',
            ':comm'   => commerce_num_or_null($in['commission_rate'] ?? null),
            ':minord' => commerce_num_or_null($in['min_order_amount'] ?? null),
            ':radius' => commerce_num_or_null($in['service_radius_km'] ?? null),
            ':lat'    => commerce_num_or_null($in['lat'] ?? null),
            ':lng'    => commerce_num_or_null($in['lng'] ?? null),
            ':addr'   => trim((string)($in['address_line'] ?? '')) ?: null,
            ':loc'    => trim((string)($in['locality'] ?? '')) ?: null,
            ':pin'    => trim((string)($in['pincode'] ?? '')) ?: null,
            ':prio'   => (int)($in['priority'] ?? ($ownership === 'company' ? 100 : 0)),
            ':feat'   => !empty($in['is_featured']) ? 1 : 0,
        ]);
        $id = (int)$db->lastInsertId();
        Logger::info('Commerce: store created', ['admin_id' => $auth['user_id'], 'store_id' => $id, 'ownership' => $ownership]);
        Response::success(['id' => $id, 'name' => $name, 'slug' => $slug], 201, 'Store created');
    } catch (PDOException $e) {
        Response::error('Could not create store.', 500);
    }
}

// PATCH /api/admin/commerce/stores/{id} — edit / status
if ($method === 'PATCH' && preg_match('#^/api/admin/commerce/stores/(\d+)$#', $uri, $m)) {
    Permission::enforce($db, $auth, 'stores.manage');
    $id = (int)$m[1];
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $chk = $db->prepare("SELECT id FROM stores WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) Response::notFound('Store');
        $set = []; $bind = [];
        $text = ['name','description','address_line','locality','pincode'];
        foreach ($text as $f) if (array_key_exists($f, $in)) { $set[] = "$f = ?"; $bind[] = trim((string)$in[$f]) ?: null; }
        foreach (['store_type_id','city_id','priority'] as $f) if (array_key_exists($f, $in)) { $set[] = "$f = ?"; $bind[] = commerce_num_or_null($in[$f], true); }
        foreach (['commission_rate','min_order_amount','service_radius_km','lat','lng'] as $f) if (array_key_exists($f, $in)) { $set[] = "$f = ?"; $bind[] = commerce_num_or_null($in[$f]); }
        if (array_key_exists('status', $in) && in_array($in['status'], ['draft','pending','active','suspended','closed'], true)) { $set[] = 'status = ?'; $bind[] = $in['status']; }
        if (array_key_exists('is_featured', $in)) { $set[] = 'is_featured = ?'; $bind[] = $in['is_featured'] ? 1 : 0; }
        if (!$set) Response::validation('Nothing to update');
        $set[] = 'updated_at = NOW()'; $bind[] = $id;
        $db->prepare("UPDATE stores SET " . implode(', ', $set) . " WHERE id = ?")->execute($bind);
        Logger::info('Commerce: store updated', ['admin_id' => $auth['user_id'], 'store_id' => $id]);
        Response::success(['id' => $id], 200, 'Store updated');
    } catch (PDOException $e) {
        Response::error('Could not update store.', 500);
    }
}

// POST /api/admin/commerce/stores/{id}/logo — upload logo (multipart: field "logo")
if ($method === 'POST' && preg_match('#^/api/admin/commerce/stores/(\d+)/logo$#', $uri, $m)) {
    Permission::enforce($db, $auth, 'stores.manage');
    $id = (int)$m[1];
    if (empty($_FILES['logo']) || $_FILES['logo']['error'] === UPLOAD_ERR_NO_FILE) {
        Response::error('No file uploaded. Send the image as multipart field "logo".', 400);
    }
    require_once SYSTEM_ROOT . '/core/helpers/UploadHelper.php';
    require_once SYSTEM_ROOT . '/core/helpers/Media.php';
    $sStmt = $db->prepare("SELECT city_id, logo_media_id FROM stores WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $sStmt->execute([$id]);
    $store = $sStmt->fetch(PDO::FETCH_ASSOC);
    if (!$store) Response::notFound('Store');
    try {
        $url = UploadHelper::save($_FILES['logo'], 'stores');
    } catch (Exception $e) {
        Response::error($e->getMessage(), 400);
    }
    $mediaId = Media::add($db, [
        'owner_type' => 'store', 'owner_id' => $id, 'media_type' => 'image', 'purpose' => 'logo',
        'storage_provider' => 'local', 'storage_key' => $url, 'public_url' => $url,
        'city_id' => !empty($store['city_id']) ? (int)$store['city_id'] : null,
        'status' => Media::STATUS_APPROVED, 'uploaded_by_user_id' => (int)$auth['user_id'],
    ]);
    if ($mediaId > 0) {
        if (!empty($store['logo_media_id'])) Media::softDelete($db, (int)$store['logo_media_id']);
        $db->prepare("UPDATE stores SET logo_media_id = ?, updated_at = NOW() WHERE id = ?")->execute([$mediaId, $id]);
    }
    Logger::info('Commerce: store logo uploaded', ['admin_id' => $auth['user_id'], 'store_id' => $id, 'media_id' => $mediaId]);
    Response::success(['url' => $url, 'media_id' => $mediaId], 200, 'Logo updated');
}

// ════════════════════════════════════════════════════════════════════════════
//  PRODUCTS — admin catalog per store (all statuses). Clean adapter, not the
//  never-touch engine. store_id/city_id set explicitly from the chosen store.
// ════════════════════════════════════════════════════════════════════════════

// GET /api/admin/commerce/products — list (filters: store_id, city_id, status, search)
if ($method === 'GET' && $uri === '/api/admin/commerce/products') {
    Permission::enforce($db, $auth, 'products.view');
    try {
        $where = ['p.deleted_at IS NULL']; $bind = [];
        foreach (['store_id' => 'store_id', 'city_id' => 'city_id', 'category_id' => 'category_id'] as $q => $col) {
            if (isset($_GET[$q]) && $_GET[$q] !== '') { $where[] = "p.$col = ?"; $bind[] = (int)$_GET[$q]; }
        }
        if (isset($_GET['status']) && $_GET['status'] !== '') { $where[] = 'p.status = ?'; $bind[] = $_GET['status']; }
        if (isset($_GET['search']) && trim((string)$_GET['search']) !== '') { $where[] = '(p.name LIKE ? OR p.sku LIKE ?)'; $bind[] = '%'.trim($_GET['search']).'%'; $bind[] = '%'.trim($_GET['search']).'%'; }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
        $stmt = $db->prepare(
            "SELECT p.id, p.name, p.sku, p.price, p.sale_price, p.status, p.images,
                    p.store_id, p.city_id, p.category_id,
                    s.name AS store_name, c.name AS category_name,
                    i.quantity AS stock_qty, i.reserved AS reserved_qty, i.low_stock_alert, i.allow_backorder
               FROM products p
               LEFT JOIN stores s     ON s.id = p.store_id
               LEFT JOIN categories c ON c.id = p.category_id
               LEFT JOIN inventory i  ON i.product_id = p.id
               $whereSql
              ORDER BY p.updated_at DESC
              LIMIT $limit"
        );
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $imgs = $r['images'] ? json_decode($r['images'], true) : [];
            $r['thumb'] = (is_array($imgs) && !empty($imgs)) ? (is_string($imgs[0]) ? $imgs[0] : ($imgs[0]['url'] ?? null)) : null;
            unset($r['images']);
            foreach (['id','store_id','city_id','category_id','stock_qty','reserved_qty','low_stock_alert','allow_backorder'] as $k) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
            foreach (['price','sale_price'] as $k) $r[$k] = $r[$k] === null ? null : (float)$r[$k];
        }
        Response::success(['products' => $rows]);
    } catch (PDOException $e) {
        Response::error('products table is not available yet — run the commerce migrations.', 503);
    }
}

// GET /api/admin/commerce/products/{id} — detail
if ($method === 'GET' && preg_match('#^/api/admin/commerce/products/(\d+)$#', $uri, $m)) {
    Permission::enforce($db, $auth, 'products.view');
    $id = (int)$m[1];
    try {
        $stmt = $db->prepare(
            "SELECT p.*, i.quantity AS stock_qty, i.reserved AS reserved_qty, i.low_stock_alert,
                    i.allow_backorder, i.track_inventory
               FROM products p LEFT JOIN inventory i ON i.product_id = p.id
              WHERE p.id = ? AND p.deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) Response::notFound('Product');
        $row['images'] = $row['images'] ? (json_decode($row['images'], true) ?: []) : [];
        Response::success(['product' => $row]);
    } catch (PDOException $e) {
        Response::error('products table is not available yet — run the commerce migrations.', 503);
    }
}

// POST /api/admin/commerce/products — create product + inventory (store-scoped)
if ($method === 'POST' && $uri === '/api/admin/commerce/products') {
    Permission::enforce($db, $auth, 'products.manage');
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $v = Validator::make($in, ['name' => 'required|string|max:255', 'store_id' => 'required|integer', 'price' => 'required|numeric']);
    if ($v->fails()) Response::validation($v->firstError(), $v->errors());
    $storeId = (int)$in['store_id'];
    $price = (float)$in['price'];
    $sale = commerce_num_or_null($in['sale_price'] ?? null);
    if ($price < 0) Response::validation('Price must be >= 0');
    if ($sale !== null && $sale >= $price) Response::validation('Sale price must be less than price');
    try {
        // Store must exist → derive store's vendor_id + city_id (explicit alignment).
        $sStmt = $db->prepare("SELECT id, ownership, owner_vendor_id, city_id FROM stores WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $sStmt->execute([$storeId]);
        $store = $sStmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) Response::validation('Store not found — pick a valid store for this product.');
        // Every store backs onto a vendor (set at store-create). Use it so the
        // NOT-NULL products.vendor_id + the vendor-keyed ledger both have a home.
        // Safety net for any legacy company store missing it: ensure the company vendor.
        $vendorId = (int)($store['owner_vendor_id'] ?? 0);
        if ($vendorId <= 0 && ($store['ownership'] ?? '') === 'company') {
            $vendorId = commerce_company_vendor_id($db, (int)$auth['user_id']);
        }
        if ($vendorId <= 0) Response::validation('This store has no seller identity yet — re-save the store, then add products.');
        $name = trim((string)$in['name']);
        $slug = commerce_unique_slug($db, 'products', 'slug', commerce_slug($in['slug'] ?? '' ?: $name) ?: 'product');
        $status = in_array($in['status'] ?? '', ['draft','active','inactive'], true) ? $in['status'] : 'draft';
        $cityId = !empty($store['city_id']) ? (int)$store['city_id'] : null;

        $db->beginTransaction();
        $db->prepare(
            "INSERT INTO products
                (vendor_id, store_id, city_id, category_id, name, slug, description, short_desc,
                 sku, price, sale_price, cost_price, unit, images, status, created_at, updated_at)
             VALUES
                (:vendor, :store, :city, :cat, :name, :slug, :desc, :sdesc,
                 :sku, :price, :sale, :cost, :unit, :images, :status, NOW(), NOW())"
        )->execute([
            ':vendor' => $vendorId ?: null, ':store' => $storeId, ':city' => $cityId,
            ':cat'    => commerce_num_or_null($in['category_id'] ?? null, true),
            ':name'   => $name, ':slug' => $slug,
            ':desc'   => trim((string)($in['description'] ?? '')) ?: null,
            ':sdesc'  => trim((string)($in['short_desc'] ?? '')) ?: null,
            ':sku'    => trim((string)($in['sku'] ?? '')) ?: null,
            ':price'  => $price, ':sale' => $sale,
            ':cost'   => commerce_num_or_null($in['cost_price'] ?? null),
            ':unit'   => trim((string)($in['unit'] ?? '')) ?: null,
            ':images' => '[]',
            ':status' => $status,
        ]);
        $productId = (int)$db->lastInsertId();
        $qty = max(0, (int)($in['quantity'] ?? 0));
        $db->prepare(
            "INSERT INTO inventory (product_id, store_id, city_id, quantity, reserved, low_stock_alert, allow_backorder, track_inventory)
             VALUES (:pid, :store, :city, :qty, 0, :low, :back, 1)"
        )->execute([
            ':pid' => $productId, ':store' => $storeId, ':city' => $cityId, ':qty' => $qty,
            ':low' => (int)($in['low_stock_alert'] ?? 5),
            ':back' => !empty($in['allow_backorder']) ? 1 : 0,
        ]);
        $db->commit();
        Logger::info('Commerce: product created', ['admin_id' => $auth['user_id'], 'product_id' => $productId, 'store_id' => $storeId]);
        Response::success(['id' => $productId, 'slug' => $slug, 'status' => $status], 201, 'Product created');
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e->getCode() === '23000') Response::error('A product with this name or SKU already exists.', 409);
        Logger::error('Commerce product create failed', ['error' => $e->getMessage()]);
        Response::error('Could not create product.', 500);
    }
}

// PATCH /api/admin/commerce/products/{id} — edit (price, category, status, …)
if ($method === 'PATCH' && preg_match('#^/api/admin/commerce/products/(\d+)$#', $uri, $m)) {
    Permission::enforce($db, $auth, 'products.manage');
    $id = (int)$m[1];
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $chk = $db->prepare("SELECT price FROM products WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $chk->execute([$id]);
        $cur = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$cur) Response::notFound('Product');
        $set = []; $bind = [];
        foreach (['name','description','short_desc','sku','unit'] as $f) if (array_key_exists($f, $in)) { $set[] = "$f = ?"; $bind[] = trim((string)$in[$f]) ?: null; }
        if (array_key_exists('category_id', $in)) { $set[] = 'category_id = ?'; $bind[] = commerce_num_or_null($in['category_id'], true); }
        $newPrice = array_key_exists('price', $in) ? (float)$in['price'] : (float)$cur['price'];
        if (array_key_exists('price', $in)) { if ($newPrice < 0) Response::validation('Price must be >= 0'); $set[] = 'price = ?'; $bind[] = $newPrice; }
        if (array_key_exists('sale_price', $in)) {
            $sale = commerce_num_or_null($in['sale_price']);
            if ($sale !== null && $sale >= $newPrice) Response::validation('Sale price must be less than price');
            $set[] = 'sale_price = ?'; $bind[] = $sale;
        }
        foreach (['cost_price'] as $f) if (array_key_exists($f, $in)) { $set[] = "$f = ?"; $bind[] = commerce_num_or_null($in[$f]); }
        if (array_key_exists('status', $in) && in_array($in['status'], ['draft','active','inactive','archived'], true)) { $set[] = 'status = ?'; $bind[] = $in['status']; }
        if (array_key_exists('is_featured', $in)) { $set[] = 'is_featured = ?'; $bind[] = $in['is_featured'] ? 1 : 0; }
        if (!$set) Response::validation('Nothing to update');
        $set[] = 'updated_at = NOW()'; $bind[] = $id;
        $db->prepare("UPDATE products SET " . implode(', ', $set) . " WHERE id = ?")->execute($bind);
        Logger::info('Commerce: product updated', ['admin_id' => $auth['user_id'], 'product_id' => $id]);
        Response::success(['id' => $id], 200, 'Product updated');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') Response::error('A product with this name or SKU already exists.', 409);
        Response::error('Could not update product.', 500);
    }
}

// POST /api/admin/commerce/products/{id}/photo — upload photo (multipart: "photo")
//  media row (owner_type='product') + mirror URL into products.images JSON so the
//  never-touch ProductService/catalog renders it with no change.
if ($method === 'POST' && preg_match('#^/api/admin/commerce/products/(\d+)/photo$#', $uri, $m)) {
    Permission::enforce($db, $auth, 'products.manage');
    $id = (int)$m[1];
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        Response::error('No file uploaded. Send the image as multipart field "photo".', 400);
    }
    require_once SYSTEM_ROOT . '/core/helpers/UploadHelper.php';
    require_once SYSTEM_ROOT . '/core/helpers/Media.php';
    $pStmt = $db->prepare("SELECT city_id, images FROM products WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $pStmt->execute([$id]);
    $prod = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$prod) Response::notFound('Product');
    try {
        $url = UploadHelper::save($_FILES['photo'], 'products');
    } catch (Exception $e) {
        Response::error($e->getMessage(), 400);
    }
    $mediaId = Media::add($db, [
        'owner_type' => 'product', 'owner_id' => $id, 'media_type' => 'image', 'purpose' => 'gallery',
        'storage_provider' => 'local', 'storage_key' => $url, 'public_url' => $url,
        'city_id' => !empty($prod['city_id']) ? (int)$prod['city_id'] : null,
        'status' => Media::STATUS_APPROVED, 'uploaded_by_user_id' => (int)$auth['user_id'],
    ]);
    // Mirror into products.images (JSON array of URLs) for the never-touch reader.
    $imgs = $prod['images'] ? (json_decode($prod['images'], true) ?: []) : [];
    if (!is_array($imgs)) $imgs = [];
    $imgs[] = $url;
    $db->prepare("UPDATE products SET images = ?, updated_at = NOW() WHERE id = ?")
       ->execute([json_encode(array_values($imgs), JSON_UNESCAPED_SLASHES), $id]);
    Logger::info('Commerce: product photo uploaded', ['admin_id' => $auth['user_id'], 'product_id' => $id, 'media_id' => $mediaId]);
    Response::success(['url' => $url, 'media_id' => $mediaId, 'images' => $imgs], 200, 'Photo added');
}

// ════════════════════════════════════════════════════════════════════════════
//  INVENTORY — stock per product (grain unchanged: one row per product)
// ════════════════════════════════════════════════════════════════════════════
if ($method === 'PATCH' && preg_match('#^/api/admin/commerce/products/(\d+)/inventory$#', $uri, $m)) {
    Permission::enforce($db, $auth, 'inventory.manage');
    $id = (int)$m[1];
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $chk = $db->prepare("SELECT product_id FROM inventory WHERE product_id = ? LIMIT 1");
        $chk->execute([$id]);
        $exists = (bool)$chk->fetchColumn();
        $pchk = $db->prepare("SELECT store_id, city_id FROM products WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $pchk->execute([$id]);
        $prod = $pchk->fetch(PDO::FETCH_ASSOC);
        if (!$prod) Response::notFound('Product');
        if (!$exists) {
            $db->prepare("INSERT INTO inventory (product_id, store_id, city_id, quantity, reserved, track_inventory) VALUES (?, ?, ?, 0, 0, 1)")
               ->execute([$id, $prod['store_id'] ?: null, $prod['city_id'] ?: null]);
        }
        $set = []; $bind = [];
        if (array_key_exists('quantity', $in)) { $set[] = 'quantity = ?'; $bind[] = max(0, (int)$in['quantity']); }
        if (array_key_exists('low_stock_alert', $in)) { $set[] = 'low_stock_alert = ?'; $bind[] = max(0, (int)$in['low_stock_alert']); }
        if (array_key_exists('allow_backorder', $in)) { $set[] = 'allow_backorder = ?'; $bind[] = $in['allow_backorder'] ? 1 : 0; }
        if (array_key_exists('track_inventory', $in)) { $set[] = 'track_inventory = ?'; $bind[] = $in['track_inventory'] ? 1 : 0; }
        if (!$set) Response::validation('Nothing to update');
        $set[] = 'updated_at = NOW()'; $bind[] = $id;
        $db->prepare("UPDATE inventory SET " . implode(', ', $set) . " WHERE product_id = ?")->execute($bind);
        Logger::info('Commerce: inventory updated', ['admin_id' => $auth['user_id'], 'product_id' => $id]);
        Response::success(['product_id' => $id], 200, 'Stock updated');
    } catch (PDOException $e) {
        Response::error('Could not update inventory.', 500);
    }
}
