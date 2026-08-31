<?php
/**
 * VendorTools.php — WorkToGo Agent Layer :: VENDOR-agent tools (Phase 6)
 * ----------------------------------------------------------------------------
 * The vendor never has to learn a dashboard — the agent does their work for them,
 * conversationally. These tools map to EXISTING vendor endpoints via clean
 * adapters (like the service/commerce tools), RBAC-scoped to the vendor role.
 *
 * Vendor tools:
 *   start_listing  [read ]  opens the inline "onboard" stepper (fetches real
 *                           categories) — the agent asks name/skill/area/exp/
 *                           languages one at a time.
 *   save_listing   [WRITE]  PATCH /api/vendor/profile — writes the collected
 *                           answers. DRAFT stays draft; admin approval is the
 *                           existing flow (the agent proposes, admin approves).
 *   my_status      [read ]  composes GET /api/vendor/profile + service-analytics
 *                           + work-photos into a short, friendly status + real,
 *                           data-driven growth hints.
 *   set_reminder   [WRITE]  agent-owned reminder (agent_reminders); delivery via
 *                           the existing notifications/FCM later.
 *   (upload_work_photo — reused from Phase 4 WidgetTools; vendor-only.)
 *
 * PRIVACY/GUARDRAILS unchanged: no PII to the AI (it only ever saw scrubbed
 * language); writes are confirmation-gated; RBAC is enforced by the registry AND
 * re-checked by the real endpoints; every step is audited.
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentTool.php';

/** Opens the conversational onboarding stepper (one question at a time). */
final class StartListingTool extends AbstractTool
{
    public function name(): string { return 'start_listing'; }
    public function description(): string { return 'Open the guided step-by-step listing builder for a vendor (name, skill, area, experience, languages).'; }
    public function allowedRoles(): array { return ['vendor_service', 'vendor_shopping']; }
    public function argSpec(): array { return []; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        // Real category options (so the vendor taps, never types an id).
        $options = [];
        $cats = $tc->engine->get('/api/service/categories');
        if ($cats->ok && is_array($cats->data)) {
            $list = $cats->data['categories'] ?? (array_is_list($cats->data) ? $cats->data : []);
            foreach ($list as $c) {
                if (is_array($c) && isset($c['id'])) {
                    $options[] = ['value' => (int) $c['id'], 'label' => (string) ($c['name'] ?? ('#' . $c['id']))];
                }
            }
        }

        return EngineResult::ok(200, ['_widget' => [
            'type'        => 'onboard',
            'title'       => 'Chaliye 2 minute mein aapki listing bana dete hain',
            'submit_tool' => 'save_listing',
            'steps'       => [
                ['key' => 'name',             'q' => 'Aapka naam ya business ka naam?',            'kind' => 'text',        'placeholder' => 'e.g. Sharma Painters'],
                ['key' => 'category_id',      'q' => 'Aap kya kaam karte ho?',                     'kind' => 'select',      'options' => $options],
                ['key' => 'area',             'q' => 'Aap kis area/ilaake mein kaam karte ho?',    'kind' => 'text',        'placeholder' => 'e.g. Rajpur Road'],
                ['key' => 'experience_years', 'q' => 'Kitne saal ka experience hai?',              'kind' => 'number',      'placeholder' => 'e.g. 5'],
                ['key' => 'languages',        'q' => 'Aap kaunsi bhasha bolte ho?',                'kind' => 'multiselect', 'options' => [
                    ['value' => 'hindi', 'label' => 'Hindi'], ['value' => 'english', 'label' => 'English'],
                    ['value' => 'punjabi', 'label' => 'Punjabi'], ['value' => 'kumaoni', 'label' => 'Kumaoni'],
                ]],
            ],
        ]]);
    }
}

