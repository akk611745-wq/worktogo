<?php
/**
 * AgentMemory.php — WorkToGo Agent Layer :: per-user memory / personalization (Phase 4)
 * ----------------------------------------------------------------------------
 * Remembers each user so repeat use is fast and personal — what they use most,
 * their area/city, how many times they've been here — keyed to OUR internal user
 * id, stored in OUR database (table `agent_memory`).
 *
 * ══ THE WALL RULE (founder's #1 requirement, re-stated for this file) ═════════
 * This memory lives ENTIRELY inside our system. The raw memory row is NEVER sent
 * to the external AI. The orchestrator uses the full row internally (to greet by
 * name-of-habit, pre-fill area, prioritise) — but the ONLY thing that may cross
 * the privacy wall is the tiny, derived, NON-IDENTIFYING hint list from hints():
 *
 *     ["returning", "pref_category:painting", "area_known"]
 *
 * These are coarse tokens — a public category slug and boolean-style flags. No
 * user id, no phone/email/address, no actual area or city value ever appears.
 * And even these pass the wall's deny-by-default allow-list + fail-closed
 * tripwire (PiiWall), so a mis-derived hint containing PII is refused, not sent.
 *
 * Everything here is defensive: a missing table (migration not applied) or any
 * error degrades to "no memory" — a new-user experience — never a broken turn.
 */

declare(strict_types=1);

final class AgentMemory
{
    /** How many recent tool names to keep. */
    private const RECENT_CAP = 8;

    /** @var bool|null cached table-existence for this request */
    private static ?bool $tableExists = null;

    public function __construct(private readonly ?PDO $db = null) {}

