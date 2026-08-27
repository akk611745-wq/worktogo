<?php
/**
 * AgentContext.php — WorkToGo Agent Layer (foundation)
 * ----------------------------------------------------------------------------
 * The INTERNAL, ours-only identity + situation envelope for one agent turn.
 *
 * This object is how OUR system knows who the user is. It carries the real
 * user_id, role, city_id, session and request ids. It is the counterpart to the
 * privacy wall: the wall reads ONLY the coarse, non-identifying getters from it
 * to build the tiny "understanding" envelope that may leave the building, and
 * uses the real user_id ONLY to write our own internal audit row — never to
 * build anything a provider sees.
 *
 * ── The rule that makes the wall work ────────────────────────────────────────
 *   • Identifying fields (userId, cityId, sessionId) are used INSIDE our system
 *     only. The wall never serialises this object; it never puts userId/cityId
 *     into a SanitizedPrompt. See PiiWall::sanitize().
 *   • Coarse fields (role, language, period) are the ONLY things allowed into the
 *     outbound meta, and even those pass the wall's deny-by-default allow-list.
 *   • City is deliberately WITHHELD from providers in this foundation — the exact
 *     city is an identifying signal we don't need to send for language
 *     understanding. cityId stays internal for later action routing.
 *
 * Register-gate: fromAuth() is only ever built from a verified JWT payload
 * (AuthMiddleware::require()), so an AgentContext cannot exist for an
 * unregistered/anonymous caller.
 */

declare(strict_types=1);

final class AgentContext
{
    /** Languages we accept as a coarse, non-identifying hint (BCP-47 subset). */
    private const ALLOWED_LANGS = [
        'hi', 'en', 'ta', 'te', 'ml', 'kn', 'mr', 'bn', 'gu', 'pa',
        'ur', 'or', 'as', 'ne', 'si', 'fr', 'de', 'es', 'pt', 'ar',
    ];

    private const ALLOWED_ROLES = [
        'customer', 'vendor_service', 'vendor_shopping', 'delivery', 'admin',
    ];

    private function __construct(
        public readonly int     $userId,
        public readonly string  $role,
        public readonly string  $language,
        public readonly int     $cityId,       // INTERNAL — never sent to a provider
        public readonly string  $period,       // morning|afternoon|evening|night
        public readonly string  $requestId,
        public readonly ?string $sessionId,
    ) {}

    /**
     * Build the internal context from a VERIFIED JWT payload + request body.
     *
     * @param array    $jwtPayload Decoded, verified JWT (must carry user_id, role).
     * @param array    $body       Parsed request body (for an optional language hint).
     * @param PDO|null $db          Optional — resolves the user's city_id (internal).
     */
    public static function fromAuth(array $jwtPayload, array $body = [], ?PDO $db = null): self
    {
        $userId = (int) ($jwtPayload['user_id'] ?? 0);
        if ($userId <= 0) {
            // Register-gate is enforced upstream (AuthMiddleware); this is a defensive echo.
            throw new AgentSecurityException('AgentContext requires an authenticated user.');
        }

        $role = (string) ($jwtPayload['role'] ?? 'customer');
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            $role = 'customer';
        }

        $lang = strtolower(trim((string) ($body['language'] ?? 'en')));
        if (!in_array($lang, self::ALLOWED_LANGS, true)) {
            $lang = 'en';
        }

        // City is resolved for INTERNAL use only (action routing in a later phase).
        // Fully defensive: if the cities table/migration is absent, cityId is 0.
        $cityId = 0;
        if ($db instanceof PDO) {
            try {
                require_once __DIR__ . '/../helpers/CityResolver.php';
                $cityId = CityResolver::resolveFromUser($db, $userId);
            } catch (\Throwable $e) {
                $cityId = 0;
            }
        }

        return new self(
            userId:    $userId,
            role:      $role,
            language:  $lang,
            cityId:    $cityId,
            period:    self::periodNow(),
            requestId: self::generateRequestId(),
            sessionId: isset($body['session_id']) && is_string($body['session_id'])
                          ? substr(trim($body['session_id']), 0, 64)
                          : null,
        );
    }

    /**
     * The ONLY fields allowed to describe the user to an external provider.
     * Deliberately coarse and non-identifying. The wall re-checks this against
     * its own allow-list (deny-by-default) — this method is a convenience, not
     * the authority.
     */
    public function coarseMeta(): array
    {
        return [
            'role'   => $this->role,      // 'customer' | 'vendor_service' | …  (a category, not a person)
            'lang'   => $this->language,  // 'hi' | 'en' | …
            'period' => $this->period,    // time-of-day bucket (IST)
        ];
    }

    private static function periodNow(): string
    {
        try {
            $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('G');
        } catch (\Throwable $e) {
            $hour = (int) date('G');
        }
        return match (true) {
            $hour >= 5  && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 17 => 'afternoon',
            $hour >= 17 && $hour < 21 => 'evening',
            default                    => 'night',
        };
    }

    private static function generateRequestId(): string
    {
        return 'agt-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    }
}
