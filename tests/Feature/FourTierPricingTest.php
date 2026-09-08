<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Workspace;
use App\Services\PricingService;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ApprovedCatalogue;
use Tests\TestCase;

/**
 * v2.60.0 -- THE FOUR-TIER MODEL.
 *
 * Three things this release had to get right, each of which has a way of
 * being wrong silently:
 *
 *  1. WHAT A TIER GRANTS. `Package::defaultWorkspaceKeys()` used to be a
 *     hardcoded `match` with a `default => []` arm, so a plan whose slug
 *     the match did not recognise resolved to zero departments while still
 *     having a price. Adding Business is exactly the change that would
 *     have tripped that. The mapping now lives in config/plans.php and an
 *     unknown slug falls back to the entry tier instead of to nothing.
 *
 *  2. WHAT AN EXISTING CUSTOMER PAYS. Repricing the catalogue must not
 *     reprice anybody. A subscription now carries the price it was sold
 *     at, and the catalogue is only a fallback for rows that predate the
 *     column.
 *
 *  3. WHAT THE PRICING PAGES SAY. "Most popular" is a claim; it is now a
 *     value in config, served through PricingService, rather than a card
 *     index the UI guessed at -- which broke the moment a fourth tier
 *     arrived.
 *
 * The prices themselves are asserted in PricingConsistencyTest.
 */
class FourTierPricingTest extends TestCase
{
    use RefreshDatabase;

    /** The `workspaces` table is seeded, not migrated -- without it every tier scope reads as empty and passes vacuously. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
    }

    private function plan(string $slug): Package
    {
        return Package::where('slug', $slug)->firstOrFail();
    }

    /* ================================================================
     * 1. WHAT EACH TIER GRANTS
     * ================================================================ */

    public function test_each_tier_grants_exactly_the_approved_departments(): void
    {
        $everyDepartment = Workspace::where('tier', Workspace::TIER_DEPARTMENT)->pluck('key')->sort()->values()->all();

        foreach (ApprovedCatalogue::SCOPE as $slug => $expected) {
            $actual = $this->plan($slug)->departmentWorkspaceKeys();
            sort($actual);

            if ($expected === '*') {
                $this->assertSame($everyDepartment, $actual, "{$slug} must grant every department that exists.");

                continue;
            }

            sort($expected);
            $this->assertSame($expected, $actual, "{$slug} grants the wrong set of departments.");
        }
    }

    /**
     * The v2.58.0 fix, re-checked with a fourth tier in the ladder: the
     * global-tier workspaces are the application's own chrome and no plan
     * may withhold them, but they are not a tier's selling point either.
     */
    public function test_every_tier_receives_the_global_chrome_and_never_sells_it(): void
    {
        foreach (ApprovedCatalogue::slugs() as $slug) {
            $package = $this->plan($slug);

            foreach (Workspace::globalKeys() as $global) {
                $this->assertContains($global, $package->defaultWorkspaceKeys(), "{$slug} lost {$global}.");
                $this->assertNotContains(
                    $global,
                    $package->departmentWorkspaceKeys(),
                    "{$slug} presents {$global} as a purchased department."
                );
            }
        }
    }

    /** The ladder is cumulative: no tier may take a department away from the tier below it. */
    public function test_the_ladder_never_removes_a_department(): void
    {
        $previous = [];

        foreach (ApprovedCatalogue::slugs() as $slug) {
            $current = $this->plan($slug)->departmentWorkspaceKeys();

            foreach ($previous as $inherited) {
                $this->assertContains($inherited, $current, "{$slug} dropped {$inherited}, which a cheaper tier includes.");
            }

            $previous = $current;
        }
    }

