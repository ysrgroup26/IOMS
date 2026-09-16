<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictDepartmentAccess;
use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.71.0 -- A CAPABILITY MUST BE REACHABLE BY WHOEVER OWNS IT.
 *
 * IOMS answers "may this person use this module" in three independent
 * places, and they can silently disagree:
 *
 *   1. `User::canManageX()`           -- the CAPABILITY. The real gate.
 *   2. `config/departments.php`       -- which DEPARTMENT owns the route.
 *   3. `config/plans.php`             -- which WORKSPACE a plan grants.
 *
 * When 1 and 2 diverge, the routing layer 403s a user the permission layer
 * allows. When 2 and 3 then interact, it gets worse: `EnforceTenantEntitlement`
 * resolves a route's owning workspace through the SAME department map, so
 * a module filed under a department the customer's plan does not include
 * becomes unreachable for everyone on that plan.
 *
 * That exact pair of bugs shipped twice before it was caught a third time:
 *
 *   permits-to-work   fixed v2.42.0  (PTW is cross-department by design)
 *   man-hour          fixed v1.11.15 (shared HR/HSE data)
 *   material-requests fixed v2.71.0  (HSE has owned the capability since
 *                                     the module shipped, while the route
 *                                     was filed under Logistics -- which
 *                                     neither Starter nor Professional
 *                                     buys)
 *
 * These tests exist so the fourth one fails here instead of in production.
 */
class DepartmentCapabilityReachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        config(['saas.enforce_entitlement' => true, 'saas.enforce_workspace_entitlement' => true]);
    }

    /* ==================================================================
     * The invariant, stated directly
     * ================================================================== */

    /**
     * Every capability whose permission spans more than one department must
     * be cross-department at the ROUTING layer too -- otherwise the routing
     * layer is stricter than the permission it is supposed to defer to.
     */
    public static function crossDepartmentCapabilities(): array
    {
        return [
            // route prefix        => the capability that owns it
            'material requests' => ['material-requests', 'canManageMaterialRequests'],
            'permits to work' => ['permits-to-work', 'canCreatePtw'],
            'man-hour' => ['man-hour', 'canManageManHour'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('crossDepartmentCapabilities')]
    public function test_a_cross_department_capability_is_not_owned_by_one_department(string $prefix, string $capability): void
    {
        $owningDepartment = collect(config('departments'))
            ->search(fn (array $prefixes) => in_array($prefix, $prefixes, true));

        $this->assertFalse(
            $owningDepartment,
            "`{$prefix}` is filed under the `{$owningDepartment}` department, but `User::{$capability}()` spans "
            .'more than one. Filing it under a single department makes RestrictDepartmentAccess -- and, through the '
            .'same map, EnforceTenantEntitlement -- stricter than the permission itself. It belongs in '
            .'RestrictDepartmentAccess::UNIVERSAL_PREFIXES.'
        );
    }

    /** The counterpart: it must actually BE in the universal list, not merely absent from the map. */
    public function test_material_requests_is_declared_universal(): void
    {
        $universal = (new \ReflectionClass(RestrictDepartmentAccess::class))
            ->getConstant('UNIVERSAL_PREFIXES');

        $this->assertContains('material-requests', $universal);
    }

    /**
     * Fulfilment is NOT cross-department, and must not drift that way. A
     * requester raising demand is everyone's business; buying and receiving
     * it is Procurement's and Logistics'.
     */
    public function test_procurement_and_fulfilment_routes_stay_department_owned(): void
    {
        $departments = collect(config('departments'));

        foreach (['purchase-requisitions', 'purchase-orders', 'goods-receipts', 'rfqs'] as $prefix) {
            $this->assertNotFalse(
                $departments->search(fn (array $prefixes) => in_array($prefix, $prefixes, true)),
                "`{$prefix}` should stay owned by a department -- fulfilment is a specialised function."
            );
        }
    }

    /* ==================================================================
     * The plan-level consequence, exercised over HTTP
     * ================================================================== */

    private function starterTenantWithHseAdmin(): User
    {
        $package = Package::where('slug', 'starter')->firstOrFail();
        $suffix = uniqid();

        $tenant = Tenant::create([
            'name' => 'Starter '.$suffix, 'slug' => 'starter-'.$suffix, 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Company::withoutGlobalScopes()->create([
            'name' => 'Yard', 'code' => strtoupper(substr($suffix, 0, 6)), 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now(), 'ends_at' => now()->addMonth(),
        ]);

        // Provisioned exactly as TenantProvisioningService does it: real
        // grant rows, so the "no grants recorded = unrestricted" safety net
        // does NOT apply and the real allow-list is what is being tested.
        $tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($tenant->fresh());

        return User::create([
            'name' => 'HSE Lead', 'email' => uniqid().'@starter.test',
            'password' => bcrypt('secret-pass-1'), 'role' => 'hse',
            'tenant_id' => $tenant->id, 'company_id' => $tenant->companies()->first()->id,
            'is_active' => true,
        ]);
    }

    /** Precondition, stated so the test below cannot pass vacuously. */
    public function test_the_starter_plan_does_not_include_logistics(): void
    {
        $granted = Package::where('slug', 'starter')->firstOrFail()->defaultWorkspaceKeys();

        $this->assertContains('hse', $granted);
        $this->assertNotContains('logistics', $granted, 'Precondition: Starter sells HSE without Logistics.');
    }

    /**
     * THE BUG THIS RELEASE FIXED, held down.
     *
     * An HSE user on the plan that sells HSE could not open the module
     * their own permission says they manage, because the route was filed
     * under a department their plan does not include.
     */
    public function test_an_hse_user_on_the_hse_only_plan_can_reach_material_requests(): void
    {
        $hse = $this->starterTenantWithHseAdmin();

        $this->assertTrue($hse->canManageMaterialRequests(), 'Precondition: HSE owns this capability.');

        $this->actingAs($hse)->get(route('material-requests.index'))->assertOk();
        $this->actingAs($hse)->get(route('material-requests.create'))->assertOk();
    }

    /**
     * The entitlement gate is still real. A Starter tenant must not reach a
     * module its plan genuinely does not include -- this test is what
     * proves the fix above widened one prefix and not the whole gate.
     */
    public function test_the_same_user_is_still_refused_a_module_the_plan_does_not_include(): void
    {
        $hse = $this->starterTenantWithHseAdmin();

        $this->actingAs($hse)->get(route('goods-receipts.index'))->assertForbidden();
    }

    /* ==================================================================
     * Field PTW account management
     * ================================================================== */

    /**
     * v2.71.0 -- HSE could grant PTW Access but not the field workspace, so
     * the toggle beside it 403'd. Both halves are needed to produce a
     * working field account, and both are now HSE's.
     */
    public function test_hse_can_grant_both_ptw_access_and_the_field_workspace(): void
    {
        $hse = $this->starterTenantWithHseAdmin();

        $field = User::create([
            'name' => 'Foreman', 'email' => uniqid().'@starter.test',
            'password' => bcrypt('secret-pass-1'), 'role' => 'manager',
            'tenant_id' => $hse->tenant_id, 'company_id' => $hse->company_id, 'is_active' => true,
        ]);

        $this->actingAs($hse)
            ->put(route('settings.users.ptw-access', $field), ['ptw_access' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($hse)
            ->put(route('settings.users.field-access', $field), ['is_field_user' => true])
            ->assertSessionHasNoErrors();

        $field = $field->fresh();
        $this->assertTrue((bool) $field->ptw_access);
        $this->assertTrue((bool) $field->is_field_user);
    }

    /** Issuing credentials stays an administrative act. HSE was NOT given it. */
    public function test_hse_still_cannot_create_accounts_or_set_credentials(): void
    {
        $hse = $this->starterTenantWithHseAdmin();

        $this->actingAs($hse)->post(route('settings.users.store'), [
            'name' => 'Should Not Exist',
            'email' => 'nope@starter.test',
            'password' => 'secret-pass-1',
            'role' => 'manager',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nope@starter.test']);
    }

    /** The tenant boundary holds on both toggles, for HSE exactly as for anyone else. */
    public function test_field_and_ptw_access_cannot_cross_tenants(): void
    {
        $mine = $this->starterTenantWithHseAdmin();
        $theirs = $this->starterTenantWithHseAdmin();

        $this->actingAs($mine)
            ->put(route('settings.users.ptw-access', $theirs), ['ptw_access' => true])
            ->assertNotFound();

        $this->actingAs($mine)
            ->put(route('settings.users.field-access', $theirs), ['is_field_user' => true])
            ->assertNotFound();

        $this->assertFalse((bool) $theirs->fresh()->ptw_access);
        $this->assertFalse((bool) $theirs->fresh()->is_field_user);
    }

    /**
     * An ordinary account cannot reach either toggle. The v2.19.0 route
     * group is `role:super_admin,hse` and stays that way.
     */
    public function test_an_ordinary_user_cannot_reach_the_access_toggles(): void
    {
        $hse = $this->starterTenantWithHseAdmin();

        $staff = User::create([
            'name' => 'Staff', 'email' => uniqid().'@starter.test',
            'password' => bcrypt('secret-pass-1'), 'role' => 'manager',
            'tenant_id' => $hse->tenant_id, 'company_id' => $hse->company_id, 'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->put(route('settings.users.ptw-access', $staff), ['ptw_access' => true])
            ->assertForbidden();

        $this->actingAs($staff)
            ->put(route('settings.users.field-access', $staff), ['is_field_user' => true])
            ->assertForbidden();
    }
}
