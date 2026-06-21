<?php
// ============================================================
//  GET /api/search
//  Unified search across services and products.
//  Query params: q (required), type (all|services|products), page, limit
// ============================================================

$q     = substr(trim($_GET['q'] ?? ''), 0, 100);
$type  = trim($_GET['type']  ?? 'all');
$page  = max(1, (int) ($_GET['page']  ?? 1));
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

if (strlen($q) < 2) {
    Response::validation('Search query must be at least 2 characters');
}

$results  = [];
$totals   = [];

try {
    $searchTerm = '%' . $q . '%';

    // ── Search Services (only if service engine exists) ───────
    if (in_array($type, ['all', 'services'], true)) {
        try {
            $hasPhase2A = false;
            $checkStmt = $db->query("SHOW COLUMNS FROM services LIKE 'deleted_at'");
            if ($checkStmt && $checkStmt->rowCount() > 0) {
                $hasPhase2A = true;
            }

            $hasShortDesc = false;
            $shortDescStmt = $db->query("SHOW COLUMNS FROM services LIKE 'short_desc'");
            if ($shortDescStmt && $shortDescStmt->rowCount() > 0) {
                $hasShortDesc = true;
            }

            $hasSlug = false;
            $slugStmt = $db->query("SHOW COLUMNS FROM services LIKE 'slug'");
            if ($slugStmt && $slugStmt->rowCount() > 0) {
                $hasSlug = true;
            }

            $hasRating = false;
            $ratingStmt = $db->query("SHOW COLUMNS FROM services LIKE 'rating'");
            if ($ratingStmt && $ratingStmt->rowCount() > 0) {
                $hasRating = true;
            }

            $hasFeatured = false;
            $featuredStmt = $db->query("SHOW COLUMNS FROM services LIKE 'is_featured'");
            if ($featuredStmt && $featuredStmt->rowCount() > 0) {
                $hasFeatured = true;
            }

            $slugSelect = $hasSlug ? 's.slug' : "'' AS slug";
            $shortDescSelect = $hasShortDesc ? 's.short_desc' : "'' AS short_desc";
            $ratingSelect = $hasRating ? 's.rating' : '0.00 AS rating';
            $categoryIconSelect = "NULL AS category_icon";
            try {
                $categoryIconStmt = $db->query("SHOW COLUMNS FROM categories LIKE 'icon'");
                if ($categoryIconStmt && $categoryIconStmt->rowCount() > 0) {
                    $categoryIconSelect = 'c.icon AS category_icon';
                }
            } catch (PDOException) {}
            $shortDescWhere = $hasShortDesc ? ' OR s.short_desc LIKE :q' : '';
            $featuredOrder = $hasFeatured ? 's.is_featured DESC, ' : '';
            $ratingOrder = $hasRating ? 's.rating DESC, ' : '';

            // Match the broader field set the frontend's client-side fallback
            // (_searchText in app/pages/home.js) already covers, so full-catalog
            // search works the same regardless of what's currently loaded on
            // screen: vendor business name, category name/slug, and the
            // vendor's service localities (the last is an optional column —
            // guarded like the other Phase 2A columns above).
            $hasServiceLocalities = false;
            $localitiesStmt = $db->query("SHOW COLUMNS FROM vendors LIKE 'service_localities'");
            if ($localitiesStmt && $localitiesStmt->rowCount() > 0) {
                $hasServiceLocalities = true;
            }
            $localitiesWhere = $hasServiceLocalities ? ' OR v.service_localities LIKE :q' : '';
            $vendorCategoryWhere = " OR v.business_name LIKE :q OR c.name LIKE :q OR c.slug LIKE :q{$localitiesWhere}";

            if ($hasPhase2A) {
                $svcQuery = "SELECT s.id, s.name, {$slugSelect}, {$shortDescSelect}, s.base_price, {$ratingSelect},
                        'service' AS result_type,
                        v.business_name AS vendor_name,
                        c.slug AS category_slug,
                        c.name AS category_name,
                        {$categoryIconSelect}
                 FROM services s
                 LEFT JOIN vendors v ON v.id = s.vendor_id
                 LEFT JOIN categories c ON c.id = s.category_id
                 WHERE s.status = 'active' AND s.deleted_at IS NULL
                   AND (s.name LIKE :q OR s.description LIKE :q{$shortDescWhere}{$vendorCategoryWhere})
                 ORDER BY {$featuredOrder}{$ratingOrder}s.name ASC
                 LIMIT :limit OFFSET :offset";
            } else {
                $svcQuery = "SELECT s.id, s.name, {$slugSelect}, {$shortDescSelect}, s.base_price, {$ratingSelect},
                        'service' AS result_type,
                        v.business_name AS vendor_name,
                        c.slug AS category_slug,
                        c.name AS category_name,
                        {$categoryIconSelect}
                 FROM services s
                 LEFT JOIN vendors v ON v.id = s.vendor_id
                 LEFT JOIN categories c ON c.id = s.category_id
                 WHERE s.status = 'active'
                   AND (s.name LIKE :q{$shortDescWhere}{$vendorCategoryWhere})
                 ORDER BY {$featuredOrder}{$ratingOrder}s.id DESC
                 LIMIT :limit OFFSET :offset";
            }

            $svcStmt = $db->prepare($svcQuery);
            $svcStmt->bindValue(':q',      $searchTerm);
            $svcStmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
            $svcStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $svcStmt->execute();
            $results['services'] = $svcStmt->fetchAll();
            $totals['services']  = count($results['services']);
        } catch (PDOException) {
            $results['services'] = []; // Engine table may not exist yet
        }
    }

    // ── Search Products (only if shopping engine exists) ──────
    if (in_array($type, ['all', 'products'], true)) {
        try {
            $prdStmt = $db->prepare(
                "SELECT p.id, p.name, p.slug, p.short_desc AS short_desc,
                        p.sale_price AS base_price, p.rating,
                        'product' AS result_type,
                        v.business_name AS vendor_name
                 FROM products p
                 LEFT JOIN vendors v ON v.id = p.vendor_id
                 WHERE p.status = 'active' AND p.deleted_at IS NULL
                   AND (p.name LIKE :q OR p.description LIKE :q)
                 ORDER BY p.is_featured DESC, p.rating DESC
                 LIMIT :limit OFFSET :offset"
            );
            $prdStmt->bindValue(':q',      $searchTerm);
            $prdStmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
            $prdStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $prdStmt->execute();
            $results['products'] = $prdStmt->fetchAll();
            $totals['products']  = count($results['products']);
        } catch (PDOException) {
            $results['products'] = [];
        }
    }

    Response::success([
        'query'      => $q,
        'type'       => $type,
        'results'    => $results,
        'totals'     => $totals,
        'pagination' => ['page' => $page, 'limit' => $limit],
    ]);

} catch (PDOException $e) {
    Logger::error('Search error', ['error' => $e->getMessage(), 'q' => $q]);
    Response::serverError('Search unavailable. Please try again.');
}
