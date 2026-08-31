<?php
/**
 * proactive_selftest.php — WorkToGo Agent Layer :: Phase 9 proof (no key, no DB)
 * ----------------------------------------------------------------------------
 * Proves the proactive nervous-system logic WITHOUT a live DB, by injecting tiny
 * fake PDOs that model exactly the queries the workers run. It asserts the real
 * decision branches — not the fakes:
 *
 *   REMINDER DELIVERY (ReminderDelivery):
 *     R1  a due reminder is picked up and DELIVERED (mock notification fires).
 *     R2  a WEEKLY reminder reschedules (+7 days) — the claim writes the next_at.
 *     R3  a ONCE reminder is completed (deactivated), not rescheduled.
 *     R4  IDEMPOTENT — if the claim wins 0 rows (another run took it), delivery
 *         does NOT fire (no double-send).
 *     R5  a delivery that returns false is counted failed + an error is noted.
 *
 *   DEMAND DETECTION (DemandSignals):
 *     D1  record() writes ONE demand event with the right metadata (no PII).
 *     D2  a high-demand / low-supply signal (5 asks, 3 users, 0 supply) RAISES an
 *         admin alert exactly once, and marks the signal alerted.
 *     D3  a below-threshold signal (2 asks) does NOT alert.
 *     D4  a signal already alerted inside the cooldown does NOT re-alert.
 *
 *   Run:  php core/agent/tests/proactive_selftest.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../ReminderDelivery.php';
require_once __DIR__ . '/../DemandSignals.php';

$pass = 0; $fail = 0;
function check(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; }
    else       { $fail++; echo "FAIL  $label\n"; }
}

// ── A tiny fake PDOStatement: rows/rowCount configured per prepared query ─────
class FakeStmt extends PDOStatement
{
    public array $params = [];
    /** @var array<int,array> */
    public array $rows = [];
    public int $rc = 0;
    /** @var callable|null fn(FakeStmt $self): void — runs on execute() */
    public $onExec = null;

    public function __construct(public string $sql) {}
    #[\ReturnTypeWillChange]
    public function execute($params = null): bool { $this->params = $params ?? []; if ($this->onExec) { ($this->onExec)($this); } return true; }
    #[\ReturnTypeWillChange]
    public function fetchAll($mode = 0, ...$args): array { return $this->rows; }
    #[\ReturnTypeWillChange]
    public function fetch($mode = 0, ...$args) { return $this->rows[0] ?? false; }
    #[\ReturnTypeWillChange]
    public function fetchColumn($col = 0) { $r = $this->rows[0] ?? null; return $r === null ? false : array_values($r)[$col]; }
    #[\ReturnTypeWillChange]
    public function rowCount(): int { return $this->rc; }
    #[\ReturnTypeWillChange]
    public function bindValue($p, $v, $t = PDO::PARAM_STR): bool { $this->params[$p] = $v; return true; }
}

abstract class FakePdo extends PDO
{
    public array $log = [];
    public function __construct() {}   // bypass real PDO connect
    abstract protected function build(string $sql): FakeStmt;
    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = []) { $s = $this->build($sql); $this->log[] = $sql; return $s; }
    #[\ReturnTypeWillChange]
    public function query($sql, $fetchMode = null, ...$a) { $s = $this->build($sql); $s->execute(); $this->log[] = $sql; return $s; }
}

// ════════════════════════════════════════════════════════════════════════════
//  REMINDER DELIVERY
// ════════════════════════════════════════════════════════════════════════════
class ReminderPdo extends FakePdo
{
    public array $due = [];
    public int   $claimRc = 1;      // 1 = this run wins the row; 0 = another run took it
    public array $claims = [];       // captured UPDATE claim params
    public array $errors = [];       // captured last_error writes

    protected function build(string $sql): FakeStmt
    {
        $s = new FakeStmt($sql);
        if (str_contains($sql, 'SELECT id, user_id, title') && str_contains($sql, 'agent_reminders')) {
            $s->rows = $this->due;
        } elseif (str_contains($sql, 'UPDATE agent_reminders') && str_contains($sql, 'last_error = :e')) {
            $s->rc = 1;
            $s->onExec = function (FakeStmt $st) { $this->errors[] = $st->params; };
        } elseif (str_contains($sql, 'UPDATE agent_reminders')) {   // a claim
            $rc = $this->claimRc;
            $kind = str_contains($sql, 'active = 0') ? 'once' : 'recurs';
            $s->rc = $rc;
            $s->onExec = function (FakeStmt $st) use ($kind) { $this->claims[] = ['kind' => $kind, 'params' => $st->params]; };
        }
        return $s;
    }
}

