<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DashboardStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.38.0. `DashboardStatsService::departmentDistribution()` used
 * `->withCount(...)->having('employees_count', '>', 0)` with no GROUP BY.
 * MySQL tolerates that; standard SQL does not, and SQLite threw
 * "HAVING clause on a non-aggregate query" -- so the query 500'd on any
 * non-MySQL database and, more importantly, could never be covered by a
 * test at all. That is exactly why the defect survived: it was
 * structurally untestable.
 *
 * These tests pin the BEHAVIOUR the fix has to preserve, not the SQL:
 * only departments with at least one active employee appear, the count
 * reflects active employees only, and the whole thing stays tenant-scoped.
 */
class DashboardStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'C', 'tenant_id' => $tenant->id]);

        $user = User::create([
            'name' => 'Admin', 'email' => 'd@example.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $this->be($user);
        app(\App\Support\CurrentTenant::class)->set($tenant);
    }

    private function department(string $name): Department
    {
        return Department::create(['name' => $name, 'company_id' => $this->company->id]);
    }

    private function employee(Department $d, string $status = 'active'): Employee
    {
        return Employee::create([
            'employee_id' => 'E'.uniqid(),
            'full_name' => 'Worker '.uniqid(),
            'company_id' => $this->company->id,
            'department_id' => $d->id,
            'status' => $status,
        ]);
    }

    public function test_department_distribution_runs_and_returns_expected_shape(): void
    {
        $produksi = $this->department('Produksi');
        $this->employee($produksi);
        $this->employee($produksi);

        $result = app(DashboardStatsService::class)->departmentDistribution();

        $this->assertSame([['label' => 'Produksi', 'value' => 2]], $result);
    }

    /** The whole point of the original HAVING: empty departments must not appear. */
    public function test_departments_with_no_active_employees_are_excluded(): void
    {
        $withPeople = $this->department('Maintenance');
        $this->employee($withPeople);

        $this->department('Kosong'); // no employees at all

        $inactiveOnly = $this->department('Hanya Nonaktif');
        $this->employee($inactiveOnly, 'inactive');

        $labels = array_column(app(DashboardStatsService::class)->departmentDistribution(), 'label');

        $this->assertContains('Maintenance', $labels);
        $this->assertNotContains('Kosong', $labels, 'A department with no employees must not appear.');
        $this->assertNotContains('Hanya Nonaktif', $labels, 'A department with only inactive employees must not appear.');
    }

    /** Inactive employees must not inflate the count of a department that does qualify. */
    public function test_count_reflects_active_employees_only(): void
    {
        $d = $this->department('HSE');
        $this->employee($d);
        $this->employee($d, 'inactive');
        $this->employee($d, 'resigned');

        $result = app(DashboardStatsService::class)->departmentDistribution();

        $this->assertSame([['label' => 'HSE', 'value' => 1]], $result);
    }

    public function test_distribution_excludes_other_tenants_departments(): void
    {
        $mine = $this->department('Mine');
        $this->employee($mine);

        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'OC', 'tenant_id' => $otherTenant->id]);
        $foreignDept = Department::create(['name' => 'Foreign', 'company_id' => $otherCompany->id]);
        Employee::create([
            'employee_id' => 'F1', 'full_name' => 'Foreign Worker',
            'company_id' => $otherCompany->id, 'department_id' => $foreignDept->id, 'status' => 'active',
        ]);

        $labels = array_column(app(DashboardStatsService::class)->departmentDistribution(), 'label');

        $this->assertContains('Mine', $labels);
        $this->assertNotContains('Foreign', $labels, "Another tenant's department must never appear.");
    }
}
