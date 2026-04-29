<?php

require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/core/helpers/Response.php';
require_once HEART_ROOT . '/middleware/AuthMiddleware.php';

class ShoppingVendorAnalyticsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDB();
    }

    private function requireVendorAndGetId(): int
    {
        $auth = AuthMiddleware::requireRole(ROLE_VENDOR_SHOPPING, ROLE_ADMIN);

        if (($auth['role'] ?? '') === ROLE_ADMIN) {
            $vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
            if ($vendorId > 0) {
                return $vendorId;
            }
            Response::validation('vendor_id is required for admin analytics access');
        }

        $userId = (int)($auth['user_id'] ?? 0);
        if ($userId <= 0) {
            Response::unauthorized('Valid authentication token required');
        }

        $stmt = $this->db->prepare("SELECT id FROM vendors WHERE user_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$userId]);
        $vendorId = (int)$stmt->fetchColumn();

        if ($vendorId <= 0) {
            Response::forbidden('Vendor profile not found or inactive');
        }

        return $vendorId;
    }

    public function getDashboardStats()
    {
        $vendor_id = $this->requireVendorAndGetId();

        // 1. orders_today
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM orders WHERE vendor_id=? AND DATE(created_at)=CURDATE() AND status != 'cancelled'");
        $stmt->execute([$vendor_id]);
        $orders_today = (int)$stmt->fetchColumn();

        // 2. revenue_today
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(t.amount),0) FROM transactions t JOIN orders o ON t.order_id = o.id WHERE o.vendor_id=? AND DATE(t.created_at)=CURDATE() AND t.status='completed'");
        $stmt->execute([$vendor_id]);
        $revenue_today = (float)$stmt->fetchColumn();

        // 3. active_products
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM products WHERE vendor_id=? AND status='published' AND is_active=1");
        $stmt->execute([$vendor_id]);
        $active_products = (int)$stmt->fetchColumn();

        // 4. profile_views_today
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM brain_events WHERE target_id=? AND target_type='vendor' AND event_type='view' AND DATE(created_at)=CURDATE()");
        $stmt->execute([$vendor_id]);
        $profile_views_today = (int)$stmt->fetchColumn();

        // 5. revenue_this_week
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(t.amount),0) FROM transactions t JOIN orders o ON t.order_id = o.id WHERE o.vendor_id=? AND DATE(t.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND t.status='completed'");
        $stmt->execute([$vendor_id]);
        $revenue_this_week = (float)$stmt->fetchColumn();

        // 6. revenue_this_month
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(t.amount),0) FROM transactions t JOIN orders o ON t.order_id = o.id WHERE o.vendor_id=? AND MONTH(t.created_at)=MONTH(CURDATE()) AND YEAR(t.created_at)=YEAR(CURDATE()) AND t.status='completed'");
        $stmt->execute([$vendor_id]);
        $revenue_this_month = (float)$stmt->fetchColumn();

        // 7. new_orders_count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM orders WHERE vendor_id=? AND status='new'");
        $stmt->execute([$vendor_id]);
        $new_orders_count = (int)$stmt->fetchColumn();

        Response::json([
            'success' => true,
            'data' => [
                'orders_today' => $orders_today,
                'revenue_today' => $revenue_today,
                'active_products' => $active_products,
                'profile_views_today' => $profile_views_today,
                'revenue_this_week' => $revenue_this_week,
                'revenue_this_month' => $revenue_this_month,
                'new_orders_count' => $new_orders_count
            ]
        ]);
    }

    public function getRevenue()
    {
        $vendor_id = $this->requireVendorAndGetId();

        $range = $_GET['range'] ?? '7d';
        if (!in_array($range, ['1d', '7d', '30d'])) {
            $range = '7d';
        }

        if ($range === '1d') {
            $sql = "SELECT DATE_FORMAT(t.created_at, '%Y-%m-%d %H:00:00') as date, COALESCE(SUM(t.amount),0) as revenue, COUNT(o.id) as order_count 
                    FROM transactions t JOIN orders o ON t.order_id = o.id 
                    WHERE o.vendor_id = ? AND t.status = 'completed' AND t.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) 
                    GROUP BY DATE_FORMAT(t.created_at, '%Y-%m-%d %H:00:00') ORDER BY date ASC";
        } else {
            $days = $range === '30d' ? 30 : 7;
            $sql = "SELECT DATE(t.created_at) as date, COALESCE(SUM(t.amount),0) as revenue, COUNT(o.id) as order_count 
                    FROM transactions t JOIN orders o ON t.order_id = o.id 
                    WHERE o.vendor_id = ? AND t.status = 'completed' AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) 
                    GROUP BY DATE(t.created_at) ORDER BY date ASC";
        }

        $stmt = $this->db->prepare($sql);
        if ($range === '1d') {
            $stmt->execute([$vendor_id]);
        } else {
            $stmt->execute([$vendor_id, $days]);
        }
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        $resultMap = [];
        foreach ($results as $row) {
            $resultMap[$row['date']] = $row;
        }

        if ($range === '1d') {
            $start = strtotime('-24 hours');
            for ($i = 1; $i <= 24; $i++) {
                $d = date('Y-m-d H:00:00', $start + ($i * 3600));
                if (isset($resultMap[$d])) {
                    $data[] = [
                        'date' => $d,
                        'revenue' => (float)$resultMap[$d]['revenue'],
                        'order_count' => (int)$resultMap[$d]['order_count']
                    ];
                } else {
                    $data[] = ['date' => $d, 'revenue' => 0, 'order_count' => 0];
                }
            }
        } else {
            $days = $range === '30d' ? 30 : 7;
            for ($i = $days; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                if (isset($resultMap[$d])) {
                    $data[] = [
                        'date' => $d,
                        'revenue' => (float)$resultMap[$d]['revenue'],
                        'order_count' => (int)$resultMap[$d]['order_count']
                    ];
                } else {
                    $data[] = ['date' => $d, 'revenue' => 0, 'order_count' => 0];
                }
            }
        }

        Response::json([
            'success' => true,
            'range' => $range,
            'data' => $data
        ]);
    }

    public function getFunnel()
    {
        $vendor_id = $this->requireVendorAndGetId();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM brain_events WHERE target_type='product' AND event_type='view' AND target_id IN (SELECT id FROM products WHERE vendor_id=?) AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute([$vendor_id]);
        $product_views = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM brain_events WHERE target_type='product' AND event_type='add_to_cart' AND target_id IN (SELECT id FROM products WHERE vendor_id=?) AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute([$vendor_id]);
        $add_to_cart = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM brain_events WHERE target_type='product' AND event_type='checkout' AND target_id IN (SELECT id FROM products WHERE vendor_id=?) AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute([$vendor_id]);
        $checkout = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM orders WHERE vendor_id=? AND status != 'cancelled' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute([$vendor_id]);
        $purchased = (int)$stmt->fetchColumn();

        $conversion_rate = $product_views > 0 ? round(($purchased / $product_views) * 100, 1) . '%' : '0%';

        Response::json([
            'success' => true,
            'data' => [
                'product_views' => $product_views,
                'add_to_cart' => $add_to_cart,
                'checkout' => $checkout,
                'purchased' => $purchased,
                'conversion_rate' => $conversion_rate
            ]
        ]);
    }

    public function getTopProducts()
    {
        $vendor_id = $this->requireVendorAndGetId();

        $sql = "SELECT p.id, p.title, p.price, p.stock_quantity,
                       COUNT(oi.id) as total_sales,
                       COALESCE(SUM(oi.price * oi.quantity),0) as revenue,
                       (SELECT COUNT(*) FROM brain_events WHERE target_id=p.id AND target_type='product' AND event_type='view') as views
                FROM products p
                LEFT JOIN order_items oi ON oi.product_id = p.id
                LEFT JOIN orders o ON oi.order_id = o.id AND o.status != 'cancelled' AND MONTH(o.created_at) = MONTH(CURDATE())
                WHERE p.vendor_id = ?
                GROUP BY p.id
                ORDER BY total_sales DESC
                LIMIT 5";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$vendor_id]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($products as $p) {
            $data[] = [
                'product_id' => (int)$p['id'],
                'title' => $p['title'],
                'price' => (float)$p['price'],
                'stock_quantity' => (int)$p['stock_quantity'],
                'total_sales' => (int)$p['total_sales'],
                'revenue' => (float)$p['revenue'],
                'views' => (int)$p['views']
            ];
        }

        Response::json([
            'success' => true,
            'data' => $data
        ]);
    }
}
