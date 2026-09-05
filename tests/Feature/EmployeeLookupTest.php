<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.38.0 (Master Audit) -- shared employee lookup.
 *
 * This endpoint exists to stop seven controllers shipping the tenant's
 * entire employee directory into page payloads. Because it is a
 * directory over personal data, its tenant boundary and its result cap
 * are the two properties that must never regress -- both are pinned here.
 */
class EmployeeLookupTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b']);

        $this->companyA = Company::withoutGlobalScopes()->create(['name' => 'CA', 'tenant_id' => $tenantA->id]);
        $this->companyB = Company::withoutGlobalScopes()->create(['name' => 'CB', 'tenant_id' => $tenantB->id]);

        $this->userA = User::create([
            'name' => 'Admin A', 'email' => 'la@example.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenantA->id, 'is_active' => true,
        ]);
    }

    private function employee(Company $c, string $name, string $code, ?int $deptId = null, string $status = 'active'): Employee
    {
        return Employee::create([
            'employee_id' => $code, 'full_name' => $name,
            'company_id' => $c->id, 'department_id' => $deptId, 'status' => $status,
        ]);
    }

    public function test_lookup_never_returns_another_tenants_employees(): void
    {
        $this->employee($this->companyA, 'Budi Santoso', 'A1');
        $this->employee($this->companyB, 'Budi Rahman', 'B1');

        $response = $this->actingAs($this->userA)->getJson('/employee-lookup?q=Budi');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('full_name');

        $this->assertContains('Budi Santoso', $names);
        $this->assertNotContains('Budi Rahman', $names, 'Lookup leaked another tenant\'s employee.');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/employee-lookup')->assertUnauthorized();
    }

    public function test_search_matches_by_name(): void
    {
        $this->employee($this->companyA, 'Siti Aminah', 'A1');
        $this->employee($this->companyA, 'Joko Widodo', 'A2');

        $names = collect($this->actingAs($this->userA)->getJson('/employee-lookup?q=Siti')->json('data'))->pluck('full_name');

        $this->assertContains('Siti Aminah', $names);
        $this->assertNotContains('Joko Widodo', $names);
    }

    /** The cap is what stops a "lookup" becoming an unbounded directory export. */
    public function test_per_page_is_capped(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->employee($this->companyA, "Worker $i", "A$i");
        }

        $data = $this->actingAs($this->userA)->getJson('/employee-lookup?per_page=500')->json('data');

        $this->assertLessThanOrEqual(50, count($data), 'per_page must be capped regardless of what the client asks for.');
    }

    /** Department name is returned as `group` so a client can render headings without a second request. */
    public function test_results_carry_a_department_group_label(): void
    {
        $dept = Department::create(['name' => 'Produksi', 'company_id' => $this->companyA->id]);
        $this->employee($this->companyA, 'Andi', 'A1', $dept->id);

        $row = collect($this->actingAs($this->userA)->getJson('/employee-lookup?q=Andi')->json('data'))->first();

        $this->assertSame('Produksi', $row['group']);
    }

    /** Inactive employees are excluded from browsing but must still hydrate by id. */
    public function test_inactive_employees_are_excluded_from_search_but_resolvable_by_id(): void
    {
        $inactive = $this->employee($this->companyA, 'Mantan Pekerja', 'A9', null, 'inactive');

        $searched = collect($this->actingAs($this->userA)->getJson('/employee-lookup?q=Mantan')->json('data'))->pluck('full_name');
        $this->assertNotContains('Mantan Pekerja', $searched);

        $hydrated = collect($this->actingAs($this->userA)->getJson('/employee-lookup?ids[]='.$inactive->id)->json('data'))->pluck('full_name');
        $this->assertContains('Mantan Pekerja', $hydrated, 'An existing record referencing a deactivated employee must still show their name.');
    }

    public function test_hydration_by_id_is_still_tenant_scoped(): void
    {
        $foreign = $this->employee($this->companyB, 'Foreign Person', 'B9');

        $data = $this->actingAs($this->userA)->getJson('/employee-lookup?ids[]='.$foreign->id)->json('data');

        $this->assertSame([], $data, 'Hydration by id must not bypass the tenant boundary.');
    }
}
