<?php

require_once dirname(dirname(dirname(__DIR__))) . '/core/helpers/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/core/helpers/Response.php';
require_once __DIR__ . '/SlotController.php';

class BookingController {

    public function createBooking() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $vendorId = (int)($input['vendor_id'] ?? 0);
        $date = $input['date'] ?? null;
        $time = $input['time'] ?? null;
        $userId = (int)($input['user_id'] ?? 0);
        
        if (!$vendorId || !$date || !$time || !$userId) {
            Response::validation('vendor_id, date, time, and user_id are required');
        }

        $db = getDB();
        
        try {
            $db->beginTransaction();

            // Insert booking row (Simplified for this fresh controller)
            $bookingNum = 'WTG-BKG-' . strtoupper(bin2hex(random_bytes(4)));
            $bStmt = $db->prepare(
                "INSERT INTO bookings (booking_number, user_id, vendor_id, status, scheduled_at, created_at)
                 VALUES (:bnum, :uid, :vid, 'pending', :sched, NOW())"
            );
            $scheduledAt = $date . ' ' . $time . ':00';
            $bStmt->execute([
                ':bnum' => $bookingNum,
                ':uid'  => $userId,
                ':vid'  => $vendorId,
                ':sched'=> $scheduledAt
            ]);
            $bookingId = (int)$db->lastInsertId();

            // 1. Call checkSlotAvailable(vendor_id, date, time)
            // Simulating the check internally since checkSlotAvailable via HTTP would require loopback
            $slotCtrl = new SlotController();
            
            // Check availability directly
            $dayOfWeek = (int)date('w', strtotime($date));
            $slotStmt = $db->prepare("SELECT * FROM vendor_availability_slots WHERE vendor_id = ? AND day_of_week = ? AND start_time = ? FOR UPDATE");
            $slotStmt->execute([$vendorId, $dayOfWeek, $time]);
            $slot = $slotStmt->fetch(PDO::FETCH_ASSOC);
            
            $available = false;
            if ($slot) {
                $resStmt = $db->prepare("SELECT COUNT(*) FROM booking_slot_reservations WHERE vendor_id = ? AND slot_date = ? AND slot_time = ? AND status = 'reserved' FOR UPDATE");
                $resStmt->execute([$vendorId, $date, $time]);
                $currentBookings = (int)$resStmt->fetchColumn();
                $slotsRemaining = (int)$slot['max_bookings'] - $currentBookings;
                if ($slotsRemaining > 0) {
                    $available = true;
                }
            }

            // 2. If available: INSERT booking_slot_reservations
            if ($available) {
                $resvStmt = $db->prepare("INSERT INTO booking_slot_reservations (booking_id, vendor_id, slot_date, slot_time, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'reserved', NOW(), NOW())");
                $resvStmt->execute([$bookingId, $vendorId, $date, $time]);
                $db->commit();
                Response::success(['message' => 'Booking created successfully', 'booking_id' => $bookingId]);
            } else {
                // 3. If NOT available: rollback booking, return 409 error
                $db->rollBack();
                Response::error('Slot just got booked, please choose another time', 409);
            }

        } catch (Exception $e) {
            $db->rollBack();
            Response::error($e->getMessage(), 500);
        }
    }

    public function releaseSlot($bookingId) {
        $db = getDB();
        $stmt = $db->prepare("UPDATE booking_slot_reservations SET status = 'cancelled', updated_at = NOW() WHERE booking_id = ?");
        $stmt->execute([(int)$bookingId]);
    }
}
