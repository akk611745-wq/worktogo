<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceNotifier  (Paint PB7)
//  The alerts nervous-system. CommerceEventBus::emit already writes the TIMELINE
//  + in-app notifications; this fans the SAME events out to external channels
//  (FCM push now; MSG91 SMS/WhatsApp are queued here and delivered by the host),
//  each with its own TONE, and LOGS every attempt to commerce_notification_log.
//  A channel failure is caught and logged — it NEVER blocks an order.
//
//  Admin turns channels on/off (commerce_notify_channels). The loud, repeating
//  seller "new order" alert + the rider job tone are played by the PWAs
//  (seller.js / rider.js) off the priority/tone carried here + in the inbox.
//
//  Reuses FcmNotifier (push) + the shared notifications table; no engine edit.
// ─────────────────────────────────────────────────────────────────────────────

class CommerceNotifier
{
    /** Per-(event, audience) delivery spec: channels + tone + priority + repeat. */
    public static function spec(): array
    {
        // critical = loud/repeat; high = prompt; normal = quiet.
        return [
            'order_created'    => [
                'seller'   => ['channels' => ['inapp', 'push', 'sms', 'whatsapp'], 'tone' => 'seller_new_order', 'priority' => 'critical', 'repeat' => true],
                'customer' => ['channels' => ['inapp', 'push'],                     'tone' => 'order_placed',     'priority' => 'normal',   'repeat' => false],
            ],
            'order_accepted'   => ['customer' => ['channels' => ['inapp', 'push'],         'tone' => 'status', 'priority' => 'normal']],
            'order_ready'      => ['customer' => ['channels' => ['inapp', 'push'],         'tone' => 'status', 'priority' => 'normal']],
            'out_for_delivery' => ['customer' => ['channels' => ['inapp', 'push', 'sms'],  'tone' => 'otw',    'priority' => 'high']],
            'delivered'        => ['customer' => ['channels' => ['inapp', 'push'],         'tone' => 'delivered', 'priority' => 'normal'],
                                   'seller'   => ['channels' => ['inapp'],                 'tone' => 'quiet',  'priority' => 'normal']],
            'order_rejected'   => ['customer' => ['channels' => ['inapp', 'push'],         'tone' => 'status', 'priority' => 'normal']],
            'order_rerouted'   => ['seller'   => ['channels' => ['inapp', 'push'],         'tone' => 'rider_job', 'priority' => 'high']],
            'payment_failed'   => ['customer' => ['channels' => ['inapp', 'push', 'sms'],  'tone' => 'alert',  'priority' => 'high']],
            'payment_captured' => ['customer' => ['channels' => ['inapp', 'push'],         'tone' => 'status', 'priority' => 'normal']],
            'cancelled'        => ['customer' => ['channels' => ['inapp', 'push', 'sms'],  'tone' => 'status', 'priority' => 'normal']],
            'order_cancelled'  => ['customer' => ['channels' => ['inapp', 'push', 'sms'],  'tone' => 'status', 'priority' => 'normal']],
            'seller_cancelled' => ['customer' => ['channels' => ['inapp', 'push'],         'tone' => 'status', 'priority' => 'normal']],
            'refunded'         => ['customer' => ['channels' => ['inapp', 'push'],         'tone' => 'status', 'priority' => 'normal']],
            'rider_assigned'   => ['rider'    => ['channels' => ['inapp', 'push'],         'tone' => 'rider_job', 'priority' => 'high', 'repeat' => true]],
        ];
    }

