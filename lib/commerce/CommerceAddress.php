<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceAddress  (Paint PB15)
//  Customer saved-address book: Home / Shop / Site labels, one default, LANDMARK
//  required, optional GPS pin. The pincode is validated against PB14
//  serviceability at save. toShipping() turns a saved row into the order's
//  shipping_address JSON (full — incl. landmark + pin) so the rider gets it all.
//
//  Additive; the never-touch OrderService stores whatever shipping_address array
//  it is handed, so landmark/lat/lng persist. No engine edit.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/Serviceability.php';

class CommerceAddress
{
    public const LABELS = ['home', 'shop', 'site'];

    private static function label(?string $l): string
    {
        $l = strtolower(trim((string)$l));
        return in_array($l, self::LABELS, true) ? $l : 'home';
    }

    /** All of a user's saved addresses, default first. */
    public static function listForUser(PDO $db, int $userId): array
    {
        $s = $db->prepare("SELECT id, label, name, phone, line1, landmark, city, state, pincode, city_id, lat, lng, is_default
                             FROM commerce_user_addresses WHERE user_id=? AND deleted_at IS NULL
                            ORDER BY is_default DESC, updated_at DESC");
        $s->execute([$userId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id']; $r['is_default'] = (int)$r['is_default'] === 1;
            $r['lat'] = $r['lat'] !== null ? (float)$r['lat'] : null;
            $r['lng'] = $r['lng'] !== null ? (float)$r['lng'] : null;
        }
        return $rows;
    }

    /** One address scoped to its owner (null if not theirs). */
    public static function get(PDO $db, int $id, int $userId): ?array
    {
        $s = $db->prepare("SELECT * FROM commerce_user_addresses WHERE id=? AND user_id=? AND deleted_at IS NULL LIMIT 1");
        $s->execute([$id, $userId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Add (or, with $id, update) an address. LANDMARK is required; the pincode is
     * checked against PB14 serviceability. First address becomes the default.
     * @return array{ok:bool, reason?:string, id?:int, serviceable?:bool}
     */
    public static function save(PDO $db, int $userId, array $d, ?int $id = null): array
    {
        $g = fn($k) => trim((string)($d[$k] ?? ''));
        $name = $g('name'); $phone = preg_replace('/[^\d+]/', '', $g('phone'));
        $line1 = $g('line1'); $landmark = $g('landmark');
        $city = $g('city'); $state = $g('state');
        $pincode = Serviceability::normalizePincode($d['pincode'] ?? '');
        $cityId = isset($d['city_id']) && $d['city_id'] !== '' ? (int)$d['city_id'] : null;
        $lat = isset($d['lat']) && $d['lat'] !== '' ? (float)$d['lat'] : null;
        $lng = isset($d['lng']) && $d['lng'] !== '' ? (float)$d['lng'] : null;

        if ($name === '' || strlen($phone) < 7 || $line1 === '' || $city === '' || $state === '')
            return ['ok' => false, 'reason' => 'missing_fields'];
        if ($landmark === '') return ['ok' => false, 'reason' => 'landmark_required'];
        if ($pincode === '')  return ['ok' => false, 'reason' => 'bad_pincode'];
        // PB14: don't let a customer save an address we can't deliver to.
        if (!Serviceability::cityServes($db, $cityId, $pincode))
            return ['ok' => false, 'reason' => 'area_not_served', 'serviceable' => false];

        $label = self::label($d['label'] ?? 'home');
        $wantDefault = !empty($d['is_default']);
        $count = (int)$db->query("SELECT COUNT(*) FROM commerce_user_addresses WHERE user_id=" . (int)$userId . " AND deleted_at IS NULL")->fetchColumn();
        $makeDefault = $wantDefault || $count === 0;

        if ($id !== null) {
            $own = self::get($db, $id, $userId);
            if (!$own) return ['ok' => false, 'reason' => 'not_found'];
            $db->prepare("UPDATE commerce_user_addresses SET label=?, name=?, phone=?, line1=?, landmark=?, city=?, state=?, pincode=?, city_id=?, lat=?, lng=?, updated_at=NOW() WHERE id=? AND user_id=?")
               ->execute([$label, $name, $phone, $line1, $landmark, $city, $state, $pincode, $cityId, $lat, $lng, $id, $userId]);
            $newId = $id;
        } else {
            $db->prepare("INSERT INTO commerce_user_addresses (user_id, label, name, phone, line1, landmark, city, state, pincode, city_id, lat, lng, is_default, created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),NOW())")
               ->execute([$userId, $label, $name, $phone, $line1, $landmark, $city, $state, $pincode, $cityId, $lat, $lng]);
            $newId = (int)$db->lastInsertId();
        }
        if ($makeDefault) self::setDefault($db, $newId, $userId);
        return ['ok' => true, 'id' => $newId, 'serviceable' => true, 'is_default' => $makeDefault];
    }

    /** Make one address the user's default (clears the others). */
    public static function setDefault(PDO $db, int $id, int $userId): array
    {
        if (!self::get($db, $id, $userId)) return ['ok' => false, 'reason' => 'not_found'];
        $db->prepare("UPDATE commerce_user_addresses SET is_default=0 WHERE user_id=?")->execute([$userId]);
        $db->prepare("UPDATE commerce_user_addresses SET is_default=1, updated_at=NOW() WHERE id=? AND user_id=?")->execute([$id, $userId]);
        return ['ok' => true, 'id' => $id];
    }

    /** Soft-delete an address; if it was the default, promote the most recent remaining one. */
    public static function remove(PDO $db, int $id, int $userId): array
    {
        $a = self::get($db, $id, $userId);
        if (!$a) return ['ok' => false, 'reason' => 'not_found'];
        $db->prepare("UPDATE commerce_user_addresses SET deleted_at=NOW(), is_default=0 WHERE id=? AND user_id=?")->execute([$id, $userId]);
        if ((int)$a['is_default'] === 1) {
            $next = $db->prepare("SELECT id FROM commerce_user_addresses WHERE user_id=? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 1");
            $next->execute([$userId]);
            $nid = (int)($next->fetchColumn() ?: 0);
            if ($nid) self::setDefault($db, $nid, $userId);
        }
        return ['ok' => true, 'id' => $id];
    }

    /** Turn a saved row into the order's shipping_address array (full, incl. landmark + pin). */
    public static function toShipping(array $a): array
    {
        $out = [
            'name'     => $a['name'] ?? '',
            'phone'    => $a['phone'] ?? '',
            'line1'    => $a['line1'] ?? '',
            'landmark' => $a['landmark'] ?? '',
            'city'     => $a['city'] ?? '',
            'state'    => $a['state'] ?? '',
            'pincode'  => $a['pincode'] ?? '',
            'label'    => $a['label'] ?? 'home',
        ];
        if (($a['lat'] ?? null) !== null && $a['lat'] !== '') $out['lat'] = (float)$a['lat'];
        if (($a['lng'] ?? null) !== null && $a['lng'] !== '') $out['lng'] = (float)$a['lng'];
        return $out;
    }
}
