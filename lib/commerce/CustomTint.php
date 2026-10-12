<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CustomTint  (Paint Unit P5: custom-tint structure)
//  paint-unit-complete-pack.md D.2 Path B + F.6. The made-to-order tint spine:
//  brand-code guard, new-vs-repaint rule, repeat→same-seller routing, consent +
//  advance gate, and the ops confirm-call flag. DATA-EMPTY (shade/base/code fills
//  from Prisma at P6) and OFF in config (UnitConfig paint.custom_tint_enabled)
//  until then — ready paint runs meanwhile. Pure lib + config + the P5 tables;
//  no engine edit. The advance itself is P4 (CommercePayment).
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/PaintUnit.php';
require_once __DIR__ . '/UnitConfig.php';

class CustomTint
{
    /** True if ANY brand has custom tint switched on (for the global UI banner). */
    public static function enabled(PDO $db): bool
    {
        return count(UnitConfig::customTintBrands($db)) > 0;
    }

    /** The admin "enabled brands" list (as stored). */
    public static function enabledBrands(PDO $db): array { return UnitConfig::customTintBrands($db); }

    /** Is this brand's custom tint live? (case-insensitive) */
    public static function brandEnabled(PDO $db, ?string $brand): bool { return UnitConfig::customTintBrandEnabled($db, $brand); }

