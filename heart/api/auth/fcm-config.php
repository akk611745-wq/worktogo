<?php
// ============================================================
//  GET /api/auth/fcm-config
//  Public Firebase Web SDK config (no secrets — apiKey/appId/etc.
//  are designed to be exposed client-side). Lets static admin/
//  vendor pages get this from .env without hardcoding it in JS.
// ============================================================

Response::success([
    'apiKey'    => getenv('FCM_API_KEY') ?: '',
    'projectId' => getenv('FCM_PROJECT_ID') ?: '',
    'senderId'  => getenv('FCM_SENDER_ID') ?: '',
    'appId'     => getenv('FCM_APP_ID') ?: '',
    'vapidKey'  => getenv('FCM_VAPID_KEY') ?: '',
]);
