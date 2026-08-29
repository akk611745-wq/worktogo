<?php
/**
 * ServiceTools.php — WorkToGo Agent Layer :: SERVICE-engine tools (Phase 2)
 * ----------------------------------------------------------------------------
 * The concrete, allow-listed tools the service-agent may invoke. Each maps to
 * ONE real service-engine endpoint via the EngineClient — the engine does all
 * authorization, validation, city-scoping and writes. Nothing here bypasses it.
 *
 * Customer tools (role 'customer'):
 *   find_vendors            [read ]  GET  /api/vendors
 *   get_vendor_profile      [read ]  GET  /api/vendors/{id}
 *   get_packages            [read ]  GET  /api/service/packages
 *   get_expert_visit_price  [read ]  GET  /api/service/expert-visit-fee
 *   create_booking          [WRITE]  POST /api/service/request (direct_vendor)
 *   request_expert_visit    [WRITE]  POST /api/service/request (inspection)
 *
 * Vendor tools (role 'vendor_service') — per-role toolset, RBAC-scoped:
 *   list_my_jobs            [read ]  GET  /api/vendor/jobs
 *   get_my_profile          [read ]  GET  /api/vendor/profile
 *
 * WRITE tools never run without an explicit user confirmation step — that gate is
 * enforced by the orchestrator, and the engine re-validates everything anyway.
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentTool.php';

/** Maps casual category words (English/Hinglish) to WorkToGo category slugs. */
final class ServiceCategoryMap
{
    private const MAP = [
        'painter' => 'painting', 'paint' => 'painting', 'painting' => 'painting',
        'plumber' => 'plumber', 'plumbing' => 'plumber', 'nal' => 'plumber',
        'electrician' => 'electrician', 'electric' => 'electrician', 'bijli' => 'electrician',
        'waterproof' => 'waterproofing', 'waterproofing' => 'waterproofing', 'seepage' => 'waterproofing',
        'cctv' => 'cctv', 'camera' => 'cctv', 'security camera' => 'cctv',
    ];

    public static function normalize(string $category): string
    {
        $c = strtolower(trim($category));
        return self::MAP[$c] ?? $c;   // unknown terms pass through unchanged
    }
}

// ── Customer reads ───────────────────────────────────────────────────────────

final class FindVendorsTool extends AbstractTool
{
    public function name(): string { return 'find_vendors'; }
    public function description(): string { return 'Find active service vendors by category (e.g. painting, plumber). Optional free-text search.'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['category' => 'string', 'area' => 'string?', 'search' => 'string?', 'limit' => 'int?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        $query = ['type' => ServiceCategoryMap::normalize($a['category'])];
        if (!empty($a['search'])) { $query['search'] = $a['search']; }
        $query['limit'] = (int) ($a['limit'] ?? 20);
        return $tc->engine->get('/api/vendors', $query);
    }
}

final class GetVendorProfileTool extends AbstractTool
{
    public function name(): string { return 'get_vendor_profile'; }
    public function description(): string { return 'Get one vendor profile by its id.'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['id' => 'int']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        return $tc->engine->get('/api/vendors/' . (int) $a['id']);
    }
}

final class GetPackagesTool extends AbstractTool
{
    public function name(): string { return 'get_packages'; }
    public function description(): string { return 'Get service packages (Standard/Premium/…) for a category, with sqft pricing.'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['category' => 'string', 'city' => 'string?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        $query = ['category' => ServiceCategoryMap::normalize($a['category'])];
        if (!empty($a['city'])) { $query['city'] = $a['city']; }
        return $tc->engine->get('/api/service/packages', $query);
    }
}

final class GetExpertVisitPriceTool extends AbstractTool
{
    public function name(): string { return 'get_expert_visit_price'; }
    public function description(): string { return 'Get the expert-visit fee (free or paid) for a category in the user\'s city.'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['category' => 'string', 'city' => 'string?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        $query = ['category' => ServiceCategoryMap::normalize($a['category'])];
        if (!empty($a['city'])) { $query['city'] = $a['city']; }
        return $tc->engine->get('/api/service/expert-visit-fee', $query);
    }
}

// ── Customer writes (require confirmation; engine re-validates) ───────────────

final class CreateBookingTool extends AbstractTool
{
    public function name(): string { return 'create_booking'; }
    public function description(): string { return 'Book a specific vendor directly. Needs vendor_id and a future scheduled_at.'; }
    public function mode(): string { return 'write'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['vendor_id' => 'int', 'scheduled_at' => 'string', 'notes' => 'string?', 'address_id' => 'int?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        $body = [
            'booking_mode' => 'direct_vendor',
            'vendor_id'    => (int) $a['vendor_id'],
            'scheduled_at' => $a['scheduled_at'],
        ];
        if (!empty($a['notes']))      { $body['notes'] = $a['notes']; }
        if (!empty($a['address_id'])) { $body['address_id'] = (int) $a['address_id']; }
        return $tc->engine->post('/api/service/request', $body);
    }
}

final class RequestExpertVisitTool extends AbstractTool
{
    public function name(): string { return 'request_expert_visit'; }
    public function description(): string { return 'Request a company expert visit for a category. Needs category and a future scheduled_at.'; }
    public function mode(): string { return 'write'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['category' => 'string', 'scheduled_at' => 'string', 'address_id' => 'int?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        $body = [
            'booking_mode'  => 'inspection',
            'category_slug' => ServiceCategoryMap::normalize($a['category']),
            'scheduled_at'  => $a['scheduled_at'],
        ];
        if (!empty($a['address_id'])) { $body['address_id'] = (int) $a['address_id']; }
        return $tc->engine->post('/api/service/request', $body);
    }
}

// ── Vendor reads (per-role toolset) ──────────────────────────────────────────

final class ListMyJobsTool extends AbstractTool
{
    public function name(): string { return 'list_my_jobs'; }
    public function description(): string { return 'List the calling vendor\'s own jobs (optionally by status).'; }
    public function allowedRoles(): array { return ['vendor_service']; }
    public function argSpec(): array { return ['status' => 'string?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        $query = [];
        if (!empty($a['status'])) { $query['status'] = $a['status']; }
        return $tc->engine->get('/api/vendor/jobs', $query);
    }
}

final class GetMyProfileTool extends AbstractTool
{
    public function name(): string { return 'get_my_profile'; }
    public function description(): string { return 'Get the calling vendor\'s own profile.'; }
    public function allowedRoles(): array { return ['vendor_service']; }
    public function argSpec(): array { return []; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        return $tc->engine->get('/api/vendor/profile');
    }
}