$now = '2026-08-31 11:00:00';

// R1–R3: one weekly + one once, both due; both claims win.
$rp = new ReminderPdo();
$rp->due = [
    ['id' => 1, 'user_id' => 42, 'title' => 'Weekly work-photo upload', 'cadence' => 'weekly', 'next_at' => '2026-08-31 09:00:00'],
    ['id' => 2, 'user_id' => 43, 'title' => 'One-off reminder',          'cadence' => 'once',   'next_at' => '2026-08-31 10:00:00'],
];
$delivered = [];
$deliver = function (int $uid, string $title, string $body, array $data) use (&$delivered): bool {
    $delivered[] = ['uid' => $uid, 'title' => $title, 'body' => $body, 'data' => $data];
    return true;
};
$sum = (new ReminderDelivery($rp))->run($deliver, 200, $now);

check('R1 both due reminders delivered (notification fired)', count($delivered) === 2);
check('R1 delivered to the right internal user',              ($delivered[0]['uid'] ?? 0) === 42);
check('R1 delivery carries the agent_reminder type',          ($delivered[0]['data']['type'] ?? '') === 'agent_reminder');
$weeklyClaim = null; $onceClaim = null;
foreach ($rp->claims as $c) { if ($c['kind'] === 'recurs') { $weeklyClaim = $c; } if ($c['kind'] === 'once') { $onceClaim = $c; } }
check('R2 weekly reschedules to now + 7 days',                ($weeklyClaim['params'][':next'] ?? '') === '2026-09-07 11:00:00');
check('R3 once is completed (deactivated, no reschedule)',    $onceClaim !== null && !isset($onceClaim['params'][':next']));
check('R2/R3 summary counts',                                 $sum['delivered'] === 2 && $sum['rescheduled'] === 1 && $sum['completed'] === 1);

// R4: the claim wins 0 rows (another run already took it) → NO delivery.
$rp2 = new ReminderPdo();
$rp2->claimRc = 0;
$rp2->due = [['id' => 9, 'user_id' => 7, 'title' => 'x', 'cadence' => 'weekly', 'next_at' => '2026-08-31 09:00:00']];
$fired = 0;
$sum2 = (new ReminderDelivery($rp2))->run(function () use (&$fired) { $fired++; return true; }, 200, $now);
check('R4 idempotent — lost claim does not deliver',          $fired === 0 && $sum2['delivered'] === 0);

// R5: delivery returns false → counted failed + last_error noted.
$rp3 = new ReminderPdo();
$rp3->due = [['id' => 5, 'user_id' => 8, 'title' => 'y', 'cadence' => 'weekly', 'next_at' => '2026-08-31 09:00:00']];
$sum3 = (new ReminderDelivery($rp3))->run(function () { return false; }, 200, $now);
check('R5 failed delivery counted + error noted',             $sum3['failed'] === 1 && count($rp3->errors) === 1);

// ════════════════════════════════════════════════════════════════════════════
//  DEMAND DETECTION
// ════════════════════════════════════════════════════════════════════════════
class DemandPdo extends FakePdo
{
    public array $groups = [];        // rows the aggregate SELECT returns
    public array $signalOverride = []; // scope|cat|city => extra fields on the upserted signal (e.g. alerted)
    public array $inserts = [];        // captured demand-event inserts
    public array $alerted = [];        // captured markAlerted ids
    private int  $sigId = 100;

