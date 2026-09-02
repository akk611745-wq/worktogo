<?php
/**
 * Msg91SmsChannel.php  —  WorkToGo Smart Auth Layer :: MSG91 SMS OTP (REAL)
 * ----------------------------------------------------------------------------
 * The one REAL, working channel — a thin, faithful wrapper around the EXISTING
 * MSG91 v5 OTP send used by heart/api/auth/send-otp.php (same endpoint, payload,
 * phone-cleaning and env vars). It adds only the retryable-vs-terminal failure
 * classification the manager needs for failover — the analogue of CashfreeAdapter
 * in the payment layer.
 *
 * Credentials (unchanged from the legacy path):
 *   • MSG91_AUTH_KEY     — required
 *   • MSG91_TEMPLATE_ID  — required
 *   • MSG91_SENDER_ID    — optional (default 'WRKGO')
 *
 * SECURITY: never logs the OTP or the outgoing payload (M1). On an HTTP error it
 * logs only the status code — never the body (which could echo inputs).
 */

require_once __DIR__ . '/OtpChannel.php';

final class Msg91SmsChannel implements OtpChannel
{
    private const ENDPOINT = 'https://api.msg91.com/api/v5/otp';

    public function name(): string { return 'msg91'; }

    public function kinds(): array { return ['sms']; }

    public function isConfigured(): bool
    {
        $key = (string) (getenv('MSG91_AUTH_KEY') ?: '');
        $tpl = (string) (getenv('MSG91_TEMPLATE_ID') ?: '');
        return $key !== '' && $tpl !== ''
            && stripos($key, 'placeholder') === false
            && stripos($key, 'your_') === false;
    }

    public function send(string $phone, string $otp, array $ctx = []): array
    {
        if (!$this->isConfigured()) {
            // Treat "no creds" as retryable so the manager can fail over to a
            // configured secondary instead of hard-failing.
            return $this->result(false, 0, true, 'msg91 not configured');
        }

        // Exactly the legacy phone-cleaning: strip non-digits, drop a leading 91,
        // then prefix 91 (MSG91 wants the country-coded mobile).
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $cleanPhone = preg_replace('/^91/', '', (string) $cleanPhone);

        $payload = json_encode([
            'template_id' => (string) getenv('MSG91_TEMPLATE_ID'),
            'mobile'      => '91' . $cleanPhone,
            'otp'         => $otp,
            'sender'      => getenv('MSG91_SENDER_ID') ?: 'WRKGO',
        ]);

        [$code, $errno] = $this->post(self::ENDPOINT, (string) $payload, [
            'authkey: ' . (string) getenv('MSG91_AUTH_KEY'),
            'content-type: application/json',
        ]);

        if ($errno !== 0) {
            // Transport error (network/DNS/TLS/timeout) → the provider is
            // unreachable → retryable.
            return $this->result(false, 0, true, 'msg91 transport error');
        }
        if ($code >= 200 && $code < 300) {
            return $this->result(true, $code, false, null);
        }

        // Classify like the payment layer: 5xx/429/401/403 = down/erroring/creds
        // → retryable (fail over); 4xx (bad number/template) = terminal (would fail
        // identically on any SMS provider) → do not fail over.
        $retryable = ($code >= 500 || $code === 429 || $code === 401 || $code === 403);
        if (class_exists('Logger')) {
            Logger::warning('MSG91 OTP send HTTP error', ['code' => $code]); // status only — never body/OTP
        }
        return $this->result(false, $code, $retryable, 'msg91 http ' . $code);
    }

    /** cURL POST — returns [httpCode, curlErrno]. Never throws, never logs the body. */
    private function post(string $url, string $payload, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        return [$code, $errno];
    }

    private function result(bool $ok, int $code, bool $retryable, ?string $error): array
    {
        return ['success' => $ok, 'channel' => $this->name(), 'http_code' => $code,
                'retryable' => $retryable, 'error' => $error];
    }
}
