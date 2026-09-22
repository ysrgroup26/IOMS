<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * v2.78.1 -- WHAT A PAYMENT-PROVIDER REVIEWER CAN SEE, AND WHAT THEY CANNOT.
 *
 * Midtrans reviews the website's usage and transaction flow with a dummy
 * account. This walks both halves of that review through the real routes,
 * exactly as docs/ADR/038 § "Payment-provider review" tells the reviewer to:
 *
 *   A. a brand-new customer: register → verify → set up a subscription →
 *      order → checkout. Stops at payment. Nothing is provisioned.
 *   B. an existing customer's billing: renewal and upgrade, on a reviewer
 *      tenant created through the operator's ordinary Create Tenant form.
 *      Stops at payment. Nothing is extended or changed.
 *
 * And the boundary: the reviewer's administrator role is the SAME role
 * every paying customer's first user holds -- scoped to its own tenant --
 * with no platform console and no reach into another customer.
 *
 * No payment succeeds anywhere in this file. That is the point.
 */
class ReviewerJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PackageSeeder::class);
        Mail::fake();
    }

    /* ------------------------------------------------------------------ */
    /* A. A new customer, up to payment                                    */
    /* ------------------------------------------------------------------ */

    public function test_a_new_customer_can_reach_payment_and_nothing_is_provisioned_before_it(): void
    {
        // 1. Register -- creates a person, nothing else.
        $this->post(route('register.account'), [
            'name' => 'Midtrans Reviewer', 'email' => 'reviewer@example.test',
            'password' => 'Perancah2026kuat', 'password_confirmation' => 'Perancah2026kuat', 'terms' => true,
        ])->assertRedirect(route('register.welcome'));

        $reviewer = User::withoutGlobalScopes()->where('email', 'reviewer@example.test')->firstOrFail();
        $this->assertSame(User::ROLE_ACCOUNT, $reviewer->role);
        $this->assertNull($reviewer->tenant_id);

        // 2. Email verification gates buying. Unverified: sent back.
        $this->actingAs($reviewer)->get(route('subscribe.setup'))->assertRedirect(route('account.overview'));

        $this->actingAs($reviewer)->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $reviewer->id, 'hash' => sha1($reviewer->email),
        ]))->assertRedirect();
        $this->assertTrue($reviewer->fresh()->hasVerifiedEmail());

        // 3–4. The subscription setup page, with the plans.
        $this->actingAs($reviewer->fresh())->get(route('subscribe.setup'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Public/GetStarted')->has('plans'));

        // 5. Order → the order summary page.
        $this->actingAs($reviewer->fresh())->post(route('subscribe.store'), [
            'plan' => 'business', 'billing_cycle' => 'monthly', 'terms' => true,
            'company_legal_name' => 'PT Review Midtrans', 'company_address' => 'Jl. Contoh 1',
            'company_city' => 'Jakarta', 'company_province' => 'DKI Jakarta',
        ])->assertRedirect();

        $order = TenantRegistration::where('user_id', $reviewer->id)->firstOrFail();
        $this->get(route('register.status', $order->token))->assertOk();

        // 6. Checkout issues a real invoice and stops there.
        $this->post(route('register.checkout', $order->token))->assertRedirect();

        $order->refresh();
        $this->assertSame(TenantRegistration::STATUS_AWAITING_PAYMENT, $order->status);
        $this->assertNotNull($order->invoice_id);
        $this->assertNotSame(Invoice::STATUS_PAID, Invoice::withoutGlobalScopes()->find($order->invoice_id)->status);

        // Nothing exists until a VERIFIED payment: no tenant, no subscription.
        $this->assertNull($reviewer->fresh()->tenant_id);
        $this->assertSame(User::ROLE_ACCOUNT, $reviewer->fresh()->role);
        $this->assertSame(0, Subscription::withoutGlobalScopes()->whereHas('tenant', fn ($q) => $q->where('name', 'like', '%Review Midtrans%'))->count());
    }

    /* ------------------------------------------------------------------ */
    /* B. An existing customer's renewal and upgrade, up to payment        */
    /* ------------------------------------------------------------------ */

    /** The reviewer tenant, created through the operator's ordinary form. */
    private function reviewerTenant(string $plan = 'starter'): User
    {
        $operator = User::create([
            'name' => 'Operator', 'email' => 'operator@iomsuite.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);

        $this->actingAs($operator)->post(route('platform.tenants.store'), [
            'name' => 'Midtrans Review', 'slug' => 'midtrans-review',
            'package_id' => Package::where('slug', $plan)->value('id'),
            'status' => Tenant::STATUS_ACTIVE,
            'admin_name' => 'Midtrans Reviewer', 'admin_email' => 'reviewer@example.test',
            'admin_password' => 'Perancah2026kuat', 'admin_password_confirmation' => 'Perancah2026kuat',
        ])->assertSessionHasNoErrors()->assertRedirect();

        auth()->logout();

        return User::withoutGlobalScopes()->where('email', 'reviewer@example.test')->firstOrFail();
    }

    public function test_the_reviewer_account_is_an_ordinary_customer_administrator(): void
    {
        $reviewer = $this->reviewerTenant();

        // The same role every paying customer's first user holds -- scoped
        // to its own tenant. NOT the platform operator.
        $this->assertSame(User::ROLE_SUPER_ADMIN, $reviewer->role);
        $this->assertNotNull($reviewer->tenant_id);
        $this->assertFalse($reviewer->isPlatformAdmin());

        // No platform console.
        $this->assertNotSame(200, $this->actingAs($reviewer)->get(route('platform.dashboard'))->getStatusCode());
        $this->assertNotSame(200, $this->actingAs($reviewer)->get(route('platform.tenants'))->getStatusCode());

        // The product itself works for it.
        $this->actingAs($reviewer)->get(route('dashboard'))->assertOk();
        $this->actingAs($reviewer)->get(route('subscription.billing'))->assertOk();
        $this->actingAs($reviewer)->get(route('subscription.plans'))->assertOk();
    }

    public function test_the_reviewer_cannot_reach_another_customers_data_or_invoices(): void
    {
        $reviewer = $this->reviewerTenant();

        // Another customer, with an invoice of its own.
        $other = Tenant::create(['name' => 'Other Customer', 'slug' => 'other-customer', 'status' => Tenant::STATUS_ACTIVE]);
        $otherSubscription = Subscription::create([
            'tenant_id' => $other->id, 'package_id' => Package::where('slug', 'business')->value('id'),
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription', 'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
        ]);
        $foreignInvoice = Invoice::create([
            'tenant_id' => $other->id, 'subscription_id' => $otherSubscription->id,
            'invoice_number' => 'INV-OTHER-1', 'amount' => 1, 'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED, 'purpose' => Invoice::PURPOSE_RENEWAL,
            'issue_date' => now(), 'due_date' => now()->addDays(14),
        ]);

        $this->actingAs($reviewer)->get(route('subscription.pay', $foreignInvoice))->assertNotFound();
        $this->actingAs($reviewer)->get(route('subscription.invoices.pdf', $foreignInvoice))->assertNotFound();

        $billing = $this->actingAs($reviewer)->get(route('subscription.billing'))->getContent();
        $this->assertStringNotContainsString('INV-OTHER-1', $billing);
        $this->assertStringNotContainsString('Other Customer', $billing);
    }

    public function test_the_reviewer_can_walk_renewal_up_to_payment_without_anything_being_extended(): void
    {
        $reviewer = $this->reviewerTenant();
        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $reviewer->tenant_id)->firstOrFail();
        $endsBefore = $subscription->ends_at->toDateTimeString();

        $this->actingAs($reviewer)->post(route('subscription.renew'))->assertRedirect();

        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $reviewer->tenant_id)->latest('id')->firstOrFail();
        $this->assertSame(Invoice::PURPOSE_RENEWAL, $invoice->purpose);
        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->status);

        // The invoice is visible where the reviewer will look for it.
        $this->actingAs($reviewer)->get(route('subscription.billing'))
            ->assertInertia(fn ($p) => $p->where('outstandingInvoice.invoice_number', $invoice->invoice_number));

        // Pay: with no gateway on this deployment, an honest message --
        // never a fake success. (With Midtrans configured, the checkout.)
        $this->actingAs($reviewer)->get(route('subscription.pay', $invoice))->assertRedirect(route('subscription.billing'));

        // Nothing moved without a verified payment.
        $this->assertSame($endsBefore, $subscription->fresh()->ends_at->toDateTimeString());
    }

    /**
     * The confirmation dialog states ONE outcome, and it is the outcome the
     * server then produces: the previewed upgrade amount is the invoice
     * amount, to the rupiah.
     */
    public function test_the_plan_change_preview_matches_what_the_server_then_does(): void
    {
        $reviewer = $this->reviewerTenant('starter');
        $business = Package::where('slug', 'business')->firstOrFail();
        $starter = Package::where('slug', 'starter')->firstOrFail();

        $preview = $this->actingAs($reviewer)->get(route('subscription.plans'))
            ->assertOk()
            ->viewData('page')['props']['changePreview'];

        $this->assertSame('current', $preview[$starter->id]['monthly']['kind']);
        $this->assertSame('upgrade', $preview[$business->id]['monthly']['kind']);
        $this->assertSame('scheduled', $preview[$starter->id]['yearly']['kind'], 'A cycle change is scheduled, not billed today.');

        $this->actingAs($reviewer)->post(route('subscription.plan-change'), [
            'package_id' => $business->id, 'billing_cycle' => 'monthly',
        ]);

        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $reviewer->tenant_id)->latest('id')->firstOrFail();
        $this->assertSame(
            app(\App\Services\PricingService::class)->format((float) $invoice->amount, $invoice->currency),
            $preview[$business->id]['monthly']['amount_formatted'],
            'The dialog promised one amount and the invoice charged another.'
        );
    }

    public function test_the_reviewer_can_walk_an_upgrade_up_to_payment_without_the_plan_changing(): void
    {
        $reviewer = $this->reviewerTenant('starter');
        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $reviewer->tenant_id)->firstOrFail();
        $starter = $subscription->package_id;

        $this->actingAs($reviewer)->post(route('subscription.plan-change'), [
            'package_id' => Package::where('slug', 'business')->value('id'),
            'billing_cycle' => 'monthly',
        ])->assertRedirect();

        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $reviewer->tenant_id)->latest('id')->firstOrFail();
        $this->assertSame(Invoice::PURPOSE_PLAN_CHANGE, $invoice->purpose);
        $this->assertGreaterThan(0, (float) $invoice->amount, 'An upgrade is billed, prorated.');
        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->status);

        // Still on Starter until a verified payment applies the change.
        $this->assertSame($starter, $subscription->fresh()->package_id);
    }
}
