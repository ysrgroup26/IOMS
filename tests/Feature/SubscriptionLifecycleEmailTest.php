<?php

namespace Tests\Feature;

use App\Mail\InvoiceIssued;
use App\Mail\SubscriptionLifecycleNotice;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionLifecycleService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.78.0 -- THE LIFECYCLE, BY EMAIL, AND NOT TOO OFTEN.
 *
 *   upcoming expiry  → the renewal invoice email (once, inside the lead window)
 *   grace            → one email when the period ends
 *   lapsed           → one email when recording pauses
 *   renewed          → one email, only after a VERIFIED payment is applied
 */
class SubscriptionLifecycleEmailTest extends TestCase
{
    use RefreshDatabase;

    private function subscribed(array $subscription = []): Subscription
    {
        $package = Package::create([
            'name' => 'Business', 'slug' => 'business-'.uniqid(), 'is_active' => true, 'is_public' => true,
            'price_monthly' => 1000000, 'price_yearly' => 10000000, 'currency' => 'IDR',
        ]);

        $tenant = Tenant::create(['name' => 'Galangan Nusantara', 'slug' => 'gn-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        Company::withoutGlobalScopes()->create(['name' => 'GN', 'code' => 'GN'.rand(10, 99), 'tenant_id' => $tenant->id, 'is_active' => true]);

        app(CurrentTenant::class)->set($tenant);
        User::create([
            'name' => 'Admin', 'email' => 'admin@gn.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);
        // Not an administrator: must not receive billing mail.
        User::create([
            'name' => 'Supervisor', 'email' => 'spv@gn.test', 'password' => bcrypt('x'),
            'role' => 'manager', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        return Subscription::create(array_merge([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonths(2), 'ends_at' => now()->addMonth(),
            'agreed_price_monthly' => 1000000, 'agreed_price_yearly' => 10000000, 'agreed_currency' => 'IDR',
        ], $subscription));
    }

    private function lifecycleMails(string $event): \Illuminate\Support\Collection
    {
        return Mail::sent(SubscriptionLifecycleNotice::class, fn ($m) => $m->event === $event);
    }

    /* ------------------------------------------------------------------ */
    /* Grace and lapsed: once per state per period                         */
    /* ------------------------------------------------------------------ */

    public function test_an_active_subscription_receives_no_lifecycle_email(): void
    {
        Mail::fake();
        $this->subscribed();

        $this->artisan('subscriptions:lifecycle')->assertSuccessful();

        Mail::assertNotSent(SubscriptionLifecycleNotice::class);
    }

    public function test_entering_grace_is_emailed_once_to_administrators_only(): void
    {
        Mail::fake();
        $this->subscribed(['ends_at' => now()->subDays(2)]);

        $this->artisan('subscriptions:lifecycle');
        $this->artisan('subscriptions:lifecycle');   // the next night
        $this->artisan('subscriptions:lifecycle');   // and the one after

        $sent = $this->lifecycleMails('grace');
        $this->assertCount(1, $sent, 'Grace must be emailed once, not every night.');
        $this->assertTrue($sent->first()->hasTo('admin@gn.test'));
        Mail::assertNotSent(SubscriptionLifecycleNotice::class, fn ($m) => $m->hasTo('spv@gn.test'));
    }

    public function test_entering_lapse_is_emailed_once(): void
    {
        Mail::fake();
        // Grace ended three days ago: a fresh lapse.
        $this->subscribed(['ends_at' => now()->subDays(17)]);

        $this->artisan('subscriptions:lifecycle');
        $this->artisan('subscriptions:lifecycle');

        $this->assertCount(1, $this->lifecycleMails('lapsed'));
        $this->assertCount(0, $this->lifecycleMails('grace'), 'A lapsed subscription is not told it is in grace.');
    }

    /** Deploying this must not email every organization that lapsed months ago. */
    public function test_an_old_lapse_is_not_announced(): void
    {
        Mail::fake();
        $this->subscribed(['ends_at' => now()->subDays(120)]);

        $this->artisan('subscriptions:lifecycle');

        Mail::assertNotSent(SubscriptionLifecycleNotice::class);
    }

    /** grace → lapsed is a new state, so it is a new (single) email. */
    public function test_grace_then_lapse_sends_exactly_one_of_each(): void
    {
        Mail::fake();
        $subscription = $this->subscribed(['ends_at' => now()->subDays(2)]);

        $this->artisan('subscriptions:lifecycle');
        $this->travel(13)->days();
        $this->artisan('subscriptions:lifecycle');
        $this->artisan('subscriptions:lifecycle');

        $this->assertCount(1, $this->lifecycleMails('grace'));
        $this->assertCount(1, $this->lifecycleMails('lapsed'));
        $this->assertSame('lapsed:'.$subscription->fresh()->periodEndsAt()->toDateString(), $subscription->fresh()->lifecycle_notified);
    }

    /* ------------------------------------------------------------------ */
    /* Renewal: only after a verified payment                              */
    /* ------------------------------------------------------------------ */

    public function test_a_verified_renewal_payment_sends_one_renewed_email(): void
    {
        Mail::fake();
        $subscription = $this->subscribed(['ends_at' => now()->subDays(20)]); // lapsed
        $invoice = app(SubscriptionLifecycleService::class)->issueRenewalInvoice($subscription, force: true);

        $this->settle($invoice)->assertOk();
        $this->settle($invoice)->assertOk();   // the provider retries

        $sent = $this->lifecycleMails('renewed');
        $this->assertCount(1, $sent, 'A replayed notification must not send a second confirmation.');
        $this->assertTrue($sent->first()->writesRestored, 'It was read-only; the email must say recording is back.');
        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $subscription->fresh()->lifecycleState());
    }

    public function test_a_forged_payment_notification_sends_no_email(): void
    {
        Mail::fake();
        $subscription = $this->subscribed(['ends_at' => now()->subDays(20)]);
        $invoice = app(SubscriptionLifecycleService::class)->issueRenewalInvoice($subscription, force: true);

        config(['payment.gateway' => 'midtrans', 'payment.midtrans.server_key' => 'test-server-key']);
        $this->postJson(route('webhooks.payment.midtrans'), [
            'order_id' => 'INV'.$invoice->id.'-1', 'status_code' => '200',
            'gross_amount' => number_format((float) $invoice->amount, 2, '.', ''),
            'transaction_status' => 'settlement', 'signature_key' => 'forged',
        ]);

        $this->assertCount(0, $this->lifecycleMails('renewed'));
    }

    /** A payment extends a suspended subscription's period but does not restore access -- so no "renewed". */
    public function test_a_suspended_subscription_is_not_told_it_was_renewed(): void
    {
        Mail::fake();
        $subscription = $this->subscribed(['status' => Subscription::STATUS_SUSPENDED]);
        $invoice = Invoice::create([
            'tenant_id' => $subscription->tenant_id, 'subscription_id' => $subscription->id,
            'invoice_number' => 'INV-TEST-1', 'amount' => 1000000, 'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED, 'purpose' => Invoice::PURPOSE_RENEWAL,
            'issue_date' => now(), 'due_date' => now()->addDays(14),
        ]);

        app(SubscriptionLifecycleService::class)->applyPaidInvoice($invoice);

        $this->assertCount(0, $this->lifecycleMails('renewed'));
    }

    /* ------------------------------------------------------------------ */
    /* Content                                                             */
    /* ------------------------------------------------------------------ */

    public function test_each_email_states_the_state_the_date_and_the_action(): void
    {
        $subscription = $this->subscribed(['ends_at' => now()->subDays(2)])->load('tenant', 'package');
        $billing = route('subscription.billing');

        $grace = (new SubscriptionLifecycleNotice($subscription, 'grace', $billing))->render();
        $this->assertStringContainsString($subscription->graceEndsAt()->format('d M Y'), $grace);
        $this->assertStringContainsString('read-only', $grace);
        $this->assertStringContainsString('Renew subscription', $grace);
        $this->assertStringContainsString('href="'.$billing.'"', $grace);

        $lapsed = (new SubscriptionLifecycleNotice($subscription, 'lapsed', $billing))->render();
        $this->assertStringContainsString('Nothing has been deleted', $lapsed);
        $this->assertStringContainsString('Renew subscription', $lapsed);

        $renewed = (new SubscriptionLifecycleNotice($subscription, 'renewed', $billing, writesRestored: true))->render();
        $this->assertStringContainsString('Recording new data is available again', $renewed);

        // The existing IOMS identity, and the canonical email logo.
        $envelope = (new SubscriptionLifecycleNotice($subscription, 'grace', $billing))->envelope();
        $this->assertSame('noreply@iomsuite.com', $envelope->from->address);
        $this->assertSame('IOMS', $envelope->from->name);
        $this->assertSame(config('ioms.emails.billing'), $envelope->replyTo[0]->address);
        $this->assertStringContainsString('https://iomsuite.com/branding/ioms-logo-email.png', $grace);
    }

    /** The renewal invoice is the "about to expire" email -- it must not read like onboarding. */
    public function test_the_renewal_invoice_email_speaks_to_an_existing_customer(): void
    {
        $subscription = $this->subscribed(['ends_at' => now()->addDays(10)]);
        $invoice = app(SubscriptionLifecycleService::class)->issueRenewalInvoice($subscription, force: true);

        $html = (new InvoiceIssued($invoice, 'Business', 'Rp1.000.000', route('subscription.pay', $invoice)))->render();

        $this->assertStringContainsString('period ends on', $html);
        $this->assertStringContainsString($subscription->ends_at->format('d M Y'), $html);
        $this->assertStringNotContainsString('workspace activates', $html);
    }

    private function settle(Invoice $invoice): \Illuminate\Testing\TestResponse
    {
        $serverKey = 'test-server-key';
        config([
            'payment.gateway' => 'midtrans',
            'payment.midtrans.server_key' => $serverKey,
            'payment.midtrans.client_key' => 'test-client-key',
        ]);

        $orderId = 'INV'.$invoice->id.'-20260101120000';
        $gross = number_format((float) $invoice->amount, 2, '.', '');

        PaymentTransaction::firstOrCreate(['gateway_reference' => $orderId], [
            'invoice_id' => $invoice->id, 'gateway' => 'midtrans', 'status' => 'pending',
            'amount' => $invoice->amount, 'currency' => $invoice->currency,
        ]);

        $payload = [
            'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => $gross,
            'transaction_status' => 'settlement', 'transaction_id' => 'txn-email',
        ];
        $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$serverKey);

        return $this->postJson(route('webhooks.payment.midtrans'), $payload);
    }
}
