<?php
/**
 * ReminderDelivery.php — WorkToGo Agent Layer :: reminder-delivery worker (Phase 9)
 * ----------------------------------------------------------------------------
 * The agent SET reminders in Phase 6 ("kal 11 baje yaad dilana", "weekly work-
 * photo upload") — they were stored, but never delivered. This worker turns a due
 * `agent_reminders` row into a real notification through the EXISTING channel and
 * reschedules it. It is the nervous-system's first proactive reflex.
 *
 * Design:
 *   • PURE + injectable — the DB and the "notify one user" delivery are passed in,
 *     so the cron wires the real FcmNotifier and the self-test injects a mock. No
 *     engine is touched; the agent owns this small table.
 *   • IDEMPOTENT — each due row is CLAIMED with a conditional UPDATE that advances
 *     next_at (or deactivates a 'once') BEFORE delivery. A claim that updates 0
 *     rows (another run already took it) is skipped, so a reminder never fires
 *     twice — even without the cron's flock. Recurring rows reschedule from NOW so
 *     a backlog never bursts.
 *   • SAFE — a delivery failure is recorded in last_error and never throws; a
 *     missing table degrades to "nothing to do".
 *
 * PRIVACY WALL: this is entirely our-system-side. No external AI is involved in
 * delivery; nothing about the user leaves the building.
 */

declare(strict_types=1);

final class ReminderDelivery
{
    /** Cadence → the interval used to reschedule the next fire (from NOW). */
    private const INTERVALS = ['daily' => '+1 day', 'weekly' => '+7 days'];

    public function __construct(private readonly PDO $db) {}

    /**
     * Deliver every reminder due at/of $now, up to $limit.
     *
     * @param callable $deliver fn(int $userId, string $title, string $body, array $data): bool
     *                          — returns true if the notification was dispatched.
     * @param int      $limit   max reminders per run (admin-tunable; the cron passes it).
     * @param string   $now     'Y-m-d H:i:s' reference time (injectable for tests).
     * @return array{due:int,delivered:int,rescheduled:int,completed:int,failed:int}
     */
    public function run(callable $deliver, int $limit = 200, string $now = ''): array
    {
        $now = $now !== '' ? $now : date('Y-m-d H:i:s');
        $summary = ['due' => 0, 'delivered' => 0, 'rescheduled' => 0, 'completed' => 0, 'failed' => 0];

        $due = $this->fetchDue($now, $limit);
        $summary['due'] = count($due);

        foreach ($due as $r) {
            $id      = (int) $r['id'];
            $cadence = strtolower((string) ($r['cadence'] ?? 'weekly'));
            $recurs  = isset(self::INTERVALS[$cadence]);
            $nextAt  = $recurs
                ? date('Y-m-d H:i:s', strtotime(self::INTERVALS[$cadence], strtotime($now)) ?: strtotime($now))
                : null;

            // CLAIM this row (idempotent): advance next_at + fire_count, deactivate a
            // 'once'. If 0 rows change, another run already took it — skip.
            if (!$this->claim($id, $now, $nextAt, $recurs)) {
                continue;
            }

            $title = (string) ($r['title'] ?? 'Reminder');
            $body  = $this->body($title);
            $data  = ['type' => 'agent_reminder', 'reminder_id' => (string) $id, 'url' => '/app/#/agent'];

            $ok = false;
            try {
                $ok = (bool) $deliver((int) $r['user_id'], $title, $body, $data);
            } catch (\Throwable $e) {
                $this->noteError($id, $e->getMessage());
            }

            if ($ok) {
                $summary['delivered']++;
                $recurs ? $summary['rescheduled']++ : $summary['completed']++;
            } else {
                $summary['failed']++;
                $this->noteError($id, 'delivery returned false');
            }
        }

        return $summary;
    }

    /** Due = active and next_at at/before now. */
    private function fetchDue(string $now, int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        try {
            $stmt = $this->db->prepare(
                "SELECT id, user_id, title, cadence, next_at
                   FROM agent_reminders
                  WHERE active = 1 AND next_at <= :now
                  ORDER BY next_at ASC
                  LIMIT {$limit}"
            );
            $stmt->execute([':now' => $now]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // Table absent (migration not applied) — nothing to deliver.
            return [];
        }
    }

    /**
     * Atomically claim a due row so it cannot be delivered twice. Recurring rows
     * advance next_at; a 'once' is deactivated. Returns true only if THIS call
     * won the row (rowCount === 1).
     */
    private function claim(int $id, string $now, ?string $nextAt, bool $recurs): bool
    {
        try {
            if ($recurs) {
                $stmt = $this->db->prepare(
                    "UPDATE agent_reminders
                        SET next_at = :next, last_fired_at = :now, fire_count = fire_count + 1, last_error = NULL
                      WHERE id = :id AND active = 1 AND next_at <= :now2"
                );
                $stmt->execute([':next' => $nextAt, ':now' => $now, ':id' => $id, ':now2' => $now]);
            } else {
                $stmt = $this->db->prepare(
                    "UPDATE agent_reminders
                        SET active = 0, last_fired_at = :now, fire_count = fire_count + 1, last_error = NULL
                      WHERE id = :id AND active = 1 AND next_at <= :now2"
                );
                $stmt->execute([':now' => $now, ':id' => $id, ':now2' => $now]);
            }
            return $stmt->rowCount() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function noteError(int $id, string $msg): void
    {
        try {
            $this->db->prepare("UPDATE agent_reminders SET last_error = :e WHERE id = :id")
                     ->execute([':e' => mb_substr($msg, 0, 250), ':id' => $id]);
        } catch (\Throwable $e) { /* best-effort */ }
    }

    /** A friendly, non-identifying reminder body (Hinglish, matches the agent's voice). */
    private function body(string $title): string
    {
        return 'Aapka reminder: ' . mb_substr($title, 0, 140) . ' ⏰';
    }
}
