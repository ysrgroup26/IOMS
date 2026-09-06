<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.46.0 -- the remaining "This page belongs to a different department."
 * 403, this time on PTW ACCESS (Settings > Users).
 *
 * ROOT CAUSE, traced route -> middleware -> role -> controller:
 *
 *   1. The HSE sidebar offers "PTW Access" -> settings.index?tab=users.
 *   2. That route, and the write behind it (settings.users.ptw-access), are
 *      BOTH explicitly gated `role:super_admin,hse` in routes/web.php, and
 *      SettingsController::updatePtwAccess() additionally asserts
 *      canManageHse(). HSE is authorized at every real layer.
 *   3. But config/departments.php mapped the `settings` prefix to
 *      `administration` ONLY, and RestrictDepartmentAccess took the FIRST
 *      matching department -- so a department-scoped HSE user was denied at
 *      the ROUTING layer before any of those gates could run.
 *
 * The routing layer was contradicting the authorization layer. The fix lets
 * a prefix be owned by several departments and lists `settings` for hse too.
 * It grants no new capability, which is what the second half of these tests
 * exists to prove: the mutating settings routes stay `role:super_admin`, and
 * a non-HSE department is still shut out entirely.
 */
class PtwAccessSettingsReachabilityTest extends TestCase
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

    /** THE BUG: an HSE user who may grant PTW Access could not open the page that grants it. */
    public function test_an_hse_department_user_can_open_settings_to_manage_ptw_access(): void
    {
        $user = $this->user(['role' => 'hse', 'department_key' => 'hse']);

        $this->assertTrue($user->canManageHse(), 'Sanity: HSE role may manage PTW Access.');

        $this->actingAs($user)->get(route('settings.index'))->assertOk();
    }

    /** And the write itself must go through, not just the page. */
    public function test_an_hse_department_user_can_actually_grant_ptw_access(): void
    {
        $admin = $this->user(['role' => 'hse', 'department_key' => 'hse']);
        $target = $this->user(['department_key' => 'project-management']);

        $this->assertFalse($target->fresh()->canCreatePtw());

        $this->actingAs($admin)
            ->put(route('settings.users.ptw-access', $target), ['ptw_access' => true])
            ->assertRedirect();

        $this->assertTrue($target->fresh()->canCreatePtw(), 'PTW Access was not actually granted.');
    }

    /** Administrators keep the access they always had. */
    public function test_an_administration_department_user_is_unaffected(): void
    {
        $user = $this->user(['role' => 'super_admin', 'department_key' => 'administration']);

        $this->actingAs($user)->get(route('settings.index'))->assertOk();
    }

    /**
     * NOT WEAKENED, part 1: a department with no claim on `settings` is
     * still shut out. This is the regression guard for the multi-owner
     * lookup -- a bug there would silently open Settings to everyone.
     */
    public function test_an_unrelated_department_user_still_cannot_open_settings(): void
    {
        $user = $this->user(['role' => 'super_admin', 'department_key' => 'warehouse']);

        $this->actingAs($user)->get(route('settings.index'))->assertForbidden();
    }

    /**
     * NOT WEAKENED, part 2: reaching Settings is not the same as being able
     * to change it. Company/branding stays `role:super_admin`, so an HSE
     * user who can now open the page still cannot rewrite tenant branding.
     */
    public function test_an_hse_user_still_cannot_change_company_settings(): void
    {
        $user = $this->user(['role' => 'hse', 'department_key' => 'hse']);

        $this->actingAs($user)
            ->post(route('settings.company'), ['company_name' => 'Hijacked'])
            ->assertForbidden();
    }

    /**
     * NOT WEAKENED, part 3: department membership never substitutes for the
     * role gate. An HSE-department user holding a non-HSE role is still
     * stopped by the route's own `role:super_admin,hse`.
     */
    public function test_an_hse_department_user_without_an_hse_role_is_still_blocked(): void
    {
        $user = $this->user(['role' => 'manager', 'department_key' => 'hse']);

        $this->actingAs($user)->get(route('settings.index'))->assertForbidden();
    }

    /** The multi-owner change must not disturb unrelated department boundaries. */
    public function test_other_department_boundaries_are_intact(): void
    {
        $warehouse = $this->user(['role' => 'super_admin', 'department_key' => 'warehouse']);

        $this->actingAs($warehouse)->get(route('incidents.index'))->assertForbidden();

        $hse = $this->user(['role' => 'hse', 'department_key' => 'hse']);
        $this->actingAs($hse)->get(route('incidents.index'))->assertOk();
    }
}
