<?php
// ============================================================
//  WorkToGo CORE — JWT Helper (Pure PHP, no dependencies)
//  Implements HS256 signed JSON Web Tokens.
// ============================================================

class JWT
{
    // ── Encode ───────────────────────────────────────────────
    public static function encode(array $payload, string $secret, int $expiresIn = 0): string
    {
        $now = time();

        $payload['iat'] = $now;
        $payload['nbf'] = $now;

        if ($expiresIn > 0) {
            $payload['exp'] = $now + $expiresIn;
        }

        $header    = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body      = self::b64url(json_encode($payload));
        $signature = self::b64url(hash_hmac('sha256', "{$header}.{$body}", $secret, true));

        return "{$header}.{$body}.{$signature}";
    }

    // ── Decode ───────────────────────────────────────────────
    // Returns payload array on success, null on any failure.
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $body, $sig] = $parts;

        // Verify signature (constant-time compare prevents timing attacks)
        $expected = self::b64url(hash_hmac('sha256', "{$header}.{$body}", $secret, true));

        if (!hash_equals($expected, $sig)) {
            return null;
        }

        // Decode payload
        $payload = json_decode(self::b64urlDecode($body), true);

        if (!is_array($payload)) {
            return null;
        }

        $now = time();

        // Not before check
        if (isset($payload['nbf']) && $payload['nbf'] > $now) {
            return null;
        }

        // Expiry check
        if (isset($payload['exp']) && $payload['exp'] < $now) {
            return null;
        }

        return $payload;
    }

    // ── Extract payload WITHOUT verifying (for logging/debug) ─
    public static function unsafeDecode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        return json_decode(self::b64urlDecode($parts[1]), true) ?: null;
    }

    // ── Get expiry timestamp from token ──────────────────────
    public static function expiresAt(string $token): ?int
    {
        $payload = self::unsafeDecode($token);
        return $payload['exp'] ?? null;
    }

    // ── Base64URL encode ─────────────────────────────────────
    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ── Base64URL decode ─────────────────────────────────────
    private static function b64urlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) + (4 - strlen($data) % 4) % 4, '=');
        return base64_decode($padded);
    }
}
