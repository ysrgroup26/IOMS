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

        $expected = [
            'starter' => [1499000, 14990000],
            'professional' => [3499000, 34990000],
            'enterprise' => [7499000, 74990000],
        ];

        foreach ($expected as $slug => [$monthly, $yearly]) {
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
