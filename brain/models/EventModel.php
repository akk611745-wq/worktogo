<?php

/**
 * BrainCore - EventModel
 *
 * Handles all database operations for the brain_events table.
 * Controllers call this — they never write SQL directly.
 */

class EventModel
{
    /**
     * Check if a client_id exists and is active in brain_clients.
     *
     * @param  string $clientId
     * @return bool
     */
    public static function clientExists(string $clientId): bool
    {
        $db  = getDB();
        $sql = "SELECT id FROM brain_clients WHERE id = :id AND is_active = 1 LIMIT 1";
        $st  = $db->prepare($sql);
        $st->execute([':id' => $clientId]);

        return (bool) $st->fetch();
    }

    /**
     * Insert a new event into brain_events.
     *
     * @param  array  $data  Validated event data
     * @return string        The UUID of the inserted event
     */
    public static function store(array $data): string
    {
        $db  = getDB();
        $id  = self::generateUuid();

        $sql = "
            INSERT INTO brain_events
                (id, client_id, event_type, category, location, payload, ip_address, processed, created_at)
            VALUES
                (:id, :client_id, :event_type, :category, :location, :payload, :ip_address, 0, NOW())
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':id'         => $id,
            ':client_id'  => $data['client_id'],
            ':event_type' => $data['event_type'],
            ':category'   => $data['category']   ?? null,
            ':location'   => $data['location']   ?? null,
            ':payload'    => isset($data['payload']) ? json_encode($data['payload']) : null,
            ':ip_address' => $data['ip_address']  ?? null,
        ]);

        return $id;
    }

    /**
     * Generate a UUID v4.
     * Used as the primary key for every new event.
     *
     * @return string  e.g. "550e8400-e29b-41d4-a716-446655440000"
     */
    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);

        // Set version bits (v4) and variant bits (RFC 4122)
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