    protected function build(string $sql): FakeStmt
    {
        $s = new FakeStmt($sql);
        if (str_contains($sql, 'INSERT INTO agent_demand_events')) {
            $s->rc = 1;
            $s->onExec = function (FakeStmt $st) { $this->inserts[] = $st->params; };
        } elseif (str_contains($sql, 'FROM agent_demand_events') && str_contains($sql, 'GROUP BY')) {
            $s->rows = $this->groups;
        } elseif (str_contains($sql, 'INSERT INTO agent_demand_signals')) {
            $s->rc = 1;   // the upsert
        } elseif (str_contains($sql, 'SELECT id, scope, category_key') && str_contains($sql, 'agent_demand_signals')) {
            // Return a fresh signal row echoing the just-upserted group.
            $s->onExec = function (FakeStmt $st) {
                $p = $st->params;
                $key = $p[':scope'] . '|' . $p[':cat'] . '|' . $p[':city'];
                $g = null;
                foreach ($this->groups as $row) {
                    if ($row['scope'] === $p[':scope'] && $row['category_key'] === $p[':cat'] && (int) $row['city_id'] === (int) $p[':city']) { $g = $row; break; }
                }
                if ($g === null) { $st->rows = []; return; }
                $base = [
                    'id' => $this->sigId++, 'scope' => $g['scope'], 'category_key' => $g['category_key'], 'city_id' => (int) $g['city_id'],
                    'area' => $g['area'] ?? null, 'ask_count' => (int) $g['ask_count'], 'distinct_users' => (int) $g['distinct_users'],
                    'supply_estimate' => (int) $g['supply_estimate'], 'status' => 'open', 'alerted_at' => null,
                ];
                $st->rows = [array_merge($base, $this->signalOverride[$key] ?? [])];
            };
        } elseif (str_contains($sql, "UPDATE agent_demand_signals SET status = 'alerted'")) {
            $s->rc = 1;
            $s->onExec = function (FakeStmt $st) { $this->alerted[] = $st->params[':id'] ?? null; };
        }
        return $s;
    }
}

$thresholds = ['window_days' => 14, 'min_asks' => 5, 'min_distinct_users' => 3, 'max_supply' => 2, 'cooldown_hours' => 168];

// D1: record() writes one clean event.
$dp0 = new DemandPdo();
(new DemandSignals($dp0))->record('service', 'painting', 7, 'Rajpur Road', 0, 42);
$ev = $dp0->inserts[0] ?? [];
check('D1 record writes one demand event',                    count($dp0->inserts) === 1);
check('D1 event carries scope/category/supply metadata',      ($ev[':scope'] ?? '') === 'service' && ($ev[':cat'] ?? '') === 'painting' && (int) ($ev[':rc'] ?? -1) === 0);
check('D1 event keeps internal user_id (never leaves system)',(int) ($ev[':uid'] ?? 0) === 42);

// D2: a qualifying signal (5 asks, 3 users, 0 supply) alerts exactly once.
$dp = new DemandPdo();
$dp->groups = [
    ['scope' => 'service', 'category_key' => 'painting', 'city_id' => 7, 'ask_count' => 5, 'distinct_users' => 3, 'supply_estimate' => 0, 'area' => 'Rajpur Road'],
];
$alerts = [];
$notify = function (array $signal) use (&$alerts) { $alerts[] = $signal; };
$ds = (new DemandSignals($dp))->detect($thresholds, $notify);
check('D2 high-demand/low-supply raises ONE admin alert',     count($alerts) === 1 && $ds['alerted'] === 1);
check('D2 alert carries the category + counts',               ($alerts[0]['category_key'] ?? '') === 'painting' && (int) ($alerts[0]['ask_count'] ?? 0) === 5);
check('D2 signal marked alerted',                             count($dp->alerted) === 1);

// D3: below threshold (2 asks) does NOT alert.
$dp2 = new DemandPdo();
$dp2->groups = [
    ['scope' => 'service', 'category_key' => 'cctv', 'city_id' => 7, 'ask_count' => 2, 'distinct_users' => 2, 'supply_estimate' => 0, 'area' => null],
];
$alerts2 = [];
$ds2 = (new DemandSignals($dp2))->detect($thresholds, function (array $s) use (&$alerts2) { $alerts2[] = $s; });
check('D3 below-threshold demand does NOT alert',             count($alerts2) === 0 && $ds2['alerted'] === 0);

// D4: already alerted within cooldown → no re-alert.
$dp3 = new DemandPdo();
$dp3->groups = [
    ['scope' => 'commerce', 'category_key' => 'kurta', 'city_id' => 7, 'ask_count' => 8, 'distinct_users' => 5, 'supply_estimate' => 1, 'area' => 'Mall Road'],
];
$dp3->signalOverride['commerce|kurta|7'] = ['status' => 'alerted', 'alerted_at' => date('Y-m-d H:i:s', time() - 3600)]; // 1h ago, cooldown 168h
$alerts3 = [];
$ds3 = (new DemandSignals($dp3))->detect($thresholds, function (array $s) use (&$alerts3) { $alerts3[] = $s; });
check('D4 alerted-within-cooldown does NOT re-alert',         count($alerts3) === 0 && $ds3['alerted'] === 0);

echo "\n----------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
