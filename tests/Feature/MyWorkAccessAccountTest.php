<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Package;
use App\Models\Position;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EmployeeEraser;
use App\Services\MyWorkAccessService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * v2.123.0 -- A MY WORK ACCOUNT IS AN EMPLOYEE'S LOGIN.
 *
 * Two flows that were one: Add Platform User invents an identity and gives
 * it a role; Add My Work User picks an identity that already exists in the
 * Employee Register and issues it an access code. What this pins is that
 * separating the FORMS separated nothing else -- the quota, the tenant
 * boundary, the account class and RestrictMyWorkUser all still apply, and
 * the Platform path is untouched.
 *
 * And the credential itself: a name finds an account, a code proves it.
 */
class MyWorkAccessAccountTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('my-work-login|emp-0001|127.0.0.1');
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        [$this->tenant, $this->company, $this->admin] = $this->tenantWith('business');
    }

    /** @return array{0: Tenant, 1: Company, 2: User} */
    private function tenantWith(string $packageSlug): array
    {
        $package = Package::where('slug', $packageSlug)->sole();

        $tenant = Tenant::create([
            'name' => 'Yard '.$packageSlug, 'slug' => 'yard-'.uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit '.$packageSlug, 'code' => strtoupper(substr($packageSlug, 0, 3)).rand(100, 999),
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(30),
        ]);

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));

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

    private function employee(string $number, string $name, ?Company $company = null): Employee
    {
        $company ??= $this->company;

        $department = Department::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Fabrication'],
        );

        $position = Position::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Welder', 'department_id' => $department->id],
        );

        return Employee::withoutGlobalScopes()->create([
            'employee_id' => $number,
            'nik' => '32'.random_int(10000000000000, 99999999999999),
            'full_name' => $name,
            'company_id' => $company->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'status' => 'active',
            'join_date' => '2026-01-10',
        ]);
    }

    /** The code the administrator was shown, for the one response it exists in. */
    private function createAccountFor(Employee $employee, array $extra = []): string
    {
        $response = $this->actingAs($this->admin)
            ->post(route('settings.my-work-users.store'), array_merge([
                'employee_id' => $employee->id,
            ], $extra));

        $response->assertSessionHasNoErrors();

        return $response->baseResponse->getSession()->get('my_work_access')['code'];
    }

    /* ==================================================================
     * 1. THE ACCOUNT IS THE EMPLOYEE'S, AND NOTHING IS RETYPED
     * ================================================================== */

    public function test_an_account_is_created_from_the_register_and_linked_to_the_employee(): void
    {
        $employee = $this->employee('EMP-0001', 'Budi Santoso');

        $code = $this->createAccountFor($employee);

        $account = User::where('employee_id', $employee->id)->sole();

        $this->assertSame(User::TYPE_MY_WORK, $account->user_type);
        $this->assertSame($this->tenant->id, $account->tenant_id);
        $this->assertSame($this->company->id, $account->company_id);
        $this->assertSame($employee->full_name, $account->name);
        $this->assertTrue($account->is_active);
        $this->assertNotNull($account->access_code_set_at);

        // The identity came from the register, not from the form.
        $this->assertSame($employee->id, $account->employee->id);

        // And the code is a credential, not a stored string.
        $this->assertNotSame($code, $account->password);
        $this->assertTrue(Hash::check(MyWorkAccessService::normalise($code), $account->password));
    }

    public function test_no_employee_is_ever_created_by_this_flow(): void
    {
        $employee = $this->employee('EMP-0002', 'Siti Rahayu');

        $before = Employee::withoutGlobalScopes()->count();

        $this->createAccountFor($employee);

        $this->assertSame($before, Employee::withoutGlobalScopes()->count());
    }

    public function test_an_email_address_is_not_required(): void
    {
        $employee = $this->employee('EMP-0003', 'Agus Priyono');

        $this->createAccountFor($employee);

        $this->assertNull(User::where('employee_id', $employee->id)->sole()->email);
    }

    public function test_an_email_address_is_kept_when_one_is_given(): void
    {
        $employee = $this->employee('EMP-0004', 'Dewi Lestari');

        $this->createAccountFor($employee, ['email' => 'dewi@yard.test']);

        $this->assertSame('dewi@yard.test', User::where('employee_id', $employee->id)->sole()->email);
    }

    public function test_an_employee_cannot_be_given_two_accounts(): void
    {
        $employee = $this->employee('EMP-0005', 'Rudi Hartono');

        $this->createAccountFor($employee);

        $this->actingAs($this->admin)
            ->post(route('settings.my-work-users.store'), ['employee_id' => $employee->id])
            ->assertSessionHasErrors('employee_id');

        $this->assertSame(1, User::withoutGlobalScopes()->where('employee_id', $employee->id)->count());
    }

    public function test_an_employee_of_another_tenant_is_refused(): void
    {
        [, $otherCompany] = $this->tenantWith('starter');
        $foreign = $this->employee('EMP-9001', 'Foreign Worker', $otherCompany);

        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->actingAs($this->admin)
            ->post(route('settings.my-work-users.store'), ['employee_id' => $foreign->id])
            ->assertSessionHasErrors('employee_id');

        $this->assertNull(User::withoutGlobalScopes()->where('employee_id', $foreign->id)->first());
    }

    /* ==================================================================
     * 2. SIGNING IN WITH A BADGE NUMBER OR A NAME, AND A CODE
     * ================================================================== */

    public function test_a_worker_signs_in_with_their_employee_id_and_lands_on_my_work(): void
    {
        $employee = $this->employee('EMP-0100', 'Joko Susilo');
        $code = $this->createAccountFor($employee);

        auth()->logout();
        session()->invalidate();

        $this->post(route('my-work.login.store'), [
            'identifier' => 'EMP-0100',
            'access_code' => $code,
        ])->assertRedirect(route('my-work'));

        $this->assertAuthenticatedAs(User::where('employee_id', $employee->id)->sole());
    }

    public function test_a_worker_may_sign_in_with_their_full_name(): void
    {
        $employee = $this->employee('EMP-0101', 'Rina Kartika');
        $code = $this->createAccountFor($employee);

        auth()->logout();
        session()->invalidate();

        $this->post(route('my-work.login.store'), [
            'identifier' => 'rina kartika',
            'access_code' => strtolower(str_replace('-', '', $code)),
        ])->assertRedirect(route('my-work'));

        $this->assertAuthenticatedAs(User::where('employee_id', $employee->id)->sole());
    }

    /**
     * THE NAME COLLISION. Two workers, one name, two codes -- each code
     * must reach its own account and neither may reach the other's.
     */
    public function test_two_workers_with_the_same_name_are_told_apart_by_their_codes(): void
    {
        $first = $this->employee('EMP-0200', 'Budi Santoso');
        $second = $this->employee('EMP-0201', 'Budi Santoso');

        $firstCode = $this->createAccountFor($first);
        $secondCode = $this->createAccountFor($second);

        $this->assertNotSame($firstCode, $secondCode);

        auth()->logout();
        session()->invalidate();

        $this->post(route('my-work.login.store'), [
            'identifier' => 'Budi Santoso',
            'access_code' => $secondCode,
        ])->assertRedirect(route('my-work'));

        $this->assertAuthenticatedAs(User::where('employee_id', $second->id)->sole());
    }

    public function test_an_employee_id_reaches_exactly_one_account_even_when_names_collide(): void
    {
        $first = $this->employee('EMP-0300', 'Budi Santoso');
        $second = $this->employee('EMP-0301', 'Budi Santoso');

        $firstCode = $this->createAccountFor($first);
        $this->createAccountFor($second);

        auth()->logout();
        session()->invalidate();

        $this->post(route('my-work.login.store'), [
            'identifier' => 'EMP-0300',
            'access_code' => $firstCode,
        ])->assertRedirect(route('my-work'));

        $this->assertAuthenticatedAs(User::where('employee_id', $first->id)->sole());
    }

    public function test_knowing_a_name_is_not_enough_to_reach_someone_elses_account(): void
    {
        $employee = $this->employee('EMP-0400', 'Hendra Wijaya');
        $this->createAccountFor($employee);

        auth()->logout();
        session()->invalidate();

        $this->post(route('my-work.login.store'), [
            'identifier' => 'Hendra Wijaya',
            'access_code' => 'WRONGCODE',
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_a_reset_code_replaces_the_one_the_worker_held(): void
    {
        $employee = $this->employee('EMP-0500', 'Tono Saputra');
        $oldCode = $this->createAccountFor($employee);

        $account = User::where('employee_id', $employee->id)->sole();

        $response = $this->actingAs($this->admin)
            ->post(route('settings.my-work-users.access-code', $account->id));

        $response->assertSessionHasNoErrors();
        $newCode = $response->baseResponse->getSession()->get('my_work_access')['code'];

        $this->assertNotSame($oldCode, $newCode);

        auth()->logout();
        session()->invalidate();

        $this->post(route('my-work.login.store'), [
            'identifier' => 'EMP-0500', 'access_code' => $oldCode,
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest();

        $this->post(route('my-work.login.store'), [
            'identifier' => 'EMP-0500', 'access_code' => $newCode,
        ])->assertRedirect(route('my-work'));
        $this->assertAuthenticated();
    }

    public function test_a_deactivated_account_cannot_sign_in_and_can_be_restored(): void
    {
        $employee = $this->employee('EMP-0600', 'Yanto Pribadi');
        $code = $this->createAccountFor($employee);
        $account = User::where('employee_id', $employee->id)->sole();

        $this->actingAs($this->admin)
            ->put(route('settings.my-work-users.status', $account->id), ['is_active' => false])
            ->assertSessionHasNoErrors();

        auth()->logout();
        session()->invalidate();

        $this->post(route('my-work.login.store'), [
            'identifier' => 'EMP-0600', 'access_code' => $code,
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest();

        $this->actingAs($this->admin)
            ->put(route('settings.my-work-users.status', $account->id), ['is_active' => true])
            ->assertSessionHasNoErrors();

        auth()->logout();
        session()->invalidate();

        $this->post(route('my-work.login.store'), [
            'identifier' => 'EMP-0600', 'access_code' => $code,
        ])->assertRedirect(route('my-work'));
        $this->assertAuthenticated();
    }

    public function test_repeated_wrong_codes_are_rate_limited(): void
    {
        $employee = $this->employee('EMP-0700', 'Bambang Irawan');
        $this->createAccountFor($employee);

        auth()->logout();
        session()->invalidate();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('my-work.login.store'), [
                'identifier' => 'EMP-0700', 'access_code' => 'WRONG'.$attempt,
            ])->assertSessionHasErrors('identifier');
        }

        $response = $this->post(route('my-work.login.store'), [
            'identifier' => 'EMP-0700', 'access_code' => 'WRONG-AGAIN',
        ]);

        $response->assertSessionHasErrors('identifier');
        $this->assertStringContainsString(
            'Terlalu banyak percobaan',
            session('errors')->first('identifier'),
        );

        RateLimiter::clear('my-work-login|emp-0700|127.0.0.1');
    }

    /* ==================================================================
     * 3. THE RULES THAT MUST SURVIVE THE SEPARATION
     * ================================================================== */

    public function test_the_my_work_quota_still_applies_to_this_flow(): void
    {
        $limit = $this->tenant->subscription->myWorkSeatLimit();
        $this->assertNotNull($limit, 'This test needs a plan with a finite My Work allowance.');

        for ($i = 0; $i < $limit; $i++) {
            $this->createAccountFor($this->employee('EMP-10'.$i, 'Worker '.$i));
        }

        $overflow = $this->employee('EMP-1999', 'One Too Many');

        $this->actingAs($this->admin)
            ->post(route('settings.my-work-users.store'), ['employee_id' => $overflow->id])
            ->assertSessionHasErrors('employee_id');

        $this->assertNull(User::withoutGlobalScopes()->where('employee_id', $overflow->id)->first());
    }

    public function test_reactivating_at_a_full_quota_is_refused(): void
    {
        $limit = $this->tenant->subscription->myWorkSeatLimit();

        $first = $this->employee('EMP-2000', 'First Worker');
        $this->createAccountFor($first);
        $account = User::where('employee_id', $first->id)->sole();

        $this->actingAs($this->admin)
            ->put(route('settings.my-work-users.status', $account->id), ['is_active' => false]);

        // The freed seat is taken by somebody else...
        for ($i = 0; $i < $limit; $i++) {
            $this->createAccountFor($this->employee('EMP-20'.$i.'X', 'Filler '.$i));
        }

        // ...so restoring the first account would exceed the allowance.
        $this->actingAs($this->admin)
            ->put(route('settings.my-work-users.status', $account->id), ['is_active' => true])
            ->assertSessionHasErrors('is_active');

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_the_account_is_still_bound_by_the_my_work_allow_list(): void
    {
        $employee = $this->employee('EMP-3000', 'Field Worker');
        $this->createAccountFor($employee);

        $account = User::where('employee_id', $employee->id)->sole();

        $this->actingAs($account)->get(route('my-work'))->assertOk();

        foreach (['settings.index', 'employees.index', 'work-center.index'] as $routeName) {
            $this->actingAs($account)->get(route($routeName))->assertForbidden();
        }
    }

    public function test_only_an_administrator_of_this_tenant_may_manage_the_account(): void
    {
        $employee = $this->employee('EMP-4000', 'Managed Worker');
        $this->createAccountFor($employee);
        $account = User::where('employee_id', $employee->id)->sole();

        [, , $otherAdmin] = $this->tenantWith('starter');

        $this->actingAs($otherAdmin)
            ->post(route('settings.my-work-users.access-code', $account->id))
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->put(route('settings.my-work-users.status', $account->id), ['is_active' => false])
            ->assertNotFound();

        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_erasing_the_employee_deactivates_the_login_and_keeps_the_record(): void
    {
        $employee = $this->employee('EMP-5000', 'Departing Worker');
        $this->createAccountFor($employee);
        $account = User::where('employee_id', $employee->id)->sole();

        app(EmployeeEraser::class)->erase($employee);

        $account->refresh();

        $this->assertNull($account->employee_id, 'The pointer is cleared with the person.');
        $this->assertFalse($account->is_active, 'A login issued to somebody who no longer exists is not left active.');
        $this->assertDatabaseHas('users', ['id' => $account->id]);
    }

    public function test_the_workers_own_account_page_names_what_they_sign_in_with(): void
    {
        $employee = $this->employee('EMP-6000', 'Self Service Worker');
        $this->createAccountFor($employee);
        $account = User::where('employee_id', $employee->id)->sole();

        $this->actingAs($account)
            ->get(route('account.overview'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('account.uses_access_code', true)
                ->where('account.email', null)
                ->where('account.employee_number', 'EMP-6000')
                /*
                 * A My Work account has no workspace to focus -- the
                 * allow-list refuses every one of them -- so the control
                 * is not offered. It used to be, because the options came
                 * from the TENANT's grants.
                 */
                ->where('workspaceFocus.options', []));
    }

    public function test_a_platform_user_is_still_told_it_signs_in_with_a_password(): void
    {
        $this->actingAs($this->admin)
            ->get(route('account.overview'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('account.uses_access_code', false));
    }

    /* ==================================================================
     * 4. THE PLATFORM PATH IS UNTOUCHED
     * ================================================================== */

    public function test_a_platform_user_is_still_created_and_still_signs_in_with_an_email(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.users.store'), [
                'name' => 'Office Person',
                'email' => 'office@yard.test',
                'password' => 'secret-pass-2',
                'role' => 'hrd',
            ])
            ->assertSessionHasNoErrors();

        $created = User::where('email', 'office@yard.test')->sole();

        $this->assertSame(User::TYPE_FULL, $created->user_type);
        $this->assertNull($created->employee_id);

        auth()->logout();
        session()->invalidate();

        $this->post(route('login'), ['email' => 'office@yard.test', 'password' => 'secret-pass-2'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($created);
    }

    public function test_a_platform_user_cannot_sign_in_through_the_my_work_form(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.users.store'), [
                'name' => 'Office Person Two',
                'email' => 'office2@yard.test',
                'password' => 'secret-pass-2',
                'role' => 'hrd',
            ]);

        auth()->logout();
        session()->invalidate();

        $this->post(route('my-work.login.store'), [
            'identifier' => 'Office Person Two',
            'access_code' => 'secret-pass-2',
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }
}
