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
use Tests\Support\ApprovedCatalogue;
use Tests\TestCase;

/**
 * v2.96.0 -- EFFECTIVE ACCESS, AND THE RULE THAT THE UI MAY NEVER OFFER
 * MORE THAN THE SERVER ALLOWS.
 *
 * IOMS composes access from four independent things:
 *
 *   PLAN ENTITLEMENT      which workspaces the ORGANIZATION bought
 *   ROLE                  what this PERSON may do
 *   ASSIGNMENT            which department a Department User is confined to
 *   MODULE AVAILABILITY   which features are switched on
 *
 * `WorkspaceArchitectureTest` already pins the plan axis for workspace
 * REACH. What nothing pinned is the relationship between the two layers:
 * that a row the sidebar offers is a row the backend will actually serve,
 * and that a choice a form offers is a choice the request will accept.
 *
 * Both had drifted, in the same direction -- the UI offering more than the
 * server allows:
 *
 *   ASSIGNMENT  Settings built its department list from
 *               `config('departments')`, every department the APPLICATION
 *               knows about. A Starter tenant could assign somebody to
 *               People, Warehouse or Management: an entitlement never
 *               bought, given to a user whose routes then refused them.
 *
 *   NAVIGATION  Nine rows were gated more loosely than the routes behind
 *               them. Three Admin Space rows (Operating Units, Audit Logs,
 *               Module Management) are `role:super_admin` but were shown to
 *               HSE; six operational rows are `role:super_admin,hse` but
 *               were shown to every role in the workspace.
 *
 * 403 remains the real boundary and is asserted as such throughout. The
 * point of hiding a row is that a person is never invited to fail.
 */
