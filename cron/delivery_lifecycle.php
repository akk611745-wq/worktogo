<?php
/**
 * WORKTOGO — DELIVERY LIFECYCLE ENGINE (CRON)
 * ---------------------------------------------------------------------------
 * The delivery watchdog (audit commerce-08 §2). Runs every ~30–60s and handles
 * the unhappy paths the native delivery engine didn't:
 *   A. No rider available → retry assignment; alert admin if still none after N min.
 *   B. Assigned but not accepted / not picked in time → flag 'stuck' + alert.
 *   C. Picked but not delivered in time → flag 'stuck' + alert (needs backup).
 *   D. Running past ETA → flag 'late' + alert.
 *   E. Delivered but unsettled → retry order settlement (belt-and-braces).
 * Flags surface in the admin Delivery Control "needs attention" view; alerts go
 * to the admin `alerts` stream. Lock-safe (flock) so overlapping runs are safe;
 * all thresholds are admin-tunable in platform_settings. Delivery-scoped only.
 */

require_once __DIR__ . '/../core/helpers/Database.php';
require_once __DIR__ . '/../core/delivery/DeliveryEngine.php';
require_once __DIR__ . '/../core/helpers/LedgerEngine.php';

// ── Defensive access: allow CLI; over HTTP require a matching cron secret ────
if (php_sapi_name() !== 'cli') {
    $secret = getenv('CRON_SECRET') ?: getenv('MIGRATE_SECRET') ?: '';
    $given  = $_GET['secret'] ?? '';
    if ($secret === '' || !hash_equals($secret, (string)$given)) {
        http_response_code(403);
        die("Forbidden");
    }
}

$db = getDB();

$lockFile = __DIR__ . '/delivery_lifecycle.lock';
$fp = fopen($lockFile, 'w+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    die("Delivery lifecycle is already running.\n");
}

$summary = ['retried' => 0, 'assigned' => 0, 'no_rider' => 0, 'stuck' => 0, 'late' => 0, 'settled' => 0, 'cancelled' => 0];

