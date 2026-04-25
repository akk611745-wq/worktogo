<?php
/**
 * WorkToGo — Shopping Engine Unified Entry Point
 */

require_once dirname(dirname(__DIR__)) . '/core/helpers/Database.php';
require_once dirname(dirname(__DIR__)) . '/core/helpers/Response.php';
require_once dirname(dirname(__DIR__)) . '/heart/middleware/AuthMiddleware.php';
require_once __DIR__ . '/helpers/shopping.helpers.php';

$db = getDB();

// Internal Heart Dispatch
if (defined('HEART_INTERNAL_INC')) {
    $payload = json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true);
    $intent  = $payload['intent'] ?? '';
    $data    = $payload['data'] ?? [];

    // Route based on intent
    switch ($intent) {
        case 'shopping:list_products':
        case 'shopping:search_products':
            require_once __DIR__ . '/api/products/index.php';
            $ctrl = new ProductController($db);
            $ctrl->index();
            break;

        case 'shopping:view_product':
            require_once __DIR__ . '/api/products/index.php';
            $ctrl = new ProductController($db);
            $ctrl->show((int)($data['id'] ?? 0));
            break;

        case 'shopping:add_to_cart':
            require_once __DIR__ . '/api/cart/index.php';
            $ctrl = new CartController($db);
            $ctrl->add();
            break;

        case 'shopping:create_order':
            require_once __DIR__ . '/api/orders/OrderController.php';
            $ctrl = new OrderController($db);
            $ctrl->create();
            break;

        case 'shopping:vendor_orders':
            require_once __DIR__ . '/api/orders/OrderController.php';
            $ctrl = new OrderController($db);
            $ctrl->vendorOrders();
            break;

        case 'shopping:vendor_update_order':
            require_once __DIR__ . '/api/orders/OrderController.php';
            $ctrl = new OrderController($db);
            // We need order ID. We can pass it in payload data
            $ctrl->vendorUpdateStatus((int)($data['id'] ?? 0));
            break;

        default:
            Response::success([
                'items' => [],
                'total' => 0,
                'message' => 'Shopping engine active.'
            ]);
    }
    exit;
}

// Fallback for direct access
http_response_code(403);
echo json_encode(['error' => 'Direct access forbidden']);
exit;
