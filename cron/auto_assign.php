<?php
/**
 * WORKTOGO — AUTO ASSIGN ENGINE (CRON) [V3 - PRODUCTION GRADE]
 * Scalable, lock-safe, DB-configured assignment engine.
 */

require_once __DIR__ . '/../core/helpers/Database.php';

$db = getDB();

$lockFile = __DIR__ . '/auto_assign.lock';
$fp = fopen($lockFile, 'w+');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    die("Auto assign is already running.\n");
}

try {
    // Load config from DB
    $stmt = $db->query("SELECT `key`, value FROM platform_settings WHERE `key` IN ('auto_assign_enabled', 'auto_assign_timeout', 'vendor_search_radius')");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }

    $isEnabled = filter_var($settings['auto_assign_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
    $timeoutSeconds = (int)($settings['auto_assign_timeout'] ?? 45);

    if (!$isEnabled) {
        die("Auto assign is disabled in admin settings.\n");
    }

    $db->beginTransaction();

    // 1. TIMEOUT HANDLER
    $stmt = $db->prepare("
        SELECT id, entity_type, entity_id 
        FROM auto_assignments 
        WHERE status = 'pending' 
          AND assigned_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
        FOR UPDATE
    ");
    $stmt->execute([$timeoutSeconds]);
    $timedOut = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($timedOut as $t) {
        $db->prepare("UPDATE auto_assignments SET status = 'timeout', responded_at = NOW() WHERE id = ?")->execute([$t['id']]);
        
        if ($t['entity_type'] === 'job') {
            $db->prepare("UPDATE jobs SET assignment_lock_time = NULL WHERE id = ?")->execute([$t['entity_id']]);
        } else {
            $db->prepare("UPDATE orders SET assignment_lock_time = NULL WHERE id = ?")->execute([$t['entity_id']]);
        }
        
        echo "Marked assignment {$t['id']} as timed out.\n";
    }

    // 2. FAIL-SAFE: Recover broken locks older than 2 minutes
    $db->exec("UPDATE jobs SET assignment_lock_time = NULL WHERE assignment_lock_time < DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
    $db->exec("UPDATE orders SET assignment_lock_time = NULL WHERE assignment_lock_time < DATE_SUB(NOW(), INTERVAL 2 MINUTE)");

    // 3. NEW ASSIGNMENTS
    $entitiesToAssign = $db->query("
        SELECT 'job' as type, j.id, j.status, j.user_id
        FROM jobs j
        LEFT JOIN bookings b ON j.booking_id = b.id
        WHERE j.status IN ('open', 'pending') 
          AND j.assignment_lock_time IS NULL
          AND (b.id IS NULL OR b.payment_method = 'cod' OR b.payment_status = 'paid')
        FOR UPDATE SKIP LOCKED
        UNION ALL
        SELECT 'order' as type, id, status, user_id
        FROM orders 
        WHERE status IN ('pending', 'confirmed') 
          AND parent_order_id IS NULL AND vendor_id IS NULL
          AND assignment_lock_time IS NULL
          AND (payment_method = 'cod' OR payment_status = 'paid')
        FOR UPDATE SKIP LOCKED
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($entitiesToAssign as $entity) {
        $type = $entity['type'];
        $id = $entity['id'];
        $userId = $entity['user_id'];

        $stmt = $db->prepare("
            SELECT status FROM auto_assignments 
            WHERE entity_type = ? AND entity_id = ? 
            ORDER BY assigned_at DESC LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$type, $id]);
        $lastAssignment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lastAssignment && in_array($lastAssignment['status'], ['pending', 'accepted'])) {
            continue; 
        }

        if ($type === 'job') {
            $db->prepare("UPDATE jobs SET assignment_lock_time = NOW() WHERE id = ?")->execute([$id]);
        } else {
            $db->prepare("UPDATE orders SET assignment_lock_time = NOW() WHERE id = ?")->execute([$id]);
        }

        // Send "Finding vendor..." to user if this is the first attempt
        if (!$lastAssignment) {
            $db->prepare("
                INSERT INTO alerts (type, title, message, ref_type, ref_id, user_id) 
                VALUES ('status_update', 'Finding a Vendor', 'We are looking for the best vendor for your request...', ?, ?, ?)
            ")->execute([$type, $id, $userId]);
        }

        $entityLat = 0;
        $entityLng = 0;

        if ($type === 'job') {
            $stmt = $db->prepare("SELECT a.lat, a.lng FROM jobs j JOIN bookings b ON j.booking_id = b.id JOIN addresses a ON b.address_id = a.id WHERE j.id = ?");
            $stmt->execute([$id]);
            $loc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($loc) {
                $entityLat = $loc['lat'];
                $entityLng = $loc['lng'];
            }
        } else {
            $stmt = $db->prepare("SELECT shipping_address FROM orders WHERE id = ?");
            $stmt->execute([$id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order && $order['shipping_address']) {
                $addr = json_decode($order['shipping_address'], true);
                $entityLat = $addr['lat'] ?? 0;
                $entityLng = $addr['lng'] ?? 0;
            }
        }

        // Fetch nearest online vendor
        $stmt = $db->prepare("
            SELECT v.id, 
            (6371 * acos(cos(radians(?)) * cos(radians(v.lat))
            * cos(radians(v.lng) - radians(?))
            + sin(radians(?)) * sin(radians(v.lat)))) AS distance
            FROM vendors v
            WHERE v.is_online = 1 AND v.status = 'active'
              AND v.id NOT IN (
                  SELECT vendor_id FROM auto_assignments 
                  WHERE entity_type = ? AND entity_id = ?
              )
            ORDER BY distance ASC LIMIT 1
        ");
        $stmt->execute([$entityLat, $entityLng, $entityLat, $type, $id]);
        $nextVendor = $stmt->fetchColumn();

        if ($nextVendor) {
            $db->prepare("
                INSERT INTO auto_assignments (entity_type, entity_id, vendor_id, status, assigned_at) 
                VALUES (?, ?, ?, 'pending', NOW())
            ")->execute([$type, $id, $nextVendor]);
            
            $db->prepare("
                INSERT INTO alerts (type, title, message, ref_type, ref_id, vendor_id) 
                VALUES ('order_new', 'New Request', ?, ?, ?, ?)
            ")->execute(["New $type #$id requires your acceptance.", $type, $id, $nextVendor]);
            
            echo "Assigned $type #$id to Vendor #$nextVendor\n";
        } else {
            echo "No vendors available for $type #$id. Marking as failed.\n";
            
            if ($type === 'job') {
                $db->prepare("UPDATE jobs SET status = 'cancelled', assignment_lock_time = NULL WHERE id = ?")->execute([$id]);
            } else {
                $db->prepare("UPDATE orders SET status = 'cancelled', assignment_lock_time = NULL WHERE id = ?")->execute([$id]);
            }
            
            // Notify user of failure
            if ($userId) {
                $db->prepare("
                    INSERT INTO alerts (type, title, message, ref_type, ref_id, user_id) 
                    VALUES ('system', 'Order Failed', 'No vendors are available at the moment. Please try again later.', ?, ?, ?)
                ")->execute([$type, $id, $userId]);
            }
        }
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}

flock($fp, LOCK_UN);
fclose($fp);
echo "Engine cycle complete.\n";
