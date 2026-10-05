<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.98.0 -- TWO PLACES WHERE THE ACCESS MODEL WAS READ TOO STRICTLY.
 *
 * `AccessControlBoundaryTest` pins the rule that the interface may never
 * offer more than the server allows. These are the mirror image: the server
 * refusing, or the interface offering, something the product actually
 * intends.
 *
 * STARTER COULD NOT MANAGE ITS OWN WORKFORCE. `config('departments')` gave
 * the `employees` prefix to `hr` alone, and `EnforceTenantEntitlement`
 * resolved a prefix with `search()` -- the FIRST owning department. A
 * Starter tenant is entitled to HSE and deliberately not to People / HRD,
 * so its own employee register answered 403. A customer could not add the
 * people their PPE issues, incidents and permits are about.
 *
 * Note what the fix is NOT: Starter does not gain the People workspace. The
 * register is shared because HSE genuinely needs it; the HRD-specific
 * capabilities stay where they were.
 *
 * ADMIN SPACE WAS OFFERED TO PEOPLE WHO COULD NOT ENTER IT.
 * `canAccessAdminSpace()` asked about role only, so an HSE user ASSIGNED to
 * the HSE department answered yes and the header offered them the entry.
 * Every page inside Admin Space is under the `settings` prefix, which the
 * same map gives to `administration` alone, so `RestrictDepartmentAccess`
 * refused them at the door. The entry could only ever produce a 403.
 */
