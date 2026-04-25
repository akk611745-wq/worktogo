<?php

require_once __DIR__ . '/GeoHelper.php';

/**
 * DeliveryPricingEngine - Logic for calculating delivery costs
 */
class DeliveryPricingEngine {
    
    private $db;
    private $settings = [];

    public function __construct($db) {
        $this->db = $db;
        $this->loadSettings();
    }

    /**
     * Load pricing settings from platform_settings table
     */
    private function loadSettings() {
        $keys = ['delivery_base_fare', 'delivery_per_km_rate', 'delivery_rider_share', 'delivery_platform_margin'];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        
        $stmt = $this->db->prepare("SELECT `key`, `value` FROM platform_settings WHERE `key` IN ($placeholders)");
        $stmt->execute($keys);
        $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Default values if not set in DB
        $this->settings = [
            'base_fare' => floatval($results['delivery_base_fare'] ?? 30.00),
            'per_km_rate' => floatval($results['delivery_per_km_rate'] ?? 10.00),
            'rider_share' => floatval($results['delivery_rider_share'] ?? 80.00), // percentage
            'platform_margin' => floatval($results['delivery_platform_margin'] ?? 20.00) // percentage
        ];
    }

    /**
     * Calculate delivery price based on distance
     * 
     * @param float $lat1 Pickup Latitude
     * @param float $lon1 Pickup Longitude
     * @param float $lat2 Drop Latitude
     * @param float $lon2 Drop Longitude
     * @return array Pricing details
     */
    public function calculatePricing($lat1, $lon1, $lat2, $lon2) {
        $distance = GeoHelper::calculateDistance($lat1, $lon1, $lat2, $lon2, 'km');
        
        // Delivery Price = base_fare + (distance × per_km_rate)
        $user_price = $this->settings['base_fare'] + ($distance * $this->settings['per_km_rate']);
        
        // Calculate splits
        $rider_payout = ($user_price * $this->settings['rider_share']) / 100;
        $platform_fee = ($user_price * $this->settings['platform_margin']) / 100;

        return [
            'distance_km' => round($distance, 2),
            'user_price' => round($user_price, 2),
            'rider_payout' => round($rider_payout, 2),
            'platform_fee' => round($platform_fee, 2),
            'currency' => 'INR', // Assuming default, can be dynamic too
            'breakdown' => [
                'base_fare' => $this->settings['base_fare'],
                'distance_charge' => round($distance * $this->settings['per_km_rate'], 2)
            ]
        ];
    }
}
