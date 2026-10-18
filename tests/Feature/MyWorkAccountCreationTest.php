<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictMyWorkUser;
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
 * v2.122.0 -- CREATING A MY WORK ACCOUNT, AND BEING ABLE TO USE IT.
 *
 * The owner reported that a My Work account "cannot be created". Driving
 * the application against a fresh MySQL database showed creation itself
 * SUCCEEDS -- the account is stored, it signs in, and it lands on My Work.
 * What was broken was everything around that:
 *
 *   1. EVERY account this form has ever created was written with
 *      `company_id` NULL. The form never sent an operating unit and
 *      `storeUser()` never set one, so an account belonged to a tenant but
 *      to no unit, and anything reading `$user->company_id` got null.
 *
 *   2. The account's own sidebar offered EIGHT links, SIX of which
 *      `RestrictMyWorkUser` answers 403 for (Calendar, Work Center,
 *      Reports, Analytics, Report Center, Document Library), plus a "My
 *      Tasks" tile pointing at Work Center and a "New PTW" button the
 *      account has no PTW Access for. An account whose every menu item
 *      refuses it is, from the person holding it, an account that cannot
 *      be used -- which is how it was reported.
 *
 *   3. A seat quota refusal was `abort(422)`, which Inertia renders as a
 *      bare error modal with nothing attached to the field that caused it.
 *
 * This pins all three, plus the Full User path it must not disturb.
 */
class MyWorkAccountCreationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        [$this->tenant, $this->company, $this->admin] = $this->tenantWith('business');
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: User}
     */
    private function tenantWith(string $packageSlug): array
    {
        $package = Package::where('slug', $packageSlug)->sole();

        $tenant = Tenant::create([
            'name' => 'Yard '.$packageSlug,
            'slug' => 'yard-'.uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit '.$packageSlug, 'code' => strtoupper(substr($packageSlug, 0, 3)).rand(10, 99),
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(30),
        ]);

        $tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($tenant->fresh());

        $admin = User::create([
            'name' => 'Admin '.$packageSlug,
            'email' => 'admin-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return [$tenant, $company, $admin];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Field Foreman',
            'email' => 'foreman-'.uniqid().'@yard.test',
            'password' => 'secret-pass-1',
            'role' => 'hrd',
            'user_type' => User::TYPE_MY_WORK,
        ], $overrides);
    }

    /* ==================================================================
     * 1. THE ACCOUNT IS CREATED, AND CREATED COMPLETELY
     * ================================================================== */

    public function test_a_my_work_account_is_created_and_can_sign_in(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->admin)
            ->post(route('settings.users.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $created = User::where('email', $payload['email'])->sole();

        $this->assertSame(User::TYPE_MY_WORK, $created->user_type);
        $this->assertTrue($created->isMyWorkUser());
        $this->assertSame($this->tenant->id, $created->tenant_id);
        $this->assertTrue($created->is_active);

        // THE DEFECT: this was null for every account the form ever made.
        $this->assertSame(
            $this->company->id,
            $created->company_id,
            'An account created by an administrator belongs to that administrator\'s operating unit.'
        );

        // Nobody clicks a verification link for an account whose password
        // an administrator set; the administrator IS the verification.
        $this->assertNotNull($created->email_verified_at);

        // And the credentials actually work. The administrator who made
        // the account is signed out first, or the login route simply
        // redirects an already-authenticated session.
        auth()->logout();
        session()->invalidate();

        $this->post(route('login'), [
            'email' => $payload['email'],
            'password' => $payload['password'],
        ])->assertRedirect();

        $this->assertAuthenticatedAs($created);
    }

    public function test_an_administrator_may_choose_another_unit_of_their_own_tenant(): void
    {
        $second = Company::withoutGlobalScopes()->create([
            'name' => 'Second Unit', 'code' => 'SU'.rand(10, 99),
            'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        $payload = $this->payload(['company_id' => $second->id]);

        $this->actingAs($this->admin)
            ->post(route('settings.users.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame($second->id, User::where('email', $payload['email'])->sole()->company_id);
    }

    public function test_a_unit_belonging_to_another_tenant_is_refused(): void
    {
        [, $foreignCompany] = $this->tenantWith('starter');
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $payload = $this->payload(['company_id' => $foreignCompany->id]);

        $this->actingAs($this->admin)
            ->post(route('settings.users.store'), $payload)
            ->assertSessionHasErrors('company_id');

        $this->assertNull(User::where('email', $payload['email'])->first());
    }

    /* ==================================================================
     * 2. THE ACCOUNT IS OFFERED ONLY WHAT IT MAY REACH
     * ================================================================== */

    public function test_every_my_work_tile_points_somewhere_the_server_allows(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->admin)->post(route('settings.users.store'), $payload);

        $fieldUser = User::where('email', $payload['email'])->sole();

        $this->actingAs($fieldUser)
            ->get(route('my-work'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $tiles = $page->toArray()['props']['tiles'];

                $this->assertNotEmpty($tiles);

                foreach ($tiles as $tile) {
                    $this->assertContains(
                        explode('.', $tile['route'])[0],
                        RestrictMyWorkUser::allowedPrefixes(),
                        "The tile \"{$tile['label']}\" points at a route this class is refused."
                    );
                }
            });
    }

    public function test_the_my_tasks_tile_does_not_point_at_work_center(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->admin)->post(route('settings.users.store'), $payload);

        $fieldUser = User::where('email', $payload['email'])->sole();

        // Work Center is the one route this tile used to carry, and this
        // class is refused it -- confirm the refusal is still real, so the
        // tile assertion below means something.
        $this->actingAs($fieldUser)->get(route('work-center.index'))->assertForbidden();

        $this->actingAs($fieldUser)
            ->get(route('my-work'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $routes = array_column($page->toArray()['props']['tiles'], 'route');

                $this->assertNotContains('work-center.index', $routes);
                $this->assertContains('tasks.index', $routes);
            });
    }

    public function test_the_allow_list_is_shared_with_the_browser_for_this_class_only(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->admin)->post(route('settings.users.store'), $payload);

        $fieldUser = User::where('email', $payload['email'])->sole();

        $this->actingAs($fieldUser)
            ->get(route('my-work'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'auth.user.allowed_route_prefixes',
                RestrictMyWorkUser::allowedPrefixes()
            ));

        // A Full User has no allow-list and must not be told it has one.
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.user.allowed_route_prefixes', null));
    }

    public function test_my_ptw_offers_no_create_button_without_ptw_access(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->admin)->post(route('settings.users.store'), $payload);

        $fieldUser = User::where('email', $payload['email'])->sole();

        $this->assertFalse($fieldUser->canCreatePtw());

        $this->actingAs($fieldUser)
            ->get(route('permits-to-work.mine'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canCreate', false));

        $this->actingAs($fieldUser)
            ->get(route('permits-to-work.create'))
            ->assertForbidden();
    }

    /* ==================================================================
     * 3. A REFUSAL SAYS WHAT IS WRONG, WHERE THE PERSON IS LOOKING
     * ================================================================== */

    public function test_an_exhausted_my_work_quota_is_reported_on_the_account_class_field(): void
    {
        $limit = $this->tenant->subscription->myWorkSeatLimit();

        $this->assertNotNull($limit, 'This test needs a plan with a finite My Work allowance.');

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($this->admin)
                ->post(route('settings.users.store'), $this->payload())
                ->assertSessionHasNoErrors();
        }

        $payload = $this->payload();

        $this->actingAs($this->admin)
            ->post(route('settings.users.store'), $payload)
            ->assertSessionHasErrors('user_type');

        $this->assertNull(User::where('email', $payload['email'])->first());
    }

    /* ==================================================================
     * 4. THE FULL USER PATH IS UNDISTURBED
     * ================================================================== */

    public function test_a_full_user_is_still_created_with_its_own_pool(): void
    {
        $payload = $this->payload(['user_type' => User::TYPE_FULL]);

        $this->actingAs($this->admin)
            ->post(route('settings.users.store'), $payload)
            ->assertSessionHasNoErrors();

        $created = User::where('email', $payload['email'])->sole();

        $this->assertSame(User::TYPE_FULL, $created->user_type);
        $this->assertFalse($created->isMyWorkUser());
        $this->assertSame($this->company->id, $created->company_id);
        $this->assertSame($this->tenant->id, $created->tenant_id);
    }

    public function test_an_account_class_is_still_optional_and_defaults_to_full(): void
    {
        $payload = $this->payload();
        unset($payload['user_type']);

        $this->actingAs($this->admin)
            ->post(route('settings.users.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(User::TYPE_FULL, User::where('email', $payload['email'])->sole()->user_type);
    }
}
