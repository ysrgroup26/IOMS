<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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
 * BULK DELETION, WHICH IS THE ONE OPERATION THAT MUST BE HARD TO DO BY
 * ACCIDENT.
 *
 * Removing 400 employees in one request is a genuine operational need --
 * a mis-imported file is the common case, and doing it one row at a time
 * is 400 confirmations. It is also the single most destructive thing the
 * Employees module can offer, so every rule below is a deliberate
 * refusal rather than a convenience:
 *
 *  1. ALL OR NOTHING. If any id in the request is not a deletable
 *     employee of the caller's own tenant, the whole request is refused
 *     and NOTHING is deleted. A partial delete would leave the customer
 *     unable to tell what went and what stayed -- and silently dropping
 *     a foreign id is how a cross-tenant probe gets a useful answer.
 *
 *  2. THE COUNT IS AGREED IN ADVANCE. The request carries the number of
 *     records the user believes they are deleting. If the server resolves
 *     a different number, it refuses. This is what stops a selection made
 *     on a stale page from deleting a set the user never saw.
 *
 *  3. THE WORD IS TYPED. A confirmation phrase has to be sent, and it is
 *     checked server-side. A click-through dialog alone is not consent
 *     for this.
 *
 *  4. SOFT DELETE, ALWAYS. The same reason `destroy()` has always soft
 *     deleted one employee: KPI history, man-hours, PPE issue records and
 *     approvals all reference the person. Nothing here destroys history.
 */
class EmployeeBulkDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private Company $dock;

    private User $admin;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $this->tenant = $this->makeTenant('Galangan');
        $this->yard = $this->makeCompany($this->tenant, 'Yard Utama', 'YU');
        $this->dock = $this->makeCompany($this->tenant, 'Dock Kedua', 'DK');

        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->department = Department::create(['company_id' => $this->yard->id, 'name' => 'HSE']);
        $this->admin = $this->user(User::ROLE_SUPER_ADMIN, $this->yard);
    }

    private function makeTenant(string $name): Tenant
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        return $tenant;
    }

    private function makeCompany(Tenant $tenant, string $name, string $code): Company
    {
        return Company::withoutGlobalScopes()->create([
            'name' => $name, 'code' => $code.'-'.uniqid(), 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);
    }

    private function user(string $role, Company $company, ?Tenant $tenant = null): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }

    private function employee(string $id, ?Company $company = null): Employee
    {
        return Employee::create([
            'employee_id' => $id,
            'full_name' => 'Budi '.$id,
            'company_id' => ($company ?? $this->yard)->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function deleteRequest(array $ids, array $overrides = []): array
    {
        return array_merge([
            'ids' => $ids,
            'expected_count' => count($ids),
            'confirmation' => 'HAPUS',
        ], $overrides);
    }

    // --- the happy path ---------------------------------------------------

    public function test_an_admin_removes_several_of_their_own_employees_at_once(): void
    {
        $employees = collect(['EMP-1', 'EMP-2', 'EMP-3'])->map(fn ($id) => $this->employee($id));
        $keep = $this->employee('EMP-KEEP');

        $response = $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest($employees->pluck('id')->all()));

        $response->assertRedirect(route('employees.index'));
        $response->assertSessionHas('success');

        foreach ($employees as $employee) {
            $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        }

        $this->assertNotSoftDeleted('employees', ['id' => $keep->id]);
    }

    public function test_the_records_are_soft_deleted_so_history_survives(): void
    {
        $employee = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]));

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertNotNull(
            Employee::withoutGlobalScopes()->withTrashed()->find($employee->id),
            'The row itself must still exist -- KPI and man-hour history point at it.'
        );
    }

    public function test_every_removal_is_recorded_individually_in_the_audit_trail(): void
    {
        $employees = collect(['EMP-1', 'EMP-2'])->map(fn ($id) => $this->employee($id));

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest($employees->pluck('id')->all()));

        foreach ($employees as $employee) {
            $this->assertDatabaseHas('activity_logs', [
                'subject_type' => Employee::class,
                'subject_id' => $employee->id,
                'action' => 'deleted',
            ]);
        }

        $this->assertTrue(
            ActivityLog::withoutGlobalScopes()
                ->where('action', 'deleted')
                ->where('description', 'like', '%2 employee%')
                ->exists(),
            'The bulk action itself should also be recorded as one summary line.'
        );
    }

    public function test_employees_across_several_operating_units_of_one_tenant_can_be_removed_together(): void
    {
        $dockDepartment = Department::create(['company_id' => $this->dock->id, 'name' => 'DOCK']);

        $yardEmployee = $this->employee('EMP-Y');
        $dockEmployee = Employee::create([
            'employee_id' => 'EMP-D', 'full_name' => 'Dock Person',
            'company_id' => $this->dock->id, 'department_id' => $dockDepartment->id, 'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$yardEmployee->id, $dockEmployee->id]))
            ->assertRedirect(route('employees.index'));

        $this->assertSoftDeleted('employees', ['id' => $yardEmployee->id]);
        $this->assertSoftDeleted('employees', ['id' => $dockEmployee->id]);
    }

    // --- the tenant boundary ---------------------------------------------

    public function test_another_tenants_employee_cannot_be_removed(): void
    {
        $otherTenant = $this->makeTenant('Pesaing');
        $otherCompany = $this->makeCompany($otherTenant, 'Yard Lain', 'YL');
        $otherDepartment = Department::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id, 'name' => 'HSE',
        ]);
        $foreign = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-X', 'full_name' => 'Orang Lain',
            'company_id' => $otherCompany->id, 'department_id' => $otherDepartment->id, 'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$foreign->id]))
            ->assertSessionHasErrors('ids');

        $this->assertNotSoftDeleted('employees', ['id' => $foreign->id]);
    }

    /**
     * The important half of rule 1: smuggling a foreign id in alongside
     * legitimate ones must not quietly delete the legitimate ones either.
     * Otherwise the response's count is itself a cross-tenant oracle.
     */
    public function test_one_foreign_id_refuses_the_whole_request(): void
    {
        $otherTenant = $this->makeTenant('Pesaing');
        $otherCompany = $this->makeCompany($otherTenant, 'Yard Lain', 'YL');
        $otherDepartment = Department::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id, 'name' => 'HSE',
        ]);
        $foreign = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-X', 'full_name' => 'Orang Lain',
            'company_id' => $otherCompany->id, 'department_id' => $otherDepartment->id, 'status' => 'active',
        ]);

        $mine = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$mine->id, $foreign->id]))
            ->assertSessionHasErrors();

        $this->assertNotSoftDeleted('employees', ['id' => $mine->id]);
        $this->assertNotSoftDeleted('employees', ['id' => $foreign->id]);
    }

    public function test_an_id_that_does_not_exist_refuses_the_whole_request(): void
    {
        $mine = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$mine->id, 999999]))
            ->assertSessionHasErrors();

        $this->assertNotSoftDeleted('employees', ['id' => $mine->id]);
    }

    public function test_an_already_deleted_employee_refuses_the_whole_request(): void
    {
        $gone = $this->employee('EMP-GONE');
        $gone->delete();
        $mine = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$mine->id, $gone->id]))
            ->assertSessionHasErrors();

        $this->assertNotSoftDeleted('employees', ['id' => $mine->id]);
    }

    // --- deliberate confirmation -----------------------------------------

    public function test_without_the_typed_confirmation_nothing_is_deleted(): void
    {
        $employee = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id], ['confirmation' => '']))
            ->assertSessionHasErrors('confirmation');

        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_a_wrong_confirmation_word_is_refused(): void
    {
        $employee = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id], ['confirmation' => 'ya']))
            ->assertSessionHasErrors('confirmation');

        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
    }

    /**
     * A stale selection: the user saw and agreed to two, the request
     * carries three. The server trusts the number the human agreed to,
     * not the list the page happened to submit.
     */
    public function test_a_count_the_user_did_not_agree_to_is_refused(): void
    {
        $employees = collect(['EMP-1', 'EMP-2', 'EMP-3'])->map(fn ($id) => $this->employee($id));

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest(
                $employees->pluck('id')->all(),
                ['expected_count' => 2],
            ))
            ->assertSessionHasErrors('expected_count');

        foreach ($employees as $employee) {
            $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
        }
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([]))
            ->assertSessionHasErrors();
    }

    public function test_a_duplicated_id_cannot_inflate_the_agreed_count(): void
    {
        $employee = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id, $employee->id]))
            ->assertSessionHasErrors();

        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_the_request_is_bounded(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest(range(1, 501)))
            ->assertSessionHasErrors();
    }

    // --- authorization ----------------------------------------------------

    public function test_a_manager_may_not_bulk_delete(): void
    {
        $employee = $this->employee('EMP-1');
        $manager = $this->user(User::ROLE_MANAGER, $this->yard);

        $this->actingAs($manager)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]))
            ->assertForbidden();

        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_a_warehouse_account_may_not_bulk_delete(): void
    {
        $employee = $this->employee('EMP-1');
        $warehouse = $this->user(User::ROLE_WAREHOUSE, $this->yard);

        $this->actingAs($warehouse)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]))
            ->assertForbidden();

        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $employee = $this->employee('EMP-1');

        $this->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]))
            ->assertRedirect(route('login'));

        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
    }

    /**
     * The route must not be shadowed by `employees/{employee}`. If the
     * parameterised route is declared first, `DELETE /employees/bulk`
     * resolves "bulk" as an employee id and 404s instead.
     */
    public function test_the_bulk_route_is_reachable_and_not_shadowed(): void
    {
        $this->assertSame(url('/employees/bulk'), route('employees.bulk-destroy'));

        $employee = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete('/employees/bulk', $this->deleteRequest([$employee->id]))
            ->assertRedirect(route('employees.index'));
    }
}
