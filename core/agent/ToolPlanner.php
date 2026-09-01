<?php
/**
 * ToolPlanner.php — WorkToGo Agent Layer :: intent → tool proposal (Phase 2)
 * ----------------------------------------------------------------------------
 * Turns a user message into a PROPOSED {tool, args, say}. Two stages, cheapest
 * first:
 *
 *   1. DETERMINISTIC (system-first): common asks ("painter chahiye", "plumber
 *      dikhao", a vendor's "my jobs") are resolved by our own rules with NO
 *      external AI call at all — zero cost, fully private, always works. This is
 *      the blueprint's system-first principle made real.
 *
 *   2. AI FALLBACK: anything else is sent — THROUGH THE PRIVACY WALL — to the AI
 *      provider, which must answer in a strict JSON schema
 *      {"tool":"<name|none>","args":{...},"say":"..."} chosen ONLY from the
 *      caller's role catalog. The planner just parses/normalises this proposal.
 *
 * The planner NEVER executes anything and NEVER enforces the allow-list — it only
 * proposes. Authorization (RBAC allow-list), the write-confirmation gate, and
 * execution are the orchestrator's job (single choke point). The AI proposes;
 * the backend authorizes and acts.
 */

declare(strict_types=1);

require_once __DIR__ . '/PiiWall.php';
require_once __DIR__ . '/ai/AiProviderManager.php';
require_once __DIR__ . '/tools/ToolRegistry.php';

/** A proposed action for one turn (not yet authorized or executed). */
final class ToolPlan
{
    public function __construct(
        public readonly ?string $tool,   // null ⇒ just chat/reply
        public readonly array   $args,
        public readonly string  $say,
        public readonly string  $source, // 'deterministic' | 'ai' | 'none'
    ) {}
}

final class ToolPlanner
{
    public function __construct(
        private readonly PiiWall           $wall,
        private readonly AiProviderManager $manager,
        private readonly ToolRegistry      $registry,
    ) {}

    public function plan(AgentContext $ctx, string $rawMessage, array $hints = [], bool $aiAllowed = true): ToolPlan
    {
        // 1. System-first deterministic rules (no external AI). Always available.
        $det = $this->deterministic($ctx, $rawMessage);
        if ($det !== null) {
            return $det;
        }

        // 2. AI fallback — through the wall. If the admin's AI budget is paused,
        //    we do NOT call the external AI: common asks still work (above), and
        //    anything else degrades to a friendly nudge instead of spending.
        if (!$aiAllowed) {
            return new ToolPlan(null, [], 'Filhaal main seedhe requests samajh sakta hoon — jaise "painter chahiye" ya "kapde chahiye". 🙂', 'none');
        }

        // `hints` are memory-derived, non-identifying tokens (see AgentMemory::hints);
        // they help the AI personalise its proposal without ever seeing the user.
        return $this->viaAi($ctx, $rawMessage, $hints);
    }

