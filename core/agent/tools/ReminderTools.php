<?php
/**
 * ReminderTools.php — WorkToGo Agent Layer :: reminder read tool (Phase 9)
 * ----------------------------------------------------------------------------
 * Phase 6 gave the agent `set_reminder` (a write). Phase 9 makes reminders
 * proactive — a cron DELIVERS them (see ReminderDelivery) — and this tool lets the
 * agent SURFACE them in chat: "aapke reminders" → the user's upcoming/pending
 * reminders, straight from the agent's own store.
 *
 * Read-only, own-data: it lists ONLY the calling user's reminders (keyed by the
 * internal user_id), so it is safe for any registered role (customer or vendor).
 * No PII reaches the AI — this is our data, engine/store → agent → the owner.
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentTool.php';

/** List the calling user's own upcoming/active reminders. */
final class ListRemindersTool extends AbstractTool
{
    public function name(): string { return 'list_reminders'; }
    public function description(): string { return 'Show the user\'s own upcoming reminders (title, cadence, when it is next due).'; }
    public function allowedRoles(): array { return ['customer', 'vendor_service', 'vendor_shopping']; }
    public function argSpec(): array { return ['limit' => 'int?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a     = $this->clean($args);
        $limit = max(1, min(20, (int) ($a['limit'] ?? 10)));

        $reminders = [];
        if ($tc->db instanceof PDO) {
            try {
                $stmt = $tc->db->prepare(
                    "SELECT title, cadence, next_at, active, last_fired_at
                       FROM agent_reminders
                      WHERE user_id = :uid AND active = 1
                      ORDER BY next_at ASC
                      LIMIT {$limit}"
                );
                $stmt->execute([':uid' => $tc->ctx->userId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $reminders[] = [
                        'title'   => (string) ($r['title'] ?? 'Reminder'),
                        'cadence' => (string) ($r['cadence'] ?? 'weekly'),
                        'next_at' => (string) ($r['next_at'] ?? ''),
                    ];
                }
            } catch (\Throwable $e) {
                // Table absent (migration not applied) — return an empty, honest list.
                if (class_exists('Logger')) { Logger::warning('agent.list_reminders skipped', ['error' => $e->getMessage()]); }
            }
        }

        return EngineResult::ok(200, ['reminders' => $reminders]);
    }
}
