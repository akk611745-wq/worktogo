<?php

require_once dirname(dirname(dirname(__DIR__))) . '/core/helpers/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/core/helpers/Response.php';
require_once dirname(dirname(dirname(__DIR__))) . '/core/helpers/JWT.php';
require_once dirname(dirname(dirname(__DIR__))) . '/heart/middleware/AuthMiddleware.php';

class SlotController {

    private function resolveVendorId(PDO $db, int $userId): int {
        $stmt = $db->prepare("SELECT id FROM vendors WHERE user_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            Response::forbidden('No vendor profile found for your account');
        }
        return (int)$row['id'];
    }

    public function setupAvailability() {
        $auth = AuthMiddleware::requireRole('vendor_service', 'admin');
        $db = getDB();
        $vendorId = $this->resolveVendorId($db, (int)$auth['user_id']);

        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['slots']) || !is_array($input['slots'])) {
            Response::validation('slots array is required');
        }

        try {
            $db->beginTransaction();

            $db->prepare("DELETE FROM vendor_availability_slots WHERE vendor_id = ?")->execute([$vendorId]);

            $stmt = $db->prepare("INSERT INTO vendor_availability_slots (vendor_id, day_of_week, start_time, end_time, max_bookings, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");

            foreach ($input['slots'] as $slot) {
                $day = (int)$slot['day_of_week'];
                if ($day < 0 || $day > 6) {
                    throw new Exception("Invalid day_of_week: $day. Must be 0-6.");
                }
                $start = $slot['start_time'];
                $end = $slot['end_time'];
                if ($start >= $end) {
                    throw new Exception("start_time must be before end_time");
                }
                $max = (int)($slot['max_bookings'] ?? 1);

                $stmt->execute([$vendorId, $day, $start, $end, $max]);
            }

            $db->commit();
            Response::success(['message' => 'Availability slots updated successfully']);
        } catch (Exception $e) {
            $db->rollBack();
            Response::error($e->getMessage(), 400);
        }
    }

    public function getVendorAvailability(int $vendorId) {
        $date = $_GET['date'] ?? null;
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Response::error('Invalid date format, use YYYY-MM-DD', 400);
        }
        
        if (strtotime($date) < strtotime(date('Y-m-d'))) {
            Response::error('Cannot check past dates', 400);
        }

        $db = getDB();

        // 1. Get day_of_week from the date param
        $dayOfWeek = (int)date('w', strtotime($date));

        // 2. Fetch vendor slots for that day
        $stmt = $db->prepare("SELECT * FROM vendor_availability_slots WHERE vendor_id = ? AND day_of_week = ?");
        $stmt->execute([$vendorId, $dayOfWeek]);
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$slots) {
            echo json_encode(['available' => false, 'message' => "Vendor hasn't set availability yet"]);
            exit;
        }

        $availableSlots = [];
        
        // 3. COUNT existing reservations for that vendor+date+time
        $resStmt = $db->prepare("SELECT slot_time, COUNT(*) as count FROM booking_slot_reservations WHERE vendor_id = ? AND slot_date = ? AND status = 'reserved' GROUP BY slot_time");
        $resStmt->execute([$vendorId, $date]);
        $reservations = [];
        while ($row = $resStmt->fetch(PDO::FETCH_ASSOC)) {
            $reservations[$row['slot_time']] = (int)$row['count'];
        }

        // 4. Return slots where current_bookings < max_bookings
        foreach ($slots as $slot) {
            $startTime = $slot['start_time'];
            $maxBookings = (int)$slot['max_bookings'];
            $currentBookings = $reservations[$startTime] ?? 0;
            
            $slotsRemaining = $maxBookings - $currentBookings;
            $status = $slotsRemaining > 0 ? 'available' : 'full';

            $availableSlots[] = [
                'start_time' => $startTime,
                'end_time' => $slot['end_time'],
                'slots_remaining' => max(0, $slotsRemaining),
                'status' => $status
            ];
        }

        Response::success(['date' => $date, 'slots' => $availableSlots]);
    }

    public function checkSlotAvailable() {
        $auth = AuthMiddleware::require();
        $input = json_decode(file_get_contents('php://input'), true);
        
        $vendorId = (int)($input['vendor_id'] ?? 0);
        $date = $input['date'] ?? null;
        $time = $input['time'] ?? null;

        if (!$vendorId || !$date || !$time) {
            Response::validation('vendor_id, date, and time are required');
        }

        $db = getDB();
        $dayOfWeek = (int)date('w', strtotime($date));
        
        $stmt = $db->prepare("SELECT * FROM vendor_availability_slots WHERE vendor_id = ? AND day_of_week = ? AND start_time = ?");
        $stmt->execute([$vendorId, $dayOfWeek, $time]);
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$slot) {
            echo json_encode(['available' => false, 'message' => 'No slot found for this time']);
            exit;
        }

        $resStmt = $db->prepare("SELECT COUNT(*) FROM booking_slot_reservations WHERE vendor_id = ? AND slot_date = ? AND slot_time = ? AND status = 'reserved'");
        $resStmt->execute([$vendorId, $date, $time]);
        $currentBookings = (int)$resStmt->fetchColumn();

        $maxBookings = (int)$slot['max_bookings'];
        $slotsRemaining = $maxBookings - $currentBookings;

        if ($slotsRemaining > 0) {
            Response::success(['available' => true, 'slots_remaining' => $slotsRemaining]);
        } else {
            // Find next available slot
            $nextStmt = $db->prepare("SELECT start_time FROM vendor_availability_slots WHERE vendor_id = ? AND day_of_week = ? AND start_time > ? ORDER BY start_time ASC LIMIT 1");
            $nextStmt->execute([$vendorId, $dayOfWeek, $time]);
            $nextSlot = $nextStmt->fetch(PDO::FETCH_ASSOC);
            
            Response::success([
                'available' => false, 
                'slots_remaining' => 0, 
                'next_available_slot' => $nextSlot ? $nextSlot['start_time'] : null
            ]);
        }
    }
}