    /** Admin: switch a brand ON (add to commerce_custom_tint_brands). Returns the new list. */
    public static function enableBrand(PDO $db, string $brand): array
    {
        $brand = trim($brand);
        $list = self::enabledBrands($db);
        $exists = false; foreach ($list as $b) if (strtolower($b) === strtolower($brand)) { $exists = true; break; }
        if (!$exists && $brand !== '') $list[] = $brand;
        $db->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES ('commerce_custom_tint_brands',?, 'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([json_encode(array_values($list))]);
        return $list;
    }

    /** Admin: switch a brand OFF. Returns the new list. */
    public static function disableBrand(PDO $db, string $brand): array
    {
        $list = array_values(array_filter(self::enabledBrands($db), fn($b) => strtolower($b) !== strtolower(trim($brand))));
        $db->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES ('commerce_custom_tint_brands',?, 'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([json_encode($list)]);
        return $list;
    }

    /** Attribute test (delegates to the PaintUnit convention). */
    public static function isCustomTint(array $attrs): bool { return PaintUnit::isCustomTint($attrs); }

    /** Custom tint is made-to-order → never returnable. */
    public static function isNonReturnable(array $attrs): bool { return self::isCustomTint($attrs); }

    // ── brand-code guard ────────────────────────────────────────────────────
    //  A shade code only matches WITHIN the brand the customer chose. An unknown
    //  code (or any code while the fandeck is data-empty) is rejected.
    public static function resolveShade(PDO $db, string $brand, string $code): ?array
    {
        $brand = trim($brand); $code = trim($code);
        if ($brand === '' || $code === '') return null;
        try {
            $s = $db->prepare("SELECT brand, product_line, shade_code, shade_name, base_type, finish FROM commerce_paint_shades WHERE brand=? AND shade_code=? LIMIT 1");
            $s->execute([$brand, $code]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /** True only if the code exists in THAT brand's fandeck. */
    public static function brandHasCode(PDO $db, string $brand, string $code): bool
    {
        return self::resolveShade($db, $brand, $code) !== null;
    }

    // ── new-vs-repaint rule ───────────────────────────────────────────────────
    //  repaint/match → EXACT only, same base, same seller (a tiny difference shows
    //  on an already-painted wall). new/whole job → minor adjustment allowed.
    public static function modeRule(string $mode): array
    {
        $m = strtolower(trim($mode));
        if ($m === 'repaint' || $m === 'match') {
            return ['mode' => 'repaint', 'exact_only' => true, 'same_base' => true, 'same_seller' => true, 'adjustment_allowed' => false];
        }
        return ['mode' => 'new', 'exact_only' => false, 'same_base' => false, 'same_seller' => false, 'adjustment_allowed' => true];
    }

    // ── repeat → same seller ──────────────────────────────────────────────────
    /** Record that a customer's brand+code tint was made by a seller. */
    public static function recordMade(PDO $db, ?int $userId, ?string $customerRef, string $brand, string $code, ?string $baseType, int $vendorId, ?int $orderId = null): bool
    {
        try {
            $db->prepare("INSERT INTO commerce_tint_order_log (user_id, customer_ref, brand, shade_code, base_type, vendor_id, order_id, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
               ->execute([$userId, $customerRef, trim($brand), trim($code), $baseType, $vendorId, $orderId]);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /** The seller who last made this customer's brand+code (null if first time). */
    public static function preferredSeller(PDO $db, ?int $userId, string $brand, string $code): ?int
    {
        if (!$userId) return null;
        try {
            $s = $db->prepare("SELECT vendor_id FROM commerce_tint_order_log WHERE user_id=? AND brand=? AND shade_code=? ORDER BY id DESC LIMIT 1");
            $s->execute([$userId, trim($brand), trim($code)]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null) ? null : (int)$v;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Routing preference for a tint order: for a repeat repaint-exact, pin the
     * seller who made it before (so the shade matches). Returns the vendor id to
     * try first, or null to fall through to normal routing.
     */
    public static function routeFirstSeller(PDO $db, ?int $userId, string $brand, string $code, string $mode): ?int
    {
        if (!self::modeRule($mode)['same_seller']) return null; // 'new' can go to anyone
        return self::preferredSeller($db, $userId, $brand, $code);
    }

    // ── consent + confirm-call + order-intent validation ──────────────────────
    public static function consentRequired(PDO $db): bool
    {
        try { $s=$db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_custom_tint_consent_required' LIMIT 1"); $s->execute(); $v=$s->fetchColumn();
              return $v === false ? true : !in_array((string)$v, ['0','false','no',''], true); }
        catch (\Throwable $e) { return true; }
    }

    private static function confirmCallCfg(PDO $db): array
    {
        $def = ['enabled' => false, 'min_value' => 5000, 'custom_always' => true];
        try { $s=$db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_confirm_call' LIMIT 1"); $s->execute(); $v=$s->fetchColumn();
              if ($v) { $j=json_decode((string)$v,true); if (is_array($j)) return $j + $def; } }
        catch (\Throwable $e) {}
        return $def;
    }

    /** Should ops confirm-call this order? (admin on/off; always-for-custom + value threshold). */
    public static function needsConfirmCall(PDO $db, float $orderTotal, bool $isCustom): bool
    {
        $c = self::confirmCallCfg($db);
        if (empty($c['enabled'])) return false;
        if ($isCustom && !empty($c['custom_always'])) return true;
        return $orderTotal >= (float)($c['min_value'] ?? 5000);
    }

    /**
     * Validate a custom-tint order intent before it may submit.
     * $flags: consent(bool), advance_started(bool), brand, shade_code, mode.
     * Returns the list of blockers (empty = may proceed). Guards the whole Path B.
     */
    public static function validateOrderIntent(PDO $db, array $attrs, array $flags): array
    {
        $blockers = [];
        if (!self::isCustomTint($attrs)) return $blockers; // ready paint: nothing to gate here
        $brand = (string)($flags['brand'] ?? ($attrs['brand'] ?? ''));
        if (!self::brandEnabled($db, $brand)) { $blockers[] = 'brand_not_enabled'; return $blockers; }
        $code  = (string)($flags['shade_code'] ?? '');
        if ($code === '' || !self::brandHasCode($db, $brand, $code)) $blockers[] = 'shade_code_not_in_brand';
        if (self::consentRequired($db) && empty($flags['consent'])) $blockers[] = 'consent_required';
        if (empty($flags['advance_started'])) $blockers[] = 'advance_required';
        return $blockers;
    }
}
