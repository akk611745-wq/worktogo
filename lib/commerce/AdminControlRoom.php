<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — AdminControlRoom  (S8)
//  commerce-system-blueprint §L. The ZERO-CODE operating console: every rule is a
//  live-editable app_settings value (no deploy); kill-switches at every level;
//  loss-proof floor; full manual override on any order; a config-change AUDIT log
//  (who changed what, old→new); and money dashboards (who-we-owe + profit by
//  window). Reuses app_settings + wallet_transactions + MarginEngine +
//  DeliveryEngine + CommerceEventBus — nothing rebuilt, no engine edited.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/CommerceEventBus.php';
require_once __DIR__ . '/MarginEngine.php';
require_once __DIR__ . '/../../core/delivery/DeliveryEngine.php';

class AdminControlRoom
{
    /** The ONLY settings the control room may change (safety allowlist). */
    public static function editableKeys(): array
    {
        return [
            'commerce_enabled', 'commerce_margin_policy', 'commerce_loss_proof_floor', 'commerce_loss_proof_enabled',
            'commerce_offer_timeout_sec', 'commerce_offer_max_retries', 'commerce_default_radius_km',
            'commerce_payment_methods', 'commerce_vehicle_thresholds',
            'commerce_delivery_accept_timeout_sec', 'commerce_delivery_max_reassign',
            'commerce_high_value_threshold', 'commerce_ticket_sla_sec', 'commerce_paused',
        ];
    }

    private static function raw(PDO $db, string $key)
    {
        $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false ? null : $v;
    }