    /**
     * Load a user's memory row (decoded). Returns an empty, new-user shape when
     * there is no row / no table / any error.
     */
    public function load(int $userId): array
    {
        $empty = [
            'turn_count'      => 0,
            'pref_categories' => [],
            'last_category'   => null,
            'last_area'       => null,   // INTERNAL — never crosses the wall
            'last_city_id'    => null,   // INTERNAL — never crosses the wall
            'recent_tools'    => [],
        ];
        if (!$this->hasTable() || $userId <= 0) {
            return $empty;
        }
        try {
            $stmt = $this->db->prepare("SELECT * FROM agent_memory WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $empty;
            }
            return [
                'turn_count'      => (int) ($row['turn_count'] ?? 0),
                'pref_categories' => $this->jsonMap($row['pref_categories'] ?? null),
                'last_category'   => $row['last_category'] ?? null,
                'last_area'       => $row['last_area'] ?? null,
                'last_city_id'    => $row['last_city_id'] !== null ? (int) $row['last_city_id'] : null,
                'recent_tools'    => is_array($t = json_decode((string) ($row['recent_tools'] ?? '[]'), true)) ? $t : [],
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * The ONLY memory-derived data allowed to cross the privacy wall. Coarse,
     * non-identifying tokens. Documented and stable so it can be reviewed.
     */
    public function hints(array $memory): array
    {
        $hints = [];
        if ((int) ($memory['turn_count'] ?? 0) > 0) {
            $hints[] = 'returning';
        }
        $top = $this->topCategory($memory);
        if ($top !== null && preg_match('/^[a-z_]{2,40}$/', $top)) { // public slug only
            $hints[] = 'pref_category:' . $top;
        }
        if (!empty($memory['last_area']) || !empty($memory['last_city_id'])) {
            $hints[] = 'area_known';   // a FLAG that we have it — never the value
        }
        return $hints;
    }

    /**
     * Update memory after a turn (upsert). Records the category used, the city,
     * bumps the turn count, and tracks recent tools. Fully defensive.
     */
    public function record(AgentContext $ctx, string $tool, array $args): void
    {
        if (!$this->hasTable() || $ctx->userId <= 0) {
            return;
        }
        try {
            $mem = $this->load($ctx->userId);

            $mem['turn_count'] = (int) $mem['turn_count'] + 1;

            $category = $this->categoryFromArgs($args);
            if ($category !== null) {
                $prefs = $mem['pref_categories'];
                $prefs[$category] = (int) ($prefs[$category] ?? 0) + 1;
                $mem['pref_categories'] = $prefs;
                $mem['last_category']   = $category;
            }
            if (!empty($ctx->cityId)) {
                $mem['last_city_id'] = (int) $ctx->cityId;
            }
            if ($tool !== '' && $tool !== '-') {
                $recent = array_values(array_filter($mem['recent_tools'], 'is_string'));
                array_unshift($recent, $tool);
                $mem['recent_tools'] = array_slice(array_values(array_unique($recent)), 0, self::RECENT_CAP);
            }

            $stmt = $this->db->prepare(
                "INSERT INTO agent_memory
                    (user_id, turn_count, pref_categories, last_category, last_city_id, recent_tools, created_at, updated_at)
                 VALUES (:uid, :tc, :pc, :lc, :city, :rt, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    turn_count = VALUES(turn_count),
                    pref_categories = VALUES(pref_categories),
                    last_category = COALESCE(VALUES(last_category), last_category),
                    last_city_id = COALESCE(VALUES(last_city_id), last_city_id),
                    recent_tools = VALUES(recent_tools),
                    updated_at = NOW()"
            );
            $stmt->execute([
                ':uid'  => $ctx->userId,
                ':tc'   => $mem['turn_count'],
                ':pc'   => json_encode((object) $mem['pref_categories'], JSON_UNESCAPED_UNICODE) ?: '{}',
                ':lc'   => $mem['last_category'],
                ':city' => $mem['last_city_id'],
                ':rt'   => json_encode(array_values($mem['recent_tools']), JSON_UNESCAPED_UNICODE) ?: '[]',
            ]);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('agent.memory record skipped', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * A personalized opener for the chat, built INSIDE our system from real
     * memory (this text is OUR reply — it is not produced by, or sent to, the AI).
     * New users get a warm generic greeting; returning users are recognised.
     *
     * @return array{reply:string,chips:string[],personalized:bool}
     */
    public function personalizedGreeting(array $memory, string $displayName = 'dost', string $role = 'customer'): array
    {
        // Vendor-agent: the recruiting pitch — the vendor never learns a dashboard.
        if (str_starts_with($role, 'vendor')) {
            return [
                'reply'        => "Namaste {$displayName}! 👋 Aap apni skill bataiye — main aapke liye premium "
                                . "customers laa dunga. Kuch seekhna nahi, bas baat kijiye 🙂",
                'chips'        => ['listing banao', 'mera status', 'photo upload', 'weekly reminder'],
                'personalized' => (int) ($memory['turn_count'] ?? 0) > 0,
            ];
        }

        $returning = (int) ($memory['turn_count'] ?? 0) > 0;
        $top       = $this->topCategory($memory);

        if ($returning && $top !== null) {
            return [
                'reply'        => "Welcome back, {$displayName}! 👋 Pichli baar aapne "
                                . "{$top} dekha tha — aaj wahi chahiye ya kuch aur?",
                'chips'        => array_values(array_unique([
                    "{$top} chahiye", "book {$top}", "{$top} packages", "expert visit",
                ])),
                'personalized' => true,
            ];
        }
        if ($returning) {
            return [
                'reply'        => "Welcome back, {$displayName}! 👋 Bataiye aaj kya chahiye?",
                'chips'        => ["painter chahiye", "plumber chahiye", "painting packages", "expert visit"],
                'personalized' => true,
            ];
        }
        return [
            'reply'        => "Namaste {$displayName}! 👋 Main aapka WorkToGo assistant hoon.\n"
                            . "Bataiye kya chahiye — main dhoond ke, dikhake, book bhi kar deta hoon.",
            'chips'        => ["painter chahiye", "plumber chahiye", "painting packages", "expert visit"],
            'personalized' => false,
        ];
    }

    // ── internals ────────────────────────────────────────────────────────────────

    /** The single most-used category slug, or null. */
    private function topCategory(array $memory): ?string
    {
        $prefs = $memory['pref_categories'] ?? [];
        if (!is_array($prefs) || $prefs === []) {
            return is_string($memory['last_category'] ?? null) ? $memory['last_category'] : null;
        }
        arsort($prefs);
        $top = array_key_first($prefs);
        return is_string($top) && $top !== '' ? $top : null;
    }

    /** Pull a category slug out of a tool's args (find_vendors / packages / booking). */
    private function categoryFromArgs(array $args): ?string
    {
        foreach (['category', 'category_slug'] as $k) {
            if (!empty($args[$k]) && is_string($args[$k])) {
                $slug = strtolower(trim($args[$k]));
                if (preg_match('/^[a-z_]{2,40}$/', $slug)) {
                    return $slug;
                }
            }
        }
        return null;
    }

    private function jsonMap($raw): array
    {
        $d = json_decode((string) ($raw ?? '{}'), true);
        return is_array($d) ? $d : [];
    }

    private function hasTable(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }
        if (!$this->db instanceof PDO) {
            return self::$tableExists = false;
        }
        try {
            $stmt = $this->db->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_memory'"
            );
            return self::$tableExists = ((int) $stmt->fetchColumn() > 0);
        } catch (\Throwable $e) {
            return self::$tableExists = false;
        }
    }
}