    // ── Stage 1: deterministic (zero-cost, fully private) ────────────────────────
    private function deterministic(AgentContext $ctx, string $message): ?ToolPlan
    {
        $m = mb_strtolower($message);

        // Any role: phone verification → inline OTP widget.
        if (str_contains($m, 'otp') || str_contains($m, 'verify') || str_contains($m, 'verification')) {
            return new ToolPlan('verify_phone', [], 'Chaliye aapka number verify karte hain.', 'deterministic');
        }

        // Any role: "show my reminders" → list them (Phase 9). Must beat the vendor
        // set_reminder rule below, so it needs a LISTING verb, not just "reminder".
        if ((str_contains($m, 'reminder') || str_contains($m, 'yaad') || str_contains($m, 'remind'))
            && (str_contains($m, 'mere') || str_contains($m, 'meri') || str_contains($m, 'my ')
                || str_contains($m, 'list') || str_contains($m, 'dikha') || str_contains($m, 'show')
                || str_contains($m, 'pending') || str_contains($m, 'upcoming') || str_contains($m, 'batao')
                || str_contains($m, 'kaun') || str_contains($m, 'kitne'))) {
            return new ToolPlan('list_reminders', [], 'Yeh rahe aapke reminders ⏰', 'deterministic');
        }

        // Vendor-agent (Phase 6): onboard / status / reminders (both vendor roles).
        $isVendor = str_starts_with($ctx->role, 'vendor');
        if ($isVendor) {
            if (str_contains($m, 'listing') || str_contains($m, 'profile ban') || str_contains($m, 'register')
                || str_contains($m, 'onboard') || str_contains($m, 'shuru') || str_contains($m, 'sign up')
                || str_contains($m, 'join')) {
                return new ToolPlan('start_listing', [], 'Chaliye, kuch seekhna nahi — bas jawab dijiye 🙂', 'deterministic');
            }
            if (str_contains($m, 'status') || str_contains($m, 'kaisa chal') || str_contains($m, 'growth')
                || str_contains($m, 'kitne log') || str_contains($m, 'kitne customer') || str_contains($m, 'report')) {
                return new ToolPlan('my_status', [], 'Yeh raha aapka status 👇', 'deterministic');
            }
            if (str_contains($m, 'remind') || str_contains($m, 'reminder') || str_contains($m, 'yaad')) {
                $cadence = (str_contains($m, 'daily') || str_contains($m, 'roz')) ? 'daily' : 'weekly';
                $title   = str_contains($m, 'photo') ? 'Weekly work-photo upload' : 'Weekly business reminder';
                return new ToolPlan('set_reminder', ['title' => $title, 'cadence' => $cadence], 'Reminder set kar dete hain ⏰', 'deterministic');
            }
        }

        // Vendor: "upload (work) photo" → inline upload widget (checked before "work"→jobs).
        if ($isVendor
            && (str_contains($m, 'photo') || str_contains($m, 'foto') || str_contains($m, 'upload'))) {
            return new ToolPlan('upload_work_photo', [], 'Apni kaam ki photo upload kijiye 👇', 'deterministic');
        }

        // Vendor: "my jobs" / "kaam"
        if ($ctx->role === 'vendor_service'
            && (str_contains($m, 'job') || str_contains($m, 'kaam') || str_contains($m, 'work'))) {
            return new ToolPlan('list_my_jobs', [], 'Here are your jobs.', 'deterministic');
        }

        // Customer: a SERVICE category keyword ⇒ find vendors. Covers "painter
        // chahiye", "need a plumber", "plumber dikhao", "electrician chahiye", …
        if ($ctx->role === 'customer') {
            // Connections (Phase 8): "behen ke liye kapde order karo" → open the
            // people picker first; the chosen person becomes the delivery target.
            if ($this->mentionsRelation($m)
                && (str_contains($m, 'order') || str_contains($m, 'gift') || str_contains($m, 'bhej') || str_contains($m, 'ke liye') || $this->looksLikeShopping($m))) {
                return new ToolPlan('list_people', [], 'Kiske liye order karna hai? 👇', 'deterministic');
            }

            $category = $this->detectCategory($m);
            if ($category !== null) {
                return new ToolPlan(
                    'find_vendors',
                    ['category' => $category],
                    'Let me find ' . $category . ' options near you.',
                    'deterministic'
                );
            }

            // Customer COMMERCE intents (Phase 5). Cart / orders first (specific),
            // then a general shopping keyword ⇒ browse products with the query.
            if (str_contains($m, 'cart') || str_contains($m, 'basket') || str_contains($m, 'tokri')) {
                return new ToolPlan('view_cart', [], 'Yeh raha aapka cart 🛒', 'deterministic');
            }
            if (str_contains($m, 'my order') || str_contains($m, 'mere order') || str_contains($m, 'meri order') || str_contains($m, 'order status') || str_contains($m, 'order kahan')) {
                return new ToolPlan('list_orders', [], 'Yeh rahi aapki recent orders 📦', 'deterministic');
            }
            if ($this->looksLikeShopping($m)) {
                return new ToolPlan('browse_products', ['query' => $this->shoppingQuery($message)], 'Dekhiye aapke area ke products 🛍️', 'deterministic');
            }
        }

        return null; // fall through to the AI
    }

    /** Does the message name a saved-person relationship? */
    private function mentionsRelation(string $lowerMessage): bool
    {
        $m = ' ' . $lowerMessage . ' ';
        foreach ([' behen', ' bahan', ' bhai', ' sister', ' brother', ' friend', ' dost',
                  ' papa', ' mummy', ' maa', ' wife', ' husband', ' beta', ' beti', ' saved'] as $w) {
            if (str_contains($m, $w)) {
                return true;
            }
        }
        return false;
    }

    /** Does the message read like a shopping intent? */
    private function looksLikeShopping(string $lowerMessage): bool
    {
        foreach (['kapde', 'kapda', 'clothes', 'kurta', 'kurti', 'shirt', 'saree', 'jeans',
                  'shoes', 'juta', 'grocery', 'saaman', 'saman', 'product', 'shop', 'kharid',
                  'buy', 'khareed', 'gift', 'diwali', 'kharidna', 'shopping'] as $word) {
            if (str_contains($lowerMessage, $word)) {
                return true;
            }
        }
        return false;
    }