    private static function audit(PDO $db, ?int $adminId, string $action, ?string $key, ?string $target, $old, $new, ?string $reason = null): void
    {
        $db->prepare("INSERT INTO commerce_config_audit (admin_user_id, action, setting_key, target, old_value, new_value, reason, created_at)
                      VALUES (?,?,?,?,?,?,?,NOW())")
           ->execute([$adminId, $action, $key, $target, is_scalar($old) || $old === null ? $old : json_encode($old), is_scalar($new) || $new === null ? $new : json_encode($new), $reason]);
    }

    /** All commerce config, typed, for the control-room screen. */
    public static function getConfig(PDO $db): array
    {
        $out = [];
        foreach (self::editableKeys() as $k) {
            $v = self::raw($db, $k);
            $out[$k] = ($v !== null && ($v[0] ?? '') === '{' || ($v[0] ?? '') === '[') ? json_decode($v, true) : $v;
        }
        return $out;
    }

    /** Live-set a commerce setting (no deploy). Allowlisted + audited (old→new). */
    public static function setConfig(PDO $db, string $key, $value, ?int $adminId, ?string $reason = null): array
    {
        if (!in_array($key, self::editableKeys(), true)) {
            return ['ok' => false, 'error' => 'not_editable'];
        }
        $new = is_scalar($value) ? (string)$value : json_encode($value);
        $old = self::raw($db, $key);
        $type = (($new[0] ?? '') === '{' || ($new[0] ?? '') === '[') ? 'json' : (is_numeric($new) ? 'number' : 'text');
        $db->prepare("INSERT INTO app_settings (setting_key, setting_value, value_type, is_public)
                      VALUES (?,?,?,0)
                      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), value_type=VALUES(value_type)")
           ->execute([$key, $new, $type]);
        self::audit($db, $adminId, 'set', $key, null, $old, $new, $reason);
        return ['ok' => true, 'key' => $key, 'old' => $old, 'new' => $new];
    }

    // ── Kill-switches at every level ─────────────────────────────────────────────
    private static function pausedSet(PDO $db): array
    {
        $v = self::raw($db, 'commerce_paused');
        $j = $v ? json_decode($v, true) : null;
        return is_array($j) ? $j + ['categories' => [], 'cities' => [], 'stores' => []] : ['categories' => [], 'cities' => [], 'stores' => []];
    }

    private static function savePausedSet(PDO $db, array $set, ?int $adminId, string $action, string $target): void
    {
        $old = self::raw($db, 'commerce_paused');
        $new = json_encode(['categories' => array_values(array_unique($set['categories'])), 'cities' => array_values(array_unique($set['cities'])), 'stores' => array_values(array_unique($set['stores']))]);
        $db->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES ('commerce_paused',?,'json',0)
                      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$new]);
        self::audit($db, $adminId, $action, 'commerce_paused', $target, $old, $new);
    }

    /** scope: commerce | unit | zone | seller | rider. $id = category/city/store/rider id (ignored for commerce). */
    public static function pause(PDO $db, string $scope, ?int $id, ?int $adminId, ?string $reason = null): array
    {
        return self::toggle($db, $scope, $id, $adminId, true, $reason);
    }
    public static function resume(PDO $db, string $scope, ?int $id, ?int $adminId, ?string $reason = null): array
    {
        return self::toggle($db, $scope, $id, $adminId, false, $reason);
    }

    private static function toggle(PDO $db, string $scope, ?int $id, ?int $adminId, bool $pause, ?string $reason): array
    {
        switch ($scope) {
            case 'commerce':
                self::setConfig($db, 'commerce_enabled', $pause ? '0' : '1', $adminId, $reason);
                return ['ok' => true, 'scope' => 'commerce', 'paused' => $pause];
            case 'unit':
            case 'zone':
                $set = self::pausedSet($db);
                $bucket = $scope === 'unit' ? 'categories' : 'cities';
                $set[$bucket] = array_map('intval', $set[$bucket]);
                if ($pause) { $set[$bucket][] = (int)$id; }
                else { $set[$bucket] = array_values(array_diff($set[$bucket], [(int)$id])); }
                self::savePausedSet($db, $set, $adminId, $pause ? 'pause' : 'resume', $scope . ':' . $id);
                return ['ok' => true, 'scope' => $scope, 'id' => $id, 'paused' => $pause];
            case 'seller':
                $old = (string)($db->query("SELECT status FROM stores WHERE id=" . (int)$id)->fetchColumn() ?: '');
                $db->prepare("UPDATE stores SET status=? WHERE id=?")->execute([$pause ? 'suspended' : 'active', (int)$id]);
                // also track in paused.stores so the orderability gate blocks it even if status read lags
                $set = self::pausedSet($db); $set['stores'] = array_map('intval', $set['stores']);
                if ($pause) $set['stores'][] = (int)$id; else $set['stores'] = array_values(array_diff($set['stores'], [(int)$id]));
                self::savePausedSet($db, $set, $adminId, $pause ? 'pause' : 'resume', 'store:' . $id);
                self::audit($db, $adminId, $pause ? 'pause' : 'resume', null, 'store:' . $id, $old, $pause ? 'suspended' : 'active', $reason);
                return ['ok' => true, 'scope' => 'seller', 'id' => $id, 'paused' => $pause];
            case 'rider':
                DeliveryEngine::riderSetOnline($db, (int)$id, !$pause);
                self::audit($db, $adminId, $pause ? 'pause' : 'resume', null, 'rider:' . $id, null, $pause ? 'offline' : 'online', $reason);
                return ['ok' => true, 'scope' => 'rider', 'id' => $id, 'paused' => $pause];
            default:
                return ['ok' => false, 'error' => 'bad_scope'];
        }
    }

    /** Is ordering killed for this context? (global OR category/city/store paused). */
    public static function isKilled(PDO $db, array $ctx): bool
    {
        if ((string)(self::raw($db, 'commerce_enabled') ?? '1') === '0') return true;
        $set = self::pausedSet($db);
        if (isset($ctx['category_id']) && in_array((int)$ctx['category_id'], array_map('intval', $set['categories']), true)) return true;
        if (isset($ctx['city_id']) && in_array((int)$ctx['city_id'], array_map('intval', $set['cities']), true)) return true;
        if (isset($ctx['store_id']) && in_array((int)$ctx['store_id'], array_map('intval', $set['stores']), true)) return true;
        return false;
    }

    // ── Loss-proof floor (reuse MarginEngine) ────────────────────────────────────
    public static function lossProofOk(PDO $db, float $marginAmount, float $platformCostExposure): bool
    {
        if ((string)(self::raw($db, 'commerce_loss_proof_enabled') ?? '1') === '0') return true;
        $floor = (float)(self::raw($db, 'commerce_loss_proof_floor') ?? 0);
        return MarginEngine::lossProofOk($marginAmount, $platformCostExposure, $floor);
    }

    // ── Full manual override on any order ────────────────────────────────────────
    public static function forceStatus(PDO $db, int $orderId, string $status, ?int $adminId, string $reason): array
    {
        $allowed = ['confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
        if (!in_array($status, $allowed, true)) return ['ok' => false, 'error' => 'bad_status'];
        $old = (string)($db->query("SELECT status FROM orders WHERE id=" . (int)$orderId)->fetchColumn() ?: '');
        if ($old === '') return ['ok' => false, 'error' => 'not_found'];
        $db->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $orderId]);
        self::audit($db, $adminId, 'override', 'status', 'order:' . $orderId, $old, $status, $reason);
        CommerceEventBus::emit($db, 'admin_force_status', ['order_id' => $orderId, 'actor_type' => 'admin', 'actor_id' => $adminId, 'from_state' => $old, 'to_state' => $status, 'reason' => $reason]);
        return ['ok' => true, 'from' => $old, 'to' => $status];
    }

    public static function configAudit(PDO $db, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return $db->query("SELECT id, admin_user_id, action, setting_key, target, old_value, new_value, reason, created_at
                             FROM commerce_config_audit ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Money dashboard (who-we-owe + profit by window) ──────────────────────────
    public static function moneyDashboard(PDO $db): array
    {
        $windows = ['24h' => 1, '7d' => 7, '30d' => 30];
        $byWindow = [];
        $sumFor = function (string $entity, int $days) use ($db): float {
            $s = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions
                                WHERE entity_type=? AND type='credit' AND created_at >= (NOW() - INTERVAL ? DAY)");
            $s->execute([$entity, $days]);
            return (float)$s->fetchColumn();
        };
        foreach ($windows as $label => $days) {
            $byWindow[$label] = [
                'platform_profit' => round($sumFor('platform', $days), 2),
                'vendor_earnings' => round($sumFor('vendor', $days), 2),
                'rider_earnings'  => round($sumFor('driver', $days), 2),
            ];
        }
        // Who we owe RIGHT NOW (current balances, not windowed).
        $vendorOwed = (float)($db->query("SELECT COALESCE(SUM(pending_balance),0) FROM vendor_wallets")->fetchColumn());
        $riderCash  = (float)($db->query("SELECT COALESCE(SUM(cash_in_hand),0) FROM driver_wallets")->fetchColumn());
        return [
            'by_window'  => $byWindow,
            'who_we_owe' => ['vendor_pending' => round($vendorOwed, 2), 'rider_cash_in_hand' => round($riderCash, 2)],
        ];
    }
}