class StarterWorkforceAndAdminSpaceTest extends TestCase
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

    private function tenantOn(string $slug): Tenant
    {
        $package = Package::where('slug', $slug)->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard '.$slug, 'slug' => 'yard-'.$slug.'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YRD', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());

        return $this->tenant;
    }

    private function user(string $role, array $overrides = []): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'), 'role' => $role,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'is_active' => true, ...$overrides,
        ]);
    }

    /* ==================================================================
     * 1. STARTER MANAGES ITS OWN WORKFORCE
     * ================================================================== */

    /** @return array<string, array{0: string}> */
    public static function everyPlan(): array
    {
        return ['starter' => ['starter'], 'professional' => ['professional'], 'business' => ['business']];
    }

    /**
     * THE DEFECT. Every plan reaches its own employee register, including
     * the one that does not buy People / HRD.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyPlan')]
    public function test_every_plan_can_reach_its_own_employee_register(string $slug): void
    {
        $this->tenantOn($slug);
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get(route('employees.index'))->assertOk();
    }

    /**
     * And can actually ADD one, which is the point of the complaint: a
     * customer that cannot register its own workforce cannot use the
     * safety modules the workforce appears in.
     */
    public function test_a_starter_admin_can_create_an_employee(): void
    {
        $this->tenantOn('starter');
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        $department = Department::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Produksi',
        ]);

        $this->actingAs($admin)
            ->post(route('employees.store'), [
                'employee_id' => 'EMP-001',
                'full_name' => 'Budi Santoso',
                'company_id' => $this->company->id,
                'department_id' => $department->id,
                'status' => 'active',
                'employment_type' => Employee::EMPLOYMENT_TYPES[0],
            ])
            ->assertSessionHasNoErrors();

        // Scoped through its operating unit, which is how an employee
        // belongs to a tenant in this schema.
        $this->assertDatabaseHas('employees', [
            'employee_id' => 'EMP-001',
            'company_id' => $this->company->id,
        ]);
    }

    /** HSE is the role Starter actually runs on, and it must work too. */
    public function test_a_starter_hse_user_can_reach_the_employee_register(): void
    {
        $this->tenantOn('starter');
        $hse = $this->user('hse');

        $this->actingAs($hse)->get(route('employees.index'))->assertOk();
    }

    /**
     * STARTER DID NOT GAIN THE HRD WORKSPACE. This is the other half of the
     * fix and the easier one to get wrong: sharing the register must not
     * share everything beside it.
     */
    public function test_starter_still_does_not_get_the_people_workspace(): void
    {
        $this->tenantOn('starter');
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get(route('hr.dashboard'))->assertForbidden();

        // The HRD-specific capabilities stay refused.
        foreach (['shifts.master', 'competency.master', 'leave-requests.index', 'employee-cases.index'] as $routeName) {
            $this->actingAs($admin)
                ->get(route($routeName))
                ->assertForbidden();
        }
    }

    /** Professional and Business are unchanged by any of this. */
    public function test_professional_and_business_keep_the_full_people_workspace(): void
    {
        foreach (['professional', 'business'] as $slug) {
            $this->tenantOn($slug);
            $admin = $this->user(User::ROLE_SUPER_ADMIN);

            $this->actingAs($admin)->get(route('hr.dashboard'))->assertOk();
            $this->actingAs($admin)->get(route('shifts.master'))->assertOk();
            $this->actingAs($admin)->get(route('employees.index'))->assertOk();
        }
    }

    /**
     * A tenant entitled to NEITHER owning workspace is still refused, so
     * accepting any owner widened nothing it should not have.
     */
    public function test_a_prefix_is_still_refused_when_no_owning_workspace_is_granted(): void
    {
        $this->tenantOn('starter');
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        // Warehouse Logistics is owned by `logistics` alone, which Starter
        // does not have.
        $this->actingAs($admin)->get(route('items.index'))->assertForbidden();
    }

    /* ==================================================================
     * 2. ADMIN SPACE IS OFFERED ONLY WHERE IT CAN BE ENTERED
     * ================================================================== */

    /**
     * THE DEFECT. A department-confined user was offered an entry that
     * could only answer 403.
     */
    public function test_a_department_confined_user_is_not_offered_admin_space(): void
    {
        $this->tenantOn('business');
        $confined = $this->user('hse', ['department_key' => 'hse']);

        $this->assertFalse($confined->canAccessAdminSpace());

        // The header payload agrees.
        $this->actingAs($confined)
            ->get(route('hse.dashboard'))
            ->assertInertia(fn ($page) => $page->where('auth.user.can_access_admin_space', false));

        // And the backend still refuses the destination, which is the real
        // boundary. Hiding the entry did not replace it.
        $this->actingAs($confined)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($confined)->get(route('admin.index'))->assertForbidden();
    }

    /** An unrestricted HSE user keeps it -- they genuinely can enter. */
    public function test_an_unrestricted_hse_user_is_still_offered_admin_space(): void
    {
        $this->tenantOn('business');
        $hse = $this->user('hse');

        $this->assertTrue($hse->canAccessAdminSpace());

        $this->actingAs($hse)
            ->get(route('hse.dashboard'))
            ->assertInertia(fn ($page) => $page->where('auth.user.can_access_admin_space', true));

        // The entry points them at Settings, which they can open.
        $this->actingAs($hse)->get(route('settings.index'))->assertOk();
    }

    /** The tenant administrator is unaffected in every direction. */
    public function test_the_tenant_administrator_keeps_admin_space(): void
    {
        $this->tenantOn('business');
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        $this->assertTrue($admin->canAccessAdminSpace());
        $this->actingAs($admin)->get(route('admin.index'))->assertOk();
    }

    /** A role with no administrative capability never had it and still does not. */
    public function test_an_ordinary_role_is_not_offered_admin_space(): void
    {
        $this->tenantOn('business');
        $warehouse = $this->user('warehouse');

        $this->assertFalse($warehouse->canAccessAdminSpace());
        $this->actingAs($warehouse)->get(route('admin.index'))->assertForbidden();
    }

    /** Direct access remains the boundary for every unauthorized shape. */
    public function test_direct_admin_access_remains_forbidden_for_everyone_unauthorized(): void
    {
        $this->tenantOn('business');

        foreach ([
            $this->user('hse', ['department_key' => 'hse']),
            $this->user('warehouse'),
            $this->user('hrd'),
            $this->user('manager'),
        ] as $user) {
            $this->actingAs($user)->get(route('admin.index'))->assertForbidden();
        }
    }
}
