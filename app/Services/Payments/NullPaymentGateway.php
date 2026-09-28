<?php

namespace App\Services\Payments;

use App\Contracts\PaymentCheckoutResult;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentWebhookResult;
use App\Models\Invoice;
use RuntimeException;

/**
 * v1.11.6 (Production Readiness pass, Part 18). Default binding for
 * PaymentGatewayInterface when no real provider is configured -- see
 * PaymentServiceProvider. Deliberately throws on every method rather
 * than returning a fake success, so the architecture can exist and be
 * exercised (routes, Invoice/Subscription wiring, tests) without any
 * risk of a payment silently appearing to succeed. Swap the binding in
 * PaymentServiceProvider for a real adapter (e.g. a Midtrans/Xendit
 * implementation of this same interface) once provider credentials are
 * actually available -- REQUIRES PROVIDER CONFIGURATION until then.
 */
class NullPaymentGateway implements PaymentGatewayInterface
{
    public function createCheckout(Invoice $invoice): PaymentCheckoutResult
    {
        throw new RuntimeException('No payment gateway is configured. Set PAYMENT_GATEWAY and its credentials, then bind a real adapter in PaymentServiceProvider.');
    }

    public function createPayment(Invoice $invoice, array $options = []): PaymentCheckoutResult
    {
        throw new RuntimeException('No payment gateway is configured.');
    }

    public function getPaymentStatus(string $gatewayReference): string
    {
        throw new RuntimeException('No payment gateway is configured.');
    }

    public function verifyWebhookSignature(array $payload, array $headers): bool
    {
        return false;
    }

    public function handleWebhook(array $payload): PaymentWebhookResult
    {
        throw new RuntimeException('No payment gateway is configured.');
    }

    /**
     * v2.80.0 -- the three contract calls that must answer rather than throw.
     *
     * They are asked BEFORE a payment is attempted, to decide whether to
     * offer one at all. Throwing here would turn "no gateway configured"
     * into a 500 on the billing page, when the correct behaviour is the page
     * saying plainly that online payment is not enabled.
     */
    public function gatewayName(): string
    {
        // Named honestly: nothing is configured, so nothing is the provider.
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function clientConfig(): array
    {
        return [];
    }

    public function invoiceIdFromReference(string $gatewayReference): ?int
    {
        return null;
    }

    public function refund(string $gatewayReference, ?float $amount = null): bool
    {
        throw new RuntimeException('No payment gateway is configured.');
    }
}
