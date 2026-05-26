<?php
/**
 * WorkToGo — Services & Bookings Module
 *
 * GET    /api/services                → list active services
 * GET    /api/services/{id}           → service detail
 * POST   /api/service/request         → create booking (+ auto-creates job)
 * GET    /api/service/bookings        → list bookings (scoped by role)
 * GET    /api/service/bookings/{id}   → booking detail with linked job
 * PATCH  /api/service/bookings/{id}/assign → admin assign/reassign vendor
 * PATCH  /api/jobs/{id}/status        → update job status (vendor/admin)
 */

// ── Centralized Boot ──────────────────────────────────────────
// Incorrect path: dirname(dirname(dirname(__DIR__))) . '/core/...' -> body/core/...
// Corrected path: dirname(dirname(dirname(dirname(__DIR__)))) . '/core/...' -> /core/...
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/Database.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/Response.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/JWT.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/ServiceVendorEligibility.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/heart/middleware/AuthMiddleware.php';

$db = getDB();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Handle internal Heart calls
if (defined('HEART_INTERNAL_INC')) {
    $input = json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true);
}

/**
 * Resolve the vendors.id for the authenticated user.
 * Terminates with 403 if no vendor profile exists.
 */
function resolveVendorId(PDO $db, int $userId): int
{
    $stmt = $db->prepare(
        "SELECT id FROM vendors WHERE user_id = ? AND deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        Response::forbidden('No vendor profile found for your account');
    }
    return (int)$row['id'];
}

function serviceTableHasColumn(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    return $cache[$key];
}

function servicePublicSetting(PDO $db, string $key, mixed $fallback): mixed
{
    try {
        $stmt = $db->prepare("SELECT setting_value, value_type FROM app_settings WHERE setting_key = ? AND is_public = 1 LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $fallback;
        return match ($row['value_type'] ?? 'text') {
            'number' => (float)$row['setting_value'],
            'boolean' => (bool)$row['setting_value'],
            'json' => json_decode((string)$row['setting_value'], true) ?: $fallback,
            default => (string)$row['setting_value'],
        };
    } catch (Throwable) {
        return $fallback;
    }
}

function servicePilotConfig(PDO $db): array
{
    $fallback = [
        'city' => 'Haldwani',
        'hero_title' => 'Book trusted local services in Haldwani',
        'hero_subtitle' => 'Browse first. Login is needed only when you send a booking request or track it.',
        'trust_badges' => ['Local providers', 'Pay after service', 'Manual confirmation'],
        'support_label' => 'Need help?',
        'support_phone' => '+91 95285 44548',
        'whatsapp_url' => 'https://wa.me/919528544548?text=Hi%20WorkToGo%2C%20I%20need%20help%20with%20a%20service%20booking.',
        'featured_services_label' => 'Services near you',
        'fallback_title' => 'Need another service?',
        'fallback_text' => 'Tell us on WhatsApp. We are manually coordinating pilot requests in Haldwani.',
        'manual_fallback_label' => 'Manual assistance',
    ];
    $stored = servicePublicSetting($db, 'pilot_public_config', []);
    return array_replace($fallback, is_array($stored) ? $stored : []);
}

function normalizeServiceJobStatus(string $status): string
{
    $status = strtolower(trim($status));
    $map = [
        'open' => 'pending',
        'pending' => 'pending',
        'assigned' => 'confirmed',
        'accepted' => 'confirmed',
        'confirmed' => 'confirmed',
        'started' => 'in_progress',
        'ongoing' => 'in_progress',
        'in_progress' => 'in_progress',
        'completed' => 'completed',
        'delivered' => 'completed',
        'rejected' => 'cancelled',
        'cancelled' => 'cancelled',
    ];
    return $map[$status] ?? $status;
}

function canonicalJobStatusForBooking(string $status): string
{
    return match (normalizeServiceJobStatus($status)) {
        'pending' => 'open',
        'confirmed' => 'assigned',
        'in_progress' => 'in_progress',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        default => 'open',
    };
}

function canonicalBookingStatusForJob(string $status): string
{
    return match (canonicalJobStatusForBooking($status)) {
        'open' => 'pending',
        'assigned' => 'confirmed',
        'in_progress' => 'in_progress',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        default => 'pending',
    };
}

function canonicalBookingMode(array $input, array $service): string
{
    $mode = strtolower(trim((string)($input['booking_mode'] ?? $input['lifecycle_type'] ?? '')));
    if (in_array($mode, ['inspection', 'premium_inspection'], true)) return 'inspection';
    if (in_array($mode, ['direct_vendor', 'vendor_direct'], true) && !empty($service['vendor_id'])) return 'direct_vendor';
    return 'free_lead';
}

function serviceLifecycleNote(array $input, string $bookingMode, array $service): string
{
    $notes = serviceFreeformOperationalNotes((string)($input['notes'] ?? ''));
    $operational = serviceNormalizeOperationalRequest($input, $bookingMode, $service);
    $lines = [
        'Request ID: ' . $operational['request_id'],
        'Client request ID: ' . $operational['client_request_id'],
        'Request schema version: ' . $operational['request_schema_version'],
        'Request type: ' . $operational['request_type'],
        'Category: ' . $operational['category_label'],
        'Issue list: ' . implode(', ', $operational['issue_list']),
        'Issue summary: ' . $operational['issue_summary'],
        'Locality: ' . $operational['locality'],
        'City: ' . $operational['city'],
        'Payment required: ' . ($operational['payment_required'] ? 'yes' : 'no'),
        'Payment route: ' . $operational['payment_route'],
        'Priority: ' . $operational['priority'],
        'Priority score: ' . $operational['priority_score'],
        'Assignment state: ' . $operational['assignment_state'],
        'Lifecycle state: ' . $operational['lifecycle_state'],
        'Timeline: ' . json_encode($operational['timeline'], JSON_UNESCAPED_SLASHES),
        'Assignment metadata: ' . json_encode($operational['assignment'], JSON_UNESCAPED_SLASHES),
        'Inspection metadata: ' . json_encode($operational['inspection'], JSON_UNESCAPED_SLASHES),
        'Escalation metadata: ' . json_encode($operational['escalation'], JSON_UNESCAPED_SLASHES),
        'Routing context: ' . json_encode($operational['routing_context'], JSON_UNESCAPED_SLASHES),
        'Sorting keys: ' . json_encode($operational['sorting_keys'], JSON_UNESCAPED_SLASHES),
        'Operational tags: ' . implode(', ', $operational['operational_tags']),
        'Booking mode: ' . $bookingMode,
        'Vendor route: ' . ($bookingMode === 'direct_vendor' ? ('direct:' . (int)($service['vendor_id'] ?? 0)) : 'admin_queue'),
        $notes,
    ];
    return trim(implode("\n", array_values(array_filter($lines, fn($line) => trim((string)$line) !== ''))));
}

function serviceFreeformOperationalNotes(string $notes): string
{
    $blocked = '/^(Request ID|Client request ID|Request schema version|Request type|Category|Issue list|Issue summary|Locality|City|Payment required|Payment route|Priority|Priority score|Assignment state|Lifecycle state|Timeline|Assignment metadata|Inspection metadata|Escalation metadata|Routing context|Sorting keys|Operational tags|Booking mode|Vendor route|Customer|Mobile|Address|Preferred time|Selected worker):/i';
    $lines = [];
    foreach (preg_split('/\R/', $notes) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || preg_match($blocked, $line)) continue;
        $lines[] = $line;
    }
    return implode("\n", array_slice($lines, 0, 4));
}

function serviceNormalizeIssueList($value): array
{
    $source = is_array($value) ? $value : preg_split('/[,|]/', (string)$value);
    $seen = [];
    $out = [];
    foreach ($source ?: [] as $item) {
        $clean = trim(preg_replace('/\s+/', ' ', (string)$item));
        $key = strtolower($clean);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $clean;
    }
    return $out;
}

function serviceSlug(string $value): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($value)) ?? '');
    return trim($slug, '_');
}

function serviceIssueSummary(array $issues): string
{
    $parts = [];
    foreach ($issues as $issue) {
        $clean = trim(preg_replace('/\b(issue|problem|service|work)\b$/i', '', $issue));
        if ($clean !== '') $parts[] = $clean;
        if (count($parts) >= 4) break;
    }
    return implode(' + ', serviceNormalizeIssueList($parts));
}

