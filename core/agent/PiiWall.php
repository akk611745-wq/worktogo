<?php
/**
 * PiiWall.php — WorkToGo Agent Layer :: THE PRIVACY WALL (foundation, top priority)
 * ----------------------------------------------------------------------------
 * The single, mandatory choke point between WorkToGo and any external AI
 * provider. Every provider call MUST originate from a SanitizedPrompt, and a
 * SanitizedPrompt can ONLY be minted here (see SanitizedPrompt::seal). Therefore
 * there is no path to a provider that does not pass through this wall.
 *
 * What the wall guarantees (the founder's #1 requirement):
 *   1. NO PII LEAVES. Before any text is packaged for a provider it is scrubbed:
 *      phone numbers, emails, UPI ids, card-like numbers, Aadhaar-like numbers,
 *      our order/booking refs, URLs and long id-like digit runs are replaced with
 *      neutral placeholders (<PHONE_1>, <EMAIL_1>, …). The provider reasons over
 *      placeholders; meaning survives, identity does not.
 *   2. DENY-BY-DEFAULT ENVELOPE. The only structured context that may accompany
 *      the text is a coarse, allow-listed set (role, language, time-of-day,
 *      public capability verbs). Anything not on the allow-list is dropped. The
 *      real user_id / city_id / session are NEVER placed in the outbound object.
 *   3. FAIL-CLOSED TRIPWIRE. After scrubbing, the wall re-scans the exact bytes
 *      that would leave. If ANY detector still matches, it throws PiiLeakException
 *      and NOTHING is sent. A leak is a hard refusal, not a warning.
 *   4. WE STAY THE OWNER. The placeholder→original map is held HERE, keyed by
 *      request id, and is used only on the RETURN path to re-hydrate the reply
 *      for the user who owns that data. It never travels toward a provider, and
 *      is dropped when the wall object dies (request scope).
 *   5. AUDITABLE, WITHOUT SECRETS. The wall logs COUNTS of what it stripped (per
 *      type) — never the raw values — so egress is auditable.
 *
 * Extensibility: detectors are a simple ordered list. Name/address detection
 * (NER, saved-contact matching) drops in as additional detectors WITHOUT
 * changing the wall's contract — in the full agent, names/addresses are resolved
 * from saved contacts INSIDE the system, so they are referenced by placeholder,
 * never typed through to a provider.
 *
 * The wall does no business logic and never die()s at include time.
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentExceptions.php';
require_once __DIR__ . '/AgentContext.php';
require_once __DIR__ . '/SanitizedPrompt.php';

final class PiiWall
{
    /** The ONLY meta keys allowed to describe the user to a provider (deny-by-default).
     *  'hints' carries memory-DERIVED, non-identifying tokens only (e.g.
     *  'returning', 'pref_category:painting', 'area_known') — never a raw memory
     *  row, id, area or city value. See AgentMemory::hints(). The tripwire below
     *  re-scans everything, so any hint that still looks like PII is refused. */
    private const ALLOWED_META_KEYS = ['role', 'lang', 'period', 'capabilities', 'hints'];

    /**
     * Ordered PII detectors. Order matters: the most specific / longest patterns
     * run first so a broad catch-all (long digit run) never eats a structured id.
     * Each entry: [type, regex]. `type` becomes the placeholder tag (<TYPE_n>).
     */
    private const DETECTORS = [
        ['URL',     '#https?://[^\s<>"\']+#i'],
        ['EMAIL',   '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/'],
        ['ID',      '/\bWTG_[A-Za-z0-9_]+/i'],                 // our order/booking refs (WTG_… / WTG_B…)
        ['AADHAAR', '/\b\d{4}\s\d{4}\s\d{4}\b/'],              // 12 digits, space-grouped
        ['CARD',    '/\b\d(?:[ \-]?\d){12,18}\b/'],            // 13–19 digit card-like runs
        ['PHONE',   '/(?:\+?91[\-\s]?|\b0)?[6-9]\d{9}\b/'],    // Indian mobile, optional +91/0
        ['UPI',     '/\b[A-Za-z0-9.\-_]{2,}@[A-Za-z]{2,}\b/'], // vpa@handle (no dot TLD)
        ['NUM',     '/\b\d{6,}\b/'],                            // catch-all: OTPs, pincodes, ids
    ];

    private ?PDO $db;

    /** requestId → [placeholder => originalValue]  (kept INSIDE the wall only). */
    private array $maps = [];
    /** requestId → [type => count]  (redaction summary for audit). */
    private array $counts = [];
    /** Saved contact names to tokenize as <PERSON_n> (Phase 8 connections). */
    private array $contactTerms = [];

    /**
     * Give the wall the user's saved contact NAMES so it scrubs them out of the
     * message before anything leaves (the blueprint's saved-contact tokenization).
     * Names shorter than 3 chars are ignored to avoid over-scrubbing.
     */
    public function setContactTerms(array $terms): void
    {
        $this->contactTerms = array_values(array_unique(array_filter(
            array_map(static fn ($t) => trim((string) $t), $terms),
            static fn ($t) => mb_strlen($t) >= 3
        )));
    }

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    // ── The outbound path ────────────────────────────────────────────────────────

    /**
     * Turn a raw user turn into a sealed, safe-to-send SanitizedPrompt.
     *
     * @param AgentContext $ctx          internal identity (only coarse getters are read)
     * @param string       $rawUserText  the user's utterance (may contain PII)
     * @param string       $system       our static developer instruction (no user data)
     * @param string[]     $capabilities public verb list the assistant may perform (may be empty)
     * @param string       $task         'understand' | 'generate'
     *
     * @throws PiiLeakException if the outbound bytes still contain detectable PII (fail-closed)
     */
    public function sanitize(
        AgentContext $ctx,
        string $rawUserText,
        string $system,
        array $capabilities = [],
        string $task = 'understand',
        array $hints = []
    ): SanitizedPrompt {
        $requestId = $ctx->requestId;

        // 1. Scrub the user's text. The system text is OURS (static, no user data)
        //    but is still tripwire-checked below as defence in depth.
        [$scrubbed, $map, $counts] = $this->scrub($rawUserText, $requestId);
        $this->maps[$requestId]   = $map;
        $this->counts[$requestId] = $counts;

        // 2. Build the coarse, deny-by-default meta. Only allow-listed keys survive.
        //    `hints` are memory-derived, non-identifying tokens (short strings);
        //    anything longer/odd is dropped here, and the tripwire re-checks all.
        $safeHints = array_values(array_filter(
            array_map('strval', $hints),
            static fn ($h) => $h !== '' && mb_strlen($h) <= 40
        ));
        $meta = $this->filterMeta(array_merge(
            $ctx->coarseMeta(),
            [
                'capabilities' => array_values(array_filter(array_map('strval', $capabilities))),
                'hints'        => $safeHints,
            ]
        ));

        // 3. FAIL-CLOSED tripwire: re-scan the exact bytes that would leave.
        $outboundBlob = $system . "\n" . $scrubbed . "\n" . json_encode($meta, JSON_UNESCAPED_UNICODE);
        $this->assertOutboundClean($outboundBlob, $requestId);

        // 4. Audit the egress — COUNTS only, never raw values.
        $this->logEgress($ctx, $task, $counts, mb_strlen($scrubbed));

        // 5. Mint the sealed prompt (only the wall can do this).
        return SanitizedPrompt::seal($system, $scrubbed, $task, $meta, $requestId);
    }

    // ── The return path ──────────────────────────────────────────────────────────

    /**
     * Re-hydrate a provider's reply for the user who OWNS the data. Placeholders
     * are swapped back to their originals using the request-scoped map. Safe: the
     * data goes back only to its owner, never to a provider.
     */
    public function rehydrate(string $requestId, string $providerText): string
    {
        $map = $this->maps[$requestId] ?? [];
        if ($map === [] || $providerText === '') {
            return $providerText;
        }
        return strtr($providerText, $map);
    }

    /** Per-type redaction counts for a request (for the orchestrator's audit row). */
    public function redactionSummary(string $requestId): array
    {
        return $this->counts[$requestId] ?? [];
    }

    /** Drop the request-scoped map/counts as soon as the turn is done. */
    public function forget(string $requestId): void
    {
        unset($this->maps[$requestId], $this->counts[$requestId]);
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    /**
     * Replace every detected PII span with a stable placeholder.
     *
     * @return array{0:string,1:array<string,string>,2:array<string,int>}
     *               [scrubbedText, placeholder=>original map, type=>count]
     */
    private function scrub(string $text, string $requestId): array
    {
        $map    = [];
        $counts = [];
        $seq    = [];

        // Pre-pass: tokenize saved contact names → <PERSON_n> (runs before the
        // regex detectors so a name is never emitted to the AI).
        foreach ($this->contactTerms as $term) {
            $pat = '/\b' . preg_quote($term, '/') . '\b/iu';
            $text = preg_replace_callback($pat, function ($m) use (&$map, &$counts, &$seq) {
                $seq['PERSON'] = ($seq['PERSON'] ?? 0) + 1;
                $token = '<PERSON_' . $seq['PERSON'] . '>';
                $map[$token] = $m[0];
                $counts['PERSON'] = ($counts['PERSON'] ?? 0) + 1;
                return $token;
            }, $text) ?? $text;
        }

        foreach (self::DETECTORS as [$type, $regex]) {
            $text = preg_replace_callback($regex, function ($m) use ($type, &$map, &$counts, &$seq) {
                $original = $m[0];
                $seq[$type] = ($seq[$type] ?? 0) + 1;
                $token = '<' . $type . '_' . $seq[$type] . '>';
                $map[$token] = $original;                 // token → original (for re-hydrate)
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                return $token;
            }, $text) ?? $text;
        }

        return [$text, $map, $counts];
    }

    /** Keep only allow-listed meta keys — deny everything else by default. */
    private function filterMeta(array $meta): array
    {
        $clean = [];
        foreach (self::ALLOWED_META_KEYS as $key) {
            if (array_key_exists($key, $meta)) {
                $clean[$key] = $meta[$key];
            }
        }
        return $clean;
    }

    /**
     * Fail-closed egress tripwire. If ANY detector still matches the outbound
     * bytes, refuse the turn — do not send. This is the structural guarantee that
     * the wall never emits detectable PII, even if a future change slips raw data
     * into the envelope.
     */
    private function assertOutboundClean(string $blob, string $requestId): void
    {
        foreach (self::DETECTORS as [$type, $regex]) {
            if (preg_match($regex, $blob) === 1) {
                // Scrub the request-scoped map so nothing lingers after a refusal.
                $this->forget($requestId);
                throw new PiiLeakException(
                    'Outbound text failed the PII tripwire (' . $type . '); request refused.'
                );
            }
        }
    }

    /**
     * Audit what left — COUNTS ONLY, no raw values, no provider secrets. Written
     * to agent_ai_log when a DB is present, and always echoed to the app log so a
     * wall-authored trace exists even without a DB. Fully defensive: an audit
     * failure never blocks or breaks the turn.
     */
    private function logEgress(AgentContext $ctx, string $task, array $counts, int $outboundChars): void
    {
        $redactions = json_encode($counts, JSON_UNESCAPED_UNICODE) ?: '{}';

        if (class_exists('Logger')) {
            Logger::info('agent.egress', [
                'request_id'  => $ctx->requestId,
                'user_id'     => $ctx->userId,   // internal audit only — NOT sent to any provider
                'task'        => $task,
                'redactions'  => $counts,
                'out_chars'   => $outboundChars,
            ]);
        }

        if (!$this->db instanceof PDO) {
            return;
        }
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO agent_ai_log
                    (request_id, user_id, provider, model, task, redactions, prompt_chars, status, created_at)
                 VALUES (:rid, :uid, :provider, :model, :task, :red, :chars, :status, NOW())"
            );
            $stmt->execute([
                ':rid'      => $ctx->requestId,
                ':uid'      => $ctx->userId,
                ':provider' => 'pending',   // updated by the orchestrator once the provider replies
                ':model'    => null,
                ':task'     => $task,
                ':red'      => $redactions,
                ':chars'    => $outboundChars,
                ':status'   => 'egress',
            ]);
        } catch (\Throwable $e) {
            // Table absent (migration not applied) or write error — never fatal.
            if (class_exists('Logger')) {
                Logger::warning('agent.egress audit skipped', ['error' => $e->getMessage()]);
            }
        }
    }
}
