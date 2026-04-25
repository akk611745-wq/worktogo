<?php

/**
 * BrainCore - IntegrationLayer
 *
 * Executes outbound calls for each action type.
 *
 * ─── THE SWITCH ──────────────────────────────────────────────
 * Every method checks IntegrationConfig::isLive():
 *
 *   simulation mode → returns fake success (same as Step 8)
 *   live mode       → calls real external API via HttpClient
 *                     if API fails → logs error, returns graceful failure
 *
 * ─── SAFETY GUARANTEE ────────────────────────────────────────
 * This layer NEVER throws. Every failure is caught, logged,
 * and returned as a structured result. The main decision flow
 * is never broken by an integration failure.
 *
 * ─── WEBHOOK SUPPORT ─────────────────────────────────────────
 * notifyWebhook() sends the full decision payload to any
 * external system that wants to receive BrainCore events.
 * Endpoint configured via INTEGRATION_WEBHOOK_URL in .env.
 *
 * ─── ADDING NEW INTEGRATIONS ─────────────────────────────────
 * 1. Add endpoint key to IntegrationConfig
 * 2. Add .env variable (INTEGRATION_SOMETHING_URL)
 * 3. Add method here following the same pattern
 * 4. Call from ActionEngine
 * ────────────────────────────────────────────────────────────
 */

class IntegrationLayer
{
    /**
     * Send a user notification.
     *
     * Simulation: returns fake success.
     * Live: POSTs to INTEGRATION_NOTIFY_USER_URL
     *
     * Expected external API format:
     * POST {url}
     * {
     *   "user_id":    "...",
     *   "message":    "...",
     *   "client_id":  "...",
     *   "location":   "...",
     *   "category":   "...",
     *   "source":     "braincore"
     * }
     *
     * @param  array $payload  From ActionEngine::buildPayload()
     * @return array           { success, mode, message, error, http_status }
     */
    public static function notifyUser(array $payload): array
    {
        $mode = IntegrationConfig::getMode();

        // ── Simulation ─────────────────────────────────────────
        if (!IntegrationConfig::isLive()) {
            return self::simulated(
                'notify_user',
                "User notification simulated → user_id=" . ($payload['user_id'] ?? 'unknown')
                . " | banner: " . ($payload['banner'] ?? 'N/A')
            );
        }

        // ── Live ───────────────────────────────────────────────
        $url = IntegrationConfig::getEndpoint('notify_user');

        if ($url === null) {
            return self::configMissing('notify_user', 'INTEGRATION_NOTIFY_USER_URL');
        }

        $result = HttpClient::post(
            url:     $url,
            body:    [
                'user_id'   => $payload['user_id']   ?? null,
                'message'   => $payload['banner']    ?? 'Notification from BrainCore',
                'client_id' => $payload['client_id'] ?? null,
                'location'  => $payload['location']  ?? null,
                'category'  => $payload['category']  ?? null,
                'source'    => 'braincore',
            ],
            token:   IntegrationConfig::getToken('notify_user'),
            timeout: IntegrationConfig::getTimeout()
        );

        return self::fromHttpResult('notify_user', $result);
    }

    /**
     * Send a vendor alert.
     *
     * Simulation: returns fake success.
     * Live: POSTs to INTEGRATION_NOTIFY_VENDOR_URL
     *
     * Expected external API format:
     * POST {url}
     * {
     *   "category":   "...",
     *   "location":   "...",
     *   "client_id":  "...",
     *   "rule_matched": "...",
     *   "source":     "braincore"
     * }
     *
     * @param  array $payload
     * @return array
     */
    public static function notifyVendor(array $payload): array
    {
        if (!IntegrationConfig::isLive()) {
            return self::simulated(
                'notify_vendor',
                "Vendor alert simulated → category=" . ($payload['boost_category'] ?? $payload['category'] ?? 'N/A')
                . " | location=" . ($payload['location'] ?? 'unknown')
            );
        }

        $url = IntegrationConfig::getEndpoint('notify_vendor');

        if ($url === null) {
            return self::configMissing('notify_vendor', 'INTEGRATION_NOTIFY_VENDOR_URL');
        }

        $result = HttpClient::post(
            url:     $url,
            body:    [
                'category'     => $payload['boost_category'] ?? $payload['category'] ?? null,
                'location'     => $payload['location']       ?? null,
                'client_id'    => $payload['client_id']      ?? null,
                'rule_matched' => $payload['rule_matched']   ?? null,
                'source'       => 'braincore',
            ],
            token:   IntegrationConfig::getToken('notify_vendor'),
            timeout: IntegrationConfig::getTimeout()
        );

        return self::fromHttpResult('notify_vendor', $result);
    }

