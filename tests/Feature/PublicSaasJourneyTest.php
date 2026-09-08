<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.50.0 -- the public SaaS front door, and the boundaries around it.
 *
 * The journey is landing -> pricing -> get started -> (provisioning) ->
 * login -> IOMS. Before this release "Get Started" pointed at the login
 * form, which is the one door a prospect without an account cannot walk
 * through, and pricing existed only as an anchor inside the landing page
 * so there was no URL to send anyone to.
 *
 * These tests pin three things: the journey is reachable by a guest, it
 * exposes no tenant data, and the plan prices it advertises are the ones
 * the entitlement catalog actually holds.
 */
class PublicSaasJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlans(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class);
    }

    public function test_a_guest_can_reach_pricing_and_get_started(): void
    {
        $this->seedPlans();

        $this->get(route('pricing'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Public/Pricing')->has('plans'));

        $this->get(route('get-started'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Public/GetStarted')->has('plans'));
    }

    /** The standardized commercial model, asserted against the real catalog. */
    public function test_the_standardized_plan_prices_are_seeded(): void
    {
        $this->seedPlans();

        // The approved commercial model, asserted here so a seeder edit
        // that drifts from it fails loudly. v2.60.0 moved the figures
        // themselves to Tests\Support\ApprovedCatalogue, because they had
        // been transcribed into three separate test files and repricing
        // broke all three at once.
        foreach (\Tests\Support\ApprovedCatalogue::PLANS as $slug => [$monthly, $yearly, , ]) {
            $package = Package::where('slug', $slug)->first();

            $this->assertNotNull($package, "Plan {$slug} is missing.");
            $this->assertEquals($monthly, (float) $package->price_monthly, "{$slug} monthly price.");
            $this->assertEquals($yearly, (float) $package->price_yearly, "{$slug} yearly price.");
            $this->assertSame('IDR', $package->currency);
            $this->assertFalse(
                (bool) $package->is_custom,
                "{$slug} must be a standardized plan with a published price, never a negotiated custom build."
            );
        }
    }

    /**
     * THE v2.50.0 DEFECT, pinned.
     *
     * A seeder only runs when someone runs it; the `packages` table is
     * what the application actually reads. v2.50.0 changed the seeder and
     * production kept rendering the old placeholder prices for a whole
     * release. The standardize-pricing migration now applies the same
     * catalog on deploy -- this test proves the two agree, on a database
     * built by migrations alone with NO seeding.
     */
    public function test_the_pricing_migration_alone_produces_the_launch_catalog(): void
    {
        // Seed the ORIGINAL placeholder rows the migration has to correct,
        // exactly as an upgraded deployment would hold them.
        // v2.60.0: updateOrCreate, because the four-tier migration has
        // already written these slugs. The point is unchanged -- put the
        // database into the state an upgrading deployment was in, then run
        // the v2.51/v2.52 migrations over it.
        foreach ([['starter', 0, 0], ['professional', 49, 490], ['enterprise', 149, 1490]] as [$slug, $m, $y]) {
            Package::updateOrCreate(['slug' => $slug], [
                'name' => ucfirst($slug),
                'price_monthly' => $m, 'price_yearly' => $y, 'currency' => 'IDR',
                'is_active' => true, 'is_public' => true, 'is_custom' => $slug === 'enterprise',
            ]);
        }

        // Re-run the pricing migrations IN ORDER, exactly as an upgraded
        // deployment does. v2.52.0 supersedes v2.51.0's figures, so testing
        // only the first would assert prices no environment ends up with.
        (require database_path('migrations/2026_09_17_100220_standardize_launch_plan_pricing.php'))->up();
        (require database_path('migrations/2026_09_18_100230_standardize_plan_capacity_and_launch_pricing.php'))->up();

        $starter = Package::where('slug', 'starter')->first();
        $enterprise = Package::where('slug', 'enterprise')->first();

        $this->assertEquals(299000, (float) $starter->price_monthly);
        $this->assertEquals(2990000, (float) $starter->price_yearly);
        $this->assertEquals(1999000, (float) $enterprise->price_monthly);

        // Enterprise must stop being "Hubungi Kami" -- it is the highest
        // standardized tier, not a negotiated custom build.
        $this->assertFalse((bool) $enterprise->is_custom);
        $this->assertNotSame('Hubungi Kami', app(\App\Services\PricingService::class)->summarize($enterprise)['monthly']['formatted']);
    }

    /** IDR renders the way an Indonesian buyer reads it, on every surface, from one formatter. */
    public function test_idr_is_formatted_as_rupiah(): void
    {
        $this->assertSame('Rp299.000', app(\App\Services\PricingService::class)->format(299000.0, 'IDR'));
        $this->assertSame('Rp19.990.000', app(\App\Services\PricingService::class)->format(19990000.0, 'IDR'));
    }

    /** Every marketing nav destination must be a real, guest-reachable page -- no dead anchors. */
    public function test_every_public_navigation_destination_resolves(): void
    {
        $this->seedPlans();

        foreach (['platform-overview', 'solutions', 'how-it-works', 'faq', 'pricing', 'get-started'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }
    /** Annual must be genuinely cheaper per month, or presenting it as better value is a lie. */
    public function test_annual_billing_is_actually_better_value(): void
    {
        $this->seedPlans();

        foreach (Package::whereNotNull('price_yearly')->get() as $package) {
            if ((float) $package->price_monthly <= 0) {
                continue;
            }

            $this->assertLessThan(
                (float) $package->price_monthly * 12,
                (float) $package->price_yearly,
                "{$package->slug}: annual price must be below 12x monthly."
            );
        }
    }

    /**
     * The public journey must never leak tenant data. It reads only the
     * platform plan catalog, so a tenant's company name must not appear
     * even when tenants exist.
     */
    public function test_the_public_journey_exposes_no_tenant_data(): void
    {
        $this->seedPlans();

        $tenant = Tenant::create(['name' => 'ACME Shipyard', 'slug' => 'acme']);
        Company::withoutGlobalScopes()->create(['name' => 'ACME Shipyard', 'tenant_id' => $tenant->id]);
        app(CurrentTenant::class)->set(null);

        foreach ([route('pricing'), route('get-started'), route('home')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('ACME Shipyard');
        }
    }

    /** An unknown ?plan= must not be echoed back; it is validated against the catalog. */
    public function test_get_started_rejects_an_unknown_plan_slug(): void
    {
        $this->seedPlans();

        $this->get(route('get-started', ['plan' => 'not-a-real-plan']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('selectedPlan', null));

        $this->get(route('get-started', ['plan' => 'professional']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('selectedPlan', 'professional'));
    }

    /** An authenticated user has no business on the acquisition funnel's landing page. */
    public function test_an_authenticated_user_is_redirected_off_the_landing_page(): void
    {
        $tenant = Tenant::create(['name' => 'ACME', 'slug' => 'acme']);
        Company::withoutGlobalScopes()->create(['name' => 'ACME', 'tenant_id' => $tenant->id]);

        $user = User::create([
            'name' => 'Admin', 'email' => 'a@acme.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('home'))->assertRedirect(route('dashboard'));
    }

    /**
     * MASTER ADMIN BOUNDARY. A platform admin operates the platform; they
     * must not be able to wander into tenant surfaces, and a tenant user
     * must not reach the platform surface. Both directions are asserted so
     * neither can regress silently.
     */
    public function test_platform_and_tenant_surfaces_stay_separated(): void
    {
        $platform = User::create([
            'name' => 'Master', 'email' => 'master@ioms.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);

        $tenant = Tenant::create(['name' => 'ACME', 'slug' => 'acme']);
        Company::withoutGlobalScopes()->create(['name' => 'ACME', 'tenant_id' => $tenant->id]);
        $tenantAdmin = User::create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        // Platform admin is redirected out of the tenant application.
        $this->actingAs($platform)->get(route('dashboard'))->assertRedirect(route('platform.dashboard'));

        // A tenant administrator cannot reach the platform surface at all.
        $this->actingAs($tenantAdmin)->get(route('platform.dashboard'))->assertForbidden();
    }
}
