<?php
// ============================================================================
//  /api/shop/* — Customer storefront API (Commerce Phase 4)
// ----------------------------------------------------------------------------
//  A clean, commerce-scoped ADAPTER that powers the customer shopping storefront
//  by REUSING the production-grade, never-touch engine services as-is:
//    • ProductService  (catalog reads / formatting)
//    • CartService     (cart get/add/update/remove)
//    • OrderService    (multi-vendor split, inventory locking, COD checkout)
//  It instantiates those classes and calls their public methods — it does NOT
//  edit or fork them. On top, it adds the two things the engine can't: CITY
//  scoping and the DUAL-STORE (company-first) browse the storefront needs.
//
//  Dispatched from heart/index.php on prefix /api/shop. Uses the front-controller
//  globals $db (PDO), $method, $uri, and the AuthMiddleware / Response /
//  CityResolver helpers. Browse is PUBLIC (AuthMiddleware::optional); cart /
//  checkout / orders require a customer token (ROLE_CUSTOMER/ROLE_ADMIN), exactly
//  like the never-touch OrderController.
//
//  CITY IS INVISIBLE: resolved from the logged-in user (CityResolver), else the
//  active default (Haldwani) — the customer never picks a city, same as service.
//
//  MONEY-SAFETY: checkout is COD and goes entirely through OrderService::create
//  (unchanged) — multi-vendor split + FOR UPDATE inventory locks + the Phase-2
//  store/city alignment triggers + the LedgerEngine reference_type='order'
//  collision fix all apply untouched. Online payment is intentionally NOT enabled
//  here (the engine COD-locks it via one constant) — see the storefront doc §5.
//
//  AI-READY: the browse response carries score inputs (rating, total_sold,
//  is_featured, store priority) so a future feed/personalizer can rank without a
//  schema or contract change.
// ============================================================================

require_once SYSTEM_ROOT . '/body/shopping-engine/api/products/ProductService.php';
require_once SYSTEM_ROOT . '/body/shopping-engine/api/cart/CartService.php';
require_once SYSTEM_ROOT . '/body/shopping-engine/api/orders/OrderService.php';
require_once SYSTEM_ROOT . '/core/helpers/CityResolver.php';
require_once SYSTEM_ROOT . '/core/helpers/RateLimiter.php';
require_once SYSTEM_ROOT . '/lib/commerce/OrderabilityResolver.php'; // S2: 4-layer orderability + ON/OFF/BUSY gate
require_once SYSTEM_ROOT . '/lib/commerce/RoutingEngine.php';        // S3: deterministic routing + signal capture
require_once SYSTEM_ROOT . '/lib/commerce/CommerceEventBus.php';     // S4: event bus / timeline / flags / notifications

// M5 (audit §4): per-user spam/brute guard. Reject with 429 when tripped.
$shopRateLimit = function (string $action, int $max, int $windowSec) {
    if (!RateLimiter::check($action, $max, $windowSec)) {
        Response::error('Too many requests. Please slow down and try again.', 429);
        exit;
    }
};

// ── City resolution (invisible to the customer) ─────────────────────────────
$shopAuth = AuthMiddleware::optional();
$shopUserId = $shopAuth['user_id'] ?? null;
$shopCityId = $shopUserId
    ? CityResolver::resolveFromUser($db, (int)$shopUserId)
    : CityResolver::resolveId($db, null);

