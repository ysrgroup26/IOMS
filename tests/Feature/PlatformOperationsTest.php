<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PlatformOperationsService;
use App\Services\SubscriptionLifecycleService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.79.0 -- "HOW IS THE PLATFORM OPERATING?"
 *
 * Two properties, both easy to get wrong:
 *
 *   1. The operations figures are DERIVED. A subscription whose period
 *      ended six weeks ago still stores `active`, so counting stored
 *      status would report a healthy platform while customers sit
 *      read-only.
 *   2. Platform notifications reach the OPERATOR and nobody else. They
 *      describe the operator's business -- who bought, who lapsed, whose
 *      payment failed -- and must never appear in a tenant's bell.
 */
class PlatformOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function package(string $slug = 'starter'): Package
    {
        return Package::firstOrCreate(['slug' => $slug], [
            'name' => ucfirst($slug), 'price_monthly' => 1000000, 'price_yearly' => 10000000,
            'currency' => 'IDR', 'max_users' => 10, 'max_companies' => 1, 'is_active' => true, 'is_public' => true,
        ]);
    }

    private function tenantWith(array $subscription, string $name = 'Customer'): Tenant
    {
        $suffix = uniqid();
        $tenant = Tenant::create(['name' => $name, 'slug' => 'c-'.$suffix, 'status' => Tenant::STATUS_ACTIVE]);
        Company::withoutGlobalScopes()->create(['name' => $name, 'code' => strtoupper(substr($suffix, 0, 5)), 'tenant_id' => $tenant->id, 'is_active' => true]);

        Subscription::create(array_merge([
            'tenant_id' => $tenant->id, 'package_id' => $this->package()->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription', 'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
            'agreed_price_monthly' => 1000000, 'agreed_price_yearly' => 10000000, 'agreed_currency' => 'IDR',
        ], $subscription));

        return $tenant->fresh();
    }

    private function operator(): User
    {
        return User::create([
            'name' => 'Operator', 'email' => 'ops-'.uniqid().'@iomsuite.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The figures                                                         */
    /* ------------------------------------------------------------------ */

    /** Where a subscription sits in TIME, not what its status column says. */
    public function test_subscription_health_is_derived_from_dates(): void
    {
        $this->tenantWith(['ends_at' => now()->addMonths(2)], 'Healthy');
        $this->tenantWith(['ends_at' => now()->addDays(5)], 'Expiring');       // inside the renewal lead window
        $this->tenantWith(['ends_at' => now()->subDays(3)], 'In Grace');
        $this->tenantWith(['ends_at' => now()->subDays(40)], 'Lapsed');
        $this->tenantWith(['status' => Subscription::STATUS_SUSPENDED], 'Suspended');

        $health = app(PlatformOperationsService::class)->subscriptionHealth();

        $this->assertSame(1, $health['counts']['active']);
        $this->assertSame(1, $health['counts']['expiring']);
        $this->assertSame(1, $health['counts']['grace']);
        $this->assertSame(1, $health['counts']['lapsed'], 'A lapsed subscription still STORES active -- it must be counted by its dates.');
        $this->assertSame(1, $health['counts']['blocked']);

        // The working list holds only what needs chasing, soonest first.
        $names = array_column($health['attention'], 'tenant');
        $this->assertSame(['Lapsed', 'In Grace', 'Expiring'], $names);
        $this->assertNotContains('Healthy', $names);
    }

    public function test_payment_activity_sums_real_invoices_across_providers(): void
    {
        $tenant = $this->tenantWith([]);
        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $paid = Invoice::create([
            'tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'invoice_number' => 'INV-P-1',
            'amount' => 1000000, 'currency' => 'IDR', 'status' => Invoice::STATUS_PAID,
            'issue_date' => now()->subDays(3), 'due_date' => now()->addDays(11), 'payment_date' => now()->subDays(2),
        ]);
        Invoice::create([
            'tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'invoice_number' => 'INV-O-1',
            'amount' => 250000, 'currency' => 'IDR', 'status' => Invoice::STATUS_ISSUED,
            'issue_date' => now()->subDays(20), 'due_date' => now()->subDays(6),
        ]);
        PaymentTransaction::create([
            'invoice_id' => $paid->id, 'gateway' => 'midtrans', 'gateway_reference' => 'ref-ok',
            'status' => 'paid', 'amount' => 1000000, 'currency' => 'IDR',
        ]);
        PaymentTransaction::create([
            'invoice_id' => $paid->id, 'gateway' => 'midtrans', 'gateway_reference' => 'ref-bad',
            'status' => 'failed', 'amount' => 1000000, 'currency' => 'IDR',
        ]);

        $payments = app(PlatformOperationsService::class)->paymentActivity();

        $this->assertSame(1, $payments['paid_count']);
        $this->assertEquals(1000000.0, $payments['paid_amount']);
        $this->assertSame(1, $payments['outstanding_count']);
        $this->assertEquals(250000.0, $payments['outstanding_amount']);
        $this->assertSame(1, $payments['overdue_count']);
        $this->assertSame(1, $payments['failed_attempts']);
        $this->assertSame(['midtrans' => 1], $payments['by_provider'], 'Provider-agnostic: read from transactions, not hardcoded.');
    }

    /** A demo tenant is never invoiced, so it is not operator workload. */
    public function test_the_demo_tenant_is_not_counted_as_workload(): void
    {
        $demo = $this->tenantWith(['ends_at' => now()->subDays(40)], 'Nusantara Marine Works (Demo)');
        $demo->forceFill(['is_demo' => true])->save();

        $health = app(PlatformOperationsService::class)->subscriptionHealth();

        $this->assertSame(0, $health['counts']['lapsed']);
        $this->assertSame([], $health['attention']);
    }

    public function test_the_dashboard_serves_the_operations_payload_to_the_operator(): void
    {
        $this->tenantWith(['ends_at' => now()->subDays(3)], 'In Grace');

        $this->actingAs($this->operator())->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Dashboard')
                ->where('operations.subscriptions.counts.grace', 1)
                ->has('operations.payments')
                ->has('platform_events'));
    }

    /* ------------------------------------------------------------------ */
    /* Platform notifications                                              */
    /* ------------------------------------------------------------------ */

    /** A verified renewal tells the operator, and nobody else. */
    public function test_a_verified_renewal_notifies_platform_admins_only(): void
    {
        Mail::fake();
        $operator = $this->operator();
        $tenant = $this->tenantWith(['ends_at' => now()->subDays(40)], 'Renewing Co');
        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        app(CurrentTenant::class)->set($tenant);
        $tenantUser = User::create([
            'name' => 'Tenant Admin', 'email' => 'ta-'.uniqid().'@customer.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $invoice = app(SubscriptionLifecycleService::class)->issueRenewalInvoice($subscription, force: true);
        app(SubscriptionLifecycleService::class)->applyPaidInvoice($invoice->fresh());

        $forOperator = Notification::withoutGlobalScopes()->where('user_id', $operator->id)->get();
        $this->assertCount(1, $forOperator);
        $this->assertStringContainsString('Renewing Co', $forOperator->first()->title);
        $this->assertStringContainsString('diaktifkan kembali', $forOperator->first()->title, 'It was read-only; the operator is told it came back.');

        $this->assertSame(
            0,
            Notification::withoutGlobalScopes()->where('user_id', $tenantUser->id)->count(),
            'Platform business events must not appear in a tenant user\'s notifications.'
        );
    }

    /** Entering grace and going read-only are each announced once. */
    public function test_grace_and_lapse_transitions_notify_the_operator_once(): void
    {
        Mail::fake();
        $operator = $this->operator();
        $this->tenantWith(['ends_at' => now()->subDays(2)], 'Slipping Co');

        $this->artisan('subscriptions:lifecycle');
        $this->artisan('subscriptions:lifecycle');

        $titles = Notification::withoutGlobalScopes()->where('user_id', $operator->id)->pluck('title');

        $this->assertCount(1, $titles->filter(fn ($t) => str_contains($t, 'Masa tenggang dimulai')));

        // Past the configured grace window, while the lapse is still fresh.
        $this->travel(Subscription::graceDays() + 1)->days();
        $this->artisan('subscriptions:lifecycle');
        $this->artisan('subscriptions:lifecycle');

        $titles = Notification::withoutGlobalScopes()->where('user_id', $operator->id)->pluck('title');
        $this->assertCount(1, $titles->filter(fn ($t) => str_contains($t, 'read-only')));
    }

    /** A failed payment is visibility, not a state change. */
    public function test_a_failed_payment_notifies_the_operator_and_changes_nothing(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenantWith([], 'Failing Co');
        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $endsBefore = $subscription->ends_at->toDateTimeString();

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'invoice_number' => 'INV-F-1',
            'amount' => 1000000, 'currency' => 'IDR', 'status' => Invoice::STATUS_ISSUED,
            'purpose' => Invoice::PURPOSE_RENEWAL, 'issue_date' => now(), 'due_date' => now()->addDays(14),
        ]);

        $serverKey = 'test-server-key';
        config(['payment.gateway' => 'midtrans', 'payment.midtrans.server_key' => $serverKey, 'payment.midtrans.client_key' => 'ck']);

        $orderId = 'INV'.$invoice->id.'-20260101120000';
        PaymentTransaction::create([
            'invoice_id' => $invoice->id, 'gateway' => 'midtrans', 'gateway_reference' => $orderId,
            'status' => 'pending', 'amount' => $invoice->amount, 'currency' => 'IDR',
        ]);

        $payload = [
            'order_id' => $orderId, 'status_code' => '202', 'gross_amount' => '1000000.00',
            'transaction_status' => 'deny', 'transaction_id' => 'txn-fail',
        ];
        $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$serverKey);

        $this->postJson(route('webhooks.payment.midtrans'), $payload)->assertOk();

        $this->assertSame(1, Notification::withoutGlobalScopes()->where('user_id', $operator->id)
            ->where('title', 'like', 'Pembayaran gagal%')->count());
        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame($endsBefore, $subscription->fresh()->ends_at->toDateTimeString(), 'A failed payment must extend nothing.');
    }

    /** The operations console stays closed to tenants. */
    public function test_a_tenant_administrator_cannot_reach_the_operations_console(): void
    {
        $tenant = $this->tenantWith([]);
        app(CurrentTenant::class)->set($tenant);
        $tenant->workspaces()->sync(Workspace::pluck('id'));

        $admin = User::create([
            'name' => 'Tenant Admin', 'email' => 'x-'.uniqid().'@customer.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $this->assertNotSame(200, $this->actingAs($admin)->get(route('platform.dashboard'))->getStatusCode());
    }
}
