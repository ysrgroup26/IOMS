<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ApprovedCatalogue;
use Tests\TestCase;

/**
 * v2.94.0 -- ONE PRICE, ALL THE WAY DOWN.
 *
 * `PricingConsistencyTest` pins what the catalogue says and what the public
 * surfaces quote. `FourTierPricingTest` pins what each tier grants and that
 * a catalogue reprice leaves existing customers alone. Neither follows a
 * single amount through the PAYMENT path, which is where a price stops
 * being a label and becomes money.
 *
 * This walks the whole chain, for every sold plan on both cycles:
 *
 *   catalogue -> quote -> registration -> invoice -> payment session
 *             -> the integer sent to the provider -> the signed callback
 *             -> settlement -> subscription -> the agreed-price snapshot
 *
 * and asserts the amount is IDENTICAL at every hop.
 *
 * WHY AN AUTOMATED CHAIN AND NOT A MANUAL SANDBOX PAYMENT. A manual
 * transaction proves one plan on one cycle, once. This proves six
 * combinations on every run, including the two that are easy to get wrong
 * and impossible to notice by eye:
 *
 *   PROFESSIONAL ANNUAL is a DISCOUNT -- eleven months' money for twelve
 *   months of access.
 *
 *   BUSINESS ANNUAL is EXTRA SERVICE -- twelve months' money for FOURTEEN
 *   months of access. It has no price saving at all, so the service term
 *   cannot be derived from the prices; it has to be asserted separately or
 *   it silently becomes a calendar year.
 *
 * The expectations come from Tests\Support\ApprovedCatalogue, which is
 * transcribed from the approved commercial decision rather than read back
 * out of config or the migration. A test that derives its expectation from
 * the code under test asserts only that the code equals itself.
 */
class PricingChainIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: float, 3: int, 4: int}> */
    public static function soldCombinations(): array
    {
        return ApprovedCatalogue::cycleProvider();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->configureDuitkuSandbox();

        // The provider is faked at the HTTP boundary rather than by mocking
        // the gateway, so the adapter's own request assembly -- including
        // the amount it puts on the wire and the signature over it -- is the
        // thing under test.
        Http::fake([
            '*duitku.com/webapi/api/merchant/v2/inquiry' => Http::response([
                'statusCode' => '00',
                'statusMessage' => 'SUCCESS',
                'reference' => 'DUITKU-REF-1',
                'paymentUrl' => 'https://sandbox.duitku.com/topup/pay/ABC123',
            ]),
        ]);
    }

    /* ==================================================================
     * THE WHOLE CHAIN, PER SOLD COMBINATION
     * ================================================================== */

    /**
     * The amount a customer is quoted is the amount that reaches the
     * provider, settles the invoice and is remembered on the subscription.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('soldCombinations')]
    public function test_one_amount_survives_the_entire_chain(
        string $slug,
        string $cycle,
        float $approved,
        int $paidMonths,
        int $serviceMonths,
    ): void {
        $package = Package::where('slug', $slug)->firstOrFail();

        /* 1 -- CATALOGUE. What the plan costs. */
        $catalogue = (float) ($cycle === 'monthly' ? $package->price_monthly : $package->price_yearly);
        $this->assertEquals($approved, $catalogue, "$slug $cycle: the catalogue drifted from the approved price.");

        /* 2 -- QUOTE. What the pricing service answers when asked. */
        $quote = app(PricingService::class)->amountFor($package, $cycle);
        $this->assertEquals($approved, $quote, "$slug $cycle: the quote does not match the catalogue.");

        /*
         * 3 -- REGISTRATION -> INVOICE. The registration is deliberately
         * created carrying a WRONG stored amount. The invoice must come out
         * at the approved figure anyway, because the server recomputes it
         * from the package and never trusts a number it finds lying around.
         */
        $registration = $this->registration($package, $cycle, storedAmount: 1.0);

        $this->post(route('register.checkout', $registration->token));

        $registration->refresh();
        $invoice = $registration->invoice;

        $this->assertNotNull($invoice, "$slug $cycle: checkout produced no invoice.");
        $this->assertEquals($approved, (float) $invoice->amount, "$slug $cycle: the invoice did not follow the catalogue.");
        $this->assertSame('IDR', $invoice->currency);

        /* 4 -- PAYMENT SESSION. The session is opened for the invoice total. */
        $transaction = PaymentTransaction::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals((float) $invoice->amount, (float) $transaction->amount, "$slug $cycle: the session amount left the invoice behind.");

        /*
         * 5 -- THE WIRE. What actually went to the provider, and the
         * signature computed over it. This is the hop where a rounding or a
         * formatting change would be invisible everywhere else.
         */
        Http::assertSent(function (ClientRequest $request) use ($approved, $package) {
            if (! str_contains($request->url(), '/v2/inquiry')) {
                return false;
            }

            $body = $request->data();

            $this->assertSame(
                (int) round($approved),
                $body['paymentAmount'],
                'The amount sent to the provider must be the approved figure, as a whole number of rupiah.',
            );

            // The signature must be over THAT amount. A signature computed
            // over a different figure than the one sent is the failure that
            // shows up as "the provider rejects everything".
            $expected = hash_hmac(
                'sha256',
                config('payment.duitku.merchant_code').$body['merchantOrderId'].$body['paymentAmount'],
                config('payment.duitku.api_key'),
            );
            $this->assertSame($expected, $body['signature'], 'The request signature must cover the amount actually sent.');

            return true;
        });

        /* 6 -- THE CALLBACK. Signed over the same figure, and it settles. */
        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, (string) (int) round($approved)),
        )->assertOk();

        /* 7 -- SETTLEMENT. */
        $invoice->refresh();
        $transaction->refresh();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->status, "$slug $cycle: the invoice did not settle.");
        $this->assertEquals($approved, (float) $invoice->amount, "$slug $cycle: settlement must not alter the amount.");
        $this->assertSame(PaymentTransaction::STATUS_PAID, $transaction->status);
        $this->assertNotNull($transaction->paid_at);

        /* 8 -- SUBSCRIPTION, and what it remembers it agreed to. */
        $tenant = Tenant::findOrFail($registration->fresh()->tenant_id);
        $subscription = $tenant->subscription;

        $this->assertNotNull($subscription, "$slug $cycle: settlement did not produce a subscription.");
        $this->assertSame($package->id, $subscription->package_id);
        $this->assertSame(
            $cycle === 'monthly' ? Subscription::CYCLE_MONTHLY : Subscription::CYCLE_YEARLY,
            $subscription->billing_cycle,
        );

        // Both cycles are snapshotted, so a later monthly<->yearly switch
        // cannot silently reprice this customer.
        $this->assertEquals((float) $package->price_monthly, (float) $subscription->agreed_price_monthly);
        $this->assertEquals((float) $package->price_yearly, (float) $subscription->agreed_price_yearly);

        // And the amount the customer would be billed for THIS cycle is the
        // one they just paid.
        $this->assertEquals($approved, $subscription->agreedAmountFor($cycle), "$slug $cycle: the agreed amount drifted from what was paid.");

        /*
         * 9 -- THE TERM. How much access the money bought.
         *
         * Asserted from ApprovedCatalogue, not from the dates the code
         * happened to write. This is the Business-annual case: twelve
         * months' money, FOURTEEN months of access.
         */
        $this->assertEquals(
            $serviceMonths,
            round($subscription->starts_at->diffInMonths($subscription->ends_at)),
            "$slug $cycle: the subscription term is not the approved number of SERVICE months.",
        );

        $this->assertSame($paidMonths, (int) round($cycle === 'monthly' ? 1 : $subscription->annualPaidMonths()));
    }

    /* ==================================================================
     * HISTORICAL IMMUTABILITY
     * ================================================================== */

    /**
     * A catalogue reprice after the fact must not reach back into a
     * settled invoice.
     *
     * `FourTierPricingTest` asserts the subscription half of this. The
     * INVOICE half matters for a different reason: an invoice is a
     * financial document the customer has filed, and in Indonesia has
     * probably submitted to their own accounting. If it can change after
     * being paid, it was never a record of anything.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('soldCombinations')]
    public function test_a_later_reprice_never_rewrites_a_settled_invoice(
        string $slug,
        string $cycle,
        float $approved,
    ): void {
        $package = Package::where('slug', $slug)->firstOrFail();
        $registration = $this->registration($package, $cycle);

        $this->post(route('register.checkout', $registration->token));
        $registration->refresh();
        $invoice = $registration->invoice;
        $transaction = PaymentTransaction::where('invoice_id', $invoice->id)->firstOrFail();

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, (string) (int) round($approved)),
        )->assertOk();

        $paidOn = $invoice->fresh()->payment_date;

        // The catalogue moves afterwards -- a real event, not a hypothetical.
        $package->update([
            'price_monthly' => (float) $package->price_monthly * 2,
            'price_yearly' => (float) $package->price_yearly * 2,
        ]);

        $invoice->refresh();

        $this->assertEquals($approved, (float) $invoice->amount, 'A settled invoice must keep the amount that was paid.');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertEquals($paidOn, $invoice->payment_date);

        // And the customer is still on the price they agreed to.
        $subscription = Tenant::findOrFail($registration->fresh()->tenant_id)->subscription;
        $this->assertEquals($approved, $subscription->agreedAmountFor($cycle), 'An existing customer must not be repriced by a catalogue edit.');
    }

    /* ==================================================================
     * THE AMOUNT IS THE SERVER'S, NOT THE BROWSER'S
     * ================================================================== */

    /**
     * The invoice follows the catalogue even when the registration carries
     * a different figure, because the server recomputes rather than trusts.
     */
    public function test_a_tampered_registration_amount_does_not_reach_the_invoice(): void
    {
        $package = Package::where('slug', 'business')->firstOrFail();

        // Someone has put a rupiah in the stored amount.
        $registration = $this->registration($package, 'monthly', storedAmount: 1.0);

        $this->post(route('register.checkout', $registration->token));

        $invoice = $registration->fresh()->invoice;

        $this->assertEquals(
            ApprovedCatalogue::PLANS['business'][0],
            (float) $invoice->amount,
            'The invoice must be priced from the catalogue, never from a number carried alongside the order.',
        );
    }

    /** A callback for the pre-tamper figure is refused, not settled. */
    public function test_a_callback_for_a_tampered_amount_is_refused(): void
    {
        $package = Package::where('slug', 'business')->firstOrFail();
        $registration = $this->registration($package, 'monthly', storedAmount: 1.0);

        $this->post(route('register.checkout', $registration->token));
        $registration->refresh();
        $invoice = $registration->invoice;
        $transaction = PaymentTransaction::where('invoice_id', $invoice->id)->firstOrFail();

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, '1'),
        )->assertOk();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status, 'A rupiah must not buy a Business subscription.');
        $this->assertNull($registration->fresh()->tenant_id);
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    private function configureDuitkuSandbox(): void
    {
        config([
            'payment.gateway' => 'duitku',
            'payment.duitku.merchant_code' => 'DTEST',
            'payment.duitku.api_key' => 'test-api-key',
            'payment.duitku.is_production' => false,
        ]);

        app()->forgetInstance(PaymentGatewayInterface::class);
    }

    private function signedCallback(string $orderId, string $amount): array
    {
        return [
            'merchantCode' => 'DTEST',
            'amount' => $amount,
            'merchantOrderId' => $orderId,
            'resultCode' => '00',
            'reference' => 'DUITKU-REF-1',
            'signature' => hash_hmac('sha256', 'DTEST'.$amount.$orderId, 'test-api-key'),
        ];
    }

    /**
     * A verified registration for a plan and cycle.
     *
     * `storedAmount` exists so a test can prove the invoice is priced from
     * the PACKAGE rather than from whatever the registration happens to
     * carry.
     */
    private function registration(Package $package, string $cycle, ?float $storedAmount = null): TenantRegistration
    {
        return TenantRegistration::create([
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => TenantRegistration::STATUS_VERIFIED,
            'contact_name' => 'Buyer',
            'contact_email' => 'buyer-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass-1'),
            'company_legal_name' => 'PT. Rantai Harga',
            'company_display_name' => 'Rantai Harga',
            'package_id' => $package->id,
            'billing_cycle' => $cycle,
            'amount' => $storedAmount ?? ($cycle === 'monthly' ? $package->price_monthly : $package->price_yearly),
            'currency' => 'IDR',
            'email_verified_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
