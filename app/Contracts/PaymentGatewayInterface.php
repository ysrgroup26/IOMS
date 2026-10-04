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

    /**
     * v2.95.0 -- THE PAYMENT METHODS THIS PROVIDER OFFERS FOR AN AMOUNT.
     *
     * Some providers choose the method themselves: Midtrans Snap presents
     * its own picker, so the merchant never names one. Others REQUIRE the
     * method up front -- Duitku's inquiry refuses outright without it
     * ("paymentMethod is mandatory"), which is a 400 and no payment session
     * at all.
     *
     * So this returns the list when the provider needs the merchant to
     * choose, and an EMPTY ARRAY when the provider does its own choosing.
     * An empty array is therefore not an error and not "no methods
     * available"; it means "do not ask, this provider handles it".
     *
     * The list is fetched per AMOUNT because availability and fees depend
     * on it -- a method may be offered for one total and not another.
     *
     * @return array<int, array{code: string, name: string, fee: float}>
     */
    public function getPaymentMethods(float $amount, string $currency = 'IDR'): array;

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

    /**
     * v2.80.0 -- THE THREE CALLS THAT WERE MISSING, AND WHY THEY MATTER.
     *
     * The contract described how to take a payment but not the three things
     * the rest of the application needed from a provider anyway -- so those
     * were reached for concretely instead: two controllers did
     * `$gateway instanceof MidtransGateway ? $gateway->clientConfig() : []`,
     * and the shared verified-payment path called a STATIC on MidtransGateway
     * to work out which invoice a reference belonged to.
     *
     * That is the leak this closes. A second provider would have had to be
     * added to an instanceof chain and to a static call inside domain code,
     * which is exactly the "provider-specific logic scattered through
     * subscription code" this abstraction exists to prevent.
     */

    /** Whether this deployment can actually take a payment: named provider AND credentials present. */
    public function isConfigured(): bool;

    /**
     * v2.84.1 -- WHICH PROVIDER THIS IS, for the records a payment leaves
     * behind.
     *
     * The shared settlement path stamps the provider name onto the invoice
     * when it marks it paid, and it was naming Midtrans as a literal -- the
     * last piece of provider-specific knowledge left in code every gateway
     * runs through. A second adapter would have settled its payments under
     * the first one's name, which is the kind of wrong that is invisible
     * until somebody reconciles a statement.
     */
    public function gatewayName(): string;

    /**
     * What the BROWSER may know about this provider -- a public/client key,
     * a script URL, a sandbox flag. Never a server key or secret. Empty for
     * a provider with no in-page component (a plain redirect flow).
     */
    public function clientConfig(): array;

    /**
     * Which invoice a provider reference belongs to, or null when it cannot
     * be determined. The reference format is the provider adapter's business
     * -- Midtrans needs a unique order id per attempt, another provider may
     * not -- so reading it back has to be the adapter's job too.
     */
    public function invoiceIdFromReference(string $gatewayReference): ?int;
}
