<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\KpiCategory;
use App\Models\KpiRecord;
use App\Models\ManHourLog;
use App\Models\Package;
use App\Models\PpeType;
use App\Models\Project;
use App\Models\ProjectManpower;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * WHAT BULK DELETION MUST NOT TAKE WITH IT.
 *
 * `employees` is the target of a long row of `cascadeOnDelete` foreign
 * keys — KPI records, PPE issues, man-hour logs, project manpower,
 * competencies, shift assignments, internships. A HARD delete of one
 * employee would therefore destroy that person's entire operational
 * history, silently, as a side effect of the database doing exactly what
 * it was told.
 *
 * Bulk deletion is a SOFT delete, so none of those cascades ever fire.
 * That is the single most important property of the feature and nothing
 * pinned it: a later change from `delete()` to `forceDelete()` would look
 * like a one-word simplification and would quietly erase the KPI history
 * of everybody removed.
 *
 * This file also pins atomicity (a failure part-way through must leave
 * NOTHING deleted) and the full-authorized-scope case.
 */
class EmployeeBulkDeletionDependenciesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $admin;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->yard = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Utama', 'code' => 'YU', 'tenant_id' => $this->tenant->id, 'is_active' => true,
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

        $this->department = Department::create(['company_id' => $this->yard->id, 'name' => 'HSE']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    private function employee(string $id): Employee
    {
        return Employee::create([
            'employee_id' => $id,
            'full_name' => 'Budi '.$id,
            'company_id' => $this->yard->id,
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

    /**
     * An employee with a working life behind them: KPI, man-hours, a PPE
     * issue and a project assignment.
     *
     * @return array{0: Employee, 1: array<string, int>}
     */
    private function employeeWithHistory(string $id): array
    {
        $employee = $this->employee($id);

        $project = Project::create([
            'company_id' => $this->yard->id,
            'name' => 'Docking '.$id,
            'status' => 'ongoing',
            'start_date' => now()->subMonths(2)->toDateString(),
        ]);

        ProjectManpower::create([
            'project_id' => $project->id,
            'employee_id' => $employee->id,
            'assigned_date' => now()->subMonth()->toDateString(),
            'added_by' => $this->admin->id,
        ]);

        $category = KpiCategory::firstOrCreate(
            ['company_id' => $this->yard->id, 'code' => 'ATT'],
            ['name' => 'Attendance', 'short_label' => 'ATT']
        );

        KpiRecord::create([
            'employee_id' => $employee->id,
            'department_id' => $this->department->id,
            'kpi_category_id' => $category->id,
            'record_date' => now()->subDays(5)->toDateString(),
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'quantity' => 1,
            'created_by' => $this->admin->id,
        ]);

        ManHourLog::create([
            'company_id' => $this->yard->id,
            'employee_id' => $employee->id,
            'work_date' => now()->subDays(10)->toDateString(),
            'regular_hours' => 8,
            'overtime_hours' => 2,
        ]);

        $ppeType = PpeType::firstOrCreate(
            ['company_id' => $this->yard->id, 'name' => 'Helm'],
            ['lifespan_months' => 12]
        );

        EmployeePpe::create([
            'employee_id' => $employee->id,
            'ppe_type_id' => $ppeType->id,
            'issued_date' => now()->subMonths(3)->toDateString(),
            'expiry_date' => now()->addMonths(9)->toDateString(),
            'issued_by' => $this->admin->id,
        ]);

        return [$employee, [
            'project_manpower' => ProjectManpower::where('employee_id', $employee->id)->count(),
            'kpi_records' => KpiRecord::where('employee_id', $employee->id)->count(),
            'man_hour_logs' => ManHourLog::where('employee_id', $employee->id)->count(),
            'employee_ppe' => EmployeePpe::where('employee_id', $employee->id)->count(),
        ]];
    }

    /**
     * THE ONE THAT MATTERS MOST. Every dependent table carries
     * `cascadeOnDelete`, so this is the assertion that stands between a
     * bulk removal and a destroyed safety record.
     */
    public function test_a_bulk_removal_destroys_no_operational_history(): void
    {
        [$employee, $before] = $this->employeeWithHistory('EMP-1');

        $this->assertSame(['project_manpower' => 1, 'kpi_records' => 1, 'man_hour_logs' => 1, 'employee_ppe' => 1], $before);

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]))
            ->assertRedirect(route('employees.index'));

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);

        $this->assertSame(1, ProjectManpower::where('employee_id', $employee->id)->count(), 'Project manpower must survive.');
        $this->assertSame(1, KpiRecord::where('employee_id', $employee->id)->count(), 'KPI history must survive.');
        $this->assertSame(1, ManHourLog::where('employee_id', $employee->id)->count(), 'Man-hour logs must survive.');
        /*
         * PPE is asserted WITHOUT its global scope, deliberately, and the
         * difference is a real property of the system worth stating.
         *
         * `EmployeePpe` has no `company_id`; it reaches its owner through
         * `whereHas('employee')` (v2.62.0). `Employee` soft-deletes, so
         * `whereHas` stops matching the moment the person is removed and
         * every PPE issue of theirs becomes INVISIBLE to ordinary queries.
         *
         * The row is still there -- nothing cascaded, nothing was
         * destroyed, and a restore brings the whole issue history back
         * with the person. But "preserved" here means preserved in the
         * table, not still readable through the PPE module. That is
         * pre-existing behaviour of single deletion too, not something
         * bulk deletion introduced, and it is recorded in the knowledge
         * base rather than changed under this task.
         */
        $this->assertSame(
            1,
            EmployeePpe::withoutGlobalScopes()->where('employee_id', $employee->id)->count(),
            'PPE issue records must survive in the database.'
        );

        $this->assertSame(
            0,
            EmployeePpe::where('employee_id', $employee->id)->count(),
            'And they are hidden by the ownership scope while the employee is removed -- stated so a '
            .'future change to that scope has to confront this assertion rather than surprise somebody.'
        );
    }

    /** The employee row itself is still there to point at. */
    public function test_the_employee_row_survives_so_its_history_still_resolves(): void
    {
        [$employee] = $this->employeeWithHistory('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]));

        $kpi = KpiRecord::where('employee_id', $employee->id)->sole();

        $this->assertNotNull(
            Employee::withoutGlobalScopes()->withTrashed()->find($kpi->employee_id),
            'A KPI record whose employee cannot be resolved is an orphan in all but name.'
        );
    }

    /**
     * The soft-deleted employee still occupies its number, which is what
     * the importer's duplicate check already assumes (`withTrashed()`).
     */
    public function test_a_removed_employees_number_is_still_taken(): void
    {
        $employee = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]));

        $this->assertTrue(
            Employee::withoutGlobalScopes()->withTrashed()->where('employee_id', 'EMP-1')->exists()
        );
    }

    // --- atomicity --------------------------------------------------------

    /**
     * A failure part-way through must leave NOTHING deleted. Without the
     * transaction the first employees would be gone and the rest would
     * not, with no way for the customer to tell which.
     */
    public function test_a_failure_part_way_through_deletes_nothing(): void
    {
        $employees = collect(['EMP-1', 'EMP-2', 'EMP-3'])->map(fn ($id) => $this->employee($id));
        $doomed = $employees->last()->id;

        Employee::deleting(function (Employee $employee) use ($doomed) {
            if ($employee->id === $doomed) {
                throw new RuntimeException('Simulated failure on the last record.');
            }
        });

        try {
            $this->withoutExceptionHandling()
                ->actingAs($this->admin)
                ->delete(route('employees.bulk-destroy'), $this->deleteRequest($employees->pluck('id')->all()));

            $this->fail('The simulated failure should have propagated.');
        } catch (RuntimeException) {
            // expected
        }

        foreach ($employees as $employee) {
            $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
        }

        $this->assertSame(
            0,
            DB::table('activity_logs')->where('action', 'deleted')->count(),
            'The audit trail must roll back with the deletions it describes.'
        );
    }

    // --- the whole register ----------------------------------------------

    /**
     * "Full authorized scope": every employee this administrator may
     * delete, in one request.
     */
    public function test_the_entire_authorized_register_can_be_removed_in_one_request(): void
    {
        $ids = collect(range(1, 25))
            ->map(fn (int $i) => $this->employee(sprintf('EMP-%03d', $i))->id)
            ->all();

        $response = $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest($ids));

        $response->assertRedirect(route('employees.index'));
        $response->assertSessionHas('success');

        $this->assertSame(0, Employee::query()->whereIn('company_id', [$this->yard->id])->count());
        $this->assertSame(25, Employee::withoutGlobalScopes()->withTrashed()->count());

        // One audit line per person, plus one for the act itself.
        $this->assertSame(26, DB::table('activity_logs')->where('action', 'deleted')->count());
    }

    public function test_a_single_record_goes_through_the_same_bulk_path(): void
    {
        $employee = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    /**
     * The list the administrator comes back to must no longer show the
     * people they just removed — the observable outcome, not just the
     * column.
     */
    public function test_the_register_no_longer_lists_the_removed_employees(): void
    {
        $gone = $this->employee('EMP-GONE');
        $kept = $this->employee('EMP-KEPT');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$gone->id]));

        $response = $this->actingAs($this->admin)->get(route('employees.index'));
        $response->assertOk();

        $listed = collect($response->viewData('page')['props']['employees']['data'])->pluck('employee_id');

        $this->assertFalse($listed->contains('EMP-GONE'));
        $this->assertTrue($listed->contains('EMP-KEPT'));
    }
}
