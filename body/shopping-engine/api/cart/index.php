<?php
// ─────────────────────────────────────────────
//  WorkToGo — Cart Module Router  (Production v3.0)
// ─────────────────────────────────────────────
require_once __DIR__ . '/CartController.php';

$ctrl = new CartController($db);

if ($method === 'GET'  && $uri === '/api/cart')        { $ctrl->get();    exit; }
if ($method === 'POST' && $uri === '/api/cart/add')    { $ctrl->add();    exit; }
if ($method === 'POST' && $uri === '/api/cart/remove') { $ctrl->remove(); exit; }
if ($method === 'POST' && $uri === '/api/cart/update') { $ctrl->update(); exit; }

se_fail('Endpoint not found', 404);
