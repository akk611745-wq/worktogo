<?php
// ============================================================================
//  /api/tint/* — custom-tint flow (Paint Unit P5). STRUCTURE, data-empty + OFF.
// ----------------------------------------------------------------------------
//  Public customer flow endpoints over CustomTint (the lib ruleset). Until the
//  Prisma shade data lands and config turns it on, /config reports enabled:false
//  and the brand-code guard rejects every code (fandeck empty).
//    GET  /api/tint/config      → {enabled, advance_pct, consent_required, confirm_call}
//    POST /api/tint/check-code  → {brand, code} → brand-code guard result
//    POST /api/tint/validate    → order-intent blockers (consent/advance/code)
//  Additive; no engine edit. Dispatched from heart/index.php on /api/tint.
// ============================================================================

require_once SYSTEM_ROOT . '/lib/commerce/CustomTint.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommercePayment.php';

if ($method === 'GET' && $uri === '/api/tint/config') {
    Response::success([
        'enabled'          => CustomTint::enabled($db),           // any brand live?
        'enabled_brands'   => CustomTint::enabledBrands($db),     // per-brand go-live list
        'advance_pct'      => CommercePayment::advancePct($db),
        'consent_required' => CustomTint::consentRequired($db),
    ], 200, 'OK');
    exit;
}

if ($method === 'POST' && $uri === '/api/tint/check-code') {
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $brand = (string)($in['brand'] ?? '');
    $code  = (string)($in['code'] ?? $in['shade_code'] ?? '');
    $shade = CustomTint::resolveShade($db, $brand, $code);
    if ($shade) {
        Response::success(['found' => true, 'base_type' => $shade['base_type'], 'shade_name' => $shade['shade_name'], 'finish' => $shade['finish']], 200, 'OK');
    } else {
        // brand-code guard: reject a code not in the chosen brand (or empty fandeck)
        Response::success(['found' => false, 'message' => 'This code isn’t in this brand.'], 200, 'OK');
    }
    exit;
}

if ($method === 'POST' && $uri === '/api/tint/validate') {
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $attrs = ['shade_mode' => 'tintable', 'custom_tintable' => 'yes', 'brand' => (string)($in['brand'] ?? '')];
    $blockers = CustomTint::validateOrderIntent($db, $attrs, [
        'brand'           => (string)($in['brand'] ?? ''),
        'shade_code'      => (string)($in['shade_code'] ?? $in['code'] ?? ''),
        'mode'            => (string)($in['mode'] ?? 'new'),
        'consent'         => !empty($in['consent']),
        'advance_started' => !empty($in['advance_started']),
    ]);
    Response::success(['ok' => empty($blockers), 'blockers' => $blockers], 200, 'OK');
    exit;
}

Response::notFound('Tint endpoint');
