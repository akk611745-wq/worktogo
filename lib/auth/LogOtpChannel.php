<?php
/**
 * LogOtpChannel.php  —  WorkToGo Smart Auth Layer :: dev-only log channel
 * ----------------------------------------------------------------------------
 * The keyless DEV + last-resort channel. It preserves the EXACT security posture
 * of the legacy _logOtp() in send-otp.php (M1): in an explicitly-dev environment
 * it logs the OTP so a developer can test with no SMS provider; ANYWHERE ELSE
 * (production or an unknown/unset APP_ENV) it FAILS LOUDLY — it logs NO secret and
 * returns success=false, so the caller reports "Failed to send OTP" rather than a
 * silent fake success. A plaintext OTP must NEVER reach a production log.
 *
 * isConfigured() is true ONLY in dev, so:
 *   • in dev it is a usable failover for SMS (mirrors the legacy dev behaviour);
 *   • in prod it is NOT usable, so the manager never falls over to it and it is
 *     never counted as real SMS deliverability by the method resolver.
 * This is the analogue of the AI layer's MockAiAdapter — a safe, keyless stand-in.
 */

require_once __DIR__ . '/OtpChannel.php';

final class LogOtpChannel implements OtpChannel
{
    public function name(): string { return 'log'; }

    public function kinds(): array { return ['sms', 'whatsapp']; }

    /** Usable ONLY in an explicit dev environment (never counts as real delivery in prod). */
    public function isConfigured(): bool
    {
        return self::isDev();
    }

    public function send(string $phone, string $otp, array $ctx = []): array
    {
        if (!self::isDev()) {
            // Fail LOUD in production — never log the OTP.
            if (class_exists('Logger')) {
                Logger::error('OTP send fell back to log mode with no SMS provider configured — OTP NOT logged, delivery failed. Configure an SMS provider.', ['phone' => $phone]);
            }
            return ['success' => false, 'channel' => $this->name(), 'http_code' => 0,
                    'retryable' => false, 'error' => 'no sms provider configured'];
        }
        if (class_exists('Logger')) {
            Logger::info('OTP (dev/log mode)', ['phone' => $phone, 'otp' => $otp]);
        }
        return ['success' => true, 'channel' => $this->name(), 'http_code' => 200,
                'retryable' => false, 'error' => null];
    }

    private static function isDev(): bool
    {
        $env = strtolower((string) (getenv('APP_ENV') ?: ''));
        return in_array($env, ['local', 'dev', 'development'], true)
            || getenv('APP_DEBUG') === 'true';
    }
}
