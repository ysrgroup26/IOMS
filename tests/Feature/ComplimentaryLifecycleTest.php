<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.97.0 -- A FREE PERIOD IS AN EVALUATION: IT IS SHORT, IT CAN BE GIVEN
 * AGAIN, AND IT ENDS BY ITSELF.
 *
 * `ComplimentaryAccessTest` pins the GRANT -- that it fakes no payment,
 * grants nothing extra, and provisions exactly one organization. This pins
 * what happens around it, which is where the original design was wrong in
 * three ways:
 *
 *   DURATION   was MONTHS from a closed list (1/3/6/12). That describes a
 *              plan, not an evaluation. It is now days, 3 to 30, bounded in
 *              one place that both the form and the request read.
 *
 *   RE-GRANT   was impossible. The grant provisions a REGISTRATION, and
 *              `isGrantable()` requires `tenant_id === null`, so after the
 *              first grant a customer could never be given another -- the
 *              opposite of what an evaluation is for.
 *
 *   EXPIRY     did nothing. `billing_mode` stayed `complimentary` forever
 *              and the nightly job skips complimentary rows, so an expired
 *              free tenant was never invoiced and never asked for anything.
 *              It sat read-only and permanently non-billable until somebody
 *              noticed by hand. A free period had no end.
 *
 * The lifecycle those three produce together:
 *
 *   Complimentary active -> expired -> paid-required -> normal billing
 *
 * and nothing on that path deletes a tenant, a user, an invoice or a line of
 * history.
 */
class ComplimentaryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['saas.enforce_entitlement' => true]);
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);
    }

    /* ================================================================== */

    private function operator(): User
    {
        return User::create([
            'name' => 'Master Admin', 'email' => 'ops-'.uniqid().'@iomsuite.test',
            'password' => Hash::make('secret-pass-1'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);
    }

    /**
     * A tenant that already exists and is PAYING -- the state every customer
     * is in before an evaluation is granted to them a second time.
     */
    private function payingTenant(string $slug = 'starter'): Tenant
    {
        $package = Package::where('slug', $slug)->sole();

        $tenant = Tenant::create([
            'name' => 'PT Galangan Aliran Jaya',
            'slug' => 'galangan-'.uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        Company::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $tenant->name, 'code' => 'GAJ', 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'type' => Subscription::TYPE_SUBSCRIPTION,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'billing_mode' => Subscription::BILLING_MODE_PAID,
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->addDays(10),
        ]);

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));

        app(CurrentTenant::class)->set($tenant->fresh());

        return $tenant->fresh();
    }

    private function grantTo(Tenant $tenant, array $payload = [])
    {
        return $this->actingAs($this->operator())->post(
            route('platform.tenants.complimentary', $tenant),
            array_merge([
                'package_id' => Package::where('slug', 'starter')->value('id'),
                'days' => 14,
                'reason' => 'Operational evaluation for the yard team',
            ], $payload),
        );
    }

    /* ==================================================================
     * 1. DURATION IS DAYS, AND THE BOUNDS ARE REAL
     * ================================================================== */

    /** @return array<string, array{0: int, 1: bool}> */
    public static function durations(): array
    {
        return [
            '2 days is below the floor' => [2, false],
            '3 days is the floor' => [3, true],
            '7 days' => [7, true],
            '14 days' => [14, true],
            '21 days' => [21, true],
            '30 days is the ceiling' => [30, true],
            '31 days is above the ceiling' => [31, false],
            '90 days' => [90, false],
            'zero' => [0, false],
            'negative' => [-5, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('durations')]
    public function test_the_duration_must_sit_between_three_and_thirty_days(int $days, bool $accepted): void
    {
        $tenant = $this->payingTenant();

        $response = $this->grantTo($tenant, ['days' => $days]);

        if ($accepted) {
            $response->assertSessionHasNoErrors();
            $this->assertSame(
                now()->addDays($days)->toDateString(),
                $tenant->fresh()->subscription->ends_at->toDateString(),
                "$days days must be granted exactly.",
            );

            return;
        }

        $response->assertSessionHasErrors('days');
        $this->assertTrue($tenant->fresh()->subscription->isBillable(), "$days days must change nothing.");
    }

    /** The old month-based model is gone, not merely unused. */
    public function test_the_month_based_duration_model_is_gone(): void
    {
        $this->assertNull(config('saas.complimentary_durations'), 'The month allow-list must not come back.');
        $this->assertSame(3, config('saas.complimentary_min_days'));
        $this->assertSame(30, config('saas.complimentary_max_days'));

        $tenant = $this->payingTenant();

        // A request speaking the old language is simply missing `days`.
        $this->actingAs($this->operator())
            ->post(route('platform.tenants.complimentary', $tenant), [
                'package_id' => Package::where('slug', 'starter')->value('id'),
                'months' => 3,
                'reason' => 'Operational evaluation for the yard team',
            ])
            ->assertSessionHasErrors('days');
    }

    /* ==================================================================
     * 2. RE-GRANT
     * ================================================================== */

    /**
     * THE DEFECT THIS WAS WRITTEN FOR. A tenant that already exists --
     * including one whose evaluation has already ended -- can be given
     * another, and nothing is duplicated to do it.
     */
    public function test_an_existing_tenant_can_be_granted_complimentary_access_again(): void
    {
        $tenant = $this->payingTenant();
        $subscriptionId = $tenant->subscription->id;

        // First evaluation.
        $this->grantTo($tenant, ['days' => 3])->assertSessionHasNoErrors();
        $this->assertTrue($tenant->fresh()->subscription->isComplimentary());

        // It runs out and becomes payable again.
        $this->travelTo(now()->addDays(4));
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);
        $this->assertTrue($tenant->fresh()->subscription->isBillable(), 'The first evaluation must have ended.');

        // And a second one is granted, by hand.
        $this->grantTo($tenant, ['days' => 30, 'reason' => 'Second evaluation after the pilot'])
            ->assertSessionHasNoErrors();

        $subscription = $tenant->fresh()->subscription;

        $this->assertTrue($subscription->isComplimentary());
        $this->assertSame(now()->addDays(30)->toDateString(), $subscription->ends_at->toDateString());

        // NOTHING was duplicated.
        $this->assertSame($subscriptionId, $subscription->id, 'A re-grant must reuse the subscription row.');
        $this->assertSame(1, Subscription::where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, Tenant::where('id', $tenant->id)->count());
        $this->assertSame(1, Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    /** Previous free access is history, not a lock. */
    public function test_previous_complimentary_history_does_not_block_a_new_grant(): void
    {
        $tenant = $this->payingTenant();

        $this->grantTo($tenant, ['days' => 3]);
        $this->grantTo($tenant, ['days' => 7])->assertSessionHasNoErrors();

        $this->assertSame(now()->addDays(7)->toDateString(), $tenant->fresh()->subscription->ends_at->toDateString());

        // Both decisions are on the record.
        $this->assertGreaterThanOrEqual(
            2,
            ActivityLog::withoutGlobalScopes()->where('description', 'like', '%Complimentary access granted%')->count(),
            'Every grant must leave its own audit line.',
        );
    }

    /** It is MANUAL. Nothing on a schedule hands out free access. */
    public function test_nothing_renews_a_complimentary_period_automatically(): void
    {
        $tenant = $this->payingTenant();
        $this->grantTo($tenant, ['days' => 3]);

        $endsAt = $tenant->fresh()->subscription->ends_at->toDateString();

        $this->travelTo(now()->addDays(5));
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        $subscription = $tenant->fresh()->subscription;

        $this->assertTrue($subscription->isBillable(), 'A free period must never renew itself.');
        $this->assertSame($endsAt, $subscription->ends_at->toDateString(), 'Expiry must not extend the period.');
    }

    /* ==================================================================
     * 3. EXPIRY MOVES IT INTO PAID-REQUIRED
     * ================================================================== */

    /**
     * The whole point of the lifecycle. A free period ends by itself, the
     * account becomes billable, and the ordinary billing flow takes over.
     */
    public function test_an_expired_complimentary_period_becomes_paid_required(): void
    {
        $tenant = $this->payingTenant();
        $this->grantTo($tenant, ['days' => 3]);

        $this->assertTrue($tenant->fresh()->subscription->isComplimentary());
        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        $this->travelTo(now()->addDays(4));
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        $subscription = $tenant->fresh()->subscription;

        // It stopped being complimentary, automatically.
        $this->assertFalse($subscription->isComplimentary());
        $this->assertTrue($subscription->isBillable());
        $this->assertSame(Subscription::BILLING_MODE_PAID, $subscription->billing_mode);

        // And the normal billing flow has taken over: a real invoice exists,
        // at catalogue price, because nothing was ever agreed.
        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest('id')->first();
        $this->assertNotNull($invoice, 'A paid-required account must be invoiced like any other.');
        $this->assertEquals((float) Package::where('slug', 'starter')->value('price_monthly'), (float) $invoice->amount);
        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->status, 'Nothing may be marked paid by an expiry.');
    }

    /** Expiry removes no access of its own; the dates already did that. */
    public function test_expiry_changes_the_billing_mode_and_nothing_else(): void
    {
        $tenant = $this->payingTenant();
        $this->grantTo($tenant, ['days' => 3, 'reason' => 'Pilot for the Batam yard']);

        $before = $tenant->fresh()->subscription;
        $packageId = $before->package_id;
        $startsAt = $before->starts_at->toDateString();
        $endsAt = $before->ends_at->toDateString();

        $this->travelTo(now()->addDays(4));
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        $after = $tenant->fresh()->subscription;

        $this->assertSame($packageId, $after->package_id, 'The plan must not change.');
        $this->assertSame($startsAt, $after->starts_at->toDateString());
        $this->assertSame($endsAt, $after->ends_at->toDateString());
        $this->assertSame(Subscription::STATUS_ACTIVE, $after->status, 'Expiry is derived from dates, not stored.');
        $this->assertSame('Pilot for the Batam yard', $after->notes, 'The reason the account was free is still the record.');
    }

    /** Nothing is deleted. The tenant, its people and its history survive. */
    public function test_expiry_deletes_no_tenant_data(): void
    {
        $tenant = $this->payingTenant();

        $member = User::create([
            'name' => 'Yard HSE', 'email' => 'hse-'.uniqid().'@galangan.test',
            'password' => Hash::make('secret-pass-1'), 'role' => 'hse',
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $this->grantTo($tenant, ['days' => 3]);
        $logsBefore = ActivityLog::withoutGlobalScopes()->count();

        $this->travelTo(now()->addDays(4));
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
        $this->assertNotNull(User::find($member->id));
        $this->assertSame(1, Subscription::where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThanOrEqual($logsBefore, ActivityLog::withoutGlobalScopes()->count(), 'History only grows.');
    }

    /** A free period that is still running is left alone. */
    public function test_a_running_complimentary_period_is_not_touched(): void
    {
        $tenant = $this->payingTenant();
        $this->grantTo($tenant, ['days' => 30]);

        $this->travelTo(now()->addDays(10));
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        $this->assertTrue($tenant->fresh()->subscription->isComplimentary(), 'A running evaluation must stay free.');
        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    /* ==================================================================
     * 4. ENTITLEMENTS AND AUTHORIZATION ARE UNCHANGED THROUGHOUT
     * ================================================================== */

    /** The plan decides, before and after the free period ends. */
    public function test_entitlements_follow_the_plan_through_the_whole_lifecycle(): void
    {
        $tenant = $this->payingTenant();
        $entitlements = app(EntitlementService::class);

        $this->grantTo($tenant, ['days' => 3]);
        $this->assertSame(['hse'], $entitlements->tenantDepartmentKeys($tenant->fresh()));

        $this->travelTo(now()->addDays(4));
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        $this->assertSame(['hse'], $entitlements->tenantDepartmentKeys($tenant->fresh()), 'Expiry must not change scope.');
    }

    /** A re-grant on a different plan re-entitles through the normal path. */
    public function test_a_regrant_on_another_plan_applies_that_plan_scope(): void
    {
        $tenant = $this->payingTenant();

        $this->grantTo($tenant, [
            'package_id' => Package::where('slug', 'business')->value('id'),
            'days' => 14,
        ])->assertSessionHasNoErrors();

        $granted = app(EntitlementService::class)->tenantDepartmentKeys($tenant->fresh());

        $this->assertSame(['hse', 'hr', 'logistics', 'management'], $granted);
        $this->assertSame('business', $tenant->fresh()->subscription->package->slug);
    }

    /** Only the platform operator may grant it. */
    public function test_a_tenant_administrator_cannot_grant_themselves_free_access(): void
    {
        $tenant = $this->payingTenant();

        $customerAdmin = User::create([
            'name' => 'Owner', 'email' => 'owner-'.uniqid().'@galangan.test',
            'password' => Hash::make('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $this->actingAs($customerAdmin)
            ->post(route('platform.tenants.complimentary', $tenant), [
                'package_id' => Package::where('slug', 'starter')->value('id'),
                'days' => 30,
                'reason' => 'I would like this for free please',
            ])
            ->assertForbidden();

        $this->assertTrue($tenant->fresh()->subscription->isBillable());
    }

    /** A reason is required for a re-grant exactly as for a first grant. */
    public function test_a_regrant_still_requires_a_reason(): void
    {
        $tenant = $this->payingTenant();

        $this->grantTo($tenant, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->grantTo($tenant, ['reason' => 'test'])->assertSessionHasErrors('reason');

        $this->assertTrue($tenant->fresh()->subscription->isBillable());
    }

    /* ==================================================================
     * 5. NO TENANT-SPECIFIC BEHAVIOUR
     * ================================================================== */

    public function test_two_tenants_receive_identical_lifecycle_treatment(): void
    {
        $first = $this->payingTenant();
        $second = $this->payingTenant();

        foreach ([$first, $second] as $tenant) {
            $this->grantTo($tenant, ['days' => 3]);
        }

        $this->travelTo(now()->addDays(4));
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        foreach ([$first, $second] as $tenant) {
            $this->assertTrue($tenant->fresh()->subscription->isBillable(), 'Both must end the same way.');
        }
    }
}
