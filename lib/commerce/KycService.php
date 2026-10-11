<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — KycService  (S18)
//  Seller + rider onboarding/KYC with a submit → review → approve workflow, and
//  the PAYOUT TARGET (bank/UPI) S9 settlement needs. Reads/writes only the two new
//  KYC tables (+ flips vendors.status on seller approve, and ensures a
//  delivery_riders row on rider approve — both via their public columns). Documents
//  are referenced by media.id (uploaded via the existing media flow). No engine edit.
// ─────────────────────────────────────────────────────────────────────────────

class KycService
{
    private const SELLER_FIELDS = ['owner_name','shop_address','pincode','lat','lng','gst_number','enrolment_no','pan','bank_account_name','bank_account_number','bank_ifsc','upi_id','operating_hours','service_area','shop_photo_media_id','id_proof_media_id','address_proof_media_id'];
    private const RIDER_FIELDS  = ['full_name','phone','address','licence_number','vehicle_type','vehicle_number','rc_number','bank_account_name','bank_account_number','bank_ifsc','upi_id','photo_media_id','licence_media_id','rc_media_id','id_proof_media_id'];

    private static function hasPayout(array $r): bool
    {
        return (!empty($r['upi_id'])) || (!empty($r['bank_account_number']) && !empty($r['bank_ifsc']));
    }

