<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\Package;
use App\Models\Position;
use App\Models\PpeType;
use App\Models\Project;
use App\Models\Scopes\CompanyOwnedScope;
use App\Models\Scopes\TenantScope;
use App\Models\Scopes\UserTenantScope;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.62.0 -- THE CROSS-TENANT READ INCIDENT, PINNED.
 *
 * A newly provisioned Starter tenant opened Reports and saw the
 * departments and employee names of an existing customer ("GAJ"). It was
 * READ leakage, not data duplication -- the new tenant owned no foreign
 * rows; it could simply see them.
 *
 * ROOT CAUSE. Milestone 2 put a global scope on `Company` and relied on
 * every downstream query voluntarily resolving
 * `Company::query()->pluck('id')` before touching a company-owned table.
 * That is a convention, and 72 query sites did not follow it. The one in
 * the screenshot:
 *
 *     Department::where('is_active', true)
 *         ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
 *
 * With no company filter chosen -- the default -- this returns every
 * department in the installation.
 *
 * These tests use the incident's own shape: an established tenant with
 * data, and a brand-new one with almost none. Every assertion is made at
 * the QUERY layer or through a real HTTP request, never against what the
 * frontend chose to render.
 */
class TenantDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $gaj;

    private Company $gajCompany;

    private User $gajAdmin;

    private Employee $gajEmployee;

    private Department $gajDepartment;

    private EmployeePpe $gajPpe;

    private Tenant $starter;

    private Company $starterCompany;

    private User $starterAdmin;

    private Employee $starterEmployee;

    private Department $starterDepartment;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->gaj, $this->gajCompany, $this->gajAdmin] = $this->tenant('GAJ', 'GAJ');
        [$this->starter, $this->starterCompany, $this->starterAdmin] = $this->tenant('New Starter Customer', 'NSC');

        [$this->gajDepartment, $this->gajEmployee, $this->gajPpe] = $this->operationalData($this->gajCompany, $this->gajAdmin, 'GAJ');
        [$this->starterDepartment, $this->starterEmployee] = $this->operationalData($this->starterCompany, $this->starterAdmin, 'NSC');
    }

    /** @return array{0: Tenant, 1: Company, 2: User} */
    private function tenant(string $name, string $code): array
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => strtolower($code).'-'.uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'code' => $code,
            'is_active' => true,
        ]);

        $admin = User::create([
            'name' => $name.' Admin',
            'email' => strtolower($code).'-'.uniqid().'@example.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        return [$tenant, $company, $admin];
    }

    /** @return array{0: Department, 1: Employee, 2: EmployeePpe} */
    private function operationalData(Company $company, User $issuer, string $prefix): array
    {
        $department = Department::create([
            'company_id' => $company->id,
            'name' => $prefix.' Engineering',
            'is_active' => true,
        ]);

        $position = Position::create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'name' => $prefix.' Welder',
            'is_active' => true,
        ]);

        $employee = Employee::create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_id' => $prefix.'-0001',
            'full_name' => $prefix.' Budi Santoso',
            'status' => 'active',
        ]);

        $ppeType = PpeType::firstOrCreate(['name' => 'Safety Helmet'], ['is_active' => true]);

        $ppe = EmployeePpe::create([
            'employee_id' => $employee->id,
            'ppe_type_id' => $ppeType->id,
            'issued_date' => now()->subMonth(),
            'status' => EmployeePpe::STATUS_IN_USE,
            'issued_by' => $issuer->id,
        ]);

        return [$department, $employee, $ppe];
    }

    /** Adopts a tenant the way ResolveTenant does for a real request. */
    private function asTenant(User $user): void
    {
        $this->be($user);
        app(CurrentTenant::class)->set($user->tenant);
    }

    /* ================================================================
     * 1-3. THE ESTABLISHED TENANT STILL SEES ITS OWN DATA
     *
     * Every one of these passed before the fix too. They are here so a
     * future tightening cannot "fix" isolation by hiding a customer's
     * own records from them -- the failure mode on the other side.
     * ================================================================ */

    public function test_1_gaj_user_can_see_gaj_employees(): void
    {
        $this->asTenant($this->gajAdmin);

        $employees = Employee::all();

        $this->assertCount(1, $employees);
        $this->assertSame($this->gajEmployee->id, $employees->first()->id);
    }

    public function test_2_gaj_user_can_see_gaj_departments(): void
    {
        $this->asTenant($this->gajAdmin);

        $this->assertSame([$this->gajDepartment->id], Department::pluck('id')->all());
    }

    public function test_3_gaj_user_can_see_gaj_ppe_records(): void
    {
        $this->asTenant($this->gajAdmin);

        $this->assertSame([$this->gajPpe->id], EmployeePpe::pluck('id')->all());
    }

    /* ================================================================
     * 4-6. THE NEW TENANT CANNOT SEE ANY OF IT
     *
     * These are the incident. Each one FAILED before this release.
     * ================================================================ */

    public function test_4_new_starter_tenant_cannot_see_gaj_employees(): void
    {
        $this->asTenant($this->starterAdmin);

        $names = Employee::pluck('full_name');

        $this->assertNotContains('GAJ Budi Santoso', $names, 'A new tenant can read another customer\'s employee names.');
        $this->assertSame([$this->starterEmployee->id], Employee::pluck('id')->all());
    }

    public function test_5_new_starter_tenant_cannot_see_gaj_departments(): void
    {
        $this->asTenant($this->starterAdmin);

        // The exact query from ReportController::index(), which is the
        // screenshot in the incident report.
        $departments = Department::where('is_active', true)->get(['id', 'name', 'company_id']);

        $this->assertSame([$this->starterDepartment->id], $departments->pluck('id')->all());
        $this->assertNotContains('GAJ Engineering', $departments->pluck('name'));
    }

    public function test_6_new_starter_tenant_cannot_see_gaj_ppe(): void
    {
        $this->asTenant($this->starterAdmin);

        $visible = EmployeePpe::with('employee')->get();

        // `employee_ppe` has no company_id of its own -- ownership runs
        // through the employee it was issued to -- so this is also the
        // test that a TRANSITIVELY owned table is isolated.
        $this->assertNotContains(
            $this->gajPpe->id,
            $visible->pluck('id'),
            'A PPE assignment belonging to another tenant\'s employee was visible.'
        );
        $this->assertTrue(
            $visible->every(fn ($row) => $row->employee->company_id === $this->starterCompany->id),
            'PPE assignments leaked across tenants.'
        );
    }

    /* ================================================================
     * 7. AND STILL SEES ITS OWN
     * ================================================================ */

    public function test_7_new_starter_tenant_can_see_its_own_records(): void
    {
        $this->asTenant($this->starterAdmin);

        $this->assertSame([$this->starterEmployee->id], Employee::pluck('id')->all());
        $this->assertSame([$this->starterDepartment->id], Department::pluck('id')->all());
        $this->assertSame([$this->starterCompany->id], Company::pluck('id')->all());
    }

    /* ================================================================
     * 8. PARAMETER TAMPERING
     * ================================================================ */

    public function test_8_changing_ids_or_parameters_cannot_bypass_isolation(): void
    {
        $this->asTenant($this->starterAdmin);

        // Naming the foreign company explicitly, the way a crafted query
        // string reaches ReportController / PpeController / EmployeeController.
        $this->assertCount(0, Employee::where('company_id', $this->gajCompany->id)->get());
        $this->assertCount(0, Department::where('company_id', $this->gajCompany->id)->get());

        // And through a real request with a tampered company_id filter.
        $this->get(route('reports.index', ['company_id' => $this->gajCompany->id]))
            ->assertOk();

        $departments = collect($this->get(route('reports.index', ['company_id' => $this->gajCompany->id]))
            ->viewData('page')['props']['departments']);

        $this->assertTrue(
            $departments->every(fn ($d) => $d['company_id'] === $this->starterCompany->id),
            'A tampered company_id filter returned another tenant\'s departments.'
        );
    }

    /* ================================================================
     * 9. REPORTS -- the surface the incident was reported from
     * ================================================================ */

    public function test_9_reports_cannot_cross_tenant_boundaries(): void
    {
        $this->asTenant($this->starterAdmin);

        $props = $this->get(route('reports.index'))->assertOk()->viewData('page')['props'];

        $names = collect($props['departments'])->pluck('name');
        $this->assertNotContains('GAJ Engineering', $names, 'The Reports page listed another tenant\'s departments.');

        $companies = collect($props['companies'])->pluck('id');
        $this->assertNotContains($this->gajCompany->id, $companies, 'The Reports page offered another tenant\'s Operating Unit as a filter.');
    }

    /* ================================================================
     * 10. SEARCH
     * ================================================================ */

    public function test_10_search_cannot_cross_tenant_boundaries(): void
    {
        $this->asTenant($this->starterAdmin);

        $this->assertCount(0, Employee::where('full_name', 'like', '%Budi%')
            ->where('company_id', '!=', $this->starterCompany->id)
            ->get());

        $hits = Employee::where('full_name', 'like', '%Budi%')->get();
        $this->assertCount(1, $hits);
        $this->assertSame($this->starterEmployee->id, $hits->first()->id);
    }

    /* ================================================================
     * 11. PAGINATION
     * ================================================================ */

    public function test_11_pagination_cannot_cross_tenant_boundaries(): void
    {
        $this->asTenant($this->starterAdmin);

        $page = Employee::paginate(50);

        $this->assertSame(1, $page->total(), 'Pagination counted another tenant\'s rows.');
        $this->assertSame([$this->starterEmployee->id], collect($page->items())->pluck('id')->all());
    }

    /* ================================================================
     * 12. DIRECT RECORD ACCESS (IDOR)
     * ================================================================ */

    public function test_12_direct_record_access_cannot_cross_tenant_boundaries(): void
    {
        $this->asTenant($this->starterAdmin);

        $this->assertNull(Employee::find($this->gajEmployee->id), 'Employee::find() reached another tenant\'s record.');
        $this->assertNull(Department::find($this->gajDepartment->id), 'Department::find() reached another tenant\'s record.');
        $this->assertNull(EmployeePpe::find($this->gajPpe->id), 'EmployeePpe::find() reached another tenant\'s record.');

        // And through the router, where the record is resolved by id
        // before any controller code runs.
        $this->get(route('employees.show', $this->gajEmployee->id))->assertNotFound();
        $this->get(route('ppe.employees.show', $this->gajEmployee->id))->assertNotFound();
    }

    /* ================================================================
     * 13. PROVISIONING PRODUCES A CLEAN TENANT
     * ================================================================ */

    public function test_13_self_service_provisioning_creates_a_clean_tenant(): void
    {
        // The forensic question the incident raised: did provisioning COPY
        // GAJ's data, or merely fail to hide it? Everything the new tenant
        // owns must have been created for it.
        $this->asTenant($this->starterAdmin);

        foreach ([Employee::all(), Department::all(), Position::all()] as $rows) {
            foreach ($rows as $row) {
                $this->assertSame(
                    $this->starterCompany->id,
                    $row->company_id,
                    'A provisioned tenant holds a record owned by another Operating Unit.'
                );
            }
        }

        // Nothing of GAJ's was reassigned in the process.
        $this->assertSame(
            $this->gajCompany->id,
            Employee::withoutGlobalScopes()->find($this->gajEmployee->id)->company_id
        );
    }

    /* ================================================================
     * 14. THE ESTABLISHED TENANT'S DATA IS UNTOUCHED
     * ================================================================ */

    public function test_14_existing_gaj_data_remains_unchanged(): void
    {
        $this->assertSame('GAJ Budi Santoso', Employee::withoutGlobalScopes()->find($this->gajEmployee->id)->full_name);
        $this->assertSame('GAJ Engineering', Department::withoutGlobalScopes()->find($this->gajDepartment->id)->name);
        $this->assertNotNull(EmployeePpe::withoutGlobalScopes()->find($this->gajPpe->id));

        $this->asTenant($this->gajAdmin);
        $this->assertCount(1, Employee::all());
        $this->assertCount(1, Department::all());
        $this->assertCount(1, EmployeePpe::all());
    }

    /* ================================================================
     * THE SAME MECHANISM, ACROSS THE REST OF THE PRODUCT
     *
     * The incident named Reports, Employees, Departments and PPE. The
     * defect was in the scoping mechanism those four happen to share, so
     * limiting the regression suite to them would leave 60 other tables
     * asserted by nothing.
     * ================================================================ */

    public function test_every_company_owned_model_carries_the_isolation_scope(): void
    {
        $missing = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $source = file_get_contents($file);

            if (! str_contains($source, "'company_id'")) {
                continue;
            }

            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $scopes = array_keys((new $class)->getGlobalScopes());
            $hasScope = in_array(CompanyOwnedScope::class, $scopes, true)
                || in_array('tenant', $scopes, true)
                || in_array('company', $scopes, true)
                || in_array(UserTenantScope::class, $scopes, true)
                || in_array(TenantScope::class, $scopes, true);

            if (! $hasScope) {
                $missing[] = basename($file, '.php');
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These models carry a company_id but no isolation scope, so any query that forgets to filter them returns every tenant's rows: ".implode(', ', $missing)
        );
    }

    /** A spread of other tenant-owned modules, proving the mechanism is shared rather than patched per page. */
    public function test_other_tenant_owned_modules_are_isolated_too(): void
    {
        $gajProject = Project::create([
            'company_id' => $this->gajCompany->id,
            'name' => 'GAJ Drydocking',
            'status' => 'ongoing',
        ]);

        $this->asTenant($this->starterAdmin);

        $this->assertCount(0, Project::all());
        $this->assertNull(Project::find($gajProject->id));
        $this->assertCount(0, Position::where('company_id', $this->gajCompany->id)->get());

        // Users are tenant-owned, and were being listed to everyone by the
        // task assignee pickers.
        $this->assertSame([$this->starterAdmin->id], User::pluck('id')->all());
    }

    /** The escape hatch is deliberate, explicit, and still available where it is genuinely needed. */
    public function test_isolation_can_only_be_bypassed_explicitly(): void
    {
        $this->asTenant($this->starterAdmin);

        $this->assertCount(1, Employee::all());
        $this->assertCount(2, Employee::withoutGlobalScope(CompanyOwnedScope::class)->get());
    }

    /**
     * The subscription that provisioning creates belongs to the tenant, so
     * an entitlement question asked in one tenant's context can never be
     * answered with another tenant's plan.
     */
    public function test_subscriptions_do_not_cross_tenants(): void
    {
        Subscription::create([
            'tenant_id' => $this->gaj->id,
            'package_id' => Package::where('slug', 'enterprise')->value('id'),
            'status' => 'active', 'type' => 'subscription', 'billing_cycle' => 'yearly',
            'starts_at' => now(), 'ends_at' => now()->addYear(),
        ]);

        $this->asTenant($this->starterAdmin);

        $this->assertNull(
            $this->starter->fresh()->subscription,
            'A tenant with no subscription resolved another tenant\'s.'
        );
        $this->assertNotNull($this->gaj->fresh()->subscription, 'The other tenant must keep its own.');
    }
}