    /** A light free-text query for the storefront search (drops filler words). */
    private function shoppingQuery(string $message): string
    {
        $stop = ['chahiye', 'chaiye', 'mujhe', 'ek', 'ke', 'liye', 'aa', 'rahi', 'raha', 'hai',
                 'dikhao', 'need', 'want', 'the', 'buy', 'some', 'and', 'for'];
        $words = preg_split('/\s+/', mb_strtolower(trim($message))) ?: [];
        $kept  = array_values(array_filter($words, static fn ($w) => $w !== '' && !in_array($w, $stop, true) && mb_strlen($w) >= 3));
        return trim(implode(' ', array_slice($kept, 0, 4)));
    }

    /** First recognised service category word in the message, else null. */
    private function detectCategory(string $lowerMessage): ?string
    {
        foreach (['painter', 'paint', 'painting', 'plumber', 'plumbing', 'nal',
                  'electrician', 'electric', 'bijli', 'waterproof', 'waterproofing',
                  'seepage', 'cctv', 'camera'] as $word) {
            if (str_contains($lowerMessage, $word)) {
                return ServiceCategoryMap::normalize($word);
            }
        }
        return null;
    }

    // ── Stage 2: AI fallback (through the privacy wall) ──────────────────────────
    private function viaAi(AgentContext $ctx, string $rawMessage, array $hints = []): ToolPlan
    {
        $system   = $this->systemPrompt($ctx->role, $hints);
        $capNames = $this->registry->toolNamesForRole($ctx->role);

        // PRIVACY WALL: raw message is scrubbed before anything leaves; the
        // anonymized hints are allow-listed + tripwire-scanned by the wall too.
        $prompt = $this->wall->sanitize($ctx, $rawMessage, $system, $capNames, 'understand', $hints);
        $resp   = $this->manager->complete($prompt, 'understand');

        if (!$resp->success) {
            return new ToolPlan(null, [], $this->fallbackNudge(), 'none');
        }

        // The mock/echo provider is a keyless DEV + failover stand-in — its
        // diagnostic echo ("[mock:understand] I understood: …") must NEVER surface
        // to a real user (audit M3). If it resolved (real key down/absent), degrade
        // to a clean deterministic nudge instead of echoing.
        if ($resp->provider === 'mock' || str_starts_with(ltrim($resp->text), '[mock:')) {
            return new ToolPlan(null, [], $this->fallbackNudge(), 'none');
        }

        $parsed = $this->parseJson($resp->text);
        if ($parsed === null) {
            // A real provider replied but not as a tool proposal — surface its (safe,
            // rehydrated-for-owner) sentence; fall back to a nudge if it is empty.
            return new ToolPlan(null, [], $resp->text !== '' ? $resp->text : $this->fallbackNudge(), 'ai');
        }

        $tool = isset($parsed['tool']) && is_string($parsed['tool']) ? trim($parsed['tool']) : '';
        $tool = ($tool === '' || strtolower($tool) === 'none') ? null : $tool;
        $args = is_array($parsed['args'] ?? null) ? $parsed['args'] : [];
        $say  = is_string($parsed['say'] ?? null) ? $parsed['say'] : '';

        return new ToolPlan($tool, $args, $say, 'ai');
    }

    /** A clean, deterministic "how can I help" nudge — never a provider's raw text (audit M3). */
    private function fallbackNudge(): string
    {
        return 'Main aapki madad kar sakta hoon — jaise "painter chahiye", "kapde chahiye", ya "meri booking dikhao". Bataiye kya chahiye? 🙂';
    }

    /** Strict-JSON instruction + the role's tool catalog (public, non-sensitive). */
    private function systemPrompt(string $role, array $hints = []): string
    {
        $catalog = json_encode($this->registry->catalogForRole($role), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Non-identifying personalization hints (safe to send — no identity/data).
        $hintLine = $hints
            ? "\nNon-identifying context about this anonymous user (use to personalise, "
              . "never ask for identity): " . implode(', ', $hints) . "\n"
            : "";
        return "You are WorkToGo's assistant. Decide which ONE tool best serves the user, "
            . "using ONLY the tools in this catalog (do not invent tools):\n"
            . $catalog . $hintLine . "\n"
            . "Reply with STRICT JSON only, no prose, in exactly this shape:\n"
            . '{"tool":"<tool name or none>","args":{...},"say":"<one short friendly sentence>"}' . "\n"
            . "Put only arguments that appear in the chosen tool's args. If nothing fits, use "
            . '"tool":"none". Keep any masked tags like <PHONE_1> unchanged.';
    }

    /** Extract the first JSON object from the model's reply. */
    private function parseJson(string $text): ?array
    {
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }
}
