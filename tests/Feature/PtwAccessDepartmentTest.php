<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.42.0 -- the reported "This page belongs to a different department." 403
 * on PTW.
 *
 * ROOT CAUSE: two authorization systems had silently diverged.
 * `User::canCreatePtw()` is deliberately a UNION -- canManageHse() OR an
 * individually-granted `ptw_access` flag (Settings > Users > PTW Access) --
 * precisely so a Field/Operations user in a NON-hse department can be granted
 * permit authoring rights without joining HSE. But `config/departments.php`
 * listed 'permits-to-work' as an hse-OWNED route prefix, so
 * RestrictDepartmentAccess 403'd exactly that user at the ROUTING layer,
 * before the controller's own canCreatePtw() check could ever run.
 *
 * The fix moves the prefix to UNIVERSAL_PREFIXES. It removes a routing-layer
 * guess, NOT an authorization check: the controller's per-action gates
 * (canCreatePtw() for create/store, canManageHse() for approval actions) are
 * unchanged and remain authoritative. These tests pin both halves -- the
 * granted user gets through, the ungranted one still does not.
 */
class PtwAccessDepartmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'ACME', 'slug' => 'acme']);
        Company::withoutGlobalScopes()->create(['name' => 'ACME', 'tenant_id' => $this->tenant->id]);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function user(array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => "User {$n}",
            'email' => "user{$n}@acme.test",
            'password' => bcrypt('secret'),
            'role' => 'manager',
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ], $attributes));
    }

    /** THE BUG: granted PTW Access, but in a non-HSE department. */
    public function test_a_granted_non_hse_department_user_can_reach_ptw(): void
    {
        $user = $this->user(['department_key' => 'project-management', 'ptw_access' => true]);

        $this->assertTrue($user->canCreatePtw(), 'Sanity: the capability itself must be granted.');

        $this->actingAs($user)->get(route('permits-to-work.index'))->assertOk();
        $this->actingAs($user)->get(route('permits-to-work.create'))->assertOk();
        $this->actingAs($user)->get(route('permits-to-work.mine'))->assertOk();
    }

    /**
     * The other half: removing the routing guess must NOT hand PTW authoring
     * to every department user. The controller's own capability gate is the
     * real boundary and is unchanged.
     */
    public function test_an_ungranted_department_user_still_cannot_create_a_ptw(): void
    {
        $user = $this->user(['department_key' => 'project-management', 'ptw_access' => false]);

        $this->assertFalse($user->canCreatePtw());

        $this->actingAs($user)->get(route('permits-to-work.create'))->assertForbidden();
    }

    /** HSE department users keep the access they always had. */
    public function test_an_hse_department_user_is_unaffected(): void
    {
        $user = $this->user(['department_key' => 'hse', 'role' => 'hse']);

        $this->actingAs($user)->get(route('permits-to-work.index'))->assertOk();
        $this->actingAs($user)->get(route('permits-to-work.create'))->assertOk();
    }

    /** Administrators (no department_key) are untouched by this middleware. */
    public function test_an_administrator_is_unaffected(): void
    {
        $user = $this->user(['role' => 'super_admin']);

        $this->actingAs($user)->get(route('permits-to-work.index'))->assertOk();
        $this->actingAs($user)->get(route('permits-to-work.create'))->assertOk();
    }

    /**
     * v2.42.0 IA fix: `dashboard` used to silently return Field Home for any
     * Department User, so the one route meaning "IOMS-wide company picture"
     * meant something different per account -- and made PTW look like the
     * primary content of the company Dashboard.
     */
    public function test_a_department_user_now_gets_the_company_wide_dashboard(): void
    {
        $user = $this->user(['department_key' => 'hse', 'role' => 'hse']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/Index'));
    }

    /** Field Home was not deleted -- it moved to an honestly-named route. */
    public function test_field_home_is_still_reachable_at_my_work(): void
    {
        $user = $this->user(['department_key' => 'hse', 'role' => 'hse']);

        $this->actingAs($user)
            ->get(route('my-work'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Field/Home'));
    }

    /** My Work is cross-department: a non-HSE department user reaches it too. */
    public function test_my_work_is_not_department_restricted(): void
    {
        $user = $this->user(['department_key' => 'warehouse']);

        $this->actingAs($user)->get(route('my-work'))->assertOk();
    }

    /**
     * REGRESSION GUARD. The department boundary itself must still work --
     * this release widened exactly one prefix, not the mechanism. A
     * non-HSE user must still be blocked from an HSE-owned route.
     */
    public function test_department_restriction_still_blocks_other_hse_routes(): void
    {
        $user = $this->user(['department_key' => 'project-management', 'ptw_access' => true]);

        $this->actingAs($user)
            ->get(route('incidents.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('safety-observations.index'))
            ->assertForbidden();
    }
}
