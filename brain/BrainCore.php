<?php
declare(strict_types=1);

require_once __DIR__ . '/FeedRankingEngine.php';

class BrainCore {
    private \PDO $db;
    private FeedRankingEngine $rankingEngine;

    public function __construct(?\PDO $db = null) {
        $this->db = $db ?? getDB();
        $this->rankingEngine = new FeedRankingEngine($this->db);
    }

    public function observe(string $event_type, int $user_id, int $target_id, string $target_type, array $payload = []): void {
        $stmt = $this->db->prepare("
            INSERT INTO brain_events (event_type, user_id, target_id, target_type, payload, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $event_type,
            $user_id,
            $target_id,
            $target_type,
            json_encode($payload)
        ]);
    }

    public function logEvent(string $event_type, int $user_id, int $target_id, string $target_type, array $payload = []): void {
        $this->observe($event_type, $user_id, $target_id, $target_type, $payload);
    }

    public function buildUserFeed(?int $user_id, ?float $lat = null, ?float $lng = null, int $limit = 20): array {
        // STEP 0: Get thresholds from app_settings
        $stmt = $this->db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'cold_start_threshold'");
        $stmt->execute();
        $cold_start_threshold = (int) ($stmt->fetchColumn() ?: 10);

        $stmt = $this->db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'brain_discovery_percent'");
        $stmt->execute();
        $discovery_pct = (int) ($stmt->fetchColumn() ?: 30);
        $personalized_pct = 100 - $discovery_pct;

        // Ensure defaults if user_id is null
        $event_count = 0;
        $city = 'Unknown';
        
        if ($user_id) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM brain_events WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $event_count = (int) $stmt->fetchColumn();

            $stmt = $this->db->prepare("SELECT city FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $city = $stmt->fetchColumn() ?: 'Unknown';
        }

        $items = [];
        $phase = '';
        $reason = '';

        if ($user_id === null || $event_count < $cold_start_threshold) {
            // PHASE 1: Cold Start
            $phase = 'cold_start';
            $reason = 'Trending in ' . $city;
            
            $trending = $this->rankingEngine->getTrendingItems($limit, $city);
            
            // Mix 60% shopping, 40% service
            $shopping_limit = (int) round($limit * 0.6);
            $service_limit = $limit - $shopping_limit;
            
            $shopping = array_filter($trending, fn($item) => $item['type'] === 'product');
            $services = array_filter($trending, fn($item) => $item['type'] === 'service');
            
            $shopping = array_slice($shopping, 0, $shopping_limit);
            $services = array_slice($services, 0, $service_limit);
            
            $items = array_merge($shopping, $services);
            foreach ($items as &$item) {
                $item['phase'] = $phase;
            }
        } else if ($event_count >= $cold_start_threshold && $event_count < 50) {
            // PHASE 2: Learning
            $phase = 'learning';
            $reason = 'Based on your interests';
            
            // Top 2 categories
            $stmt = $this->db->prepare("
                SELECT target_type, COUNT(*) as cnt 
                FROM brain_events
                WHERE user_id = ? AND event_type IN ('click','view','purchase')
                GROUP BY target_type 
                ORDER BY cnt DESC 
                LIMIT 2
            ");
            $stmt->execute([$user_id]);
            $top_categories = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            $personalized_count = (int) round($limit * $personalized_pct / 100);
            $discovery_count = $limit - $personalized_count;
            
            // Dummy logic for fetching based on category - usually would query items matching categories
            $trending = $this->rankingEngine->getTrendingItems($limit * 2, $city);
            
            $personalized_items = [];
            $discovery_items = [];
            
            foreach ($trending as $t) {
                if (in_array($t['type'], $top_categories)) {
                    if (count($personalized_items) < $personalized_count) {
                        $t['phase'] = $phase;
                        $personalized_items[] = $t;
                    }
                } else {
                    if (count($discovery_items) < $discovery_count) {
                        $t['phase'] = 'cold_start'; // discovery items act like cold start
                        $discovery_items[] = $t;
                    }
                }
            }
            
            $items = array_merge($personalized_items, $discovery_items);
            shuffle($items);
        } else {
            // PHASE 3: Personalized
            $phase = 'personalized';
            
            // Time of day weighting
            $hour = (int) date('H');
            $time_boost = []; // dummy struct
            if ($hour >= 6 && $hour <= 11) {
                $time_boost = ['breakfast', 'grocery'];
            } else if ($hour >= 12 && $hour <= 15) {
                $time_boost = ['services', 'delivery'];
            } else if ($hour >= 16 && $hour <= 22) {
                $time_boost = ['restaurants', 'entertainment', 'shopping'];
            }
            
            // Cross sell logic
            $stmt = $this->db->prepare("SELECT DISTINCT event_type FROM brain_events WHERE user_id = ? AND target_type = 'product'");
            $stmt->execute([$user_id]);
            $product_events = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            $cross_sell_types = [];
            // Assuming event_type contains category names for simplicity in this dummy representation
            foreach ($product_events as $ev) {
                if (stripos($ev, 'paint') !== false || stripos($ev, 'hardware') !== false) {
                    $cross_sell_types[] = 'painter';
                    $cross_sell_types[] = 'electrician';
                }
                if (stripos($ev, 'food') !== false) {
                    $cross_sell_types[] = 'catering';
                    $cross_sell_types[] = 'tiffin';
                }
                if (stripos($ev, 'clothing') !== false) {
                    $cross_sell_types[] = 'tailor';
                }
            }
            
            $trending = $this->rankingEngine->getTrendingItems($limit, $city);
            $discovery_count = (int) round($limit * $discovery_pct / 100);
            
            foreach ($trending as $k => $t) {
                $t['phase'] = $phase;
                // Add relevance score placeholder
                $relevance = 10; 
                $base_score = ($t['score'] * 0.5) + ($relevance * 0.5);
                
                $t['score'] = $base_score;
                
                // Cross sell dummy check
                if ($t['type'] === 'service' && count($cross_sell_types) > 0) {
                    $t['is_cross_sell'] = true;
                }
                
                $items[] = $t;
            }
        }

        $ranked = $this->rankingEngine->rankItems($items, $user_id ?? 0);
        
        // Take limit
        $ranked = array_slice($ranked, 0, $limit);

        return [
            'phase' => $phase,
            'reason' => $reason,
            'items' => $ranked
        ];
    }
}
