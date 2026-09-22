<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Services\SubscriptionLifecycleService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.70.0 -- THE SUBSCRIPTION LIFECYCLE, END TO END.
 *
 * These tests exist because every one of them covers something that
 * either had no implementation before this release or had one that was
 * quietly wrong:
 *
 *  - Time state was STORED and written by nothing, so `status` said
 *    "active" on a subscription that ran out months ago.
 *  - No renewal invoice was ever issued by anything.
 *  - The webhook could settle an invoice and had no idea what it bought.
 *  - A Platform Admin moving a tenant between plans left the tenant's
 *    entitlements on the old plan and its agreed price on the old price.
 *  - `tenants.status` had a suspend button that wrote a column nothing
 *    read.
 *
 * THE INVARIANT EVERY TEST HERE DEFENDS: no lifecycle transition ever
 * removes a customer's data or their access to READ it. Lapsing is
 * read-only, not a lockout. That is asserted directly, not assumed.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        config(['saas.grace_days' => 14, 'saas.renewal_lead_days' => 14]);
    }

    /* ==================================================================
     * Fixtures
     * ================================================================== */

    /**
     * The real catalogue row for a slug, forced to the prices this test
     * needs.
     *
     * The slug matters and cannot be randomised: `defaultWorkspaceKeys()`
     * resolves a plan's departments from config/plans.php BY SLUG, so the
     * grant-sync assertions would compare two identical fallback sets if
     * these were made-up names. The prices are overwritten because the
     * seeded figures move with the real catalogue, and a test that changes
     * its expected arithmetic every time marketing reprices is worthless.
     */
    private function plan(string $slug, array $attributes = []): Package
    {
        $package = Package::firstOrCreate(['slug' => $slug], array_merge([
            'name' => ucfirst($slug),
            'price_monthly' => 1000000, 'price_yearly' => 10000000, 'currency' => 'IDR',
            'max_users' => 10, 'max_companies' => 1, 'is_active' => true, 'is_public' => true,
        ], $attributes));

        if ($attributes !== []) {
            $package->update($attributes);
        }

        return $package->fresh();
    }

    /** A tenant provisioned the way TenantProvisioningService does it. */
    private function subscribedTenant(array $subscription = [], string $slug = 'starter'): Tenant
    {
        $package = $this->plan($slug, ['price_monthly' => 1000000, 'price_yearly' => 10000000]);
        $suffix = uniqid();

        $tenant = Tenant::create([
            'name' => 'Customer '.$suffix, 'slug' => 'customer-'.$suffix, 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Company::withoutGlobalScopes()->create([
            'name' => 'Yard', 'code' => strtoupper(substr($suffix, 0, 6)), 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create(array_merge([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
            'agreed_price_monthly' => $package->price_monthly,
            'agreed_price_yearly' => $package->price_yearly,
            'agreed_currency' => $package->currency,
        ], $subscription));

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));

        return $tenant->fresh();
    }

    private function adminFor(Tenant $tenant): User
    {
        app(CurrentTenant::class)->set($tenant);

        return User::create([
            'name' => 'Admin', 'email' => uniqid().'@customer.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);
    }

    private function lifecycle(): SubscriptionLifecycleService
    {
        return app(SubscriptionLifecycleService::class);
    }

    /** Configure Midtrans and post a correctly-signed settlement for an invoice. */
    private function settle(Invoice $invoice, ?string $amount = null, string $transactionId = 'txn-1'): \Illuminate\Testing\TestResponse
    {
        $serverKey = 'test-server-key';
        config([
            'payment.gateway' => 'midtrans',
            'payment.midtrans.server_key' => $serverKey,
            'payment.midtrans.client_key' => 'test-client-key',
        ]);

        $orderId = 'INV'.$invoice->id.'-20260101120000';
        $gross = $amount ?? number_format((float) $invoice->amount, 2, '.', '');

        PaymentTransaction::firstOrCreate(
            ['gateway_reference' => $orderId],
            [
                'invoice_id' => $invoice->id, 'gateway' => 'midtrans', 'status' => 'pending',
                'amount' => $invoice->amount, 'currency' => $invoice->currency,
            ]
        );

        $payload = [
            'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => $gross,
            'transaction_status' => 'settlement', 'transaction_id' => $transactionId,
        ];
        $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$serverKey);

        return $this->postJson(route('webhooks.payment.midtrans'), $payload);
    }

    /* ==================================================================
     * 1. THE DERIVED STATE
     * ================================================================== */

    /** Inside the paid period: fully active, full access. */
    public function test_a_current_subscription_is_active_and_writable(): void
    {
        $subscription = $this->subscribedTenant()->subscription;

        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $subscription->lifecycleState());
        $this->assertTrue($subscription->allowsWrites());
        $this->assertTrue($subscription->allowsReads());
    }

    /** Past the period but inside grace: still fully writable, and said so loudly elsewhere. */
    public function test_a_subscription_inside_the_grace_window_still_allows_writes(): void
    {
        $subscription = $this->subscribedTenant(['ends_at' => now()->subDays(3)])->subscription;

        $this->assertSame(Subscription::LIFECYCLE_GRACE, $subscription->lifecycleState());
        $this->assertTrue($subscription->allowsWrites(), 'Grace must not withdraw writing.');
    }

    /** Past grace: read-only. NEVER a lockout. */
    public function test_a_lapsed_subscription_is_read_only_but_never_blocked(): void
    {
        $subscription = $this->subscribedTenant(['ends_at' => now()->subDays(40)])->subscription;

        $this->assertSame(Subscription::LIFECYCLE_LAPSED, $subscription->lifecycleState());
        $this->assertFalse($subscription->allowsWrites());
        $this->assertTrue($subscription->allowsReads(), 'A lapse must never withdraw reading.');
        $this->assertFalse($subscription->isBlocked());
    }

    /** A deliberate operator decision outranks any date, in both directions. */
    public function test_a_deliberate_status_outranks_the_dates(): void
    {
        $suspended = $this->subscribedTenant([
            'status' => Subscription::STATUS_SUSPENDED, 'ends_at' => now()->addYear(),
        ])->subscription;

        $this->assertSame(Subscription::LIFECYCLE_SUSPENDED, $suspended->lifecycleState());
        $this->assertFalse($suspended->allowsReads());
    }

    /** A lifetime licence has no period to run out of. */
    public function test_a_lifetime_subscription_never_lapses(): void
    {
        $subscription = $this->subscribedTenant(['type' => 'lifetime', 'ends_at' => null])->subscription;

        $this->assertNull($subscription->periodEndsAt());
        $this->assertFalse($subscription->isExpired());
        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $subscription->lifecycleState());
    }

    /** The removed vocabulary must not creep back in. */
    public function test_expired_and_grace_period_are_no_longer_storable_statuses(): void
    {
        $this->assertSame(
            ['trial', 'active', 'suspended', 'cancelled'],
            Subscription::STATUSES,
            'Time states are derived; they must not be stored.'
        );
    }

    /* ==================================================================
     * 2. RENEWAL
     * ================================================================== */

    /** Nothing is invoiced while the period end is still far away. */
    public function test_no_renewal_invoice_is_issued_outside_the_lead_window(): void
    {
        $subscription = $this->subscribedTenant(['ends_at' => now()->addMonths(2)])->subscription;

        $this->assertNull($this->lifecycle()->issueRenewalInvoice($subscription));
        $this->assertSame(0, Invoice::count());
    }

    /** Inside the lead window an invoice is raised, for the NEXT period, at the agreed price. */
    public function test_a_renewal_invoice_is_issued_inside_the_lead_window(): void
    {
        $tenant = $this->subscribedTenant(['ends_at' => now()->addDays(5)]);
        $subscription = $tenant->subscription;

        $invoice = $this->lifecycle()->issueRenewalInvoice($subscription);

        $this->assertNotNull($invoice);
        $this->assertSame(Invoice::PURPOSE_RENEWAL, $invoice->purpose);
        $this->assertSame($tenant->id, $invoice->tenant_id);
        $this->assertSame($subscription->id, $invoice->subscription_id);
        $this->assertSame(1000000.0, (float) $invoice->amount);
        $this->assertTrue(
            $invoice->period_start->isSameDay($subscription->ends_at),
            'The next period must start where the paid one ends, not today.'
        );
    }

    /** Running the job twice must not demand the same money twice. */
    public function test_issuing_a_renewal_invoice_is_idempotent(): void
    {
        $subscription = $this->subscribedTenant(['ends_at' => now()->addDays(5)])->subscription;

        $first = $this->lifecycle()->issueRenewalInvoice($subscription);
        $second = $this->lifecycle()->issueRenewalInvoice($subscription->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::count());
    }

    /** PAYING MUST NOT LIFT AN OPERATOR'S SUSPENSION. */
    public function test_extending_a_suspended_subscription_does_not_reinstate_it(): void
    {
        $subscription = $this->subscribedTenant([
            'status' => Subscription::STATUS_SUSPENDED, 'ends_at' => now()->subDay(),
        ])->subscription;

        $this->lifecycle()->extendPeriod($subscription);

        $subscription = $subscription->fresh();
        $this->assertSame(Subscription::STATUS_SUSPENDED, $subscription->status, 'Money must not overturn a deliberate suspension.');
        $this->assertTrue($subscription->isBlocked());
        // The time is still bought, so reinstating costs the customer nothing.
        $this->assertTrue($subscription->ends_at->isFuture());
    }

    /** A blocked subscription cannot invite its own payment. */
    public function test_a_suspended_subscription_cannot_self_renew(): void
    {
        config(['saas.enforce_entitlement' => true]);

        $tenant = $this->subscribedTenant([
            'status' => Subscription::STATUS_SUSPENDED, 'ends_at' => now()->subDay(),
        ]);
        $admin = $this->adminFor($tenant);

        $this->assertNull($this->lifecycle()->issueRenewalInvoice($tenant->subscription, force: true));

        $this->actingAs($admin)->post(route('subscription.renew'))->assertRedirect();
        $this->assertSame(0, Invoice::where('tenant_id', $tenant->id)->count());

        $this->actingAs($admin)->post(route('subscription.plan-change'), [
            'package_id' => $this->plan('professional')->id,
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
        ])->assertRedirect();
        $this->assertNull($tenant->fresh()->subscription->pending_package_id);
    }

    /** A cancelled subscription is not silently renewed by a nightly job. */
    public function test_a_cancelled_subscription_is_never_auto_renewed(): void
    {
        $subscription = $this->subscribedTenant([
            'status' => Subscription::STATUS_CANCELLED, 'ends_at' => now()->addDays(2),
        ])->subscription;

        $this->assertNull($this->lifecycle()->issueRenewalInvoice($subscription));
    }

    /* ==================================================================
     * 3. PERIOD ARITHMETIC -- the part that silently steals from someone
     * ================================================================== */

    /** Paying early ADDS to the time already bought; it never resets the clock to today. */
    public function test_paying_early_extends_from_the_existing_end_date(): void
    {
        $subscription = $this->subscribedTenant(['ends_at' => now()->addDays(10)])->subscription;
        $expected = $subscription->ends_at->copy()->addMonth();

        $this->lifecycle()->extendPeriod($subscription);

        $this->assertTrue(
            $subscription->fresh()->ends_at->isSameDay($expected),
            'An early renewal must not discard the days already paid for.'
        );
    }

    /** Paying after a lapse starts the new period today, not back-dated into a gap nobody could use. */
    public function test_paying_late_starts_the_new_period_today(): void
    {
        $subscription = $this->subscribedTenant(['ends_at' => now()->subDays(40)])->subscription;

        $this->lifecycle()->extendPeriod($subscription);

        $this->assertTrue(
            $subscription->fresh()->ends_at->isSameDay(now()->addMonth()),
            'A late renewal must not sell a month that has already passed.'
        );
    }

    /** A lapsed customer who pays is writable again immediately. */
    public function test_renewal_restores_write_access_after_a_lapse(): void
    {
        $subscription = $this->subscribedTenant(['ends_at' => now()->subDays(40)])->subscription;
        $this->assertFalse($subscription->allowsWrites(), 'Precondition: lapsed.');

        $this->lifecycle()->extendPeriod($subscription);

        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $subscription->fresh()->lifecycleState());
        $this->assertTrue($subscription->fresh()->allowsWrites());
    }

    /* ==================================================================
     * 4. THE WEBHOOK -- the only thing that may extend a subscription
     * ================================================================== */

    /** A verified settlement of a renewal invoice extends the period. */
    public function test_a_verified_settlement_extends_the_subscription(): void
    {
        Mail::fake();
        $subscription = $this->subscribedTenant(['ends_at' => now()->addDays(5)])->subscription;
        $invoice = $this->lifecycle()->issueRenewalInvoice($subscription);
        $expected = $subscription->ends_at->copy()->addMonth();

        $this->settle($invoice)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertTrue($subscription->fresh()->ends_at->isSameDay($expected));
    }

    /** A retried notification must not buy a second period. */
    public function test_a_replayed_settlement_does_not_extend_twice(): void
    {
        Mail::fake();
        $subscription = $this->subscribedTenant(['ends_at' => now()->addDays(5)])->subscription;
        $invoice = $this->lifecycle()->issueRenewalInvoice($subscription);
        $expected = $subscription->ends_at->copy()->addMonth();

        $this->settle($invoice)->assertOk();
        $this->settle($invoice)->assertOk();

        $this->assertTrue($subscription->fresh()->ends_at->isSameDay($expected), 'A replay must extend nothing.');
        $this->assertSame(1, PaymentWebhookEvent::where('gateway', 'midtrans')->count());
    }

    /** A notification claiming less than the invoice is not a settlement. */
    public function test_an_underpayment_extends_nothing(): void
    {
        Mail::fake();
        $subscription = $this->subscribedTenant(['ends_at' => now()->addDays(5)])->subscription;
        $invoice = $this->lifecycle()->issueRenewalInvoice($subscription);
        $originalEnd = $subscription->ends_at->copy();

        $this->settle($invoice, amount: '1000.00')->assertOk();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertTrue($subscription->fresh()->ends_at->isSameDay($originalEnd));
    }

    /** An unsigned notification is refused before any state is read. */
    public function test_an_unsigned_notification_extends_nothing(): void
    {
        $subscription = $this->subscribedTenant(['ends_at' => now()->addDays(5)])->subscription;
        $invoice = $this->lifecycle()->issueRenewalInvoice($subscription);
        $originalEnd = $subscription->ends_at->copy();

        config([
            'payment.gateway' => 'midtrans',
            'payment.midtrans.server_key' => 'test-server-key',
            'payment.midtrans.client_key' => 'test-client-key',
        ]);

        $this->postJson(route('webhooks.payment.midtrans'), [
            'order_id' => 'INV'.$invoice->id.'-20260101120000',
            'status_code' => '200',
            'gross_amount' => '1000000.00',
            'transaction_status' => 'settlement',
            'signature_key' => 'not-the-real-signature',
        ])->assertForbidden();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertTrue($subscription->fresh()->ends_at->isSameDay($originalEnd));
    }

    /* ==================================================================
     * 5. PLAN CHANGES
     * ================================================================== */

    /** An upgrade is billed prorated and does not take effect until it is paid. */
    public function test_an_upgrade_issues_a_prorated_invoice_and_waits_for_payment(): void
    {
        $tenant = $this->subscribedTenant([
            'starts_at' => now()->subDays(15), 'ends_at' => now()->addDays(15),
        ]);
        $subscription = $tenant->subscription;
        $target = $this->plan('professional', ['price_monthly' => 3000000, 'price_yearly' => 30000000, 'max_users' => 50]);

        $invoice = $this->lifecycle()->requestPlanChange($subscription, $target, Subscription::CYCLE_MONTHLY);

        $this->assertNotNull($invoice, 'An upgrade must produce an invoice.');
        $this->assertSame(Invoice::PURPOSE_PLAN_CHANGE, $invoice->purpose);
        $this->assertSame($target->id, $invoice->target_package_id);
        // Half a month of the 2,000,000 difference.
        $this->assertEqualsWithDelta(1000000.0, (float) $invoice->amount, 50000.0);
        $this->assertSame(
            $subscription->package_id,
            $subscription->fresh()->package_id,
            'An unpaid upgrade must not move the customer yet.'
        );
    }

    /** Paying the upgrade invoice moves the plan AND re-entitles the tenant. */
    public function test_paying_an_upgrade_applies_the_plan_and_resyncs_grants(): void
    {
        Mail::fake();
        $tenant = $this->subscribedTenant([
            'starts_at' => now()->subDays(15), 'ends_at' => now()->addDays(15),
        ]);
        $subscription = $tenant->subscription;
        $target = $this->plan('professional', ['price_monthly' => 3000000, 'price_yearly' => 30000000]);

        $invoice = $this->lifecycle()->requestPlanChange($subscription, $target, Subscription::CYCLE_MONTHLY);
        $endBefore = $subscription->ends_at->copy();

        $this->settle($invoice)->assertOk();

        $subscription = $subscription->fresh();
        $this->assertSame($target->id, $subscription->package_id);
        $this->assertSame(3000000.0, (float) $subscription->agreed_price_monthly, 'An upgrade re-agrees the price.');
        $this->assertTrue($subscription->ends_at->isSameDay($endBefore), 'A plan change buys capability, not time.');

        $expected = Workspace::whereIn('key', $target->defaultWorkspaceKeys())->pluck('id')->sort()->values()->all();
        $actual = $tenant->fresh()->workspaces()->pluck('workspaces.id')->sort()->values()->all();
        $this->assertSame($expected, $actual, 'A paid plan change must re-entitle the tenant.');
    }

    /**
     * v2.78.1 -- an upgrade that also switches monthly → yearly is two
     * changes. It used to be prorated in YEARLY prices over the MONTHLY
     * period that was left: (30,000,000 - 10,000,000) × ½ = 10,000,000 for
     * fifteen days, with the period end unchanged. Now the plan upgrade is
     * prorated in the current cycle and the cycle switch waits for the
     * boundary (ADR 033 §7).
     */
    public function test_a_cross_cycle_upgrade_bills_the_plan_now_and_schedules_the_cycle(): void
    {
        $tenant = $this->subscribedTenant([
            'starts_at' => now()->subDays(15), 'ends_at' => now()->addDays(15),
        ]);
        $subscription = $tenant->subscription;
        $target = $this->plan('professional', ['price_monthly' => 3000000, 'price_yearly' => 30000000]);

        $invoice = $this->lifecycle()->requestPlanChange($subscription, $target, Subscription::CYCLE_YEARLY);

        // Half a month of the MONTHLY difference -- not of the yearly one.
        $this->assertEqualsWithDelta(1000000.0, (float) $invoice->amount, 50000.0);
        $this->assertSame(Subscription::CYCLE_MONTHLY, $invoice->target_billing_cycle);

        $subscription = $subscription->fresh();
        $this->assertSame(Subscription::CYCLE_YEARLY, $subscription->pending_billing_cycle, 'The cycle switch is scheduled.');
        $this->assertSame(Subscription::CYCLE_MONTHLY, $subscription->billing_cycle, 'Not applied mid-period.');
    }

    /** Paying the upgrade must not silently drop the yearly switch the customer asked for. */
    public function test_paying_a_cross_cycle_upgrade_keeps_the_scheduled_cycle_switch(): void
    {
        Mail::fake();
        $tenant = $this->subscribedTenant([
            'starts_at' => now()->subDays(15), 'ends_at' => now()->addDays(15),
        ]);
        $subscription = $tenant->subscription;
        $target = $this->plan('professional', ['price_monthly' => 3000000, 'price_yearly' => 30000000]);
        $endBefore = $subscription->ends_at->copy();

        $invoice = $this->lifecycle()->requestPlanChange($subscription, $target, Subscription::CYCLE_YEARLY);
        $this->settle($invoice)->assertOk();

        $subscription = $subscription->fresh();
        $this->assertSame($target->id, $subscription->package_id, 'The plan applies on payment.');
        $this->assertSame(Subscription::CYCLE_MONTHLY, $subscription->billing_cycle);
        $this->assertTrue($subscription->ends_at->isSameDay($endBefore), 'A plan change buys capability, not time.');
        $this->assertSame(Subscription::CYCLE_YEARLY, $subscription->pending_billing_cycle, 'The yearly switch survives payment.');

        // And the next renewal bills the new plan on the new cycle.
        [$cycle, $package] = $this->lifecycle()->nextPeriodPlan($subscription);
        $this->assertSame(Subscription::CYCLE_YEARLY, $cycle);
        $this->assertSame($target->id, $package->id);
    }

    /** A downgrade is scheduled, never applied mid-period, and costs nothing today. */
    public function test_a_downgrade_is_deferred_to_the_period_boundary(): void
    {
        $tenant = $this->subscribedTenant([], 'professional');
        $subscription = $tenant->subscription;
        $cheaper = $this->plan('starter-lite', ['price_monthly' => 100000, 'price_yearly' => 1000000]);

        $invoice = $this->lifecycle()->requestPlanChange($subscription, $cheaper, Subscription::CYCLE_MONTHLY);

        $this->assertNull($invoice, 'A downgrade must not be billed today.');
        $this->assertSame(0, Invoice::count());

        $subscription = $subscription->fresh();
        $this->assertSame($cheaper->id, $subscription->pending_package_id);
        $this->assertNotSame($cheaper->id, $subscription->package_id, 'The customer keeps what they paid for.');
    }

    /** A scheduled change can be withdrawn before it takes effect. */
    public function test_a_scheduled_plan_change_can_be_cancelled(): void
    {
        $subscription = $this->subscribedTenant([], 'professional')->subscription;
        $cheaper = $this->plan('starter-lite', ['price_monthly' => 100000, 'price_yearly' => 1000000]);

        $this->lifecycle()->requestPlanChange($subscription, $cheaper, Subscription::CYCLE_MONTHLY);
        $this->lifecycle()->cancelPendingChange($subscription->fresh());

        $this->assertNull($subscription->fresh()->pending_package_id);
    }

    /** The scheduled downgrade is what the next renewal invoice bills for. */
    public function test_a_renewal_invoice_bills_the_scheduled_plan(): void
    {
        $subscription = $this->subscribedTenant(['ends_at' => now()->addDays(5)], 'professional')->subscription;
        $cheaper = $this->plan('starter-lite', ['price_monthly' => 100000, 'price_yearly' => 1000000]);

        $this->lifecycle()->requestPlanChange($subscription, $cheaper, Subscription::CYCLE_MONTHLY);
        $invoice = $this->lifecycle()->issueRenewalInvoice($subscription->fresh());

        $this->assertSame($cheaper->id, $invoice->target_package_id);
        $this->assertSame(100000.0, (float) $invoice->amount);
    }

    /** An upgrade on a lapsed subscription buys a full cycle rather than a negative proration. */
    public function test_proration_is_never_negative(): void
    {
        $subscription = $this->subscribedTenant(['ends_at' => now()->subDays(60)])->subscription;
        $cheaper = $this->plan('starter-lite', ['price_monthly' => 1, 'price_yearly' => 1]);

        $this->assertGreaterThanOrEqual(
            0,
            $this->lifecycle()->upgradeProration($subscription, $cheaper, Subscription::CYCLE_MONTHLY)
        );
    }

    /* ==================================================================
     * 6. ACCESS ENFORCEMENT OVER HTTP
     * ================================================================== */

    /** The whole access model, asserted through the real middleware stack. */
    public function test_a_lapsed_tenant_can_read_everything_and_write_nothing(): void
    {
        config(['saas.enforce_entitlement' => true]);

        $tenant = $this->subscribedTenant(['ends_at' => now()->subDays(40)]);
        $admin = $this->adminFor($tenant);

        // READ: unaffected. This is the property that matters most -- IOMS
        // is a system of record for safety compliance, and withholding it
        // over a late invoice would be a safety problem, not leverage.
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('subscription.billing'))->assertOk();

        // WRITE: refused, with an explanation rather than a blank 403.
        $this->actingAs($admin)
            ->post(route('settings.company'), ['company_name' => 'Renamed'])
            ->assertForbidden();
    }

    /** Paying is the one write a lapsed customer must always be able to make. */
    public function test_a_lapsed_tenant_can_still_reach_the_billing_actions(): void
    {
        config(['saas.enforce_entitlement' => true]);
        Mail::fake();

        $tenant = $this->subscribedTenant(['ends_at' => now()->subDays(40)]);
        $admin = $this->adminFor($tenant);

        $this->actingAs($admin)->post(route('subscription.renew'))->assertRedirect();
        $this->assertSame(1, Invoice::where('tenant_id', $tenant->id)->count());
    }

    /** A tenant inside grace is not restricted at all. */
    public function test_a_tenant_in_grace_can_still_write(): void
    {
        config(['saas.enforce_entitlement' => true]);

        $tenant = $this->subscribedTenant(['ends_at' => now()->subDays(2)]);
        $admin = $this->adminFor($tenant);

        $this->actingAs($admin)
            ->post(route('settings.company'), ['company_name' => 'Renamed In Grace'])
            ->assertSessionHasNoErrors();
    }

    /** The account-level switch is now enforced, having previously done nothing at all. */
    public function test_suspending_a_tenant_account_blocks_access(): void
    {
        config(['saas.enforce_entitlement' => true]);

        $tenant = $this->subscribedTenant();
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->assertFalse(app(EntitlementService::class)->tenantIsUsable($tenant->fresh()));
        $this->assertSame('account_suspended', app(EntitlementService::class)->blockedReason($tenant->fresh()));
    }

    /** `expired` is gone from the account vocabulary -- it was never written and expiry is not an account decision. */
    public function test_tenant_status_no_longer_offers_expired(): void
    {
        $this->assertSame(['trial', 'active', 'suspended'], Tenant::STATUSES);
    }

    /* ==================================================================
     * 7. TENANT ISOLATION ON THE NEW PATHS
     * ================================================================== */

    /** One organization can never pay, read or download another's invoice. */
    public function test_a_tenant_cannot_reach_another_tenants_invoice(): void
    {
        $mine = $this->subscribedTenant(['ends_at' => now()->addDays(5)]);
        $theirs = $this->subscribedTenant(['ends_at' => now()->addDays(5)]);

        $foreign = $this->lifecycle()->issueRenewalInvoice($theirs->subscription);
        $admin = $this->adminFor($mine);

        $this->actingAs($admin)->get(route('subscription.pay', $foreign))->assertNotFound();
        $this->actingAs($admin)->get(route('subscription.invoices.pdf', $foreign))->assertNotFound();
    }

    /** A plan change is resolved from the signed-in tenant, so there is no id to tamper with. */
    public function test_a_plan_change_always_targets_the_callers_own_subscription(): void
    {
        $mine = $this->subscribedTenant();
        $theirs = $this->subscribedTenant();
        $target = $this->plan('professional', ['price_monthly' => 3000000, 'price_yearly' => 30000000]);

        $admin = $this->adminFor($mine);

        $this->actingAs($admin)->post(route('subscription.plan-change'), [
            'package_id' => $target->id,
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
        ])->assertRedirect();

        $this->assertNotSame(
            $target->id,
            $theirs->fresh()->subscription->package_id,
            "Another organization's subscription must be untouched."
        );
    }

    /** Billing is commercial data: an ordinary account cannot read or act on it. */
    public function test_a_non_administrator_cannot_reach_the_billing_surface(): void
    {
        $tenant = $this->subscribedTenant();
        app(CurrentTenant::class)->set($tenant);

        $staff = User::create([
            'name' => 'Staff', 'email' => uniqid().'@customer.test',
            'password' => bcrypt('secret-pass-1'), 'role' => 'hse',
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $this->actingAs($staff)->get(route('subscription.billing'))->assertForbidden();
        $this->actingAs($staff)->post(route('subscription.renew'))->assertForbidden();
    }

    /* ==================================================================
     * 8. DATA PRESERVATION -- the product principle, asserted
     * ================================================================== */

    /** Every unhappy path leaves the customer's organization and records exactly where they were. */
    public function test_no_lifecycle_transition_removes_tenant_data(): void
    {
        $tenant = $this->subscribedTenant();
        $subscription = $tenant->subscription;
        $companiesBefore = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();

        // Lapse, suspend, cancel, and come back. Reinstating is a
        // deliberate operator act -- paying alone does not undo a
        // suspension, which is asserted separately above.
        $subscription->update(['ends_at' => now()->subYear()]);
        $subscription->update(['status' => Subscription::STATUS_SUSPENDED]);
        $subscription->update(['status' => Subscription::STATUS_CANCELLED]);
        $subscription->update(['status' => Subscription::STATUS_ACTIVE]);
        $this->lifecycle()->extendPeriod($subscription->fresh());

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
        $this->assertSame(
            $companiesBefore,
            Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'Subscription state must never destroy the customer system of record.'
        );
        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $subscription->fresh()->lifecycleState());
    }

    /* ==================================================================
     * 9. THE SCHEDULED RUN
     * ================================================================== */

    /** The command issues what is due and nothing else, and is safe to repeat. */
    public function test_the_lifecycle_command_issues_due_invoices_only_once(): void
    {
        Mail::fake();

        $due = $this->subscribedTenant(['ends_at' => now()->addDays(3)]);
        $notDue = $this->subscribedTenant(['ends_at' => now()->addMonths(3)]);

        $this->artisan('subscriptions:lifecycle')->assertSuccessful();
        $this->artisan('subscriptions:lifecycle')->assertSuccessful();

        $this->assertSame(1, Invoice::where('tenant_id', $due->id)->count());
        $this->assertSame(0, Invoice::where('tenant_id', $notDue->id)->count());
    }

    /** A scheduled downgrade is applied once the period it was waiting for has ended. */
    public function test_the_lifecycle_command_applies_a_scheduled_plan_change(): void
    {
        Mail::fake();

        $tenant = $this->subscribedTenant(['ends_at' => now()->addDays(5)], 'professional');
        $cheaper = $this->plan('starter-lite', ['price_monthly' => 100000, 'price_yearly' => 1000000]);
        $this->lifecycle()->requestPlanChange($tenant->subscription, $cheaper, Subscription::CYCLE_MONTHLY);

        // The period runs out.
        $tenant->subscription->update(['ends_at' => now()->subDay()]);

        $this->artisan('subscriptions:lifecycle')->assertSuccessful();

        $subscription = $tenant->fresh()->subscription;
        $this->assertSame($cheaper->id, $subscription->package_id);
        $this->assertNull($subscription->pending_package_id);
    }

    /** The Sandbox is a demonstration, not a customer, and is never invoiced. */
    public function test_the_demo_tenant_is_never_invoiced(): void
    {
        Mail::fake();

        $tenant = $this->subscribedTenant(['ends_at' => now()->addDays(3)]);
        $tenant->update(['is_demo' => true]);

        $this->artisan('subscriptions:lifecycle')->assertSuccessful();

        $this->assertSame(0, Invoice::where('tenant_id', $tenant->id)->count());
    }

    /* ==================================================================
     * 10. THE MANUAL (BANK TRANSFER) PATH
     * ================================================================== */

    /** Recording a transfer against a renewal invoice extends the period, exactly as a gateway settlement does. */
    public function test_marking_a_renewal_invoice_paid_manually_extends_the_subscription(): void
    {
        $tenant = $this->subscribedTenant(['ends_at' => now()->addDays(5)]);
        $subscription = $tenant->subscription;
        $invoice = $this->lifecycle()->issueRenewalInvoice($subscription);
        $expected = $subscription->ends_at->copy()->addMonth();

        $platformAdmin = User::create([
            'name' => 'Platform', 'email' => uniqid().'@ioms.test',
            'password' => bcrypt('secret-pass-1'), 'role' => 'platform_admin',
            'tenant_id' => null, 'is_active' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->put(route('platform.invoices.mark-paid', $invoice), ['payment_method' => 'bank_transfer'])
            ->assertRedirect();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertTrue($subscription->fresh()->ends_at->isSameDay($expected));
    }
}
