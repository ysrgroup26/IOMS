<?php

namespace App\Services\Payments;

use App\Contracts\PaymentCheckoutResult;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentWebhookResult;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v2.84.1 -- THE DUITKU ADAPTER, AT THE BOUNDARY THAT ALREADY EXISTED.
 *
 * Nothing outside this class knows Duitku exists. The provider abstraction
 * (`PaymentGatewayInterface`, v1.11.6) and the verified-payment path
 * (`PaymentWebhookController::apply()`, v2.51.0) were built for exactly this
 * -- adding a second provider is a new adapter, a config block and a webhook
 * route, with no change to the subscription lifecycle, the invoice model or
 * any controller that takes a payment.
 *
 * THE API THIS IMPLEMENTS, from Duitku's official documentation
 * (docs.duitku.com/api/en). Every formula below is quoted from it rather
 * than inferred, because a signature scheme guessed wrong does not fail
 * loudly -- it fails as "every payment is rejected" and, far worse for a
 * webhook, as "every notification is rejected" while payments succeed.
 *
 *   Request Transaction (v2 inquiry)
 *     POST {base}/webapi/api/merchant/v2/inquiry            (JSON)
 *     signature = HMAC_SHA256(merchantCode + merchantOrderId + paymentAmount, apiKey)
 *     response  : statusCode "00" = success, plus reference + paymentUrl
 *
 *   Callback (webhook)
 *     POST (form-encoded) to our own callbackUrl
 *     signature = HMAC_SHA256(merchantCode + amount + merchantOrderId, apiKey)
 *     resultCode: "00" = success, "01" = failed
 *
 *   Check Transaction Status
 *     POST {base}/webapi/api/merchant/transactionStatus     (form-encoded)
 *     signature = HMAC_SHA256(merchantCode + merchantOrderId, apiKey)
 *     statusCode: "00" SUCCESS, "01" PROCESS, "02" FAILED/EXPIRED
 *
 * NOTE ON THE SIGNATURE ALGORITHM. Duitku's older v2 scheme was
 * MD5(... + apiKey); the documentation now carries an explicit deprecation
 * ("The previous signature MD5 method has been obsolete") and specifies
 * HMAC-SHA256 for both the request and the callback. This implements the
 * current one. It is the single most important line in the file, which is
 * why it is stated here in full rather than left to be read out of
 * `hash_hmac`.
 *
 * WHAT THIS CLASS MAY NOT DO. It may not report a payment as settled from
 * anything except a signature-verified callback. There is no code path here
 * that returns 'paid' because a checkout was opened, a redirect was
 * followed, or a customer came back to a success URL.
 */
class DuitkuGateway implements PaymentGatewayInterface
{
    public const GATEWAY = 'duitku';

    public function __construct(
        private readonly string $merchantCode,
        private readonly string $apiKey,
        private readonly bool $isProduction,
    ) {
        if ($this->merchantCode === '' || $this->apiKey === '') {
            throw new RuntimeException('Duitku is selected but DUITKU_MERCHANT_CODE / DUITKU_API_KEY are not set.');
        }
    }

    private function baseUrl(): string
    {
        return $this->isProduction
            ? 'https://passport.duitku.com'
            : 'https://sandbox.duitku.com';
    }

    /**
     * The merchantOrderId sent to Duitku.
     *
     * Unique per ATTEMPT for the same reason Midtrans's order id is: a
     * customer who abandons a checkout and comes back must be able to pay,
     * and a provider will refuse a repeat of an order id that already
     * carries a transaction. The invoice id stays the stable prefix so an
     * inbound callback is always traceable to exactly one invoice.
     */
    public static function orderIdFor(Invoice $invoice): string
    {
        // v2.94.0 -- RANDOM SUFFIX, because the timestamp alone is not
        // unique. `gateway_reference` is a UNIQUE column, and two attempts
        // inside the same second produced the same string: the second
        // insert hit the constraint, the caller swallowed it, and the
        // customer was told checkout could not be created. Seconds are not
        // fine-grained enough for something a double-click can trigger.
        return 'INV'.$invoice->id.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
    }

    public function invoiceIdFromReference(string $gatewayReference): ?int
    {
        return preg_match('/^INV(\d+)-/', $gatewayReference, $m) ? (int) $m[1] : null;
    }

    public function gatewayName(): string
    {
        return self::GATEWAY;
    }

    public function isConfigured(): bool
    {
        return filled($this->merchantCode) && filled($this->apiKey);
    }

    /**
     * Duitku's flow is a plain hosted redirect -- there is no in-page
     * component and therefore nothing the browser needs.
     *
     * The merchant code is deliberately NOT published here. It is not a
     * secret, but the browser has no use for it, and the smallest surface
     * is the right default for anything in the payment path.
     */
    public function clientConfig(): array
    {
        return [];
    }