    /** Admin channel on/off map (commerce_notify_channels). Default: all on. */
    public static function channels(PDO $db): array
    {
        $def = ['inapp' => true, 'push' => true, 'sms' => true, 'whatsapp' => true];
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_notify_channels' LIMIT 1");
            $s->execute(); $v = $s->fetchColumn();
            if ($v) { $j = json_decode((string)$v, true); if (is_array($j)) foreach ($def as $k => $_) $def[$k] = !empty($j[$k]); }
        } catch (\Throwable $e) {}
        return $def;
    }

    /** Admin: turn a channel on/off. */
    public static function setChannel(PDO $db, string $name, bool $on): array
    {
        $name = strtolower(trim($name));
        if (!in_array($name, ['inapp', 'push', 'sms', 'whatsapp'], true)) return ['ok' => false, 'reason' => 'bad_channel'];
        $cur = self::channels($db); $cur[$name] = $on;
        $db->prepare("INSERT INTO app_settings (setting_key, setting_value, value_type, label, is_public)
                      VALUES ('commerce_notify_channels', ?, 'json', 'Order-alert channels turned on (admin toggles)', 0)
                      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
           ->execute([json_encode(['inapp' => $cur['inapp'], 'push' => $cur['push'], 'sms' => $cur['sms'], 'whatsapp' => $cur['whatsapp']])]);
        return ['ok' => true, 'channels' => $cur];
    }

    private static function log(PDO $db, ?int $orderId, ?int $userId, ?string $audience, string $channel, string $event, ?string $tone, ?string $priority, string $status, ?string $error = null): void
    {
        try {
            $db->prepare("INSERT INTO commerce_notification_log (order_id, user_id, audience, channel, event_type, tone, priority, status, error, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
               ->execute([$orderId, $userId, $audience, $channel, substr($event, 0, 40), $tone, $priority, $status, $error !== null ? mb_substr($error, 0, 240) : null]);
        } catch (\Throwable $e) { error_log('[notifier log] ' . $e->getMessage()); }
    }

    /** Recipients (user ids) for an audience on an order. */
    private static function recipients(PDO $db, int $orderId, string $audience): array
    {
        try {
            if ($audience === 'customer') {
                $s = $db->prepare("SELECT user_id FROM orders WHERE id=? LIMIT 1"); $s->execute([$orderId]);
                $u = $s->fetchColumn(); return $u ? [(int)$u] : [];
            }
            if ($audience === 'seller') {
                $s = $db->prepare("SELECT DISTINCT v.user_id FROM orders o JOIN vendors v ON v.id=o.vendor_id WHERE (o.parent_order_id=? OR o.id=?) AND v.user_id IS NOT NULL");
                $s->execute([$orderId, $orderId]); return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            }
            if ($audience === 'rider') {
                $s = $db->prepare("SELECT driver_id FROM deliveries WHERE order_id=? AND driver_id IS NOT NULL ORDER BY id DESC LIMIT 1");
                $s->execute([$orderId]); $d = $s->fetchColumn(); return $d ? [(int)$d] : [];
            }
        } catch (\Throwable $e) {}
        return [];
    }

    private static function copyFor(string $event, string $audience): array
    {
        $map = [
            'order_created'    => ['seller' => ['New order', 'A new order needs your acceptance.'], 'customer' => ['Order placed', 'Your order is confirmed.']],
            'order_accepted'   => ['customer' => ['Order confirmed', 'Your order is being prepared.']],
            'out_for_delivery' => ['customer' => ['Out for delivery', 'Your order is on the way.']],
            'delivered'        => ['customer' => ['Delivered', 'Your order has arrived. Enjoy!']],
            'payment_failed'   => ['customer' => ['Payment issue', 'There was a problem with your payment.']],
            'order_cancelled'  => ['customer' => ['Order cancelled', 'Your order has been cancelled.']],
            'cancelled'        => ['customer' => ['Order cancelled', 'Your order has been cancelled.']],
            'refunded'         => ['customer' => ['Refund initiated', 'Your refund is on its way.']],
            'rider_assigned'   => ['rider' => ['New delivery job', 'A delivery has been assigned to you.']],
            'order_rerouted'   => ['seller' => ['Order reassigned', 'An order was routed to another store.']],
        ];
        return $map[$event][$audience] ?? [ucfirst(str_replace('_', ' ', $event)), 'Order update.'];
    }

    /**
     * Fan an event out to its audiences/channels and log every attempt.
     * Best-effort: catches everything so it can NEVER block the order flow.
     * @return array{dispatched:int, sent:int}
     */
    public static function dispatch(PDO $db, string $eventType, ?int $orderId, array $ctx = []): array
    {
        $dispatched = 0; $sent = 0;
        if (!$orderId) return ['dispatched' => 0, 'sent' => 0];
        $spec = self::spec()[$eventType] ?? null;
        if (!$spec) return ['dispatched' => 0, 'sent' => 0];
        $chanOn = self::channels($db);
        foreach ($spec as $audience => $cfg) {
            $tone = $cfg['tone'] ?? null; $priority = $cfg['priority'] ?? 'normal';
            [$title, $body] = self::copyFor($eventType, $audience);
            foreach (self::recipients($db, $orderId, $audience) as $uid) {
                foreach (($cfg['channels'] ?? []) as $channel) {
                    $dispatched++;
                    if (empty($chanOn[$channel])) { self::log($db, $orderId, $uid, $audience, $channel, $eventType, $tone, $priority, 'skipped', 'channel off'); continue; }
                    try {
                        if ($channel === 'push') {
                            if (class_exists('FcmNotifier')) {
                                FcmNotifier::notifyUser($db, $uid, $title, $body, ['tone' => (string)$tone, 'priority' => (string)$priority, 'event' => $eventType, 'order_id' => (string)$orderId]);
                                self::log($db, $orderId, $uid, $audience, $channel, $eventType, $tone, $priority, 'sent'); $sent++;
                            } else { self::log($db, $orderId, $uid, $audience, $channel, $eventType, $tone, $priority, 'skipped', 'fcm unavailable'); }
                        } elseif ($channel === 'inapp') {
                            // EventBus already wrote the in-app row for its mapped audiences;
                            // record the attempt so the log is complete.
                            self::log($db, $orderId, $uid, $audience, $channel, $eventType, $tone, $priority, 'sent'); $sent++;
                        } else { // sms | whatsapp — queued for the host MSG91 worker.
                            self::log($db, $orderId, $uid, $audience, $channel, $eventType, $tone, $priority, 'queued');
                        }
                    } catch (\Throwable $e) {
                        self::log($db, $orderId, $uid, $audience, $channel, $eventType, $tone, $priority, 'failed', $e->getMessage());
                    }
                }
            }
        }
        return ['dispatched' => $dispatched, 'sent' => $sent];
    }
}
