<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceOpsQueue  (Paint PB9)
//  ONE human-support escalation queue. It does not raise flags itself — it READS
//  every open exception and turns each into a plain instruction: "decide X",
//  a suggested action, and the buttons to do it. The system drives the human.
//
//  Feeds: vehicle doubt (PB8 commerce_vehicle_doubts) · no seller accepted
//  (needs_ops flag from routing) · rider cash mismatch (PB5
//  commerce_cash_collections) · high-value hold · cancellation dispute · payment
//  stuck (payment_issue flag). Custom-tint price-outlier will plug in later.
//
//  Actions reuse OpsConsole (claim / escalate / resolve / approveHold /
//  reassignRider / cancelOrder) + CommerceVehicle::resolve; every decision is
//  logged to commerce_ops_actions. Admin config picks what auto-escalates.
//  Additive; no engine edit.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/OpsConsole.php';
require_once __DIR__ . '/CommerceVehicle.php';

class CommerceOpsQueue
{
    /** Flag kinds that auto-escalate (jump the queue), admin-configurable. */
    public static function autoEscalate(PDO $db): array
    {
        $def = ['high_value_hold', 'needs_ops', 'payment_issue'];
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_ops_auto_escalate' LIMIT 1");
            $s->execute(); $v = $s->fetchColumn();
            if ($v) { $j = json_decode((string)$v, true); if (is_array($j)) return array_map('strval', $j); }
        } catch (\Throwable $e) {}
        return $def;
    }

    private static function money($n): string { return '₹' . number_format((float)$n, 0); }

    /** Build the unified queue: each row carries a decision + suggested action + buttons. */
    public static function build(PDO $db): array
    {
        $auto = self::autoEscalate($db);
        $items = [];

        // 1) Order-flag tickets (reuse OpsConsole), enriched into instructions.
        //    'vehicle_doubt' flags are covered by the richer feed below, so skip here.
        $flagCopy = [
            'needs_ops'            => ['No store accepted this order', 'Reassign it to the company dark-store, or cancel + refund the customer.', 'no_seller'],
            'high_value_hold'      => ['High-value order is on hold for approval', 'Verify the customer, then Approve to release it or Reject to cancel + refund.', 'high_value'],
            'payment_issue'        => ['Payment is stuck on this order', 'Confirm the payment landed, then Resolve — or Cancel + refund if it failed.', 'payment_stuck'],
            'cancellation_dispute' => ['A cancellation is disputed', 'Decide who bears the cost, then Resolve with a note.', 'cancellation_dispute'],
            'delivery_failed'      => ['Delivery failed', 'Reassign a rider or cancel the order.', 'delivery_failed'],
        ];
        foreach (OpsConsole::exceptionQueue($db) as $t) {
            $flag = $t['flag'];
            if ($flag === 'vehicle_doubt') continue; // richer vehicle feed below
            $c = $flagCopy[$flag] ?? [ucfirst(str_replace('_', ' ', $flag)), 'Review and resolve.', $flag];
            $actions = [['label' => 'Claim', 'action' => 'claim'], ['label' => 'Resolve', 'action' => 'resolve', 'note' => true]];
            if ($flag === 'high_value_hold') $actions = [['label' => 'Approve', 'action' => 'hold_approve'], ['label' => 'Reject', 'action' => 'hold_reject', 'note' => true]];
            elseif ($flag === 'needs_ops' || $flag === 'delivery_failed') $actions = [['label' => 'Reassign rider', 'action' => 'reassign'], ['label' => 'Cancel + refund', 'action' => 'cancel', 'note' => true], ['label' => 'Resolve', 'action' => 'resolve', 'note' => true]];
            $items[] = [
                'kind'       => $c[2],
                'flag'       => $flag,
                'order_id'   => (int)$t['order_id'],
                'order_number' => $t['order_number'],
                'decide'     => $c[0] . ' — order #' . $t['order_number'],
                'detail'     => ($t['reason'] ?: '') . ' · ' . self::money($t['total']),
                'suggestion' => $c[1],
                'actions'    => $actions,
                'priority'   => (in_array($flag, $auto, true) || !empty($t['sla_breached'])) ? 'escalated' : 'normal',
                'age_sec'    => (int)$t['age_sec'],
                'since'      => $t['created_at'],
            ];
        }

        // 2) Vehicle doubts (PB8) — richer: options + the seller's suggestion.
        foreach (CommerceVehicle::queue($db) as $d) {
            $sugg = $d['suggested_vehicle'] ? ('Seller suggests ' . $d['suggested_vehicle']) : ('Auto-pick ' . $d['auto_vehicle'] . ' — confirm it');
            $items[] = [
                'kind'       => 'vehicle_doubt',
                'flag'       => 'vehicle_doubt',
                'order_id'   => (int)$d['order_id'],
                'decide'     => 'Confirm the delivery vehicle — order #' . (int)$d['order_id'],
                'detail'     => ($d['reason'] ?: '') . ($d['weight_kg'] !== null ? ' · ' . $d['weight_kg'] . 'kg' : ''),
                'suggestion' => $sugg . '. Pick one: ' . implode(' / ', $d['options'] ?: []),
                'options'    => $d['options'] ?: [],
                'suggested'  => $d['suggested_vehicle'],
                'actions'    => [['label' => 'Set vehicle', 'action' => 'vehicle_resolve', 'choices' => $d['options'] ?: []]],
                'priority'   => 'normal',
                'since'      => $d['created_at'],
            ];
        }

        // 3) Rider cash mismatches (PB5) — reconcile.
        try {
            $cs = $db->query("SELECT cc.id, cc.order_id, cc.expected, cc.collected, cc.created_at, o.order_number
                                FROM commerce_cash_collections cc LEFT JOIN orders o ON o.id=cc.order_id
                               WHERE cc.mismatch=1 AND cc.resolved_at IS NULL ORDER BY cc.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cs as $c) {
                $items[] = [
                    'kind'       => 'cash_mismatch',
                    'order_id'   => (int)$c['order_id'],
                    'ref_id'     => (int)$c['id'],
                    'decide'     => 'Reconcile rider cash — order #' . ($c['order_number'] ?: $c['order_id']),
                    'detail'     => 'Collected ' . self::money($c['collected']) . ' vs expected ' . self::money($c['expected']),
                    'suggestion' => 'Check with the rider, adjust the cash ledger, then Resolve with a note.',
                    'actions'    => [['label' => 'Mark reconciled', 'action' => 'cash_resolve', 'note' => true]],
                    'priority'   => 'normal',
                    'since'      => $c['created_at'],
                ];
            }
        } catch (\Throwable $e) {}

        // escalated first, then oldest first
        usort($items, function ($a, $b) {
            $ap = $a['priority'] === 'escalated' ? 0 : 1; $bp = $b['priority'] === 'escalated' ? 0 : 1;
            if ($ap !== $bp) return $ap <=> $bp;
            return strcmp((string)($a['since'] ?? ''), (string)($b['since'] ?? ''));
        });
        return $items;
    }

    /** Count open items (for the nav badge / summary). */
    public static function count(PDO $db): int { return count(self::build($db)); }

    private static function logAction(PDO $db, string $kind, ?int $refId, ?int $orderId, string $action, ?string $note, ?int $actorId): void
    {
        try {
            $db->prepare("INSERT INTO commerce_ops_actions (kind, ref_id, order_id, action, note, actor_id, created_at) VALUES (?,?,?,?,?,?,NOW())")
               ->execute([$kind, $refId, $orderId, $action, $note !== null ? mb_substr($note, 0, 400) : null, $actorId]);
        } catch (\Throwable $e) { error_log('[ops action log] ' . $e->getMessage()); }
    }

    /** Resolve a rider cash mismatch (ops reconciled it). */
    public static function resolveCash(PDO $db, int $cashId, int $opsUserId, ?string $note = null): array
    {
        $s = $db->prepare("SELECT order_id FROM commerce_cash_collections WHERE id=? AND resolved_at IS NULL LIMIT 1");
        $s->execute([$cashId]); $oid = $s->fetchColumn();
        if ($oid === false) return ['ok' => false, 'reason' => 'not_found_or_resolved'];
        $db->prepare("UPDATE commerce_cash_collections SET resolved_at=NOW(), resolved_by=? WHERE id=?")->execute([$opsUserId, $cashId]);
        self::logAction($db, 'cash_mismatch', $cashId, (int)$oid, 'resolve', $note, $opsUserId);
        return ['ok' => true, 'id' => $cashId];
    }

    /** Record any queue action against the audit (used by the adapter for flag/vehicle actions too). */
    public static function note(PDO $db, string $kind, ?int $orderId, string $action, ?string $note, ?int $actorId): void
    {
        self::logAction($db, $kind, null, $orderId, $action, $note, $actorId);
    }
}