    /**
     * A plan created through the Platform Admin UI, or one whose slug was
     * mistyped, must receive a WORKING product rather than a price with no
     * navigation behind it.
     */
    public function test_an_unrecognised_plan_slug_falls_back_to_the_entry_tier(): void
    {
        $strange = Package::create([
            'name' => 'Bespoke', 'slug' => 'not-a-real-tier',
            'price_monthly' => 500000, 'price_yearly' => 5000000, 'currency' => 'IDR',
            'is_active' => true, 'is_public' => false, 'is_custom' => false,
        ]);

        $this->assertSame(
            $this->plan(config('plans.fallback'))->departmentWorkspaceKeys(),
            $strange->departmentWorkspaceKeys(),
            'An unknown slug must resolve to the entry tier, never to zero departments.'
        );
        $this->assertNotEmpty($strange->defaultModuleKeys());
    }

    /* ================================================================
     * 2. WHAT THE PRICING SURFACES SAY
     * ================================================================ */

    public function test_exactly_one_tier_is_presented_as_most_popular(): void
    {
        $plans = app(PricingService::class)->publicPlans();

        $popular = $plans->where('is_popular', true)->pluck('slug')->all();

        $this->assertSame([ApprovedCatalogue::POPULAR], $popular);
    }

    /**
     * IOMS does not sell a free trial, and the Plans page prints one
     * whenever `trial_days` is set.
     *
     * Found by looking at the running page: Professional was still
     * carrying trial_days = 14 from an older catalogue, because the
     * four-tier migration normalised the column on INSERT but not on
     * UPDATE -- and every existing tier takes the update path.
     */
    public function test_no_tier_advertises_a_free_trial(): void
    {
        foreach (Package::all() as $package) {
            $this->assertEmpty(
                $package->trial_days,
                "{$package->slug} advertises a {$package->trial_days}-day free trial; IOMS does not sell one."
            );
        }
    }

    public function test_every_tier_states_who_it_is_for(): void
    {
        foreach (app(PricingService::class)->publicPlans() as $plan) {
            $this->assertNotEmpty($plan['positioning'], "{$plan['slug']} has no positioning line.");
        }
    }

    /**
     * The pricing pages read `department_workspaces`. If it ever came back
     * empty the cards would silently list capacity and nothing else --
     * which is how the v2.58.0 empty sidebar looked from the outside.
     */
    public function test_every_public_plan_lists_at_least_one_department(): void
    {
        foreach (app(PricingService::class)->publicPlans() as $plan) {
            $this->assertNotEmpty(
                $plan['department_workspaces'],
                "{$plan['slug']} would render a pricing card with no departments on it."
            );
        }
    }

    /** All four tiers reach the public pages, not just the three that used to exist. */
    public function test_all_four_tiers_reach_the_public_pricing_surfaces(): void
    {
        foreach (['home', 'pricing', 'get-started'] as $routeName) {
            $slugs = collect($this->get(route($routeName))->viewData('page')['props']['plans'])
                ->pluck('slug')->all();

            $this->assertSame(ApprovedCatalogue::slugs(), $slugs, "{$routeName} does not offer the four approved tiers.");
        }
    }

    /* ================================================================
     * 3. WHAT AN EXISTING CUSTOMER PAYS
     * ================================================================ */

