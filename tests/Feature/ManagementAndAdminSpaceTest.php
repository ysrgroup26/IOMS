<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Services\ManagementInsightsService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.83.0 -- THE MANAGEMENT WORKSPACE, ADMIN SPACE, AND WORKSPACE FOCUS.
 *
 * Three capabilities that share one risk: each of them LOOKS like an
 * authorization change and must not be one.
 *
 *   MANAGEMENT      a new sellable workspace. The failure mode is a
 *                   Professional tenant reaching a Business capability, or
 *                   an HSE supervisor reading company-wide performance.
 *
 *   ADMIN SPACE     a reframing of administration. The failure mode is
 *                   removing something HSE already had, or letting an
 *                   ordinary user -- or the platform OPERATOR -- into a
 *                   customer's administration.
 *
 *   WORKSPACE FOCUS a preference. The failure mode is the one that would
 *                   be hardest to notice: a preference quietly becoming a
 *                   permission, in either direction.
 *
 * Every gate is therefore asserted at the ROUTE, not at the navigation
 * layer -- direct URL access is the only test that means anything, which is
 * this codebase's standing rule and the reason v1.10.5 exists.
 */
class ManagementAndAdminSpaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['saas.enforce_entitlement' => true, 'saas.enforce_workspace_entitlement' => true]);

        // The workspace CATALOGUE has to exist for any of this to mean
        // anything: `defaultWorkspaceKeys()` resolves global-tier keys from
        // this table, and an empty table makes every tenant look
        // "unprovisioned", which EntitlementService reads as unrestricted.
        // A test that granted nothing and asserted access would pass for
        // entirely the wrong reason.
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
    }

    /* ==================================================================
     * Fixtures
     * ================================================================== */

    /**
     * A tenant on a real plan, granted exactly what that plan grants --
     * which is the whole point: a test that syncs every workspace could
     * never catch a Business capability leaking onto Professional.
     */
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

        // The real grant path, from the plan's own scope.
        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());

        return $this->tenant;
    }

    private function userWith(string $role, array $overrides = []): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
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
     * 1-4. MANAGEMENT ENTITLEMENT -- the plan decides
     * ================================================================== */

    public function test_business_plan_grants_the_management_workspace(): void
    {
        $tenant = $this->tenantOn('business');

        $this->assertContains('management', Package::where('slug', 'business')->sole()->defaultWorkspaceKeys());
        $this->assertTrue($this->entitlements()->tenantCanUseWorkspace($tenant, 'management'));
    }

    public function test_starter_and_professional_do_not_grant_management(): void
    {
        foreach (['starter', 'professional'] as $slug) {
            $tenant = $this->tenantOn($slug);

            $this->assertNotContains('management', Package::where('slug', $slug)->sole()->defaultWorkspaceKeys(), $slug);
            $this->assertFalse($this->entitlements()->tenantCanUseWorkspace($tenant, 'management'), $slug);
        }
    }

    public function test_a_business_administrator_can_open_the_management_workspace(): void
    {
        $this->tenantOn('business');

        $this->actingAs($this->userWith(User::ROLE_SUPER_ADMIN))
            ->get(route('management.overview'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Management/Overview'));
    }

    /**
     * THE REVENUE-SIDE FAILURE. A route existing is not a grant, and
     * hiding the navigation entry is not enforcement -- so this asks for
     * the URL directly, exactly as a curious customer would.
     */
    public function test_a_professional_tenant_is_refused_management_by_direct_url(): void
    {
        $this->tenantOn('professional');
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN);

        foreach ([
            'management.overview', 'management.kpi', 'management.hse',
            'management.workforce', 'management.logistics', 'management.actions',
        ] as $name) {
            $this->actingAs($admin)->get(route($name))->assertForbidden();
        }
    }

    /* ==================================================================
     * 5-7. MANAGEMENT AUTHORIZATION -- the person decides too
     * ================================================================== */

    /**
     * v2.84.0 -- MANAGEMENT ASKS THE SAME QUESTION AS EVERY OTHER WORKSPACE.
     *
     * v2.83.0 gated this workspace on a role allow-list (tenant administrator
     * or Manager) ON TOP of the plan. It was the only workspace in IOMS that
     * did, and the consequence was a 403 for accounts the customer had paid
     * for -- an HRD or HSE account on a tenant whose plan includes Management
     * was refused a page their organization bought.
     *
     * The gate is now the plan grant plus a Department User's own assignment,
     * exactly as it is for HSE, People and Logistics. Every role that is not
     * confined to another department may read it.
     */
    public function test_every_role_on_an_entitled_tenant_may_read_management(): void
    {
        $this->tenantOn('business');

        foreach ([User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER, User::ROLE_HSE, User::ROLE_HRD, User::ROLE_WAREHOUSE] as $role) {
            $this->actingAs($this->userWith($role))
                ->get(route('management.overview'))
                ->assertOk("{$role} was refused a workspace their plan includes.");
        }
    }

    /**
     * A DEPARTMENT USER STAYS IN THEIR DEPARTMENT, which is the one
     * person-level rule that still applies -- and it applies to Management
     * the same way it applies to everything else.
     *
     * This is also half of the v2.83.0 403: an account assigned to HSE was
     * refused by `RestrictDepartmentAccess` before the controller ever ran.
     * That refusal is correct and is kept; what changed is that an
     * UNASSIGNED account is no longer refused as well.
     */
    public function test_a_department_user_stays_inside_their_own_department(): void
    {
        $this->tenantOn('business');

        $hseOnly = $this->userWith(User::ROLE_HSE, ['department_key' => 'hse']);

        $this->actingAs($hseOnly)->get(route('management.overview'))->assertForbidden();
        $this->assertFalse($this->entitlements()->userCanUseWorkspace($hseOnly, 'management'));
        $this->assertTrue($this->entitlements()->userCanUseWorkspace($hseOnly, 'hse'));
    }

    public function test_the_management_capability_is_not_granted_by_the_platform_operator_role(): void
    {
        $this->tenantOn('business');

        $operator = User::create([
            'name' => 'Operator', 'email' => 'ops-'.uniqid().'@ioms.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null, 'is_active' => true,
        ]);

        // A tenantless account holds no tenant capability at all: an
        // operator does not silently become management of every customer.
        $this->assertFalse($this->entitlements()->userCanUseWorkspace($operator, 'management'));
        $this->assertFalse($operator->isTenantAdmin());
        $this->assertFalse($operator->canAccessAdminSpace());
        $this->assertFalse($this->entitlements()->tenantHasGlobalDashboard($operator->tenant));
    }

    /* ==================================================================
     * 8-10. MANAGEMENT AGGREGATES -- real data, and tenant-isolated
     * ================================================================== */

    public function test_management_figures_are_read_from_real_tenant_records(): void
    {
        $this->tenantOn('business');
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Fabrication', 'code' => 'FAB', 'is_active' => true,
        ]);

        foreach (range(1, 3) as $index) {
            Employee::create([
                'employee_id' => 'EMP-'.$index.'-'.uniqid(),
                'full_name' => 'Worker '.$index,
                'company_id' => $this->company->id,
                'department_id' => $department->id,
                'status' => 'active',
            ]);
        }

        Incident::create([
            'incident_number' => 'INC-'.uniqid(),
            'title' => 'Dropped object',
            'category' => 'near_miss',
            'incident_date' => now()->subDays(3)->toDateString(),
            'severity' => 'high',
            'status' => Incident::STATUS_REPORTED,
            'company_id' => $this->company->id,
            'reported_by' => $admin->id,
        ]);

        $this->actingAs($admin);
        $insights = app(ManagementInsightsService::class);
        $summary = $insights->executiveSummary();

        $this->assertSame(3, $summary['headcount']['active']);
        $this->assertTrue($summary['headcount']['available']);
        $this->assertSame(1, $summary['incidents']['open']);
        $this->assertSame(3, $summary['incidents']['days_since_last']);

        $comparison = $insights->departmentComparison((int) now()->year);
        $this->assertSame('Fabrication', $comparison[0]['name']);
        $this->assertSame(3, $comparison[0]['headcount']);
    }

    /**
     * TENANT ISOLATION, ASSERTED ON THE AGGREGATES THEMSELVES.
     *
     * An aggregate is where isolation is easiest to lose: a COUNT with a
     * missing WHERE returns a plausible number rather than an error, so
     * nothing looks broken. This builds two tenants with different data
     * and reads each one's totals.
     */
    public function test_management_aggregates_never_cross_tenants(): void
    {
        // Tenant A: three employees, one incident.
        $this->tenantOn('business');
        $adminA = $this->userWith(User::ROLE_SUPER_ADMIN);
        $companyA = $this->company;

        foreach (range(1, 3) as $index) {
            Employee::create([
                'employee_id' => 'A-'.$index.'-'.uniqid(), 'full_name' => 'A Worker '.$index,
                'company_id' => $companyA->id, 'status' => 'active',
            ]);
        }
        Incident::create([
            'incident_number' => 'INC-A-'.uniqid(), 'title' => 'A incident', 'category' => 'near_miss',
            'incident_date' => now()->subDay()->toDateString(), 'severity' => 'low',
            'status' => Incident::STATUS_REPORTED, 'company_id' => $companyA->id, 'reported_by' => $adminA->id,
        ]);

        // Tenant B: seven employees, no incidents.
        $this->tenantOn('business');
        $adminB = $this->userWith(User::ROLE_SUPER_ADMIN);

        foreach (range(1, 7) as $index) {
            Employee::create([
                'employee_id' => 'B-'.$index.'-'.uniqid(), 'full_name' => 'B Worker '.$index,
                'company_id' => $this->company->id, 'status' => 'active',
            ]);
        }

        $this->actingAs($adminB);
        app(CurrentTenant::class)->set($adminB->tenant);
        $forB = app(ManagementInsightsService::class)->executiveSummary();

        $this->assertSame(7, $forB['headcount']['active'], 'Tenant B must not see tenant A headcount');
        $this->assertSame(0, $forB['incidents']['open'], 'Tenant B has no incidents of its own');
        $this->assertFalse($forB['incidents']['available'], 'and must not inherit tenant A history');

        $this->actingAs($adminA);
        app(CurrentTenant::class)->set($adminA->tenant);
        $forA = app(ManagementInsightsService::class)->executiveSummary();

        $this->assertSame(3, $forA['headcount']['active']);
        $this->assertSame(1, $forA['incidents']['open']);
    }

    /**
     * A NEW TENANT MUST NOT BE TOLD ANYTHING FALSE.
     *
     * The v2.39.0 defect ("Great job!" on an empty database) in a new
     * shape: every section reports `available: false` rather than a
     * confident zero, and `days_since_last` is NULL rather than 0 -- "no
     * incident has ever been recorded" and "there was one today" must
     * never be the same value.
     */
    public function test_an_empty_tenant_reports_no_data_rather_than_zero(): void
    {
        $this->tenantOn('business');
        $this->actingAs($this->userWith(User::ROLE_SUPER_ADMIN));

        $insights = app(ManagementInsightsService::class);
        $summary = $insights->executiveSummary();

        $this->assertFalse($summary['headcount']['available']);
        $this->assertFalse($summary['incidents']['available']);
        $this->assertFalse($summary['corrective_actions']['available']);
        $this->assertNull($summary['incidents']['days_since_last']);

        $this->assertFalse($insights->workforce()['available']);
        $this->assertFalse($insights->logistics()['available']);
        $this->assertFalse($insights->compliance()['available']);
        $this->assertSame([], $insights->departmentComparison((int) now()->year));

        // And every page still renders rather than erroring on the gaps.
        foreach ([
            'management.overview', 'management.kpi', 'management.hse',
            'management.workforce', 'management.logistics', 'management.actions',
        ] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    /* ==================================================================
     * 11-14. ADMIN SPACE
     * ================================================================== */

    public function test_a_tenant_administrator_can_open_admin_space(): void
    {
        $this->tenantOn('starter');

        $this->actingAs($this->userWith(User::ROLE_SUPER_ADMIN))
            ->get(route('admin.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Overview'));
    }

    /**
     * ADMIN SPACE IS NOT SOLD. It is the customer's own administration, so
     * the entry tier reaches it exactly as Business does -- the opposite of
     * Management.
     */
    public function test_admin_space_is_available_on_every_plan(): void
    {
        foreach (['starter', 'professional', 'business'] as $slug) {
            $this->tenantOn($slug);

            $this->actingAs($this->userWith(User::ROLE_SUPER_ADMIN))
                ->get(route('admin.index'))
                ->assertOk();
        }
    }

    public function test_an_ordinary_user_cannot_reach_admin_space_by_direct_url(): void
    {
        $this->tenantOn('business');

        foreach ([User::ROLE_HSE, User::ROLE_HRD, User::ROLE_MANAGER, User::ROLE_WAREHOUSE] as $role) {
            $this->actingAs($this->userWith($role))
                ->get(route('admin.index'))
                ->assertForbidden();
        }
    }

    /**
     * HSE KEEPS WHAT IT ALREADY HAD.
     *
     * Reframing administration into a space must not quietly remove a
     * capability an existing customer uses: HSE has managed Departments and
     * Positions from Settings since v1.x. It may ENTER the space and is
     * still refused the administrator's own Overview -- entering a space is
     * not a permission.
     */
    public function test_hse_may_enter_admin_space_without_gaining_the_administrator_overview(): void
    {
        $this->tenantOn('business');
        $hse = $this->userWith(User::ROLE_HSE);

        $this->assertTrue($hse->canAccessAdminSpace());
        $this->assertFalse($hse->isTenantAdmin());

        $this->actingAs($hse)->get(route('settings.index'))->assertOk();
        $this->actingAs($hse)->get(route('admin.index'))->assertForbidden();
    }

    public function test_admin_space_reads_the_same_capacity_figures_as_billing(): void
    {
        $this->tenantOn('starter');
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN);

        $this->tenant->subscription->update(['additional_users' => 2]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $snapshot = $this->tenant->subscription->fresh()->stateSnapshot();

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('access.included_users', $snapshot['included_users'])
            ->where('access.additional_users', $snapshot['additional_users'])
            ->where('access.seat_limit', $snapshot['seat_limit'])
            ->where('subscription.lifecycle_state', $snapshot['lifecycle_state'])
        );
    }

    /** Admin Space data is the tenant's own, counted without the viewer's company scope. */
    public function test_admin_space_counts_only_its_own_tenants_accounts(): void
    {
        $this->tenantOn('business');
        $adminA = $this->userWith(User::ROLE_SUPER_ADMIN);
        $this->userWith(User::ROLE_HSE);
        $this->userWith(User::ROLE_HRD, ['is_active' => false]);

        $this->tenantOn('business');
        $adminB = $this->userWith(User::ROLE_SUPER_ADMIN);

        $this->actingAs($adminB)->get(route('admin.index'))->assertInertia(fn ($page) => $page
            ->where('access.total_users', 1)
            ->where('access.active_users', 1)
        );

        app(CurrentTenant::class)->set($adminA->tenant);
        $this->actingAs($adminA)->get(route('admin.index'))->assertInertia(fn ($page) => $page
            ->where('access.total_users', 3)
            // The deactivated account does not consume a slot (ADR 043).
            ->where('access.active_users', 2)
            ->where('access.inactive_users', 1)
        );
    }

    /* ==================================================================
     * 15-19. WORKSPACE FOCUS -- a preference, and nothing more
     * ================================================================== */

    public function test_all_workspaces_is_the_default_and_exposes_only_authorized_workspaces(): void
    {
        $this->tenantOn('professional');
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN);

        $this->assertNull($admin->workspace_focus, 'a new account starts on All Workspaces');

        $keys = $this->entitlements()->authorizedDepartmentKeys($admin);

        $this->assertContains('hse', $keys);
        $this->assertContains('hr', $keys);
        // Professional's scope, so neither the Business workspace nor the
        // application's own chrome may appear in a DEPARTMENT list.
        $this->assertNotContains('management', $keys);
        $this->assertNotContains('logistics', $keys);
        $this->assertNotContains('administration', $keys);
        $this->assertNotContains('reports', $keys);
    }

    public function test_a_focus_can_be_chosen_and_cleared_without_touching_permissions(): void
    {
        $this->tenantOn('professional');
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN);

        $before = [
            'role' => $admin->role,
            'department_key' => $admin->department_key,
            'granted' => $this->entitlements()->grantedWorkspaceKeys($admin->tenant),
        ];

        $this->actingAs($admin)
            ->put(route('account.workspace-focus'), ['workspace_focus' => 'hr'])
            ->assertRedirect();

        $admin->refresh();
        $this->assertSame('hr', $admin->workspace_focus);

        // A focused user still reaches every workspace they were entitled
        // to. This is the assertion that makes focus a preference.
        $this->actingAs($admin)->get(route('hse.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('hr.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('settings.index'))->assertOk();

        $this->assertSame($before['role'], $admin->role);
        $this->assertSame($before['department_key'], $admin->department_key);
        $this->assertSame($before['granted'], $this->entitlements()->grantedWorkspaceKeys($admin->tenant));

        // Clearing it returns the account to All Workspaces.
        $this->actingAs($admin)->put(route('account.workspace-focus'), ['workspace_focus' => null])->assertRedirect();
        $this->assertNull($admin->fresh()->workspace_focus);
    }

    /** A focus never widens access either -- it cannot be set to something the account is not already authorized for. */
    public function test_a_focus_cannot_name_a_workspace_the_account_is_not_authorized_for(): void
    {
        $this->tenantOn('professional');
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)
            ->put(route('account.workspace-focus'), ['workspace_focus' => 'management'])
            ->assertSessionHasErrors('workspace_focus');

        $this->assertNull($admin->fresh()->workspace_focus);
        $this->assertFalse($this->entitlements()->tenantCanUseWorkspace($admin->tenant, 'management'));
    }

    /**
     * A STALE FOCUS DEGRADES, IT DOES NOT STRAND.
     *
     * The realistic path into this state is a downgrade: a Business tenant
     * focused on Management drops to Professional. The stored value is
     * deliberately left alone (an upgrade should restore the preference),
     * and the EFFECTIVE value becomes All Workspaces.
     */
    public function test_a_focus_that_is_no_longer_granted_degrades_to_all_workspaces(): void
    {
        $this->tenantOn('business');
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN, ['workspace_focus' => 'management']);

        $this->assertSame('management', $this->entitlements()->effectiveWorkspaceFocus($admin));

        // Downgrade: the workspace grant is re-synced from the new plan.
        $professional = Package::where('slug', 'professional')->sole();
        $this->tenant->subscription->update(['package_id' => $professional->id]);
        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $professional->defaultWorkspaceKeys())->pluck('id'));
        $admin->refresh()->load('tenant');

        $this->assertSame('management', $admin->workspace_focus, 'the preference is kept');
        $this->assertNull($this->entitlements()->effectiveWorkspaceFocus($admin), 'but it is no longer served');

        // And the account is not stranded: the downgraded plan has no Global
        // Company Dashboard either, so /dashboard takes them to a workspace
        // they DO still have rather than refusing them.
        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('hse.dashboard'));
    }

    /** A Department User has exactly one workspace, so there is nothing to focus and nothing to switch. */
    public function test_a_department_user_has_a_single_authorized_workspace(): void
    {
        $this->tenantOn('business');
        $field = $this->userWith(User::ROLE_HSE, ['department_key' => 'hse']);

        $this->assertSame(['hse'], $this->entitlements()->authorizedDepartmentKeys($field));
        $this->assertNull($this->entitlements()->effectiveWorkspaceFocus($field));
    }

    /* ==================================================================
     * 20-22. NOTHING ELSE MOVED
     * ================================================================== */

    /**
     * The new `admin` and `subscription` prefixes joined the administration
     * entry in config/departments.php. Administration is GLOBAL tier, so no
     * plan may withhold it -- otherwise a Starter tenant loses its own
     * billing page, which is the v2.58.0 empty-sidebar defect exactly.
     */
    public function test_administration_prefixes_are_never_withheld_by_a_plan(): void
    {
        $this->tenantOn('starter');
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN);

        $this->assertContains('admin', config('departments.administration'));
        $this->assertContains('subscription', config('departments.administration'));
        $this->assertTrue($this->entitlements()->tenantCanUseWorkspace($admin->tenant, 'administration'));

        $this->actingAs($admin)->get(route('subscription.billing'))->assertOk();
        $this->actingAs($admin)->get(route('activity-center.index'))->assertOk();
    }

    /** Management is a real catalogue row of the right tier, or the navigation layer cannot resolve it. */
    public function test_management_is_a_department_tier_workspace_in_the_catalogue(): void
    {
        $workspace = Workspace::where('key', 'management')->sole();

        $this->assertSame(Workspace::TIER_DEPARTMENT, $workspace->tier);
        $this->assertTrue($workspace->is_active);
        $this->assertFalse(Workspace::isGlobalKey('management'), 'Management is sold, so it is not chrome');
    }

    /** The billing behaviour v2.82.0 shipped is unchanged by any of this. */
    public function test_existing_subscription_and_billing_behaviour_is_intact(): void
    {
        $this->tenantOn('business');
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN);
        $subscription = $this->tenant->subscription;

        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $subscription->lifecycleState());
        $this->assertSame(25, $subscription->includedUsers());
        $this->assertTrue($subscription->allowsWrites());

        $this->actingAs($admin)->get(route('subscription.billing'))->assertOk();

        // And a lapsed tenant may still READ the management workspace --
        // a lapse withdraws writing, never reading, and Management has no
        // writes at all.
        $subscription->update([
            'ends_at' => now()->subDays(Subscription::graceDays() + 3)->toDateString(),
        ]);
        $this->assertSame(Subscription::LIFECYCLE_LAPSED, $subscription->fresh()->lifecycleState());
        $this->actingAs($admin)->get(route('management.overview'))->assertOk();
    }
}
