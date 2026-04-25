<?php

/**
 * BrainCore v3 — ActionEngine
 * PHP 7.2+ compatible
 *
 * Executes actions from resolved decisions.
 * Passes throttle_fp to ActionModel for indexed dedup.
 */

class ActionEngine
{
    private static $ACTION_MAP = [
        'notify_user'    => 'handleNotifyUser',
        'notify_vendor'  => 'handleNotifyVendor',
        'log_only'       => 'handleLogOnly',
        'show_popup'     => 'handleShowPopup',
        'boost_category' => 'handleBoostCategory',
        'none'           => 'handleLogOnly',
    ];

    public static function execute(
        string $decisionId,
        string $clientId,
        array  $decision
    ): array {
        $actionType = strtolower(trim($decision['action'] ?? 'log_only'));
        if (!array_key_exists($actionType, self::$ACTION_MAP)) {
            $actionType = 'log_only';
        }

        $payload = self::buildPayload($decision, $clientId);

        // ── ActionThrottle check ───────────────────────────────
        if (class_exists('ActionThrottle') && ActionThrottle::isThrottled($clientId, $actionType, $payload)) {
            return [
                'action_id'      => null,
                'action_type'    => $actionType,
                'mode'           => 'throttled',
                'status'         => 'throttled',
                'message'        => 'Duplicate action suppressed by ActionThrottle.',
                'webhook_fired'  => false,
                'webhook_status' => false,
            ];
        }

        // Compute fingerprint for indexed storage
        $throttleFp = class_exists('ActionThrottle')
            ? ActionThrottle::makeFingerprint($clientId, $actionType, $payload)
            : null;

        // ── Dispatch handler ───────────────────────────────────
        try {
            $handlerMethod = self::$ACTION_MAP[$actionType];
            $handlerResult = self::$handlerMethod($payload);
        } catch (\Throwable $e) {
            $handlerResult = [
                'success' => false,
                'mode'    => IntegrationConfig::getMode(),
                'status'  => 'failed',
                'message' => 'Handler threw an exception: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
            ];
        }

        $status = self::resolveStatus($handlerResult);

        // ── Alert on failure ───────────────────────────────────
        if ($status === 'failed' && class_exists('AlertEngine')) {
            AlertEngine::onActionFailure(
                $clientId,
                $actionType,
                $handlerResult['error'] ?? ($handlerResult['message'] ?? 'unknown error'),
                $decisionId
            );
        }

        // ── Store action with throttle_fp ──────────────────────
        $actionId = ActionModel::store(
            $decisionId,
            $clientId,
            $actionType,
            array_merge($payload, ['integration_result' => $handlerResult]),
            $status,
            $handlerResult['error'] ?? null,
            $throttleFp
        );

        // ── Insert feedback row ────────────────────────────────
        if ($status === 'executed' || $status === 'simulated') {
            FeedbackModel::insert(
                $decisionId,
                $actionId,
                $clientId,
                $actionType
            );
        }

        // ── Fire webhook (non-blocking) ────────────────────────
        $webhookResult = null;
        try {
            $webhookResult = IntegrationLayer::notifyWebhook(
                $decisionId,
                $clientId,
                $actionType,
                $decision
            );
        } catch (\Throwable $e) {
            $webhookResult = ['success' => false, 'error' => $e->getMessage()];
        }

        return [
            'action_id'      => $actionId,
            'action_type'    => $actionType,
            'mode'           => $handlerResult['mode']    ?? IntegrationConfig::getMode(),
            'status'         => $status,
            'message'        => $handlerResult['message'] ?? '',
            'webhook_fired'  => ($webhookResult['mode'] ?? '') !== 'skipped',
            'webhook_status' => $webhookResult['success'] ?? false,
        ];
    }

    // ── Handlers ───────────────────────────────────────────────

    private static function handleNotifyUser(array $payload): array
    {
        return IntegrationLayer::notifyUser($payload);
    }

    private static function handleNotifyVendor(array $payload): array
    {
        return IntegrationLayer::notifyVendor($payload);
    }

    private static function handleLogOnly(array $payload): array
    {
        return [
            'success' => true,
            'mode'    => 'internal',
            'status'  => 'executed',
            'message' => 'Action logged. No notification sent.',
            'error'   => null,
        ];
    }

    private static function handleShowPopup(array $payload): array
    {
        return IntegrationLayer::showPopup($payload);
    }

    private static function handleBoostCategory(array $payload): array
    {
        return IntegrationLayer::boostCategory($payload);
    }

    // ── Helpers ────────────────────────────────────────────────

    private static function resolveStatus(array $result): string
    {
        if (isset($result['status'])) return $result['status'];
        if (!($result['success'] ?? true)) return 'failed';

        $mode = $result['mode'] ?? 'simulation';
        if ($mode === 'live' || $mode === 'internal') return 'executed';
        return 'simulated';
    }

    private static function buildPayload(array $decision, string $clientId): array
    {
        return array_filter([
            'client_id'      => $clientId,
            'banner'         => $decision['banner']              ?? null,
            'boost_category' => $decision['boost_category']      ?? null,
            'action'         => $decision['action']              ?? null,
            'user_id'        => $decision['context']['user_id']  ?? null,
            'location'       => $decision['context']['location'] ?? null,
            'category'       => $decision['context']['category'] ?? null,
            'rule_matched'   => $decision['rule_matched']        ?? null,
            'source'         => $decision['source']              ?? null,
        ]);
    }
}
