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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * v2.84.0 -- THE FINAL WORKSPACE ARCHITECTURE.
 *
 * IOMS is one platform with FIVE distinct spaces over shared, connected
 * data:
 *
 *   OPERATIONAL   HSE · People / HRD · Logistics / Warehouse · Management
 *   ADMINISTRATIVE                                            Admin Space
 *
 * and one thing that is not a workspace at all:
 *
 *   GLOBAL COMPANY DASHBOARD   Business only
 *
 * Four distinctions had collapsed into each other before this release, and
 * every test below pins one of them:
 *
 *   Dashboard  is not  Overview       (company-wide vs this workspace)
 *   Dashboard  is not  Management     (snapshot vs analysis)
 *   Management is not  Admin Space    (the business vs the account)
 *   Admin Space is not Master Admin   (the customer vs the operator)
 *
 * plus the two that are not the same kind of thing at all: a WORKSPACE is a
 * business area, a PERMISSION is what you may reach, and a FOCUS is only
 * where you start.
 */
class WorkspaceArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['saas.enforce_entitlement' => true, 'saas.enforce_workspace_entitlement' => true]);
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
    }

    /* ==================================================================
     * Fixtures -- a tenant granted exactly what its own plan grants
     * ================================================================== */

    private function tenantOn(string $slug): Tenant
    {
        $package = Package::where('slug', $slug)->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard '.$slug,
            'slug' => 'yard-'.$slug.'-'.uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YRD', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE,
            'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());

        return $this->tenant;
    }

    private function admin(array $overrides = []): User
    {
        return User::create([
            'name' => 'Owner',
            'email' => 'owner-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function entitlements(): EntitlementService
    {
        return app(EntitlementService::class);
    }

    /* ==================================================================
     * 1-3. THE PRODUCT IS FOUR OPERATIONAL WORKSPACES
     * ================================================================== */

    /** The one list every other answer is bounded by. */
    public function test_ioms_sells_exactly_four_operational_workspaces(): void
    {
        $this->assertSame(['hse', 'hr', 'logistics', 'management'], config('plans.operational'));
    }

    /**
     * The registry a customer's sidebar is built from contains those four,
     * Admin Space, and the reporting chrome -- and nothing else. This reads
     * the file because the file IS the source of truth for navigation.
     */
    public function test_the_navigation_registry_offers_no_retired_workspace(): void
    {
        $registry = file_get_contents(resource_path('js/lib/workspaces.js'));

        foreach (['hse', 'hr', 'logistics', 'management', 'reports', 'administration'] as $key) {
            $this->assertStringContainsString("key: '{$key}',", $registry, "The registry lost '{$key}'.");
        }

        foreach ([
            'project-management', 'procurement', 'asset-management',
            'maintenance', 'quality-control', 'finance', 'warehouse',
        ] as $retired) {
            $this->assertStringNotContainsString(
                "key: '{$retired}',",
                $registry,
                "'{$retired}' is not customer-facing and must not be in the navigation registry."
            );
        }

        // And nothing anywhere is offered as a locked preview.
        $this->assertStringNotContainsString('disabled: true', $registry, 'IOMS does not advertise unbuilt navigation.');
    }

    /** No plan grants a retired department, including the retired plan itself. */
    public function test_no_plan_grants_a_retired_department(): void
    {
        $sold = config('plans.operational');

        foreach (Package::all() as $package) {
            foreach ($package->departmentWorkspaceKeys() as $key) {
                $this->assertContains(
                    $key,
                    $sold,
                    "Plan '{$package->slug}' grants '{$key}', which IOMS does not sell."
                );
            }
        }
    }

    /* ==================================================================
     * 4-6. THE PLAN MATRIX, ASSERTED BY DIRECT URL
     * ================================================================== */

    /**
     * @return array<string, array{0: string, 1: array<string>, 2: array<string>, 3: bool}>
     */
    public static function planMatrix(): array
    {
        return [
            // slug          reachable                                unreachable                              global dashboard
            'starter' => ['starter', ['hse'], ['hr', 'logistics', 'management'], false],
            'professional' => ['professional', ['hse', 'hr'], ['logistics', 'management'], false],
            'business' => ['business', ['hse', 'hr', 'logistics', 'management'], [], true],
        ];
    }

    /** Overview routes, per workspace key. */
    private function overview(string $key): string
    {
        return config('workspaces.overviews')[$key];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('planMatrix')]
    public function test_each_plan_reaches_exactly_its_own_workspaces(string $slug, array $reachable, array $unreachable): void
    {
        $this->tenantOn($slug);
        $admin = $this->admin();

        foreach ($reachable as $key) {
            $this->actingAs($admin)
                ->get(route($this->overview($key)))
                ->assertOk("{$slug} must reach its own {$key} Overview.");

            $this->assertTrue($this->entitlements()->userCanUseWorkspace($admin, $key));
        }

        foreach ($unreachable as $key) {
            $this->actingAs($admin)
                ->get(route($this->overview($key)))
                ->assertForbidden("{$slug} must not reach {$key}.");

            $this->assertFalse($this->entitlements()->userCanUseWorkspace($admin, $key));
        }
    }

    /**
     * THE GLOBAL COMPANY DASHBOARD IS BUSINESS ONLY, and the plans without
     * it are REDIRECTED rather than refused.
     *
     * A 403 on `/dashboard` would greet a paying Starter customer with a
     * denial every time they signed in -- it is the post-login landing route
     * and the one pinned link in the header. They are taken to the workspace
     * they actually work in instead.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('planMatrix')]
    public function test_only_business_receives_the_global_company_dashboard(string $slug, array $reachable, array $unreachable, bool $hasDashboard): void
    {
        $this->tenantOn($slug);
        $admin = $this->admin();

        $this->assertSame($hasDashboard, $this->entitlements()->tenantHasGlobalDashboard($this->tenant));

        $response = $this->actingAs($admin)->get(route('dashboard'));

        if ($hasDashboard) {
            $response->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard/Index'));

            return;
        }

        $response->assertRedirect(route($this->overview($reachable[0])));
    }

    /** The company snapshot never carries a figure from a workspace the plan lacks. */
    public function test_the_global_dashboard_shows_no_retired_or_unentitled_metric(): void
    {
        $this->tenantOn('business');

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(function ($page) {
                // Procurement, Assets and Maintenance left with their
                // workspaces -- the props are gone, not zeroed.
                $page->missing('pendingProcurementCount')
                    ->missing('assetCount')
                    ->missing('maintenanceDueCount')
                    ->where('workspaceAccess.hse', true)
                    ->where('workspaceAccess.management', true);
            });
    }

    /* ==================================================================
     * 7-9. THE FOUR DISTINCTIONS
     * ================================================================== */

    /** Dashboard, Overview and Management are three different pages. */
    public function test_the_dashboard_the_overviews_and_management_are_distinct_pages(): void
    {
        $this->tenantOn('business');
        $admin = $this->admin();

        $components = [];

        foreach (['dashboard', 'hse.dashboard', 'hr.dashboard', 'logistics.dashboard', 'management.overview'] as $name) {
            $this->actingAs($admin)->get(route($name))->assertOk()->assertInertia(function ($page) use (&$components) {
                $components[] = $page->toArray()['component'];
            });
        }

        $this->assertSame(
            $components,
            array_unique($components),
            'Each of these answers a different question and must be its own page.'
        );
        $this->assertContains('Dashboard/Index', $components);
        $this->assertContains('Management/Overview', $components);
    }

    /** Admin Space is its own space, and it is not Master Admin. */
    public function test_admin_space_is_the_customers_own_administration(): void
    {
        $this->tenantOn('starter');
        $admin = $this->admin();

        // Available on the entry tier: administration is not sold.
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Overview'));

        // And the operator's console stays closed to a tenant administrator.
        $this->assertNotSame(200, $this->actingAs($admin)->get(route('platform.dashboard'))->getStatusCode());
        $this->assertNotSame(200, $this->actingAs($admin)->get(route('platform.support'))->getStatusCode());
    }

    /**
     * Admin Space's navigation is administration, and reporting belongs to
     * the operational workspaces.
     *
     * The defect: the old sidebar merged Reports + Administration into one
     * "global navigation" state, so entering Admin Space showed Reports,
     * Analytics and Report Center beside Users, Roles and Billing.
     */
    public function test_admin_space_navigation_is_not_merged_with_reporting(): void
    {
        $registry = file_get_contents(resource_path('js/lib/workspaces.js'));

        $this->assertStringContainsString('export function getAdminSpaceItems(', $registry);
        $this->assertStringContainsString('export function getCompanyNavItems(', $registry);
        $this->assertStringContainsString('COMPANY_REPORTS_GROUP', $registry);

        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

        // The rail is the SPACE's navigation, chosen from three named states.
        $this->assertStringContainsString("space === 'admin'", $layout);
        $this->assertStringContainsString("space === 'workspace'", $layout);
        $this->assertStringContainsString('getAdminSpaceItems(', $layout);
        $this->assertStringContainsString('getCompanyNavItems(', $layout);
    }

    /* ==================================================================
     * 10-12. FOCUS IS CONTEXT, NEVER AUTHORIZATION
     * ================================================================== */

    /** All Workspaces exposes only the operational workspaces this account has. */
    public function test_all_workspaces_exposes_only_authorized_operational_workspaces(): void
    {
        $this->tenantOn('professional');
        $admin = $this->admin();

        $keys = $this->entitlements()->authorizedDepartmentKeys($admin);

        $this->assertSame(['hse', 'hr'], $keys);

        // Never the chrome, and never a workspace the plan does not grant.
        foreach (['administration', 'reports', 'management', 'logistics', 'procurement'] as $absent) {
            $this->assertNotContains($absent, $keys);
        }
    }

    /** A focus neither grants nor revokes -- asserted in both directions. */
    public function test_a_focus_changes_context_and_nothing_else(): void
    {
        $this->tenantOn('business');
        $admin = $this->admin();

        $before = $this->entitlements()->authorizedDepartmentKeys($admin);

        $this->actingAs($admin)
            ->put(route('account.workspace-focus'), ['workspace_focus' => 'logistics'])
            ->assertRedirect();

        $admin->refresh();

        $this->assertSame('logistics', $admin->workspace_focus);
        $this->assertSame($before, $this->entitlements()->authorizedDepartmentKeys($admin), 'A focus may not change what is authorized.');

        // Every other workspace is still open...
        foreach (['hse', 'hr', 'management'] as $key) {
            $this->actingAs($admin)->get(route($this->overview($key)))->assertOk();
        }

        // ...and one they never had is still closed.
        $this->actingAs($admin)
            ->put(route('account.workspace-focus'), ['workspace_focus' => 'procurement'])
            ->assertSessionHasErrors('workspace_focus');
    }

    /** Admin Space is not an operational workspace and is never a focus option. */
    public function test_admin_space_can_never_become_a_workspace_focus(): void
    {
        $this->tenantOn('business');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('account.workspace-focus'), ['workspace_focus' => 'administration'])
            ->assertSessionHasErrors('workspace_focus');

        $this->assertNull($admin->fresh()->workspace_focus);
        $this->assertNotContains('administration', $this->entitlements()->authorizedDepartmentKeys($admin));
    }

    /* ==================================================================
     * 13-14. THE PLUMBING THAT MAKES IT COHERENT
     * ================================================================== */

    /** Every workspace has an Overview, and every Overview route exists. */
    public function test_every_sold_workspace_has_a_real_overview_route(): void
    {
        $overviews = config('workspaces.overviews');

        $this->assertSame(config('plans.operational'), array_keys($overviews));

        foreach ($overviews as $key => $routeName) {
            $this->assertTrue(Route::has($routeName), "{$key}'s Overview route '{$routeName}' does not exist.");
        }
    }

    /** Tenant isolation is unchanged: one tenant's workspace never opens another's data. */
    public function test_workspace_access_is_tenant_scoped(): void
    {
        $this->tenantOn('business');
        $businessAdmin = $this->admin();

        $this->tenantOn('starter');
        $starterAdmin = $this->admin();

        // The starter account cannot reach Management even though a tenant
        // that CAN exists in the same database.
        $this->actingAs($starterAdmin)->get(route('management.overview'))->assertForbidden();
        $this->assertFalse($this->entitlements()->tenantHasGlobalDashboard($starterAdmin->tenant));

        app(CurrentTenant::class)->set($businessAdmin->tenant);
        $this->assertTrue($this->entitlements()->tenantHasGlobalDashboard($businessAdmin->tenant));
    }
}
