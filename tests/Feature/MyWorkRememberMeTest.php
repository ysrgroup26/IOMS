<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * v2.128.0 -- STAYING SIGNED IN, AND BEING ABLE TO STOP.
 *
 * A My Work account signs in with a badge number and an 8-character access
 * code (ADR 062), typed on a phone, in a yard, several times a shift. The
 * form now offers to remember the device.
 *
 * What these tests are really pinning is the pair of promises either side
 * of that: that CHECKING it uses Laravel's own remember token and nothing
 * home-made, and that UNCHECKING it, signing out, or being deactivated
 * still ends the session -- a convenience that cannot be revoked is not a
 * convenience.
 */
class MyWorkRememberMeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $worker;

    private Employee $employee;

    private const CODE = 'ABCD2345';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'rm-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit', 'code' => 'RMU', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->employee = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-RM-01', 'full_name' => 'Budi Santoso',
            'company_id' => $this->company->id, 'status' => 'active',
        ]);

        $this->worker = User::create([
            'name' => 'Budi Santoso', 'email' => null,
            // No role is ASKED for a My Work account (ADR 062); the column
            // keeps its database default, and `user_type` is what the
            // allow-list and every gate actually read.
            'password' => self::CODE,
            'user_type' => User::TYPE_MY_WORK,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'is_active' => true, 'email_verified_at' => now(),
        ]);

        // `employee_id` is deliberately NOT fillable (see User::$fillable),
        // so mass assignment would drop it SILENTLY -- the v2.74.0 pitfall
        // in docs/CONVENTIONS.md, which this fixture walked straight into.
        $this->worker->forceFill(['employee_id' => $this->employee->id])->save();
    }

    private function signIn(array $extra = [])
    {
        return $this->post('/my-work/login', array_merge([
            'identifier' => 'EMP-RM-01',
            'access_code' => self::CODE,
        ], $extra));
    }

    /** The framework's own recaller cookie for the web guard. */
    private function recallerName(): string
    {
        return 'remember_web_'.sha1(\Illuminate\Auth\SessionGuard::class);
    }

    public function test_checking_remember_me_issues_the_frameworks_remember_cookie(): void
    {
        $response = $this->signIn(['remember' => true]);

        $response->assertRedirect(route('my-work'));
        $response->assertCookie($this->recallerName());

        $this->assertNotNull($this->worker->fresh()->remember_token,
            'Remember Me must persist a remember token, which is what the cookie carries.');
    }

    public function test_the_remember_cookie_never_carries_the_access_code(): void
    {
        $response = $this->signIn(['remember' => true]);

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === $this->recallerName());

        $this->assertNotNull($cookie);
        $this->assertStringNotContainsString(self::CODE, (string) $cookie->getValue(),
            'The access code must never travel in a cookie.');
        $this->assertStringNotContainsString(
            (string) $this->worker->fresh()->password,
            (string) $cookie->getValue(),
            'Nor may the hash of it.',
        );
    }

    public function test_leaving_remember_me_unchecked_keeps_the_session_as_it_was(): void
    {
        $response = $this->signIn();

        $response->assertRedirect(route('my-work'));
        $response->assertCookieMissing($this->recallerName());
        $this->assertNull($this->worker->fresh()->remember_token);
    }

    /**
     * The point of the feature, in two halves.
     *
     * Recovering a session FROM the cookie is the framework's own job and
     * is covered above by asserting that the cookie is issued at all. What
     * is this release's own is where a recovered session LANDS: straight on
     * My Work, not on `/dashboard` -- a page this class is redirected away
     * from anyway -- and not on a sign-in form.
     */
    public function test_a_signed_in_worker_reaching_the_login_page_is_taken_to_my_work(): void
    {
        $this->actingAs($this->worker)
            ->get('/my-work/login')
            ->assertRedirect(route('my-work'));
    }

    public function test_a_worker_who_was_not_remembered_gets_the_form(): void
    {
        $this->signIn();
        $this->flushSession();
        Auth::logout();

        $this->get('/my-work/login')->assertOk();
    }

    public function test_signing_out_revokes_every_remembered_device(): void
    {
        $this->signIn(['remember' => true]);
        $before = $this->worker->fresh()->remember_token;
        $this->assertNotNull($before);

        $this->actingAs($this->worker->fresh())->post('/logout')
            // And back to the door they came in by: the platform form asks
            // for an email address a My Work account does not have.
            ->assertRedirect(route('my-work.login'));

        $after = $this->worker->fresh()->remember_token;

        $this->assertNotSame($before, $after,
            'Logging out must cycle the remember token, or a stolen cookie still signs in.');
    }

    public function test_a_full_account_still_signs_out_to_the_platform_form(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-rm-'.uniqid().'@rm.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'is_active' => true, 'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)->post('/logout')->assertRedirect('/login');
    }

    /**
     * A remembered session is re-checked on every request, so revoking
     * access does not have to wait for the next sign-in.
     */
    public function test_a_deactivated_account_cannot_use_a_remembered_session(): void
    {
        $this->signIn(['remember' => true]);
        $this->worker->forceFill(['is_active' => false])->save();

        $this->actingAs($this->worker->fresh())->get('/my-work')->assertRedirect();
    }
}