    /**
     * v2.95.0 -- THE METHODS THIS MERCHANT MAY ACTUALLY OFFER, for an amount.
     *
     * Duitku's inquiry REQUIRES `paymentMethod`; without it the API answers
     * 400 "paymentMethod is mandatory" and no session exists. So the method
     * cannot be an afterthought -- the customer has to choose before there
     * is anything to pay.
     *
     * The list is fetched live rather than hardcoded because it is a
     * property of the MERCHANT ACCOUNT and the amount, not of the
     * integration: channels are enabled per account in the Duitku
     * dashboard, and a hardcoded list would offer a channel this merchant
     * cannot take, or hide one they are paying for.
     *
     * v2.131.0 -- THE SAME HMAC AS EVERY OTHER CALL. This endpoint used to
     * be signed with plain SHA256(merchantcode + amount + datetime + apiKey),
     * which Duitku's documentation changelog has since declared obsolete
     * alongside MD5: the current reference gives
     *
     *     signature = HMAC_SHA256(merchantcode + amount + datetime, apiKey)
     *
     * The old signature fails exactly the way this docblock warned it
     * would -- not as an error, but as "no methods available", so the
     * customer lands on "Sesi pembayaran belum tersedia" and checkout can
     * never start. That is a payment flow that cannot be completed end to
     * end, on the one page a payment provider's reviewer tests.
     *
     * A failure returns an EMPTY list, never a throw: being unable to list
     * methods must leave the customer on a page that says payment cannot
     * start right now, not a 500.
     */
    public function getPaymentMethods(float $amount, string $currency = 'IDR'): array
    {
        $amount = (int) round($amount);
        $datetime = now()->format('Y-m-d H:i:s');

        try {
            $response = Http::asJson()->acceptJson()->timeout(20)
                ->post($this->baseUrl().'/webapi/api/merchant/paymentmethod/getpaymentmethod', [
                    'merchantcode' => $this->merchantCode,
                    'amount' => (string) $amount,
                    'datetime' => $datetime,
                    'signature' => hash_hmac('sha256', $this->merchantCode.$amount.$datetime, $this->apiKey),
                ]);
        } catch (\Throwable $e) {
            Log::error('Duitku payment methods could not be fetched.', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::error('Duitku refused to list payment methods.', [
                'http_status' => $response->status(),
                'status_code' => $response->json('responseCode'),
                // Duitku's own words, e.g. "Invalid Signature" -- no secret.
                'message' => $response->json('responseMessage') ?? $response->json('Message'),
            ]);

            return [];
        }

        return collect($response->json('paymentFee') ?? [])
            ->map(fn (array $m) => [
                'code' => (string) ($m['paymentMethod'] ?? ''),
                'name' => (string) ($m['paymentName'] ?? ''),
                'fee' => (float) ($m['totalFee'] ?? 0),
            ])
            ->filter(fn (array $m) => $m['code'] !== '')
            ->values()
            ->all();
    }

    public function createCheckout(Invoice $invoice): PaymentCheckoutResult
    {
        return $this->createPayment($invoice);
    }

    public function createPayment(Invoice $invoice, array $options = []): PaymentCheckoutResult
    {
        $orderId = self::orderIdFor($invoice);
        // Duitku takes the amount as a whole number of rupiah.
        $amount = (int) round((float) $invoice->amount);

        /*
         * v2.95.0 -- REQUIRED, and refused loudly when absent.
         *
         * Duitku's inquiry answers 400 "paymentMethod is mandatory" without
         * it. Throwing here rather than sending a default is deliberate:
         * picking a channel on the customer's behalf would put them in front
         * of a payment instrument they did not choose, and the fee differs
         * per channel, so a silent default is a commercial decision made by
         * accident.
         */
        $method = (string) ($options['payment_method'] ?? '');

        if ($method === '') {
            throw new RuntimeException('Duitku requires a payment method to be chosen before a payment session can be created.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl().'/webapi/api/merchant/v2/inquiry', [
                'merchantCode' => $this->merchantCode,
                'paymentAmount' => $amount,
                'paymentMethod' => $method,
                'merchantOrderId' => $orderId,
                'productDetails' => $this->productDetail($invoice),
                'email' => $this->payerEmail($invoice),
                'customerVaName' => $this->payerName($invoice),
                'callbackUrl' => route('webhooks.payment.duitku'),
                // v2.131.0: where the CALLER says the customer belongs. This
                // was always the tenant billing page, so a new customer
                // paying an onboarding invoice came back from Duitku to a
                // sign-in wall instead of their order status. UX only --
                // nothing is settled by arriving here.
                'returnUrl' => $options['finish_url'] ?? route('subscription.billing'),
                'expiryPeriod' => max(1, (int) config('payment.checkout_expiry_hours', 24) * 60),
                'signature' => $this->requestSignature($orderId, $amount),
            ]);

        $body = $response->json() ?? [];

        // "00" is Duitku's success code. Anything else is a refusal, and a
        // refusal must surface as one -- never as a checkout the customer
        // can click that leads nowhere.
        if (! $response->successful() || ($body['statusCode'] ?? null) !== '00' || blank($body['paymentUrl'] ?? null)) {
            Log::error('Duitku refused to create a payment.', [
                'invoice' => $invoice->invoice_number,
                'http_status' => $response->status(),
                'status_code' => $body['statusCode'] ?? null,
                'status_message' => $body['statusMessage'] ?? null,
            ]);

            throw new RuntimeException('Duitku did not create a payment session for this invoice.');
        }

        return new PaymentCheckoutResult(
            gatewayReference: $orderId,
            redirectUrl: (string) $body['paymentUrl'],
            status: 'pending',
            // No in-page token: Duitku hosts the payment page itself.
            token: null,
        );
    }

    /**
     * A direct "check now" against the provider, for an operator or a
     * customer asking whether a payment landed.
     *
     * This is a READ. It is deliberately not wired to anything that settles
     * an invoice -- settlement stays with the signed callback, so there is
     * exactly one path by which IOMS can come to believe it was paid.
     */
    public function getPaymentStatus(string $gatewayReference): string
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl().'/webapi/api/merchant/transactionStatus', [
                'merchantCode' => $this->merchantCode,
                'merchantOrderId' => $gatewayReference,
                'signature' => $this->statusSignature($gatewayReference),
            ]);

