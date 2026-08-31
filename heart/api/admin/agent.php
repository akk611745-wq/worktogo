<?php
/**
 * /api/admin/agent/* — Agent control-room monitor (admin only)
 * ----------------------------------------------------------------------------
 * Read-only monitor for the admin control-room. The ROLE_ADMIN gate is already
 * applied by heart/api/admin/index.php; this file only reads. It surfaces:
 *   • the live governance snapshot (kill-switch, capabilities, limits, budget)
 *   • which AI providers are usable + the per-task routing (the flexible AI layer)
 *   • a 24h activity summary + a recent activity feed
 * — ALL from the existing agent audit tables (agent_action_log / agent_ai_log),
 * METADATA ONLY (no PII, no message text). Settings are CHANGED via the existing
 * PUT /api/admin/settings/{key}; the orchestrator/planner/entry read + enforce
 * them (see core/agent/AgentPolicy.php).
 *
 * Uses the admin front-controller globals: $db (PDO), $method, $uri, Response.
 */

declare(strict_types=1);

require_once SYSTEM_ROOT . '/core/agent/bootstrap.php';

// ── GET /api/admin/agent/overview ────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/admin/agent/overview') {
    $policy  = new AgentPolicy($db);
    $manager = new AiProviderManager($db);

    $providers = [];
    foreach ($manager->knownProviders() as $p) {
        $providers[$p] = $manager->isUsable($p);   // booleans only — never a key
    }

    $summary = ['turns' => 0, 'blocked' => 0, 'pii_blocked' => 0, 'rejected' => 0, 'ai_calls' => 0, 'tokens' => 0, 'tools' => []];
    try {
        $byStatus = [];
        foreach ($db->query(
            "SELECT status, COUNT(*) c FROM agent_action_log
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byStatus[(string) $row['status']] = (int) $row['c'];
            $summary['turns'] += (int) $row['c'];
        }
        $summary['blocked']     = $byStatus['blocked']     ?? 0;
        $summary['pii_blocked'] = $byStatus['pii_blocked'] ?? 0;
        $summary['rejected']    = $byStatus['rejected']    ?? 0;

        foreach ($db->query(
            "SELECT tool, COUNT(*) c FROM agent_action_log
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND tool <> '-'
              GROUP BY tool ORDER BY c DESC LIMIT 12"
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary['tools'][] = ['tool' => $row['tool'], 'count' => (int) $row['c']];
        }
    } catch (\Throwable $e) { /* tables not migrated yet — empty summary */ }

    try {
        $a = $db->query(
            "SELECT COUNT(*) c, COALESCE(SUM(prompt_tokens + completion_tokens), 0) tok
               FROM agent_ai_log
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND provider <> 'pending'"
        )->fetch(PDO::FETCH_ASSOC);
        $summary['ai_calls'] = (int) ($a['c'] ?? 0);
        $summary['tokens']   = (int) ($a['tok'] ?? 0);
    } catch (\Throwable $e) { /* ignore */ }

    Response::success([
        'settings'    => $policy->snapshot(),
        'providers'   => $providers,
        'routing'     => [
            'understand' => $manager->routeFor('understand'),
            'generate'   => $manager->routeFor('generate'),
        ],
        'summary_24h' => $summary,
    ]);
    exit;
}

// ── GET /api/admin/agent/activity?limit=50 ───────────────────────────────────
if ($method === 'GET' && $uri === '/api/admin/agent/activity') {
    $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
    $rows  = [];
    try {
        $stmt = $db->prepare(
            "SELECT request_id, user_id, tool, mode, source, status, http_status, created_at
               FROM agent_action_log ORDER BY id DESC LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { /* table not migrated yet */ }
    Response::success(['activity' => $rows]);
    exit;
}

Response::notFound('Agent admin endpoint');
