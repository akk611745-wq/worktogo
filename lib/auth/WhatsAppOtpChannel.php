<?php
/**
 * WhatsAppOtpChannel.php  —  WorkToGo Smart Auth Layer :: WhatsApp OTP (STUB / slot)
 * ----------------------------------------------------------------------------
 * A clean, drop-in slot for WhatsApp OTP (WhatsApp Cloud API authentication
 * template, or a BSP like Gupshup/Interakt). Full OtpChannel contract, guarded
 * OFF until real credentials + a template are set and validated. It never sends
 * — and is never OFFERED as a login method — until deliberately enabled in
 * config/auth.channels.php. That is the founder's "only configured methods are
 * shown": if WhatsApp isn't connected, it simply isn't offered.
 *
 * To turn it on (a later, deliberate task):
 *   1. .env: WHATSAPP_TOKEN, WHATSAPP_PHONE_ID (Cloud API), WHATSAPP_OTP_TEMPLATE
 *      (an approved authentication template with a one-time-code button).
 *   2. Implement send() against
 *        POST https://graph.facebook.com/v20.0/<WHATSAPP_PHONE_ID>/messages
 *        body: { messaging_product:'whatsapp', to:<phone>, type:'template',
 *                template:{ name:<WHATSAPP_OTP_TEMPLATE>, language:{code:'en'},
 *                  components:[{type:'body',parameters:[{type:'text',text:<otp>}]},
 *                    {type:'button',sub_type:'url',index:'0',
 *                     parameters:[{type:'text',text:<otp>}]}] } }
 *      classifying HTTP status → retryable exactly like Msg91SmsChannel.
 *   3. Flip isConfigured() to check the creds, enable 'whatsapp' in config, and
 *      set routing.whatsapp (primary 'whatsapp', secondary 'msg91' for SMS
 *      fallback). No auth-flow or manager change needed.
 *
 * Until then send() returns a retryable failure so a WhatsApp route falls back to
 * its SMS secondary, and isConfigured()==false keeps whatsapp_otp OUT of the
 * offered methods.
 */

require_once __DIR__ . '/OtpChannel.php';

final class WhatsAppOtpChannel implements OtpChannel
{
    public function name(): string { return 'whatsapp'; }

    public function kinds(): array { return ['whatsapp']; }

    /** STUB: intentionally false until WhatsApp creds + an approved template exist. */
    public function isConfigured(): bool { return false; }

    public function send(string $phone, string $otp, array $ctx = []): array
    {
        return [
            'success'   => false,
            'channel'   => $this->name(),
            'http_code' => 0,
            'retryable' => true, // retryable ⇒ a WhatsApp route falls back to SMS
            'error'     => 'whatsapp channel is a stub — not yet implemented/enabled',
        ];
    }
}
