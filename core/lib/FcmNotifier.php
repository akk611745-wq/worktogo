<?php
// ============================================================
//  WorkToGo CORE — FCM Push Notifier (HTTP v1 API, pure PHP)
//  Sends browser push notifications via Firebase Cloud Messaging.
//  Auth: Google OAuth2 service-account JWT bearer flow — no SDK,
//  same hand-rolled style as core/helpers/JWT.php and the SMS
//  dispatch in heart/api/auth/send-otp.php.
// ============================================================

class FcmNotifier
{
    private static ?string $accessToken = null;

    // ── Public: notify every token belonging to one user ────────
    public static function notifyUser(PDO $db, int $userId, string $title, string $body, array $data = []): void
    {
        self::insertNotification($db, $userId, $title, $body, $data);
        $tokens = PushSubscription::tokensForUser($db, $userId);
        self::sendToTokens($db, $tokens, $title, $body, $data);
    }

    // ── Public: notify every token belonging to a role (e.g. admin) ─
    public static function notifyRole(PDO $db, string $role, string $title, string $body, array $data = []): void
    {
        $stmt = $db->prepare("SELECT id FROM users WHERE role = ? AND deleted_at IS NULL");
        $stmt->execute([$role]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            self::insertNotification($db, (int)$userId, $title, $body, $data);
        }

        $tokens = PushSubscription::tokensForRole($db, $role);
        self::sendToTokens($db, $tokens, $title, $body, $data);
    }

    // ── In-app notification feed row (bell icon), independent of push delivery ─
    private static function insertNotification(PDO $db, int $userId, string $title, string $body, array $data): void
    {
        try {
            $db->prepare(
                "INSERT INTO notifications (user_id, title, body, type, is_read) VALUES (?, ?, ?, ?, 0)"
            )->execute([$userId, $title, $body, $data['type'] ?? 'general']);
        } catch (PDOException $e) {
            Logger::error('Failed to insert notification row', ['error' => $e->getMessage()]);
        }
    }

    // ── Send the same notification to a batch of tokens ─────────
    private static function sendToTokens(PDO $db, array $tokens, string $title, string $body, array $data): void
    {
        if (empty($tokens)) {
            return;
        }

        $accessToken = self::getAccessToken();
        if ($accessToken === null) {
            // No service-account configured (e.g. local/dev) — log instead of sending.
            Logger::info('FCM (log mode, no service account)', ['title' => $title, 'body' => $body, 'tokens' => count($tokens)]);
            return;
        }

        foreach ($tokens as $token) {
            self::sendToToken($db, $token, $accessToken, $title, $body, $data);
        }
    }

    // ── Send to a single token; clean up if FCM reports it dead ─
    private static function sendToToken(PDO $db, string $token, string $accessToken, string $title, string $body, array $data): bool
    {
        $projectId = getenv('FCM_PROJECT_ID') ?: '';
        if ($projectId === '') {
            Logger::error('FCM_PROJECT_ID not configured');
            return false;
        }

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
                'webpush' => [
                    'notification' => [
                        'icon' => (getenv('APP_URL') ?: '') . '/assets/icon-192.png',
                    ],
                    'fcm_options' => array_filter([
                        'link' => $data['url'] ?? null,
                    ]),
                ],
                'data' => array_map('strval', $data),
            ],
        ];

        [$code, $responseBody] = self::httpPostJson(
            "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
            $payload,
            ["Authorization: Bearer {$accessToken}"]
        );

        if ($code >= 200 && $code < 300) {
            return true;
        }

        $decoded = json_decode((string)$responseBody, true);
        $errorStatus = $decoded['error']['status'] ?? '';
        if (in_array($errorStatus, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            // Token is no longer valid (uninstalled, expired, malformed) — stop trying it.
            PushSubscription::remove($db, $token);
        }

        Logger::error('FCM send failed', ['status_code' => $code, 'response' => $responseBody]);
        return false;
    }

    // ── Get (and cache for this request) a Google OAuth2 access token ─
    private static function getAccessToken(): ?string
    {
        if (self::$accessToken !== null) {
            return self::$accessToken;
        }

        $path = getenv('FCM_SERVICE_ACCOUNT_PATH') ?: '';
        if ($path === '') {
            return null;
        }
        if (!str_starts_with($path, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            $path = (defined('SYSTEM_ROOT') ? SYSTEM_ROOT : dirname(__DIR__, 2)) . '/' . $path;
        }
        if (!is_file($path)) {
            Logger::error('FCM service account file not found', ['path' => $path]);
            return null;
        }

        $account = json_decode((string)file_get_contents($path), true);
        if (!is_array($account) || empty($account['private_key']) || empty($account['client_email'])) {
            Logger::error('FCM service account file malformed');
            return null;
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $segments = self::b64url(json_encode($header)) . '.' . self::b64url(json_encode($claims));
        $signature = '';
        $ok = openssl_sign($segments, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok) {
            Logger::error('FCM JWT signing failed');
            return null;
        }
        $assertion = $segments . '.' . self::b64url($signature);

        [$code, $body] = self::httpPostForm($claims['aud'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]);

        if ($code < 200 || $code >= 300) {
            Logger::error('FCM OAuth2 token request failed', ['status_code' => $code, 'response' => $body]);
            return null;
        }

        $decoded = json_decode((string)$body, true);
        self::$accessToken = $decoded['access_token'] ?? null;
        return self::$accessToken;
    }

    // ── HTTP helpers (cURL, mirrors send-otp.php conventions) ───
    private static function httpPostJson(string $url, array $payload, array $headers = []): array
    {
        $headers[] = 'Content-Type: application/json';
        return self::httpPost($url, json_encode($payload), $headers);
    }

    private static function httpPostForm(string $url, array $fields): array
    {
        return self::httpPost($url, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
    }

    private static function httpPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $response];
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
