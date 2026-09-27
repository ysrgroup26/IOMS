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
use App\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.80.0 -- THE CUSTOMER AND THE OPERATOR MUST READ ONE ANSWER.
 *
 * The failure this file exists to prevent is not a wrong state. It is TWO
 * states: a customer whose Billing page says "read-only, renew to continue"
 * while Master Admin shows the same subscription as Active, so support
 * argues with the customer about their own account.
 *
 * It is prevented structurally rather than by agreement --
 * Subscription::stateSnapshot() is the only place a lifecycle fact is
 * assembled, and both payloads spread it. This test asserts the two HTTP
 * payloads are IDENTICAL on every lifecycle key, across the six situations
 * the business actually distinguishes, and that the timing policy (H-7
 * reminder, 7-day grace) is what the dates produce.
 *
 * Note the deliberate shape: it compares the RENDERED props, not the model.
 * Testing the model would prove only that one method agrees with itself.
 */
class SubscriptionStateParityTest extends TestCase
{
    use RefreshDatabase;

    /** Every key a UI may use to describe where a subscription sits. */
    private const LIFECYCLE_KEYS = [
        'status', 'type', 'billing_cycle', 'lifecycle_state', 'period_ends_at',
        'grace_ends_at', 'grace_days', 'days_remaining', 'allows_writes',
        'allows_reads', 'is_lifetime', 'is_usable', 'is_degraded', 'plan_name',
    ];

    private Tenant $tenant;
    private User $customer;
    private User $operator;
    private Subscription $subscription;
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

        $this->tenant = Tenant::create([
            'name' => 'Parity Yard', 'slug' => 'parity-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        Company::withoutGlobalScopes()->create([
            'name' => 'Parity Co', 'code' => 'PRT', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
        $this->tenant->workspaces()->sync(Workspace::pluck('id'));

        $this->subscription = Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $this->package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
            'agreed_price_monthly' => 1499000, 'agreed_price_yearly' => 14990000, 'agreed_currency' => 'IDR',
        ]);

        app(CurrentTenant::class)->set($this->tenant);

