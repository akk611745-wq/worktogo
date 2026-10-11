<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — PaintUnit  (Paint Unit P1: attribute convention)
//  commerce blueprint §M + paint-unit-complete-pack.md D.1. The canonical,
//  ENFORCEABLE convention for paint products. Paint needs more than a generic
//  product row, but it needs ZERO new engine columns — every paint field lives
//  in the existing `products.attributes` JSON. This class is the single source
//  of truth for (a) the 7 paint families, (b) the attribute keys, (c) which are
//  required for a valid catalogue row, and (d) whether a row is a custom tint.
//  Pure convention — no DB, no engine edit. UnitConfig consumes it at the
//  OrderabilityResolver layer-4 hook.
// ─────────────────────────────────────────────────────────────────────────────

class PaintUnit
{
    /** The 7 paint families (value stored in products.attributes.category). */
    public const FAMILIES = [
        'interior-emulsion', 'exterior-emulsion', 'enamel', 'distemper',
        'primer', 'putty', 'waterproofing',
    ];

    /** The DB `categories.slug` rows these families map to (paint- namespaced). */
    public const CATEGORY_SLUGS = [
        'paint-interior-emulsion', 'paint-exterior-emulsion', 'paint-enamel',
        'paint-distemper', 'paint-primer', 'paint-putty', 'paint-waterproofing',
    ];

    /** Every attribute key a paint row may carry (D.1). Price=offer, stock=inventory — NOT here. */
    public const ATTRIBUTES = [
        'brand', 'product_line', 'tier', 'product_name', 'category', 'surface',
        'finish', 'pack_size', 'pack_unit', 'coverage_value', 'coverage_unit',
        'coverage_basis_coats', 'recommended_coats', 'warranty_years',
        'warranty_reference', 'shade_mode', 'custom_tintable', 'base_type',
        'shade_name', 'shade_code', 'recommended_primer', 'recommended_putty',
        'search_keywords', 'source_type', 'source_verified_at', 'returnable',
    ];

    /** Controlled vocabularies (validated softly — warn, don't hard-block unknowns). */
    public const TIERS    = ['economy', 'mid', 'premium'];
    public const SURFACES = ['interior', 'exterior', 'wood', 'metal'];
    public const FINISHES = ['matt', 'sheen', 'gloss', 'satin'];
    public const SHADE_MODES = ['ready', 'tintable'];

    /** Minimum a seller/catalogue row must carry to be a valid paint entry. */
    public const REQUIRED_READY = ['brand', 'product_name', 'category', 'pack_size', 'pack_unit', 'finish', 'shade_mode', 'returnable'];
    /** A tintable row additionally declares its base + that it is custom-tintable. */
    public const REQUIRED_TINT  = ['brand', 'product_name', 'category', 'pack_size', 'pack_unit', 'shade_mode', 'custom_tintable', 'base_type', 'returnable'];

    /** True when this attribute set is a made-to-order custom tint (non-returnable, advance). */
    public static function isCustomTint(array $attrs): bool
    {
        $mode = strtolower(trim((string)($attrs['shade_mode'] ?? '')));
        if ($mode !== 'tintable') return false;
        $ct = $attrs['custom_tintable'] ?? false;
        if (is_bool($ct)) return $ct;
        return in_array(strtolower(trim((string)$ct)), ['1', 'yes', 'true', 'y'], true);
    }

    /** Is this products.attributes.category one of the 7 paint families? */
    public static function isPaintFamily(?string $category): bool
    {
        return $category !== null && in_array(strtolower(trim($category)), self::FAMILIES, true);
    }

    /**
     * Validate a catalogue attribute set. Returns the list of MISSING required
     * keys (empty = valid). Tintable rows use the tint required-set. Convention
     * check only — it never writes and never blocks the engine.
     */
    public static function validate(array $attrs): array
    {
        $mode = strtolower(trim((string)($attrs['shade_mode'] ?? '')));
        $required = ($mode === 'tintable') ? self::REQUIRED_TINT : self::REQUIRED_READY;
        $missing = [];
        foreach ($required as $k) {
            $v = $attrs[$k] ?? null;
            if ($v === null || $v === '' || (is_array($v) && $v === [])) $missing[] = $k;
        }
        return $missing;
    }
}
