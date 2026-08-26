<?php
/**
 * PaymentGateway.php  —  WorkToGo Payment Abstraction Layer
 * ----------------------------------------------------------------------------
 * The single contract every payment gateway (Cashfree, Razorpay, …) implements.
 *
 * The whole system talks to THIS interface, never to a concrete gateway. Adding
 * or swapping a gateway = write one adapter + a config line. No orchestration,
 * webhook, or engine code changes.
 *
 * The three money-critical operations (per founder intent):
 *   createOrder()   — server-authoritative order/session creation
 *   verifyWebhook() — signature verification of an inbound webhook (per-gateway)
 *   parseWebhook()  — normalise a verified webhook into one engine-agnostic shape
 *
 * ── Normalised shapes (plain arrays, matching the codebase's array-first style)
 *
 * createOrder($request) input:
 *   [
 *     'order_ref'  => string,   // OUR internal, gateway-neutral reference (WTG_… / WTG_B…)
 *     'amount'     => float,     // server-verified amount in major units (INR)
 *     'currency'   => string,    // 'INR'
 *     'customer'   => ['id','name','email','phone'],
 *     'return_url' => string,    // absolute https URL (customer redirect)
 *     'notify_url' => string,    // absolute https webhook URL (WITHOUT ?gateway=…; adapter appends it)
 *     'tags'       => [string=>string],  // e.g. internal_order_id, reference_type ('order'|'booking')
 *   ]
 *
 * createOrder() return:
 *   [
 *     'success'            => bool,
 *     'gateway'            => string,        // $this->name()
 *     'gateway_order_id'   => string|null,   // id to correlate the webhook back to us
 *     'payment_session_id' => string|null,   // handed to the client SDK (the ONE payable session)
 *     'order_token'        => string|null,
 *     'mode'               => string,        // 'production' | 'sandbox' (client SDK mode)
 *     'http_code'          => int,
 *     'retryable'          => bool,          // true ⇒ infra/gateway-down ⇒ failover allowed
 *     'error'              => string|null,
 *     'raw'                => mixed,         // untouched gateway response (audit)
 *   ]
 *
 * parseWebhook($rawBody, $decoded) return (a normalised event):
 *   [
 *     'gateway'         => string,           // which gateway produced this event
 *     'order_ref'       => string|null,      // OUR reference (matches orders/bookings.payment_id)
 *     'gateway_txn_id'  => string|null,      // gateway payment/txn id (gateway_ref)
 *     'amount'          => float|null,       // amount the gateway reports (re-checked server-side)
 *     'internal_status' => string,           // ALREADY mapped to 'paid'|'failed'|'unpaid'
 *     'event_type'      => string|null,      // raw gateway event type (audit/logging)
 *     'is_payment_event'=> bool,             // false ⇒ acknowledge & ignore (not a payment result)
 *   ]
 *
 * IMPORTANT: neither createOrder nor parseWebhook trust the caller for the
 * amount — the orchestrator (PaymentService) always re-verifies against the DB.
 */

interface PaymentGateway
{
    /** Canonical lowercase gateway name, e.g. 'cashfree'. Stored on orders/bookings/transactions. */
    public function name(): string;

    /**
     * Are real, non-placeholder credentials present for this gateway?
     * Used both as a create-order guard and as a failover eligibility check —
     * we never fail over to a gateway that isn't configured.
     */
    public function isConfigured(): bool;

    /**
     * Create a hosted-checkout order/session on the gateway.
     * MUST NOT throw for ordinary gateway/network failures — return
     * ['success'=>false, 'retryable'=>bool, …] so the manager can decide on failover.
     *
     * @param array $request Normalised request (see file header).
     * @return array         Normalised result (see file header).
     */
    public function createOrder(array $request): array;

    /**
     * Verify an inbound webhook's authenticity (signature / HMAC) against the
     * RAW request body. Called BEFORE any parsing or DB write.
     *
     * @param string $rawBody Raw, unmodified request body.
     * @param array  $headers Lower-cased header map.
     * @return bool           true only if the signature is valid.
     */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /**
     * Normalise a VERIFIED webhook body into one engine-agnostic event.
     * Only called after verifyWebhook() returned true.
     *
     * @param string $rawBody Raw request body (already verified).
     * @param array  $decoded json_decode($rawBody, true).
     * @return array          Normalised event (see file header).
     */
    public function parseWebhook(string $rawBody, array $decoded): array;
}
