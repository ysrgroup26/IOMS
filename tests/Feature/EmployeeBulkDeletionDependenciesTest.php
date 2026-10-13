<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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
 * WHAT PERMANENT DELETION TAKES, AND WHAT IT MUST NOT.
 *
 * This file used to assert the opposite of most of what it asserts now,
 * and the reason is worth keeping in front of whoever reads it next.
 *
 * `employees` is the target of twenty-four foreign keys. Deletion was
 * therefore a SOFT delete, so none of the cascades could fire and no
 * safety record could be destroyed by an HR action. What that produced
 * was a product that could not recover from a bad import: a workforce the
 * customer had deliberately deleted still held its employee numbers
 * against unique indexes that know nothing about `deleted_at`, so the
 * corrected file was refused row by row as duplicates of employees the
 * Employees page said did not exist.
 *
 * The owner's decision is that deletion is PERMANENT. So the property to
 * pin is no longer "nothing is destroyed" -- it is that the RIGHT things
 * are, and only those:
 *
 *  1. DEPENDENTS GO. Records that exist only to describe the person --
 *     KPI entries, man-hours, PPE issues, competencies, rosters, shifts,
 *     leave, project manpower, permit personnel, TBM attendance -- are
 *     removed with them. A KPI score belonging to nobody is not a record
 *     of anything, and leaving one is strictly worse than deleting it:
 *     `EmployeePpe` and `KpiRecord` reach their owner THROUGH the
 *     employee, so an orphan is invisible to every tenant while still
 *     being counted by any raw total.
 *
 *  2. DOCUMENTS STAY, AND KEEP THE NAME. A permit, an incident report, a
 *     handover record is a document in its own right. It survives with
 *     its pointer cleared, and where it PRINTS the person's name that
 *     name is written into it as text first.
 *
 *  3. THE ORGANISATION IS NOT TOUCHED. Departments, positions, projects,
 *     shifts, PPE types, KPI categories, companies: master data that
 *     existed before this person was hired and outlives them. The
 *     reported defect was partly about this -- deleting employees left
 *     the departments and positions behind, which is correct, and no
 *     attempt to fix the import may "clean them up".
 *
 *  4. THE NUMBER COMES BACK. The whole point: the employee number and
 *     NIK are free again and the file can be imported.
 *
 * It also pins atomicity -- a failure anywhere in the erasure must leave
 * NOTHING deleted -- and the full-authorized-scope case. `EmployeeEraser`
 * is the engine under all of it, and `EmployeeErasureCoverageTest` makes
 * sure it knows about every reference the schema actually has.
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
     * RULE 1. The records that only describe this person go with them,
     * and the assertion is made WITHOUT global scopes on purpose: the
     * question is whether the row is in the table, not whether a scope
     * happens to be hiding it. An orphan that a scope hides is the worst
     * of the available outcomes, because nothing in the product can find
     * it and every raw total still includes it.
     */
    public function test_a_bulk_removal_takes_the_records_that_only_describe_those_people(): void
    {
        [$employee, $before] = $this->employeeWithHistory('EMP-1');

        $this->assertSame(['project_manpower' => 1, 'kpi_records' => 1, 'man_hour_logs' => 1, 'employee_ppe' => 1], $before);

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]))
            ->assertRedirect(route('employees.index'));

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);

        foreach (['project_manpower', 'kpi_records', 'man_hour_logs', 'employee_ppe'] as $table) {
            $this->assertSame(
                0,
                DB::table($table)->where('employee_id', $employee->id)->count(),
                "{$table} must not keep a row pointing at an employee who no longer exists."
            );
        }
    }

    /**
     * RULE 1, stated as the thing that must never be true afterwards.
     *
     * Every one of these tables reaches its owning tenant THROUGH the
     * employee (`BelongsToCompanyThrough`), so a surviving row with a
     * dangling `employee_id` is unreachable for every tenant at once.
     */
    public function test_no_orphan_is_left_pointing_at_a_person_who_is_gone(): void
    {
        [$employee] = $this->employeeWithHistory('EMP-1');
        [$kept] = $this->employeeWithHistory('EMP-KEPT');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]));

        $liveIds = DB::table('employees')->pluck('id')->all();

        foreach (array_keys(\App\Services\EmployeeEraser::DEPENDENTS) as $table) {
            $dangling = DB::table($table)->whereNotIn('employee_id', $liveIds)->count();

            $this->assertSame(0, $dangling, "{$table} holds a row whose employee is gone.");
        }

        // And the employee who was NOT selected keeps everything.
        $this->assertSame(1, DB::table('kpi_records')->where('employee_id', $kept->id)->count());
        $this->assertSame(1, DB::table('employee_ppe')->where('employee_id', $kept->id)->count());
    }

    /**
     * RULE 4, and the reason this release exists. The number and the NIK
     * are free, and the proof is that the same ones can be used again --
     * which is exactly what a corrected import does.
     */
    public function test_a_removed_employees_number_and_nik_are_free_again(): void
    {
        $employee = $this->employee('EMP-1');
        $employee->forceFill(['nik' => '3201010101900001'])->save();

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]));

        $this->assertFalse(
            Employee::withoutGlobalScopes()->where('employee_id', 'EMP-1')->exists(),
            'Nothing may still hold the number.'
        );

        $reimported = Employee::create([
            'employee_id' => 'EMP-1',
            'nik' => '3201010101900001',
            'full_name' => 'Orang Baru',
            'company_id' => $this->yard->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $this->assertTrue($reimported->exists);
        $this->assertNotSame($employee->id, $reimported->id, 'A fresh record, not the old row brought back.');
    }

    /**
     * RULE 3. The reported defect included this: deleting employees left
     * the departments, positions and operating units behind, which is
     * CORRECT, and no fix to the import may tidy them away.
     */
    public function test_the_organisations_own_master_data_is_never_touched(): void
    {
        [$employee] = $this->employeeWithHistory('EMP-1');

        $before = [
            'departments' => DB::table('departments')->count(),
            'positions' => DB::table('positions')->count(),
            'projects' => DB::table('projects')->count(),
            'companies' => DB::table('companies')->count(),
            'kpi_categories' => DB::table('kpi_categories')->count(),
            'ppe_types' => DB::table('ppe_types')->count(),
        ];

        $this->assertGreaterThan(0, $before['departments']);
        $this->assertGreaterThan(0, $before['projects']);

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]));

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} must be untouched by an employee deletion.");
        }
    }

    // --- atomicity --------------------------------------------------------

    /**
     * A failure ANYWHERE in the erasure must leave NOTHING deleted.
     *
     * The failure is injected on the summary audit row, which the
     * controller writes LAST -- after the dependents are deleted, the
     * references are cleared and the employees themselves are gone. So
     * this does not only prove the three deletions roll back together: it
     * proves the whole erasure does, across all the tables it reaches.
     *
     * TWO TRAPS, both of which this test fell into first:
     *
     * 1. The previous version threw from `Employee::deleting`, which
     *    worked while the controller deleted models one by one.
     *    `EmployeeEraser` deletes through the QUERY BUILDER, where
     *    Eloquent model events do not fire at all -- so the hook was
     *    never called and no failure was ever simulated.
     *
     * 2. `$this->fail()` inside a `try` whose `catch` names
     *    `RuntimeException` is swallowed by that catch: PHPUnit's
     *    `AssertionFailedError` IS a `RuntimeException`. The test then
     *    reports the symptom (rows missing) and hides the cause (no
     *    exception was raised). The flag below keeps the two apart.
     */
    public function test_a_failure_part_way_through_deletes_nothing(): void
    {
        [$first] = $this->employeeWithHistory('EMP-1');
        $employees = collect([$first])
            ->concat(collect(['EMP-2', 'EMP-3'])->map(fn ($id) => $this->employee($id)));

        ActivityLog::creating(function (ActivityLog $log) {
            if (str_contains((string) $log->description, 'in one bulk deletion')) {
                throw new RuntimeException('Simulated failure after the rows were erased.');
            }
        });

        $propagated = false;

        try {
            $this->withoutExceptionHandling()
                ->actingAs($this->admin)
                ->delete(route('employees.bulk-destroy'), $this->deleteRequest($employees->pluck('id')->all()));
        } catch (RuntimeException $e) {
            $propagated = str_contains($e->getMessage(), 'Simulated failure');
        }

        $this->assertTrue($propagated, 'The simulated failure should have propagated out of the request.');

        foreach ($employees as $employee) {
            $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        }

        $this->assertSame(1, DB::table('kpi_records')->where('employee_id', $first->id)->count(), 'The dependent records must come back too.');
        $this->assertSame(1, DB::table('employee_ppe')->where('employee_id', $first->id)->count());
        $this->assertSame(1, DB::table('man_hour_logs')->where('employee_id', $first->id)->count());

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
        $this->assertSame(0, Employee::withoutGlobalScopes()->count(), 'Permanent means the rows are not there at all.');

        // One audit line per person, plus one for the act itself.
        $this->assertSame(26, DB::table('activity_logs')->where('action', 'deleted')->count());
    }

    public function test_a_single_record_goes_through_the_same_bulk_path(): void
    {
        $employee = $this->employee('EMP-1');

        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), $this->deleteRequest([$employee->id]))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
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
