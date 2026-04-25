<?php
// ─────────────────────────────────────────────
//  WorkToGo — Products Module Router  (Production v3.0)
// ─────────────────────────────────────────────
require_once __DIR__ . '/ProductController.php';

$ctrl = new ProductController($db);

// GET /api/products/categories
if ($method === 'GET' && $uri === '/api/products/categories') {
    try {
        $stmt = $db->prepare(
            "SELECT id, name, slug, parent_id, image_url, module
             FROM categories
             WHERE is_active = 1
             ORDER BY sort_order, name"
        );
        $stmt->execute();
        se_ok(['categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (\Throwable $e) {
        error_log('[products/categories] ' . $e->getMessage());
        se_fail('Failed to load categories', 500);
    }
    exit;
}

// GET /api/vendor/products
if ($method === 'GET' && $uri === '/api/vendor/products') {
    $ctrl->vendorList(); exit;
}

// POST /api/products
if ($method === 'POST' && $uri === '/api/products') {
    $ctrl->create(); exit;
}

// PUT /api/products/{id}
if ($method === 'PUT' && preg_match('#^/api/products/(\d+)$#', $uri, $m)) {
    $ctrl->update((int)$m[1]); exit;
}

// PUT /api/vendor/product/variation/{id}/stock
if ($method === 'PUT' && preg_match('#^/api/vendor/product/variation/(\d+)/stock$#', $uri, $m)) {
    $ctrl->updateVariationStock((int)$m[1]); exit;
}

// DELETE /api/products/{id}
if ($method === 'DELETE' && preg_match('#^/api/products/(\d+)$#', $uri, $m)) {
    $ctrl->delete((int)$m[1]); exit;
}

// GET /api/product/{id}
if ($method === 'GET' && preg_match('#^/api/product/(\d+)$#', $uri, $m)) {
    $ctrl->getProductWithVariations((int)$m[1]); exit;
}

// GET /api/products/{id}
if ($method === 'GET' && preg_match('#^/api/products/(\d+)$#', $uri, $m)) {
    $ctrl->show((int)$m[1]); exit;
}

// GET /api/products
if ($method === 'GET' && $uri === '/api/products') {
    $ctrl->index(); exit;
}

se_fail('Endpoint not found', 404);
