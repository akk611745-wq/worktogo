<?php
/**
 * EngineClient.php — WorkToGo Agent Layer :: the ONLY way tools reach the backend
 * ----------------------------------------------------------------------------
 * A tool never talks to the database or an engine's internals. It asks an
 * EngineClient to call a REAL backend endpoint — the same HTTP contract a normal
 * client hits — so the engine's own routing, RBAC, validation, city-scoping,
 * ownership checks and writes all run UNTOUCHED. The agent proposes; the backend
 * authorizes and executes.
 *
 * Why HTTP (not include): the service engine is procedural (top-level functions)
 * and its Response helper either exit()s or throws under HEART_INTERNAL_INC —
 * re-including it in-process would redeclare functions and pollute the request.
 * An internal HTTP sub-request (mirroring heart/pipeline/EngineLoader's http
 * path) isolates every call in the engine's own request, forwards the user's
 * JWT so the engine authorizes the REAL user, and never edits the engine.
 *
 * Privacy note: engine responses carry OUR real data (vendor names, prices, ids).
 * That data flows engine → agent → the user who owns it. It is NEVER sent to an
 * external AI — the AI only ever saw the sanitized language (see PiiWall).
 *
 * Implementations:
 *   • HttpEngineClient — production. cURL to APP_URL + path, Bearer <user JWT>.
 *   • (tests inject a mock EngineClient that returns canned responses.)
 */

declare(strict_types=1);

/** Normalised result of one backend call. Never carries an exception upward. */
final class EngineResult
{
    private function __construct(
        public readonly bool    $ok,
        public readonly int     $status,
        public readonly ?array  $data,
        public readonly ?string $error,
    ) {}

    public static function ok(int $status, ?array $data): self
    {
        return new self(true, $status, $data, null);
    }

    public static function fail(string $error, int $status = 0, ?array $data = null): self
    {
        return new self(false, $status, $data, $error);
    }
}

interface EngineClient
{
    /** GET a backend endpoint. Never throws — returns EngineResult. */
    public function get(string $path, array $query = []): EngineResult;

    /** POST a backend endpoint. Never throws — returns EngineResult. */
    public function post(string $path, array $body = []): EngineResult;

    /** PATCH a backend endpoint. Never throws — returns EngineResult. */
    public function patch(string $path, array $body = []): EngineResult;
}

/**
 * Production EngineClient: an internal HTTP sub-request to our own app, carrying
 * the caller's real JWT so the engine authorizes the real user. Never throws.
 */
final class HttpEngineClient implements EngineClient
{
    private const CONNECT_TIMEOUT_S = 2;
    private const READ_TIMEOUT_S    = 8;
    private const MAX_BYTES         = 1_048_576; // 1 MB

    private string $baseUrl;

    public function __construct(
        private readonly ?string $bearerToken,
        ?string $baseUrl = null
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string) (getenv('APP_URL') ?: ''), '/');
    }

    public function get(string $path, array $query = []): EngineResult
    {
        $qs = $query ? ('?' . http_build_query($query)) : '';
        return $this->send('GET', $path . $qs, null);
    }

    public function post(string $path, array $body = []): EngineResult
    {
        return $this->send('POST', $path, $body);
    }

    public function patch(string $path, array $body = []): EngineResult
    {
        return $this->send('PATCH', $path, $body);
    }

    private function send(string $method, string $path, ?array $body): EngineResult
    {
        if ($this->baseUrl === '') {
            return EngineResult::fail('engine base URL (APP_URL) not configured', 0);
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = ['Accept: application/json'];
        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT_S,
            CURLOPT_TIMEOUT         => self::READ_TIMEOUT_S,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw   = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return EngineResult::fail("engine unreachable (curl {$errno}: {$err})", 0);
        }
        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) {
            return EngineResult::fail('engine response empty or too large', $code);
        }

        $decoded = json_decode($raw, true);
        $data    = is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;

        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded)
                ? ($decoded['message'] ?? ($decoded['error']['message'] ?? "HTTP {$code}"))
                : "HTTP {$code}";
            return EngineResult::fail((string) $msg, $code, is_array($data) ? $data : null);
        }

        return EngineResult::ok($code, is_array($data) ? $data : null);
    }
}