class AccessControlBoundaryTest extends TestCase
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
        $this->seed(\Database\Seeders\ModuleSeeder::class);
    }

    /* ==================================================================
     * Fixtures
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

    private function userWithRole(string $role, array $overrides = []): User
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

    /** @return array<string, array{0: string, 1: array<int, string>}> */
    public static function planDepartments(): array
    {
        return [
            'starter' => ['starter', ['hse']],
            'professional' => ['professional', ['hse', 'hr']],
            'business' => ['business', ['hse', 'hr', 'logistics', 'management']],
        ];
    }

    /* ==================================================================
     * 1. THE PLAN BOUNDS WHO CAN BE ASSIGNED WHERE
     * ================================================================== */

    /**
     * The departments a tenant may assign are exactly the departments its
     * plan grants. Asserted against the approved commercial scope, not
     * against whatever the code computes.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('planDepartments')]
    public function test_assignable_departments_are_exactly_what_the_plan_grants(string $slug, array $expected): void
    {
        $this->tenantOn($slug);

        $this->assertSame(
            $expected,
            app(EntitlementService::class)->tenantDepartmentKeys($this->tenant),
            "$slug must offer exactly its own departments.",
        );

        // And the approved scope agrees, so this cannot drift with the code.
        $this->assertSame($expected, ApprovedCatalogue::SCOPE[$slug]);
    }

    /**
     * THE DEFECT THIS WAS WRITTEN FOR. A Starter tenant creating a user
     * could put them in a department Starter does not sell.
     */
    public function test_a_starter_tenant_cannot_assign_a_department_it_does_not_have(): void
    {
        $this->tenantOn('starter');
        $admin = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        foreach (['hr', 'logistics', 'management'] as $forbidden) {
            $this->actingAs($admin)
                ->post(route('settings.users.store'), [
                    'name' => 'Nova',
                    'email' => 'nova-'.$forbidden.'-'.uniqid().'@yard.test',
                    'password' => 'secret-pass-1',
                    'role' => 'hse',
                    'department_key' => $forbidden,
                ])
                ->assertSessionHasErrors('department_key');
        }

        $this->assertSame(0, User::where('tenant_id', $this->tenant->id)->where('department_key', '!=', null)->count());
    }

    /** The department the plan DOES grant is still assignable. */
    public function test_a_starter_tenant_can_assign_its_own_department(): void
    {
        $this->tenantOn('starter');
        $admin = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)
            ->post(route('settings.users.store'), [
                'name' => 'Nova',
                'email' => 'nova-'.uniqid().'@yard.test',
                'password' => 'secret-pass-1',
                'role' => 'hse',
                'department_key' => 'hse',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, User::where('tenant_id', $this->tenant->id)->where('department_key', 'hse')->count());
    }

    /** A higher plan accepts the departments it bought. */
    public function test_a_business_tenant_can_assign_every_department_it_bought(): void
    {
        $this->tenantOn('business');
        $admin = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        foreach (['hse', 'hr', 'logistics', 'management'] as $key) {
            $this->actingAs($admin)
                ->post(route('settings.users.store'), [
                    'name' => 'Nova',
                    'email' => 'nova-'.$key.'-'.uniqid().'@yard.test',
                    'password' => 'secret-pass-1',
                    'role' => 'hse',
                    'department_key' => $key,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(4, User::where('tenant_id', $this->tenant->id)->whereNotNull('department_key')->count());
    }

    /** Professional sits between the two, and is not simply "not Starter". */
    public function test_professional_cannot_assign_logistics_or_management(): void
    {
        $this->tenantOn('professional');
        $admin = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        foreach (['logistics', 'management'] as $forbidden) {
            $this->actingAs($admin)
                ->post(route('settings.users.store'), [
                    'name' => 'Nova',
                    'email' => 'nova-'.$forbidden.'-'.uniqid().'@yard.test',
                    'password' => 'secret-pass-1',
                    'role' => 'hse',
                    'department_key' => $forbidden,
                ])
                ->assertSessionHasErrors('department_key');
        }
    }

    /** An EXISTING user cannot be moved into an unentitled department either. */
    public function test_an_existing_user_cannot_be_moved_into_an_unentitled_department(): void
    {
        $this->tenantOn('starter');
        $admin = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $member = $this->userWithRole('hse', ['department_key' => 'hse']);

        $this->actingAs($admin)
            ->put(route('settings.users.update', $member), [
                'name' => $member->name,
                'email' => $member->email,
                'role' => 'hse',
                'department_key' => 'logistics',
            ])
            ->assertSessionHasErrors('department_key');

        $this->assertSame('hse', $member->fresh()->department_key);
    }

    /**
     * The FORM may not offer what the request would refuse.
     *
     * The dropdown was built in the browser from the hardcoded workspace
     * registry, so it kept offering every department after the server began
     * rejecting them -- a validation error where there should have been no
     * choice.
     */
    public function test_the_user_form_offers_only_the_departments_the_plan_grants(): void
    {
        $this->tenantOn('starter');
        $admin = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('assignableDepartments', fn ($offered) => collect($offered)->pluck('key')->all() === ['hse'])
            );
    }

    /* ==================================================================
     * 2. DIRECT URL REMAINS THE REAL BOUNDARY (403, not a hidden link)
     * ================================================================== */

    /**
     * Hiding a row is a courtesy. These are the same rows, reached by
     * typing the URL, and every one must still be refused.
     */
    public function test_super_admin_only_surfaces_refuse_hse_by_direct_url(): void
    {
        $this->tenantOn('business');
        $hse = $this->userWithRole('hse');

        // Audit Logs.
        $this->actingAs($hse)->get(route('activity-center.index'))->assertForbidden();

        // Operating Units.
        $this->actingAs($hse)
            ->post(route('settings.companies.store'), ['name' => 'Unit B', 'code' => 'UB'])
            ->assertForbidden();

        // Module Management.
        $this->actingAs($hse)->post(route('settings.modules'), ['modules' => []])->assertForbidden();

        // User management proper.
        $this->actingAs($hse)
            ->post(route('settings.users.store'), [
                'name' => 'X', 'email' => 'x-'.uniqid().'@yard.test',
                'password' => 'secret-pass-1', 'role' => 'hse',
            ])
            ->assertForbidden();
    }

    /**
     * WITHIN AN ENTITLED WORKSPACE, LISTS ARE READABLE AND WRITES ARE NOT.
     *
     * This is the design, and it is easy to "fix" in the wrong direction.
     * A workspace's index routes carry no role middleware on purpose: a
     * Warehouse user can read the employee register, and each controller
     * passes `can.manage` so the page renders without its write controls.
     * Hiding those rows from the sidebar would remove reading the product
     * intends people to do.
     *
     * The boundary is the WRITE, and it is asserted here so that "the row
     * is visible" can never quietly come to mean "the action is allowed".
     */
    public function test_within_an_entitled_workspace_lists_are_readable_and_writes_are_not(): void
    {
        $this->tenantOn('business');
        $warehouse = $this->userWithRole('warehouse');

        foreach (['employees.index', 'shifts.master', 'competency.master', 'ppe.dashboard'] as $routeName) {
            $this->actingAs($warehouse)
                ->get(route($routeName))
                ->assertOk();
        }

        // The write is refused, which is where the boundary actually is.
        $this->actingAs($warehouse)
            ->post(route('employees.store'), ['name' => 'Someone'])
            ->assertForbidden();
    }

    /** And the role that DOES have them is still served. */
    public function test_the_authorized_role_still_reaches_those_surfaces(): void
    {
        $this->tenantOn('business');
        $hse = $this->userWithRole('hse');

        foreach (['employees.index', 'shifts.master', 'ppe.dashboard'] as $routeName) {
            $this->actingAs($hse)->get(route($routeName))->assertOk();
        }

        $admin = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $this->actingAs($admin)->get(route('activity-center.index'))->assertOk();
    }

    /* ==================================================================
     * 3. THE PLAN STILL BOUNDS THE WORKSPACE, WHATEVER THE ROLE
     * ================================================================== */

    /**
     * Role never widens the plan. A Super Admin on Starter has every
     * capability IOMS defines and still cannot reach a workspace Starter
     * does not include.
     */
    public function test_role_never_widens_the_plan(): void
    {
        $this->tenantOn('starter');
        $admin = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get(route('hse.dashboard'))->assertOk();

        foreach (['hr.dashboard', 'logistics.dashboard', 'management.overview'] as $routeName) {
            $this->actingAs($admin)->get(route($routeName))->assertForbidden();
        }
    }

    /**
     * And the shared navigation payload agrees with the route gate, for
     * every sold workspace. This is the invariant that keeps the sidebar
     * and the server from drifting.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('planDepartments')]
    public function test_the_navigation_payload_matches_the_route_gate(string $slug, array $expected): void
    {
        $this->tenantOn($slug);
        $admin = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertInertia(function ($page) use ($expected) {
                $access = collect($page->toArray()['props']['auth']['user']['workspace_access'] ?? []);

                $this->assertSame(
                    $expected,
                    $access->filter()->keys()->values()->all(),
                    'The sidebar must offer exactly the workspaces the routes serve.',
                );
            });
    }

    /* ==================================================================
     * 4. A DEPARTMENT USER IS CONFINED, AND THE PAYLOAD SAYS SO
     * ================================================================== */

    public function test_a_department_user_is_confined_to_their_own_department(): void
    {
        $this->tenantOn('business');
        $hrOnly = $this->userWithRole('hse', ['department_key' => 'hr']);

        $entitlements = app(EntitlementService::class);

        $this->assertTrue($entitlements->userCanUseWorkspace($hrOnly, 'hr'));
        $this->assertFalse($entitlements->userCanUseWorkspace($hrOnly, 'hse'));
        $this->assertFalse($entitlements->userCanUseWorkspace($hrOnly, 'logistics'));

        // The route agrees.
        $this->actingAs($hrOnly)->get(route('logistics.dashboard'))->assertForbidden();
    }

    /* ==================================================================
     * 5. COMPLIMENTARY FOLLOWS ITS PLAN, AND GETS NOTHING EXTRA
     * ================================================================== */

    /**
     * A complimentary tenant is entitled by its PLAN, exactly like a paying
     * one. Billing mode grants nothing (ADR 041), so a free Starter is a
     * Starter.
     */
    public function test_a_complimentary_tenant_follows_its_plan_and_gains_nothing(): void
    {
        $this->tenantOn('starter');

        $this->tenant->subscription->update([
            'billing_mode' => Subscription::BILLING_MODE_COMPLIMENTARY,
        ]);

        $admin = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $entitlements = app(EntitlementService::class);

        $this->assertTrue($this->tenant->fresh()->subscription->isComplimentary());

        $this->assertSame(['hse'], $entitlements->tenantDepartmentKeys($this->tenant->fresh()));

        $this->actingAs($admin)->get(route('hse.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('hr.dashboard'))->assertForbidden();

        // And it cannot assign a department its plan does not grant either.
        $this->actingAs($admin)
            ->post(route('settings.users.store'), [
                'name' => 'Nova', 'email' => 'nova-'.uniqid().'@yard.test',
                'password' => 'secret-pass-1', 'role' => 'hse', 'department_key' => 'logistics',
            ])
            ->assertSessionHasErrors('department_key');
    }

    /* ==================================================================
     * 6. NO TENANT-SPECIFIC EXCEPTIONS
     * ================================================================== */

    /**
     * Two tenants on the SAME plan get the SAME answer. Nothing in the
     * access path may key off a tenant's identity.
     */
    public function test_two_tenants_on_one_plan_receive_identical_entitlements(): void
    {
        $this->tenantOn('professional');
        $first = app(EntitlementService::class)->tenantDepartmentKeys($this->tenant);

        $this->tenantOn('professional');
        $second = app(EntitlementService::class)->tenantDepartmentKeys($this->tenant);

        $this->assertSame($first, $second);
        $this->assertSame(['hse', 'hr'], $first);
    }
}