try {
    // ── Config (admin-tunable) ───────────────────────────────────────────────
    $keys = ['delivery_lifecycle_enabled','delivery_no_rider_retry_sec','delivery_no_rider_alert_min',
             'delivery_accept_timeout_min','delivery_pickup_stuck_min','delivery_deliver_stuck_min','delivery_late_grace_min'];
    $cfg = [];
    try {
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $st = $db->prepare("SELECT `key`, value FROM platform_settings WHERE `key` IN ($ph)");
        $st->execute($keys);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $cfg[$r['key']] = $r['value']; }
    } catch (\Throwable $e) { /* table may be absent on a partial env — use defaults */ }

    $enabled       = filter_var($cfg['delivery_lifecycle_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
    $retrySec      = (int)($cfg['delivery_no_rider_retry_sec']  ?? 30);
    $noRiderMin    = (int)($cfg['delivery_no_rider_alert_min']  ?? 5);
    $acceptMin     = (int)($cfg['delivery_accept_timeout_min']  ?? 5);
    $pickupMin     = (int)($cfg['delivery_pickup_stuck_min']    ?? 20);
    $deliverMin    = (int)($cfg['delivery_deliver_stuck_min']   ?? 45);
    $lateGraceMin  = (int)($cfg['delivery_late_grace_min']      ?? 15);
    if (!$enabled) { die("Delivery lifecycle disabled in settings.\n"); }

    $alert = function (int $orderId, string $title, string $msg) use ($db) {
        try {
            $db->prepare("INSERT INTO alerts (type, title, message, ref_type, ref_id, created_at)
                          VALUES ('system', ?, ?, 'order', ?, NOW())")
               ->execute([mb_substr($title, 0, 120), mb_substr($msg, 0, 500), $orderId ?: null]);
        } catch (\Throwable $e) { /* alerts optional */ }
    };
    $setFlag = function (int $deliveryId, string $flag) use ($db) {
        $db->prepare("UPDATE deliveries SET flag = ?, flagged_at = NOW() WHERE id = ?")->execute([$flag, $deliveryId]);
    };

    // ── A. Pending (no rider): retry assignment; alert if stale ──────────────
    $pending = $db->query(
        "SELECT id, order_id, created_at, assign_attempts, last_attempt_at, flag,
                TIMESTAMPDIFF(SECOND, COALESCE(last_attempt_at, created_at), NOW()) AS since_attempt,
                TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_min
           FROM deliveries WHERE status = 'pending' AND driver_id IS NULL"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pending as $d) {
        $did = (int)$d['id'];
        if ((int)$d['since_attempt'] >= $retrySec) {
            $db->prepare("UPDATE deliveries SET assign_attempts = assign_attempts + 1, last_attempt_at = NOW() WHERE id = ?")->execute([$did]);
            $summary['retried']++;
            $rider = DeliveryEngine::assignNearest($db, $did);
            if ($rider) { $summary['assigned']++; if ($d['flag'] !== 'none') { $setFlag($did, 'none'); } continue; }
        }
        if ((int)$d['age_min'] >= $noRiderMin && $d['flag'] !== 'no_rider') {
            $setFlag($did, 'no_rider'); $summary['no_rider']++;
            $alert((int)$d['order_id'], 'No rider for delivery', "Order #{$d['order_id']} has had no available rider for {$d['age_min']} min.");
        }
    }

    // ── B/C. Assigned but stuck (not accepted / not picked) ──────────────────
    $assignedStuck = $db->prepare(
        "SELECT id, order_id, TIMESTAMPDIFF(MINUTE, assigned_at, NOW()) AS since_assign, accepted_at
           FROM deliveries
          WHERE status = 'assigned' AND flag <> 'stuck' AND assigned_at IS NOT NULL
            AND ( (accepted_at IS NULL AND TIMESTAMPDIFF(MINUTE, assigned_at, NOW()) >= ?)
               OR (TIMESTAMPDIFF(MINUTE, assigned_at, NOW()) >= ?) )"
    );
    $assignedStuck->execute([$acceptMin, $pickupMin]);
    foreach ($assignedStuck->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $setFlag((int)$d['id'], 'stuck'); $summary['stuck']++;
        $reason = $d['accepted_at'] === null ? "not accepted" : "not picked up";
        $alert((int)$d['order_id'], 'Delivery stuck', "Order #{$d['order_id']} assigned {$d['since_assign']} min ago and {$reason}. Consider reassigning.");
    }

    // ── D. Picked but not delivered in time ──────────────────────────────────
    $pickedStuck = $db->prepare(
        "SELECT id, order_id, TIMESTAMPDIFF(MINUTE, picked_at, NOW()) AS since_pick
           FROM deliveries WHERE status = 'picked' AND flag <> 'stuck'
            AND picked_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, picked_at, NOW()) >= ?"
    );
    $pickedStuck->execute([$deliverMin]);
    foreach ($pickedStuck->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $setFlag((int)$d['id'], 'stuck'); $summary['stuck']++;
        $alert((int)$d['order_id'], 'Delivery not completed', "Order #{$d['order_id']} picked up {$d['since_pick']} min ago, still not delivered. Send a backup rider?");
    }

    // ── E. Running past ETA → late (don't override a 'stuck' flag) ────────────
    $late = $db->prepare(
        "SELECT id, order_id FROM deliveries
          WHERE status IN ('assigned','picked') AND flag NOT IN ('stuck','late')
            AND eta_minutes IS NOT NULL AND assigned_at IS NOT NULL
            AND NOW() > DATE_ADD(assigned_at, INTERVAL (eta_minutes + ?) MINUTE)"
    );
    $late->execute([$lateGraceMin]);
    foreach ($late->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $setFlag((int)$d['id'], 'late'); $summary['late']++;
        $alert((int)$d['order_id'], 'Delivery running late', "Order #{$d['order_id']} is past its ETA.");
    }

    // ── F. Delivered but unsettled → retry settlement (idempotent) ───────────
    $unsettled = $db->query(
        "SELECT d.order_id FROM deliveries d
           JOIN orders o ON o.id = d.order_id
          WHERE d.status = 'delivered' AND o.ledger_status <> 'processed'
          LIMIT 100"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($unsettled as $oid) {
        if (\Core\Helpers\LedgerEngine::processCommerceOrderSettlement((int)$oid, $db)) { $summary['settled']++; }
    }

    // ── G. Order cancelled but delivery still active → cancel + free rider ───
    // Safety net for cancel paths outside the storefront (admin / vendor cancel).
    $orphans = $db->query(
        "SELECT d.order_id FROM deliveries d JOIN orders o ON o.id = d.order_id
          WHERE o.status = 'cancelled' AND d.status IN ('pending','assigned','picked') LIMIT 100"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($orphans as $oid) { $summary['cancelled'] += DeliveryEngine::cancelForOrder($db, (int)$oid); }

    echo json_encode(['success' => true, 'summary' => $summary]) . "\n";
} catch (\Throwable $e) {
    error_log('[cron/delivery_lifecycle] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]) . "\n";
} finally {
    flock($fp, LOCK_UN); fclose($fp);
}
