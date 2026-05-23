<?php

/**
 * WorkToGo — Service Vendor Eligibility Helper
 *
 * Centralizes existing service-engine assignment checks without creating a
 * parallel routing system. Uses vendors.status/type/is_online, vendor service
 * area text, booking customer_locality, scheduled_at, jobs, and existing slots.
 */
class ServiceVendorEligibility
{
    public static function tableHasColumn(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
        return $cache[$key];
    }

    public static function vendorTypeColumn(PDO $db): string
    {
        return self::tableHasColumn($db, 'vendors', 'type') ? 'type' : 'vendor_type';
    }

    public static function buildVendorSelect(PDO $db, string $alias = 'v'): string
    {
        $typeCol = self::vendorTypeColumn($db);
        $parts = [
            "{$alias}.id",
            "{$alias}.business_name",
            "{$alias}.status",
            "{$alias}.{$typeCol} AS type",
            self::tableHasColumn($db, 'vendors', 'is_online') ? "{$alias}.is_online" : '1 AS is_online',
            self::tableHasColumn($db, 'vendors', 'service_localities') ? "{$alias}.service_localities" : 'NULL AS service_localities',
            self::tableHasColumn($db, 'vendors', 'service_area_notes') ? "{$alias}.service_area_notes" : 'NULL AS service_area_notes',
        ];
        return implode(', ', $parts);
    }

    public static function normalizeLocality(?string $value): string
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9\s,-]+/i', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return trim($value);
    }

    public static function localityCovered(?string $vendorLocalities, ?string $bookingLocality): array
    {
        $bookingLocality = self::normalizeLocality($bookingLocality);
        $vendorLocalities = trim((string)$vendorLocalities);

        if ($bookingLocality === '') {
            return ['known' => false, 'covered' => null, 'reason' => 'Booking locality missing'];
        }
        if ($vendorLocalities === '') {
            return ['known' => false, 'covered' => null, 'reason' => 'Vendor service area not set'];
        }

        $areas = preg_split('/[,\n]+/', $vendorLocalities) ?: [];
        foreach ($areas as $area) {
            $area = self::normalizeLocality($area);
            if ($area === '') continue;
            if ($area === '*' || str_contains($bookingLocality, $area) || str_contains($area, $bookingLocality)) {
                return ['known' => true, 'covered' => true, 'reason' => 'Locality covered'];
            }
        }

        return ['known' => true, 'covered' => false, 'reason' => 'Locality outside vendor service area'];
    }

    public static function availabilityFor(PDO $db, int $vendorId, ?string $scheduledAt, ?int $ignoreBookingId = null): array
    {
        if (!$scheduledAt || strtotime($scheduledAt) === false) {
            return ['known' => false, 'available' => null, 'reason' => 'Schedule missing'];
        }

        $ts = strtotime($scheduledAt);
        $day = (int)date('w', $ts);
        $date = date('Y-m-d', $ts);
        $time = date('H:i:s', $ts);

        $slotSql = "SELECT * FROM vendor_availability_slots WHERE vendor_id = ? AND day_of_week = ? AND start_time <= ? AND end_time > ?";
        if (self::tableHasColumn($db, 'vendor_availability_slots', 'is_active')) {
            $slotSql .= " AND is_active = 1";
        }
        $slotSql .= " ORDER BY start_time ASC LIMIT 1";
        $stmt = $db->prepare($slotSql);
        $stmt->execute([$vendorId, $day, $time, $time]);
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$slot) {
            return ['known' => false, 'available' => null, 'reason' => 'Vendor availability slots not set for scheduled time'];
        }

        $maxBookings = max(1, (int)($slot['max_bookings'] ?? 1));
        $activeStatuses = ['pending', 'confirmed', 'in_progress'];
        $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
        $params = [$vendorId, $date, $slot['start_time'], $slot['end_time'], ...$activeStatuses];

        $bookingSql = "SELECT COUNT(*) FROM bookings
                       WHERE vendor_id = ?
                         AND DATE(scheduled_at) = ?
                         AND TIME(scheduled_at) >= ?
                         AND TIME(scheduled_at) < ?
                         AND status IN ({$placeholders})";
        if ($ignoreBookingId) {
            $bookingSql .= " AND id <> ?";
            $params[] = $ignoreBookingId;
        }
        $bStmt = $db->prepare($bookingSql);
        $bStmt->execute($params);
        $current = (int)$bStmt->fetchColumn();
        $remaining = max(0, $maxBookings - $current);

        return [
            'known' => true,
            'available' => $remaining > 0,
            'reason' => $remaining > 0 ? 'Slot available' : 'Slot capacity full',
            'slot' => [
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'max_bookings' => $maxBookings,
                'current_bookings' => $current,
                'slots_remaining' => $remaining,
            ],
        ];
    }

    public static function evaluate(array $vendor, array $booking, array $availability): array
    {
        $isActive = (($vendor['status'] ?? '') === 'active');
        $isService = (($vendor['type'] ?? '') === 'service');
        $isOnline = (int)($vendor['is_online'] ?? 1) === 1;
        $area = self::localityCovered($vendor['service_localities'] ?? null, $booking['customer_locality'] ?? null);
        $reasons = [];

        if (!$isActive) $reasons[] = 'Vendor inactive';
        if (!$isService) $reasons[] = 'Not a service vendor';
        if (!$isOnline) $reasons[] = 'Vendor offline';
        if ($area['covered'] === false) $reasons[] = $area['reason'];
        if ($availability['available'] === false) $reasons[] = $availability['reason'];

        $assignable = $isActive && $isService && $isOnline && $area['covered'] !== false && $availability['available'] !== false;

        return [
            'active' => $isActive,
            'service_vendor' => $isService,
            'online' => $isOnline,
            'service_area' => $area,
            'availability' => $availability,
            'assignable' => $assignable,
            'status' => $assignable ? 'eligible' : 'not_eligible',
            'reasons' => $reasons,
        ];
    }
}
