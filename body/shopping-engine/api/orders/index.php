<?php
// ─────────────────────────────────────────────
//  WorkToGo — Orders Module Router  (Production v3.0)
// ─────────────────────────────────────────────
require_once __DIR__ . '/OrderController.php';

$ctrl = new OrderController($db);

// POST /api/orders  OR  /api/order/create
if ($method === 'POST' && ($uri === '/api/orders' || $uri === '/api/order/create')) {
    $ctrl->create(); exit;
}

// POST /api/orders/{id}/cancel
if ($method === 'POST' && preg_match('#^/api/orders/(\d+)/cancel$#', $uri, $m)) {
    $ctrl->cancel((int)$m[1]); exit;
}

// PUT /api/vendor/orders/{id}/status
if ($method === 'PUT' && preg_match('#^/api/vendor/orders/(\d+)/status$#', $uri, $m)) {
    $ctrl->vendorUpdateStatus((int)$m[1]); exit;
}

// GET /api/vendor/orders
if ($method === 'GET' && $uri === '/api/vendor/orders') {
    $ctrl->vendorOrders(); exit;
}

// GET /api/orders
if ($method === 'GET' && $uri === '/api/orders') {
    $ctrl->list(); exit;
}

// GET /api/orders/{id}
if ($method === 'GET' && preg_match('#^/api/orders/(\d+)$#', $uri, $m)) {
    $ctrl->show((int)$m[1]); exit;
}

se_fail('Endpoint not found', 404);
