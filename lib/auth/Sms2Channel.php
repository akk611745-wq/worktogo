<?php
/**
 * Sms2Channel.php  —  WorkToGo Smart Auth Layer :: 2nd SMS provider (STUB / slot)
 * ----------------------------------------------------------------------------
 * A clean, drop-in slot for a SECOND SMS provider (Fast2SMS / Twilio / Gupshup /
 * Kaleyra / …) so SMS OTP has real failover, not a single dependency. The full
 * OtpChannel contract is implemented, but every send path is guarded OFF
 * (isConfigured() == false) until real credentials are set AND the transport is
 * filled in and validated. It never sends until deliberately enabled in
 * config/auth.channels.php. Same discipline as the payment layer's RazorpayAdapter.
 *
 * To turn it on (a later, deliberate task):
 *   1. .env: SMS2_PROVIDER (e.g. 'fast2sms') + its key(s), e.g. FAST2SMS_KEY.
 *   2. Implement send() against that provider's OTP/SMS API (see the legacy
 *      _sendFast2Sms / _sendTwilio in send-otp.php for working reference calls),
 *      classifying HTTP status → retryable exactly like Msg91SmsChannel.
 *   3. Flip isConfigured() to check the key, enable 'sms2' in config, and point
 *      routing.sms.secondary => 'sms2'. No auth-flow or manager change needed.
 *
 * Until then send() returns a retryable failure so the manager simply moves on —
 * never a hard error, never a silent success.
 */

require_once __DIR__ . '/OtpChannel.php';

final class Sms2Channel implements OtpChannel
{
    public function name(): string { return 'sms2'; }

    public function kinds(): array { return ['sms']; }

    /** STUB: intentionally false until a real 2nd provider is wired + validated. */
    public function isConfigured(): bool { return false; }

    public function send(string $phone, string $otp, array $ctx = []): array
    {
        return [
            'success'   => false,
            'channel'   => $this->name(),
            'http_code' => 0,
            'retryable' => true, // retryable ⇒ the manager falls past this stub
            'error'     => 'sms2 channel is a stub — not yet implemented/enabled',
        ];
    }
}
