<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceDelivery  (S6)
//  commerce-system-blueprint §F. A THIN commerce seam OVER the native
//  DeliveryEngine (reused as-is, not edited): it adds the handoff CONTRACT
//  (weight → vehicle, prepaid vs COD, ready vs custom), pickup/deliver PROOF +
//  delivery OTP, and auto-reassign on accept-timeout. Pricing, nearest-rider
//  assignment, reject-reassign, cancel and the no-rider/stuck lifecycle cron all
//  stay in DeliveryEngine / cron. All thresholds/timeouts are DB-config.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../core/delivery/DeliveryEngine.php';
require_once __DIR__ . '/CommerceEventBus.php';

class CommerceDelivery
{
    private static function setting(PDO $db, string $key, $default)
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false ? $default : $v;
        } catch (\Throwable $e) { return $default; }
    }

    public static function acceptTimeoutSec(PDO $db): int { return (int)self::setting($db, 'commerce_delivery_accept_timeout_sec', 120); }
    public static function maxReassign(PDO $db): int       { return (int)self::setting($db, 'commerce_delivery_max_reassign', 3); }

    /** Weight (kg) → vehicle, from the ordered threshold list (null max = catch-all). */
    public static function requiredVehicle(PDO $db, float $weightKg): string
    {
        $raw = self::setting($db, 'commerce_vehicle_thresholds', '');
        $list = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($list) || !$list) {
            $list = [['max_kg' => 20, 'vehicle' => 'bike'], ['max_kg' => 80, 'vehicle' => 'e_rickshaw'], ['max_kg' => null, 'vehicle' => 'loader']];
        }
        foreach ($list as $t) {
            $max = $t['max_kg'] ?? null;
            if ($max === null || $weightKg <= (float)$max) return (string)($t['vehicle'] ?? 'bike');
        }
        return (string)($list[count($list) - 1]['vehicle'] ?? 'loader');
    }

    /**
     * Enrich the delivery handoff for a parent order: total weight (Σ item qty ×
     * product weight across sub-orders) → required vehicle, prepaid vs COD, ready
     * vs custom. Writes onto the existing deliveries row (created by DeliveryEngine).
     */
    public static function enrichHandoff(PDO $db, int $parentOrderId): array
    {
        $w = $db->prepare(
            "SELECT COALESCE(SUM(oi.quantity * COALESCE(p.weight,0)),0) AS kg
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
               LEFT JOIN products p ON p.id = oi.product_id
              WHERE o.parent_order_id = ?"
        );
        $w->execute([$parentOrderId]);
        $weight = (float)($w->fetchColumn() ?: 0);

        $o = $db->prepare("SELECT payment_method, payment_status, total FROM orders WHERE id=? LIMIT 1");
        $o->execute([$parentOrderId]);
        $ord = $o->fetch(PDO::FETCH_ASSOC) ?: [];
        $isPrepaid = (($ord['payment_method'] ?? '') === 'online') ? 1 : 0;
        $codAmount = (($ord['payment_method'] ?? '') === 'cod') ? (float)($ord['total'] ?? 0) : 0.0;
        $vehicle   = self::requiredVehicle($db, $weight);

        $db->prepare(
            "UPDATE deliveries
                SET total_weight_kg=?, required_vehicle=?, is_prepaid=?, cod_amount=?, ready_vs_custom=COALESCE(ready_vs_custom,'ready')
              WHERE order_id=?"
        )->execute([$weight, $vehicle, $isPrepaid, $codAmount, $parentOrderId]);

        return ['weight_kg' => $weight, 'required_vehicle' => $vehicle, 'is_prepaid' => $isPrepaid, 'cod_amount' => $codAmount];
    }

    /** Issue a 4-digit delivery OTP for an order's delivery; returns the plain OTP (shown to the customer). */
    public static function issueOtp(PDO $db, int $parentOrderId): ?string
    {
        $otp = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $hash = hash('sha256', $otp);
        $u = $db->prepare("UPDATE deliveries SET delivery_otp_hash=? WHERE order_id=?");
        $u->execute([$hash, $parentOrderId]);
        return $u->rowCount() > 0 ? $otp : null;
    }

    /** Rider marks pickup WITH proof (reuses DeliveryEngine::riderPickup). */
    public static function pickup(PDO $db, int $deliveryId, int $riderId, string $proof): array
    {
        $res = DeliveryEngine::riderPickup($db, $deliveryId, $riderId);
        $db->prepare("UPDATE deliveries SET pickup_proof=? WHERE id=? AND driver_id=?")->execute([$proof, $deliveryId, $riderId]);
        return $res + ['pickup_proof' => $proof];
    }

    /**
     * Rider delivers: OTP is verified FIRST (sole completion gate); only then does
     * DeliveryEngine::riderDeliver run (which books settlement). Wrong/missing OTP
     * is rejected and the order stays undelivered.
     */
    public static function deliver(PDO $db, int $deliveryId, int $riderId, string $otp, string $proof): array
    {
        $s = $db->prepare("SELECT delivery_otp_hash FROM deliveries WHERE id=? AND driver_id=? LIMIT 1");
        $s->execute([$deliveryId, $riderId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Delivery not found or not assigned to you');
        $expected = (string)($row['delivery_otp_hash'] ?? '');
        if ($expected === '' || !hash_equals($expected, hash('sha256', $otp))) {
            return ['status' => 'rejected', 'error' => 'invalid_otp'];
        }
        $res = DeliveryEngine::riderDeliver($db, $deliveryId, $riderId);
        $db->prepare("UPDATE deliveries SET delivery_proof=? WHERE id=?")->execute([$proof, $deliveryId]);
        return $res + ['delivery_proof' => $proof];
    }

    /**
     * Auto-reassign deliveries ASSIGNED but not ACCEPTED past the accept timeout
     * (the lifecycle cron flags 'stuck'; this actively hands them to the next
     * nearest rider — "no stuck states"). After max attempts with no rider →
     * flag 'no_rider' + emit delivery_failed (→ ops needs_ops). Time in SQL NOW().
     * @return array{reassigned:int, failed:int}
     */
    public static function reassignUnaccepted(PDO $db): array
    {
        $timeout = self::acceptTimeoutSec($db);
        $max     = self::maxReassign($db);
        $q = $db->prepare(
            "SELECT id, order_id, driver_id, assign_attempts
               FROM deliveries
              WHERE status='assigned' AND accepted_at IS NULL
                AND assigned_at IS NOT NULL
                AND assigned_at < (NOW() - INTERVAL ? SECOND)"
        );
        $q->execute([$timeout]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $reassigned = 0; $failed = 0;
        foreach ($rows as $d) {
            $did = (int)$d['id'];
            if ((int)$d['assign_attempts'] >= $max) {
                $db->prepare("UPDATE deliveries SET flag='no_rider', flagged_at=NOW() WHERE id=?")->execute([$did]);
                CommerceEventBus::emit($db, 'delivery_failed', ['order_id' => (int)$d['order_id'], 'reason' => 'no rider accepted after ' . $max . ' attempts']);
                $failed++;
                continue;
            }
            $db->prepare("UPDATE deliveries SET assign_attempts = assign_attempts + 1, last_attempt_at = NOW() WHERE id=?")->execute([$did]);
            $r = DeliveryEngine::adminReassign($db, $did, null); // frees current + assignNearest(exclude current)
            if (!empty($r['reassigned'])) $reassigned++;
            else {
                $db->prepare("UPDATE deliveries SET flag='no_rider', flagged_at=NOW() WHERE id=?")->execute([$did]);
                CommerceEventBus::emit($db, 'delivery_failed', ['order_id' => (int)$d['order_id'], 'reason' => 'no alternate rider available']);
                $failed++;
            }
        }
        return ['reassigned' => $reassigned, 'failed' => $failed];
    }
}