// ── Small helpers ────────────────────────────────────────────────────────────
$shopRequireUser = function () {
    $auth = AuthMiddleware::requireRole(ROLE_CUSTOMER, ROLE_ADMIN);
    return (int)$auth['user_id'];
};
$shopBody = function () {
    return json_decode(file_get_contents('php://input'), true) ?? [];
};
// M2 (audit §4): a product is orderable only if its store is in the customer's
// city. Returns true when allowed — allows a store with no city set (legacy /
// geo-less), rejects only an explicit cross-city mismatch. Server-side guard so
// a crafted request can't cart/checkout across cities (browse is already scoped).
$shopAssertCity = function (int $productId, int $cityId) use ($db) {
    $s = $db->prepare("SELECT st.city_id FROM products p LEFT JOIN stores st ON st.id = p.store_id WHERE p.id = ? LIMIT 1");
    $s->execute([$productId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return !($r && $r['city_id'] !== null && (int)$r['city_id'] !== $cityId);
};

try {

    // ── GET /api/shop/meta — city + filters (public) ─────────────────────────
    if ($method === 'GET' && $uri === '/api/shop/meta') {
        $city = null;
        $cst = $db->prepare("SELECT id, name FROM cities WHERE id = ? LIMIT 1");
        $cst->execute([$shopCityId]);
        $city = $cst->fetch(PDO::FETCH_ASSOC) ?: null;
        $types = $db->query("SELECT id, name, slug FROM store_types WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        $cats  = $db->query("SELECT id, name FROM categories WHERE (type='product' OR type='both') AND (is_active = 1 OR is_active IS NULL) ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        Response::success(['city' => $city, 'store_types' => $types, 'categories' => $cats]);
        exit;
    }

    // ── GET /api/shop/stores — city-scoped, company-first (public) ───────────
    if ($method === 'GET' && $uri === '/api/shop/stores') {
        if (!OrderabilityResolver::commerceEnabled($db)) { Response::success(['stores' => [], 'city_id' => (int)$shopCityId]); exit; }
        // S2: only show stores whose seller is available (ON + not BUSY); company stores always.
        $where = ["s.status = 'active'", 's.deleted_at IS NULL', 's.city_id = :city', OrderabilityResolver::sellerAvailableSql('s', 'vo')];
        $bind = [':city' => $shopCityId];
        if (isset($_GET['store_type_id']) && $_GET['store_type_id'] !== '') { $where[] = 's.store_type_id = :t'; $bind[':t'] = (int)$_GET['store_type_id']; }
        $sql = "SELECT s.id, s.name, s.slug, s.ownership, s.locality, s.store_type_id, s.priority,
                       t.name AS type_name,
                       (SELECT public_url FROM media WHERE id = s.logo_media_id) AS logo_url,
                       (SELECT COUNT(*) FROM products p JOIN inventory i ON i.product_id=p.id
                          WHERE p.store_id = s.id AND p.status='active' AND p.deleted_at IS NULL
                            AND ((i.quantity - i.reserved) > 0 OR i.allow_backorder = 1)) AS product_count
                  FROM stores s
                  LEFT JOIN store_types t ON t.id = s.store_type_id
                  LEFT JOIN vendors vo ON vo.id = s.owner_vendor_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY (s.ownership='company') DESC, s.priority DESC, s.is_featured DESC, s.name ASC";
        $stmt = $db->prepare($sql); $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id']=(int)$r['id']; $r['product_count']=(int)$r['product_count']; $r['is_company']=($r['ownership']==='company'); }
        Response::success(['stores' => $rows, 'city_id' => (int)$shopCityId]);
        exit;
    }

    // ── GET /api/shop/products — city-scoped, dual-store, company-first ──────
    if ($method === 'GET' && $uri === '/api/shop/products') {
        if (!OrderabilityResolver::commerceEnabled($db)) { Response::success(['products' => [], 'page' => 1, 'city_id' => (int)$shopCityId]); exit; }
        $productService = new ProductService($db);
        // S2: seller-availability gate (ON + not BUSY; company always) added to the browse predicate.
        $where = ["p.status = 'active'", 'p.deleted_at IS NULL', "s.status = 'active'", 's.deleted_at IS NULL', 's.city_id = :city',
                  '((i.quantity - i.reserved) > 0 OR i.allow_backorder = 1)', OrderabilityResolver::sellerAvailableSql('s', 'v')];
        $bind = [':city' => $shopCityId];
        if (isset($_GET['store_id']) && $_GET['store_id'] !== '')     { $where[]='p.store_id = :store';  $bind[':store']=(int)$_GET['store_id']; }
        if (isset($_GET['category_id']) && $_GET['category_id'] !== ''){ $where[]='p.category_id = :cat'; $bind[':cat']=(int)$_GET['category_id']; }
        if (isset($_GET['search']) && trim((string)$_GET['search'])!==''){ $where[]='(p.name LIKE :q OR p.short_desc LIKE :q)'; $bind[':q']='%'.trim($_GET['search']).'%'; }
        $page = max(1, (int)($_GET['page'] ?? 1)); $limit = min(50, max(1, (int)($_GET['limit'] ?? 20))); $offset = ($page-1)*$limit;
        $order = "ORDER BY (s.ownership='company') DESC, s.priority DESC, p.is_featured DESC, p.rating DESC, p.total_sold DESC";
        $sql = "SELECT p.id, p.uuid, p.name, p.short_desc, p.price, p.sale_price, p.images, p.category_id,
                       p.vendor_id, p.is_featured, p.rating, p.total_reviews, p.total_sold, p.status, p.unit,
                       (i.quantity - i.reserved) AS available_qty, i.allow_backorder,
                       p.store_id, s.name AS store_name, s.ownership, v.business_name AS vendor_name
                  FROM products p
                  JOIN inventory i ON i.product_id = p.id
                  JOIN stores s ON s.id = p.store_id
                  LEFT JOIN vendors v ON v.id = p.vendor_id
                 WHERE " . implode(' AND ', $where) . " $order LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql);
        foreach ($bind as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $products = array_map(function($row) use ($productService) {
            $p = $productService->formatProduct($row);          // REUSE engine formatting
            $p['store_id']   = (int)$row['store_id'];
            $p['store_name'] = $row['store_name'];
            $p['is_company'] = ($row['ownership'] === 'company');
            return $p;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        Response::success(['products' => $products, 'page' => $page, 'city_id' => (int)$shopCityId]);
        exit;
    }

    // ── GET /api/shop/products/{id} — detail (REUSE ProductService::detail) ──
    if ($method === 'GET' && preg_match('#^/api/shop/products/(\d+)$#', $uri, $m)) {
        $productService = new ProductService($db);
        $product = $productService->detail((int)$m[1]);          // REUSE engine detail
        if (!$product) { Response::notFound('Product'); exit; }
        // Augment with the store (city/dual-store context) — the engine detail lacks it.
        $sst = $db->prepare(
            "SELECT s.id, s.name, s.ownership, s.locality, s.city_id, s.status,
                    (SELECT public_url FROM media WHERE id = s.logo_media_id) AS logo_url
               FROM products p JOIN stores s ON s.id = p.store_id WHERE p.id = ? LIMIT 1"
        );
        $sst->execute([(int)$m[1]]);
        $store = $sst->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($store) { $store['id']=(int)$store['id']; $store['is_company']=($store['ownership']==='company'); }
        $product['store'] = $store;
        Response::success(['product' => $product]);
        exit;
    }

    // ── GET /api/shop/cart — REUSE CartService::get ─────────────────────────
    if ($method === 'GET' && $uri === '/api/shop/cart') {
        $uid = $shopRequireUser();
        Response::success((new CartService($db))->get($uid));
        exit;
    }

    // ── GET /api/shop/delivery-quote — fee by distance (store → city) ────────
    // M1 fix (audit §4): the fee is server-computed (store → the city's known
    // drop point), NOT from client coords — so the shown estimate equals the
    // tamper-proof billed fee. Client drop coords are ignored here.
    if ($method === 'GET' && $uri === '/api/shop/delivery-quote') {
        $uid = $shopRequireUser();
        $shopRateLimit('shop_quote_' . $uid, 30, 60);
        require_once SYSTEM_ROOT . '/core/delivery/DeliveryEngine.php';
        $cart = (new CartService($db))->get($uid);
        $subtotal = (float)($cart['subtotal'] ?? 0);
        $pLat = null; $pLng = null; $cityId = (int)$shopCityId;
        if (!empty($cart['items'])) {
            $s = $db->prepare("SELECT st.lat, st.lng, st.city_id FROM products p JOIN stores st ON st.id = p.store_id WHERE p.id = ? LIMIT 1");
            $s->execute([(int)$cart['items'][0]['product_id']]);
            if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
                $pLat = $row['lat'] !== null ? (float)$row['lat'] : null;
                $pLng = $row['lng'] !== null ? (float)$row['lng'] : null;
                $cityId = $row['city_id'] ? (int)$row['city_id'] : (int)$shopCityId;
            }
        }
        Response::success(DeliveryEngine::quoteServerSide($db, $pLat, $pLng, $cityId, $subtotal));
        exit;
    }

    // ── POST /api/shop/cart — add or set quantity (REUSE CartService) ───────
    if ($method === 'POST' && $uri === '/api/shop/cart') {
        $uid = $shopRequireUser(); $b = $shopBody();
        $shopRateLimit('shop_cart_' . $uid, 60, 60);
        $pid = (int)($b['product_id'] ?? 0); $qty = (int)($b['quantity'] ?? 1);
        $op  = $b['op'] ?? 'add';
        if ($pid <= 0) { Response::validation('product_id is required'); exit; }
        // S2: full 4-layer orderability gate (catalogue / seller ON-OFF-BUSY+city / stock / kill-switch).
        $ord = OrderabilityResolver::check($db, $pid, (int)$shopCityId);
        if (!$ord['orderable']) { Response::error(OrderabilityResolver::message($ord['reason']), 422); exit; }
        $cart = new CartService($db);
        try {
            $result = ($op === 'set') ? $cart->update($uid, $pid, $qty) : $cart->add($uid, $pid, max(1, $qty));
            Response::success($result);
        } catch (\Throwable $e) { Response::error($e->getMessage(), 400); }
        exit;
    }

    // ── POST /api/shop/cart/remove — REUSE CartService::remove ──────────────
    if ($method === 'POST' && $uri === '/api/shop/cart/remove') {
        $uid = $shopRequireUser(); $b = $shopBody();
        $pid = isset($b['product_id']) ? (int)$b['product_id'] : null;
        $cid = isset($b['cart_item_id']) ? (int)$b['cart_item_id'] : null;
        try { Response::success((new CartService($db))->remove($uid, $pid, $cid)); }
        catch (\Throwable $e) { Response::error($e->getMessage(), 400); }
        exit;
    }

    // ── POST /api/shop/checkout — COD (REUSE OrderService::create) ──────────
    if ($method === 'POST' && $uri === '/api/shop/checkout') {
        $uid = $shopRequireUser(); $b = $shopBody();
        $shopRateLimit('shop_checkout_' . $uid, 10, 60);
        // Force COD here (the engine COD-locks online; enabling online is a
        // surfaced engine decision — see commerce-06 §5). Everything else — the
        // multi-vendor split, inventory locking, alignment, ledger safety — is
        // the engine's, unchanged.
        $b['payment_method'] = 'cod';
        $cart  = new CartService($db);
        // M2 (audit §4): reject checkout if the cart holds an item from a store
        // outside the customer's city (defence against crafted cross-city carts).
        $cartNow = $cart->get($uid);
        foreach (($cartNow['items'] ?? []) as $it) {
            // S2: re-run the full orderability gate at checkout (seller may have gone OFF/BUSY,
            // stock may be gone, commerce may be paused — not just a cross-city guard).
            $ord = OrderabilityResolver::check($db, (int)$it['product_id'], (int)$shopCityId);
            if (!$ord['orderable']) {
                Response::error('An item in your cart is no longer available (' . $ord['reason'] . '). Remove it to continue.', 422);
                exit;
            }
        }
        $order = new OrderService($db, $cart);
        try {
            $result = $order->create($uid, $b);                  // REUSE engine checkout
            // Order placed → hand to the NATIVE delivery engine (create + price by
            // distance + auto-assign the nearest rider). Non-fatal: a delivery
            // hiccup never fails a placed order.
            try {
                require_once SYSTEM_ROOT . '/core/delivery/DeliveryEngine.php';
                $dLat = isset($b['drop_lat']) && $b['drop_lat'] !== '' ? (float)$b['drop_lat'] : null;
                $dLng = isset($b['drop_lng']) && $b['drop_lng'] !== '' ? (float)$b['drop_lng'] : null;
                $result['delivery'] = DeliveryEngine::createForOrder($db, (int)$result['order_id'], $dLat, $dLng, (float)($result['subtotal'] ?? 0));
            } catch (\Throwable $de) { error_log('[shop/checkout] delivery handoff: ' . $de->getMessage()); }
            // S3: mark sub-orders OFFERED + capture routing signals (deterministic
            // routing lifecycle; non-fatal — a routing hiccup never fails a placed order).
            try {
                $dLat = isset($b['drop_lat']) && $b['drop_lat'] !== '' ? (float)$b['drop_lat'] : null;
                $dLng = isset($b['drop_lng']) && $b['drop_lng'] !== '' ? (float)$b['drop_lng'] : null;
                // S4: open the order timeline (+ high-value hold flag + notifications) then route.
                CommerceEventBus::emit($db, 'order_created', ['order_id' => (int)$result['order_id'], 'actor_type' => 'customer', 'actor_id' => $uid, 'to_state' => 'pending']);
                RoutingEngine::onOrderPlaced($db, (int)$result['order_id'], $dLat, $dLng);
            } catch (\Throwable $re) { error_log('[shop/checkout] routing/events: ' . $re->getMessage()); }
            Response::success($result, 201, 'Order placed');
        } catch (\InvalidArgumentException $e) { Response::error($e->getMessage(), 422); }
        catch (\Throwable $e) { Response::error($e->getMessage(), 400); }
        exit;
    }

    // ── GET /api/shop/orders — REUSE OrderService::list ─────────────────────
    if ($method === 'GET' && $uri === '/api/shop/orders') {
        $uid = $shopRequireUser();
        $cart = new CartService($db);
        Response::success((new OrderService($db, $cart))->list($uid, $_GET));
        exit;
    }

    // ── GET /api/shop/orders/{id} — REUSE OrderService::detail ──────────────
    if ($method === 'GET' && preg_match('#^/api/shop/orders/(\d+)$#', $uri, $m)) {
        $uid = $shopRequireUser();
        $cart = new CartService($db);
        $order = (new OrderService($db, $cart))->detail($uid, (int)$m[1]);
        if (!$order) { Response::notFound('Order'); exit; }
        Response::success(['order' => $order]);
        exit;
    }

    // ── POST /api/shop/orders/{id}/cancel — REUSE OrderService::cancel ──────
    if ($method === 'POST' && preg_match('#^/api/shop/orders/(\d+)/cancel$#', $uri, $m)) {
        $uid = $shopRequireUser();
        $cart = new CartService($db);
        try {
            $res = (new OrderService($db, $cart))->cancel($uid, (int)$m[1]);
            // M3 (audit §4): cancel the order's active delivery + free the rider
            // (the never-touch cancel is delivery-unaware). The lifecycle cron is
            // the safety net for admin/vendor cancel paths.
            require_once SYSTEM_ROOT . '/core/delivery/DeliveryEngine.php';
            DeliveryEngine::cancelForOrder($db, (int)$m[1]);
            Response::success($res);
        } catch (\Throwable $e) { Response::error($e->getMessage(), 400); }
        exit;
    }

} catch (\Throwable $e) {
    // Commerce tables missing on this environment, or an unexpected engine error.
    error_log('[api/shop] ' . $e->getMessage());
    Response::error('Store is not available right now.', 503);
    exit;
}

Response::notFound('Shop endpoint');
