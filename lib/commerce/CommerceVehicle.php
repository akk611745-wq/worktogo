<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceVehicle  (Paint PB8)
//  Vehicle-doubt gate on top of the existing weight→vehicle rule
//  (CommerceDelivery::requiredVehicle + commerce_vehicle_thresholds — reused, not
//  rebuilt). When the auto pick is UNCLEAR — weight unknown, near a threshold
//  boundary, multi-item, or high-value (all admin thresholds) — the order is
//  marked "needs confirm": a commerce_vehicle_doubts row + a needs-ops event,
//  instead of dispatching blind. The seller suggests a vehicle; admin overrides.
//  On resolve the final vehicle is written to the delivery and the ops flag cleared.
//
//  Additive; reuses CommerceDelivery + CommerceEventBus. No engine edit.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/CommerceDelivery.php';
require_once __DIR__ . '/CommerceEventBus.php';

class CommerceVehicle
{
    private static function num(PDO $db, string $key, float $def): float
    {
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$key]); $v = $s->fetchColumn();
              if ($v !== false && is_numeric($v)) return (float)$v; } catch (\Throwable $e) {}
        return $def;
    }

    /** Distinct vehicle choices from the admin threshold list (ordered small→large). */
    public static function options(PDO $db): array
    {
        $raw = null;
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_vehicle_thresholds' LIMIT 1"); $s->execute(); $raw = $s->fetchColumn(); } catch (\Throwable $e) {}
        $list = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($list) || !$list) $list = [['vehicle' => 'bike'], ['vehicle' => 'e_rickshaw'], ['vehicle' => 'loader']];
        $out = [];
        foreach ($list as $t) { $v = (string)($t['vehicle'] ?? ''); if ($v !== '' && !in_array($v, $out, true)) $out[] = $v; }
        return $out ?: ['bike', 'e_rickshaw', 'loader'];
    }

    /** Finite threshold boundaries (kg). */
    private static function boundaries(PDO $db): array
    {
        $raw = null;
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_vehicle_thresholds' LIMIT 1"); $s->execute(); $raw = $s->fetchColumn(); } catch (\Throwable $e) {}
        $list = is_string($raw) ? json_decode($raw, true) : [];
        $b = [];
        if (is_array($list)) foreach ($list as $t) { if (isset($t['max_kg']) && $t['max_kg'] !== null && is_numeric($t['max_kg'])) $b[] = (float)$t['max_kg']; }
        return $b;
    }

    /**
     * Decide the vehicle for an order + whether it's confident.
     * @return array{vehicle:string, confident:bool, reason:string, options:array, weight_kg:float, items:int, total:float}
     */
    public static function decide(PDO $db, int $orderId): array
    {
        // weight + total from the delivery handoff / the order; item count across sub-orders.
        $w = 0.0;
        try { $s = $db->prepare("SELECT total_weight_kg FROM deliveries WHERE order_id=? ORDER BY id DESC LIMIT 1"); $s->execute([$orderId]); $wv = $s->fetchColumn(); if ($wv !== false && $wv !== null) $w = (float)$wv; } catch (\Throwable $e) {}
        $total = 0.0;
        try { $s = $db->prepare("SELECT total FROM orders WHERE id=? LIMIT 1"); $s->execute([$orderId]); $total = (float)($s->fetchColumn() ?: 0); } catch (\Throwable $e) {}
        $items = 0;
        try { $s = $db->prepare("SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.id=? OR o.parent_order_id=?"); $s->execute([$orderId, $orderId]); $items = (int)$s->fetchColumn(); } catch (\Throwable $e) {}

        $vehicle = CommerceDelivery::requiredVehicle($db, $w);
        $options = self::options($db);
        $confident = true; $reasons = [];

        if ($w <= 0) { $confident = false; $reasons[] = 'weight unknown'; }
        else {
            $pct = self::num($db, 'commerce_vehicle_doubt_near_pct', 15);
            foreach (self::boundaries($db) as $bnd) {
                if ($bnd > 0 && abs($w - $bnd) <= $bnd * $pct / 100) { $confident = false; $reasons[] = 'weight ' . $w . 'kg near the ' . $bnd . 'kg cut-off'; break; }
            }
        }
        $minItems = (int)self::num($db, 'commerce_vehicle_doubt_min_items', 3);
        if ($minItems > 0 && $items >= $minItems) { $confident = false; $reasons[] = $items . ' items (mixed load)'; }
        $hv = self::num($db, 'commerce_high_value_threshold', 0);
        if ($hv > 0 && $total >= $hv) { $confident = false; $reasons[] = 'high-value ₹' . (int)$total; }

        return ['vehicle' => $vehicle, 'confident' => $confident, 'reason' => $confident ? 'clear' : implode('; ', $reasons),
                'options' => $options, 'weight_kg' => $w, 'items' => $items, 'total' => $total];
    }

    /**
     * Called at dispatch (seller accept): if the vehicle is unclear, open a doubt
     * (record + needs-ops event) instead of a blind dispatch. Idempotent per order.
     * @return array{vehicle_doubt:bool, decision:array}
     */
    public static function evaluateOnDispatch(PDO $db, int $subOrderId, ?int $suggestedBy = null): array
    {
        $parent = $subOrderId;
        try { $s = $db->prepare("SELECT parent_order_id FROM orders WHERE id=? LIMIT 1"); $s->execute([$subOrderId]); $p = $s->fetchColumn(); if ($p) $parent = (int)$p; } catch (\Throwable $e) {}
        $d = self::decide($db, $parent);
        if ($d['confident']) return ['vehicle_doubt' => false, 'decision' => $d];
        self::open($db, $parent, $subOrderId, $d);
        return ['vehicle_doubt' => true, 'decision' => $d];
    }

    /** Open (or refresh) a pending doubt for an order + raise the ops flag. */
    public static function open(PDO $db, int $orderId, ?int $subOrderId, array $decision): void
    {
        try {
            $db->prepare("INSERT INTO commerce_vehicle_doubts (order_id, sub_order_id, auto_vehicle, options, reason, weight_kg, status, created_at, updated_at)
                          VALUES (?,?,?,?,?,?, 'pending', NOW(), NOW())
                          ON DUPLICATE KEY UPDATE auto_vehicle=VALUES(auto_vehicle), options=VALUES(options), reason=VALUES(reason), weight_kg=VALUES(weight_kg), status='pending', updated_at=NOW()")
               ->execute([$orderId, $subOrderId, $decision['vehicle'], json_encode($decision['options']), mb_substr($decision['reason'], 0, 160), $decision['weight_kg']]);
        } catch (\Throwable $e) { error_log('[vehicle open] ' . $e->getMessage()); }
        // The "needs confirm" signal lives in commerce_vehicle_doubts + the ops flag
        // below (deliveries.flag is a fixed enum, so it isn't reused for this).
        try { CommerceEventBus::emit($db, 'vehicle_doubt', ['order_id' => $orderId, 'sub_order_id' => $subOrderId, 'reason' => $decision['reason'], 'payload' => ['auto_vehicle' => $decision['vehicle'], 'options' => $decision['options']]]); } catch (\Throwable $e) {}
    }

    /** The open doubt for an order (null if none / resolved). */
    public static function doubtFor(PDO $db, int $orderId): ?array
    {
        try {
            $s = $db->prepare("SELECT * FROM commerce_vehicle_doubts WHERE order_id=? LIMIT 1");
            $s->execute([$orderId]); $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $r['options'] = $r['options'] ? (json_decode($r['options'], true) ?: []) : [];
            return $r ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /** Seller suggests a vehicle (they know the goods). Must be a valid option + their order. */
    public static function sellerSuggest(PDO $db, int $subOrderId, int $vendorId, string $vehicle): array
    {
        $parent = $subOrderId;
        $own = $db->prepare("SELECT parent_order_id, vendor_id FROM orders WHERE id=? LIMIT 1");
        $own->execute([$subOrderId]); $o = $own->fetch(PDO::FETCH_ASSOC);
        if (!$o) return ['ok' => false, 'reason' => 'not_found'];
        if ((int)$o['vendor_id'] !== $vendorId) return ['ok' => false, 'reason' => 'not_yours'];
        if ($o['parent_order_id']) $parent = (int)$o['parent_order_id'];
        $doubt = self::doubtFor($db, $parent);
        if (!$doubt || $doubt['status'] !== 'pending') return ['ok' => false, 'reason' => 'no_open_doubt'];
        if (!in_array($vehicle, self::options($db), true)) return ['ok' => false, 'reason' => 'bad_vehicle'];
        $db->prepare("UPDATE commerce_vehicle_doubts SET suggested_vehicle=?, suggested_by=?, updated_at=NOW() WHERE order_id=?")
           ->execute([$vehicle, $vendorId, $parent]);
        return ['ok' => true, 'order_id' => $parent, 'suggested' => $vehicle];
    }

    /** Resolve (seller-accept-of-own-suggestion or admin override) → write the final vehicle + dispatch. */
    public static function resolve(PDO $db, int $orderId, string $vehicle, string $role, ?int $actorId = null): array
    {
        $doubt = self::doubtFor($db, $orderId);
        if (!$doubt) return ['ok' => false, 'reason' => 'no_doubt'];
        if (!in_array($vehicle, self::options($db), true)) return ['ok' => false, 'reason' => 'bad_vehicle'];
        $db->prepare("UPDATE commerce_vehicle_doubts SET final_vehicle=?, status='resolved', resolved_by=?, resolved_role=?, updated_at=NOW() WHERE order_id=?")
           ->execute([$vehicle, $actorId, $role, $orderId]);
        // write the confirmed vehicle onto the delivery; clear the ops flag below.
        $db->prepare("UPDATE deliveries SET required_vehicle=? WHERE order_id=?")->execute([$vehicle, $orderId]);
        CommerceEventBus::clearFlag($db, $orderId, 'vehicle_doubt');
        try { CommerceEventBus::emit($db, 'vehicle_confirmed', ['order_id' => $orderId, 'actor_type' => $role, 'actor_id' => $actorId, 'payload' => ['vehicle' => $vehicle]]); } catch (\Throwable $e) {}
        return ['ok' => true, 'order_id' => $orderId, 'vehicle' => $vehicle, 'resolved_by' => $role];
    }

    /** Pending doubts for the ops/admin queue. */
    public static function queue(PDO $db): array
    {
        try {
            $s = $db->query("SELECT order_id, sub_order_id, auto_vehicle, suggested_vehicle, options, reason, weight_kg, created_at
                               FROM commerce_vehicle_doubts WHERE status='pending' ORDER BY created_at ASC");
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['options'] = $r['options'] ? (json_decode($r['options'], true) ?: []) : [];
            return $rows;
        } catch (\Throwable $e) { return []; }
    }
}
