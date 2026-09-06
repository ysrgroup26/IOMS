<?php

namespace App\Services\Payments;

use App\Contracts\PaymentCheckoutResult;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentWebhookResult;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * v2.51.0 -- the real Midtrans adapter, implementing the EXISTING
 * PaymentGatewayInterface. Nothing outside this class knows Midtrans
 * exists; swapping providers stays a one-line change in
 * PaymentServiceProvider.
 *
 * Snap is used rather than Core API on purpose: Snap's hosted page is
 * what lets IOMS offer VA / e-wallet / QRIS / card without IOMS ever
 * touching a card number. No PAN, CVV or expiry passes through this
 * application or its database, so there is nothing here to tokenize or
 * store.
 *
 * Deliberately built on Laravel's HTTP client rather than the Midtrans
 * PHP SDK -- the SDK configures itself through global static state, which
 * is awkward to test and pulls a dependency for two endpoints. Two
 * documented REST calls are clearer and keep this class swappable.
 *
 * THE SECURITY RULE THIS CLASS EXISTS TO ENFORCE: a browser reaching a
 * success URL proves nothing. Only verifyWebhookSignature() +
 * handleWebhook() may report a payment as settled, and the signature is
 * checked against the server key before any side effect runs.
 */
class MidtransGateway implements PaymentGatewayInterface
{
    public const GATEWAY = 'midtrans';

    public function __construct(
        private readonly string $serverKey,
        private readonly string $clientKey,
        private readonly bool $isProduction,
    ) {
        if ($this->serverKey === '' || $this->clientKey === '') {
            throw new RuntimeException('Midtrans is selected but MIDTRANS_SERVER_KEY / MIDTRANS_CLIENT_KEY are not set.');
        }
    }

    private function snapBaseUrl(): string
    {
        return $this->isProduction
            ? 'https://app.midtrans.com/snap/v1'
            : 'https://app.sandbox.midtrans.com/snap/v1';
    }

    private function apiBaseUrl(): string
    {
        return $this->isProduction
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';
    }

    /**
     * The order_id sent to Midtrans. Must be unique per attempt, because
     * Midtrans rejects a repeat of an order_id that already carries a
     * transaction -- so a customer who abandons checkout and comes back
     * can still pay. The invoice id stays the stable prefix, so an inbound
     * notification is always traceable back to exactly one invoice.
     */
    public static function orderIdFor(Invoice $invoice): string
    {
        return 'INV'.$invoice->id.'-'.now()->format('YmdHis');
    }

    public static function invoiceIdFromOrderId(string $orderId): ?int
    {
        return preg_match('/^INV(\d+)-/', $orderId, $m) ? (int) $m[1] : null;
    }

    public function createCheckout(Invoice $invoice): PaymentCheckoutResult
    {
        return $this->createPayment($invoice);
    }

    public function createPayment(Invoice $invoice, array $options = []): PaymentCheckoutResult
    {
        $orderId = $options['order_id'] ?? self::orderIdFor($invoice);

        // Midtrans requires gross_amount to be a whole number for IDR.
        $grossAmount = (int) round((float) $invoice->amount);

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $grossAmount,
            ],
            'item_details' => [[
                'id' => 'plan-'.($options['plan_slug'] ?? 'subscription'),
                'price' => $grossAmount,
                'quantity' => 1,
                'name' => mb_substr($options['description'] ?? ('IOMS '.$invoice->invoice_number), 0, 50),
            ]],
            'customer_details' => array_filter([
                'first_name' => mb_substr((string) ($options['customer_name'] ?? ''), 0, 20) ?: null,
                'email' => $options['customer_email'] ?? null,
                'phone' => $options['customer_phone'] ?? null,
            ]),
            'credit_card' => ['secure' => true],
            'expiry' => [
                'unit' => 'hours',
                'duration' => (int) config('payment.checkout_expiry_hours', 24),
            ],
        ];

        if (! empty($options['finish_url'])) {
            $payload['callbacks'] = ['finish' => $options['finish_url']];
        }

        $response = Http::withBasicAuth($this->serverKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->snapBaseUrl().'/transactions', $payload);

        if (! $response->successful() || ! $response->json('redirect_url')) {
            Log::error('Midtrans checkout creation failed.', [
                'invoice_id' => $invoice->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('The payment provider rejected this checkout request.');
        }

        return new PaymentCheckoutResult(
            gatewayReference: $orderId,
            redirectUrl: (string) $response->json('redirect_url'),
            status: 'pending',
        );
    }

    public function getPaymentStatus(string $gatewayReference): string
    {
        $response = Http::withBasicAuth($this->serverKey, '')
            ->acceptJson()
            ->timeout(20)
            ->get($this->apiBaseUrl().'/'.$gatewayReference.'/status');

        if (! $response->successful()) {
            throw new RuntimeException('Could not read payment status from the provider.');
        }

        return $this->normalizeStatus(
            (string) $response->json('transaction_status'),
            (string) $response->json('fraud_status', 'accept'),
        );
    }

    /**
     * Midtrans signs every notification as
     * sha512(order_id + status_code + gross_amount + server_key).
     * Recomputed here from the payload's own fields and compared in
     * constant time. Returns false on ANY missing field -- an unsigned or
     * partial payload is never treated as authentic.
     */
    public function verifyWebhookSignature(array $payload, array $headers = []): bool
    {
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $required) {
            if (! isset($payload[$required]) || $payload[$required] === '') {
                return false;
            }
        }

        $expected = hash('sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$this->serverKey
        );

        return hash_equals($expected, (string) $payload['signature_key']);
    }

    /**
     * Translates an ALREADY-VERIFIED notification into the gateway-neutral
     * result the rest of IOMS understands. This method performs no side
     * effects of its own -- idempotency and state changes belong to the
     * caller (PaymentWebhookController) and are built on
     * PaymentWebhookEvent, so the same guarantees apply automatically to
     * every future gateway adapter.
     */
    public function handleWebhook(array $payload): PaymentWebhookResult
    {
        $status = $this->normalizeStatus(
            (string) ($payload['transaction_status'] ?? ''),
            (string) ($payload['fraud_status'] ?? 'accept'),
        );

        return new PaymentWebhookResult(
            gatewayReference: (string) ($payload['order_id'] ?? ''),
            status: $status,
            amount: isset($payload['gross_amount']) ? (float) $payload['gross_amount'] : null,
        );
    }

    public function refund(string $gatewayReference, ?float $amount = null): bool
    {
        $response = Http::withBasicAuth($this->serverKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->apiBaseUrl().'/'.$gatewayReference.'/refund',
                $amount === null ? [] : ['amount' => (int) round($amount)]
            );

        return $response->successful();
    }

    /**
     * Midtrans's transaction_status vocabulary mapped onto the statuses
     * PaymentTransaction already uses. `capture` counts as paid only when
     * fraud screening accepted it -- a challenged capture is still under
     * review and must NOT activate anything.
     */
    private function normalizeStatus(string $transactionStatus, string $fraudStatus = 'accept'): string
    {
        return match ($transactionStatus) {
            'capture' => $fraudStatus === 'accept' ? 'paid' : 'pending',
            'settlement' => 'paid',
            'pending' => 'pending',
            'deny', 'cancel', 'failure' => 'failed',
            'expire' => 'expired',
            'refund', 'partial_refund' => 'refunded',
            default => 'pending',
        };
    }

    public function clientKey(): string
    {
        return $this->clientKey;
    }
}
