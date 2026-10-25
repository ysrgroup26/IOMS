<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\TenantRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.131.0 -- ONE ORDER, ONE PRICE.
 *
 * Reported: Master Admin showed Starter at Rp189.000 while an invoice read
 * Rp299.000. The catalogue was right; an order raised before the v2.82.0
 * repricing kept its old figure on the status page and was sent to the
 * payment provider at that figure, because only checkout() repriced.
 */
class OpenOrderRepricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'payment.gateway' => 'duitku',
            'payment.duitku.merchant_code' => 'DXXXX',
            'payment.duitku.api_key' => 'test-api-key',
            'payment.duitku.is_production' => false,
        ]);
        app()->forgetInstance(PaymentGatewayInterface::class);

        Http::fake([
            '*duitku.com/webapi/api/merchant/paymentmethod/getpaymentmethod' => Http::response([
                'paymentFee' => [['paymentMethod' => 'BC', 'paymentName' => 'BCA VA', 'totalFee' => '0']],
                'responseCode' => '00',
            ]),
            '*duitku.com/webapi/api/merchant/v2/inquiry' => Http::response([
                'statusCode' => '00', 'statusMessage' => 'SUCCESS',
                'reference' => 'DUITKU-REF-1', 'paymentUrl' => 'https://sandbox.duitku.com/topup/pay/ABC123',
            ]),
        ]);
    }

    private function starter(): Package
    {
        $package = Package::firstOrCreate(['slug' => 'starter'], [
            'name' => 'Starter', 'currency' => 'IDR', 'max_users' => 3, 'max_companies' => 1,
            'is_active' => true, 'is_public' => true,
        ]);
        // The approved catalogue (v2.82.0), whatever the fixture said.
        $package->update(['price_monthly' => 189000, 'price_yearly' => 2268000]);

        return $package;
    }

    /** An order raised when Starter was Rp299.000, invoiced, never paid. */
    private function staleOrder(): TenantRegistration
    {
        $registration = TenantRegistration::create([
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => TenantRegistration::STATUS_AWAITING_PAYMENT,
            'contact_name' => 'Buyer',
            'contact_email' => 'buyer@contoh.test',
            'password' => Hash::make('secret-pass-1'),
            'company_legal_name' => 'PT. Contoh Bayar',
            'company_display_name' => 'Contoh Bayar',
            'package_id' => $this->starter()->id,
            'billing_cycle' => 'monthly',
            'amount' => 299000,
            'currency' => 'IDR',
            'email_verified_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $invoice = Invoice::create([
            'invoice_number' => Invoice::generateNumber(null),
            'tenant_id' => null,
            'registration_id' => $registration->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'amount' => 299000,
            'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED,
            'due_date' => now()->addDays(7)->toDateString(),
            'purpose' => Invoice::PURPOSE_ONBOARDING,
        ]);
        $registration->update(['invoice_id' => $invoice->id]);

        return $registration->fresh();
    }

    public function test_the_status_page_states_the_current_catalogue_price(): void
    {
        $registration = $this->staleOrder();

        $this->get(route('register.status', $registration->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('registration.amount', fn ($v) => str_contains($v, '189')));

        $this->assertEquals(189000, (float) $registration->fresh()->amount);
        $this->assertEquals(189000, (float) $registration->fresh()->invoice->amount);
    }

    public function test_the_payment_request_asks_the_provider_for_the_current_price(): void
    {
        $registration = $this->staleOrder();

        $this->post(route('register.pay.start', $registration->token), ['payment_method' => 'BC'])
            ->assertRedirect('https://sandbox.duitku.com/topup/pay/ABC123');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'v2/inquiry')
            && (int) $r['paymentAmount'] === 189000);

        $this->assertEquals(189000, (float) PaymentTransaction::sole()->amount);
    }

    public function test_a_paid_invoice_is_a_financial_record_and_is_never_repriced(): void
    {
        $registration = $this->staleOrder();
        $registration->invoice->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);
        $registration->update(['status' => TenantRegistration::STATUS_PAID]);

        $this->get(route('register.status', $registration->token))->assertOk();

        $this->assertEquals(299000, (float) $registration->fresh()->invoice->amount);
        $this->assertEquals(299000, (float) $registration->fresh()->amount);
    }

    public function test_an_invoice_the_provider_is_already_collecting_is_not_moved(): void
    {
        $registration = $this->staleOrder();
        PaymentTransaction::create([
            'invoice_id' => $registration->invoice_id,
            'gateway' => 'duitku',
            'gateway_reference' => 'LIVE-1',
            'status' => PaymentTransaction::STATUS_PENDING,
            'amount' => 299000,
            'currency' => 'IDR',
            'redirect_url' => 'https://sandbox.duitku.com/topup/pay/LIVE',
        ]);

        $this->get(route('register.status', $registration->token))->assertOk();

        // The callback's amount check would refuse a settlement that
        // disagreed with the session, so the session's amount stands.
        $this->assertEquals(299000, (float) $registration->fresh()->invoice->amount);
    }

    public function test_the_reprice_is_written_down(): void
    {
        $registration = $this->staleOrder();

        $this->get(route('register.status', $registration->token));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => TenantRegistration::class,
            'subject_id' => $registration->id,
        ]);
    }
}
