<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.58.0 -- THE EMPTY SIDEBAR.
 *
 * A self-service customer could sign in, reach the Dashboard, and see a
 * navy sidebar with nothing in it.
 *
 * Root cause, in two halves that compounded:
 *
 *  1. `reports` and `administration` are `tier: 'global'` -- the
 *     application's own chrome (Dashboard, Reports, Settings, Users, Audit
 *     Logs) -- and the sidebar's global state, the state you are in ON THE
 *     DASHBOARD, is built from exactly those two. No plan granted them:
 *     `Package::defaultWorkspaceKeys()` listed departments only. Both were
 *     therefore forced inactive for every Starter and Professional tenant.
 *  2. `HandleInertiaRequests` read "no grant rows" as "nothing granted",
 *     while `EntitlementService` reads the same state as "unrestricted".
 *     A tenant could be allowed through every route and shown no
 *     navigation to reach them with.
 *
 * These tests pin the fix AND the boundary it must not cross: a Starter
 * tenant still must not receive Warehouse.
 */
class TenantNavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The `workspaces` table is populated by WorkspaceSeeder, not by a
     * migration, so a RefreshDatabase run starts with it EMPTY. Every gate
     * under test reads `tier` from those rows -- without them the whole
     * suite would pass vacuously by falling through the "no grants"
     * branch, which is the opposite of what it is checking.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
    }

    private function plan(string $slug): Package
    {
        // v2.60.0: firstOrCreate, because the four-tier migration already
        // wrote starter/professional/business/enterprise into `packages`.
        // A real tier resolves to its real row; an off-catalog slug (the
        // unknown-plan case below) is still created on the spot.
        return Package::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst($slug),
                'price_monthly' => 299000, 'price_yearly' => 2990000, 'currency' => 'IDR',
                'max_users' => 10, 'max_companies' => 1, 'is_active' => true, 'is_public' => true,
            ]
        );
    }

    /** A tenant provisioned exactly the way TenantProvisioningService does it. */
    private function tenantOnPlan(string $slug): Tenant
    {
        $package = $this->plan($slug);

        $tenant = Tenant::create([
            'name' => ucfirst($slug).' Customer', 'slug' => $slug.'-customer',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        Company::withoutGlobalScopes()->create([
            'name' => ucfirst($slug).' Yard', 'code' => strtoupper($slug),
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => 'active', 'type' => 'subscription', 'billing_cycle' => 'monthly',
            'starts_at' => now(), 'ends_at' => now()->addMonth(),
        ]);

        $tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        return $tenant->fresh();
    }

    private function adminFor(Tenant $tenant): User
    {
        app(CurrentTenant::class)->set($tenant);

        return User::create([
            'name' => 'Admin', 'email' => uniqid().'@customer.test',
            'password' => bcrypt('secret-pass-1'), 'role' => 'super_admin',
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);
    }

    /** Every tier a real customer can be on -- Business included since v2.60.0. */
    public static function customerPlans(): array
    {
        return array_map(
            fn (string $slug) => [$slug],
            array_combine(\Tests\Support\ApprovedCatalogue::slugs(), \Tests\Support\ApprovedCatalogue::slugs())
        );
    }

    /* ================================================================
     * 1. EVERY PLAN RECEIVES THE APPLICATION'S OWN CHROME
     * ================================================================ */

    #[\PHPUnit\Framework\Attributes\DataProvider('customerPlans')]
    public function test_every_plan_grants_the_global_workspaces(string $slug): void
    {
        $keys = $this->plan($slug)->defaultWorkspaceKeys();

        $this->assertContains('reports', $keys, "$slug must include Reports -- it is not sold capacity.");
        $this->assertContains('administration', $keys, "$slug must include Administration -- Settings and Users live there.");
    }

    /** An unrecognised slug must not mean "no product at all". */
    public function test_an_unknown_plan_slug_still_grants_the_global_workspaces(): void
    {
        $keys = $this->plan('some-custom-plan')->defaultWorkspaceKeys();

        $this->assertContains('reports', $keys);
        $this->assertContains('administration', $keys);
    }

    /* ================================================================
     * 2. THE SIDEBAR ACTUALLY RENDERS, FOR EVERY PLAN
     * ================================================================ */

    /**
     * The prop the sidebar is built from. `applyCatalog()` in
     * resources/js/lib/workspaces.js drops any entry with
     * `is_active: false`, and `getGlobalNavItems()` reads ONLY reports +
     * administration -- so these two flags decide whether the Dashboard
     * has a sidebar at all.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('customerPlans')]
    public function test_the_dashboard_sidebar_is_not_empty_for_any_plan(string $slug): void
    {
        $tenant = $this->tenantOnPlan($slug);
        $admin = $this->adminFor($tenant);

        $catalog = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('page')['props']['workspace_catalog'];

        foreach (['reports', 'administration'] as $key) {
            $this->assertTrue(
                (bool) $catalog[$key]['is_active'],
                "A {$slug} tenant lost the '{$key}' workspace, which empties the Dashboard sidebar."
            );
        }
    }

    /**
     * THE REGRESSION THIS MUST NOT CAUSE. Global workspaces are always
     * granted; DEPARTMENTS are still sold, and a Starter tenant must not
     * quietly gain Warehouse.
     */
    public function test_a_starter_tenant_still_does_not_receive_a_professional_department(): void
    {
        $tenant = $this->tenantOnPlan('starter');
        $admin = $this->adminFor($tenant);

        $catalog = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->viewData('page')['props']['workspace_catalog'];

        $this->assertFalse((bool) $catalog['warehouse']['is_active'], 'Starter must not receive Warehouse.');
        $this->assertFalse((bool) $catalog['hr']['is_active'], 'Starter must not receive Human Resources.');
        $this->assertTrue((bool) $catalog['hse']['is_active'], 'Starter must receive its own HSE department.');
    }

    /**
     * v2.60.0 -- the Business tier is the reason the four-tier ladder
     * exists: it is the first plan that crosses departments. It must
     * receive the three it adds, and must still stop short of Enterprise.
     */
    public function test_a_business_tenant_receives_the_cross_functional_departments(): void
    {
        $tenant = $this->tenantOnPlan('business');
        $admin = $this->adminFor($tenant);

        $catalog = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->viewData('page')['props']['workspace_catalog'];

        foreach (['hse', 'hr', 'project-management', 'logistics', 'procurement'] as $key) {
            $this->assertTrue((bool) $catalog[$key]['is_active'], "Business must receive {$key}.");
        }

        // `warehouse` is a shell workspace granted only at Enterprise --
        // Business buys the real capability under Logistics / PPIC, which
        // is why the two must not be confused for each other.
        $this->assertFalse((bool) $catalog['warehouse']['is_active'], 'Business must not receive Warehouse.');
        $this->assertFalse((bool) $catalog['finance']['is_active'], 'Business must not receive Finance.');
    }

    public function test_a_professional_tenant_receives_hr_but_not_warehouse(): void
    {
        $tenant = $this->tenantOnPlan('professional');
        $admin = $this->adminFor($tenant);

        $catalog = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->viewData('page')['props']['workspace_catalog'];

        $this->assertTrue((bool) $catalog['hr']['is_active']);
        $this->assertTrue((bool) $catalog['hse']['is_active']);
        $this->assertFalse((bool) $catalog['warehouse']['is_active']);
    }

    /* ================================================================
     * 3. THE TWO LAYERS AGREE
     * ================================================================ */

    /**
     * A tenant with NO grant rows is "not yet restricted" to
     * EntitlementService. The navigation layer used to read the same state
     * as "nothing granted" and hide everything -- the contradiction that
     * made a legitimate customer navigation-less.
     */
    public function test_a_tenant_with_no_grants_is_unrestricted_in_both_layers(): void
    {
        $tenant = Tenant::create([
            'name' => 'Ungranted', 'slug' => 'ungranted', 'status' => Tenant::STATUS_ACTIVE,
        ]);
        Company::withoutGlobalScopes()->create([
            'name' => 'Yard', 'code' => 'UNG', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $service = app(EntitlementService::class);

        $this->assertSame(0, $tenant->workspaces()->count(), 'Precondition: this tenant has no grants.');

        $keys = $service->grantedWorkspaceKeys($tenant);

        foreach (['hse', 'reports', 'administration'] as $key) {
            $this->assertContains($key, $keys);
            $this->assertTrue($service->tenantCanUseWorkspace($tenant, $key));
        }
    }

    /** The route gate must never withhold a global workspace either -- Reports is in config('departments'). */
    public function test_the_route_gate_never_withholds_a_global_workspace(): void
    {
        $tenant = $this->tenantOnPlan('starter');
        $tenant->workspaces()->sync(Workspace::where('key', 'hse')->pluck('id'));

        $service = app(EntitlementService::class);

        $this->assertTrue($service->tenantCanUseWorkspace($tenant->fresh(), 'reports'));
        $this->assertTrue($service->tenantCanUseWorkspace($tenant->fresh(), 'administration'));
        $this->assertFalse($service->tenantCanUseWorkspace($tenant->fresh(), 'warehouse'));
    }

    /* ================================================================
     * 4. ROLE AND DEPARTMENT RULES ARE UNCHANGED
     * ================================================================ */

    /** A Department User is still confined to their own department; this pass did not touch that. */
    public function test_a_department_user_is_still_confined_to_their_department(): void
    {
        $tenant = $this->tenantOnPlan('enterprise');
        app(CurrentTenant::class)->set($tenant);

        $user = User::create([
            'name' => 'HSE Officer', 'email' => 'hse.officer@customer.test',
            'password' => bcrypt('secret-pass-1'), 'role' => 'hse',
            'tenant_id' => $tenant->id, 'is_active' => true, 'department_key' => 'hse',
        ]);

        $props = $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('page')['props'];

        $this->assertSame('hse', $props['auth']['user']['department_key']);
        $this->assertTrue($props['auth']['user']['is_department_user']);
        // The prefix allow-list the server actually enforces is unchanged.
        $this->assertNotEmpty($props['auth']['user']['department_prefixes']);
    }
}
