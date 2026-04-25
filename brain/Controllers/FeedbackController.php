<?php

/**
 * BrainCore v3 — FeedbackController
 * PHP 7.2+ compatible
 *
 * Handles: POST /api/feedback
 * Auth: X-Api-Key header
 */

class FeedbackController
{
    public static function record(): void
    {
        $input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $apiKey = trim($_SERVER['HTTP_X_API_KEY'] ?? '');

        if ($apiKey === '') {
            Response::error('Missing required header: X-Api-Key', 401);
        }

        $required = ['client_id', 'action_id'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                Response::error("Missing required field: $field", 422);
            }
        }

        $clientId = trim($input['client_id']);
        $actionId = trim($input['action_id']);

        if (!ClientModel::validateApiKey($clientId, $apiKey)) {
            Response::error('Invalid client_id or X-Api-Key', 401);
        }

        $response = trim($input['user_response'] ?? 'clicked');
        if (!in_array($response, ['clicked', 'dismissed', 'ignored'], true)) {
            $response = 'clicked';
        }

        $updated = FeedbackModel::update($actionId, $clientId, $response);

        if (!$updated) {
            Response::error('Action ID not found or already updated.', 404);
        }

        Response::success(['status' => 'recorded', 'user_response' => $response]);
    }
}
