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

    // product_id → read the product's paint coverage/pack attributes (shared
    // path with the tests, so the estimate never drifts); else estimate raw.
    if (!empty($in['product_id'])) {
        $est = CoverageCalculator::estimateForProduct($db, (int)$in['product_id'], $in);
    } else {
        $est = CoverageCalculator::estimate($in, $db);
    }
    Response::success($est, 200, 'Estimated');
    exit;
}

Response::notFound('Coverage endpoint');