    /**
     * Trigger a frontend popup instruction.
     *
     * Simulation: returns fake success.
     * Live: POSTs to INTEGRATION_POPUP_URL
     *
     * @param  array $payload
     * @return array
     */
    public static function showPopup(array $payload): array
    {
        if (!IntegrationConfig::isLive()) {
            return self::simulated(
                'show_popup',
                "Popup trigger simulated → banner: " . ($payload['banner'] ?? 'N/A')
            );
        }

        $url = IntegrationConfig::getEndpoint('show_popup');

        if ($url === null) {
            return self::configMissing('show_popup', 'INTEGRATION_POPUP_URL');
        }

        $result = HttpClient::post(
            url:     $url,
            body:    [
                'banner'    => $payload['banner']    ?? null,
                'client_id' => $payload['client_id'] ?? null,
                'user_id'   => $payload['user_id']   ?? null,
                'source'    => 'braincore',
            ],
            timeout: IntegrationConfig::getTimeout()
        );

        return self::fromHttpResult('show_popup', $result);
    }

    /**
     * Send a category boost instruction.
     *
     * Simulation: returns fake success.
     * Live: POSTs to INTEGRATION_BOOST_URL
     *
     * @param  array $payload
     * @return array
     */
    public static function boostCategory(array $payload): array
    {
        if (!IntegrationConfig::isLive()) {
            return self::simulated(
                'boost_category',
                "Category boost simulated → category: " . ($payload['boost_category'] ?? $payload['category'] ?? 'N/A')
            );
        }

        $url = IntegrationConfig::getEndpoint('boost_category');

        if ($url === null) {
            return self::configMissing('boost_category', 'INTEGRATION_BOOST_URL');
        }

        $result = HttpClient::post(
            url:     $url,
            body:    [
                'category'  => $payload['boost_category'] ?? $payload['category'] ?? null,
                'location'  => $payload['location']       ?? null,
                'client_id' => $payload['client_id']      ?? null,
                'source'    => 'braincore',
            ],
            timeout: IntegrationConfig::getTimeout()
        );

        return self::fromHttpResult('boost_category', $result);
    }

    /**
     * Send a webhook event to an external system.
     *
     * Webhooks are fired after every decision (regardless of action type)
     * if INTEGRATION_WEBHOOK_URL is set in .env.
     *
     * This lets any external system subscribe to BrainCore decisions.
     *
     * Payload format:
     * POST {INTEGRATION_WEBHOOK_URL}
     * {
     *   "event":       "decision.executed",
     *   "decision_id": "...",
     *   "client_id":   "...",
     *   "action_type": "...",
     *   "decision":    { ... full decision output ... },
     *   "timestamp":   "2025-01-01T12:00:00Z",
     *   "source":      "braincore"
     * }
     *
     * @param  string $decisionId
     * @param  string $clientId
     * @param  string $actionType
     * @param  array  $decision    Full decision output
     * @return array
     */
    public static function notifyWebhook(
        string $decisionId,
        string $clientId,
        string $actionType,
        array  $decision
    ): array {
        // Webhook only fires in live mode AND if URL is configured
        $url = IntegrationConfig::getEndpoint('webhook');

        if ($url === null) {
            // Silently skip — webhook is optional
            return [
                'success' => true,
                'mode'    => 'skipped',
                'message' => 'Webhook not configured. Set INTEGRATION_WEBHOOK_URL to enable.',
                'error'   => null,
            ];
        }

        if (!IntegrationConfig::isLive()) {
            return self::simulated(
                'webhook',
                "Webhook simulated → decision_id={$decisionId} | action={$actionType}"
            );
        }

        $result = HttpClient::post(
            url:     $url,
            body:    [
                'event'       => 'decision.executed',
                'decision_id' => $decisionId,
                'client_id'   => $clientId,
                'action_type' => $actionType,
                'decision'    => $decision,
                'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
                'source'      => 'braincore',
            ],
            token:   IntegrationConfig::getToken('webhook'),
            timeout: IntegrationConfig::getTimeout()
        );

        return self::fromHttpResult('webhook', $result);
    }

    // ──────────────────────────────────────────────────────────
    // PRIVATE: Result builders
    // ──────────────────────────────────────────────────────────

    /**
     * Build a simulated success result.
     */
    private static function simulated(string $type, string $message): array
    {
        return [
            'success'     => true,
            'mode'        => 'simulation',
            'action_type' => $type,
            'message'     => $message,
            'http_status' => null,
            'error'       => null,
        ];
    }

    /**
     * Config key missing — endpoint not set in .env.
     * Returns safe non-fatal result.
     */
    private static function configMissing(string $type, string $envKey): array
    {
        return [
            'success'     => false,
            'mode'        => 'live',
            'action_type' => $type,
            'message'     => "Integration skipped: {$envKey} not set in .env",
            'http_status' => null,
            'error'       => "Missing config: {$envKey}",
        ];
    }

    /**
     * Convert an HttpClient result to a standard IntegrationLayer result.
     */
    private static function fromHttpResult(string $type, array $http): array
    {
        return [
            'success'     => $http['success'],
            'mode'        => 'live',
            'action_type' => $type,
            'message'     => $http['success']
                               ? "Live call succeeded → HTTP {$http['http_status']}"
                               : "Live call failed → " . ($http['error'] ?? 'Unknown error'),
            'http_status' => $http['http_status'],
            'error'       => $http['error'],
        ];
    }
}
