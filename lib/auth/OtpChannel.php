<?php
/**
 * OtpChannel.php  —  WorkToGo Smart Auth Layer (OTP abstraction)
 * ----------------------------------------------------------------------------
 * The single contract every OTP delivery channel (MSG91 SMS, a 2nd SMS provider,
 * WhatsApp, …) implements. The auth flow talks to THIS interface, never to a
 * concrete provider — adding/swapping a channel = one adapter + a config line.
 *
 * This mirrors lib/payment/PaymentGateway.php on purpose: the same "contract +
 * config-driven manager + primary→secondary failover + isConfigured() gate"
 * shape the payment layer uses, applied to OTP SEND. Our system USES providers;
 * it never DEPENDS on one — if a provider is down or not approved, another
 * configured channel still delivers, so login never goes down for one vendor.
 *
 * ── Normalised send() result (plain array, array-first house style) ───────────
 *   [
 *     'success'    => bool,          // was the OTP handed to the provider OK?
 *     'channel'    => string,        // $this->name()
 *     'http_code'  => int,           // provider HTTP status (0 = transport error)
 *     'retryable'  => bool,          // true ⇒ provider down/erroring ⇒ failover allowed
 *     'error'      => string|null,   // short, non-sensitive error (NEVER the OTP)
 *   ]
 *
 * SECURITY: an adapter MUST NEVER log or echo the OTP or the outgoing payload
 * (which contains it). It returns only metadata. This matches the M1 hardening in
 * the legacy send-otp.php (plaintext OTPs must never reach a log).
 */

interface OtpChannel
{
    /** Canonical lowercase channel name, e.g. 'msg91' | 'whatsapp'. */
    public function name(): string;

    /** Which delivery kinds this channel serves, e.g. ['sms'] or ['whatsapp']. */
    public function kinds(): array;

    /**
     * Are real, non-placeholder credentials present for this channel? Used both as
     * a send guard AND as a failover-eligibility / method-availability check — we
     * never offer or fail over to a channel that isn't actually configured.
     */
    public function isConfigured(): bool;

    /**
     * Deliver $otp to $phone. MUST NOT throw for ordinary provider/network
     * failures — return ['success'=>false,'retryable'=>bool,…] so the manager can
     * decide on failover. Never logs/echoes the OTP.
     *
     * @param string $phone E.164-ish phone (as validated upstream).
     * @param string $otp   The plaintext OTP (already generated + hashed in DB).
     * @param array  $ctx   Optional: ['expiry_seconds'=>int, 'app_name'=>string].
     * @return array         Normalised result (see file header).
     */
    public function send(string $phone, string $otp, array $ctx = []): array;
}
