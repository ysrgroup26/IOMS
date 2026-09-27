<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.80.0 -- HOW A TENANT IS PAID FOR, AND WHAT THAT MUST NOT CHANGE.
 *
 * IOMS could not say whether an organization pays. Three things were one
 * indistinguishable `active` row: a free pilot, a customer paying by bank
 * transfer, and a customer paying through a gateway. So the nightly job
 * raised real invoices against accounts nobody intends to bill, and the
 * operations console counted a free pilot as revenue-bearing work.
 *
 * The risk in fixing it is bigger than the bug: a billing mode that could
 * also grant or withhold access would be a SECOND source of truth for the
 * thing the whole lifecycle design exists to keep single. So the assertions
 * below come in two halves -- what billing mode changes (invoicing,
 * reporting), and what it must never touch (entitlement, lifecycle).
 *
 * The other half of this release's requirement -- that an existing tenant
 * can become paying WITHOUT being recreated -- is proven end to end through
 * a signed webhook by SubscriptionReadOnlyEnforcementTest; the identity half
 * is asserted here.
 */
class BillingModeTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['saas.enforce_entitlement' => true]);

        $this->package = Package::create([
            'name' => 'Business', 'slug' => 'business-'.uniqid(), 'is_active' => true, 'is_public' => true,
            'price_monthly' => 1499000, 'price_yearly' => 14990000, 'currency' => 'IDR',
        ]);
    }

    private function tenantWith(array $subscription, string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        Company::withoutGlobalScopes()->create([
            'name' => $name.' Co', 'code' => strtoupper(substr(md5($name), 0, 3)),
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $this->package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
            'agreed_price_monthly' => 1499000, 'agreed_price_yearly' => 14990000, 'agreed_currency' => 'IDR',
            ...$subscription,
        ]);

        $tenant->workspaces()->sync(Workspace::pluck('id'));

        return $tenant;
    }

    /* ==================================================================
     * What billing mode IS
     * ================================================================== */

    public function test_every_existing_subscription_defaults_to_paid(): void
    {
        // The migration's whole safety property: adding this column changes
        // nothing about a row that existed before it.
        $tenant = $this->tenantWith([], 'Legacy');

        $subscription = $tenant->subscription;

        $this->assertSame(Subscription::BILLING_MODE_PAID, $subscription->billingMode());
        $this->assertTrue($subscription->isBillable());
        $this->assertFalse($subscription->isComplimentary());
    }

    public function test_an_unrecognised_mode_falls_back_to_billable(): void
    {
        // A typo must not silently make a paying customer free. The fallback
        // direction is the whole reason billingMode() exists rather than
        // reading the column.
        $tenant = $this->tenantWith([], 'Typo');
        $tenant->subscription->forceFill(['billing_mode' => 'gratiss'])->save();

        $this->assertSame(Subscription::BILLING_MODE_PAID, $tenant->subscription->fresh()->billingMode());
        $this->assertTrue($tenant->subscription->fresh()->isBillable());
    }

    public function test_manual_is_billable_because_the_money_is_real(): void
    {
        $tenant = $this->tenantWith(['billing_mode' => Subscription::BILLING_MODE_MANUAL], 'Transfer');

        $this->assertTrue($tenant->subscription->isBillable());
        $this->assertFalse($tenant->subscription->isComplimentary());
    }

    /* ==================================================================
     * What it changes: invoicing
     * ================================================================== */

    public function test_a_complimentary_subscription_is_never_invoiced(): void
    {
        // Inside the renewal lead window, so a billable subscription here
        // WOULD be invoiced -- which the next assertion relies on.
        $free = $this->tenantWith([
            'billing_mode' => Subscription::BILLING_MODE_COMPLIMENTARY,
            'ends_at' => now()->addDays(3),
        ], 'Pilot');

        $this->artisan('subscriptions:lifecycle');

        $this->assertSame(
            0,
            Invoice::withoutGlobalScopes()->where('tenant_id', $free->id)->count(),
            'A free-by-decision account must never be sent a bill.'
        );
    }

    public function test_a_billable_subscription_in_the_same_window_still_is(): void
    {
        // The control for the test above: same dates, different mode. Without
        // this, "no invoice" could just mean the job did nothing at all.
        $paying = $this->tenantWith(['ends_at' => now()->addDays(3)], 'Paying');

        $this->artisan('subscriptions:lifecycle');

        $this->assertSame(
            1,
            Invoice::withoutGlobalScopes()->where('tenant_id', $paying->id)->count(),
            'A paying customer inside the lead window must be invoiced.'
        );
    }

    public function test_a_complimentary_tenant_is_not_operational_work(): void
    {
        // Free, and past its dates. It must not appear on the operator's
        // chase list, because there is nothing to chase.
        $this->tenantWith([
            'billing_mode' => Subscription::BILLING_MODE_COMPLIMENTARY,
            'ends_at' => now()->subDays(30),
        ], 'Internal');

        $health = app(\App\Services\PlatformOperationsService::class)->subscriptionHealth();

        $this->assertSame([], $health['attention'], 'A free account is never chased for money.');
        $this->assertSame(1, $health['billing_modes'][Subscription::BILLING_MODE_COMPLIMENTARY]);
        $this->assertSame(0, $health['billing_modes'][Subscription::BILLING_MODE_PAID]);
    }

    public function test_the_console_separates_paying_from_complimentary(): void
    {
        $this->tenantWith([], 'Paying One');
        $this->tenantWith(['billing_mode' => Subscription::BILLING_MODE_MANUAL], 'Transfer One');
        $this->tenantWith(['billing_mode' => Subscription::BILLING_MODE_COMPLIMENTARY], 'Free One');

        $modes = app(\App\Services\PlatformOperationsService::class)->subscriptionHealth()['billing_modes'];

        $this->assertSame(1, $modes[Subscription::BILLING_MODE_PAID]);
        $this->assertSame(1, $modes[Subscription::BILLING_MODE_MANUAL]);
        $this->assertSame(1, $modes[Subscription::BILLING_MODE_COMPLIMENTARY]);
    }

    /* ==================================================================
     * What it must NOT change: access
     * ================================================================== */

    public function test_billing_mode_does_not_grant_access_a_lapsed_period_withdrew(): void
    {
        // The trap this test exists for: making a tenant complimentary is
        // NOT a way to hand out access. If it were, billing mode would be a
        // second entitlement system and the lifecycle would no longer be the
        // single answer to "may they write today".
        $tenant = $this->tenantWith([
            'billing_mode' => Subscription::BILLING_MODE_COMPLIMENTARY,
            'ends_at' => now()->subDays(30),
        ], 'Free Lapsed');

        $subscription = $tenant->subscription;

        $this->assertSame(Subscription::LIFECYCLE_LAPSED, $subscription->lifecycleState());
        $this->assertFalse($subscription->allowsWrites());

        // A free account meant to run indefinitely is expressed as a LIFETIME
        // type -- an existing, honest mechanism -- not as a billing mode that
        // quietly overrides the dates.
        $subscription->update(['type' => Subscription::TYPE_LIFETIME]);
        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $subscription->fresh()->lifecycleState());
    }

    public function test_billing_mode_does_not_withhold_access_while_the_period_runs(): void
    {
        $tenant = $this->tenantWith(['billing_mode' => Subscription::BILLING_MODE_COMPLIMENTARY], 'Free Active');

        $this->assertTrue($tenant->subscription->allowsWrites());
        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $tenant->subscription->lifecycleState());
    }

    /* ==================================================================
     * Who may set it
     * ================================================================== */

    public function test_an_operator_can_move_a_tenant_between_modes_without_recreating_it(): void
    {
        $tenant = $this->tenantWith(['billing_mode' => Subscription::BILLING_MODE_COMPLIMENTARY], 'Graduating');

        $subscriptionId = $tenant->subscription->id;
        $tenantId = $tenant->id;

        $operator = User::withoutGlobalScopes()->create([
            'name' => 'Ops', 'email' => 'ops-mode@ioms.test', 'password' => bcrypt('secret-pass-2'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->put(route('platform.tenants.subscription.update', $tenantId), [
                'package_id' => $this->package->id,
                'type' => 'subscription',
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => Subscription::CYCLE_MONTHLY,
                'billing_mode' => Subscription::BILLING_MODE_PAID,
            ])
            ->assertRedirect();

        // The identity requirement: the SAME tenant and the SAME subscription.
        // Becoming a paying customer must never mean being recreated.
        $this->assertSame(Subscription::BILLING_MODE_PAID, Subscription::withoutGlobalScopes()->find($subscriptionId)->billingMode());
        $this->assertSame(1, Tenant::withoutGlobalScopes()->where('id', $tenantId)->count());
        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());
    }

    public function test_a_tenant_administrator_cannot_set_their_own_billing_mode(): void
    {
        $tenant = $this->tenantWith([], 'Self Serve');
        app(CurrentTenant::class)->set($tenant);

        $admin = User::create([
            'name' => 'Owner', 'email' => 'owner-mode@self.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Auth::forgetGuards();

        // Declaring yourself complimentary is not a self-service action.
        $this->actingAs($admin)
            ->put(route('platform.tenants.subscription.update', $tenant->id), [
                'package_id' => $this->package->id,
                'type' => 'subscription',
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => Subscription::CYCLE_MONTHLY,
                'billing_mode' => Subscription::BILLING_MODE_COMPLIMENTARY,
            ])
            ->assertForbidden();

        $this->assertSame(Subscription::BILLING_MODE_PAID, $tenant->subscription->fresh()->billingMode());
    }

    public function test_the_mode_reaches_both_the_customer_and_the_operator_identically(): void
    {
        // It rides the shared snapshot, so the two sides cannot describe one
        // subscription's billing differently.
        $tenant = $this->tenantWith(['billing_mode' => Subscription::BILLING_MODE_MANUAL], 'Shared');

        $snapshot = $tenant->subscription->stateSnapshot();

        $this->assertSame(Subscription::BILLING_MODE_MANUAL, $snapshot['billing_mode']);
        $this->assertSame('Manual (transfer)', $snapshot['billing_mode_label']);
        $this->assertTrue($snapshot['is_billable']);
    }
}
