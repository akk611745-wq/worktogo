<?php

/**
 * BrainCore v3 — EventController
 * PHP 7.2+ compatible
 *
 * Handles: POST /api/event
 * Auth: X-Api-Key header
 */

class EventController
{
    public static function store(): void
    {
        $input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $apiKey = trim($_SERVER['HTTP_X_API_KEY'] ?? '');

        if ($apiKey === '') {
            Response::error('Missing required header: X-Api-Key', 401);
        }

        $required = ['client_id', 'event_type'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                Response::error("Missing required field: $field", 422);
            }
        }

        $clientId  = trim($input['client_id']);
        $eventType = trim($input['event_type']);

        if (!ClientModel::validateApiKey($clientId, $apiKey)) {
            Response::error('Invalid client_id or X-Api-Key', 401);
        }

        // Input length validation
        $location  = mb_substr(strtolower(trim($input['location'] ?? '')), 0, 100) ?: null;
        $category  = mb_substr(strtolower(trim($input['category'] ?? '')), 0, 100) ?: null;
        $userId    = mb_substr(trim($input['user_id']   ?? ''), 0, 100) ?: null;
        $eventType = mb_substr($eventType, 0, 100);

        $eventId = EventModel::store([
            'client_id'  => $clientId,
            'event_type' => $eventType,
            'user_id'    => $userId,
            'category'   => $category,
            'location'   => $location,
            'payload'    => $input['payload'] ?? null,
            'ip_address' => self::getClientIp(),
        ]);

        // Update user profile if user_id + category provided
        if ($userId && $category) {
            try {
                UserProfileModel::updateScore($userId, $clientId, $eventType, $category);
            } catch (\Throwable $e) {
                error_log('[EventController] UserProfile update failed: ' . $e->getMessage());
            }
        }

        // Alert engine hooks
        if (class_exists('AlertEngine')) {
            try {
                AlertEngine::onEventStored($clientId, $input);
            } catch (\Throwable $e) {}
        }

        Response::success(['event_id' => $eventId, 'status' => 'stored']);
    }

    private static function getClientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
