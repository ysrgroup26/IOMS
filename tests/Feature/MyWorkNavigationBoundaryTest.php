<?php

namespace Tests\Feature;

use App\Models\Company;
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
 * v2.103.0 -- THE MY WORK CLASS, AND WHAT THE INTERFACE OFFERS IT.
 *
 * `RestrictMyWorkUser` is a server-side allow-list and it is sound: a My
 * Work User reaches My Work, permits, tasks, their own account and nothing
 * else, whatever they type into the address bar.
 *
 * The navigation layer did not ask the same question. `canAccessAdminSpace()`
 * and `workspace_access` are computed from ROLE and PLAN, and the account's
 * CLASS was not part of either. A My Work User who also holds an
 * operational role -- which the user form allows, because class and role
 * are independent fields -- was therefore offered Admin Space and the
 * operational workspaces in the header, every one of which the allow-list
 * answers 403 for.
 *
 * This is the same defect v2.98.0 fixed for a department-confined user,
 * in a different place: the interface offering a door the server has
 * already closed. It takes no authorization away, because the allow-list
 * was always the boundary; it stops advertising what that boundary
 * refuses.
 */
class MyWorkNavigationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'yard-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YC', 'tenant_id' => $this->tenant->id, 'is_active' => true,
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
    }

    private function user(string $role, string $type = User::TYPE_FULL): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'user_type' => $type,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
    }

    /** The boundary itself, unchanged and still the real one. */
    public function test_a_my_work_user_is_refused_everything_outside_their_allow_list(): void
    {
        $fieldUser = $this->user('hse', User::TYPE_MY_WORK);

        foreach (['settings.index', 'admin.index', 'hse.dashboard', 'employees.index', 'subscription.billing'] as $routeName) {
            $this->actingAs($fieldUser)
                ->get(route($routeName))
                ->assertForbidden();
        }
    }

    /** And what it IS for still works. */
    public function test_a_my_work_user_reaches_my_work(): void
    {
        $fieldUser = $this->user('hse', User::TYPE_MY_WORK);

        $this->actingAs($fieldUser)->get(route('my-work'))->assertOk();
    }

    /**
     * THE DEFECT. The header offered Admin Space to an account the
     * allow-list refuses at the door.
     */
    public function test_a_my_work_user_is_not_offered_admin_space(): void
    {
        $fieldUser = $this->user('hse', User::TYPE_MY_WORK);

        $this->assertFalse(
            $fieldUser->canAccessAdminSpace(),
            'A My Work User can never enter Admin Space, so it must not be offered.'
        );

        $this->actingAs($fieldUser)
            ->get(route('my-work'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.user.can_access_admin_space', false));
    }

    /** Nor the operational workspaces, which answer 403 for this class. */
    public function test_a_my_work_user_is_offered_no_operational_workspace(): void
    {
        $fieldUser = $this->user('hse', User::TYPE_MY_WORK);

        $this->actingAs($fieldUser)
            ->get(route('my-work'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $access = $page->toArray()['props']['auth']['user']['workspace_access'];

                $this->assertSame(
                    [],
                    array_keys(array_filter($access)),
                    'Every operational workspace answers 403 for this class, so none may be advertised.'
                );
            });
    }

    /** The Business tier's company dashboard is equally out of reach. */
    public function test_a_my_work_user_is_not_offered_the_global_dashboard(): void
    {
        $fieldUser = $this->user('hse', User::TYPE_MY_WORK);

        $this->actingAs($fieldUser)
            ->get(route('my-work'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.user.has_global_dashboard', false));
    }

    /**
     * `dashboard` stays on the allow-list because sign-in machinery passes
     * through it, so the page itself must send this class away. A redirect
     * rather than a refusal, exactly as an unentitled tenant already gets.
     *
     * Before this, a Business tenant's My Work User -- sold at a fifth of a
     * Full User's price -- opened the cross-workspace company dashboard.
     */
    public function test_the_global_dashboard_sends_a_my_work_user_to_my_work(): void
    {
        $fieldUser = $this->user('hse', User::TYPE_MY_WORK);

        $this->actingAs($fieldUser)
            ->get(route('dashboard'))
            ->assertRedirect(route('my-work'));
    }

    /* ==================================================================
     * A FULL USER IS UNAFFECTED IN EVERY DIRECTION
     * ================================================================== */

    public function test_a_full_administrator_keeps_everything(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        $this->assertTrue($admin->canAccessAdminSpace());

        $this->actingAs($admin)
            ->get(route('hse.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.can_access_admin_space', true)
                ->where('auth.user.workspace_access.hse', true));
    }

    public function test_a_full_hse_user_keeps_their_workspaces(): void
    {
        $hse = $this->user('hse');

        $this->actingAs($hse)
            ->get(route('hse.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.user.workspace_access.hse', true));
    }
}
