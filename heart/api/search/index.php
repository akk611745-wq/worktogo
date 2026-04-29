<?php
// ============================================================
//  GET /api/search
//  Unified search across services and products.
//  Query params: q (required), type (all|services|products), page, limit
// ============================================================

$q     = trim($_GET['q']     ?? '');
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

            if ($hasPhase2A) {
                $svcQuery = "SELECT s.id, s.name, s.slug, s.short_desc, s.base_price, s.rating,
                        'service' AS result_type,
                        v.business_name AS vendor_name
                 FROM services s
                 LEFT JOIN vendors v ON v.id = s.vendor_id
                 WHERE s.status = 'active' AND s.deleted_at IS NULL
                   AND (s.name LIKE :q OR s.description LIKE :q OR s.short_desc LIKE :q)
                 ORDER BY s.is_featured DESC, s.rating DESC
                 LIMIT :limit OFFSET :offset";
            } else {
                $svcQuery = "SELECT s.id, s.name, '' AS slug, '' AS short_desc, s.base_price, 0.00 AS rating,
                        'service' AS result_type,
                        v.business_name AS vendor_name
                 FROM services s
                 LEFT JOIN vendors v ON v.id = s.vendor_id
                 WHERE s.status = 'active'
                   AND (s.name LIKE :q)
                 ORDER BY s.id DESC
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
