<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ManHourLog;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.77.0 -- MAN-HOURS ARE OPERATIONAL DATA, SO THEIR ARITHMETIC IS PINNED.
 *
 * The model under test (docs/MODULES.md § Man-Hour):
 *
 *   one row    = one person, one work date
 *   man-hours  = SUM(regular + overtime) over the period
 *              = headcount × hours each person actually worked
 *
 * Entitlement enforcement is switched off here on purpose: what these tests
 * check is the arithmetic and the tenant boundary, not which plan sells the
 * module. The subscription lifecycle has its own tests.
 */
class ManHourTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private Department $department;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['saas.enforce_entitlement' => false]);

        $this->tenant = Tenant::create(['name' => 'Yard', 'slug' => 'yard']);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Yard Co', 'tenant_id' => $this->tenant->id]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'mh@yard.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        app(CurrentTenant::class)->set($this->tenant);
        $this->department = Department::create(['name' => 'Produksi', 'company_id' => $this->company->id]);
    }

    private function employee(string $name): Employee
    {
        return Employee::create([
            'employee_id' => 'E'.uniqid(), 'full_name' => $name,
            'company_id' => $this->company->id, 'department_id' => $this->department->id, 'status' => 'active',
        ]);
    }

    private function log(Employee $employee, string $date, float $regular, float $overtime = 0, ?Project $project = null): ManHourLog
    {
        return ManHourLog::create([
            'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'project_id' => $project?->id,
            'work_date' => $date, 'regular_hours' => $regular, 'overtime_hours' => $overtime,
        ]);
    }

    private function summary(array $query = []): array
    {
        return $this->actingAs($this->admin)
            ->get(route('man-hour.index', array_merge(['from' => '2026-09-01', 'to' => '2026-09-30'], $query)))
            ->assertOk()
            ->viewData('page')['props']['summary'];
    }

    /* ------------------------------------------------------------------ */
    /* The calculation                                                     */
    /* ------------------------------------------------------------------ */

    /** Three people, two days: man-hours are the sum of what each actually worked. */
    public function test_man_hours_are_headcount_times_hours_actually_worked(): void
    {
        [$a, $b, $c] = [$this->employee('A'), $this->employee('B'), $this->employee('C')];

        $this->log($a, '2026-09-01', 8, 2);   // 10
        $this->log($b, '2026-09-01', 8);      //  8
        $this->log($c, '2026-09-01', 8, 1.5); //  9.5
        $this->log($a, '2026-09-02', 8);      //  8
        $this->log($b, '2026-09-02', 7.5);    //  7.5

        $s = $this->summary();

        $this->assertEquals(43.0, $s['total_hours']);
        $this->assertEquals(39.5, $s['regular_hours']);
        $this->assertEquals(3.5, $s['overtime_hours']);
        $this->assertEqualsWithDelta($s['regular_hours'] + $s['overtime_hours'], $s['total_hours'], 0.001);
        $this->assertSame(3, $s['headcount']);
        $this->assertSame(2, $s['work_days']);
        $this->assertSame(5, $s['record_count']);
    }

    /**
     * THE BUG THIS RELEASE FIXED. The headline total was summed from the 30
     * rows on the current page, so a period with more records reported a
     * wrong figure -- and a different wrong figure on page 2.
     */
    public function test_the_total_covers_the_whole_period_not_one_page(): void
    {
        $people = collect(range(1, 40))->map(fn ($i) => $this->employee("Worker {$i}"));
        $people->each(fn ($e) => $this->log($e, '2026-09-10', 8));

        $page1 = $this->summary();
        $page2 = $this->summary(['page' => 2]);

        $this->assertEquals(320.0, $page1['total_hours'], '40 people × 8h, not 30 × 8h.');
        $this->assertSame(40, $page1['headcount']);
        $this->assertSame($page1, $page2, 'The period total must not depend on which page of the table is open.');
    }

    public function test_the_period_bounds_are_inclusive_and_exclude_everything_outside(): void
    {
        $e = $this->employee('A');
        $this->log($e, '2026-08-31', 8); // before
        $this->log($e, '2026-09-01', 8); // first day
        $this->log($e, '2026-09-30', 8); // last day
        $this->log($e, '2026-10-01', 8); // after

        $this->assertEquals(16.0, $this->summary()['total_hours']);
    }

    public function test_an_empty_period_is_zero_with_no_breakdown(): void
    {
        $s = $this->summary();

        $this->assertEquals(0.0, $s['total_hours']);
        $this->assertSame(0, $s['headcount']);
        $this->assertSame([], $s['by_project']);
    }

    /** The breakdown always adds up to the total, including hours with no project. */
    public function test_the_project_breakdown_adds_up_to_the_total(): void
    {
        $hull = Project::create(['name' => 'Hull Block 12', 'company_id' => $this->company->id]);
        [$a, $b] = [$this->employee('A'), $this->employee('B')];

        $this->log($a, '2026-09-01', 8, 2, $hull);
        $this->log($b, '2026-09-01', 8, 0, $hull);
        $this->log($a, '2026-09-02', 6);

        $s = $this->summary();
        $byProject = collect($s['by_project'])->keyBy(fn ($r) => $r['name'] ?? 'none');

        $this->assertEquals(18.0, $byProject['Hull Block 12']['hours']);
        $this->assertSame(2, $byProject['Hull Block 12']['headcount']);
        $this->assertEquals(6.0, $byProject['none']['hours']);
        $this->assertEqualsWithDelta($s['total_hours'], collect($s['by_project'])->sum('hours'), 0.001);

        // And filtering to a project narrows every figure.
        $this->assertEquals(18.0, $this->summary(['project_id' => $hull->id])['total_hours']);
        $this->assertEquals(6.0, $this->summary(['project_id' => 'none'])['total_hours']);
    }

    /* ------------------------------------------------------------------ */
    /* Input rules                                                         */
    /* ------------------------------------------------------------------ */

    public function test_a_day_cannot_hold_more_than_24_hours(): void
    {
        $e = $this->employee('A');

        $this->actingAs($this->admin)->post(route('man-hour.store'), [
            'employee_id' => $e->id, 'work_date' => now()->toDateString(),
            'regular_hours' => 20, 'overtime_hours' => 8,
        ])->assertSessionHasErrors('overtime_hours');

        $this->actingAs($this->admin)->post(route('man-hour.store'), [
            'employee_id' => $e->id, 'work_date' => now()->toDateString(),
            'regular_hours' => 0, 'overtime_hours' => 0,
        ])->assertSessionHasErrors('regular_hours');

        $this->assertSame(0, ManHourLog::count());
    }

    /** One person cannot work one day twice: saving again replaces, and says so. */
    public function test_saving_the_same_person_and_date_replaces_rather_than_duplicates(): void
    {
        $e = $this->employee('A');
        $payload = ['employee_id' => $e->id, 'work_date' => now()->toDateString(), 'regular_hours' => 8, 'overtime_hours' => 0];

        $this->actingAs($this->admin)->post(route('man-hour.store'), $payload)
            ->assertSessionHas('success', 'Man-hour record saved.');

        $this->actingAs($this->admin)->post(route('man-hour.store'), [...$payload, 'overtime_hours' => 3])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'replaced'));

        $this->assertSame(1, ManHourLog::count());
        $this->assertEquals(11.0, ManHourLog::first()->total_hours);
        $this->assertSame($this->admin->id, ManHourLog::first()->recorded_by, 'Who recorded it is kept for audit.');
    }

    public function test_a_reversed_period_is_rejected_rather_than_read_as_zero_hours(): void
    {
        $this->actingAs($this->admin)
            ->get(route('man-hour.index', ['from' => '2026-09-30', 'to' => '2026-09-01']))
            ->assertSessionHasErrors('to');
    }

    /* ------------------------------------------------------------------ */
    /* Tenant boundary                                                     */
    /* ------------------------------------------------------------------ */

    public function test_another_tenants_hours_never_reach_the_totals_or_the_form(): void
    {
        $this->log($this->employee('Mine'), '2026-09-05', 8);

        // A second tenant with its own hours.
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Other Co', 'tenant_id' => $other->id]);
        $otherDept = Department::withoutGlobalScopes()->create(['name' => 'X', 'company_id' => $otherCompany->id]);
        $foreign = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'F1', 'full_name' => 'Foreign', 'company_id' => $otherCompany->id,
            'department_id' => $otherDept->id, 'status' => 'active',
        ]);
        ManHourLog::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id, 'employee_id' => $foreign->id,
            'work_date' => '2026-09-05', 'regular_hours' => 12, 'overtime_hours' => 0,
        ]);

        // Even asking for the other tenant's company by id does not reach it.
        $this->assertEquals(8.0, $this->summary()['total_hours']);
        $this->assertEquals(8.0, $this->summary(['company_id' => $otherCompany->id])['total_hours']);

        // Nor can this tenant record hours against the foreign employee.
        $this->actingAs($this->admin)->post(route('man-hour.store'), [
            'employee_id' => $foreign->id, 'work_date' => now()->toDateString(),
            'regular_hours' => 8, 'overtime_hours' => 0,
        ])->assertSessionHasErrors('employee_id');
    }
}