function serviceJsonField(?string $notes, string $field, array $fallback = []): array
{
    $raw = serviceNoteField($notes, $field);
    if ($raw === '') return $fallback;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function serviceLifecycleTransitions(string $bookingMode): array
{
    if ($bookingMode === 'inspection') {
        return [
            'payment_pending' => ['payment_verified'],
            'payment_verified' => ['inspection_queued'],
            'inspection_queued' => ['coordinator_review', 'inspection_assigned'],
            'coordinator_review' => ['inspection_assigned', 'inspection_queued'],
            'inspection_assigned' => ['service_in_progress', 'completed', 'cancelled'],
            'service_in_progress' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];
    }

    return [
        'request_received' => ['searching_worker', 'worker_assigned', 'cancelled'],
        'searching_worker' => ['worker_assigned', 'request_received', 'cancelled'],
        'worker_confirmation_pending' => ['worker_assigned', 'searching_worker', 'cancelled'],
        'worker_assigned' => ['service_in_progress', 'searching_worker', 'cancelled'],
        'service_in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];
}

function serviceCanTransitionLifecycle(string $bookingMode, string $from, string $to): bool
{
    if ($from === $to) return true;
    $transitions = serviceLifecycleTransitions($bookingMode);
    return in_array($to, $transitions[$from] ?? [], true);
}

function serviceNormalizeTimeline(array $timeline, string $createdAt, string $state): array
{
    $entries = [];
    foreach ($timeline as $entry) {
        if (!is_array($entry)) continue;
        $event = serviceSlug((string)($entry['event'] ?? 'update')) ?: 'update';
        $entryState = serviceSlug((string)($entry['state'] ?? $state)) ?: $state;
        $at = trim((string)($entry['at'] ?? $createdAt));
        $actor = serviceSlug((string)($entry['actor'] ?? 'system')) ?: 'system';
        $entries[] = [
            'event' => $event,
            'state' => $entryState,
            'at' => $at ?: $createdAt,
            'actor' => $actor,
            'source' => serviceSlug((string)($entry['source'] ?? $actor)) ?: $actor,
            'visible_to_customer' => (bool)($entry['visible_to_customer'] ?? false),
            'notes' => trim((string)($entry['notes'] ?? '')),
        ];
    }
    if (!$entries) {
        $entries[] = ['event' => 'request_created', 'state' => $state, 'at' => $createdAt, 'actor' => 'customer', 'source' => 'customer', 'visible_to_customer' => true, 'notes' => ''];
    }
    return array_slice($entries, 0, 80);
}

function serviceTimelineSnapshot(array $timeline): array
{
    $latest = $timeline ? $timeline[count($timeline) - 1] : [];
    return [
        'latest_event' => (string)($latest['event'] ?? 'request_created'),
        'latest_actor' => (string)($latest['actor'] ?? 'system'),
        'latest_state' => (string)($latest['state'] ?? 'request_received'),
        'latest_at' => (string)($latest['at'] ?? ''),
    ];
}

function serviceDefaultAssignmentMetadata(array $provided = []): array
{
    return [
        'assigned_vendor_id' => isset($provided['assigned_vendor_id']) && $provided['assigned_vendor_id'] !== '' ? (int)$provided['assigned_vendor_id'] : null,
        'assigned_vendor_name' => trim((string)($provided['assigned_vendor_name'] ?? '')) ?: null,
        'assigned_by' => trim((string)($provided['assigned_by'] ?? '')) ?: null,
        'assigned_at' => trim((string)($provided['assigned_at'] ?? '')) ?: null,
        'assignment_notes' => trim((string)($provided['assignment_notes'] ?? '')) ?: null,
    ];
}

function serviceDefaultInspectionMetadata(string $bookingMode, array $provided = []): array
{
    return [
        'inspection_required' => (bool)($provided['inspection_required'] ?? ($bookingMode === 'inspection')),
        'inspection_status' => serviceSlug((string)($provided['inspection_status'] ?? ($bookingMode === 'inspection' ? 'payment_pending' : 'not_required'))),
        'inspection_assigned_to' => trim((string)($provided['inspection_assigned_to'] ?? '')) ?: null,
        'inspection_scheduled_at' => trim((string)($provided['inspection_scheduled_at'] ?? '')) ?: null,
        'inspection_notes' => trim((string)($provided['inspection_notes'] ?? '')) ?: null,
    ];
}

function serviceDefaultEscalationMetadata(array $provided = []): array
{
    return [
        'escalation_required' => (bool)($provided['escalation_required'] ?? false),
        'escalation_reason' => trim((string)($provided['escalation_reason'] ?? '')) ?: null,
        'escalation_level' => serviceSlug((string)($provided['escalation_level'] ?? 'none')) ?: 'none',
        'escalated_at' => trim((string)($provided['escalated_at'] ?? '')) ?: null,
    ];
}

function serviceAppendNoteField(?string $notes, string $field, string $value): string
{
    $prefix = strtolower($field . ':');
    $lines = [];
    foreach (preg_split('/\R/', (string)$notes) ?: [] as $line) {
        if (str_starts_with(strtolower(trim($line)), $prefix)) continue;
        $lines[] = rtrim($line);
    }
    $lines[] = $field . ': ' . $value;
    return trim(implode("\n", array_filter($lines, fn($line) => trim((string)$line) !== '')));
}

function serviceBuildAdminRequestView(array $booking): array
{
    $timeline = $booking['timeline'] ?? [];
    $snapshot = serviceTimelineSnapshot(is_array($timeline) ? $timeline : []);
    return [
        'request_summary' => [
            'request_id' => $booking['request_id'] ?? null,
            'created_at' => $booking['created_at'] ?? null,
            'request_type' => $booking['request_type'] ?? null,
            'service_name' => $booking['service_name'] ?? null,
            'booking_number' => $booking['booking_number'] ?? null,
        ],
        'customer_summary' => [
            'name' => $booking['customer_name'] ?? $booking['user_name'] ?? null,
            'mobile' => $booking['customer_mobile'] ?? $booking['customer_phone'] ?? null,
        ],
        'location' => [
            'city' => $booking['city'] ?? null,
            'locality' => $booking['locality'] ?? null,
            'full_address' => $booking['customer_address'] ?? null,
        ],
        'issue_summary' => [
            'category' => serviceSlug((string)($booking['category_label'] ?? 'service')),
            'label' => $booking['category_label'] ?? null,
            'summary' => $booking['issue_summary'] ?? null,
            'issues' => $booking['issue_list'] ?? [],
        ],
        'payment_type' => $booking['payment_route'] ?? null,
        'priority' => $booking['priority'] ?? null,
        'lifecycle_state' => $booking['lifecycle_state'] ?? null,
        'assignment_state' => $booking['assignment_state'] ?? null,
        'inspection_status' => $booking['inspection']['inspection_status'] ?? null,
        'timeline_snapshot' => $snapshot,
        'filter_keys' => $booking['sorting_keys'] ?? [],
    ];
}

function serviceOperationalActionConfig(string $action, string $bookingMode): array
{
    $map = [
        'assign_vendor' => ['lifecycle' => $bookingMode === 'inspection' ? 'inspection_assigned' : 'worker_assigned', 'assignment' => $bookingMode === 'inspection' ? 'inspection_assigned' : 'worker_assigned', 'event' => 'vendor_assigned'],
        'unassign_vendor' => ['lifecycle' => $bookingMode === 'inspection' ? 'inspection_queued' : 'searching_worker', 'assignment' => $bookingMode === 'inspection' ? 'inspection_unassigned' : 'unassigned_searching', 'event' => 'vendor_unassigned'],
        'reassign_vendor' => ['lifecycle' => $bookingMode === 'inspection' ? 'inspection_assigned' : 'worker_assigned', 'assignment' => $bookingMode === 'inspection' ? 'inspection_assigned' : 'worker_assigned', 'event' => 'vendor_reassigned'],
        'mark_worker_contacted' => ['lifecycle' => $bookingMode === 'inspection' ? 'coordinator_review' : 'searching_worker', 'assignment' => 'worker_contacted', 'event' => 'worker_contacted'],
        'mark_worker_confirmed' => ['lifecycle' => $bookingMode === 'inspection' ? 'inspection_assigned' : 'worker_assigned', 'assignment' => $bookingMode === 'inspection' ? 'inspection_assigned' : 'worker_assigned', 'event' => 'worker_confirmed'],
        'inspection_queued' => ['lifecycle' => 'inspection_queued', 'assignment' => 'inspection_unassigned', 'inspection' => 'inspection_queued', 'event' => 'inspection_queued'],
        'coordinator_assigned' => ['lifecycle' => 'coordinator_review', 'assignment' => 'coordinator_review', 'inspection' => 'coordinator_assigned', 'event' => 'coordinator_assigned'],
        'inspection_scheduled' => ['lifecycle' => 'inspection_assigned', 'assignment' => 'inspection_assigned', 'inspection' => 'inspection_scheduled', 'event' => 'inspection_scheduled'],
        'inspection_completed' => ['lifecycle' => 'completed', 'assignment' => 'inspection_assigned', 'inspection' => 'inspection_completed', 'event' => 'inspection_completed'],
        'mark_escalated' => ['event' => 'request_escalated'],
    ];
    return $map[$action] ?? [];
}

function serviceInitialLifecycleState(string $bookingMode, array $input): string
{
    $state = serviceSlug((string)($input['lifecycle_state'] ?? $input['operational_tracking_state'] ?? ''));
    $allowed = $bookingMode === 'inspection'
        ? array_keys(serviceLifecycleTransitions('inspection'))
        : array_keys(serviceLifecycleTransitions('free_lead'));
    if (in_array($state, $allowed, true)) return $state;
    return $bookingMode === 'inspection' ? 'payment_pending' : 'request_received';
}

function serviceInitialAssignmentState(string $bookingMode, array $input): string
{
    $state = serviceSlug((string)($input['assignment_state'] ?? ''));
    $allowed = $bookingMode === 'inspection'
        ? ['payment_pending', 'inspection_unassigned', 'coordinator_review', 'inspection_assigned']
        : ['unassigned_searching', 'worker_confirmation_pending', 'worker_assigned'];
    if (in_array($state, $allowed, true)) return $state;
    return $bookingMode === 'inspection' ? 'payment_pending' : 'unassigned_searching';
}

function serviceNormalizeOperationalRequest(array $input, string $bookingMode, array $service): array
{
    $provided = is_array($input['operational_request'] ?? null) ? $input['operational_request'] : [];
    $requestId = trim((string)($input['request_id'] ?? $provided['request_id'] ?? $input['client_request_id'] ?? $provided['client_request_id'] ?? ''));
    if ($requestId === '') $requestId = 'wtg-' . serviceSlug($bookingMode ?: 'request') . '-' . bin2hex(random_bytes(6));
    $issues = serviceNormalizeIssueList($input['issue_list'] ?? $provided['issue_list'] ?? $input['issue_type'] ?? $input['subservice'] ?? $service['name'] ?? 'Service request');
    if (!$issues) $issues = [trim((string)($service['name'] ?? 'Service request'))];
    $category = trim((string)($input['category_slug'] ?? $provided['category'] ?? $service['category_slug'] ?? 'service'));
    $categoryLabel = trim((string)($input['category_label'] ?? $provided['category_label'] ?? $service['category_name'] ?? $category));
    $locality = trim((string)($input['customer_locality'] ?? $provided['locality'] ?? $input['selected_nearby_area'] ?? 'Local area'));
    $city = trim((string)($input['selected_city'] ?? $provided['city'] ?? 'Local city'));
    $priority = trim((string)($input['priority'] ?? $provided['priority'] ?? ($bookingMode === 'inspection' ? 'high_priority' : 'normal_priority')));
    $priorityScore = (int)($input['priority_score'] ?? $provided['priority_score'] ?? ($bookingMode === 'inspection' ? 35 : 15));
    $lifecycleState = serviceInitialLifecycleState($bookingMode, $input + $provided);
    $assignmentState = serviceInitialAssignmentState($bookingMode, $input + $provided);
    $paymentRequired = $bookingMode === 'inspection';
    $createdAt = trim((string)($provided['created_at'] ?? $input['created_at'] ?? gmdate('c')));
    $timeline = serviceNormalizeTimeline(is_array($provided['timeline'] ?? null) ? $provided['timeline'] : (is_array($input['timeline'] ?? null) ? $input['timeline'] : []), $createdAt, $lifecycleState);
    $tags = serviceNormalizeIssueList($input['operational_tags'] ?? $provided['operational_tags'] ?? []);
    $baseTags = [$paymentRequired ? 'paid' : 'free', serviceSlug($category), 'local_request'];
    if ($paymentRequired) $baseTags[] = 'inspection';
    if ($priorityScore >= 60 || $priority === 'high_priority') $baseTags[] = 'urgent';
    $tags = array_values(array_unique(array_filter(array_map('serviceSlug', array_merge($baseTags, $tags)))));
    $assignmentMetadata = serviceDefaultAssignmentMetadata(is_array($provided['assignment'] ?? null) ? $provided['assignment'] : []);
    $inspectionMetadata = serviceDefaultInspectionMetadata($bookingMode, is_array($provided['inspection'] ?? null) ? $provided['inspection'] : []);
    $escalationMetadata = serviceDefaultEscalationMetadata(is_array($provided['escalation'] ?? null) ? $provided['escalation'] : []);
    $sortingKeys = ['city' => serviceSlug($city), 'locality' => serviceSlug($locality), 'category' => serviceSlug($category), 'payment_route' => $paymentRequired ? 'paid_inspection' : 'free_request', 'payment_required' => $paymentRequired ? 'yes' : 'no', 'priority' => $priority, 'assignment_state' => $assignmentState, 'lifecycle_state' => $lifecycleState, 'inspection_status' => $inspectionMetadata['inspection_status']];
    $normalized = [
        'request_id' => $requestId,
        'client_request_id' => trim((string)($input['client_request_id'] ?? $provided['client_request_id'] ?? $requestId)),
        'created_at' => $createdAt,
        'request_schema_version' => 1,
        'request_type' => trim((string)($input['request_type'] ?? $provided['request_type'] ?? ($bookingMode === 'inspection' ? 'inspection' : 'free_match'))),
        'lifecycle_state' => $lifecycleState,
        'assignment_state' => $assignmentState,
        'customer_name' => trim((string)($input['customer_name'] ?? $provided['customer_name'] ?? $provided['customer']['name'] ?? 'WorkToGo Customer')),
        'customer_mobile' => preg_replace('/\D+/', '', (string)($input['customer_mobile'] ?? $provided['customer_mobile'] ?? $provided['customer']['phone'] ?? '')),
        'category' => $category,
        'category_label' => $categoryLabel,
        'issue_list' => $issues,
        'issue_summary' => serviceIssueSummary($issues),
        'city' => $city,
        'locality' => $locality,
        'full_address' => trim((string)($input['customer_address'] ?? $provided['full_address'] ?? '')),
        'payment_required' => $paymentRequired,
        'payment_route' => $paymentRequired ? 'paid_inspection' : 'free_request',
        'priority' => $priority,
        'priority_score' => max(0, min(100, $priorityScore)),
        'assignment' => $assignmentMetadata,
        'inspection' => $inspectionMetadata,
        'escalation' => $escalationMetadata,
        'routing_context' => is_array($provided['routing_context'] ?? null) ? $provided['routing_context'] : ['assignment_mode' => 'admin_queue', 'category_slug' => serviceSlug($category), 'city' => serviceSlug($city), 'locality' => serviceSlug($locality), 'route_ready' => true, 'vendor_acceptance_ready' => true, 'vendor_rejection_ready' => true, 'reassignment_ready' => true],
        'sorting_keys' => is_array($provided['sorting_keys'] ?? null) ? array_replace($sortingKeys, $provided['sorting_keys']) : $sortingKeys,
        'timeline' => $timeline,
        'operational_tags' => $tags,
    ];
    foreach (['request_id', 'client_request_id', 'created_at', 'request_type', 'lifecycle_state', 'assignment_state', 'customer_name', 'customer_mobile', 'category', 'category_label', 'issue_summary', 'city', 'locality', 'full_address', 'payment_route', 'priority'] as $key) {
        if (trim((string)($normalized[$key] ?? '')) === '') Response::validation('Malformed operational request: missing ' . $key);
    }
    return $normalized;
}

function servicePaymentStatusForMode(string $bookingMode, string $paymentMethod): string
{
    return 'unpaid';
}

function serviceJobPriorityForMode(string $bookingMode, array $input): string
{
    if ($bookingMode === 'inspection') return 'high';
    if (!empty($input['demand_priority'])) return 'demand';
    return 'normal';
}

function serviceModeFromNotes(?string $notes): string
{
    $notes = (string)$notes;
    if (str_contains($notes, 'Booking mode: inspection') || str_contains($notes, 'Lifecycle mode: inspection')) return 'inspection';
    if (str_contains($notes, 'Booking mode: direct_vendor') || str_contains($notes, 'Lifecycle mode: direct_vendor')) return 'direct_vendor';
    return 'free_lead';
}

function serviceNoteField(?string $notes, string $field): string
{
    $prefix = strtolower($field . ':');
    foreach (preg_split('/\R/', (string)$notes) ?: [] as $line) {
        $line = trim($line);
        if (str_starts_with(strtolower($line), $prefix)) return trim(substr($line, strlen($field) + 1));
    }
    return '';
}

function serviceAttachOperationalView(array $booking, bool $adminView = false): array
{
    $mode = $booking['booking_mode'] ?: serviceModeFromNotes($booking['notes'] ?? null);
    $paymentState = strtolower((string)($booking['payment_status'] ?? ''));
    $lifecycle = serviceNoteField($booking['notes'] ?? null, 'Lifecycle state') ?: ($mode === 'inspection' ? ($paymentState === 'paid' ? 'inspection_queued' : 'payment_pending') : 'request_received');
    $assignment = serviceNoteField($booking['notes'] ?? null, 'Assignment state') ?: ($mode === 'inspection' ? 'payment_pending' : 'unassigned_searching');
    $booking['request_id'] = serviceNoteField($booking['notes'] ?? null, 'Request ID') ?: ($booking['booking_number'] ?? ('booking-' . ($booking['id'] ?? '')));
    $booking['client_request_id'] = serviceNoteField($booking['notes'] ?? null, 'Client request ID') ?: $booking['request_id'];
    $booking['request_schema_version'] = (int)(serviceNoteField($booking['notes'] ?? null, 'Request schema version') ?: 1);
    $booking['request_type'] = serviceNoteField($booking['notes'] ?? null, 'Request type') ?: ($mode === 'inspection' ? 'inspection' : 'free_match');
    $booking['category_label'] = serviceNoteField($booking['notes'] ?? null, 'Category') ?: ($booking['category_label'] ?? $booking['category_name'] ?? $booking['service_name'] ?? 'Service');
    $booking['issue_list'] = serviceNormalizeIssueList(serviceNoteField($booking['notes'] ?? null, 'Issue list') ?: ($booking['subservice'] ?? $booking['service_name'] ?? 'Service request'));
    $booking['issue_summary'] = serviceNoteField($booking['notes'] ?? null, 'Issue summary') ?: serviceIssueSummary($booking['issue_list']);
    $booking['city'] = serviceNoteField($booking['notes'] ?? null, 'City') ?: ($booking['selected_city'] ?? null);
    $booking['locality'] = serviceNoteField($booking['notes'] ?? null, 'Locality') ?: ($booking['customer_locality'] ?? null);
    $booking['lifecycle_state'] = $lifecycle;
    $booking['assignment_state'] = $assignment;
    $booking['operational_tracking_state'] = $lifecycle;
    if ($adminView) {
        $booking['priority'] = serviceNoteField($booking['notes'] ?? null, 'Priority') ?: 'normal_priority';
        $booking['priority_score'] = (int)(serviceNoteField($booking['notes'] ?? null, 'Priority score') ?: 0);
        $booking['payment_route'] = serviceNoteField($booking['notes'] ?? null, 'Payment route') ?: ($mode === 'inspection' ? 'paid_inspection' : 'free_request');
        $booking['operational_tags'] = serviceNormalizeIssueList(serviceNoteField($booking['notes'] ?? null, 'Operational tags'));
        $booking['assignment'] = serviceDefaultAssignmentMetadata(serviceJsonField($booking['notes'] ?? null, 'Assignment metadata'));
        if (!empty($booking['vendor_id']) && empty($booking['assignment']['assigned_vendor_id'])) {
            $booking['assignment']['assigned_vendor_id'] = (int)$booking['vendor_id'];
            $booking['assignment']['assigned_vendor_name'] = $booking['vendor_name'] ?? null;
        }
        $booking['inspection'] = serviceDefaultInspectionMetadata($mode, serviceJsonField($booking['notes'] ?? null, 'Inspection metadata'));
        $booking['escalation'] = serviceDefaultEscalationMetadata(serviceJsonField($booking['notes'] ?? null, 'Escalation metadata'));
        $booking['routing_context'] = serviceJsonField($booking['notes'] ?? null, 'Routing context', ['assignment_mode' => $booking['vendor_route'] ?? 'admin_queue', 'route_ready' => true, 'vendor_acceptance_ready' => true, 'vendor_rejection_ready' => true, 'reassignment_ready' => true]);
        $booking['sorting_keys'] = serviceJsonField($booking['notes'] ?? null, 'Sorting keys', ['city' => serviceSlug((string)($booking['city'] ?? '')), 'locality' => serviceSlug((string)($booking['locality'] ?? '')), 'category' => serviceSlug((string)($booking['category_label'] ?? 'service')), 'payment_route' => $booking['payment_route'], 'priority' => $booking['priority'], 'assignment_state' => $assignment, 'lifecycle_state' => $lifecycle, 'inspection_status' => $booking['inspection']['inspection_status']]);
        $booking['timeline'] = serviceNormalizeTimeline(serviceJsonField($booking['notes'] ?? null, 'Timeline'), (string)($booking['created_at'] ?? gmdate('c')), $lifecycle);
        $booking['timeline_snapshot'] = serviceTimelineSnapshot($booking['timeline']);
        $booking['admin_queue'] = [
            'request_id' => $booking['request_id'],
            'created_at' => $booking['created_at'] ?? null,
            'request_type' => $booking['request_type'],
            'lifecycle_state' => $booking['lifecycle_state'],
            'assignment_state' => $booking['assignment_state'],
            'customer_name' => $booking['customer_name'] ?? $booking['user_name'] ?? null,
            'customer_mobile' => $booking['customer_mobile'] ?? $booking['customer_phone'] ?? null,
            'category' => serviceSlug((string)($booking['category_label'] ?? 'service')),
            'issue_summary' => $booking['issue_summary'],
            'issue_list' => $booking['issue_list'],
            'city' => $booking['city'],
            'locality' => $booking['locality'],
            'full_address' => $booking['customer_address'] ?? null,
            'priority' => $booking['priority'],
            'operational_tags' => $booking['operational_tags'],
            'payment_route' => $booking['payment_route'],
            'payment_required' => $booking['payment_route'] === 'paid_inspection',
            'timeline_snapshot' => $booking['timeline_snapshot'],
        ];
        $booking['admin_request_view'] = serviceBuildAdminRequestView($booking);
    } else {
        unset($booking['operational_tags'], $booking['priority'], $booking['priority_score'], $booking['routing_context'], $booking['sorting_keys'], $booking['assignment'], $booking['inspection'], $booking['escalation'], $booking['timeline_snapshot'], $booking['admin_queue'], $booking['admin_request_view']);
    }
    return $booking;
}

function serviceBookingColumnSql(PDO $db, string $column, string $expr): string
{
    return serviceTableHasColumn($db, 'bookings', $column) ? $expr : "NULL AS {$column}";
}

function serviceJobColumnSql(PDO $db, string $column, string $expr): string
{
    return serviceTableHasColumn($db, 'jobs', $column) ? $expr : "NULL AS {$column}";
}

function serviceBookingEligibility(PDO $db, array $booking): array
{
    $typeColumn = ServiceVendorEligibility::vendorTypeColumn($db);
    $onlineOrder = serviceTableHasColumn($db, 'vendors', 'is_online') ? 'v.is_online DESC, ' : '';
    $stmt = $db->prepare(
        "SELECT " . ServiceVendorEligibility::buildVendorSelect($db, 'v') . "
         FROM vendors v
         WHERE v.{$typeColumn} = 'service'
         ORDER BY v.status = 'active' DESC, {$onlineOrder}v.business_name ASC"
    );
    $stmt->execute();
    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eligibleCount = 0;
    $items = [];
    foreach ($vendors as $vendor) {
        $availability = ServiceVendorEligibility::availabilityFor($db, (int)$vendor['id'], $booking['scheduled_at'] ?? null, (int)($booking['id'] ?? 0));
        $eligibility = ServiceVendorEligibility::evaluate($vendor, $booking, $availability);
        if ($eligibility['assignable']) $eligibleCount++;
        $items[] = [
            'vendor_id' => (int)$vendor['id'],
            'vendor_name' => $vendor['business_name'],
            'service_localities' => $vendor['service_localities'] ?? null,
            'service_area_notes' => $vendor['service_area_notes'] ?? null,
            'eligibility' => $eligibility,
        ];
    }

    return [
        'booking_locality' => $booking['customer_locality'] ?? null,
        'scheduled_at' => $booking['scheduled_at'] ?? null,
        'eligible_count' => $eligibleCount,
        'vendors' => $items,
    ];
}

// ── GET /api/services ──────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/services') {
    header('Cache-Control: no-store, max-age=0');
    $category = $_GET['category'] ?? null;

    $orderParts = [];
    if (serviceTableHasColumn($db, 'services', 'is_featured')) $orderParts[] = 's.is_featured DESC';
    if (serviceTableHasColumn($db, 'services', 'rating')) $orderParts[] = 's.rating DESC';
    $orderParts[] = 's.name ASC';

    $categorySelect = serviceTableHasColumn($db, 'categories', 'icon') ? ', c.icon AS category_icon' : ", NULL AS category_icon";
    $categorySelect .= serviceTableHasColumn($db, 'categories', 'image_url') ? ', c.image_url AS category_image' : ", NULL AS category_image";

    $sql  = "SELECT s.*, v.business_name AS vendor_name, c.name AS category_name, c.slug AS category_slug {$categorySelect}
             FROM services s
             LEFT JOIN vendors v ON v.id = s.vendor_id
             LEFT JOIN categories c ON c.id = s.category_id
             WHERE s.status = 'active' AND s.deleted_at IS NULL";
    $bind = [];

    if ($category) {
        $sql .= " AND c.slug = :cat";
        $bind[':cat'] = $category;
    }

    $sql .= " ORDER BY " . implode(', ', $orderParts);

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = [];
    foreach ($services as $service) {
        $slug = $service['category_slug'] ?? '';
        if ($slug && !isset($categories[$slug])) {
            $categories[$slug] = [
                'slug' => $slug,
                'name' => $service['category_name'] ?? $slug,
                'icon' => $service['category_icon'] ?: '🔧',
                'image' => $service['category_image'] ?? null,
            ];
        }
    }

    Response::success([
        'services' => $services,
        'categories' => array_values($categories),
        'pilot_config' => servicePilotConfig($db),
        'total' => count($services)
    ]);
}

// ── GET /api/service/categories ───────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/service/categories') {
    header('Cache-Control: no-store, max-age=0');
    $iconSelect = serviceTableHasColumn($db, 'categories', 'icon') ? 'icon' : "NULL AS icon";
    $imageSelect = serviceTableHasColumn($db, 'categories', 'image_url') ? 'image_url' : "NULL AS image_url";
    $sortSelect = serviceTableHasColumn($db, 'categories', 'sort_order') ? 'sort_order' : "0 AS sort_order";
    $stmt = $db->query("SELECT id, name, slug, status, {$iconSelect}, {$imageSelect}, {$sortSelect} FROM categories WHERE type IN ('service','services') OR type IS NULL ORDER BY sort_order ASC, name ASC");
    Response::success(['categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── POST /api/services ─────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/services') {
    $auth  = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE);
    $input = defined('HEART_INTERNAL_INC') 
        ? json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true) 
        : (json_decode(file_get_contents('php://input'), true) ?? []);

    $vendorId = resolveVendorId($db, (int)$auth['user_id']);

    $name            = trim($input['name'] ?? '');
    $basePrice       = isset($input['base_price']) ? (float)$input['base_price'] : 0;
    $categoryId      = isset($input['category_id']) ? (int)$input['category_id'] : 0;
    $durationMinutes = isset($input['duration_minutes']) ? (int)$input['duration_minutes'] : 0;
    $description     = trim($input['description'] ?? '');

    if (!$name || !$basePrice || !$categoryId || !$durationMinutes) {
        Response::validation('name, base_price, category_id, and duration_minutes are required');
    }

    $stmt = $db->prepare(
        "INSERT INTO services (vendor_id, category_id, name, description, base_price, duration_minutes, status, created_at, updated_at)
         VALUES (:vid, :cid, :name, :desc, :price, :duration, 'active', NOW(), NOW())"
    );
    
    $stmt->execute([
        ':vid'      => $vendorId,
        ':cid'      => $categoryId,
        ':name'     => $name,
        ':desc'     => $description ?: null,
        ':price'    => $basePrice,
        ':duration' => $durationMinutes
    ]);

    $serviceId = (int)$db->lastInsertId();

    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    Response::success(['service' => $service], 201);
}

