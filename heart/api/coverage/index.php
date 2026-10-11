<?php
// ============================================================================
//  /api/coverage/* — paint coverage calculator + guide  (Paint Unit P2)
// ----------------------------------------------------------------------------
//  Public (pre-login) customer tool — the "how much paint do I need?" moat.
//  Thin adapter over CoverageCalculator (the single math source of truth):
//    POST /api/coverage/estimate   body: net_area_sqft OR area_value+area_unit
//                                   OR length_ft/width_ft/height_ft(+ceiling,
//                                   openings_sqft); coverage_per_l, basis_coats,
//                                   recommended_coats, pack_sizes[].
//                                   If product_id is given, paint coverage/pack
//                                   attributes are read from the product.
//    GET  /api/coverage/guide      prep + situation guide.
//  Every estimate carries the "Estimated requirement" label + disclaimer.
//  Additive; no engine edit. Dispatched from heart/index.php on /api/coverage.
// ============================================================================

require_once SYSTEM_ROOT . '/lib/commerce/CoverageCalculator.php';
require_once SYSTEM_ROOT . '/lib/commerce/PaintUnit.php';

if ($method === 'GET' && $uri === '/api/coverage/guide') {
    Response::success(['guide' => CoverageCalculator::guide()], 200, 'OK');
    exit;
}

if ($method === 'POST' && $uri === '/api/coverage/estimate') {
    $in = json_decode(file_get_contents('php://input'), true) ?? [];

    // Optional: pull coverage/pack attributes straight from a catalogue product.
    if (!empty($in['product_id'])) {
        try {
            $s = $db->prepare("SELECT attributes FROM products WHERE id=? LIMIT 1");
            $s->execute([(int)$in['product_id']]);
            $a = $s->fetchColumn();
            $attrs = $a ? (json_decode((string)$a, true) ?: []) : [];
            if (isset($attrs['coverage_value']) && !isset($in['coverage_per_l'])) $in['coverage_per_l'] = (float)$attrs['coverage_value'];
            if (isset($attrs['coverage_basis_coats']) && !isset($in['basis_coats'])) $in['basis_coats'] = (int)$attrs['coverage_basis_coats'];
            if (isset($attrs['recommended_coats']) && !isset($in['recommended_coats'])) $in['recommended_coats'] = (int)$attrs['recommended_coats'];
            if (isset($attrs['pack_size']) && empty($in['pack_sizes'])) $in['pack_sizes'] = [(float)$attrs['pack_size']];
        } catch (\Throwable $e) { error_log('[api/coverage] product read: ' . $e->getMessage()); }
    }

    $est = CoverageCalculator::estimate($in, $db);
    Response::success($est, 200, 'Estimated');
    exit;
}

Response::notFound('Coverage endpoint');
