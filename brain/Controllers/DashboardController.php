<?php

/**
 * BrainCore — DashboardController
 *
 * Phase C: Master Dashboard Layer
 *
 * ─── ENDPOINT ────────────────────────────────────────────────
 *
 *   GET /api/admin/dashboard
 *
 * ─── AUTHENTICATION ──────────────────────────────────────────
 *
 *   Two-layer auth (same as all admin endpoints):
 *
 *   Layer 1 — client_id + api_key (query params)
 *     Identifies which client's data to read.
 *
 *   Layer 2 — X-Admin-Key header (must match ADMIN_KEY in .env)
 *     Ensures only admins can call this endpoint.
 *
 * ─── QUERY PARAMS ────────────────────────────────────────────
 *
 *   Required:
 *     client_id    Which client's dashboard to load
 *     api_key      Client API key (validates identity)
 *
 * ─── READ-ONLY ───────────────────────────────────────────────
 *
 *   This controller never writes to the database.
 *   It only reads and assembles data via DashboardModel.
 *
 * ─── RESPONSE STRUCTURE ──────────────────────────────────────
 *
 *   {
 *     "status": "ok",
 *     "data": {
 *       "client_id":   "worktogo",
 *       "generated_at": "2026-03-29T10:00:00Z",
 *       "overview":    { ... },
 *       "live":        { ... },
 *       "patterns":    { ... },
 *       "alerts":      { ... },
 *       "performance": { ... }
 *     }
 *   }
 */

class DashboardController
{
    // ──────────────────────────────────────────────────────────
    // ADMIN KEY GUARD
    // ──────────────────────────────────────────────────────────

    /**
     * Verify X-Admin-Key header against ADMIN_KEY in .env.
     *
     * Copied from AdminController — each controller is self-contained.
     * If ADMIN_KEY is not configured → blocks all access (fail-secure).
     */
    private static function requireAdminKey(): void
    {
        $envKey = trim(getenv('ADMIN_KEY') ?: '');

        if ($envKey === '') {
            Response::error(
                'Admin access is not configured. Set ADMIN_KEY in .env.',
                503
            );
        }

        $headerKey = trim($_SERVER['HTTP_X_ADMIN_KEY'] ?? '');

        if ($headerKey === '') {
            Response::error('Admin access requires X-Admin-Key header.', 403);
        }

        // Constant-time compare — prevents timing oracle attacks
        if (!hash_equals($envKey, $headerKey)) {
            Response::error('Invalid admin key.', 403);
        }
    }

    // ──────────────────────────────────────────────────────────
    // GET /api/admin/dashboard
    // ──────────────────────────────────────────────────────────

    /**
     * Master dashboard endpoint.
     *
     * Returns five data sections in a single response:
     *
     *   overview    — all-time totals (events, decisions, actions, alerts)
     *   live        — last-hour activity + pending alert count
     *   patterns    — top categories, locations, event types, IPs
     *   alerts      — severity buckets, type buckets, recent critical alerts
     *   performance — action success/failure rates, rule match rate
     *
     * All data is scoped to the authenticated client_id.
     * This endpoint is intentionally READ-ONLY.
     *
     * @return void  Outputs JSON and exits
     */
    public static function index(): void
    {
        // ── Auth Layer 1: admin key ────────────────────────────
        self::requireAdminKey();

        // ── Auth Layer 2: client credentials ──────────────────
        $input    = $_GET;
        $clientId = trim($input['client_id'] ?? '');
        $apiKey   = trim($input['api_key']   ?? '');

        if ($clientId === '' || $apiKey === '') {
            Response::error('Missing required param: client_id or api_key', 422);
        }

        if (!ClientModel::validateApiKey($clientId, $apiKey)) {
            Response::error('Invalid client_id or api_key.', 401);
        }

        // ── Build dashboard ────────────────────────────────────
        try {
            $dashboard = DashboardModel::getFullDashboard($clientId);
        } catch (\Throwable $e) {
            // Log to PHP error log — never expose DB internals to caller
            error_log('[BrainCore][Dashboard] Error for client=' . $clientId . ': ' . $e->getMessage());
            Response::error('Dashboard data could not be loaded. Check server logs.', 500);
        }

        // ── Respond ────────────────────────────────────────────
        Response::success(array_merge(
            [
                'client_id'    => $clientId,
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),  // UTC ISO-8601
            ],
            $dashboard
        ));
    }
}
