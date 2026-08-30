<?php
/**
 * CommerceTools.php — WorkToGo Agent Layer :: COMMERCE tools (Phase 5)
 * ----------------------------------------------------------------------------
 * Lets the agent shop by conversation. Each tool maps to ONE real storefront
 * endpoint via the EngineClient — the clean `/api/shop/*` adapter that already
 * reuses the never-touch shopping engine (ProductService / CartService /
 * OrderService) and is city-scoped + dual-store (company + local vendor). We
 * never touch the engine; we reach it exactly like the service tools reach the
 * service engine.
 *
 * Customer tools (role 'customer'):
 *   browse_products   [read ]  GET  /api/shop/products        (city-scoped, company-first)
 *   get_product       [read ]  GET  /api/shop/products/{id}
 *   view_cart         [read ]  GET  /api/shop/cart
 *   add_to_cart       [read*]  POST /api/shop/cart            (*light, reversible, no money → no confirm)
 *   list_orders       [read ]  GET  /api/shop/orders
 *   track_order       [read ]  GET  /api/shop/orders/{id}
 *   checkout          [WRITE]  POST /api/shop/checkout        (confirmation required)
 *
 * MONEY: checkout is Cash-on-Delivery through the storefront's real endpoint
 * (the engine COD-locks online pay today — a surfaced engine decision, not ours
 * to flip here). The agent only PLACES the order; when online pay is enabled for
 * commerce it flows through the existing payment-abstraction layer and the user
 * completes payment. The agent never moves money.
 *
 * PRIVACY: product/cart/order data is OUR real data — it goes engine → agent →
 * the user, never to the AI (which only ever saw the scrubbed language).
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentTool.php';

final class BrowseProductsTool extends AbstractTool
{
    public function name(): string { return 'browse_products'; }
    public function description(): string { return 'Browse products in the user\'s city (company + local stores). Free-text query optional (e.g. clothes, kurta).'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['query' => 'string?', 'limit' => 'int?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        $query = [];
        if (!empty($a['query'])) { $query['search'] = $a['query']; }
        $query['limit'] = (int) ($a['limit'] ?? 12);
        return $tc->engine->get('/api/shop/products', $query);
    }
}

final class GetProductTool extends AbstractTool
{
    public function name(): string { return 'get_product'; }
    public function description(): string { return 'Get one product by its id (price, store, stock).'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['id' => 'int']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        return $tc->engine->get('/api/shop/products/' . (int) $a['id']);
    }
}

final class ViewCartTool extends AbstractTool
{
    public function name(): string { return 'view_cart'; }
    public function description(): string { return 'Show the user\'s current cart.'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return []; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        return $tc->engine->get('/api/shop/cart');
    }
}

/**
 * A LIGHT write, intentionally NOT confirmation-gated: adding to the cart is
 * trivially reversible and moves no money. The engine still applies its own
 * city guard, stock check and rate limit. Checkout (money) is the gated write.
 */
final class AddToCartTool extends AbstractTool
{
    public function name(): string { return 'add_to_cart'; }
    public function description(): string { return 'Add a product to the cart. Needs product_id; quantity defaults to 1.'; }
    public function mode(): string { return 'read'; }   // light, reversible, no money → runs freely
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['product_id' => 'int', 'quantity' => 'int?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        return $tc->engine->post('/api/shop/cart', [
            'product_id' => (int) $a['product_id'],
            'quantity'   => max(1, (int) ($a['quantity'] ?? 1)),
            'op'         => 'add',
        ]);
    }
}

final class ListOrdersTool extends AbstractTool
{
    public function name(): string { return 'list_orders'; }
    public function description(): string { return 'List the user\'s recent orders.'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return []; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        return $tc->engine->get('/api/shop/orders');
    }
}

final class TrackOrderTool extends AbstractTool
{
    public function name(): string { return 'track_order'; }
    public function description(): string { return 'Get one order by its id (status, items, delivery).'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['id' => 'int']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        return $tc->engine->get('/api/shop/orders/' . (int) $a['id']);
    }
}

/**
 * The gated write: place the order. COD via the storefront's real endpoint. The
 * engine does the multi-vendor split, inventory locking, city guard, ledger and
 * delivery hand-off — all untouched. The agent initiates; when online pay is on,
 * the user completes payment. The agent never moves money.
 */
final class CheckoutTool extends AbstractTool
{
    public function name(): string { return 'checkout'; }
    public function description(): string { return 'Place the order for the current cart (Cash on Delivery). Optional address_id.'; }
    public function mode(): string { return 'write'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['address_id' => 'int?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        $body = [];
        if (!empty($a['address_id'])) { $body['address_id'] = (int) $a['address_id']; }
        return $tc->engine->post('/api/shop/checkout', $body);
    }
}