        $this->customer = User::create([
            'name' => 'Owner', 'email' => 'owner@parity.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        // The operator has no tenant of their own; the role is what makes
        // them a platform administrator (ADR 038), not a null tenant_id.
        $this->operator = User::withoutGlobalScopes()->create([
            'name' => 'Ops', 'email' => 'ops@ioms.test', 'password' => bcrypt('secret-pass-2'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);
    }

    /* ==================================================================
     * The parity itself
     * ================================================================== */

    /** What the customer's own Billing page says about their subscription. */
    private function customerView(): array
    {
        $this->forgetSession();
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $props = $this->actingAs($this->customer->fresh())
            ->get(route('subscription.billing'))
            ->assertOk()
            ->viewData('page')['props'];

        return $props['subscription'];
    }

    /** What Master Admin says about the same subscription. */
    private function operatorView(): array
    {
        $this->forgetSession();

        $props = $this->actingAs($this->operator->fresh())
            ->get(route('platform.tenants.show', $this->tenant->id))
            ->assertOk()
            ->viewData('page')['props'];

        return $props['subscription'];
    }

    /**
     * Guards and tenant context are resolved once per request lifecycle, so
     * switching between a tenant user and a platform operator inside one
     * test needs the previous one genuinely forgotten -- otherwise the
     * second request answers as the first user (the v2.77.0 test artefact).
     */
    private function forgetSession(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
    }

    private function assertBothSidesAgree(string $expectedState, string $situation): array
    {
        $customer = $this->customerView();
        $operator = $this->operatorView();

        foreach (self::LIFECYCLE_KEYS as $key) {
            $this->assertArrayHasKey($key, $customer, "customer payload is missing {$key} ({$situation})");
            $this->assertArrayHasKey($key, $operator, "platform payload is missing {$key} ({$situation})");
            $this->assertSame(
                $customer[$key],
                $operator[$key],
                "Customer Billing and Master Admin disagree on `{$key}` while {$situation}."
            );
        }

        $this->assertSame($expectedState, $customer['lifecycle_state'], "wrong lifecycle state while {$situation}");

        return $customer;
    }

    public function test_active_subscription_reads_active_on_both_sides(): void
    {
        $view = $this->assertBothSidesAgree(Subscription::LIFECYCLE_ACTIVE, 'active');

        $this->assertTrue($view['allows_writes']);
        $this->assertSame(20, $view['days_remaining']);
    }

    public function test_expired_but_inside_grace_reads_grace_on_both_sides(): void
    {
        // Three days past the period end, so inside a seven-day grace.
        $this->subscription->update(['ends_at' => now()->subDays(3)]);

        $view = $this->assertBothSidesAgree(Subscription::LIFECYCLE_GRACE, 'in grace');

        // The point of grace: full access, not a warning with no teeth.
        $this->assertTrue($view['allows_writes'], 'grace must keep FULL access');
        $this->assertTrue($view['is_degraded']);
        $this->assertSame(now()->subDays(3)->addDays(7)->toDateString(), $view['grace_ends_at']);
    }

    public function test_past_grace_reads_lapsed_and_read_only_on_both_sides(): void
    {
        $this->subscription->update(['ends_at' => now()->subDays(8)]);

        $view = $this->assertBothSidesAgree(Subscription::LIFECYCLE_LAPSED, 'lapsed');

        $this->assertFalse($view['allows_writes'], 'past grace must be read-only');
        $this->assertTrue($view['allows_reads'], 'a lapse never withdraws reads');
    }

    public function test_suspension_reads_suspended_on_both_sides(): void
    {
        // A deliberate operator decision, and one no date may override:
        // the period here is still current.
        $this->subscription->update([
            'status' => Subscription::STATUS_SUSPENDED,
            'ends_at' => now()->addDays(20),
        ]);

        $view = $this->assertBothSidesAgree(Subscription::LIFECYCLE_SUSPENDED, 'suspended');

        $this->assertFalse($view['allows_writes']);
        $this->assertFalse($view['allows_reads'], 'suspension is the state that closes the door');
        $this->assertFalse($view['is_usable']);
    }

    public function test_cancellation_reads_cancelled_on_both_sides(): void
    {
        $this->subscription->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now()->subDay(),
        ]);

        $view = $this->assertBothSidesAgree(Subscription::LIFECYCLE_CANCELLED, 'cancelled');

        $this->assertFalse($view['is_usable']);
    }

    public function test_renewal_returns_both_sides_to_active_together(): void
    {
        $this->subscription->update(['ends_at' => now()->subDays(8)]);
        $this->assertBothSidesAgree(Subscription::LIFECYCLE_LAPSED, 'lapsed before renewal');

        // Renew the way a verified payment does.
        app(SubscriptionLifecycleService::class)
            ->extendPeriod($this->subscription->fresh(), Subscription::CYCLE_MONTHLY);

        $view = $this->assertBothSidesAgree(Subscription::LIFECYCLE_ACTIVE, 'renewed');

        $this->assertTrue($view['allows_writes'], 'renewal must restore write access');
    }

    /* ==================================================================
     * The timing policy the states are derived from (Task 1)
     * ================================================================== */

    public function test_grace_is_seven_days_and_the_reminder_is_seven_days_out(): void
    {
        $this->assertSame(7, config('saas.grace_days'), 'grace window is 7 days');
        $this->assertSame(7, config('saas.renewal_lead_days'), 'the renewal reminder is H-7');
        $this->assertSame(7, Subscription::graceDays());
    }

    public function test_grace_boundary_is_exact(): void
    {
        // Day 7 after the period end is still grace; day 8 is not. The
        // boundary is the whole policy, so it is asserted rather than
        // assumed from a mid-window date.
        $this->subscription->update(['ends_at' => now()->subDays(7)->addHours(2)]);
        $this->assertSame(Subscription::LIFECYCLE_GRACE, $this->subscription->fresh()->lifecycleState());

        $this->subscription->update(['ends_at' => now()->subDays(7)->subHours(2)]);
        $this->assertSame(Subscription::LIFECYCLE_LAPSED, $this->subscription->fresh()->lifecycleState());
    }

    public function test_paying_early_extends_from_the_existing_period_end_not_from_today(): void
    {
        // The customer pays with 20 days still to run. Those 20 days are
        // paid for; billing from today would silently take them away.
        $periodEnd = $this->subscription->ends_at->copy();

        app(SubscriptionLifecycleService::class)
            ->extendPeriod($this->subscription, Subscription::CYCLE_MONTHLY);

        $this->assertSame(
            $periodEnd->copy()->addMonth()->toDateString(),
            $this->subscription->fresh()->ends_at->toDateString(),
            'an early renewal must add a cycle to the EXISTING period end'
        );
    }

    public function test_a_lapse_never_deletes_the_tenant_or_its_records(): void
    {
        $invoice = Invoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'invoice_number' => 'INV-PARITY-1',
            'amount' => 1499000, 'currency' => 'IDR', 'status' => Invoice::STATUS_ISSUED,
            'due_date' => now()->addDays(14),
        ]);

        $this->subscription->update(['ends_at' => now()->subMonths(6)]);
        $this->assertBothSidesAgree(Subscription::LIFECYCLE_LAPSED, 'long lapsed');

        $this->assertNotNull(Tenant::withoutGlobalScopes()->find($this->tenant->id));
        $this->assertNotNull(Subscription::withoutGlobalScopes()->find($this->subscription->id));
        $this->assertNotNull(Invoice::withoutGlobalScopes()->find($invoice->id));
        $this->assertSame(1, User::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }
}
