<?php
// ─────────────────────────────────────────────────────────────
//  WorkToGo — OrderController  (Production v3.0)
//
//  v3.0 changes:
//    - create() maps rate-limit RuntimeException to HTTP 429
//    - All write routes call se_require_auth()
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../helpers/shopping.helpers.php';
require_once __DIR__ . '/../cart/CartService.php';
require_once __DIR__ . '/OrderService.php';

class OrderController
{
    private OrderService $service;
    private PDO          $db;

    public function __construct(PDO $db)
    {
        $this->db      = $db;
        $cart          = new CartService($db);
        $this->service = new OrderService($db, $cart);
    }

    // ── POST /api/orders ──────────────────────────────────────
    public function create(): void
    {
        $auth   = AuthMiddleware::requireRole(ROLE_CUSTOMER, ROLE_ADMIN);
        $userId = $auth['user_id'];
        $body   = se_json_body();

        try {
            $order = $this->service->create($userId, $body);
            se_ok($order);
        } catch (\InvalidArgumentException $e) {
            se_fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            // Detect rate-limit message and return 429
            if (str_contains($e->getMessage(), 'Too many orders')) {
                se_fail($e->getMessage(), 429);
            }
            se_fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log('[OrderController::create] ' . $e->getMessage());
            se_fail('Order creation failed', 500);
        }
    }

    // ── POST /api/orders/{id}/cancel ─────────────────────────
    public function cancel(int $id): void
    {
        $auth   = AuthMiddleware::requireRole(ROLE_CUSTOMER, ROLE_ADMIN);
        $userId = $auth['user_id'];

        try {
            $result = $this->service->cancel($userId, $id);
            se_ok($result);
        } catch (\InvalidArgumentException $e) {
            se_fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            se_fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log('[OrderController::cancel] ' . $e->getMessage());
            se_fail('Failed to cancel order', 500);
        }
    }

    // ── GET /api/orders ───────────────────────────────────────
    public function list(): void
    {
        $auth   = AuthMiddleware::requireRole(ROLE_CUSTOMER, ROLE_ADMIN);
        $userId = $auth['user_id'];
        try {
            se_ok($this->service->list($userId, $_GET));
        } catch (\Throwable $e) {
            error_log('[OrderController::list] ' . $e->getMessage());
            se_fail('Failed to load orders', 500);
        }
    }

    // ── GET /api/orders/{id} ──────────────────────────────────
    public function show(int $id): void
    {
        $auth   = AuthMiddleware::requireRole(ROLE_CUSTOMER, ROLE_ADMIN);
        $userId = $auth['user_id'];
        try {
            $order = $this->service->detail($userId, $id);
            if (!$order) {
                se_fail('Order not found', 404);
                return;
            }
            se_ok($order);
        } catch (\Throwable $e) {
            error_log('[OrderController::show] ' . $e->getMessage());
            se_fail('Failed to load order', 500);
        }
    }

    // ── GET /api/vendor/orders ────────────────────────────────
    public function vendorOrders(): void
    {
        $userId = se_require_auth();
        try {
            $vendorId = $this->resolveVendorId($userId);
            if (!$vendorId) {
                se_fail('Vendor account required', 403);
                return;
            }
            se_ok($this->service->vendorList($vendorId, $_GET));
        } catch (\Throwable $e) {
            error_log('[OrderController::vendorOrders] ' . $e->getMessage());
            se_fail('Failed to load vendor orders', 500);
        }
    }

    // ── PUT /api/vendor/orders/{id}/status ───────────────────
    public function vendorUpdateStatus(int $id): void
    {
        $userId = se_require_auth();
        $body   = se_json_body();

        $newStatus = trim($body['status'] ?? '');
        if ($newStatus === '') {
            se_fail('status is required', 422);
            return;
        }

        try {
            $vendorId = $this->resolveVendorId($userId);
            if (!$vendorId) {
                se_fail('Vendor account required', 403);
                return;
            }
            $result = $this->service->updateVendorOrderStatus($vendorId, $id, $newStatus);
            se_ok($result);
        } catch (\InvalidArgumentException $e) {
            se_fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            se_fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log('[OrderController::vendorUpdateStatus] ' . $e->getMessage());
            se_fail('Failed to update order status', 500);
        }
    }

    // ── Private helpers ───────────────────────────────────────
    private function resolveVendorId(int $userId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM vendors WHERE user_id = :uid AND status = 'active' LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }
}