// ── GET /api/services/{id} ────────────────────────────────────────────────────
if ($method === 'GET' && preg_match('#^/api/services/(\d+)$#', $uri, $m)) {
    $stmt = $db->prepare(
        "SELECT s.*, v.business_name AS vendor_name, v.logo_url AS vendor_logo,
                c.name AS category_name
         FROM services s
         LEFT JOIN vendors v ON v.id = s.vendor_id
         LEFT JOIN categories c ON c.id = s.category_id
         WHERE s.id = ? AND s.status = 'active' AND s.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([(int)$m[1]]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) Response::notFound('Service');

    Response::success(['service' => $service]);
}

// ── PUT /api/services/{id} ────────────────────────────────────────────────────
if ($method === 'PUT' && preg_match('#^/api/services/(\d+)$#', $uri, $m)) {
    $auth  = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE);
    $input = defined('HEART_INTERNAL_INC') 
        ? json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true) 
        : (json_decode(file_get_contents('php://input'), true) ?? []);

    $vendorId = resolveVendorId($db, (int)$auth['user_id']);
    $serviceId = (int)$m[1];

    $stmt = $db->prepare("SELECT id FROM services WHERE id = ? AND vendor_id = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$serviceId, $vendorId]);
    if (!$stmt->fetch()) {
        Response::notFound('Service not found or you do not have permission to edit it');
    }

    $updates = [];
    $bind = [':id' => $serviceId];

    if (isset($input['name'])) {
        $updates[] = 'name = :name';
        $bind[':name'] = trim($input['name']);
    }
    if (isset($input['base_price'])) {
        $updates[] = 'base_price = :price';
        $bind[':price'] = (float)$input['base_price'];
    }
    if (isset($input['category_id'])) {
        $updates[] = 'category_id = :cid';
        $bind[':cid'] = (int)$input['category_id'];
    }
    if (isset($input['duration_minutes'])) {
        $updates[] = 'duration_minutes = :duration';
        $bind[':duration'] = (int)$input['duration_minutes'];
    }
    if (isset($input['description'])) {
        $updates[] = 'description = :desc';
        $bind[':desc'] = trim($input['description']) ?: null;
    }

    if (empty($updates)) {
        Response::validation('No valid fields provided for update');
    }

    $updates[] = 'updated_at = NOW()';

    $db->prepare("UPDATE services SET " . implode(', ', $updates) . " WHERE id = :id")->execute($bind);

    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    Response::success(['service' => $service]);
}

