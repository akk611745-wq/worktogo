<?php
/**
 * WORKTOGO — VENDOR ACCEPT ASSIGNMENT [PRODUCTION]
 * Handles vendor acceptance with race condition prevention and user notifications.
 */

require_once __DIR__ . '/../../core/helpers/Database.php';
require_once __DIR__ . '/../../core/helpers/Response.php';
require_once __DIR__ . '/../../heart/middleware/AuthMiddleware.php';

$db = getDB();
$auth = AuthMiddleware::requireRole('vendor_service', 'vendor_shopping');

$input = json_decode(file_get_contents('php://input'), true);
$entityType = $input['type'] ?? '';

// FIX 1: Strict integer validation — prevents SQL injection
$entityId = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
if ($entityId === false || $entityId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

if (!in_array($entityType, ['job', 'order'], true) || !$entityId) {
    Response::validation('Valid type (job/order) and id required');
}

try {
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT id, business_name FROM vendors WHERE user_id = ? LIMIT 1");
    $stmt->execute([$auth['user_id']]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vendor) {
        $db->rollBack();
        Response::forbidden('Vendor profile not found');
    }
    
    $vendorId = $vendor['id'];
    $vendorName = $vendor['business_name'];

    $stmt = $db->prepare("
        SELECT id, status 
        FROM auto_assignments 
        WHERE entity_type = ? AND entity_id = ? AND vendor_id = ? 
        FOR UPDATE
    ");
    $stmt->execute([$entityType, $entityId, $vendorId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        $db->rollBack();
        Response::error('Assignment not found for this vendor', 404);
    }

    if ($assignment['status'] !== 'pending') {
        $db->rollBack();
        Response::error("Cannot accept: Assignment is already {$assignment['status']}", 400);
    }

    if ($entityType === 'job') {
        // FIX 1: Prepared statement — was raw $entityId interpolation
        $stmtStatus = $db->prepare("SELECT status FROM jobs WHERE id = ? FOR UPDATE");
        $stmtStatus->execute([$entityId]);
        $entityStatus = $stmtStatus->fetchColumn();
        if (!in_array($entityStatus, ['open', 'pending'], true)) {
            $db->prepare("UPDATE auto_assignments SET status = 'timeout', responded_at = NOW() WHERE id = ?")->execute([$assignment['id']]);
            $db->rollBack();
            Response::error("Cannot accept: Job is already {$entityStatus}", 400);
        }
    } else {
        // FIX 1: Prepared statement — was raw $entityId interpolation
        $stmtStatus = $db->prepare("SELECT status FROM orders WHERE id = ? FOR UPDATE");
        $stmtStatus->execute([$entityId]);
        $entityStatus = $stmtStatus->fetchColumn();
        if (!in_array($entityStatus, ['pending', 'confirmed'], true)) {
            $db->prepare("UPDATE auto_assignments SET status = 'timeout', responded_at = NOW() WHERE id = ?")->execute([$assignment['id']]);
            $db->rollBack();
            Response::error("Cannot accept: Order is already {$entityStatus}", 400);
        }
    }

    $stmt = $db->prepare("
        SELECT id FROM auto_assignments 
        WHERE entity_type = ? AND entity_id = ? AND status = 'accepted'
    ");
    $stmt->execute([$entityType, $entityId]);
    if ($stmt->fetch()) {
        $db->prepare("UPDATE auto_assignments SET status = 'timeout', responded_at = NOW() WHERE id = ?")->execute([$assignment['id']]);
        $db->rollBack();
        Response::error('Order has already been accepted by another vendor', 409);
    }

    $db->prepare("
        UPDATE auto_assignments 
        SET status = 'accepted', responded_at = NOW() 
        WHERE id = ?
    ")->execute([$assignment['id']]);

    $userId = null;
    if ($entityType === 'job') {
        $db->prepare("
            UPDATE jobs
            SET status = 'in_progress', vendor_id = ?, assignment_lock_time = NULL
            WHERE id = ?
        ")->execute([$vendorId, $entityId]);

        // FIX 1: Prepared statement — was raw $entityId interpolation
        $stmtUid = $db->prepare("SELECT user_id FROM jobs WHERE id = ?");
        $stmtUid->execute([$entityId]);
        $userId = $stmtUid->fetchColumn();
    } else {
        $db->prepare("
            UPDATE orders
            SET status = 'processing', vendor_id = ?, assignment_lock_time = NULL
            WHERE id = ?
        ")->execute([$vendorId, $entityId]);

        // FIX 1: Prepared statement — was raw $entityId interpolation
        $stmtUid = $db->prepare("SELECT user_id FROM orders WHERE id = ?");
        $stmtUid->execute([$entityId]);
        $userId = $stmtUid->fetchColumn();
    }

    $db->prepare("
        INSERT INTO alerts (type, title, message, ref_type, ref_id, vendor_id) 
        VALUES ('status_update', 'Assignment Accepted', ?, ?, ?, ?)
    ")->execute(["You have successfully accepted $entityType #$entityId.", $entityType, $entityId, $vendorId]);

    // User Feedback Notification
    if ($userId) {
        $db->prepare("
            INSERT INTO alerts (type, title, message, ref_type, ref_id, user_id) 
            VALUES ('order_accepted', 'Vendor Assigned!', ?, ?, ?, ?)
        ")->execute(["Your $entityType has been assigned to $vendorName.", $entityType, $entityId, $userId]);
    }

    $db->commit();
    Response::success(['message' => 'Assignment accepted successfully']);

} catch (Exception $e) {
    $db->rollBack();
    Response::error('An error occurred during acceptance', 500);
}