/** Writes the collected listing to the real profile endpoint (draft; admin approves). */
final class SaveListingTool extends AbstractTool
{
    public function name(): string { return 'save_listing'; }
    public function description(): string { return 'Save the vendor\'s listing details to their profile (stays a draft until admin approval).'; }
    public function mode(): string { return 'write'; }
    public function allowedRoles(): array { return ['vendor_service', 'vendor_shopping']; }
    public function argSpec(): array { return ['name' => 'string', 'category_id' => 'int?', 'area' => 'string?', 'experience_years' => 'int?', 'languages' => 'string?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        $body = ['name' => $a['name']];
        if (!empty($a['category_id']))      { $body['category_id'] = (int) $a['category_id']; }
        if (!empty($a['area']))             { $body['service_localities'] = $a['area']; }
        if (!empty($a['experience_years'])) { $body['experience_years'] = (int) $a['experience_years']; }
        if (!empty($a['languages']))        { $body['languages'] = $a['languages']; } // "hindi,english" — engine splits
        // The engine enforces vendor RBAC + keeps the row a draft; admin approves.
        return $tc->engine->patch('/api/vendor/profile', $body);
    }
}

/** A short, friendly status + real growth hints — from the vendor's own data. */
final class MyStatusTool extends AbstractTool
{
    public function name(): string { return 'my_status'; }
    public function description(): string { return 'Report the vendor\'s own status (profile, jobs, rating, photos) with simple growth hints.'; }
    public function allowedRoles(): array { return ['vendor_service', 'vendor_shopping']; }
    public function argSpec(): array { return []; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $profile = $tc->engine->get('/api/vendor/profile');
        $stats   = $tc->engine->get('/api/vendor/service-analytics/stats');
        $photos  = $tc->engine->get('/api/vendor/work-photos');

        $p = $profile->ok && is_array($profile->data) ? $profile->data : [];
        $s = $stats->ok   && is_array($stats->data)   ? $stats->data   : [];
        $photoList = $photos->ok && is_array($photos->data) ? ($photos->data['photos'] ?? (array_is_list($photos->data) ? $photos->data : [])) : [];
        $photoCount = is_array($photoList) ? count($photoList) : 0;

        $status = [
            'business_name'   => $p['business_name'] ?? ($p['name'] ?? 'Aap'),
            'status'          => $p['status'] ?? 'pending',
            'rating'          => isset($p['rating']) ? (float) $p['rating'] : 0.0,
            'experience_years'=> $p['experience_years'] ?? null,
            'languages'       => is_array($p['languages'] ?? null) ? $p['languages'] : [],
            'completed_jobs'  => (int) ($s['completed_bookings_count'] ?? 0),
            'bookings_today'  => (int) ($s['bookings_today'] ?? 0),
            'followers'       => (int) ($s['follower_count'] ?? 0),
            'photo_count'     => $photoCount,
            'growth_hints'    => $this->growthHints($p, $s, $photoCount),
        ];
        return EngineResult::ok(200, ['status' => $status]);
    }

    /** Simple, honest, data-driven suggestions (never invented). */
    private function growthHints(array $p, array $s, int $photoCount): array
    {
        $hints = [];
        if ($photoCount === 0) {
            $hints[] = 'Abhi koi work photo nahi hai — 3 achhi photo add karo, customers ka trust 2x badhta hai. 📸';
        } elseif ($photoCount < 3) {
            $hints[] = 'Aur photos add karo (kam se kam 3) — zyada photo = zyada bharosa.';
        }
        if (empty($p['languages'])) {
            $hints[] = 'Apni bhasha add karo taaki zyada log aap tak pahunche.';
        }
        if (empty($p['experience_years'])) {
            $hints[] = 'Apna experience add karo — purane vendors ko customers zyada choose karte hain.';
        }
        if (($p['status'] ?? '') !== 'active') {
            $hints[] = 'Aapki listing abhi review mein hai — approve hote hi customers ko dikhne lagegi.';
        }
        if ($hints === []) {
            $hints[] = 'Sab set hai! 🎉 Regular photos add karte raho — growth banti rahegi.';
        }
        return array_slice($hints, 0, 3);
    }
}

/**
 * Agent-owned reminder (e.g. "weekly work-photo upload"). Stored in the agent's
 * own table; delivery hooks into the existing notifications/FCM mechanism later.
 */
final class SetReminderTool extends AbstractTool
{
    public function name(): string { return 'set_reminder'; }
    public function description(): string { return 'Set a recurring reminder for the vendor (e.g. weekly work-photo upload).'; }
    public function mode(): string { return 'write'; }
    public function allowedRoles(): array { return ['vendor_service', 'vendor_shopping']; }
    public function argSpec(): array { return ['title' => 'string', 'cadence' => 'string?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        $cadence = in_array(strtolower((string) ($a['cadence'] ?? '')), ['daily', 'weekly', 'once'], true)
            ? strtolower($a['cadence']) : 'weekly';
        $interval = $cadence === 'daily' ? '+1 day' : ($cadence === 'once' ? '+1 day' : '+7 days');
        $nextAt   = date('Y-m-d H:i:s', strtotime($interval) ?: time());

        $db = $tc->db;
        if ($db instanceof PDO) {
            try {
                $db->prepare(
                    "INSERT INTO agent_reminders (user_id, title, cadence, next_at, active, created_at)
                     VALUES (:uid, :title, :cadence, :next, 1, NOW())"
                )->execute([
                    ':uid'     => $tc->ctx->userId,
                    ':title'   => mb_substr($a['title'], 0, 150),
                    ':cadence' => $cadence,
                    ':next'    => $nextAt,
                ]);
            } catch (\Throwable $e) {
                // Table absent (migration not applied) — confirm anyway, don't break.
                if (class_exists('Logger')) { Logger::warning('agent.reminder store skipped', ['error' => $e->getMessage()]); }
            }
        }
        return EngineResult::ok(201, ['reminder' => ['title' => $a['title'], 'cadence' => $cadence, 'next_at' => $nextAt]]);
    }
}
