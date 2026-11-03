<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Mail\InvoiceIssued;
use App\Mail\TenantActivated;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.141.1 -- THE CHECKOUT SCENARIOS DUITKU'S REVIEW ASKS FOR (ADR 085).
 *
 * Duitku is simulated at the HTTP boundary, so the adapter's own request
 * assembly and signatures run; nothing here reaches a real provider and no
 * money moves. The same scenarios are then repeated against
 * sandbox.duitku.com on staging (docs/PAYMENTS-DUITKU.md section 7), which
 * is the only place the real provider's answers can be observed.
 *
 * Each test names the scenario number from the owner's request.
 */
class DuitkuCheckoutScenariosTest extends TestCase
{
    use RefreshDatabase;

    /** What the simulated transactionStatus endpoint answers next. */
    private string $providerStatus = '01';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->seed(PackageSeeder::class);

        config([
            'payment.gateway' => 'duitku',
            'payment.duitku.merchant_code' => 'DSANDBOX',
            'payment.duitku.api_key' => 'sandbox-test-key',
            'payment.duitku.is_production' => false,
        ]);
        app()->forgetInstance(PaymentGatewayInterface::class);

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            // A real provider is never reached: everything goes to sandbox.
            $this->assertStringStartsWith('https://sandbox.duitku.com/', $url);

            if (str_contains($url, 'getpaymentmethod')) {
                return Http::response(['responseCode' => '00', 'paymentFee' => [
                    ['paymentMethod' => 'BC', 'paymentName' => 'BCA VA', 'totalFee' => '0'],
                    ['paymentMethod' => 'M2', 'paymentName' => 'Mandiri VA', 'totalFee' => '0'],
                ]]);
            }

            if (str_contains($url, 'v2/inquiry')) {
                return Http::response([
                    'statusCode' => '00', 'statusMessage' => 'SUCCESS',
                    'reference' => 'DSANDBOX-REF-'.uniqid(),
                    'paymentUrl' => 'https://sandbox.duitku.com/topup/pay/'.uniqid(),
                ]);
            }

            if (str_contains($url, 'transactionStatus')) {
                return Http::response(['statusCode' => $this->providerStatus, 'statusMessage' => 'SIMULATED']);
            }