// ── DELETE /api/services/{id} ─────────────────────────────────────────────────
if ($method === 'DELETE' && preg_match('#^/api/services/(\d+)$#', $uri, $m)) {
    $auth  = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE);
    $vendorId = resolveVendorId($db, (int)$auth['user_id']);
    $serviceId = (int)$m[1];

    $stmt = $db->prepare("SELECT id FROM services WHERE id = ? AND vendor_id = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$serviceId, $vendorId]);
    if (!$stmt->fetch()) {
        Response::notFound('Service not found or you do not have permission to delete it');
    }

    $db->prepare("UPDATE services SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$serviceId]);

    Response::success(['message' => 'Service deleted successfully']);
}

// ── POST /api/service/request (create booking + auto-create job) ──────────────
if ($method === 'POST' && $uri === '/api/service/request') {
    $auth  = AuthMiddleware::require();
    if (defined('HEART_INTERNAL_INC')) {
        $decoded = json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true) ?: [];
        $input = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
    } else {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    $serviceId   = (int)($input['service_id']   ?? 0);
    $scheduledAt = trim($input['scheduled_at']   ?? '');
    $notes       = trim($input['notes']          ?? '');
    $addressId   = isset($input['address_id']) ? (int)$input['address_id'] : null;

    // Required field validation
    if (!$serviceId || !$scheduledAt) {
        Response::validation('service_id and scheduled_at are required');
    }

    // Validate scheduled_at is a parseable future datetime
    $scheduledTs = strtotime($scheduledAt);
    if (!$scheduledTs || $scheduledTs <= time()) {
        Response::validation('scheduled_at must be a valid future datetime (e.g. 2026-05-01 14:00:00)');
    }
    $scheduledAt = date('Y-m-d H:i:s', $scheduledTs);

    // Validate address belongs to the authenticated user (if provided)
    if ($addressId) {
        $addrStmt = $db->prepare(
            "SELECT id FROM addresses WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $addrStmt->execute([$addressId, (int)$auth['user_id']]);
        if (!$addrStmt->fetch()) {
            Response::validation('Invalid address_id or address does not belong to your account');
        }
    }

    // Fetch the active service
    $svcStmt = $db->prepare(
        "SELECT * FROM services WHERE id = ? AND status = 'active' LIMIT 1"
    );
    $svcStmt->execute([$serviceId]);
    $service = $svcStmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) Response::notFound('Service');

    $bookingMode = canonicalBookingMode($input, $service);
    $operationalRequest = serviceNormalizeOperationalRequest($input, $bookingMode, $service);
    $paymentMethod = strtolower(trim($input['payment_method'] ?? 'cod'));
    $paymentMethod = ($bookingMode === 'inspection' && $paymentMethod === 'online') ? 'online' : 'cod';
    $paymentStatus = servicePaymentStatusForMode($bookingMode, $paymentMethod);
    $canonicalNotes = serviceLifecycleNote($input, $bookingMode, $service);
    $bookingTotal = $bookingMode === 'inspection'
        ? (float)($input['expected_payment_amount'] ?? servicePublicSetting($db, 'inspection_price', $service['inspection_price'] ?? 299))
        : (float)$service['base_price'];
    $jobPriority = serviceJobPriorityForMode($bookingMode, $input);

    // Generate collision-resistant unique reference numbers
    $bookingNum = 'WTG-BKG-' . strtoupper(bin2hex(random_bytes(4)));
    $jobNum     = 'WTG-JOB-' . strtoupper(bin2hex(random_bytes(4)));

    try {
        $db->beginTransaction();

        $initialVendorId = !empty($service['vendor_id']) ? (int)$service['vendor_id'] : null;

        // Create booking
        $bStmt = $db->prepare(
            "INSERT INTO bookings
                (booking_number, user_id, vendor_id, service_id, status, payment_status, payment_method,
                 booking_mode, scheduled_at, duration_minutes, total, address_id, notes,
                 customer_name, customer_mobile, customer_locality, customer_address, vendor_route,
                 created_at)
             VALUES
                (:bnum, :uid, :vid, :sid, 'pending', :pstatus, :pmethod,
                 :booking_mode, :sched, :dur, :price, :addr, :notes,
                 :customer_name, :customer_mobile, :customer_locality, :customer_address, :vendor_route,
                 NOW())"
        );
        $bStmt->execute([
            ':bnum'  => $bookingNum,
            ':uid'   => (int)$auth['user_id'],
            ':vid'   => $initialVendorId,
            ':sid'   => $serviceId,
            ':pstatus' => $paymentStatus,
            ':pmethod' => $paymentMethod,
            ':booking_mode' => $bookingMode,
            ':sched' => $scheduledAt,
            ':dur'   => (int)($service['duration_minutes'] ?? 60),
            ':price' => $bookingTotal,
            ':addr'  => $addressId,
            ':notes' => $canonicalNotes ?: null,
            ':customer_name' => trim((string)($input['customer_name'] ?? '')) ?: null,
            ':customer_mobile' => trim((string)($input['customer_mobile'] ?? '')) ?: null,
            ':customer_locality' => trim((string)($input['customer_locality'] ?? '')) ?: null,
            ':customer_address' => trim((string)($input['customer_address'] ?? '')) ?: null,
            ':vendor_route' => $bookingMode === 'direct_vendor' ? 'direct_vendor' : 'admin_queue',
        ]);

        $bookingId = (int)$db->lastInsertId();

        // Online Payment logic
        $paymentData = null;
        if ($paymentMethod === 'online') {
            require_once SYSTEM_ROOT . '/core/helpers/Payment.php';
            try {
                $paymentData = Payment::createOrder('cashfree', $bookingTotal, $bookingNum, [
                    'return_url' => (defined('APP_URL') ? APP_URL : '') . '/app/#home?payment_return=inspection&booking_id=' . $bookingId,
                    'notify_url' => (defined('APP_URL') ? APP_URL : '') . '/api/payment/webhook',
                    'customer' => [
                        'id' => (int)$auth['user_id'],
                        'name' => trim((string)($input['customer_name'] ?? 'WorkToGo Customer')) ?: 'WorkToGo Customer',
                        'email' => 'support@worktogo.com',
                        'phone' => trim((string)($input['customer_mobile'] ?? '9999999999')) ?: '9999999999',
                    ],
                    'order_tags' => [
                        'internal_booking_id' => (string)$bookingId,
                        'reference_type' => 'booking',
                        'platform' => 'worktogo',
                    ],
                ]);
                if (empty($paymentData['success']) || empty($paymentData['payment_id'])) {
                    throw new RuntimeException($paymentData['message'] ?? 'Cashfree order was not created');
                }
                $db->prepare("UPDATE bookings SET payment_id = ?, payment_status = 'unpaid' WHERE id = ?")
                   ->execute([$paymentData['payment_id'] ?? null, $bookingId]);
                $paymentStatus = 'unpaid';
            } catch (Throwable $paymentError) {
                $paymentData = ['success' => false, 'message' => 'Payment session could not be created. Booking lifecycle is still saved.'];
                $db->prepare("UPDATE bookings SET payment_status = 'failed' WHERE id = ?")->execute([$bookingId]);
                $paymentStatus = 'failed';
            }
        }

        // Auto-create linked job so job_number constraint is always satisfied
        $jStmt = $db->prepare(
            "INSERT INTO jobs
                (job_number, booking_id, vendor_id, user_id, title, description,
                  status, priority, created_at, updated_at)
              VALUES
                 (:jnum, :bid, :vid, :uid, :title, :desc,
                  'open', :priority, NOW(), NOW())"
        );
        $jStmt->execute([
            ':jnum'  => $jobNum,
            ':bid'   => $bookingId,
            ':vid'   => $initialVendorId,
            ':uid'   => (int)$auth['user_id'],
            ':title' => 'Job: ' . $service['name'],
            ':desc'  => $canonicalNotes ?: null,
            ':priority' => $jobPriority,
        ]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        Response::error('Booking could not be created. Please try again.', 500);
    }

    Response::success([
        'message'        => 'Booking created. A vendor will confirm shortly.',
        'booking_id'     => $bookingId,
        'booking_number' => $bookingNum,
        'job_number'     => $jobNum,
        'service'        => $service['name'],
        'booking_mode'   => $bookingMode,
        'lifecycle_type' => $bookingMode,
        'scheduled_at'   => $scheduledAt,
        'total'          => $bookingTotal,
        'status'         => 'pending',
        'payment_status' => $paymentStatus,
        'vendor_route'   => $bookingMode === 'direct_vendor' ? 'direct_vendor' : 'admin_queue',
        'payment_data'   => $paymentData,
        'request_id'     => $operationalRequest['request_id'],
        'client_request_id' => $operationalRequest['client_request_id'],
        'request_schema_version' => $operationalRequest['request_schema_version'],
        'issue_summary'  => $operationalRequest['issue_summary'],
        'lifecycle_state' => $operationalRequest['lifecycle_state'],
        'assignment_state' => $operationalRequest['assignment_state'],
    ], 201);
}

// ── GET /api/service/bookings ─────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/service/bookings') {
    header('Cache-Control: no-store, max-age=0');
    $auth   = AuthMiddleware::require();
    $status = $_GET['status'] ?? null;

    // Scope by role
    if ($auth['role'] === ROLE_ADMIN) {
        $where = [];
        $bind  = [];
    } elseif ($auth['role'] === ROLE_VENDOR_SERVICE) {
        $vendorId = resolveVendorId($db, (int)$auth['user_id']);
        $where    = ['b.vendor_id = :vid'];
        $bind     = [':vid' => $vendorId];
    } else {
        // Regular user — own bookings only
        $where = ['b.user_id = :uid'];
        $bind  = [':uid' => (int)$auth['user_id']];
    }

    if ($status) {
        $where[]         = '(b.status = :status OR j.status = :job_status)';
        $bind[':status'] = normalizeServiceJobStatus($status);
        $bind[':job_status'] = canonicalJobStatusForBooking($status);
    }

    if (!empty($_GET['vendor_id']) && $auth['role'] === ROLE_ADMIN) {
        $where[] = 'b.vendor_id = :filter_vid';
        $bind[':filter_vid'] = (int)$_GET['vendor_id'];
    }
    if (!empty($_GET['phone'])) {
        $where[] = 'u.phone LIKE :phone';
        $bind[':phone'] = '%' . trim($_GET['phone']) . '%';
    }
    if (!empty($_GET['q'])) {
        $where[] = '(b.booking_number LIKE :q OR s.name LIKE :q OR v.business_name LIKE :q OR u.name LIKE :q OR u.phone LIKE :q)';
        $bind[':q'] = '%' . trim($_GET['q']) . '%';
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $bookingModeSelect = serviceBookingColumnSql($db, 'booking_mode', 'b.booking_mode');
    $customerNameSelect = serviceBookingColumnSql($db, 'customer_name', 'b.customer_name');
    $customerMobileSelect = serviceBookingColumnSql($db, 'customer_mobile', 'b.customer_mobile');
    $customerLocalitySelect = serviceBookingColumnSql($db, 'customer_locality', 'b.customer_locality');
    $customerAddressSelect = serviceBookingColumnSql($db, 'customer_address', 'b.customer_address');
    $vendorRouteSelect = serviceBookingColumnSql($db, 'vendor_route', 'b.vendor_route');
    $jobAssignmentLockSelect = serviceJobColumnSql($db, 'assignment_lock_time', 'j.assignment_lock_time');

    $stmt = $db->prepare(
        "SELECT b.*, s.name AS service_name, v.business_name AS vendor_name,
                 {$customerNameSelect}, {$customerMobileSelect}, {$customerLocalitySelect}, {$customerAddressSelect},
                 {$bookingModeSelect}, {$vendorRouteSelect},
                  u.name AS user_name, u.phone AS customer_phone,
                  j.id AS job_id, j.job_number, j.status AS job_status, {$jobAssignmentLockSelect}
         FROM bookings b
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN vendors v ON v.id = b.vendor_id
         LEFT JOIN users u ON u.id = b.user_id
         LEFT JOIN jobs j ON j.booking_id = b.id
         $whereSQL
         ORDER BY b.created_at DESC
         LIMIT 50"
    );
    $stmt->execute($bind);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bookings as &$booking) {
        $booking['status'] = normalizeServiceJobStatus((string)($booking['status'] ?? 'pending'));
        $booking['job_status'] = normalizeServiceJobStatus((string)($booking['job_status'] ?? $booking['status']));
        $booking['amount'] = $booking['total'] ?? $booking['amount'] ?? null;
        $booking['payment_method'] = $booking['payment_method'] ?: 'cod';
        $booking['booking_mode'] = $booking['booking_mode'] ?: serviceModeFromNotes($booking['notes'] ?? null);
        $booking['vendor_route'] = $booking['vendor_route'] ?: ($booking['booking_mode'] === 'direct_vendor' ? 'direct_vendor' : 'admin_queue');
        $booking['customer_name'] = $booking['customer_name'] ?: ($booking['user_name'] ?? null);
        $booking = serviceAttachOperationalView($booking, $auth['role'] === ROLE_ADMIN);
        $booking['support_hint'] = 'WorkToGo support can help with this booking ID.';
        if ($auth['role'] === ROLE_ADMIN) {
            $booking['vendor_eligibility'] = serviceBookingEligibility($db, $booking);
        }
    }
    unset($booking);

    Response::success(['bookings' => $bookings, 'total' => count($bookings)]);
}

// ── GET /api/service/bookings/{id} ────────────────────────────────────────────
// FIX: IDOR — enforce that only the owning user, the assigned vendor, or an admin
//      can view a specific booking.
if ($method === 'GET' && preg_match('#^/api/service/bookings/(\d+)$#', $uri, $m)) {
    $auth = AuthMiddleware::require();
    $id   = (int)$m[1];

    $stmt = $db->prepare(
        "SELECT b.*, s.name AS service_name, v.business_name AS vendor_name,
                 " . serviceBookingColumnSql($db, 'booking_mode', 'b.booking_mode') . ",
                 " . serviceBookingColumnSql($db, 'customer_name', 'b.customer_name') . ",
                 " . serviceBookingColumnSql($db, 'customer_mobile', 'b.customer_mobile') . ",
                 " . serviceBookingColumnSql($db, 'customer_locality', 'b.customer_locality') . ",
                 " . serviceBookingColumnSql($db, 'customer_address', 'b.customer_address') . ",
                 " . serviceBookingColumnSql($db, 'vendor_route', 'b.vendor_route') . ",
                 j.id AS job_id, j.job_number, j.status AS job_status
         FROM bookings b
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN vendors v ON v.id = b.vendor_id
         LEFT JOIN jobs j ON j.booking_id = b.id
         WHERE b.id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) Response::notFound('Booking');

    // Ownership enforcement
    if ($auth['role'] === ROLE_ADMIN) {
        // Admin may view any booking — no restriction
    } elseif ($auth['role'] === ROLE_VENDOR_SERVICE) {
        $vendorId = resolveVendorId($db, (int)$auth['user_id']);
        if ((int)$booking['vendor_id'] !== $vendorId) {
            Response::forbidden('Access denied to this booking');
        }
    } else {
        if ((int)$booking['user_id'] !== (int)$auth['user_id']) {
            Response::forbidden('Access denied to this booking');
        }
    }

    $booking['status'] = normalizeServiceJobStatus((string)($booking['status'] ?? 'pending'));
    $booking['job_status'] = normalizeServiceJobStatus((string)($booking['job_status'] ?? $booking['status']));
    $booking['amount'] = $booking['total'] ?? $booking['amount'] ?? null;
    $booking['payment_method'] = $booking['payment_method'] ?: 'cod';
    $booking['booking_mode'] = $booking['booking_mode'] ?: serviceModeFromNotes($booking['notes'] ?? null);
    $booking['vendor_route'] = $booking['vendor_route'] ?: ($booking['booking_mode'] === 'direct_vendor' ? 'direct_vendor' : 'admin_queue');
    $booking = serviceAttachOperationalView($booking, $auth['role'] === ROLE_ADMIN);
    $booking['support_hint'] = 'WorkToGo support can help with this booking ID.';
    if ($auth['role'] === ROLE_ADMIN) {
        $booking['vendor_eligibility'] = serviceBookingEligibility($db, $booking);
    }

    // Attach linked job
    $jobStmt = $db->prepare("SELECT * FROM jobs WHERE booking_id = ? LIMIT 1");
    $jobStmt->execute([$id]);
    $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

    Response::success(['booking' => $booking, 'job' => $job ?: null]);
}

// ── PATCH /api/service/bookings/{id}/ops ───────────────────────────────────────
// Lightweight admin workflow simulation actions. Persists metadata in canonical
// booking notes so future admin UI can execute operations without schema redesign.
if ($method === 'PATCH' && preg_match('#^/api/service/bookings/(\d+)/ops$#', $uri, $m)) {
    $auth = AuthMiddleware::requireRole(ROLE_ADMIN);
    $bookingId = (int)$m[1];
    $input = defined('HEART_INTERNAL_INC')
        ? (json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true)['data'] ?? [])
        : (json_decode(file_get_contents('php://input'), true) ?? []);
    $action = serviceSlug((string)($input['action'] ?? ''));
    $allowedActions = ['assign_vendor', 'unassign_vendor', 'reassign_vendor', 'mark_worker_contacted', 'mark_worker_confirmed', 'inspection_queued', 'coordinator_assigned', 'inspection_scheduled', 'inspection_completed', 'mark_escalated'];
    if (!in_array($action, $allowedActions, true)) {
        Response::validation('Unsupported admin operation action');
    }

    $bookingStmt = $db->prepare(
        "SELECT b.*, s.name AS service_name, v.business_name AS vendor_name
         FROM bookings b
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN vendors v ON v.id = b.vendor_id
         WHERE b.id = ?
         LIMIT 1"
    );
    $bookingStmt->execute([$bookingId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) Response::notFound('Booking');

    $bookingMode = $booking['booking_mode'] ?: serviceModeFromNotes($booking['notes'] ?? null);
    $config = serviceOperationalActionConfig($action, $bookingMode);
    if (!$config) Response::validation('Unsupported admin operation action');
    if (str_starts_with($action, 'inspection_') || $action === 'coordinator_assigned') {
        $bookingMode = 'inspection';
    }

    $now = gmdate('c');
    $currentLifecycle = serviceNoteField($booking['notes'] ?? null, 'Lifecycle state') ?: ($bookingMode === 'inspection' ? 'payment_pending' : 'request_received');
    $nextLifecycle = (string)($config['lifecycle'] ?? $currentLifecycle);
    if (!serviceCanTransitionLifecycle($bookingMode, $currentLifecycle, $nextLifecycle)) {
        Response::validation('Invalid lifecycle transition from ' . $currentLifecycle . ' to ' . $nextLifecycle);
    }

    $vendorId = isset($input['vendor_id']) ? (int)$input['vendor_id'] : (int)($booking['vendor_id'] ?? 0);
    $vendorName = $booking['vendor_name'] ?? null;
    if (in_array($action, ['assign_vendor', 'reassign_vendor'], true)) {
        if ($vendorId <= 0) Response::validation('vendor_id is required for vendor assignment actions');
        $typeColumn = ServiceVendorEligibility::vendorTypeColumn($db);
        $vendorStmt = $db->prepare("SELECT " . ServiceVendorEligibility::buildVendorSelect($db, 'v') . " FROM vendors v WHERE v.id = ? AND v.status = 'active' AND v.{$typeColumn} = 'service' LIMIT 1");
        $vendorStmt->execute([$vendorId]);
        $vendor = $vendorStmt->fetch(PDO::FETCH_ASSOC);
        if (!$vendor) Response::validation('Active service vendor not found');
        $vendorName = $vendor['business_name'];
    }
    if ($action === 'unassign_vendor') {
        $vendorId = 0;
        $vendorName = null;
    }

    $assignmentMetadata = serviceDefaultAssignmentMetadata(serviceJsonField($booking['notes'] ?? null, 'Assignment metadata'));
    if (in_array($action, ['assign_vendor', 'reassign_vendor', 'unassign_vendor', 'mark_worker_contacted', 'mark_worker_confirmed'], true)) {
        $assignmentMetadata = serviceDefaultAssignmentMetadata([
            'assigned_vendor_id' => $vendorId > 0 ? $vendorId : null,
            'assigned_vendor_name' => $vendorName,
            'assigned_by' => 'admin:' . (int)($auth['user_id'] ?? 0),
            'assigned_at' => $vendorId > 0 ? $now : null,
            'assignment_notes' => trim((string)($input['notes'] ?? $input['assignment_notes'] ?? '')) ?: null,
        ]);
    }

    $inspectionMetadata = serviceDefaultInspectionMetadata($bookingMode, serviceJsonField($booking['notes'] ?? null, 'Inspection metadata'));
    if (isset($config['inspection'])) {
        $inspectionMetadata = serviceDefaultInspectionMetadata('inspection', array_replace($inspectionMetadata, [
            'inspection_required' => true,
            'inspection_status' => $config['inspection'],
            'inspection_assigned_to' => trim((string)($input['coordinator_id'] ?? $input['inspection_assigned_to'] ?? $inspectionMetadata['inspection_assigned_to'] ?? '')) ?: null,
            'inspection_scheduled_at' => trim((string)($input['inspection_scheduled_at'] ?? $inspectionMetadata['inspection_scheduled_at'] ?? '')) ?: null,
            'inspection_notes' => trim((string)($input['notes'] ?? $inspectionMetadata['inspection_notes'] ?? '')) ?: null,
        ]));
    }

    $escalationMetadata = serviceDefaultEscalationMetadata(serviceJsonField($booking['notes'] ?? null, 'Escalation metadata'));
    if ($action === 'mark_escalated') {
        $escalationMetadata = serviceDefaultEscalationMetadata([
            'escalation_required' => true,
            'escalation_reason' => trim((string)($input['escalation_reason'] ?? $input['notes'] ?? 'Operational review required')),
            'escalation_level' => trim((string)($input['escalation_level'] ?? 'level_1')),
            'escalated_at' => $now,
        ]);
    }

    $timeline = serviceNormalizeTimeline(serviceJsonField($booking['notes'] ?? null, 'Timeline'), (string)($booking['created_at'] ?? $now), $currentLifecycle);
    $timeline[] = [
        'event' => (string)($config['event'] ?? $action),
        'state' => $nextLifecycle,
        'at' => $now,
        'actor' => 'admin',
        'source' => 'admin_ops_simulation',
        'visible_to_customer' => false,
        'notes' => trim((string)($input['notes'] ?? '')),
    ];

    $nextAssignment = (string)($config['assignment'] ?? serviceNoteField($booking['notes'] ?? null, 'Assignment state') ?: ($bookingMode === 'inspection' ? 'inspection_unassigned' : 'unassigned_searching'));
    $nextNotes = serviceAppendNoteField($booking['notes'] ?? null, 'Lifecycle state', $nextLifecycle);
    $nextNotes = serviceAppendNoteField($nextNotes, 'Assignment state', $nextAssignment);
    $nextNotes = serviceAppendNoteField($nextNotes, 'Assignment metadata', json_encode($assignmentMetadata, JSON_UNESCAPED_SLASHES));
    $nextNotes = serviceAppendNoteField($nextNotes, 'Inspection metadata', json_encode($inspectionMetadata, JSON_UNESCAPED_SLASHES));
    $nextNotes = serviceAppendNoteField($nextNotes, 'Escalation metadata', json_encode($escalationMetadata, JSON_UNESCAPED_SLASHES));
    $nextNotes = serviceAppendNoteField($nextNotes, 'Timeline', json_encode($timeline, JSON_UNESCAPED_SLASHES));

    $bookingUpdates = ['notes = :notes'];
    $bookingBind = [':notes' => $nextNotes, ':id' => $bookingId];
    if (in_array($action, ['assign_vendor', 'reassign_vendor', 'unassign_vendor'], true)) {
        $bookingUpdates[] = 'vendor_id = :vendor_id';
        $bookingBind[':vendor_id'] = $vendorId > 0 ? $vendorId : null;
        if (serviceTableHasColumn($db, 'bookings', 'vendor_route')) {
            $bookingUpdates[] = 'vendor_route = :vendor_route';
            $bookingBind[':vendor_route'] = $vendorId > 0 ? 'admin_assigned' : 'admin_queue';
        }
    }
    if (serviceTableHasColumn($db, 'bookings', 'updated_at')) {
        $bookingUpdates[] = 'updated_at = NOW()';
    }

    try {
        $db->beginTransaction();
        $db->prepare("UPDATE bookings SET " . implode(', ', $bookingUpdates) . " WHERE id = :id")->execute($bookingBind);
        if (in_array($action, ['assign_vendor', 'reassign_vendor', 'unassign_vendor'], true)) {
            $jobUpdates = ['vendor_id = :vendor_id'];
            $jobBind = [':vendor_id' => $vendorId > 0 ? $vendorId : null, ':booking_id' => $bookingId];
            if (serviceTableHasColumn($db, 'jobs', 'updated_at')) $jobUpdates[] = 'updated_at = NOW()';
            $db->prepare("UPDATE jobs SET " . implode(', ', $jobUpdates) . " WHERE booking_id = :booking_id")->execute($jobBind);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        Response::error('Admin operation could not be simulated', 500);
    }

    Response::success([
        'booking_id' => $bookingId,
        'action' => $action,
        'lifecycle_state' => $nextLifecycle,
        'assignment_state' => $nextAssignment,
        'assignment' => $assignmentMetadata,
        'inspection' => $inspectionMetadata,
        'escalation' => $escalationMetadata,
        'timeline_snapshot' => serviceTimelineSnapshot($timeline),
    ], 200, 'Admin operation simulated');
}

// ── PATCH /api/service/bookings/{id}/assign ───────────────────────────────────
// Admin assignment control. Reuses bookings.vendor_id and jobs.vendor_id/status;
// no parallel routing table is introduced.
if ($method === 'PATCH' && preg_match('#^/api/service/bookings/(\d+)/assign$#', $uri, $m)) {
    $auth = AuthMiddleware::requireRole(ROLE_ADMIN);
    $bookingId = (int)$m[1];
    $input = defined('HEART_INTERNAL_INC')
        ? (json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true)['data'] ?? [])
        : (json_decode(file_get_contents('php://input'), true) ?? []);
    $vendorId = (int)($input['vendor_id'] ?? 0);
    $rawStatus = strtolower(trim((string)($input['status'] ?? 'assigned')));
    $newJobStatus = match ($rawStatus) {
        'assigned', 'confirmed', 'accept', 'accepted' => 'assigned',
        'open', 'pending', 'unassigned' => 'open',
        default => null,
    };

    if ($vendorId <= 0) Response::validation('vendor_id is required');
    if (!$newJobStatus) Response::validation('status must be assigned or open');

    $bookingStmt = $db->prepare(
        "SELECT b.*, s.name AS service_name
         FROM bookings b
         LEFT JOIN services s ON s.id = b.service_id
         WHERE b.id = ?
         LIMIT 1"
    );
    $bookingStmt->execute([$bookingId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) Response::notFound('Booking');

    $currentStatus = normalizeServiceJobStatus((string)($booking['status'] ?? 'pending'));
    if (in_array($currentStatus, ['completed', 'cancelled'], true)) {
        Response::validation('Completed or cancelled bookings cannot be reassigned');
    }

    $bookingMode = $booking['booking_mode'] ?: serviceModeFromNotes($booking['notes'] ?? null);
    $currentLifecycle = serviceNoteField($booking['notes'] ?? null, 'Lifecycle state') ?: ($bookingMode === 'inspection' ? 'inspection_queued' : 'searching_worker');
    $nextLifecycle = $newJobStatus === 'open' ? ($bookingMode === 'inspection' ? 'inspection_queued' : 'searching_worker') : ($bookingMode === 'inspection' ? 'inspection_assigned' : 'worker_assigned');
    if (!serviceCanTransitionLifecycle($bookingMode, $currentLifecycle, $nextLifecycle)) {
        Response::validation('Invalid lifecycle transition from ' . $currentLifecycle . ' to ' . $nextLifecycle);
    }

    $typeColumn = ServiceVendorEligibility::vendorTypeColumn($db);
    $vendorStmt = $db->prepare(
        "SELECT " . ServiceVendorEligibility::buildVendorSelect($db, 'v') . "
         FROM vendors v
         WHERE v.id = ? AND v.status = 'active' AND v.{$typeColumn} = 'service'
         LIMIT 1"
    );
    $vendorStmt->execute([$vendorId]);
    $vendor = $vendorStmt->fetch(PDO::FETCH_ASSOC);
    if (!$vendor) Response::validation('Active service vendor not found');

    $availability = ServiceVendorEligibility::availabilityFor($db, $vendorId, $booking['scheduled_at'] ?? null, $bookingId);
    $eligibility = ServiceVendorEligibility::evaluate($vendor, $booking, $availability);
    if (!$eligibility['assignable']) {
        Response::validation('Vendor is not operationally eligible: ' . implode('; ', $eligibility['reasons']), ['eligibility' => $eligibility]);
    }

    $jobStmt = $db->prepare("SELECT * FROM jobs WHERE booking_id = ? LIMIT 1");
    $jobStmt->execute([$bookingId]);
    $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

    $bookingStatus = canonicalBookingStatusForJob($newJobStatus);
    $effectiveVendorId = $newJobStatus === 'open' ? null : $vendorId;
    $effectiveRoute = $newJobStatus === 'open' ? 'admin_queue' : 'admin_assigned';
    $assignedAt = gmdate('c');
    $assignmentMetadata = serviceDefaultAssignmentMetadata([
        'assigned_vendor_id' => $effectiveVendorId,
        'assigned_vendor_name' => $newJobStatus === 'open' ? null : $vendor['business_name'],
        'assigned_by' => 'admin:' . (int)($auth['user_id'] ?? 0),
        'assigned_at' => $newJobStatus === 'open' ? null : $assignedAt,
        'assignment_notes' => trim((string)($input['assignment_notes'] ?? $input['notes'] ?? '')) ?: null,
    ]);
    $timeline = serviceNormalizeTimeline(serviceJsonField($booking['notes'] ?? null, 'Timeline'), (string)($booking['created_at'] ?? $assignedAt), $currentLifecycle);
    $timeline[] = [
        'event' => $newJobStatus === 'open' ? 'assignment_reopened' : 'vendor_assigned',
        'state' => $nextLifecycle,
        'at' => $assignedAt,
        'actor' => 'admin',
        'source' => 'admin_assignment',
        'visible_to_customer' => false,
        'notes' => trim((string)($input['assignment_notes'] ?? '')),
    ];
    $nextNotes = serviceAppendNoteField($booking['notes'] ?? null, 'Lifecycle state', $nextLifecycle);
    $nextNotes = serviceAppendNoteField($nextNotes, 'Assignment state', $newJobStatus === 'open' ? ($bookingMode === 'inspection' ? 'inspection_unassigned' : 'unassigned_searching') : ($bookingMode === 'inspection' ? 'inspection_assigned' : 'worker_assigned'));
    $nextNotes = serviceAppendNoteField($nextNotes, 'Assignment metadata', json_encode($assignmentMetadata, JSON_UNESCAPED_SLASHES));
    $nextNotes = serviceAppendNoteField($nextNotes, 'Timeline', json_encode($timeline, JSON_UNESCAPED_SLASHES));
    $bookingUpdates = ['vendor_id = :vendor_id', 'status = :status'];
    $bookingBind = [':vendor_id' => $effectiveVendorId, ':status' => $bookingStatus, ':notes' => $nextNotes, ':id' => $bookingId];
    $bookingUpdates[] = 'notes = :notes';
    if (serviceTableHasColumn($db, 'bookings', 'vendor_route')) {
        $bookingUpdates[] = 'vendor_route = :vendor_route';
        $bookingBind[':vendor_route'] = $effectiveRoute;
    }
    if (serviceTableHasColumn($db, 'bookings', 'updated_at')) {
        $bookingUpdates[] = 'updated_at = NOW()';
    }

    try {
        $db->beginTransaction();

        $db->prepare("UPDATE bookings SET " . implode(', ', $bookingUpdates) . " WHERE id = :id")
           ->execute($bookingBind);

        if ($job) {
            $jobUpdates = ['vendor_id = :vendor_id', 'status = :status'];
            $jobBind = [':vendor_id' => $effectiveVendorId, ':status' => $newJobStatus, ':id' => (int)$job['id']];
            if (serviceTableHasColumn($db, 'jobs', 'assignment_lock_time')) {
                $jobUpdates[] = 'assignment_lock_time = NULL';
            }
            if (serviceTableHasColumn($db, 'jobs', 'updated_at')) {
                $jobUpdates[] = 'updated_at = NOW()';
            }
            $db->prepare("UPDATE jobs SET " . implode(', ', $jobUpdates) . " WHERE id = :id")
               ->execute($jobBind);
            $jobId = (int)$job['id'];
        } else {
            $jobNum = 'WTG-JOB-' . strtoupper(bin2hex(random_bytes(4)));
            $db->prepare(
                "INSERT INTO jobs
                    (job_number, booking_id, vendor_id, user_id, title, description, status, priority, assignment_lock_time, created_at, updated_at)
                 VALUES
                    (:jnum, :bid, :vid, :uid, :title, :desc, :status, 'normal', NOW(), NOW(), NOW())"
            )->execute([
                ':jnum' => $jobNum,
                ':bid' => $bookingId,
                ':vid' => $effectiveVendorId,
                ':uid' => (int)($booking['user_id'] ?? 0),
                ':title' => 'Job: ' . ($booking['service_name'] ?: ('Booking #' . $bookingId)),
                ':desc' => $booking['notes'] ?? null,
                ':status' => $newJobStatus,
            ]);
            $jobId = (int)$db->lastInsertId();
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        Response::error('Booking assignment could not be updated', 500);
    }

    Response::success([
        'booking_id' => $bookingId,
        'job_id' => $jobId,
        'vendor_id' => $effectiveVendorId,
        'vendor_name' => $vendor['business_name'],
        'status' => $bookingStatus,
        'job_status' => normalizeServiceJobStatus($newJobStatus),
        'vendor_route' => $effectiveRoute,
        'eligibility' => $eligibility,
    ], 200, 'Booking assignment updated');
}

// ── PATCH /api/jobs/{id}/status ───────────────────────────────────────────────
// FIX: Vendor ownership validated via vendors table (not raw user_id comparison).
if ($method === 'PATCH' && preg_match('#^/api/jobs/(\d+)/status$#', $uri, $m)) {
    $auth      = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_ADMIN);
    $input     = defined('HEART_INTERNAL_INC')
        ? (json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true)['data'] ?? [])
        : (json_decode(file_get_contents('php://input'), true) ?? []);
    $jobId     = (int)$m[1];
    $rawStatus = strtolower(trim((string)($input['status'] ?? '')));
    $isVendorRequeue = $auth['role'] === ROLE_VENDOR_SERVICE && in_array($rawStatus, ['rejected', 'reject', 'declined', 'decline'], true);
    $newStatus = $isVendorRequeue ? 'open' : canonicalJobStatusForBooking($rawStatus);

    $allowed = ['open', 'assigned', 'in_progress', 'completed', 'cancelled'];
    if (!in_array($newStatus, $allowed, true)) {
        Response::validation('status must be one of: ' . implode(', ', $allowed));
    }

    $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? LIMIT 1");
    $jobStmt->execute([$jobId]);
    $jobRow = $jobStmt->fetch(PDO::FETCH_ASSOC);
    if (!$jobRow) Response::notFound('Job');

    // FIX: vendor ownership — compare against vendors.id, not users.id
    if ($auth['role'] === ROLE_VENDOR_SERVICE) {
        $vendorId = resolveVendorId($db, (int)$auth['user_id']);
        if ((int)$jobRow['vendor_id'] !== $vendorId) {
            Response::forbidden('You do not have permission to update this job');
        }
    }

    if ($isVendorRequeue) {
        $currentStatus = normalizeServiceJobStatus((string)($jobRow['status'] ?? 'pending'));
        if (in_array($currentStatus, ['completed', 'cancelled'], true)) {
            Response::validation('Completed or cancelled jobs cannot be rejected for reassignment');
        }

        $bookingForNotes = null;
        $nextBookingNotes = null;
        if ($jobRow['booking_id']) {
            $notesStmt = $db->prepare("SELECT id, booking_mode, notes, created_at FROM bookings WHERE id = ? LIMIT 1");
            $notesStmt->execute([(int)$jobRow['booking_id']]);
            $bookingForNotes = $notesStmt->fetch(PDO::FETCH_ASSOC);
            if ($bookingForNotes) {
                $mode = $bookingForNotes['booking_mode'] ?: serviceModeFromNotes($bookingForNotes['notes'] ?? null);
                $fromLifecycle = serviceNoteField($bookingForNotes['notes'] ?? null, 'Lifecycle state') ?: 'worker_assigned';
                $toLifecycle = $mode === 'inspection' ? 'inspection_queued' : 'searching_worker';
                if (!serviceCanTransitionLifecycle($mode, $fromLifecycle, $toLifecycle)) {
                    Response::validation('Invalid lifecycle transition from ' . $fromLifecycle . ' to ' . $toLifecycle);
                }
                $timeline = serviceNormalizeTimeline(serviceJsonField($bookingForNotes['notes'] ?? null, 'Timeline'), (string)($bookingForNotes['created_at'] ?? gmdate('c')), $fromLifecycle);
                $timeline[] = ['event' => 'vendor_rejected', 'state' => $toLifecycle, 'at' => gmdate('c'), 'actor' => 'vendor', 'source' => 'vendor_update', 'visible_to_customer' => false, 'notes' => trim((string)($input['notes'] ?? ''))];
                $nextBookingNotes = serviceAppendNoteField($bookingForNotes['notes'] ?? null, 'Lifecycle state', $toLifecycle);
                $nextBookingNotes = serviceAppendNoteField($nextBookingNotes, 'Assignment state', $mode === 'inspection' ? 'inspection_unassigned' : 'unassigned_searching');
                $nextBookingNotes = serviceAppendNoteField($nextBookingNotes, 'Assignment metadata', json_encode(serviceDefaultAssignmentMetadata(['assignment_notes' => 'Returned to admin queue after vendor rejection']), JSON_UNESCAPED_SLASHES));
                $nextBookingNotes = serviceAppendNoteField($nextBookingNotes, 'Timeline', json_encode($timeline, JSON_UNESCAPED_SLASHES));
            }
        }

        try {
            $db->beginTransaction();

            $jobUpdates = ['vendor_id = NULL', 'status = :status', 'updated_at = NOW()'];
            $jobBind = [':status' => 'open', ':id' => $jobId];
            if (serviceTableHasColumn($db, 'jobs', 'assignment_lock_time')) {
                $jobUpdates[] = 'assignment_lock_time = NULL';
            }
            $db->prepare("UPDATE jobs SET " . implode(', ', $jobUpdates) . " WHERE id = :id")
               ->execute($jobBind);

            if ($jobRow['booking_id']) {
                $bookingUpdates = ['vendor_id = NULL', 'status = :status'];
                $bookingBind = [':status' => 'pending', ':id' => (int)$jobRow['booking_id']];
                if (serviceTableHasColumn($db, 'bookings', 'vendor_route')) {
                    $bookingUpdates[] = 'vendor_route = :vendor_route';
                    $bookingBind[':vendor_route'] = 'admin_queue';
                }
                if (serviceTableHasColumn($db, 'bookings', 'updated_at')) {
                    $bookingUpdates[] = 'updated_at = NOW()';
                }
                if ($nextBookingNotes !== null) {
                    $bookingUpdates[] = 'notes = :notes';
                    $bookingBind[':notes'] = $nextBookingNotes;
                }
                $db->prepare("UPDATE bookings SET " . implode(', ', $bookingUpdates) . " WHERE id = :id")
                   ->execute($bookingBind);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::error('Job could not be returned to admin queue', 500);
        }

        Response::success([
            'message' => 'Job rejected and returned to admin queue for reassignment',
            'job_id'  => $jobId,
            'status'  => 'pending',
            'job_status' => 'open',
            'vendor_id' => null,
            'vendor_route' => 'admin_queue',
        ]);
    }

    try {
        $db->beginTransaction();

        $updates = ['status = :status', 'updated_at = NOW()'];
    $bind    = [':status' => $newStatus, ':id' => $jobId];

    if ($newStatus === 'in_progress' && empty($jobRow['started_at'])) {
        $updates[] = 'started_at = NOW()';
    }
    if ($newStatus === 'completed' && empty($jobRow['completed_at'])) {
        $updates[] = 'completed_at = NOW()';
    }

        $db->prepare("UPDATE jobs SET " . implode(', ', $updates) . " WHERE id = :id")
           ->execute($bind);

    // Mirror status onto the linked booking
    $bookingStatus = match ($newStatus) {
        'open'        => 'pending',
        'assigned'    => 'confirmed',
        'in_progress' => 'in_progress',
        'completed'   => 'completed',
        'cancelled'   => 'cancelled',
        default       => null,
    };
        if ($bookingStatus && $jobRow['booking_id']) {
            $bookingUpdates = ['status = :status'];
            $bookingBind = [':status' => $bookingStatus, ':id' => (int)$jobRow['booking_id']];
            if (!empty($jobRow['vendor_id'])) {
                $bookingUpdates[] = 'vendor_id = :vendor_id';
                $bookingBind[':vendor_id'] = (int)$jobRow['vendor_id'];
            }
            if (serviceTableHasColumn($db, 'bookings', 'updated_at')) {
                $bookingUpdates[] = 'updated_at = NOW()';
            }
            $db->prepare("UPDATE bookings SET " . implode(', ', $bookingUpdates) . " WHERE id = :id")
               ->execute($bookingBind);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        Response::error('Job status could not be synchronized', 500);
    }

    Response::success([
        'message' => "Job status updated to '{$newStatus}'",
        'job_id'  => $jobId,
        'status'  => $bookingStatus ?: normalizeServiceJobStatus($newStatus),
    ]);
}

Response::error('Endpoint not found', 404);