        $code = (string) ($response->json('statusCode') ?? '');

        return match ($code) {
            '00' => 'paid',
            '01' => 'pending',
            '02' => 'failed',
            default => 'unknown',
        };
    }

    /**
     * THE ONE CHECK THAT DECIDES WHETHER A PAYLOAD IS TRUSTED.
     *
     * Compared with `hash_equals`, not `===`: a timing-safe comparison is
     * the standard for anything an attacker can submit repeatedly, and the
     * cost of getting it wrong here is a forged settlement.
     *
     * Every field is taken from the payload exactly as Duitku documents the
     * callback -- merchantCode, amount, merchantOrderId -- and the amount is
     * used as the STRING Duitku sent, because the signature was computed
     * over that string and re-formatting a number changes it.
     */
    public function verifyWebhookSignature(array $payload, array $headers = []): bool
    {
        $signature = (string) ($payload['signature'] ?? '');

        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', implode('', [
            (string) ($payload['merchantCode'] ?? ''),
            (string) ($payload['amount'] ?? ''),
            (string) ($payload['merchantOrderId'] ?? ''),
        ]), $this->apiKey);

        return hash_equals($expected, $signature);
    }

    /**
     * Translates an ALREADY-VERIFIED callback into the gateway-agnostic
     * result the shared settlement path understands.
     *
     * It does not verify anything itself, by design: verification happens
     * once, before any state is read, in the controller. A method that both
     * verified and applied would make it possible to call the applying half
     * alone.
     */
    public function handleWebhook(array $payload): PaymentWebhookResult
    {
        $status = match ((string) ($payload['resultCode'] ?? '')) {
            '00' => 'paid',
            '01' => 'failed',
            // An unrecognised code is NOT treated as failure or success. It
            // is pending, which changes nothing -- the safe direction when
            // the provider says something this adapter does not know.
            default => 'pending',
        };

        return new PaymentWebhookResult(
            gatewayReference: (string) ($payload['merchantOrderId'] ?? ''),
            status: $status,
            amount: isset($payload['amount']) ? (float) $payload['amount'] : null,
        );
    }

    /**
     * Not implemented, and that is the honest state.
     *
     * Duitku's disbursement API is a separate product with its own
     * credentials and merchant-side activation. Returning false would say
     * "the refund did not go through"; throwing says "IOMS cannot do this
     * here", which is the true statement and the one that cannot be
     * mistaken for a failed attempt.
     */
    public function refund(string $gatewayReference, ?float $amount = null): bool
    {
        throw new RuntimeException('Refunds are not available through the Duitku adapter. Refund from the Duitku dashboard.');
    }

    /* ==================================================================
     | Signatures -- the formulas, in one place
     |================================================================= */

    private function requestSignature(string $orderId, int $amount): string
    {
        return hash_hmac('sha256', $this->merchantCode.$orderId.$amount, $this->apiKey);
    }

    private function statusSignature(string $orderId): string
    {
        return hash_hmac('sha256', $this->merchantCode.$orderId, $this->apiKey);
    }

    /* ==================================================================
     | Payload details
     |================================================================= */

    private function productDetail(Invoice $invoice): string
    {
        return mb_substr('IOMS '.$invoice->invoice_number, 0, 255);
    }

    /**
     * Duitku requires an email. The invoice's own tenant owner is the payer
     * of record; the billing mailbox is the fallback, so a missing contact
     * cannot block a payment the customer is trying to make.
     */
    private function payerEmail(Invoice $invoice): string
    {
        return $invoice->tenant?->users()->withoutGlobalScopes()->value('email')
            ?? (string) config('ioms.emails.billing');
    }

    private function payerName(Invoice $invoice): string
    {
        return mb_substr($invoice->tenant?->name ?? 'IOMS', 0, 50);
    }
}