            return Http::response([], 404);
        });
    }

    /* ================================================================== */

    /** 1, 3, 4 -- a new company registers, confirms, gets an invoice, chooses a method. */
    public function test_a_new_company_reaches_the_provider_with_its_own_details(): void
    {
        $registration = $this->registration('starter', verified: false);

        $this->get(route('register.verify', $registration->token))->assertRedirect(route('register.status', $registration->token));
        $this->assertTrue($registration->fresh()->isVerified());

        $this->post(route('register.checkout', $registration->token))->assertRedirect(route('register.pay', $registration->token));
        $invoice = $registration->fresh()->invoice;
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertSame(1, $this->mailed(InvoiceIssued::class));

        $this->get(route('register.pay', $registration->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Public/Checkout')->where('payment.methods.0.code', 'BC'));

        $this->post(route('register.pay.start', $registration->token), ['payment_method' => 'BC'])
            ->assertRedirectContains('https://sandbox.duitku.com/topup/pay/');

        Http::assertSent(function (HttpRequest $request) use ($registration, $invoice) {
            if (! str_contains($request->url(), 'v2/inquiry')) {
                return false;
            }

            return $request['email'] === $registration->contact_email
                && $request['customerVaName'] === $registration->contact_name
                && $request['paymentAmount'] === (int) $invoice->amount
                && $request['paymentMethod'] === 'BC'
                && $request['returnUrl'] === route('register.status', $registration->token)
                && $request['callbackUrl'] === route('webhooks.payment.duitku')
                && $request['signature'] === hash_hmac('sha256', 'DSANDBOX'.$request['merchantOrderId'].(int) $invoice->amount, 'sandbox-test-key');
        });
    }

    /** 2 -- each plan is invoiced and sent to the provider at its own catalogue price. */
    public function test_starter_professional_and_business_are_each_charged_their_catalogue_price(): void
    {
        foreach (['starter', 'professional', 'business'] as $slug) {
            $package = Package::where('slug', $slug)->firstOrFail();
            $registration = $this->registration($slug);

            $this->post(route('register.checkout', $registration->token));
            $this->post(route('register.pay.start', $registration->token), ['payment_method' => 'BC']);

            $invoice = $registration->fresh()->invoice;
            $this->assertEqualsWithDelta((float) $package->price_monthly, (float) $invoice->amount, 0.01, $slug);
            $this->assertEqualsWithDelta((float) $invoice->amount, (float) PaymentTransaction::where('invoice_id', $invoice->id)->value('amount'), 0.01, $slug);
        }
    }

    /** 5, 13, 14, 15, 16 -- a verified success settles once, activates the workspace and emails it. */
    public function test_a_successful_payment_activates_the_workspace_and_keeps_the_books_consistent(): void
    {
        [$registration, $invoice, $attempt] = $this->paying('professional');

        $this->post(route('webhooks.payment.duitku'), $this->signedCallback($attempt, '00'))->assertOk();

        $invoice->refresh();
        $registration->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(PaymentTransaction::STATUS_PAID, $attempt->fresh()->status);
        $this->assertSame(TenantRegistration::STATUS_PROVISIONED, $registration->status);
        $this->assertNotNull($registration->tenant_id);
        $this->assertSame(1, Subscription::where('tenant_id', $registration->tenant_id)->count());
        $this->assertSame(Subscription::STATUS_ACTIVE, Subscription::where('tenant_id', $registration->tenant_id)->value('status'));
        $this->assertSame(1, $this->mailed(TenantActivated::class));

        // Financial consistency: one invoice, one paid attempt, same figure.
        $this->assertSame(1, Invoice::where('registration_id', $registration->id)->count());
        $this->assertSame(1, PaymentTransaction::where('invoice_id', $invoice->id)->where('status', 'paid')->count());
        $this->assertEqualsWithDelta((float) $invoice->amount, (float) $attempt->fresh()->amount, 0.01);

        $this->get(route('register.status', $registration->token))
            ->assertInertia(fn ($page) => $page->where('registration.status', 'provisioned')->where('payment.state', 'paid'));
    }

    /** 6 -- a payment still open at the provider changes nothing and says so. */
    public function test_a_pending_payment_changes_nothing_and_can_be_resumed(): void
    {
        [$registration, $invoice, $attempt] = $this->paying('starter');
        $this->providerStatus = '01';

        $this->get(route('register.status', [$registration->token, 'merchantOrderId' => $attempt->gateway_reference, 'resultCode' => '01']))
            ->assertInertia(fn ($page) => $page->where('payment.state', 'pending')->where('payment.resume_url', route('register.pay', $registration->token)));

        $this->assertSame(PaymentTransaction::STATUS_PENDING, $attempt->fresh()->status);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertSame(0, $this->tenantsCreated());
    }

    /** 7 -- a failed callback activates nothing and lets the customer pay again. */
    public function test_a_failed_payment_activates_nothing_and_a_new_attempt_can_be_made(): void
    {
        [$registration, $invoice, $attempt] = $this->paying('starter');

        $this->post(route('webhooks.payment.duitku'), $this->signedCallback($attempt, '01'))->assertOk();

        $this->assertSame(PaymentTransaction::STATUS_FAILED, $attempt->fresh()->status);
        $this->get(route('register.status', $registration->token))->assertInertia(fn ($page) => $page->where('payment.state', 'failed'));

        $this->post(route('register.pay.start', $registration->token), ['payment_method' => 'M2'])
            ->assertRedirectContains('https://sandbox.duitku.com/topup/pay/');
        $this->assertSame(2, PaymentTransaction::where('invoice_id', $invoice->id)->count());
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertSame(0, $this->tenantsCreated());
    }

    /** 8 -- a session past its window is marked expired; the invoice survives; a new session opens. */
    public function test_an_expired_session_is_recorded_and_replaced(): void
    {
        [$registration, $invoice, $attempt] = $this->paying('starter');

        $this->travel((int) config('payment.checkout_expiry_hours', 24) + 1)->hours();

        $this->get(route('register.status', $registration->token))->assertInertia(fn ($page) => $page->where('payment.state', 'expired'));
        $this->assertSame(PaymentTransaction::STATUS_EXPIRED, $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->expired_at);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);

        $this->post(route('register.pay.start', $registration->token), ['payment_method' => 'BC'])
            ->assertRedirectContains('https://sandbox.duitku.com/topup/pay/');
        $this->assertSame(1, PaymentTransaction::where('invoice_id', $invoice->id)->where('status', 'pending')->count());
    }

    /** 9 -- cancelled at the provider: confirmed server-side, then the customer may choose again. */
    public function test_a_cancelled_payment_is_confirmed_with_the_provider_before_it_is_closed(): void
    {
        [$registration, $invoice, $attempt] = $this->paying('starter');
        $this->providerStatus = '02';

        $this->get(route('register.status', [$registration->token, 'merchantOrderId' => $attempt->gateway_reference, 'resultCode' => '02']))
            ->assertInertia(fn ($page) => $page->where('payment.state', 'failed'));

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'transactionStatus')
            && $r['merchantOrderId'] === $attempt->gateway_reference
            && $r['signature'] === hash_hmac('sha256', 'DSANDBOX'.$attempt->gateway_reference, 'sandbox-test-key'));
        $this->assertSame(PaymentTransaction::STATUS_FAILED, $attempt->fresh()->status);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
    }

    /** 9b -- the browser saying "cancelled" is not believed when the provider says otherwise. */
    public function test_a_cancel_claim_in_the_return_url_alone_closes_nothing(): void
    {
        [$registration, , $attempt] = $this->paying('starter');
        $this->providerStatus = '01';

        $this->get(route('register.status', [$registration->token, 'merchantOrderId' => $attempt->gateway_reference, 'resultCode' => '02']));

        $this->assertSame(PaymentTransaction::STATUS_PENDING, $attempt->fresh()->status);
    }

    /** 10 -- the same callback three times settles once. */
    public function test_a_callback_delivered_three_times_settles_once(): void
    {
        [$registration, $invoice, $attempt] = $this->paying('business');
        $payload = $this->signedCallback($attempt, '00');

        foreach (range(1, 3) as $ignored) {
            $this->post(route('webhooks.payment.duitku'), $payload)->assertOk();
        }

        $this->assertSame(1, $this->tenantsCreated());
        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, $this->mailed(TenantActivated::class));
        $this->assertSame(1, PaymentTransaction::where('invoice_id', $invoice->id)->where('status', 'paid')->count());
    }

    /** 11 -- a forged callback is refused before anything is read. */
    public function test_a_callback_with_an_invalid_signature_is_refused(): void
    {
        [, $invoice, $attempt] = $this->paying('starter');
        $payload = [...$this->signedCallback($attempt, '00'), 'signature' => str_repeat('0', 64)];

        $this->post(route('webhooks.payment.duitku'), $payload)->assertForbidden();

        $this->assertSame(PaymentTransaction::STATUS_PENDING, $attempt->fresh()->status);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertSame(0, $this->tenantsCreated());
    }

    /** 12 -- a success redirect with no valid callback activates nothing, even if the provider read says paid. */
    public function test_a_success_redirect_without_a_valid_callback_activates_nothing(): void
    {
        [$registration, $invoice, $attempt] = $this->paying('starter');
        $this->providerStatus = '00';

        $this->get(route('register.status', [$registration->token, 'merchantOrderId' => $attempt->gateway_reference, 'resultCode' => '00', 'reference' => 'X']))
            ->assertInertia(fn ($page) => $page->where('payment.state', 'confirming'));

        $this->assertSame(PaymentTransaction::STATUS_PENDING, $attempt->fresh()->status);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertNotSame(TenantRegistration::STATUS_PROVISIONED, $registration->fresh()->status);
        $this->assertSame(0, $this->tenantsCreated());
        $this->assertSame(0, $this->mailed(TenantActivated::class));
    }

    /** A return naming another invoice's order asks the provider nothing and changes nothing. */
    public function test_a_return_naming_someone_elses_order_is_ignored(): void
    {
        [$registrationA] = $this->paying('starter');
        [, , $attemptB] = $this->paying('starter');
        $this->providerStatus = '02';

        $this->get(route('register.status', [$registrationA->token, 'merchantOrderId' => $attemptB->gateway_reference]));

        $this->assertSame(PaymentTransaction::STATUS_PENDING, $attemptB->fresh()->status);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'transactionStatus'));
    }

    /** The manual-payment notice is gone once checkout is configured. */
    public function test_the_manual_payment_notice_does_not_appear_when_checkout_is_configured(): void
    {
        $registration = $this->registration('starter');

        $this->post(route('register.checkout', $registration->token))
            ->assertRedirect(route('register.pay', $registration->token))
            ->assertSessionMissing('info');
    }

    /* ================================================================== */

    private function mailed(string $mailable): int
    {
        return Mail::sent($mailable)->count() + Mail::queued($mailable)->count();
    }

    private function tenantsCreated(): int
    {
        return Tenant::where('name', '!=', 'Default Tenant')->count();
    }

    private function registration(string $slug, bool $verified = true): TenantRegistration
    {
        $package = Package::where('slug', $slug)->firstOrFail();

        return TenantRegistration::create([
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => $verified ? TenantRegistration::STATUS_VERIFIED : TenantRegistration::STATUS_PENDING_VERIFICATION,
            'contact_name' => 'Sandbox Buyer',
            'contact_email' => 'buyer-'.uniqid().'@example.test',
            'contact_phone' => '081200000000',
            'password' => Hash::make('secret-pass-1'),
            'company_legal_name' => 'PT. Uji Sandbox '.uniqid(),
            'company_display_name' => 'Uji Sandbox',
            'package_id' => $package->id,
            'billing_cycle' => 'monthly',
            'amount' => $package->price_monthly,
            'currency' => 'IDR',
            'email_verified_at' => $verified ? now() : null,
            'expires_at' => now()->addDays(7),
        ]);
    }

    /** @return array{0: TenantRegistration, 1: Invoice, 2: PaymentTransaction} */
    private function paying(string $slug): array
    {
        $registration = $this->registration($slug);
        $this->post(route('register.checkout', $registration->token));
        $this->post(route('register.pay.start', $registration->token), ['payment_method' => 'BC']);

        $invoice = $registration->fresh()->invoice;

        return [$registration, $invoice, PaymentTransaction::where('invoice_id', $invoice->id)->latest('id')->firstOrFail()];
    }

    private function signedCallback(PaymentTransaction $attempt, string $resultCode): array
    {
        $amount = (string) (int) $attempt->amount;

        return [
            'merchantCode' => 'DSANDBOX',
            'amount' => $amount,
            'merchantOrderId' => $attempt->gateway_reference,
            'resultCode' => $resultCode,
            'reference' => 'DSANDBOX-REF-'.$attempt->id,
            'paymentCode' => 'BC',
            'signature' => hash_hmac('sha256', 'DSANDBOX'.$amount.$attempt->gateway_reference, 'sandbox-test-key'),
        ];
    }
}
