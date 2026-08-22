<?php

namespace App\Contracts;

use App\Models\Invoice;

/**
 * v1.11.6 (Production Readiness pass, Part 18). Provider-agnostic
 * payment abstraction -- no controller/model in this codebase talks to
 * a specific gateway's SDK directly; everything goes through this
 * contract, so a concrete provider is a swappable adapter, not scattered
 * throughout the app. `App\Services\Payments\NullPaymentGateway` is
 * bound by default (see PaymentServiceProvider) and throws on every
 * method with a clear "REQUIRES PROVIDER CONFIGURATION" message --
 * intentionally not a demo/mock success path, so nothing in this
 * codebase can ever silently pretend a payment succeeded.
 *
 * `verifyPayment()`/`handleWebhook()` are the only calls allowed to
 * change an Invoice's paid status -- see Invoice::markPaid()'s own doc
 * comment. Nothing in this codebase marks an invoice paid from a
 * checkout-page-opened event alone.
 */
interface PaymentGatewayInterface
{
    /** Creates a hosted checkout/payment session for one Invoice. Returns a redirect URL and the gateway's own reference id. */
    public function createCheckout(Invoice $invoice): PaymentCheckoutResult;

    /** Low-level payment creation for gateways that separate "create payment" from "create checkout" (e.g. a Snap-style flow vs. a direct charge API). */
    public function createPayment(Invoice $invoice, array $options = []): PaymentCheckoutResult;

    /** Confirms a payment's current status directly against the provider (a manual "check now" action, distinct from webhook-driven confirmation). */
    public function getPaymentStatus(string $gatewayReference): string;

    /**
     * Verifies an inbound webhook payload's authenticity (signature/
     * secret check) BEFORE any side effect runs. Returns false on any
     * verification failure -- callers must never act on an unverified
     * payload.
     */
    public function verifyWebhookSignature(array $payload, array $headers): bool;

    /** Processes an already-verified webhook payload. Must be idempotent -- a duplicate delivery of the same event must not double-apply. */
    public function handleWebhook(array $payload): PaymentWebhookResult;

    public function refund(string $gatewayReference, ?float $amount = null): bool;
}