    // ── SELLER ──────────────────────────────────────────────────────────────
    public static function sellerGet(PDO $db, int $vendorId): ?array
    {
        $s = $db->prepare("SELECT * FROM commerce_seller_kyc WHERE vendor_id=? LIMIT 1");
        $s->execute([$vendorId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function sellerUpsert(PDO $db, int $vendorId, array $data): array
    {
        $row = self::sellerGet($db, $vendorId);
        if ($row && in_array($row['status'], ['submitted','approved'], true)) {
            return ['ok' => false, 'reason' => $row['status'] === 'approved' ? 'already_approved' : 'under_review'];
        }
        $fields = array_intersect_key($data, array_flip(self::SELLER_FIELDS));
        if (!$row) {
            $cols = array_keys($fields);
            $db->prepare("INSERT INTO commerce_seller_kyc (vendor_id" . ($cols ? ',' . implode(',', array_map(fn($c) => "`$c`", $cols)) : '') . ", status, created_at, updated_at) VALUES (?" . str_repeat(',?', count($cols)) . ", 'draft', NOW(), NOW())")
               ->execute(array_merge([$vendorId], array_values($fields)));
        } elseif ($fields) {
            $set = implode(', ', array_map(fn($c) => "`$c`=?", array_keys($fields)));
            // a rejected application that is edited returns to draft
            $db->prepare("UPDATE commerce_seller_kyc SET $set, status='draft', review_note=NULL, updated_at=NOW() WHERE vendor_id=?")
               ->execute(array_merge(array_values($fields), [$vendorId]));
        }
        return ['ok' => true, 'kyc' => self::sellerGet($db, $vendorId)];
    }

    public static function sellerSubmit(PDO $db, int $vendorId): array
    {
        $r = self::sellerGet($db, $vendorId);
        if (!$r) return ['ok' => false, 'reason' => 'no_application'];
        if (in_array($r['status'], ['submitted','approved'], true)) return ['ok' => false, 'reason' => $r['status']];
        $missing = [];
        foreach (['owner_name','shop_address','pincode','id_proof_media_id','address_proof_media_id'] as $f) if (empty($r[$f])) $missing[] = $f;
        if (!self::hasPayout($r)) $missing[] = 'bank_or_upi';
        if (empty($r['pan']) && empty($r['gst_number']) && empty($r['enrolment_no'])) $missing[] = 'pan_or_gst_or_enrolment';
        // phone(OTP): the vendor's user phone must be verified
        $v = $db->prepare("SELECT u.phone_verified_at FROM vendors ve JOIN users u ON u.id=ve.user_id WHERE ve.id=? LIMIT 1");
        $v->execute([$vendorId]);
        if (!$v->fetchColumn()) $missing[] = 'phone_otp_verified';
        if ($missing) return ['ok' => false, 'reason' => 'incomplete', 'missing' => $missing];
        $db->prepare("UPDATE commerce_seller_kyc SET status='submitted', submitted_at=NOW(), updated_at=NOW() WHERE vendor_id=?")->execute([$vendorId]);
        return ['ok' => true, 'status' => 'submitted'];
    }

    public static function sellerReview(PDO $db, int $vendorId, bool $approve, ?string $note, int $reviewerId): array
    {
        $r = self::sellerGet($db, $vendorId);
        if (!$r) return ['ok' => false, 'reason' => 'no_application'];
        if ($r['status'] !== 'submitted') return ['ok' => false, 'reason' => 'not_submitted', 'state' => $r['status']];
        $status = $approve ? 'approved' : 'rejected';
        $db->prepare("UPDATE commerce_seller_kyc SET status=?, review_note=?, reviewed_at=NOW(), reviewed_by=?, updated_at=NOW() WHERE vendor_id=?")
           ->execute([$status, $note, $reviewerId, $vendorId]);
        if ($approve) {
            // activate the seller (the vendor-approval gate) now that KYC + payout exist
            $db->prepare("UPDATE vendors SET status='active', updated_at=NOW() WHERE id=?")->execute([$vendorId]);
        }
        return ['ok' => true, 'status' => $status];
    }

    // ── RIDER ───────────────────────────────────────────────────────────────
    public static function riderGet(PDO $db, int $uid): ?array
    {
        $s = $db->prepare("SELECT * FROM commerce_rider_kyc WHERE rider_user_id=? LIMIT 1");
        $s->execute([$uid]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function riderUpsert(PDO $db, int $uid, array $data): array
    {
        $row = self::riderGet($db, $uid);
        if ($row && in_array($row['status'], ['submitted','approved'], true)) {
            return ['ok' => false, 'reason' => $row['status'] === 'approved' ? 'already_approved' : 'under_review'];
        }
        $fields = array_intersect_key($data, array_flip(self::RIDER_FIELDS));
        if (!$row) {
            $cols = array_keys($fields);
            $db->prepare("INSERT INTO commerce_rider_kyc (rider_user_id" . ($cols ? ',' . implode(',', array_map(fn($c) => "`$c`", $cols)) : '') . ", status, created_at, updated_at) VALUES (?" . str_repeat(',?', count($cols)) . ", 'draft', NOW(), NOW())")
               ->execute(array_merge([$uid], array_values($fields)));
        } elseif ($fields) {
            $set = implode(', ', array_map(fn($c) => "`$c`=?", array_keys($fields)));
            $db->prepare("UPDATE commerce_rider_kyc SET $set, status='draft', review_note=NULL, updated_at=NOW() WHERE rider_user_id=?")
               ->execute(array_merge(array_values($fields), [$uid]));
        }
        return ['ok' => true, 'kyc' => self::riderGet($db, $uid)];
    }

    public static function riderSubmit(PDO $db, int $uid): array
    {
        $r = self::riderGet($db, $uid);
        if (!$r) return ['ok' => false, 'reason' => 'no_application'];
        if (in_array($r['status'], ['submitted','approved'], true)) return ['ok' => false, 'reason' => $r['status']];
        $missing = [];
        foreach (['full_name','address','licence_number','vehicle_number','photo_media_id','licence_media_id','rc_media_id'] as $f) if (empty($r[$f])) $missing[] = $f;
        if (!self::hasPayout($r)) $missing[] = 'bank_or_upi';
        // phone(OTP): the rider user's phone must be verified
        $v = $db->prepare("SELECT phone_verified_at FROM users WHERE id=? LIMIT 1");
        $v->execute([$uid]);
        if (!$v->fetchColumn()) $missing[] = 'phone_otp_verified';
        if ($missing) return ['ok' => false, 'reason' => 'incomplete', 'missing' => $missing];
        $db->prepare("UPDATE commerce_rider_kyc SET status='submitted', submitted_at=NOW(), updated_at=NOW() WHERE rider_user_id=?")->execute([$uid]);
        return ['ok' => true, 'status' => 'submitted'];
    }

    public static function riderReview(PDO $db, int $uid, bool $approve, ?string $note, int $reviewerId, ?int $cityId = null): array
    {
        $r = self::riderGet($db, $uid);
        if (!$r) return ['ok' => false, 'reason' => 'no_application'];
        if ($r['status'] !== 'submitted') return ['ok' => false, 'reason' => 'not_submitted', 'state' => $r['status']];
        $status = $approve ? 'approved' : 'rejected';
        $db->prepare("UPDATE commerce_rider_kyc SET status=?, review_note=?, reviewed_at=NOW(), reviewed_by=?, updated_at=NOW() WHERE rider_user_id=?")
           ->execute([$status, $note, $reviewerId, $uid]);
        if ($approve) {
            // create the rider's delivery profile (reuses S6 delivery_riders) if absent
            $exists = $db->prepare("SELECT 1 FROM delivery_riders WHERE rider_id=? LIMIT 1");
            $exists->execute([$uid]);
            if (!$exists->fetchColumn()) {
                $db->prepare("INSERT INTO delivery_riders (rider_id, city_id, is_online, vehicle_type, created_at, updated_at) VALUES (?,?,0,?,NOW(),NOW())")
                   ->execute([$uid, $cityId, $r['vehicle_type'] ?: 'bike']);
            }
        }
        return ['ok' => true, 'status' => $status];
    }

    // ── admin review queue ────────────────────────────────────────────────────
    public static function pending(PDO $db): array
    {
        $sellers = $db->query("SELECT vendor_id, owner_name, status, submitted_at FROM commerce_seller_kyc WHERE status='submitted' ORDER BY submitted_at ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $riders  = $db->query("SELECT rider_user_id, full_name, status, submitted_at FROM commerce_rider_kyc WHERE status='submitted' ORDER BY submitted_at ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['sellers' => $sellers, 'riders' => $riders];
    }
}
