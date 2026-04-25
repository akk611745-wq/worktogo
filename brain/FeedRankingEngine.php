<?php
declare(strict_types=1);

class FeedRankingEngine {
    private \PDO $db;

    public function __construct(\PDO $db) {
        $this->db = $db;
    }

    public function rankItems(array $items, int $user_id): array {
        // 1. Get user's followed vendor_ids from followers table
        $followed_vendor_ids = [];
        if ($user_id > 0) {
            $stmt = $this->db->prepare("SELECT target_id FROM followers WHERE follower_user_id = ? AND target_type = 'vendor'");
            $stmt->execute([$user_id]);
            $followed_vendor_ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        $ranked_items = [];
        
        // 2. For each item
        foreach ($items as $item) {
            $vendor_id = $item['vendor_id'] ?? 0;
            $base_score = $item['score'] ?? 0;
            $type = $item['type'] ?? 'product'; // 'product' or 'service'
            $phase = $item['phase'] ?? 'default';
            $is_cross_sell = $item['is_cross_sell'] ?? false;
            $city = $item['city'] ?? 'your area';

            $is_followed = in_array($vendor_id, $followed_vendor_ids);
            
            // If is_followed: multiply score by 1.5
            $final_score = $is_followed ? ($base_score * 1.5) : $base_score;
            
            // Add reason string
            $reason = "Popular in your area";
            if ($is_followed) {
                $reason = "Because you follow this vendor";
            } else if ($phase === 'cold_start') {
                $reason = "Trending in " . $city;
            } else if ($phase === 'learning') {
                $reason = "Based on your interests";
            } else if ($phase === 'personalized' && $is_cross_sell) {
                $reason = "You might also need this";
            }

            $ranked_items[] = [
                'item_data' => $item,
                'type' => $type,
                'final_score' => $final_score,
                'is_followed' => $is_followed,
                'reason' => $reason
            ];
        }

        // 3. Sort by final_score DESC
        usort($ranked_items, function($a, $b) {
            return $b['final_score'] <=> $a['final_score'];
        });

        // 4. Return array
        return $ranked_items;
    }

    public function getTrendingItems(int $limit, string $city): array {
        // SELECT top vendors by orders+rating in given city
        $stmt = $this->db->prepare("
            SELECT id as vendor_id, business_name, vendor_type, rating, total_orders, city,
                   ((rating * 0.4) + (total_orders * 0.3)) as base_score
            FROM vendors 
            WHERE city = ? AND status = 'active'
            ORDER BY base_score DESC 
            LIMIT ?
        ");
        
        // We need to pass limit as INT, PDO might quote it if we don't bind carefully,
        // but if we use execute() it passes as string. We'll use bindParam.
        $stmt->bindParam(1, $city, \PDO::PARAM_STR);
        $stmt->bindParam(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        $vendors = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $items = [];
        foreach ($vendors as $v) {
            // Get follower count to add to score
            $f_stmt = $this->db->prepare("SELECT COUNT(*) FROM followers WHERE target_id = ? AND target_type = 'vendor'");
            $f_stmt->execute([$v['vendor_id']]);
            $follower_count = (int) $f_stmt->fetchColumn();
            
            $score = $v['base_score'] + ($follower_count * 0.3);
            
            $items[] = [
                'vendor_id' => $v['vendor_id'],
                'business_name' => $v['business_name'],
                'type' => $v['vendor_type'] === 'service' ? 'service' : 'product',
                'score' => $score,
                'city' => $v['city'],
                // Add any other data needed by frontend
            ];
        }
        
        return $items;
    }
}