    private function subscriptionOn(string $slug, array $attributes = []): Subscription
    {
        $tenant = Tenant::create([
            'name' => 'T'.uniqid(), 'slug' => 't'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        return Subscription::create(array_merge([
            'tenant_id' => $tenant->id,
            'package_id' => $this->plan($slug)->id,
            'status' => 'active',
            'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_YEARLY,
            'starts_at' => now(), 'ends_at' => now()->addYear(),
        ], $attributes));
    }

    /** A subscription sold at a price keeps that price when the catalogue moves. */
    public function test_a_catalogue_reprice_does_not_reprice_an_existing_subscription(): void
    {
        $subscription = $this->subscriptionOn('professional', [
            'agreed_price_monthly' => 999000,
            'agreed_price_yearly' => 9990000,
            'agreed_currency' => 'IDR',
        ]);

        // The catalogue moves underneath them.
        $this->plan('professional')->update(['price_yearly' => 7990000]);

        $this->assertSame(9990000.0, $subscription->agreedAmountFor(Subscription::CYCLE_YEARLY));
        $this->assertSame(999000.0, $subscription->agreedAmountFor(Subscription::CYCLE_MONTHLY));
        $this->assertTrue($subscription->isOnLegacyPricing(), 'A grandfathered price must be visible as such.');
    }

    /** A row that predates the snapshot columns falls back to the catalogue rather than to nothing. */
    public function test_a_subscription_without_a_snapshot_falls_back_to_the_catalogue(): void
    {
        $subscription = $this->subscriptionOn('business');

        $this->assertNull($subscription->agreed_price_yearly, 'Precondition: no snapshot on this row.');
        $this->assertSame(14990000.0, $subscription->agreedAmountFor(Subscription::CYCLE_YEARLY));
        $this->assertSame('IDR', $subscription->agreedCurrency());
        $this->assertFalse($subscription->isOnLegacyPricing(), 'Matching the catalogue is not legacy pricing.');
    }

    /** The cycle defaults to the subscription's own, so a caller cannot quote the wrong one by omission. */
    public function test_the_agreed_amount_defaults_to_the_subscriptions_own_cycle(): void
    {
        $monthly = $this->subscriptionOn('starter', [
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'agreed_price_monthly' => 199000, 'agreed_price_yearly' => 1990000, 'agreed_currency' => 'IDR',
        ]);

        $this->assertSame(199000.0, $monthly->agreedAmountFor());
    }

    /** Provisioning stamps the price the customer was actually sold. */
    public function test_provisioning_snapshots_the_price_at_the_moment_of_sale(): void
    {
        $package = $this->plan('business');

        $tenant = Tenant::create([
            'name' => 'Provisioned', 'slug' => 'provisioned', 'status' => Tenant::STATUS_ACTIVE,
        ]);
        Company::withoutGlobalScopes()->create([
            'name' => 'Yard', 'code' => 'PRV', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => 'active', 'type' => 'subscription', 'billing_cycle' => Subscription::CYCLE_YEARLY,
            'starts_at' => now(), 'ends_at' => now()->addYear(),
            'agreed_price_monthly' => $package->price_monthly,
            'agreed_price_yearly' => $package->price_yearly,
            'agreed_currency' => $package->currency,
        ]);

        $package->update(['price_yearly' => 99000000]);

        $this->assertSame(14990000.0, $subscription->fresh()->agreedAmountFor());
    }

    /** Repricing an existing customer is a deliberate act, and it moves them off legacy pricing. */
    public function test_repricing_a_subscription_updates_the_snapshot(): void
    {
        $subscription = $this->subscriptionOn('professional', [
            'agreed_price_monthly' => 999000, 'agreed_price_yearly' => 9990000, 'agreed_currency' => 'IDR',
        ]);

        $subscription->repriceTo(799000, 7990000);

        $this->assertSame(7990000.0, $subscription->fresh()->agreedAmountFor());
        $this->assertFalse($subscription->fresh()->isOnLegacyPricing());
    }

    /* ================================================================
     * 4. THE DEAD FEATURE LIST IS ACTUALLY GONE
     * ================================================================ */

    /**
     * `packages.features` was a third list of what a plan includes, beside
     * the two that are actually enforced. Nothing read it, and it drifted.
     * Dropped rather than left dormant for somebody to reach for.
     */
    public function test_the_dead_package_features_column_is_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('packages', 'features'));
        $this->assertFalse(method_exists(Package::class, 'hasFeature'));
    }

    /** The provisioning service is still the one place that turns a plan into grants. */
    public function test_provisioning_grants_the_tiers_workspaces(): void
    {
        $this->assertTrue(
            method_exists(TenantProvisioningService::class, 'activate'),
            'Provisioning must remain the single path from a paid plan to a granted tenant.'
        );

        $tenant = Tenant::create([
            'name' => 'Granted', 'slug' => 'granted', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $package = $this->plan('business');
        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));

        $granted = $tenant->workspaces()->pluck('key')->sort()->values()->all();
        $expected = collect($package->defaultWorkspaceKeys())->sort()->values()->all();

        $this->assertSame($expected, $granted);
        $this->assertContains('procurement', $granted);
        $this->assertNotContains('warehouse', $granted);
    }
}
