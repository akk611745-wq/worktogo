<?php
// ─────────────────────────────────────────────
//  WorkToGo — CartController  (Production v3.0)
// ─────────────────────────────────────────────

require_once __DIR__ . '/../../helpers/shopping.helpers.php';
require_once __DIR__ . '/CartService.php';

class CartController
{
    private CartService $service;

    public function __construct(PDO $db)
    {
        $this->service = new CartService($db);
    }

    // GET /api/cart
    public function get(): void
    {
        $userId = se_require_auth();
        try {
            se_ok($this->service->get($userId));
        } catch (\Throwable $e) {
            error_log('[CartController::get] ' . $e->getMessage());
            se_fail('Failed to load cart', 500);
        }
    }

    // POST /api/cart/add
    // Body: { product_id, quantity? }
    public function add(): void
    {
        $userId = se_require_auth();
        $body   = se_json_body();

        $productId = (int)($body['product_id'] ?? 0);
        $quantity  = isset($body['quantity']) ? (int)$body['quantity'] : 1;

        if (!$productId) {
            se_fail('product_id is required', 422);
            return;
        }
        if ($quantity < 1) {
            se_fail('quantity must be at least 1', 422);
            return;
        }

        try {
            se_ok($this->service->add($userId, $productId, $quantity));
        } catch (\InvalidArgumentException $e) {
            se_fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            se_fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log('[CartController::add] ' . $e->getMessage());
            se_fail('Failed to add item to cart', 500);
        }
    }

    // POST /api/cart/remove
    // Body: { product_id } or { cart_item_id }
    public function remove(): void
    {
        $userId = se_require_auth();
        $body   = se_json_body();

        $productId  = isset($body['product_id'])   ? (int)$body['product_id']   : null;
        $cartItemId = isset($body['cart_item_id']) ? (int)$body['cart_item_id'] : null;

        if (!$productId && !$cartItemId) {
            se_fail('product_id or cart_item_id is required', 422);
            return;
        }

        try {
            se_ok($this->service->remove($userId, $productId, $cartItemId));
        } catch (\InvalidArgumentException $e) {
            se_fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            se_fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log('[CartController::remove] ' . $e->getMessage());
            se_fail('Failed to remove item', 500);
        }
    }

    // POST /api/cart/update
    // Body: { product_id, quantity }  — quantity = 0 removes item
    public function update(): void
    {
        $userId = se_require_auth();
        $body   = se_json_body();

        $productId = (int)($body['product_id'] ?? 0);
        $quantity  = isset($body['quantity']) ? (int)$body['quantity'] : -1;

        if (!$productId) {
            se_fail('product_id is required', 422);
            return;
        }
        if ($quantity < 0) {
            se_fail('quantity must be >= 0 (0 removes the item)', 422);
            return;
        }

        try {
            se_ok($this->service->update($userId, $productId, $quantity));
        } catch (\InvalidArgumentException $e) {
            se_fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            se_fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log('[CartController::update] ' . $e->getMessage());
            se_fail('Failed to update cart', 500);
        }
    }
}
