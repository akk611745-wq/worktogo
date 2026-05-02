<?php

require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/core/helpers/Response.php';
require_once HEART_ROOT . '/middleware/AuthMiddleware.php';

class ServiceVendorAnalyticsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDB();
    }

    private function requireVendorAndGetId(): int
    {
        $auth = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_ADMIN);

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

    public function getBookingStats()
    {
        $vendor_id = $this->requireVendorAndGetId();

        // 1. bookings_today
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM bookings WHERE vendor_id=? AND DATE(created_at)=CURDATE() AND status != 'cancelled'");
        $stmt->execute([$vendor_id]);
        $bookings_today = (int)$stmt->fetchColumn();

        // 2. revenue_week
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(t.amount),0) FROM transactions t JOIN bookings b ON t.reference_id = b.id AND t.reference_type='booking' WHERE b.vendor_id=? AND t.status='success' AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$vendor_id]);
        $revenue_week = (float)$stmt->fetchColumn();

        // 3. avg_booking_value
        $stmt = $this->db->prepare("SELECT COALESCE(AVG(t.amount),0) FROM transactions t JOIN bookings b ON t.reference_id = b.id AND t.reference_type='booking' WHERE b.vendor_id=? AND t.status='success'");
        $stmt->execute([$vendor_id]);
        $avg_booking_value = (float)$stmt->fetchColumn();

        // 4. rating
        // reviews table does not exist in database-structure.sql, so this query is intentionally disabled.
        // Wrong SQL: SELECT COALESCE(AVG(rating),0) FROM reviews WHERE vendor_id=?
        $rating = [];

        // 5. follower_count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM followers WHERE target_id=? AND target_type='vendor'");
        $stmt->execute([$vendor_id]);
        $follower_count = (int)$stmt->fetchColumn();

        // 6. best_day
        $stmt = $this->db->prepare("SELECT DAYNAME(created_at) as day, COUNT(*) as cnt FROM bookings WHERE vendor_id=? AND status='completed' GROUP BY DAYNAME(created_at) ORDER BY cnt DESC LIMIT 1");
        $stmt->execute([$vendor_id]);
        $best_day = $stmt->fetchColumn() ?: '';

        // 7. completed_bookings_count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM bookings WHERE vendor_id=? AND status='completed'");
        $stmt->execute([$vendor_id]);
        $completed_bookings_count = (int)$stmt->fetchColumn();

        Response::json([
            'success' => true,
            'data' => [
                'bookings_today' => $bookings_today,
                'revenue_week' => $revenue_week,
                'avg_booking_value' => $avg_booking_value,
                'rating' => $rating,
                'follower_count' => $follower_count,
                'best_day' => $best_day,
                'completed_bookings_count' => $completed_bookings_count
            ]
        ]);
    }

    public function getBookingChart()
    {
        $vendor_id = $this->requireVendorAndGetId();

        $range = $_GET['range'] ?? '4w';
        if (!in_array($range, ['4w', '30d'])) {
            $range = '4w';
        }

        if ($range === '4w') {
            $sql = "SELECT WEEK(b.created_at) as week_number, MIN(DATE(b.created_at)) as week_start, COUNT(b.id) as booking_count, COALESCE(SUM(t.amount),0) as revenue 
                    FROM bookings b LEFT JOIN transactions t ON t.reference_id = b.id AND t.reference_type='booking' AND t.status='success' 
                    WHERE b.vendor_id=? AND b.created_at >= DATE_SUB(NOW(), INTERVAL 4 WEEK) AND b.status != 'cancelled' 
                    GROUP BY WEEK(b.created_at) ORDER BY week_number ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendor_id]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $resultMap = [];
            foreach ($results as $row) {
                $resultMap[$row['week_number']] = $row;
            }

            $data = [];
            $current_week = (int)date('W');
            for ($i = 3; $i >= 0; $i--) {
                $w = $current_week - $i;
                if ($w < 0) {
                    $w += 52;
                }
                
                // Get week start date approx
                $week_start = date('Y-m-d', strtotime("-".($i*7)." days"));
                
                if (isset($resultMap[$w])) {
                    $data[] = [
                        'week' => 'Week ' . (4 - $i),
                        'week_start' => $resultMap[$w]['week_start'] ?: $week_start,
                        'booking_count' => (int)$resultMap[$w]['booking_count'],
                        'revenue' => (float)$resultMap[$w]['revenue']
                    ];
                } else {
                    $data[] = [
                        'week' => 'Week ' . (4 - $i),
                        'week_start' => $week_start,
                        'booking_count' => 0,
                        'revenue' => 0
                    ];
                }
            }
        } else {
            // 30d range
            $sql = "SELECT DATE(b.created_at) as date, COUNT(b.id) as booking_count, COALESCE(SUM(t.amount),0) as revenue 
                    FROM bookings b LEFT JOIN transactions t ON t.reference_id = b.id AND t.reference_type='booking' AND t.status='success' 
                    WHERE b.vendor_id=? AND b.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND b.status != 'cancelled' 
                    GROUP BY DATE(b.created_at) ORDER BY date ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendor_id]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $resultMap = [];
            foreach ($results as $row) {
                $resultMap[$row['date']] = $row;
            }

            $data = [];
            for ($i = 30; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                if (isset($resultMap[$d])) {
                    $data[] = [
                        'date' => $d,
                        'booking_count' => (int)$resultMap[$d]['booking_count'],
                        'revenue' => (float)$resultMap[$d]['revenue']
                    ];
                } else {
                    $data[] = [
                        'date' => $d,
                        'booking_count' => 0,
                        'revenue' => 0
                    ];
                }
            }
        }

        Response::json([
            'success' => true,
            'range' => $range,
            'data' => $data
        ]);
    }
}
